<?php

declare(strict_types=1);

/**
 * Independent, signed GitHub Release updater for modified eCommerce modules.
 *
 * PURPOSE
 * =======
 * Checks one configured GitHub repository for a newer release, caches its
 * metadata and, after an explicit administrator action, installs a verified ZIP
 * directly into the shop. The implementation deliberately has no dependency on
 * MMLC or another distribution server.
 *
 * The complete trust chain is:
 *
 *   GitHub latest release
 *       -> exact ZIP asset for module and semantic version
 *       -> SHA-256 digest of the complete ZIP
 *       -> signed .bx-module-manifest.json
 *       -> SHA-256 digest of every package file
 *       -> allowed-path policy and installation
 *
 * RELEASE PACKAGE CONTRACT
 * ========================
 * Tags use semantic versions such as v1.2.4. The Release contains:
 *
 *   <module-name>-<version>.zip
 *   <module-name>-<version>.zip.sha256
 *
 * The ZIP is flat and uses portable, shop-relative paths:
 *
 *   admin/includes/modules/system/example.php
 *   lang/german/modules/system/example.php
 *   media/content/example.php
 *
 * Its root must also contain:
 *
 *   .bx-module-manifest.json
 *   .bx-module-manifest.sig
 *
 * The manifest binds module name, release version and every file hash. The
 * signature is an Ed25519 detached signature, Base64 encoded, over the exact
 * manifest bytes. Both files are verified before package files reach the shop.
 *
 * SIGNING AND KEY MANAGEMENT
 * ==========================
 * The public Ed25519 key is deployment data and may be committed with the
 * module. It is passed as constructor option "public_key". The matching private
 * key must NEVER be committed, copied into a release or installed in a shop.
 *
 * The GitHub Actions workflow reads the private key from the organization or
 * repository Actions secret BX_RELEASE_SIGNING_KEY and signs the generated
 * manifest. One organization-wide key may sign all maintained repositories.
 *
 * Public key requirements:
 * - Base64 format;
 * - decodes to exactly 32 bytes.
 *
 * Private key requirements:
 * - Base64 format;
 * - decodes to exactly 64 bytes;
 * - exists only in secure local/offline storage and GitHub Secrets.
 *
 * The ZIP checksum protects transport integrity. Ed25519 proves package
 * authenticity relative to the signing key. An attacker who only changes release
 * assets cannot create an installable package. A compromised GitHub maintainer
 * may still abuse an accessible CI signing secret; therefore workflow permissions,
 * protected environments, tag rules and required reviewers are also part of the
 * trust model.
 *
 * REQUIRED CONSTRUCTOR CONFIGURATION
 * =================================
 * - owner:              GitHub organization or user.
 * - repo:               GitHub repository name.
 * - module_name:        package/release prefix; defaults to repo.
 * - module_prefix:      upper-case modified configuration prefix.
 * - current_version:    installed semantic version.
 * - public_key:          Base64-encoded Ed25519 public key; mandatory.
 * - allowed_paths:       release paths which may be installed or removed.
 *
 * Optional configuration:
 * - admin_dir:             actual admin directory; normally DIR_ADMIN.
 * - shop_root:             shop root; defaults to DIR_FS_CATALOG.
 * - cache_dir:             updater state directory.
 * - github_token:          private-repository token, sent only to GitHub API.
 * - cache_ttl_seconds:     successful cache lifetime; default 1 hour.
 * - exclude_paths:         merchant-customized paths never to replace/remove.
 * - maximum_archive_size
 * - maximum_extracted_size
 * - maximum_manifest_size
 *
 * ADMIN DIRECTORY MAPPING
 * =======================
 * Release paths deliberately retain "admin/" as a portable logical prefix.
 * The updater maps that prefix to the admin directory selected during modified
 * installation:
 *
 *   admin/includes/modules/system/example.php
 *       -> admin_1x2y3z/includes/modules/system/example.php
 *
 * Manifest and allowed_paths therefore always use "admin/". Catalog paths such
 * as includes/, lang/ and media/ are not remapped. DIR_ADMIN should be passed as
 * admin_dir by the module.
 *
 * CHECK AND CACHE BEHAVIOUR
 * =========================
 * checkForUpdate() requests /repos/<owner>/<repo>/releases/latest. HTTPS, every
 * redirect target, release version, asset name and repository-specific asset
 * URL are validated. Successful metadata is cached in:
 *
 *   <shop_root>/cache/bx_github_updater/<module_name>/release.json
 *
 * Errors use a much shorter cache lifetime, preventing a temporary network
 * failure or GitHub rate limit from hiding a release for 24 hours. The UI offers
 * installation only when all three conditions hold: newer version, ZIP URL and
 * complete SHA-256 digest.
 *
 * INSTALLATION FLOW
 * =================
 * installLatestUpdate() performs this sequence:
 *
 * 1. Verify required extensions and parse the configuration.
 * 2. Acquire an exclusive, non-blocking per-module lock.
 * 3. Refresh and validate release metadata.
 * 4. Download the exact ZIP while enforcing redirect, protocol and byte limits.
 * 5. Verify the SHA-256 digest of the complete ZIP.
 * 6. Inspect entries for traversal, absolute paths, links, duplicates and size.
 * 7. Read the bounded manifest and verify its Ed25519 signature.
 * 8. Require exact agreement between ZIP entries and manifest entries.
 * 9. Stream package files into staging and hash them in 64 KiB chunks.
 * 10. Back up affected files and write a rollback record incrementally.
 * 11. Install only allowed paths and map the logical admin directory.
 * 12. Optionally run a callback that is not replaced by this package.
 * 13. Store the installed manifest and updated module configuration version.
 * 14. Write a commit marker and audit entry, then invalidate OPcache.
 * 15. Remove staging only after the committed state has been recorded.
 *
 * RECOVERY AND CONCURRENCY
 * ========================
 * The lock prevents parallel updates of the same module. Every changed file is
 * backed up before replacement. Obsolete files are removed only when listed in
 * the previous installed, re-verified manifest and not excluded. The existing
 * installed manifest is copied before download or package validation and its
 * original existence is recorded as had_manifest. Recovery never deletes an
 * existing manifest unless stage metadata proves that no manifest existed
 * before the update. Rollback plans, backup names and recovery paths are
 * validated before use.
 *
 * A committed.json marker plus stage metadata distinguishes an interrupted
 * operation from an already committed update. Recovery therefore cleans a
 * committed stage without rolling back successfully installed files; an
 * uncommitted stage is rolled back, including manifest and stored version state.
 * A compact JSON-lines audit record is appended to update-audit.log.
 *
 * A database transaction cannot atomically span filesystem, MySQL DDL and PHP
 * process lifetime. Migration callbacks must therefore be idempotent, manage
 * their own DB transactions and tolerate an earlier partial execution.
 * Package migrations must run in the following request so PHP loads the newly
 * installed class definition.
 *
 * SECURITY POLICY
 * ===============
 * - HTTPS only with explicit TLS verification.
 * - Every redirect destination is checked again.
 * - GitHub token is sent only to api.github.com.
 * - Repository-specific exact asset names and URLs only.
 * - No traversal, absolute path, backslash path, duplicate or symlink.
 * - No undeclared archive file and no write outside allowed_paths.
 * - Dotfiles, cache paths and sensitive files such as configure.php are denied.
 * - ZIP download, unpacked total and manifest sizes are bounded.
 * - Ordinary package files are streamed instead of fully buffered in memory.
 * - Download progress uses CURLOPT_XFERINFOFUNCTION where available and falls
 *   back to CURLOPT_PROGRESSFUNCTION on PHP 8.0/8.1.
 * - public_key is mandatory; unsigned packages cannot be installed.
 * - The locally stored installed manifest is re-verified by signature before it
 *   is allowed to drive obsolete-file deletion.
 *
 * CALLER RESPONSIBILITIES
 * =======================
 * This class does not inspect the modified admin session or ACL. The caller
 * must expose update actions only to authenticated administrators, use a
 * CSRF-protected POST, escape release notes/URLs before HTML output and provide
 * an idempotent migration callback where needed. A business-critical production
 * update should additionally have a normal shop/database backup.
 *
 * RUNTIME REQUIREMENTS
 * ====================
 * - PHP 8.0 or newer.
 * - ext-curl for API requests and downloads.
 * - ext-zip for archive validation.
 * - ext-sodium for Ed25519 signature verification.
 * - modified DIR_FS_CATALOG and TABLE_CONFIGURATION.
 * - modified xtc_db_query(), xtc_db_input() and xtc_db_num_rows().
 * - A writable, protected cache directory.
 * - A GitHub workflow with protected access to BX_RELEASE_SIGNING_KEY.
 *
 * WINDOWS / XAMPP NOTE
 * ====================
 * CLI and Apache may load different php.ini files or extension search paths.
 * Always verify Sodium through the web SAPI. In XAMPP, Apache loads
 * C:\xampp\php\php.ini; native dependencies may require explicit LoadFile
 * entries in httpd-xampp.conf. On PHP builds affected by Windows ASLR, configure
 * opcache.file_cache and opcache.file_cache_fallback as in the current project.
 * The installer uses a bounded 300-second execution window and a private 0700
 * staging directory.
 *
 * @package    BX\Github
 * @author     Axel Benkert <mail@bx-coding.de>
 * @copyright  2026-present BX Coding
 * @license    GNU General Public License, version 2 or later
 * @link       https://github.com/bx-codemaster/bx-block-contacts
 * @since      1.1.0
 * @requires   PHP >= 8.0
 * @requires   ext-curl
 * @requires   ext-zip
 * @requires   ext-sodium
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
    private int $checkConnectTimeout;
    private int $checkTimeout;
    private ?string $publicKey;

    /** @var string[] */
    private array $excludePaths;

    /** @var string[] */
    private array $allowedPaths;

    private const MANIFEST_FILE = '.bx-module-manifest.json';
    private const MANIFEST_SIGNATURE_FILE = '.bx-module-manifest.sig';
    private const CHECK_CACHE_FILE = 'release.json';
    private const INSTALLED_MANIFEST_FILE = 'installed-manifest.json';
    private const COMMIT_MARKER_FILE = 'committed.json';
    private const STAGE_META_FILE = 'stage-meta.json';
    private const AUDIT_LOG_FILE = 'update-audit.log';

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
        $this->cacheTtl = max(60, (int) ($config['cache_ttl_seconds'] ?? 3600));
        $this->maximumArchiveSize = max(
            1024 * 1024,
            (int) ($config['maximum_archive_size'] ?? 50 * 1024 * 1024)
        );
        $this->maximumManifestSize = max(10240, (int) ($config['maximum_manifest_size'] ?? 1024 * 1024));
        $this->maximumExtractedSize = max(
            $this->maximumArchiveSize,
            (int) ($config['maximum_extracted_size'] ?? 4 * $this->maximumArchiveSize)
        );
        $this->checkConnectTimeout = max(1, (int) ($config['check_connect_timeout'] ?? 2));
        $this->checkTimeout = max(2, (int) ($config['check_timeout'] ?? 5));
        $publicKey = $config['public_key'] ?? null;
        $this->publicKey = is_string($publicKey) ? trim($publicKey) : '';
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
            preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]*$/', $this->owner) !== 1
            || preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]*$/', $this->repo) !== 1
            || preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]*$/', $this->moduleName) !== 1
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
        if ($this->publicKey === '') {
            throw new InvalidArgumentException('public_key is required.');
        }
        $decodedPublicKey = base64_decode($this->publicKey, true);
        if (
            $decodedPublicKey === false
            || strlen($decodedPublicKey) !== 32
            || base64_encode($decodedPublicKey) !== $this->publicKey
        ) {
            throw new InvalidArgumentException('public_key must be a base64-encoded Ed25519 public key.');
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
                if (
                    !empty($cached['zip_url'])
                    && !$this->isExpectedAssetUrl(
                        (string) $cached['zip_url'],
                        sprintf('%s-%s.zip', $this->moduleName, $latestVersion)
                    )
                ) {
                    $cached['zip_url'] = null;
                    $cached['has_update'] = false;
                    $cached['error'] = 'Gecachte ZIP-URL ist nicht gültig.';
                }
                if (!empty($cached['html_url'])) {
                    $cached['html_url'] = $this->safeGithubReleaseUrl($cached['html_url']);
                }
                $cached['has_update'] = empty($cached['error'])
                    && !empty($cached['zip_url'])
                    && $this->isNewer($latestVersion, $this->currentVersion);
                $cached['current_version'] = $this->currentVersion;
                return array_merge($this->emptyStatus(), $cached);
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
     * Do not pass a class from the release package itself: PHP has already loaded
     * the old class definition in the current request.
     *
     * @param null|callable(string,string):void $migration
     * @return array{ok:bool,message:string,version?:string}
     */
    public function installLatestUpdate(?callable $migration = null): array
    {
        if (!extension_loaded('sodium')) {
            return ['ok' => false, 'message' => 'Die PHP-Erweiterung sodium ist für signierte Updates nicht verfügbar.'];
        }
        if (!extension_loaded('curl')) {
            return ['ok' => false, 'message' => 'Die PHP-Erweiterung cURL ist nicht verfügbar.'];
        }
        if (!class_exists('ZipArchive')) {
            return ['ok' => false, 'message' => 'Die PHP-Erweiterung ZipArchive ist nicht verfügbar.'];
        }

        try {
            $this->protectCacheDirectory();
        } catch (Throwable $exception) {
            return ['ok' => false, 'message' => 'Das Update-Verzeichnis konnte nicht vorbereitet werden.'];
        }
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
            set_time_limit(300);
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
            $this->ensureDirectory($extractDir, 0700);

            $installedManifestPath = $this->cacheDir . '/' . self::INSTALLED_MANIFEST_FILE;
            $previousManifestPath = $stageDir . '/previous-installed-manifest.json';
            $hadInstalledManifest = is_file($installedManifestPath);
            if ($hadInstalledManifest && !copy($installedManifestPath, $previousManifestPath)) {
                throw new RuntimeException('Das vorhandene Modulmanifest konnte nicht gesichert werden.');
            }
            $this->writeJsonAtomically($stageDir . '/' . self::STAGE_META_FILE, [
                'from_version' => $this->currentVersion,
                'version' => $status['latest_version'],
                'had_manifest' => $hadInstalledManifest,
            ]);

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
            $this->writeJsonAtomically($stageDir . '/' . self::STAGE_META_FILE, [
                'from_version' => $this->currentVersion,
                'version' => $status['latest_version'],
                'had_manifest' => $hadInstalledManifest,
                'zip_sha256' => (string) $status['sha256'],
                'manifest_sha256' => hash('sha256', $manifest['manifest_json']),
            ]);
            $changedFiles = $this->applyPackage($extractDir, $manifest, $stageDir);

            if ($migration !== null) {
                $migration($this->currentVersion, $status['latest_version']);
            }

            $this->writeJsonAtomically(
                $installedManifestPath,
                [
                    'manifest' => $manifest,
                    'raw' => $manifest['manifest_json'],
                    'signature' => $manifest['manifest_signature'],
                ]
            );
            $this->setInstalledVersion($status['latest_version']);
            $this->currentVersion = $status['latest_version'];
            $this->writeJsonAtomically($stageDir . '/' . self::COMMIT_MARKER_FILE, [
                'version' => $status['latest_version'],
                'zip_sha256' => (string) $status['sha256'],
                'committed_at' => time(),
            ]);
            $this->markStageCommitted($stageDir);
            $this->removePath($this->cacheFilePath());
            $this->writeAuditEntry(true, $this->currentVersion, (string) $status['sha256'], $changedFiles);

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
            $committed = $stageDir !== '' && $this->isCommittedStage($stageDir);
            if (!$committed && $stageDir !== '') {
                $rollbackErrors = $this->rollback($stageDir);
                $rollbackErrors = array_merge($rollbackErrors, $this->restoreStageMetadata($stageDir));
            }
            $message = $exception->getMessage();
            if ($rollbackErrors !== []) {
                $message .= ' Rollback unvollständig: ' . implode(' ', $rollbackErrors);
            }
            if (!$committed) {
                $this->writeAuditEntry(
                    false,
                    $this->getLatestVersionFromMeta($stageDir),
                    '',
                    0,
                    $message
                );
            }
            if ($committed) {
                $this->writeAuditEntry(
                    true,
                    $this->getLatestVersionFromMeta($stageDir),
                    '',
                    0,
                    'Commit abgeschlossen; Cleanup ergab einen Fehler.'
                );
                return [
                    'ok' => true,
                    'message' => 'Update abgeschlossen. Die temporäre Bereinigung wird beim nächsten Lauf fortgesetzt.',
                    'version' => $this->getLatestVersionFromMeta($stageDir),
                ];
            }
            return ['ok' => false, 'message' => $message];
        } finally {
            if ($stageDir !== '' && $this->isCommittedStage($stageDir)) {
                $this->removeStageAfterCommit($stageDir);
            } elseif ($stageDir !== '') {
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
                if (!$this->isExpectedAssetUrl($assetUrl, $expectedZipName)) {
                    return $this->emptyStatus('Release-ZIP gehört nicht zum erwarteten Repository.');
                }
                $zipUrl = $assetUrl;
                $digest = (string) ($asset['digest'] ?? '');
                if (preg_match('/^sha256:([a-f0-9]{64})$/i', $digest, $match)) {
                    $sha256 = strtolower($match[1]);
                }
            } elseif ($name === $expectedShaName) {
                if (!$this->isExpectedAssetUrl($assetUrl, $expectedShaName)) {
                    return $this->emptyStatus('Prüfsummen-Asset gehört nicht zum erwarteten Repository.');
                }
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
            $signatureStat = $zip->statName(self::MANIFEST_SIGNATURE_FILE);
            if (
                !is_array($signatureStat)
                || (int) ($signatureStat['size'] ?? -1) <= 0
                || (int) $signatureStat['size'] > 256
            ) {
                throw new RuntimeException('Die Manifest-Signatur fehlt oder ist zu groß.');
            }
            $signatureContent = $zip->getFromName(self::MANIFEST_SIGNATURE_FILE);
            if (!is_string($signatureContent) || strlen($signatureContent) !== (int) $signatureStat['size']) {
                throw new RuntimeException('Die Manifest-Signatur fehlt.');
            }
            $signature = trim($signatureContent);
            if (!$this->verifyManifestSignature($manifestContent, $signature)) {
                throw new RuntimeException('Die Manifest-Signatur ist ungültig.');
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
            $expectedArchiveFiles[self::MANIFEST_SIGNATURE_FILE] = true;
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
                'manifest_json' => $manifestContent,
                'manifest_signature' => $signature,
            ];
        } finally {
            $zip->close();
        }
    }

    private function verifyManifestSignature(string $manifest, string $signature): bool
    {
        if (!extension_loaded('sodium')) {
            return false;
        }
        $publicKey = base64_decode($this->publicKey, true);
        $decodedSignature = base64_decode(trim($signature), true);
        return is_string($publicKey)
            && strlen($publicKey) === SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES
            && is_string($decodedSignature)
            && strlen($decodedSignature) === SODIUM_CRYPTO_SIGN_BYTES
            && sodium_crypto_sign_verify_detached($decodedSignature, $manifest, $publicKey);
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
                if (
                    !isset($manifest['files'][$oldPath])
                    && $this->isAllowed((string) $oldPath)
                    && !$this->isExcluded((string) $oldPath)
                ) {
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

                $this->recordTargetDirectories(
                    dirname($path),
                    $rollbackPlan,
                    $rollbackIndex,
                    $stageDir . '/rollback.json'
                );
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
                $temporaryHash = hash_file('sha256', $temporaryTarget);
                if (!is_string($temporaryHash) || !hash_equals($hash, $temporaryHash)) {
                    @unlink($temporaryTarget);
                    throw new RuntimeException('Vorbereitete Datei wurde verändert: ' . $path);
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
                $path = (string) $entry['path'];
                $isDirectory = ($entry['type'] ?? 'file') === 'directory';
                if (
                    ($isDirectory ? !$this->isAllowedDirectory($path) : !$this->isAllowed($path))
                    || $this->isExcluded($path)
                ) {
                    throw new RuntimeException('Rollback-Pfad ist nicht erlaubt: ' . $path);
                }
                if ($isDirectory) {
                    @rmdir($this->safeTargetPath($path));
                    continue;
                }
                $target = $this->safeTargetPath($path);
                if (!empty($entry['existed'])) {
                    $this->ensureDirectory(dirname($target));
                    $backupName = (string) ($entry['backup'] ?? '');
                    if (preg_match('/^[a-f0-9]{16}$/', $backupName) !== 1) {
                        throw new RuntimeException('Sicherungsdateiname ist ungültig.');
                    }
                    $backup = $backupDir . '/' . $backupName;
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

    private function recordTargetDirectories(
        string $relativeDirectory,
        array &$rollbackPlan,
        array &$rollbackIndex,
        string $rollbackPlanPath
    ): void {
        if ($relativeDirectory === '.' || $relativeDirectory === '') {
            return;
        }
        $current = '';
        foreach (explode('/', $relativeDirectory) as $segment) {
            $current = $current === '' ? $segment : $current . '/' . $segment;
            $target = $this->safeTargetPath($current);
            if (is_dir($target)) {
                continue;
            }
            if (file_exists($target) || is_link($target)) {
                throw new RuntimeException('Zielpfad ist kein Verzeichnis: ' . $current);
            }
            if (isset($rollbackIndex['directory:' . $current])) {
                continue;
            }
            $this->ensureDirectory($target, 0755);
            $rollbackPlan[] = [
                'type' => 'directory',
                'path' => $current,
            ];
            $rollbackIndex['directory:' . $current] = count($rollbackPlan) - 1;
            $this->writeJsonAtomically($rollbackPlanPath, $rollbackPlan);
        }
    }

    private function readInstalledManifest(): array
    {
        $path = $this->cacheDir . '/' . self::INSTALLED_MANIFEST_FILE;
        if (!is_file($path)) {
            return [];
        }
        $stored = json_decode((string) file_get_contents($path), true);
        if (
            !is_array($stored)
            || !is_string($stored['raw'] ?? null)
            || !is_string($stored['signature'] ?? null)
            || !$this->verifyManifestSignature($stored['raw'], $stored['signature'])
        ) {
            return [];
        }
        $manifest = json_decode($stored['raw'], true);
        if (
            !is_array($manifest)
            || ($manifest['module'] ?? '') !== $this->moduleName
            || !is_array($manifest['files'] ?? null)
        ) {
            return [];
        }
        foreach ($manifest['files'] as $pathValue => $hash) {
            if (
                !is_string($pathValue)
                || !is_string($hash)
                || preg_match('/^[a-f0-9]{64}$/i', $hash) !== 1
                || !$this->isAllowed($pathValue)
                || $this->isExcluded($pathValue)
            ) {
                return [];
            }
        }
        return $manifest;
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
            $windowsReserved = preg_match(
                '/^(con|prn|aux|nul|com[1-9]|lpt[1-9])(?:\.|$)/i',
                $segment
            ) === 1;
            if (
                $segment === ''
                || $segment === '.'
                || $segment === '..'
                || strpos($segment, ':') !== false
                || $segment !== rtrim($segment, " .")
                || $windowsReserved
            ) {
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
            $responseBody = '';
            $headers = '';
            curl_setopt_array($handle, [
                CURLOPT_RETURNTRANSFER => false,
                CURLOPT_WRITEFUNCTION => function ($curlHandle, string $chunk) use (&$responseBody): int {
                    $length = strlen($chunk);
                    if (strlen($responseBody) + $length > 1024 * 1024) {
                        return 0;
                    }
                    $responseBody .= $chunk;
                    return $length;
                },
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_CONNECTTIMEOUT => $this->checkConnectTimeout,
                CURLOPT_TIMEOUT => $this->checkTimeout,
                CURLOPT_HTTPHEADER => $this->githubHeaders($accept, $currentUrl),
                CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
                CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_UNRESTRICTED_AUTH => false,
                CURLOPT_HEADER => false,
                CURLOPT_HEADERFUNCTION => static function ($curlHandle, string $header) use (&$headers): int {
                    $headers .= $header;
                    return strlen($header);
                },
            ]);
            $response = curl_exec($handle);
            $status = (int) curl_getinfo($handle, CURLINFO_HTTP_CODE);
            $error = (string) curl_error($handle);
            curl_close($handle);
            if ($response === false) {
                throw new RuntimeException($error !== '' ? $error : 'GitHub-Antwort konnte nicht gelesen werden.');
            }
            $body = $responseBody;
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
        $downloadedBytes = 0;
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
            $progressCallback = function (
                $curlHandle,
                $downloadSize,
                $downloaded,
                $uploadSize,
                $uploaded
            ) use (&$downloadedBytes): int {
                $downloadedBytes = (int) $downloaded;
                return $downloadedBytes > $this->maximumArchiveSize ? 1 : 0;
            };
            $progressOption = defined('CURLOPT_XFERINFOFUNCTION')
                ? constant('CURLOPT_XFERINFOFUNCTION')
                : constant('CURLOPT_PROGRESSFUNCTION');
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
                $progressOption           => $progressCallback,
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
        $parts = parse_url($url);
        $host = strtolower((string) ($parts['host'] ?? ''));
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        if (
            $scheme !== 'https'
            || !in_array($host, [
                'api.github.com',
                'github.com',
                'objects.githubusercontent.com',
                'release-assets.githubusercontent.com',
            ], true)
            || isset($parts['user'])
            || isset($parts['pass'])
            || (isset($parts['port']) && (int) $parts['port'] !== 443)
        ) {
            throw new RuntimeException('Unerwartete Download-URL von GitHub.');
        }
    }

    private function isExpectedAssetUrl(string $url, string $expectedAssetName): bool
    {
        try {
            $this->assertGithubUrl($url);
        } catch (Throwable $exception) {
            return false;
        }
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        $path = (string) parse_url($url, PHP_URL_PATH);
        $repository = preg_quote(rawurlencode($this->owner) . '/' . rawurlencode($this->repo), '#');
        $asset = preg_quote(rawurlencode($expectedAssetName), '#');
        if ($host === 'api.github.com') {
            return preg_match('#^/repos/' . $repository . '/releases/assets/[0-9]+$#i', $path) === 1;
        }
        return preg_match(
            '#^/' . $repository . '/releases/download/v?[^/]+/' . $asset . '$#i',
            $path
        ) === 1;
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
        $normalizedPath = strtolower($path);
        foreach ($this->excludePaths as $excluded) {
            $normalizedExcluded = strtolower($excluded);
            if (
                $normalizedPath === $normalizedExcluded
                || strpos($normalizedPath, $normalizedExcluded . '/') === 0
            ) {
                return true;
            }
        }
        return false;
    }

    private function isAllowed(string $path): bool
    {
        $normalizedPath = strtolower($path);
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
            $normalizedAllowed = strtolower($allowedPath);
            if (
                $normalizedPath === $normalizedAllowed
                || strpos($normalizedPath, $normalizedAllowed . '/') === 0
            ) {
                return true;
            }
        }
        return false;
    }

    private function isAllowedDirectory(string $path): bool
    {
        $normalizedDirectory = rtrim(strtolower($path), '/') . '/';
        foreach ($this->allowedPaths as $allowedPath) {
            $normalizedAllowed = rtrim(strtolower($allowedPath), '/') . '/';
            if (
                strpos($normalizedAllowed, $normalizedDirectory) === 0
                || strpos($normalizedDirectory, $normalizedAllowed) === 0
            ) {
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

    private function ensureDirectory(string $path, int $mode = 0755): void
    {
        if (is_dir($path)) {
            return;
        }
        if (!mkdir($path, $mode, true) && !is_dir($path)) {
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

    private function safeGithubReleaseUrl(mixed $url): ?string
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
        foreach ((array) glob($this->cacheDir . '/trash-*') as $trashDir) {
            if (is_dir($trashDir) && !is_link($trashDir)) {
                $this->removeTree($trashDir);
            }
        }
        $entries = glob($this->cacheDir . '/stage-*');
        if (!is_array($entries)) {
            return;
        }
        foreach ($entries as $stageDir) {
            if (!is_dir($stageDir)) {
                continue;
            }
            if ($this->isCommittedStage($stageDir)) {
                $this->removeStageAfterCommit($stageDir);
                continue;
            }
            $errors = $this->rollback($stageDir);
            $errors = array_merge($errors, $this->restoreStageMetadata($stageDir));
            if ($errors !== []) {
                throw new RuntimeException(
                    'Ein vorheriger Updatevorgang konnte nicht vollständig wiederhergestellt werden. '
                    . 'Stage: ' . basename($stageDir)
                );
            }
            $this->removeTree($stageDir);
        }
    }

    private function isCommittedStage(string $stageDir): bool
    {
        $markerPath = $stageDir . '/' . self::COMMIT_MARKER_FILE;
        if (!is_file($markerPath)) {
            return false;
        }
        $marker = json_decode((string) file_get_contents($markerPath), true);
        return is_array($marker)
            && is_string($marker['version'] ?? null)
            && (string) $marker['version'] === $this->readInstalledVersion();
    }

    private function markStageCommitted(string $stageDir): void
    {
        $trashDir = dirname($stageDir) . '/trash-' . substr(basename($stageDir), strlen('stage-'));
        if (!rename($stageDir, $trashDir)) {
            throw new RuntimeException('Committed Stage konnte nicht in den Cleanup-Bereich verschoben werden.');
        }
        $this->removeTree($trashDir);
    }

    private function removeStageAfterCommit(string $stageDir): void
    {
        $trashDir = dirname($stageDir) . '/trash-' . substr(basename($stageDir), strlen('stage-'));
        if (is_dir($stageDir) && !rename($stageDir, $trashDir)) {
            return;
        }
        $this->removeTree($trashDir);
    }

    private function readInstalledVersion(): string
    {
        $key = xtc_db_input('MODULE_' . $this->modulePrefix . '_VERSION');
        $query = xtc_db_query(
            "SELECT configuration_value FROM " . TABLE_CONFIGURATION
            . " WHERE configuration_key = '" . $key . "' LIMIT 1"
        );
        if (!$query || xtc_db_num_rows($query) < 1) {
            return '';
        }
        $row = xtc_db_fetch_array($query);
        return (string) ($row['configuration_value'] ?? '');
    }

    private function getLatestVersionFromMeta(string $stageDir): string
    {
        $path = $stageDir . '/' . self::STAGE_META_FILE;
        $meta = is_file($path) ? json_decode((string) file_get_contents($path), true) : null;
        return is_array($meta) ? (string) ($meta['version'] ?? '') : $this->currentVersion;
    }

    private function restoreStageMetadata(string $stageDir): array
    {
        $metaPath = $stageDir . '/' . self::STAGE_META_FILE;
        if (!is_file($metaPath)) {
            return [];
        }
        $meta = json_decode((string) file_get_contents($metaPath), true);
        if (!is_array($meta)) {
            return ['Stage-Metadaten konnten nicht gelesen werden.'];
        }
        $errors = [];
        $installedPath = $this->cacheDir . '/' . self::INSTALLED_MANIFEST_FILE;
        $previousPath = $stageDir . '/previous-installed-manifest.json';
        try {
            if (!array_key_exists('had_manifest', $meta)) {
                throw new RuntimeException(
                    'Der Manifest-Ausgangszustand ist unbekannt; vorhandenes Manifest bleibt unverändert.'
                );
            }
            if ((bool) $meta['had_manifest']) {
                if (!is_file($previousPath)) {
                    throw new RuntimeException('Sicherung des installierten Manifests fehlt.');
                }
                $previous = json_decode((string) file_get_contents($previousPath), true);
                if (!is_array($previous)) {
                    throw new RuntimeException('Sicherung des installierten Manifests ist ungültig.');
                }
                $this->writeJsonAtomically($installedPath, $previous);
            } elseif (is_file($installedPath)) {
                if (!unlink($installedPath)) {
                    throw new RuntimeException('Neu angelegtes Manifest konnte nicht entfernt werden.');
                }
            }
        } catch (Throwable $exception) {
            $errors[] = 'Manifest: ' . $exception->getMessage();
        }
        try {
            $fromVersion = (string) ($meta['from_version'] ?? '');
            $toVersion = (string) ($meta['version'] ?? '');
            if (
                $fromVersion !== ''
                && $toVersion !== ''
                && $this->readInstalledVersion() === $toVersion
            ) {
                $this->setInstalledVersion($fromVersion);
            }
        } catch (Throwable $exception) {
            $errors[] = 'Version: ' . $exception->getMessage();
        }
        return $errors;
    }

    private function writeAuditEntry(
        bool $success,
        string $version,
        string $zipHash,
        int $changedFiles,
        string $error = ''
    ): void {
        try {
            $entry = [
                'timestamp' => date(DATE_ATOM),
                'success' => $success,
                'version' => $version,
                'zip_sha256' => $zipHash,
                'changed_files' => $changedFiles,
                'error' => $error,
            ];
            $json = json_encode($entry, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            if (is_string($json)) {
                file_put_contents(
                    $this->cacheDir . '/' . self::AUDIT_LOG_FILE,
                    $json . PHP_EOL,
                    FILE_APPEND | LOCK_EX
                );
            }
        } catch (Throwable $exception) {
            // Audit data must not turn a completed update into a failed one.
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
