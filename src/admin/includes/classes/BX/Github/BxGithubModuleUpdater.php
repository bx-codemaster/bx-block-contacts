<?php

declare(strict_types=1);

class BxGithubModuleUpdater
{
    private string $owner;
    private string $repo;
    private string $moduleName;
    private string $modulePrefix;
    private string $currentVersion;
    private string $shopRoot;
    private string $cacheDir;
    private ?string $githubToken;
    private int $cacheTtl;
    private int $maximumArchiveSize;

    /** @var string[] */
    private array $excludePaths;

    private const MANIFEST_FILE = '.bx-module-manifest.json';
    private const CHECK_CACHE_FILE = 'release.json';
    private const INSTALLED_MANIFEST_FILE = 'installed-manifest.json';

    public function __construct(array $config)
    {
        $this->owner = trim((string) ($config['owner'] ?? ''));
        $this->repo = trim((string) ($config['repo'] ?? ''));
        $this->moduleName = trim((string) ($config['module_name'] ?? $this->repo));
        $this->modulePrefix = strtoupper(trim((string) ($config['module_prefix'] ?? '')));
        $this->currentVersion = $this->normalizeVersion((string) ($config['current_version'] ?? ''));
        $this->shopRoot = rtrim((string) ($config['shop_root'] ?? DIR_FS_CATALOG), '/\\');
        $this->cacheDir = rtrim(
            (string) ($config['cache_dir'] ?? ($this->shopRoot . '/cache/bx_github_updater/' . $this->moduleName)),
            '/\\'
        );
        $this->githubToken = !empty($config['github_token']) ? (string) $config['github_token'] : null;
        $this->cacheTtl = max(60, (int) ($config['cache_ttl_seconds'] ?? 86400));
        $this->maximumArchiveSize = max(
            1024 * 1024,
            (int) ($config['maximum_archive_size'] ?? 50 * 1024 * 1024)
        );
        $this->excludePaths = array_map(
            static function (string $path): string {
                return trim(str_replace('\\', '/', $path), '/');
            },
            $config['exclude_paths'] ?? []
        );

        if ($this->owner === '' || $this->repo === '' || $this->modulePrefix === '') {
            throw new InvalidArgumentException('owner, repo and module_prefix are required.');
        }
        if (!$this->isValidVersion($this->currentVersion)) {
            throw new InvalidArgumentException('current_version must be a semantic version.');
        }
        if (realpath($this->shopRoot) === false) {
            throw new InvalidArgumentException('shop_root does not exist.');
        }
        $this->shopRoot = rtrim((string) realpath($this->shopRoot), '/\\');
    }

    /**
     * @return array{has_update:bool,current_version:string,latest_version:string,zip_url:?string,sha256:?string,html_url:?string,body:string,checked_at:int,error:?string}
     */
    public function checkForUpdate(bool $forceRefresh = false): array
    {
        if (!$forceRefresh && is_file($this->cacheFilePath())) {
            $cached = json_decode((string) file_get_contents($this->cacheFilePath()), true);
            if (
                is_array($cached)
                && isset($cached['checked_at'])
                && (time() - (int) $cached['checked_at']) < $this->cacheTtl
                && isset($cached['latest_version'])
            ) {
                $latestVersion = $this->normalizeVersion((string) $cached['latest_version']);
                $cached['has_update'] = $this->isNewer($latestVersion, $this->currentVersion);
                $cached['current_version'] = $this->currentVersion;
                return $cached + $this->emptyStatus();
            }
        }

        $result = $this->loadLatestRelease();
        $this->writeCache($result);
        return $result;
    }

    /**
     * @param null|callable(string,string):void $migration
     * @return array{ok:bool,message:string,version?:string}
     */
    public function installLatestUpdate(?callable $migration = null): array
    {
        if (!extension_loaded('curl')) {
            return ['ok' => false, 'message' => 'Die PHP-Erweiterung cURL ist nicht verfügbar.'];
        }
        if (!class_exists('ZipArchive')) {
            return ['ok' => false, 'message' => 'Die PHP-Erweiterung ZipArchive ist nicht verfügbar.'];
        }

        $this->ensureDirectory($this->cacheDir);
        $lockHandle = fopen($this->cacheDir . '/update.lock', 'c');
        if ($lockHandle === false || !flock($lockHandle, LOCK_EX | LOCK_NB)) {
            if (is_resource($lockHandle)) {
                fclose($lockHandle);
            }
            return ['ok' => false, 'message' => 'Für dieses Modul läuft bereits ein Update.'];
        }

        $stageDir = '';
        try {
            $status = $this->checkForUpdate(true);
            if ($status['error'] !== null) {
                return ['ok' => false, 'message' => 'Update-Prüfung fehlgeschlagen: ' . $status['error']];
            }
            if (!$status['has_update']) {
                return ['ok' => false, 'message' => 'Es ist kein neueres Release verfügbar.'];
            }
            if (empty($status['zip_url']) || empty($status['sha256'])) {
                return [
                    'ok' => false,
                    'message' => 'Das Release enthält kein gültiges Modularchiv mit SHA-256-Prüfsumme.',
                ];
            }

            $stageDir = $this->cacheDir . '/stage-' . bin2hex(random_bytes(8));
            $extractDir = $stageDir . '/extracted';
            $zipFile = $stageDir . '/release.zip';
            $this->ensureDirectory($extractDir);

            $this->downloadFile($status['zip_url'], $zipFile);
            $actualHash = strtolower((string) hash_file('sha256', $zipFile));
            if ($actualHash === '' || !hash_equals((string) $status['sha256'], $actualHash)) {
                throw new RuntimeException('SHA-256-Prüfsumme stimmt nicht überein.');
            }

            $manifest = $this->readAndValidateArchive(
                $zipFile,
                $extractDir,
                $this->moduleName,
                $status['latest_version']
            );
            $changedFiles = $this->applyPackage($extractDir, $manifest, $stageDir);

            if ($migration !== null) {
                $migration($this->currentVersion, $status['latest_version']);
            }

            $installedManifestPath = $this->cacheDir . '/' . self::INSTALLED_MANIFEST_FILE;
            $previousManifestPath = $stageDir . '/previous-installed-manifest.json';
            if (is_file($installedManifestPath)) {
                if (!copy($installedManifestPath, $previousManifestPath)) {
                    throw new RuntimeException('Das vorhandene Modulmanifest konnte nicht gesichert werden.');
                }
            }
            $this->writeJsonAtomically(
                $installedManifestPath,
                $manifest
            );
            try {
                $this->setInstalledVersion($status['latest_version']);
            } catch (Throwable $exception) {
                if (is_file($previousManifestPath)) {
                    $this->writeJsonAtomically(
                        $installedManifestPath,
                        json_decode((string) file_get_contents($previousManifestPath), true)
                    );
                } else {
                    $this->removePath($installedManifestPath);
                }
                throw $exception;
            }
            $this->currentVersion = $status['latest_version'];
            $this->removePath($this->cacheFilePath());

            return [
                'ok' => true,
                'message' => sprintf(
                    'Update auf Version %s erfolgreich (%d Dateien geändert).',
                    $status['latest_version'],
                    $changedFiles
                ),
                'version' => $status['latest_version'],
            ];
        } catch (Throwable $exception) {
            $rollbackErrors = [];
            if ($stageDir !== '') {
                $rollbackErrors = $this->rollback($stageDir);
            }
            $message = $exception->getMessage();
            if ($rollbackErrors !== []) {
                $message .= ' Rollback unvollständig: ' . implode(' ', $rollbackErrors);
            }
            return ['ok' => false, 'message' => $message];
        } finally {
            if ($stageDir !== '') {
                $this->removeTree($stageDir);
            }
            flock($lockHandle, LOCK_UN);
            fclose($lockHandle);
        }
    }

    private function loadLatestRelease(): array
    {
        if (!extension_loaded('curl')) {
            return $this->emptyStatus('Die PHP-Erweiterung cURL ist nicht verfügbar.');
        }

        $url = sprintf(
            'https://api.github.com/repos/%s/%s/releases/latest',
            rawurlencode($this->owner),
            rawurlencode($this->repo)
        );
        try {
            $response = $this->request($url, 'application/vnd.github+json');
        } catch (Throwable $exception) {
            return $this->emptyStatus($exception->getMessage());
        }

        $release = json_decode($response, true);
        if (!is_array($release) || empty($release['tag_name'])) {
            return $this->emptyStatus('GitHub lieferte keine gültige Release-Antwort.');
        }

        $latestVersion = $this->normalizeVersion((string) $release['tag_name']);
        if (!$this->isValidVersion($latestVersion)) {
            return $this->emptyStatus('Der neueste GitHub-Tag ist keine SemVer-Version.');
        }

        $expectedZipName = sprintf('%s-%s.zip', $this->moduleName, $latestVersion);
        $expectedShaName = $expectedZipName . '.sha256';
        $zipUrl = null;
        $shaAssetUrl = null;
        $sha256 = null;
        foreach ((array) ($release['assets'] ?? []) as $asset) {
            if (!is_array($asset)) {
                continue;
            }
            $name = (string) ($asset['name'] ?? '');
            $assetUrl = (string) ($asset['url'] ?? $asset['browser_download_url'] ?? '');
            if ($name === $expectedZipName) {
                $zipUrl = $assetUrl;
                $digest = (string) ($asset['digest'] ?? '');
                if (preg_match('/^sha256:([a-f0-9]{64})$/i', $digest, $match)) {
                    $sha256 = strtolower($match[1]);
                }
            } elseif ($name === $expectedShaName) {
                $shaAssetUrl = $assetUrl;
            }
        }

        if ($sha256 === null && $shaAssetUrl !== null) {
            try {
                $checksumContent = $this->request($shaAssetUrl, 'application/octet-stream');
                if (preg_match('/\b([a-f0-9]{64})\b/i', $checksumContent, $match)) {
                    $sha256 = strtolower($match[1]);
                }
            } catch (Throwable $exception) {
                return $this->emptyStatus(
                    'Prüfsummendatei konnte nicht geladen werden: ' . $exception->getMessage(),
                    $latestVersion
                );
            }
        }

        return [
            'has_update' => $this->isNewer($latestVersion, $this->currentVersion),
            'current_version' => $this->currentVersion,
            'latest_version' => $latestVersion,
            'zip_url' => $zipUrl,
            'sha256' => $sha256,
            'html_url' => isset($release['html_url']) ? (string) $release['html_url'] : null,
            'body' => isset($release['body']) ? (string) $release['body'] : '',
            'checked_at' => time(),
            'error' => null,
        ];
    }

    /**
     * @return array{module:string,version:string,files:array<string,string>}
     */
    private function readAndValidateArchive(
        string $zipFile,
        string $extractDir,
        string $moduleName,
        string $version
    ): array {
        $zip = new ZipArchive();
        $result = $zip->open($zipFile);
        if ($result !== true) {
            throw new RuntimeException('Das ZIP-Archiv konnte nicht geöffnet werden.');
        }

        $regularFiles = [];
        $uncompressedSize = 0;
        try {
            if ($zip->numFiles > 10000) {
                throw new RuntimeException('Das Release enthält zu viele Dateien.');
            }

            for ($index = 0; $index < $zip->numFiles; $index++) {
                $name = str_replace('\\', '/', (string) $zip->getNameIndex($index));
                $this->assertSafeRelativePath($name, 'ZIP-Eintrag');

                $unixAttributes = 0;
                $operatingSystem = 0;
                if (
                    method_exists($zip, 'getExternalAttributesIndex')
                    && $zip->getExternalAttributesIndex($index, $operatingSystem, $unixAttributes)
                    && $operatingSystem === 3
                    && ((($unixAttributes >> 16) & 0170000) === 0120000)
                ) {
                    throw new RuntimeException('Symbolische Links im Release sind nicht erlaubt.');
                }

                if (substr($name, -1) !== '/') {
                    $stat = $zip->statIndex($index);
                    $uncompressedSize += (int) ($stat['size'] ?? 0);
                    if ($uncompressedSize > $this->maximumArchiveSize * 4) {
                        throw new RuntimeException('Das entpackte Release ist zu groß.');
                    }
                    $regularFiles[$name] = true;
                }
            }

            if (!isset($regularFiles[self::MANIFEST_FILE])) {
                throw new RuntimeException('Das Release enthält kein Modulmanifest.');
            }
            if (!$zip->extractTo($extractDir)) {
                throw new RuntimeException('Das ZIP-Archiv konnte nicht entpackt werden.');
            }
        } finally {
            $zip->close();
        }

        $manifestData = json_decode(
            (string) file_get_contents($extractDir . '/' . self::MANIFEST_FILE),
            true
        );
        if (
            !is_array($manifestData)
            || ($manifestData['module'] ?? '') !== $moduleName
            || ($manifestData['version'] ?? '') !== $version
            || !is_array($manifestData['files'] ?? null)
        ) {
            throw new RuntimeException('Das Modulmanifest ist ungültig.');
        }

        $files = [];
        $expectedArchiveFiles = [self::MANIFEST_FILE => true];
        foreach ($manifestData['files'] as $path => $hash) {
            if (!is_string($path) || !is_string($hash) || !preg_match('/^[a-f0-9]{64}$/i', $hash)) {
                throw new RuntimeException('Das Modulmanifest enthält ungültige Dateieinträge.');
            }
            $this->assertSafeRelativePath($path, 'Manifestpfad');
            $filePath = $extractDir . '/' . $path;
            if (!is_file($filePath) || is_link($filePath)) {
                throw new RuntimeException('Manifestdatei fehlt im Release: ' . $path);
            }
            if (!hash_equals(strtolower($hash), strtolower((string) hash_file('sha256', $filePath)))) {
                throw new RuntimeException('Dateiprüfsumme im Release ist ungültig: ' . $path);
            }
            $files[$path] = strtolower($hash);
            $expectedArchiveFiles[$path] = true;
        }

        if (count(array_diff_key($regularFiles, $expectedArchiveFiles)) > 0) {
            throw new RuntimeException('Das Release enthält Dateien, die nicht im Manifest aufgeführt sind.');
        }

        return [
            'module' => $moduleName,
            'version' => $version,
            'files' => $files,
        ];
    }

    /**
     * @param array{module:string,version:string,files:array<string,string>} $manifest
     */
    private function applyPackage(string $extractDir, array $manifest, string $stageDir): int
    {
        $backupDir = $stageDir . '/backup';
        $this->ensureDirectory($backupDir);
        $rollbackPlan = [];
        $changedFiles = 0;

        try {
            $oldManifest = $this->readInstalledManifest();
            $oldFiles = $oldManifest['files'] ?? [];

            foreach (array_keys($oldFiles) as $oldPath) {
                if (!isset($manifest['files'][$oldPath]) && !$this->isExcluded($oldPath)) {
                    $target = $this->safeTargetPath($oldPath);
                    $this->backupTarget($target, $oldPath, $backupDir, $rollbackPlan);
                    if (is_file($target) || is_link($target)) {
                        if (!unlink($target)) {
                            throw new RuntimeException('Veraltete Datei konnte nicht entfernt werden: ' . $oldPath);
                        }
                        $changedFiles++;
                    }
                }
            }

            foreach ($manifest['files'] as $path => $hash) {
                if ($this->isExcluded($path)) {
                    continue;
                }
                $source = $extractDir . '/' . $path;
                $target = $this->safeTargetPath($path);
                if (is_file($target) && hash_file('sha256', $target) === $hash) {
                    continue;
                }

                $this->ensureDirectory(dirname($target));
                $this->backupTarget($target, $path, $backupDir, $rollbackPlan);
                $temporaryTarget = $target . '.bx-update-' . bin2hex(random_bytes(4));
                if (!copy($source, $temporaryTarget)) {
                    throw new RuntimeException('Datei konnte nicht vorbereitet werden: ' . $path);
                }
                if (is_file($target) && !unlink($target)) {
                    @unlink($temporaryTarget);
                    throw new RuntimeException('Vorhandene Datei konnte nicht ersetzt werden: ' . $path);
                }
                if (!rename($temporaryTarget, $target)) {
                    @unlink($temporaryTarget);
                    throw new RuntimeException('Datei konnte nicht installiert werden: ' . $path);
                }
                $changedFiles++;
            }

            $this->writeJsonAtomically($stageDir . '/rollback.json', $rollbackPlan);
        } catch (Throwable $exception) {
            $this->writeJsonAtomically($stageDir . '/rollback.json', $rollbackPlan);
            throw $exception;
        }

        return $changedFiles;
    }

    private function rollback(string $stageDir): array
    {
        $planPath = $stageDir . '/rollback.json';
        $backupDir = $stageDir . '/backup';
        if (!is_file($planPath)) {
            return [];
        }
        $plan = json_decode((string) file_get_contents($planPath), true);
        if (!is_array($plan)) {
            return ['Rollback-Plan konnte nicht gelesen werden.'];
        }
        $errors = [];
        foreach (array_reverse($plan) as $entry) {
            if (!is_array($entry) || empty($entry['path'])) {
                continue;
            }
            try {
                $target = $this->safeTargetPath((string) $entry['path']);
                if (!empty($entry['existed'])) {
                    $this->ensureDirectory(dirname($target));
                    $backup = $backupDir . '/' . $entry['backup'];
                    if (is_file($backup)) {
                        if (is_file($target)) {
                            if (!unlink($target)) {
                                throw new RuntimeException('Zieldatei konnte nicht ersetzt werden.');
                            }
                        }
                        if (!copy($backup, $target)) {
                            throw new RuntimeException('Sicherung konnte nicht wiederhergestellt werden.');
                        }
                    } else {
                        throw new RuntimeException('Sicherungsdatei fehlt.');
                    }
                } elseif (is_file($target) || is_link($target)) {
                    if (!unlink($target)) {
                        throw new RuntimeException('Neue Datei konnte nicht entfernt werden.');
                    }
                }
            } catch (Throwable $rollbackException) {
                $errors[] = (string) $entry['path'] . ': ' . $rollbackException->getMessage();
            }
        }
        return $errors;
    }

    private function backupTarget(string $target, string $relativePath, string $backupDir, array &$rollbackPlan): void
    {
        foreach ($rollbackPlan as $entry) {
            if (($entry['path'] ?? '') === $relativePath) {
                return;
            }
        }

        $exists = is_file($target);
        $backup = bin2hex(random_bytes(8));
        if ($exists) {
            $this->ensureDirectory($backupDir);
            if (!copy($target, $backupDir . '/' . $backup)) {
                throw new RuntimeException('Sicherung der vorhandenen Datei fehlgeschlagen: ' . $relativePath);
            }
        }
        $rollbackPlan[] = [
            'path' => $relativePath,
            'existed' => $exists,
            'backup' => $backup,
        ];
    }

    private function readInstalledManifest(): array
    {
        $path = $this->cacheDir . '/' . self::INSTALLED_MANIFEST_FILE;
        if (!is_file($path)) {
            return [];
        }
        $manifest = json_decode((string) file_get_contents($path), true);
        return is_array($manifest) ? $manifest : [];
    }

    private function setInstalledVersion(string $version): void
    {
        $key = 'MODULE_' . $this->modulePrefix . '_VERSION';
        $escapedKey = xtc_db_input($key);
        $escapedVersion = xtc_db_input($version);
        $query = xtc_db_query(
            "SELECT configuration_id FROM " . TABLE_CONFIGURATION
            . " WHERE configuration_key = '" . $escapedKey . "' LIMIT 1"
        );
        if ($query && xtc_db_num_rows($query) > 0) {
            $result = xtc_db_query(
                "UPDATE " . TABLE_CONFIGURATION
                . " SET configuration_value = '" . $escapedVersion . "', last_modified = NOW()"
                . " WHERE configuration_key = '" . $escapedKey . "'"
            );
            if ($result === false) {
                throw new RuntimeException('Die installierte Modulversion konnte nicht aktualisiert werden.');
            }
            return;
        }
        throw new RuntimeException('Die installierte Modulversion konnte nicht in der Konfiguration gefunden werden.');
    }

    private function safeTargetPath(string $relativePath): string
    {
        $this->assertSafeRelativePath($relativePath, 'Zielpfad');
        $target = $this->shopRoot . '/' . $relativePath;
        $existing = $this->shopRoot;
        $segments = explode('/', $relativePath);
        array_pop($segments);
        foreach ($segments as $segment) {
            $existing .= '/' . $segment;
            if (is_link($existing)) {
                throw new RuntimeException('Symbolische Verzeichnisse sind als Updateziel nicht erlaubt: ' . $relativePath);
            }
        }
        return $target;
    }

    private function assertSafeRelativePath(string $path, string $label): void
    {
        if (
            $path === ''
            || strpos($path, "\0") !== false
            || substr($path, 0, 1) === '/'
            || preg_match('/^[A-Za-z]:/', $path)
            || strpos($path, '//') !== false
        ) {
            throw new RuntimeException($label . ' ist nicht sicher: ' . $path);
        }
        $trimmed = rtrim($path, '/');
        foreach (explode('/', $trimmed) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                throw new RuntimeException($label . ' ist nicht sicher: ' . $path);
            }
        }
    }

    private function request(string $url, string $accept): string
    {
        $this->assertGithubUrl($url);
        $handle = curl_init($url);
        if ($handle === false) {
            throw new RuntimeException('cURL konnte nicht initialisiert werden.');
        }
        curl_setopt_array($handle, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 3,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_HTTPHEADER => $this->githubHeaders($accept),
        ]);
        $body = curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_HTTP_CODE);
        $error = (string) curl_error($handle);
        curl_close($handle);
        if (!is_string($body) || $status < 200 || $status >= 300) {
            throw new RuntimeException($error !== '' ? $error : 'GitHub antwortete mit HTTP ' . $status . '.');
        }
        return $body;
    }

    private function downloadFile(string $url, string $destination): void
    {
        $this->assertGithubUrl($url);
        $handle = curl_init($url);
        $file = fopen($destination, 'wb');
        if ($handle === false || $file === false) {
            if (is_resource($handle)) {
                curl_close($handle);
            }
            if (is_resource($file)) {
                fclose($file);
            }
            throw new RuntimeException('Download konnte nicht vorbereitet werden.');
        }
        curl_setopt_array($handle, [
            CURLOPT_FILE => $file,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 3,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 60,
            CURLOPT_HTTPHEADER => $this->githubHeaders('application/octet-stream'),
        ]);
        $success = curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_HTTP_CODE);
        $error = (string) curl_error($handle);
        curl_close($handle);
        fclose($file);
        if (!$success || $status < 200 || $status >= 300) {
            @unlink($destination);
            throw new RuntimeException($error !== '' ? $error : 'Release-Download schlug mit HTTP ' . $status . ' fehl.');
        }
        $size = filesize($destination);
        if ($size === false || $size <= 0 || $size > $this->maximumArchiveSize) {
            @unlink($destination);
            throw new RuntimeException('Die Größe des Release-Archivs ist ungültig.');
        }
    }

    private function githubHeaders(string $accept): array
    {
        $headers = [
            'Accept: ' . $accept,
            'User-Agent: BX-GitHub-Module-Updater/1.0',
            'X-GitHub-Api-Version: 2022-11-28',
        ];
        if ($this->githubToken !== null) {
            $headers[] = 'Authorization: Bearer ' . $this->githubToken;
        }
        return $headers;
    }

    private function assertGithubUrl(string $url): void
    {
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        if (
            $scheme !== 'https'
            || !in_array($host, ['api.github.com', 'github.com', 'objects.githubusercontent.com'], true)
        ) {
            throw new RuntimeException('Unerwartete Download-URL von GitHub.');
        }
    }

    private function isExcluded(string $path): bool
    {
        foreach ($this->excludePaths as $excluded) {
            if ($path === $excluded || strpos($path, $excluded . '/') === 0) {
                return true;
            }
        }
        return false;
    }

    private function normalizeVersion(string $version): string
    {
        return ltrim(trim($version), 'vV');
    }

    private function isValidVersion(string $version): bool
    {
        return preg_match(
            '/^(0|[1-9]\d*)\.(0|[1-9]\d*)\.(0|[1-9]\d*)(?:-[0-9A-Za-z.-]+)?(?:\+[0-9A-Za-z.-]+)?$/',
            $version
        ) === 1;
    }

    private function isNewer(string $candidate, string $current): bool
    {
        return $this->isValidVersion($candidate) && version_compare($candidate, $current, '>');
    }

    private function cacheFilePath(): string
    {
        return $this->cacheDir . '/' . self::CHECK_CACHE_FILE;
    }

    private function emptyStatus(?string $error = null, ?string $latestVersion = null): array
    {
        return [
            'has_update' => false,
            'current_version' => $this->currentVersion,
            'latest_version' => $latestVersion ?? $this->currentVersion,
            'zip_url' => null,
            'sha256' => null,
            'html_url' => null,
            'body' => '',
            'checked_at' => time(),
            'error' => $error,
        ];
    }

    private function writeCache(array $status): void
    {
        if ($status['error'] !== null) {
            $status['checked_at'] = time();
            $status['error'] = (string) $status['error'];
        }
        $this->writeJsonAtomically($this->cacheFilePath(), $status);
    }

    private function writeJsonAtomically(string $path, array $value): void
    {
        $this->ensureDirectory(dirname($path));
        $json = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            throw new RuntimeException('JSON-Daten konnten nicht erzeugt werden.');
        }
        $temporary = $path . '.tmp-' . bin2hex(random_bytes(4));
        if (file_put_contents($temporary, $json, LOCK_EX) === false) {
            throw new RuntimeException('Datei konnte nicht geschrieben werden: ' . basename($path));
        }
        if (is_file($path) && !@unlink($path)) {
            @unlink($temporary);
            throw new RuntimeException('Vorhandene Cache-Datei konnte nicht ersetzt werden.');
        }
        if (!rename($temporary, $path)) {
            @unlink($temporary);
            throw new RuntimeException('Cache-Datei konnte nicht installiert werden.');
        }
    }

    private function ensureDirectory(string $path): void
    {
        if (is_dir($path)) {
            return;
        }
        if (!mkdir($path, 0755, true) && !is_dir($path)) {
            throw new RuntimeException('Verzeichnis konnte nicht angelegt werden: ' . $path);
        }
    }

    private function removeTree(string $path): void
    {
        if (!file_exists($path) && !is_link($path)) {
            return;
        }
        if (!is_dir($path) || is_link($path)) {
            @unlink($path);
            return;
        }
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $entry) {
            $entry->isDir() && !$entry->isLink()
                ? @rmdir($entry->getPathname())
                : @unlink($entry->getPathname());
        }
        @rmdir($path);
    }

    private function removePath(string $path): void
    {
        if (is_file($path) || is_link($path)) {
            @unlink($path);
        }
    }
}
