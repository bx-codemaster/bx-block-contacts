<?php

declare(strict_types=1);

/**
 * Independent GitHub Release updater for modified eCommerce modules.
 *
 * This class checks a module's GitHub repository for its latest release, caches
 * the result, offers version information to the calling module, and installs a
 * verified release archive directly into the shop root. It deliberately has no
 * dependency on MMLC or another module distribution server.
 *
 * Repository, module name, configuration prefix, installed version, cache
 * lifetime, optional GitHub token, excluded paths, and maximum archive size are
 * supplied through the constructor configuration. The release must provide:
 * - A strict vMAJOR.MINOR.PATCH-based version tag with optional SemVer suffixes.
 * - A ZIP named "<module-name>-<version>.zip".
 * - A SHA-256 checksum as GitHub asset digest or matching .sha256 asset.
 * - A .bx-module-manifest.json at ZIP root level.
 * - A SHA-256 hash for every file listed in the manifest.
 * - A matching .bx-module-manifest.sig when public_key is configured.
 *
 * Update installation is separated into validation, staging, backup, copying,
 * rollback, and optional database migration phases. Only an archive whose
 * complete hash, manifest, paths, sizes, file count, and individual file hashes
 * pass validation is installed. Absolute paths, traversal segments, symbolic
 * links, undeclared archive files, and excessive payloads are rejected.
 *
 * Concurrent update attempts are prevented with an exclusive non-blocking file
 * lock. Before replacing or removing files, the previous state is copied into a
 * package-specific staging backup. If installation or a later migration fails,
 * the changed files are restored and newly created files are removed.
 *
 * Release metadata is cached as JSON below
 * cache/bx_github_updater/<module-name>. Successful installations additionally
 * store the installed manifest there, allowing a later release to identify and
 * safely remove obsolete package files. Administrative callers remain
 * responsible for invoking update operations through a CSRF-protected action and
 * for passing idempotent database migrations when required.
 *
 * The class expects the modified constants DIR_FS_CATALOG and TABLE_CONFIGURATION
 * and the standard xtc_db_* helper functions to be available. The caller must
 * also provide a valid semantic version in configuration key
 * MODULE_<PREFIX>_VERSION.
 *
 * PHP 8.0 or newer is required. GitHub access additionally requires ext-curl,
 * package installation requires ext-zip, and signed releases require ext-sodium.
 *
 * @package    BX\Github
 * @author     Axel Benkert <mail@bx-coding.de>
 * @copyright  2026-present BX Coding
 * @license    GNU General Public License, version 2 or later
 * @link       https://github.com/bx-codemaster/bx-block-contacts
 * @since      1.1.0
 * @requires   PHP >= 8.0
 *
 * @see        https://docs.github.com/en/rest/releases/releases#get-the-latest-release
 */
class BxGithubModuleUpdater
{
    private string $owner;
    private string $repo;
    private string $moduleName;
    private string $modulePrefix;
    private string $currentVersion;
    private string $shopRoot;
    private string $adminDirectory;
    private string $cacheDir;
    private ?string $githubToken;
    private int $cacheTtl;
    private int $maximumArchiveSize;
    private int $maximumManifestSize;
    private int $maximumExtractedSize;
    private ?string $publicKey;

    /** @var string[] */
    private array $excludePaths;

    /** @var string[] */
    private array $allowedPaths;

    private const MANIFEST_FILE = '.bx-module-manifest.json';
    private const MANIFEST_SIGNATURE_FILE = '.bx-module-manifest.sig';
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
        $configuredAdminDirectory = (string) (
            $config['admin_dir']
            ?? (defined('DIR_ADMIN') ? DIR_ADMIN : 'admin')
        );
        $this->adminDirectory = trim(str_replace('\\', '/', $configuredAdminDirectory), '/');
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
        $this->maximumManifestSize = max(10240, (int) ($config['maximum_manifest_size'] ?? 1024 * 1024));
        $this->maximumExtractedSize = max(
            $this->maximumArchiveSize,
            (int) ($config['maximum_extracted_size'] ?? 4 * $this->maximumArchiveSize)
        );
        $publicKey = $config['public_key'] ?? null;
        $this->publicKey = $publicKey === null || $publicKey === '' ? null : (string) $publicKey;
        $this->excludePaths = array_map(
            static function (string $path): string {
                return trim(str_replace('\\', '/', $path), '/');
            },
            $config['exclude_paths'] ?? []
        );
        $this->allowedPaths = array_map(
            static function (string $path): string {
                return trim(str_replace('\\', '/', $path), '/');
            },
            $config['allowed_paths'] ?? []
        );

        if ($this->owner === '' || $this->repo === '' || $this->modulePrefix === '') {
            throw new InvalidArgumentException('owner, repo and module_prefix are required.');
        }
        $this->assertSafeRelativePath($this->adminDirectory, 'Admin directory');
        if (
            preg_match('/^[A-Za-z0-9._-]+$/', $this->owner) !== 1
            || preg_match('/^[A-Za-z0-9._-]+$/', $this->repo) !== 1
            || preg_match('/^[A-Za-z0-9._-]+$/', $this->moduleName) !== 1
            || preg_match('/^[A-Z0-9_]+$/', $this->modulePrefix) !== 1
        ) {
            throw new InvalidArgumentException('Repository, module name and module prefix contain invalid characters.');
        }
        if (!$this->isValidVersion($this->currentVersion)) {
            throw new InvalidArgumentException('current_version must be a semantic version.');
        }
        foreach ($this->excludePaths as $excludePath) {
            if ($excludePath === '') {
                throw new InvalidArgumentException('exclude_paths must not contain empty paths.');
            }
            $this->assertSafeRelativePath($excludePath, 'Exclusion path');
        }
        foreach ($this->allowedPaths as $allowedPath) {
            if ($allowedPath === '') {
                throw new InvalidArgumentException('allowed_paths must not contain empty paths.');
            }
            $this->assertSafeRelativePath($allowedPath, 'Allowed path');
        }
        if ($this->allowedPaths === []) {
            throw new InvalidArgumentException('At least one allowed_paths entry is required.');
        }
        if (realpath($this->shopRoot) === false) {
            throw new InvalidArgumentException('shop_root does not exist.');
        }
        $this->shopRoot = rtrim((string) realpath($this->shopRoot), '/\\');
        $adminRoot = realpath($this->shopRoot . '/' . $this->adminDirectory);
        if ($adminRoot === false || !is_dir($adminRoot)) {
            throw new InvalidArgumentException('Configured admin directory does not exist.');
        }
        $normalizedAdminRoot = rtrim(str_replace('\\', '/', (string) $adminRoot), '/');
        $normalizedShopRoot = str_replace('\\', '/', $this->shopRoot);
        if (strpos($normalizedAdminRoot, $normalizedShopRoot . '/') !== 0) {
            throw new InvalidArgumentException('Configured admin directory must be inside shop_root.');
        }
        if ($this->publicKey !== null) {
            if (!extension_loaded('sodium') || preg_match('/^[A-Za-z0-9+\/]{43}=$/', $this->publicKey) !== 1) {
                throw new InvalidArgumentException('public_key must be a base64-encoded Ed25519 public key.');
            }
        }
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
                && (time() - (int) $cached['checked_at']) < (
                    !empty($cached['error']) ? min(300, $this->cacheTtl) : $this->cacheTtl
                )
                && isset($cached['latest_version'])
            ) {
                $latestVersion = $this->normalizeVersion((string) $cached['latest_version']);
                $cached['has_update'] = $this->isNewer($latestVersion, $this->currentVersion);
                $cached['current_version'] = $this->currentVersion;
                return $cached + $this->emptyStatus();
            }
        }

        $result = $this->loadLatestRelease();
        try {
            $this->writeCache($result);
        } catch (Throwable $exception) {
            $result['error'] = 'Release-Daten konnten nicht gecacht werden.';
        }
        return $result;
    }

    /**
     * Runs an optional, idempotent migration callback after package files are staged.
     *
     * The callback must manage its own transactional guarantees. Database DDL may
     * commit implicitly and cannot be rolled back together with filesystem changes.
     *
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

        $this->protectCacheDirectory();
        $lockHandle = fopen($this->cacheDir . '/update.lock', 'c');
        if ($lockHandle === false || !flock($lockHandle, LOCK_EX | LOCK_NB)) {
            if (is_resource($lockHandle)) {
                fclose($lockHandle);
            }
            return ['ok' => false, 'message' => 'Für dieses Modul läuft bereits ein Update.'];
        }

        $stageDir = '';
        try {
            $this->recoverStaleStages();
            set_time_limit(0);
            ignore_user_abort(true);
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
            'html_url' => $this->safeGithubReleaseUrl($release['html_url'] ?? null),
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
                    if ($uncompressedSize > $this->maximumExtractedSize) {
                        throw new RuntimeException('Das entpackte Release ist zu groß.');
                    }
                    if (isset($regularFiles[$name])) {
                        throw new RuntimeException('Das Release enthält doppelte Dateieinträge: ' . $name);
                    }
                    $regularFiles[$name] = true;
                }
            }

            if (!isset($regularFiles[self::MANIFEST_FILE])) {
                throw new RuntimeException('Das Release enthält kein Modulmanifest.');
            }

            $manifestStat = $zip->statName(self::MANIFEST_FILE);
            if (
                !is_array($manifestStat)
                || (int) ($manifestStat['size'] ?? -1) <= 0
                || (int) $manifestStat['size'] > $this->maximumManifestSize
            ) {
                throw new RuntimeException('Das Modulmanifest ist ungültig oder zu groß.');
            }
            $manifestContent = $zip->getFromName(self::MANIFEST_FILE);
            if (!is_string($manifestContent) || strlen($manifestContent) !== (int) $manifestStat['size']) {
                throw new RuntimeException('Das Modulmanifest ist ungültig oder zu groß.');
            }
            if ($this->publicKey !== null) {
                $signatureStat = $zip->statName(self::MANIFEST_SIGNATURE_FILE);
                if (
                    !is_array($signatureStat)
                    || (int) ($signatureStat['size'] ?? -1) <= 0
                    || (int) $signatureStat['size'] > 256
                ) {
                    throw new RuntimeException('Die Manifest-Signatur fehlt oder ist zu groß.');
                }
                $signature = $zip->getFromName(self::MANIFEST_SIGNATURE_FILE);
                if (!is_string($signature) || strlen($signature) !== (int) $signatureStat['size']) {
                    throw new RuntimeException('Die Manifest-Signatur fehlt.');
                }
                $publicKey = base64_decode($this->publicKey, true);
                $decodedSignature = base64_decode(trim($signature), true);
                if (
                    !is_string($publicKey)
                    || !is_string($decodedSignature)
                    || !sodium_crypto_sign_verify_detached($decodedSignature, $manifestContent, $publicKey)
                ) {
                    throw new RuntimeException('Die Manifest-Signatur ist ungültig.');
                }
            }
            $manifestData = json_decode($manifestContent, true);
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
            if ($this->publicKey !== null) {
                $expectedArchiveFiles[self::MANIFEST_SIGNATURE_FILE] = true;
            }
            foreach ($manifestData['files'] as $path => $hash) {
                if (!is_string($path) || !is_string($hash) || !preg_match('/^[a-f0-9]{64}$/i', $hash)) {
                    throw new RuntimeException('Das Modulmanifest enthält ungültige Dateieinträge.');
                }
                $this->assertSafeRelativePath($path, 'Manifestpfad');
                if (!$this->isAllowed($path)) {
                    throw new RuntimeException('Manifestpfad ist für dieses Modul nicht erlaubt: ' . $path);
                }
                if (!isset($regularFiles[$path])) {
                    throw new RuntimeException('Manifestdatei fehlt im Release: ' . $path);
                }
                $files[$path] = strtolower($hash);
                $expectedArchiveFiles[$path] = true;
            }
            if (count(array_diff_key($regularFiles, $expectedArchiveFiles)) > 0) {
                throw new RuntimeException('Das Release enthält Dateien, die nicht im Manifest aufgeführt sind.');
            }

            foreach ($files as $path => $hash) {
                $entryIndex = $zip->locateName($path);
                if ($entryIndex === false) {
                    throw new RuntimeException('Manifestdatei fehlt im Release: ' . $path);
                }
                $filePath = $extractDir . '/' . $path;
                $this->ensureDirectory(dirname($filePath));
                $source = $zip->getStream($path);
                $destination = fopen($filePath, 'wb');
                if (!is_resource($source) || $destination === false) {
                    if (is_resource($source)) {
                        fclose($source);
                    }
                    if (is_resource($destination)) {
                        fclose($destination);
                    }
                    throw new RuntimeException('Dateistream konnte nicht geöffnet werden: ' . $path);
                }

                $hashContext = hash_init('sha256');
                $writtenBytes = 0;
                $expectedBytes = (int) ($zip->statIndex($entryIndex)['size'] ?? -1);
                while (!feof($source)) {
                    $chunk = fread($source, 65536);
                    if ($chunk === false) {
                        fclose($source);
                        fclose($destination);
                        throw new RuntimeException('Datei konnte nicht aus dem Archiv gelesen werden: ' . $path);
                    }
                    if ($chunk === '') {
                        continue;
                    }
                    hash_update($hashContext, $chunk);
                    $chunkLength = strlen($chunk);
                    if (fwrite($destination, $chunk) !== $chunkLength) {
                        fclose($source);
                        fclose($destination);
                        throw new RuntimeException(
                            'Datei konnte nicht im Staging-Verzeichnis gespeichert werden: ' . $path
                        );
                    }
                    $writtenBytes += $chunkLength;
                    if ($writtenBytes > $expectedBytes) {
                        fclose($source);
                        fclose($destination);
                        @unlink($filePath);
                        throw new RuntimeException('Archivdatei ist größer als deklariert: ' . $path);
                    }
                }
                fclose($source);
                fclose($destination);

                if (
                    $writtenBytes !== $expectedBytes
                    || !hash_equals($hash, hash_final($hashContext))
                ) {
                    @unlink($filePath);
                    throw new RuntimeException('Dateiprüfsumme im Release ist ungültig: ' . $path);
                }
            }

            return [
                'module' => $moduleName,
                'version' => $version,
                'files' => $files,
            ];
        } finally {
            $zip->close();
        }
    }

    /**
     * @param array{module:string,version:string,files:array<string,string>} $manifest
     */
    private function applyPackage(string $extractDir, array $manifest, string $stageDir): int
    {
        $backupDir = $stageDir . '/backup';
        $this->ensureDirectory($backupDir);
        $rollbackPlan = [];
        $rollbackIndex = [];
        $changedFiles = 0;

        try {
            $oldManifest = $this->readInstalledManifest();
            $oldFiles = $oldManifest['files'] ?? [];

            foreach (array_keys($oldFiles) as $oldPath) {
                if (!isset($manifest['files'][$oldPath]) && !$this->isExcluded($oldPath)) {
                    $target = $this->safeTargetPath($oldPath);
                    $this->backupTarget(
                        $target,
                        $oldPath,
                        $backupDir,
                        $rollbackPlan,
                        $rollbackIndex,
                        $stageDir . '/rollback.json'
                    );
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
                if (is_link($target)) {
                    throw new RuntimeException('Symbolische Updateziele sind nicht erlaubt: ' . $path);
                }
                if (is_file($target) && hash_file('sha256', $target) === $hash) {
                    continue;
                }

                $this->ensureDirectory(dirname($target));
                $this->backupTarget(
                    $target,
                    $path,
                    $backupDir,
                    $rollbackPlan,
                    $rollbackIndex,
                    $stageDir . '/rollback.json'
                );
                $temporaryTarget = $target . '.bx-update-' . bin2hex(random_bytes(4));
                if (!copy($source, $temporaryTarget)) {
                    throw new RuntimeException('Datei konnte nicht vorbereitet werden: ' . $path);
                }
                if (!rename($temporaryTarget, $target)) {
                    if (is_file($target) && !unlink($target)) {
                        @unlink($temporaryTarget);
                        throw new RuntimeException('Vorhandene Datei konnte nicht ersetzt werden: ' . $path);
                    }
                    if (!rename($temporaryTarget, $target)) {
                        @unlink($temporaryTarget);
                        throw new RuntimeException('Datei konnte nicht installiert werden: ' . $path);
                    }
                }
                if (function_exists('opcache_invalidate')) {
                    @opcache_invalidate($target, true);
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

    private function backupTarget(
        string $target,
        string $relativePath,
        string $backupDir,
        array &$rollbackPlan,
        array &$rollbackIndex,
        string $rollbackPlanPath
    ): void
    {
        if (isset($rollbackIndex[$relativePath])) {
            return;
        }

        if (is_link($target)) {
            throw new RuntimeException('Symbolische Datei kann nicht gesichert werden: ' . $relativePath);
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
        $rollbackIndex[$relativePath] = count($rollbackPlan) - 1;
        $this->writeJsonAtomically($rollbackPlanPath, $rollbackPlan);
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
        $mappedPath = $relativePath;
        if ($relativePath === 'admin') {
            $mappedPath = $this->adminDirectory;
        } elseif (strpos($relativePath, 'admin/') === 0) {
            $mappedPath = $this->adminDirectory . '/' . substr($relativePath, strlen('admin/'));
        }
        $target = $this->shopRoot . '/' . $mappedPath;
        $existing = $this->shopRoot;
        $segments = explode('/', $mappedPath);
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
            || strpos($path, '\\') !== false
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
        $attempt = 0;
        $currentUrl = $url;
        while ($attempt++ < 4) {
            $this->assertGithubUrl($currentUrl);
            $handle = curl_init($currentUrl);
            if ($handle === false) {
                throw new RuntimeException('cURL konnte nicht initialisiert werden.');
            }
            curl_setopt_array($handle, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_CONNECTTIMEOUT => 5,
                CURLOPT_TIMEOUT => 15,
                CURLOPT_HTTPHEADER => $this->githubHeaders($accept, $currentUrl),
                CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
                CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_UNRESTRICTED_AUTH => false,
                CURLOPT_HEADER => true,
            ]);
            $response = curl_exec($handle);
            $status = (int) curl_getinfo($handle, CURLINFO_HTTP_CODE);
            $headerSize = (int) curl_getinfo($handle, CURLINFO_HEADER_SIZE);
            $error = (string) curl_error($handle);
            curl_close($handle);
            if (!is_string($response)) {
                throw new RuntimeException($error !== '' ? $error : 'GitHub-Antwort konnte nicht gelesen werden.');
            }
            $headers = substr($response, 0, $headerSize);
            $body = substr($response, $headerSize);
            if (in_array($status, [301, 302, 303, 307, 308], true)) {
                if (!preg_match('/^Location:\s*(.+)$/mi', $headers, $match)) {
                    throw new RuntimeException('GitHub-Redirect ohne Ziel.');
                }
                $currentUrl = $this->resolveRedirectUrl($currentUrl, trim($match[1]));
                continue;
            }
            if ($status < 200 || $status >= 300) {
                throw new RuntimeException($error !== '' ? $error : 'GitHub antwortete mit HTTP ' . $status . '.');
            }
            return $body;
        }
        throw new RuntimeException('Zu viele GitHub-Redirects.');
    }

    private function downloadFile(string $url, string $destination): void
    {
        $currentUrl = $url;
        for ($attempt = 0; $attempt < 4; $attempt++) {
            $this->assertGithubUrl($currentUrl);
            $file = fopen($destination, 'wb');
            $handle = curl_init($currentUrl);
            if ($handle === false || $file === false) {
                if (is_resource($handle)) {
                    curl_close($handle);
                }
                if (is_resource($file)) {
                    fclose($file);
                }
                throw new RuntimeException('Download konnte nicht vorbereitet werden.');
            }
            $headers = '';
            $downloadedBytes = 0;
            curl_setopt_array($handle, [
                CURLOPT_FILE => $file,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_CONNECTTIMEOUT => 5,
                CURLOPT_TIMEOUT => 60,
                CURLOPT_HTTPHEADER => $this->githubHeaders('application/octet-stream', $currentUrl),
                CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
                CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_UNRESTRICTED_AUTH => false,
                CURLOPT_MAXFILESIZE => $this->maximumArchiveSize,
                CURLOPT_NOPROGRESS => false,
                CURLOPT_XFERINFOFUNCTION => function (
                    $curl,
                    int $downloadSize,
                    int $downloaded,
                    int $uploadSize,
                    int $uploaded
                ) use (&$downloadedBytes): int {
                    $downloadedBytes = $downloaded;
                    return $downloaded > $this->maximumArchiveSize ? 1 : 0;
                },
                CURLOPT_HEADERFUNCTION => static function ($handle, string $header) use (&$headers): int {
                    $headers .= $header;
                    return strlen($header);
                },
            ]);
            $success = curl_exec($handle);
            $status = (int) curl_getinfo($handle, CURLINFO_HTTP_CODE);
            $error = (string) curl_error($handle);
            curl_close($handle);
            fclose($file);
            if ($success && in_array($status, [301, 302, 303, 307, 308], true)) {
                if (!preg_match('/^Location:\s*(.+)$/mi', $headers, $match)) {
                    @unlink($destination);
                    throw new RuntimeException('GitHub-Redirect ohne Ziel.');
                }
                $currentUrl = $this->resolveRedirectUrl($currentUrl, trim($match[1]));
                continue;
            }
            if (!$success || $status < 200 || $status >= 300) {
                @unlink($destination);
                throw new RuntimeException(
                    $error !== '' ? $error : 'Release-Download schlug mit HTTP ' . $status . ' fehl.'
                );
            }
            break;
        }
        if ($attempt >= 4) {
            @unlink($destination);
            throw new RuntimeException('Zu viele GitHub-Redirects.');
        }
        $size = filesize($destination);
        if ($downloadedBytes > $this->maximumArchiveSize) {
            @unlink($destination);
            throw new RuntimeException('Das Release-Archiv überschreitet die Größenbegrenzung.');
        }
        if ($size === false || $size <= 0 || $size > $this->maximumArchiveSize) {
            @unlink($destination);
            throw new RuntimeException('Die Größe des Release-Archivs ist ungültig.');
        }
    }

    private function githubHeaders(string $accept, string $url): array
    {
        $headers = [
            'Accept: ' . $accept,
            'User-Agent: BX-GitHub-Module-Updater/1.0',
            'X-GitHub-Api-Version: 2022-11-28',
        ];
        if ($this->githubToken !== null && strtolower((string) parse_url($url, PHP_URL_HOST)) === 'api.github.com') {
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
            || !in_array($host, [
                'api.github.com',
                'github.com',
                'objects.githubusercontent.com',
                'release-assets.githubusercontent.com',
            ], true)
        ) {
            throw new RuntimeException('Unerwartete Download-URL von GitHub.');
        }
    }

    private function resolveRedirectUrl(string $baseUrl, string $location): string
    {
        if ($location === '') {
            throw new RuntimeException('GitHub-Redirect ohne Ziel.');
        }
        if (filter_var($location, FILTER_VALIDATE_URL)) {
            return $location;
        }

        $parts = parse_url($baseUrl);
        if (!is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) {
            throw new RuntimeException('Die Redirect-Basis-URL ist ungültig.');
        }
        $origin = $parts['scheme'] . '://' . $parts['host'];
        if (isset($parts['port'])) {
            $origin .= ':' . $parts['port'];
        }
        if (substr($location, 0, 2) === '//') {
            return $parts['scheme'] . ':' . $location;
        }
        if (substr($location, 0, 1) === '/') {
            return $origin . $location;
        }
        if (substr($location, 0, 1) === '?') {
            return $origin . ($parts['path'] ?? '/') . $location;
        }

        $basePath = $parts['path'] ?? '/';
        $slashPosition = strrpos($basePath, '/');
        $directory = $slashPosition === false ? '/' : substr($basePath, 0, $slashPosition + 1);
        $combined = $directory . $location;
        $segments = [];
        foreach (explode('/', $combined) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }
            if ($segment === '..') {
                array_pop($segments);
                continue;
            }
            $segments[] = $segment;
        }
        return $origin . '/' . implode('/', $segments);
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

    private function isAllowed(string $path): bool
    {
        if (
            substr(basename($path), 0, 1) === '.'
            || strpos($path, '/.') !== false
            || strpos($path, 'cache/') === 0
            || in_array($path, ['.htaccess', '.user.ini', 'index.php'], true)
            || preg_match('#(^|/)includes/configure\.php$#i', $path) === 1
            || preg_match('#(^|/)admin/includes/configure\.php$#i', $path) === 1
        ) {
            return false;
        }
        foreach ($this->allowedPaths as $allowedPath) {
            if ($path === $allowedPath || strpos($path, $allowedPath . '/') === 0) {
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
        $this->protectCacheDirectory();
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
        if (!rename($temporary, $path)) {
            if (is_file($path) && !unlink($path)) {
                @unlink($temporary);
                throw new RuntimeException('Vorhandene JSON-Datei konnte nicht ersetzt werden.');
            }
            if (!rename($temporary, $path)) {
                @unlink($temporary);
                throw new RuntimeException('JSON-Datei konnte nicht installiert werden.');
            }
        }
    }

    private function ensureDirectory(string $path): void
    {
        if (is_dir($path)) {
            return;
        }
        if (!mkdir($path, 0755, true) && !is_dir($path)) {
            throw new RuntimeException('Verzeichnis konnte nicht angelegt werden: ' . basename($path));
        }
    }

    private function protectCacheDirectory(): void
    {
        $this->ensureDirectory($this->cacheDir);
        $protectionFile = $this->cacheDir . '/.htaccess';
        if (
            !is_file($protectionFile)
            && file_put_contents(
                $protectionFile,
                "<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n"
                . "<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n",
                LOCK_EX
            ) === false
        ) {
            throw new RuntimeException('Das Cache-Verzeichnis konnte nicht abgesichert werden.');
        }
    }

    private function safeGithubReleaseUrl($url): ?string
    {
        if (!is_string($url) || !filter_var($url, FILTER_VALIDATE_URL)) {
            return null;
        }
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        return strtolower((string) parse_url($url, PHP_URL_SCHEME)) === 'https'
            && $host === 'github.com'
            ? $url
            : null;
    }

    private function recoverStaleStages(): void
    {
        $entries = glob($this->cacheDir . '/stage-*');
        if (!is_array($entries)) {
            return;
        }
        foreach ($entries as $stageDir) {
            if (!is_dir($stageDir)) {
                continue;
            }
            $errors = $this->rollback($stageDir);
            if ($errors !== []) {
                throw new RuntimeException(
                    'Ein vorheriger Updatevorgang konnte nicht vollständig wiederhergestellt werden. '
                    . 'Stage: ' . basename($stageDir)
                );
            }
            $this->removeTree($stageDir);
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
