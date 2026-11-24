<?php

declare(strict_types=1);

/**
 * Base class for module-specific database migrations.
 *
 * Provides common helper methods for version comparison, schema inspection,
 * and safe SQL identifier handling. Product modules should extend this class
 * and implement their own migrate() method with versioned migration blocks.
 *
 * @package    BX\Github
 * @author     Axel Benkert <mail@bx-coding.de>
 * @copyright  2026-present BX Coding
 * @license    GNU General Public License, version 2 or later
 * @link       https://github.com/bx-codemaster/bx-module-updater
 * @since      1.1.0
 * @requires   PHP >= 8.0
 */
abstract class BxModuleMigration
{
    /**
     * Checks whether a version range crosses a specific migration version.
     *
     * @param string $fromVersion  Starting version (exclusive lower bound)
     * @param string $toVersion    Target version (inclusive upper bound)
     * @param string $migrationVersion  Version to check for
     * @return bool  True if migration should run (from < migrationVersion <= to)
     */
    protected static function crossesVersion(string $fromVersion, string $toVersion, string $migrationVersion): bool
    {
        return version_compare($fromVersion, $migrationVersion, '<')
            && version_compare($toVersion, $migrationVersion, '>=');
    }

    /**
     * Checks if a table exists in the current database.
     *
     * @param string $table  Table name (without prefix)
     * @return bool
     */
    protected static function tableExists(string $table): bool
    {
        $query = xtc_db_query(
            "SHOW TABLES LIKE '" . xtc_db_input($table) . "'"
        );

        return $query && xtc_db_num_rows($query) > 0;
    }

    /**
     * Checks if a column exists in a table.
     *
     * @param string $table   Table name
     * @param string $column  Column name
     * @return bool
     */
    protected static function columnExists(string $table, string $column): bool
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

    /**
     * Checks if an index exists on a table.
     *
     * @param string $table  Table name
     * @param string $index  Index name (Key_name in SHOW INDEX)
     * @return bool
     */
    protected static function indexExists(string $table, string $index): bool
    {
        $query = xtc_db_query(
            'SHOW INDEX FROM ' . self::quoteIdentifier($table)
            . " WHERE Key_name = '" . xtc_db_input($index) . "'"
        );

        return $query && xtc_db_num_rows($query) > 0;
    }

    /**
     * Checks if a configuration key exists.
     *
     * @param string $key  Configuration key
     * @return bool
     */
    protected static function configurationExists(string $key): bool
    {
        $query = xtc_db_query(
            "SELECT configuration_id
             FROM " . TABLE_CONFIGURATION . "
             WHERE configuration_key = '" . xtc_db_input($key) . "'
             LIMIT 1"
        );

        return $query && xtc_db_num_rows($query) > 0;
    }

    /**
     * Finds the configuration_group_id for the module.
     * Tries common configuration keys used by modified modules.
     *
     * @return int|null  Group ID or null if not found
     */
    protected static function configurationGroupId(): ?int
    {
        // Override in child class with module-specific keys
        return null;
    }

    /**
     * Determines the next sort_order for a new configuration entry in a group.
     *
     * @param int $groupId  Configuration group ID
     * @return int  Next sort_order value
     */
    protected static function nextConfigurationSortOrder(int $groupId): int
    {
        $query = xtc_db_query(
            "SELECT COALESCE(MAX(sort_order), 0) AS max_sort_order
             FROM " . TABLE_CONFIGURATION . "
             WHERE configuration_group_id = " . (int) $groupId
        );
        $row = $query ? xtc_db_fetch_array($query) : [];

        return ((int) ($row['max_sort_order'] ?? 0)) + 1;
    }

    /**
     * Safely quotes a SQL identifier (table/column/index name).
     * Only allows alphanumeric and underscore characters.
     *
     * @param string $identifier  Raw identifier
     * @return string  Backtick-quoted identifier
     * @throws InvalidArgumentException  If identifier contains invalid characters
     */
    protected static function quoteIdentifier(string $identifier): string
    {
        if (preg_match('/^[A-Za-z0-9_]+$/', $identifier) !== 1) {
            throw new InvalidArgumentException('Invalid database identifier: ' . $identifier);
        }

        return '`' . $identifier . '`';
    }
}