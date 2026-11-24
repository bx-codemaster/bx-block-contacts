<?php

declare(strict_types=1);

final class BxBlockContactsMigrations
{
    public const SCHEMA_VERSION = '1.2.0';
    public const STORED_SCHEMA_KEY = 'MODULE_BX_BLOCK_CONTACTS_SCHEMA_VERSION';
    private const FAILURE_CACHE_SECONDS = 300;
    private const MIGRATION_LOCK_SECONDS = 10;

    public static function migratePending(): void
    {
        if (!self::shouldAttemptMigration()) {
            $failure = self::readFailure();
            if ($failure !== null) {
                throw new RuntimeException(
                    'Die letzte Migration ist fehlgeschlagen. Wiederholung frühestens in '
                    . max(0, self::FAILURE_CACHE_SECONDS - (time() - (int) $failure['time']))
                    . ' Sekunden: ' . (string) $failure['error']
                );
            }
            return;
        }

        if (!self::acquireMigrationLock()) {
            return;
        }
        try {
            self::migratePendingUnlocked();
            self::removeFailureMarker();
        } catch (Throwable $exception) {
            self::recordFailure($exception);
            throw $exception;
        } finally {
            self::releaseMigrationLock();
        }
    }

    private static function migratePendingUnlocked(): void
    {
        $storedVersion = self::readStoredSchemaVersion();
        if (version_compare($storedVersion, self::SCHEMA_VERSION, '>=')) {
            return;
        }

        self::migrate($storedVersion, self::SCHEMA_VERSION);
        self::writeStoredSchemaVersion(self::SCHEMA_VERSION);
    }

    public static function migrate(string $fromVersion, string $toVersion): void
    {
        self::migrateTo120($fromVersion, $toVersion);
    }

    private static function migrateTo120(string $fromVersion, string $toVersion): void
    {
        if (!self::crossesVersion($fromVersion, $toVersion, '1.2.0')) {
            return;
        }

        self::ensureAdminAccessColumn();
    }

    private static function ensureAdminAccessColumn(): void
    {
        if (self::columnExists(TABLE_ADMIN_ACCESS, 'bx_block_contacts')) {
            return;
        }

        $result = xtc_db_query(
            'ALTER TABLE ' . self::quoteIdentifier(TABLE_ADMIN_ACCESS)
            . ' ADD COLUMN `bx_block_contacts` INTEGER(1) NOT NULL DEFAULT 1'
        );

        if ($result === false) {
            throw new RuntimeException(
                'Migration 1.2.0: Admin-Access-Spalte bx_block_contacts konnte nicht angelegt werden.'
            );
        }
    }

    private static function crossesVersion(
        string $fromVersion,
        string $toVersion,
        string $migrationVersion
    ): bool {
        return version_compare($fromVersion, $migrationVersion, '<')
            && version_compare($toVersion, $migrationVersion, '>=');
    }

    private static function columnExists(string $table, string $column): bool
    {
        $query = xtc_db_query(
            "SELECT 1
             FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = '" . xtc_db_input($table) . "'
               AND COLUMN_NAME = '" . xtc_db_input($column) . "'
             LIMIT 1"
        );

        return $query && xtc_db_num_rows($query) > 0;
    }

    private static function quoteIdentifier(string $identifier): string
    {
        if (preg_match('/^[A-Za-z0-9_]+$/', $identifier) !== 1) {
            throw new InvalidArgumentException('Invalid database identifier.');
        }

        return '`' . $identifier . '`';
    }

    private static function readStoredSchemaVersion(): string
    {
        $query = xtc_db_query(
            "SELECT configuration_value
             FROM " . TABLE_CONFIGURATION . "
             WHERE configuration_key = '" . xtc_db_input(self::STORED_SCHEMA_KEY) . "'
             LIMIT 1"
        );
        if (!$query || xtc_db_num_rows($query) < 1) {
            return '0.0.0';
        }

        $row = xtc_db_fetch_array($query);
        $version = trim((string) ($row['configuration_value'] ?? ''));
        return $version !== '' ? $version : '0.0.0';
    }

    private static function writeStoredSchemaVersion(string $version): void
    {
        $query = xtc_db_query(
            "SELECT configuration_id
             FROM " . TABLE_CONFIGURATION . "
             WHERE configuration_key = '" . xtc_db_input(self::STORED_SCHEMA_KEY) . "'
             LIMIT 1"
        );

        if ($query && xtc_db_num_rows($query) > 0) {
            $result = xtc_db_query(
                "UPDATE " . TABLE_CONFIGURATION . "
                 SET configuration_value = '" . xtc_db_input($version) . "',
                     last_modified = NOW()
                 WHERE configuration_key = '" . xtc_db_input(self::STORED_SCHEMA_KEY) . "'"
            );
            if ($result === false) {
                throw new RuntimeException(
                    'Der gespeicherte Schemaschritt konnte nicht aktualisiert werden.'
                );
            }
            return;
        }

        $groupId = self::findConfigurationGroupId();
        $sortOrder = self::nextConfigurationSortOrder($groupId);
        $result = xtc_db_query(
            "INSERT INTO " . TABLE_CONFIGURATION . "
             (configuration_key, configuration_value, configuration_group_id, sort_order, date_added)
             VALUES (
                '" . xtc_db_input(self::STORED_SCHEMA_KEY) . "',
                '" . xtc_db_input($version) . "',
                " . $groupId . ",
                " . $sortOrder . ",
                NOW()
             )"
        );
        if ($result === false) {
            throw new RuntimeException('Der gespeicherte Schemaschritt konnte nicht angelegt werden.');
        }
    }

    private static function findConfigurationGroupId(): int
    {
        foreach (array(
            'MODULE_BX_BLOCK_CONTACTS_CONFIG_ID',
            'MODULE_BX_BLOCK_CONTACTS_STATUS',
        ) as $key) {
            $query = xtc_db_query(
                "SELECT configuration_value, configuration_group_id
                 FROM " . TABLE_CONFIGURATION . "
                 WHERE configuration_key = '" . xtc_db_input($key) . "'
                 LIMIT 1"
            );
            if (!$query || xtc_db_num_rows($query) < 1) {
                continue;
            }
            $row = xtc_db_fetch_array($query);
            $value = $key === 'MODULE_BX_BLOCK_CONTACTS_CONFIG_ID'
                ? ($row['configuration_value'] ?? '')
                : ($row['configuration_group_id'] ?? '');
            if ((int) $value > 0) {
                return (int) $value;
            }
        }

        throw new RuntimeException('Die Modul-Konfigurationsgruppe konnte nicht ermittelt werden.');
    }

    private static function nextConfigurationSortOrder(int $groupId): int
    {
        $query = xtc_db_query(
            "SELECT COALESCE(MAX(sort_order), 0) AS max_sort_order
             FROM " . TABLE_CONFIGURATION . "
             WHERE configuration_group_id = " . $groupId
        );
        $row = $query ? xtc_db_fetch_array($query) : array();
        return ((int) ($row['max_sort_order'] ?? 0)) + 1;
    }

    private static function acquireMigrationLock(): bool
    {
        $query = xtc_db_query(
            "SELECT GET_LOCK('bx_block_contacts_migration', "
            . self::MIGRATION_LOCK_SECONDS . ") AS acquired"
        );
        $row = $query ? xtc_db_fetch_array($query) : array();
        return (int) ($row['acquired'] ?? 0) === 1;
    }

    private static function releaseMigrationLock(): void
    {
        xtc_db_query("SELECT RELEASE_LOCK('bx_block_contacts_migration')");
    }

    private static function shouldAttemptMigration(): bool
    {
        $path = self::failureMarkerPath();
        if (!is_file($path)) {
            return true;
        }
        return time() - (int) filemtime($path) >= self::FAILURE_CACHE_SECONDS;
    }

    private static function recordFailure(Throwable $exception): void
    {
        $path = self::failureMarkerPath();
        $directory = dirname($path);
        if (!is_dir($directory)) {
            @mkdir($directory, 0700, true);
        }
        $htaccess = $directory . '/.htaccess';
        if (!is_file($htaccess)) {
            @file_put_contents(
                $htaccess,
                "<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n"
                . "<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n",
                LOCK_EX
            );
        }
        @file_put_contents(
            $path,
            json_encode([
                'time' => time(),
                'error' => $exception->getMessage(),
            ], JSON_UNESCAPED_UNICODE) ?: '',
            LOCK_EX
        );
    }

    private static function removeFailureMarker(): void
    {
        $path = self::failureMarkerPath();
        if (is_file($path)) {
            @unlink($path);
        }
    }

    private static function readFailure(): ?array
    {
        $path = self::failureMarkerPath();
        if (!is_file($path)) {
            return null;
        }
        $data = json_decode((string) file_get_contents($path), true);
        if (
            !is_array($data)
            || !isset($data['time'], $data['error'])
        ) {
            return null;
        }
        return [
            'time' => (int) $data['time'],
            'error' => (string) $data['error'],
        ];
    }

    private static function failureMarkerPath(): string
    {
        return rtrim(DIR_FS_CATALOG, '/\\')
            . '/cache/bx_github_updater/bx-block-contacts/migration-error.json';
    }
}
