<?php

declare(strict_types=1);

/**
 * BX Module Updater - Systemmodul für modified eCommerce.
 *
 * Zentrale Verwaltung des GitHub-basierten Update-Systems für alle
 * bx-codemaster Module. Dieses Modul ist der Eigentümer der
 * BxGithubModuleUpdater-Klasse und stellt folgende Funktionen bereit:
 *
 * - Selbstupdate des Updater-Kerns
 * - Übersicht aller installierten Module mit Updater-Integration
 * - Update-Status-Anzeige pro Modul
 * - Cache-Verwaltung und Force-Check
 * - Audit-Log-Einsicht
 * - Diagnose-Tools
 *
 * @package    BX\ModuleUpdater
 * @version    1.1.0
 * @author     Axel Benkert <mail@bx-coding.de>
 * @copyright  2026-present BX Coding
 * @license    GNU General Public License, version 2 or later
 * @link       https://github.com/bx-codemaster/bx-module-updater
 * @since      1.1.0
 * @requires   PHP >= 8.0
 */

defined('_VALID_XTC') or die('Direct Access to this location is not allowed.');

class bx_module_updater {
    public string $code;
    public string $title;
    public string $description;
    public int $sort_order;
    public bool $enabled;
    public string $version;
    private bool $_check;
    public array $properties = [];
    private ?BxGithubModuleUpdater $githubUpdater = null;
    private ?array $updateStatus = null;
    public string $development_status = 'p';

    // Bekannte Produktmodule, die den Updater nutzen (für Auto-Discovery)
    private const KNOWN_MODULES = [
        'bx_block_contacts' => [
        'prefix'       => 'BX_BLOCK_CONTACTS',
        'owner'        => 'bx-codemaster',
        'repo'         => 'bx-block-contacts',
        'key'          => 'RhVqKVDgRLHueQs0eYZBgbhU++wzMY5XETzYL+z5q3A=',
        'allowed_paths' => [
            'admin/includes/classes/BX/Github',
            'admin/includes/extra/functions',
            'admin/includes/extra/css',
            'admin/includes/modules/system',
            'admin/images/icons/heading',
            'lang/english/extra/admin',
            'lang/english/modules/system',
            'lang/german/extra/admin',
            'lang/german/modules/system',
            'media/content',
            ],
        ],
        'bx_advent_calendar' => [
            'prefix'       => 'BX_ADVENT_CALENDAR',
            'owner'        => 'bx-codemaster',
            'repo'         => 'bx-advent-calendar',
            'key'          => 'ZgJA/UwCAN1f0IbmsT81ZNFkwjcIoIQsNZB+x4Va1DM=',  // ← DEIN PUBLIC KEY
            'allowed_paths' => [
                'admin/includes/classes',
                'admin/includes/extra/csrf_exclusion',
                'admin/includes/extra/css',
                'admin/includes/extra/javascript',
                'admin/includes/extra/filenames',
                'admin/includes/extra/menu',
                'admin/includes/modules/system',
                'admin/images/icons/heading',
                'admin',
                'includes/extra/database_tables',
                'includes/extra/wysiwyg',
                'includes/extra/header/header_head',
                'includes/classes',
                'lang/english/extra/admin',
                'lang/english/modules/system',
                'lang/german/extra/admin',
                'lang/german/modules/system',
                'media/content',
                'templates/tpl_modified_nova/css',
                'templates/tpl_modified_nova/module',
                'templates/tpl_modified_nova/javascript/extra',
            ],
        ],
        // Weitere Module hier eintragen oder per Scan ermitteln
    ];

    public function __construct() {
        require_once DIR_FS_ADMIN . 'includes/classes/BX/Github/BxGithubModuleUpdater.php';

        $this->code        = 'bx_module_updater';
        $this->title       = MODULE_BX_MODULE_UPDATER_TITLE;
        $this->description = MODULE_BX_MODULE_UPDATER_DESC;
        $this->sort_order  = defined('MODULE_BX_MODULE_UPDATER_SORT_ORDER') ? (int) MODULE_BX_MODULE_UPDATER_SORT_ORDER : 0;
        $this->enabled     = defined('MODULE_BX_MODULE_UPDATER_STATUS') && MODULE_BX_MODULE_UPDATER_STATUS === 'true';
        $this->version     = BxGithubModuleUpdater::VERSION;
        $this->development_status = 'p';

        // Selbstupdate-Check
        if (defined('MODULE_BX_MODULE_UPDATER_VERSION')) {
            $this->updateStatus = $this->getGithubUpdater()->checkForUpdate(
                ($_GET['bx_updater_refresh'] ?? '') === '1'
            );
            $this->properties['button_update'] = $this->createRefreshButton();
            if (
                $this->updateStatus['has_update']
                && !empty($this->updateStatus['zip_url'])
                && !empty($this->updateStatus['sha256'])
            ) {
                $this->properties['button_update'] = $this->createUpdateButton(
                    $this->updateStatus['latest_version'],
                    true
                ) . $this->properties['button_update'];
            }
        }
    }

    public function process(): void {
        // Selbstupdate ausführen
        if (($_POST['bx_updater_action'] ?? '') === 'install') {
            global $messageStack;
            $result = $this->getGithubUpdater()->installLatestUpdate();
            $messageStack->add_session($result['message'], $result['ok'] ? 'success' : 'error');
            xtc_redirect(xtc_href_link(
                FILENAME_MODULE_EXPORT,
                'set=system&module=' . $this->code . '&action=edit'
            ));
        }

        // Cache leeren
        if (($_POST['bx_updater_clear_cache'] ?? '') === '1') {
            global $messageStack;
            $this->clearAllCaches();
            $messageStack->add_session('Alle Updater-Caches wurden geleert.', 'success');
            xtc_redirect(xtc_href_link(FILENAME_MODULE_EXPORT, 'set=system&module=' . $this->code . '&action=edit'));
        }

        // Force-Check für alle Module
        if (($_POST['bx_updater_force_check'] ?? '') === '1') {
            global $messageStack;
            $this->forceCheckAllModules();
            $messageStack->add_session('Force-Check für alle Module angestoßen.', 'success');
            xtc_redirect(xtc_href_link(FILENAME_MODULE_EXPORT, 'set=system&module=' . $this->code . '&action=edit'));
        }

        //xtc_redirect(xtc_href_link(FILENAME_MODULE_EXPORT, 'set=system&module=' . $this->code . '&action=edit'));
    }

    public function display(): array {
        $html = '';
        $html .= '<div>' . xtc_button(BUTTON_SAVE) .xtc_button_link(BUTTON_CANCEL, xtc_href_link(FILENAME_MODULE_EXPORT, 'set=' . $_GET['set'] . '&module=bx_module_updater')) . "</div><br />";
        $html .= $this->createSelfUpdateNotice();
        $html .= $this->createModuleOverview();
        $html .= $this->createToolsSection();
        return array('text' => $html);
    }

    public function check(): bool {
        if (!isset($this->_check)) {
            $check_query = xtc_db_query("SELECT configuration_value
                                         FROM " . TABLE_CONFIGURATION . "
                                         WHERE configuration_key = 'MODULE_BX_MODULE_UPDATER_STATUS'");
            $this->_check = xtc_db_num_rows($check_query) > 0;
        }
        return $this->_check;
    }

    public function install(): void {
        xtc_db_query("ALTER TABLE " . TABLE_ADMIN_ACCESS . " ADD bx_module_updater INTEGER(1)");
        xtc_db_query("UPDATE " . TABLE_ADMIN_ACCESS . " SET bx_module_updater = 1");

        $freeId_query = xtc_db_query("SELECT (configuration_group_id+1) AS id
                                      FROM " . TABLE_CONFIGURATION_GROUP . "
                                      WHERE (configuration_group_id+1) NOT IN (
                                          SELECT configuration_group_id
                                          FROM " . TABLE_CONFIGURATION_GROUP . "
                                          WHERE configuration_group_id IS NOT NULL
                                      ) LIMIT 1");
        $freeId = xtc_db_fetch_array($freeId_query);
        $group_id = (int) $freeId['id'];

        $freeSort_query = xtc_db_query("SELECT (sort_order+1) AS sort_order
                                        FROM " . TABLE_CONFIGURATION_GROUP . "
                                        WHERE (sort_order+1) NOT IN (
                                            SELECT sort_order
                                            FROM " . TABLE_CONFIGURATION_GROUP . "
                                            WHERE sort_order IS NOT NULL
                                        ) LIMIT 1");
        $freeSort = xtc_db_fetch_array($freeSort_query);
        $sort_order = (int) $freeSort['sort_order'];

        xtc_db_query("INSERT INTO " . TABLE_CONFIGURATION_GROUP . "
                      (configuration_group_id, configuration_group_title, configuration_group_description, sort_order, visible)
                      VALUES ($group_id, 'BX Module Updater', 'Zentrale Update-Verwaltung für bx-codemaster Module', $sort_order, 1)");

        $configs = [
            ['MODULE_BX_MODULE_UPDATER_STATUS', 'true', 1, 'xtc_cfg_select_option(array(\'true\', \'false\'), '],
            ['MODULE_BX_MODULE_UPDATER_SORT_ORDER', '100', 2, 'xtc_convert_value( '],
            ['MODULE_BX_MODULE_UPDATER_VERSION', $this->version, 3, 'xtc_convert_value( '],
            ['MODULE_BX_MODULE_UPDATER_CONFIG_ID', $group_id, 4, 'xtc_convert_value( '],
        ];

        foreach ($configs as $i => $c) {
            xtc_db_query("INSERT INTO " . TABLE_CONFIGURATION . "
                          (configuration_key, configuration_value, configuration_group_id, sort_order, date_added, set_function)
                          VALUES ('" . xtc_db_input($c[0]) . "', '" . xtc_db_input($c[1]) . "', $group_id, " . (int) $c[2] . ", NOW(), '" . xtc_db_input($c[3]) . "')");
        }
    }

    public function remove(): void {
        xtc_db_query("DELETE FROM " . TABLE_CONFIGURATION . " WHERE configuration_key IN ('" . implode("', '", $this->keys()) . "')");
        xtc_db_query("DELETE FROM " . TABLE_CONFIGURATION_GROUP . " WHERE configuration_group_title = 'BX Module Updater'");
        xtc_db_query("ALTER TABLE " . TABLE_ADMIN_ACCESS . " DROP bx_module_updater");
    }

    public function keys(): array {
        return [
            'MODULE_BX_MODULE_UPDATER_STATUS',
            'MODULE_BX_MODULE_UPDATER_VERSION',
            'MODULE_BX_MODULE_UPDATER_SORT_ORDER',
            'MODULE_BX_MODULE_UPDATER_CONFIG_ID',
        ];
    }

    public function custom(): void {
        if (($_POST['bx_updater_action'] ?? '') === 'install') {
            global $messageStack;
            $result = $this->getGithubUpdater()->installLatestUpdate();
            $messageStack->add_session($result['message'], $result['ok'] ? 'success' : 'error');
            return;
        }

        if (($_GET['delete'] ?? '') !== 'true') {
            return;
        }

        global $messageStack;
        $result = true;
        $dirs_and_files = [
            DIR_FS_ADMIN . 'images/icons/heading/bx_module_updater.png',
            DIR_FS_ADMIN . 'includes/classes/BX/Github/BxGithubModuleUpdater.php',
            DIR_FS_ADMIN . 'includes/classes/BX/Github/BxModuleMigration.php',
            DIR_FS_CATALOG . 'cache/bx_github_updater/bx-module-updater',
            DIR_FS_CATALOG . 'lang/english/modules/system/bx_module_updater.php',
            DIR_FS_CATALOG . 'lang/german/modules/system/bx_module_updater.php',
        ];

        foreach ($dirs_and_files as $dir_or_file) {
            if (!$this->secureDelete($dir_or_file)) {
                $messageStack->add_session($dir_or_file . ' konnte nicht gelöscht werden.', 'error');
                $result = false;
            }
        }

        if ($result === true) {
            $messageStack->add_session('BX Module Updater erfolgreich entfernt.', 'success');
        } else {
            $messageStack->add_session('Entfernung unvollständig.', 'error');
        }

        $this->secureDelete(DIR_FS_ADMIN . 'includes/modules/system/bx_module_updater.php');
        xtc_redirect(xtc_href_link(FILENAME_MODULE_EXPORT, 'set=system'));
    }

    /**
     * Erstellt Updater-Instanz für Selbstupdate
     */
    private function getGithubUpdater(): BxGithubModuleUpdater {
        if ($this->githubUpdater === null) {
            $this->githubUpdater = new BxGithubModuleUpdater([
                'owner'         => 'bx-codemaster',
                'repo'          => 'bx-module-updater',
                'module_name'   => 'bx-module-updater',
                'module_prefix' => 'BX_MODULE_UPDATER',
                'admin_dir'     => DIR_ADMIN,
                'public_key'    => 'RhVqKVDgRLHueQs0eYZBgbhU++wzMY5XETzYL+z5q3A=', // derselbe Key wie bx-block-contacts (Org-Key)
                'allowed_paths' => [
                    'admin/includes/classes/BX/Github',
                    'admin/includes/modules/system',
                    'lang/english/modules/system',
                    'lang/german/modules/system',
                    'admin/images/icons/heading',
                ],
                'current_version' => version_compare(
                    $this->version,
                    MODULE_BX_MODULE_UPDATER_VERSION,
                    '>'
                ) ? $this->version : MODULE_BX_MODULE_UPDATER_VERSION,
            ]);
        }
        return $this->githubUpdater;
    }

    /**
     * Ermittelt alle installierten Module, die den Updater nutzen
     */
    private function getInstalledModulesWithUpdater(): array {
        $modules = [];

        foreach (self::KNOWN_MODULES as $code => $info) {
            $versionKey = 'MODULE_' . $info['prefix'] . '_VERSION';
            $statusKey  = 'MODULE_' . $info['prefix'] . '_STATUS';
            $titleKey   = 'MODULE_' . $info['prefix'] . '_TITLE';

            // NUR aufnehmen, wenn Modul wirklich installiert ist (Konstanten definiert)
            if (!defined($versionKey) || !defined($statusKey)) {
                continue; // Modul nicht installiert → überspringen
            }

            $modules[$code] = [
                'code'        => $code,
                'title'       => defined($titleKey) ? constant($titleKey) : $code,
                'version'     => constant($versionKey),
                'enabled'     => strtolower((string) constant($statusKey)) === 'true',
                'prefix'      => $info['prefix'],
                'owner'       => $info['owner'],
                'repo'        => $info['repo'],
                'public_key'  => $info['key'],
                'updater_ok'  => class_exists(BxGithubModuleUpdater::class),
                'allowed_paths' => $info['allowed_paths'] ?? [],
            ];
        }

        // Zusätzlich: Versuche Module per Dateisystem zu entdecken (nur als Hinweis)
        $moduleDir = DIR_FS_ADMIN . 'includes/modules/system/';
        if (is_dir($moduleDir)) {
            foreach (glob($moduleDir . 'bx_*.php') as $file) {
                $code = basename($file, '.php');
                if (!isset($modules[$code]) && $code !== 'bx_module_updater') {
                    $modules[$code] = [
                        'code'        => $code,
                        'title'       => $code,
                        'version'     => '?',
                        'enabled'     => false,
                        'prefix'      => 'UNKNOWN',
                        'owner'       => 'bx-codemaster',
                        'repo'        => $code,
                        'public_key'  => '',
                        'updater_ok'  => false,
                        'discovered'  => true,
                    ];
                }
            }
        }

        return $modules;
    }

    /**
     * Selbstupdate-Hinweis rendern
     */
    private function createSelfUpdateNotice(): string {
        if (
            $this->updateStatus === null
            || !$this->updateStatus['has_update']
            || empty($this->updateStatus['latest_version'])
        ) {
            // Kein Update: Nur Refresh-Button (aus properties oder neu)
            $refreshBtn = $this->properties['button_update'] ?? $this->createRefreshButton();
            return '<div class="messageStackInfo txta-c">'
                . '<strong>BX Module Updater v' . htmlspecialchars($this->version) . '</strong> — aktuell'
                . '<br>' . $refreshBtn
                . '</div>';
        }

        // Update verfügbar: properties['button_update'] enthält BEIDES (Update + Refresh)
        $buttons = $this->properties['button_update'] ?? $this->createUpdateButton($this->updateStatus['latest_version'], false) . $this->createRefreshButton();

        return '<div class="messageStackWarning txta-c">'
            . '<strong>BX Module Updater Update verfügbar: v' . htmlspecialchars($this->updateStatus['latest_version']) . '</strong>'
            . '<br>' . $buttons
            . '</div>';
    }

    /**
     * Modul-Übersichtstabelle rendern
     */
    private function createModuleOverview(): string {
        $modules = $this->getInstalledModulesWithUpdater();
        $updaterVersion = BxGithubModuleUpdater::VERSION;

        $html = '<h3>Installierte Module mit Updater-Integration</h3>';
        $html .= '<table class="tableConfig" style="width: 100%; margin-top: 10px;">';
        $html .= '<thead><tr class="dataTableHeadingRow">';
        $html .= '<th>Modul</th><th>Version</th><th>Status</th><th>Update</th><th>Aktionen</th>';
        $html .= '</tr></thead><tbody>';

        foreach ($modules as $mod) {
            $html .= '<tr class="dataTableRow">';
            $html .= '<td><strong>' . htmlspecialchars($mod['title']) . '</strong> (<code>' . htmlspecialchars($mod['code']) . '</code>)</td>';
            $html .= '<td>' . htmlspecialchars($mod['version']) . '</td>';
            $html .= '<td>' . ($mod['enabled'] ? '<span class="success">Aktiv</span>' : '<span class="error">Inaktiv</span>') . '</td>';

            // Update-Check für dieses Modul (falls Konfiguration vorhanden)
            $updateInfo = $this->checkModuleUpdate($mod);
            $html .= '<td>' . $updateInfo . '</td>';

            $html .= '<td>';
            if ($mod['updater_ok'] && !empty($mod['public_key'])) {
                $html .= '<a class="button btnbox txta-c" style="margin: 0;" href="' . xtc_href_link(FILENAME_MODULE_EXPORT, 'set=system&module=' . $mod['code'] . '&action=edit') . '">Konfigurieren</a> ';
            }
            if (isset($mod['discovered'])) {
                $html .= '<span class="smallText">(Auto-entdeckt, Config fehlt)</span>';
            }
            $html .= '</td>';
            $html .= '</tr>';
        }

        $html .= '</tbody></table>';
        return $html;
    }

    /**
     * Update-Check für einzelnes Modul (read-only, cached)
     */
    private function checkModuleUpdate(array $mod): string {
        if (empty($mod['public_key']) || !defined('MODULE_' . $mod['prefix'] . '_VERSION')) {
            return '<span class="smallText">Keine Config</span>';
        }

        try {
            $updater = new BxGithubModuleUpdater([
                'owner'         => $mod['owner'],
                'repo'          => $mod['repo'],
                'module_name'   => $mod['repo'],
                'module_prefix' => $mod['prefix'],
                'admin_dir'     => DIR_ADMIN,
                'public_key'    => $mod['public_key'],
                'allowed_paths'   => $mod['allowed_paths'],
                'current_version' => constant('MODULE_' . $mod['prefix'] . '_VERSION'),
                'cache_ttl_seconds' => 3600,
            ]);

            $status = $updater->checkForUpdate(false); // cached

            if ($status['has_update'] && !empty($status['zip_url']) && !empty($status['sha256'])) {
                return '<span class="warning">Update: v' . htmlspecialchars($status['latest_version']) . '</span>';
            } elseif ($status['has_update']) {
                return '<span class="smallText">Release prüfen...</span>';
            } else {
                return '<span class="success">Aktuell</span>';
            }
        } catch (Throwable $e) {
            return '<span class="error">Fehler: ' . htmlspecialchars($e->getMessage()) . '</span>';
        }
    }

    /**
     * Tools-Sektion (Cache, Force-Check, Audit-Log)
     */
    private function createToolsSection(): string {
        $html = '<hr style="margin: 20px 0;">';
        $html .= '<h3>Werkzeuge & Diagnose</h3>';
        $html .= '<div style="display: flex; flex-direction: row; flex-wrap: nowrap; justify-content: flex-start; align-items: stretch; gap: 10px; margin: 10px 0;">';

        // Cache leeren - Button im Hauptform
        $html .= '<button type="submit" name="bx_updater_clear_cache" value="1" class="button btnbox txta-c" style="flex: 1 1 10%;" onclick="return confirm(\'Wirklich alle Updater-Caches leeren?\');">Cache leeren</button>';

        // Force-Check - Button im Hauptform
        $html .= '<button type="submit" name="bx_updater_force_check" value="1" class="button btnbox txta-c" style="flex: 1 1 10%;" onclick="return confirm(\'Force-Check für alle Module anstoßen?\');">Force-Check alle Module</button>';

        // Audit-Log
        $html .= '<a class="button btnbox txta-c" style="flex: 1 1 10%;" href="' . xtc_href_link(FILENAME_MODULE_EXPORT, 'set=system&module=' . $this->code . '&action=edit&view=audit') . '">Audit-Log anzeigen</a>';

        // Updater-Info
        $html .= '<a class="button btnbox txta-c" style="flex: 1 1 10%;" href="' . xtc_href_link(FILENAME_MODULE_EXPORT, 'set=system&module=' . $this->code . '&action=edit&view=info') . '">Updater-Info</a>';

        $html .= '</div>';

        // Audit-Log anzeigen falls angefordert
        if (($_GET['view'] ?? '') === 'audit') {
            $html .= $this->renderAuditLog();
        }
        if (($_GET['view'] ?? '') === 'info') {
            $html .= $this->renderUpdaterInfo();
        }

        return $html;
    }

    /**
     * Audit-Log rendern
     */
    private function renderAuditLog(): string {
        $cacheDir = DIR_FS_CATALOG . 'cache/bx_github_updater/bx-module-updater/';
        $logFile = $cacheDir . 'update-audit.log';

        $html = '<h4>Audit-Log (bx-module-updater)</h4>';
        if (is_file($logFile)) {
            $lines = file($logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            $html .= '<div style="max-height: 400px; overflow: auto; background: #f8f8f8; padding: 10px; font-family: monospace; font-size: 12px;">';
            foreach (array_reverse($lines) as $line) {
                $entry = json_decode($line, true);
                if (is_array($entry)) {
                    $color = $entry['success'] ? '#2e7d32' : '#c62828';
                    $html .= '<div style="border-bottom: 1px solid #ddd; padding: 5px;">';
                    $html .= '<span style="color: ' . $color . ';">[' . ($entry['success'] ? 'OK' : 'FAIL') . ']</span> ';
                    $html .= '<span>' . htmlspecialchars($entry['timestamp'] ?? '') . '</span> ';
                    $html .= '<span>v' . htmlspecialchars($entry['version'] ?? '') . '</span> ';
                    $html .= '<span>' . (int) ($entry['changed_files'] ?? 0) . ' Dateien</span> ';
                    if (!empty($entry['error'])) {
                        $html .= '<span style="color: #c62828;">| ' . htmlspecialchars($entry['error']) . '</span>';
                    }
                    $html .= '</div>';
                }
            }
            $html .= '</div>';
        } else {
            $html .= '<p class="smallText">Kein Audit-Log vorhanden.</p>';
        }
        $html .= '<p><a class="button btnbox txta-c" href="' . xtc_href_link(FILENAME_MODULE_EXPORT, 'set=system&module=' . $this->code . '&action=edit') . '">Zurück</a></p>';
        return $html;
    }

    /**
     * Updater-Info rendern (Konfiguration, Pfade, Extensions)
     */
    private function renderUpdaterInfo(): string {
        $html = '<h4>Updater-Diagnose</h4>';
        $html .= '<table class="tableConfig" style="width: 100%;">';
        $html .= '<tbody>';

        $checks = [
            'PHP Version' => PHP_VERSION,
            'ext-curl' => extension_loaded('curl') ? '✓' : '✗ FEHLT',
            'ext-zip' => class_exists('ZipArchive') ? '✓' : '✗ FEHLT',
            'ext-sodium' => extension_loaded('sodium') ? '✓' : '✗ FEHLT',
            'Updater Version' => BxGithubModuleUpdater::VERSION,
            'Cache-Verzeichnis' => DIR_FS_CATALOG . 'cache/bx_github_updater/',
            'Admin-Verzeichnis' => DIR_ADMIN,
            'Shop-Root' => DIR_FS_CATALOG,
        ];

        foreach ($checks as $label => $value) {
            $html .= '<tr class="dataTableRow"><td><strong>' . htmlspecialchars($label) . '</strong></td><td>' . htmlspecialchars((string) $value) . '</td></tr>';
        }

        $html .= '</tbody></table>';
        $html .= '<p><a class="button btnbox txta-c" href="' . xtc_href_link(FILENAME_MODULE_EXPORT, 'set=system&module=' . $this->code . '&action=edit') . '">Zurück</a></p>';
        return $html;
    }

    /**
     * Cache aller Module leeren
     */
    private function clearAllCaches(): void {
        $baseCacheDir = DIR_FS_CATALOG . 'cache/bx_github_updater/';
        if (is_dir($baseCacheDir)) {
            foreach (glob($baseCacheDir . '*/release.json') as $file) {
                @unlink($file);
            }
            foreach (glob($baseCacheDir . '*/migration-error.json') as $file) {
                @unlink($file);
            }
        }
    }

    /**
     * Force-Check für alle bekannten Module
     */
    private function forceCheckAllModules(): void {
        $modules = $this->getInstalledModulesWithUpdater();
        foreach ($modules as $mod) {
            if (empty($mod['public_key']) || !defined('MODULE_' . $mod['prefix'] . '_VERSION')) {
                continue;
            }
            try {
                $updater = new BxGithubModuleUpdater([
                    'owner'           => $mod['owner'],
                    'repo'            => $mod['repo'],
                    'module_name'     => $mod['repo'],
                    'module_prefix'   => $mod['prefix'],
                    'admin_dir'       => DIR_ADMIN,
                    'public_key'      => $mod['public_key'],
                    'allowed_paths'   => ['admin/includes/modules/system'],  // ← MINIMAL: nur Systemmodul-Pfad
                    'current_version' => constant('MODULE_' . $mod['prefix'] . '_VERSION'),
                    'cache_ttl_seconds' => 60,  // Kurz für Force-Check
                ]);
                $updater->checkForUpdate(true); // force refresh
            } catch (Throwable $e) {
                error_log('BX Module Updater Force-Check failed for ' . $mod['code'] . ': ' . $e->getMessage());
            }
        }
    }

    /**
     * Update-Button erstellen
     */
    private function createUpdateButton(string $version, bool $standaloneForm): string {
        $formStart   = '';
        $formEnd     = '';
        $actionField = ' name="bx_updater_action" value="install"';
        if ($standaloneForm) {
            $formStart = xtc_draw_form('bx_module_updater_update', FILENAME_MODULE_EXPORT, 'set=system&module=' . $this->code . '&action=custom');
            $formEnd = '</form>';
            $actionField = '';
        }
        return $formStart
            . ($standaloneForm ? xtc_draw_hidden_field('bx_updater_action', 'install') : '')
            . '<button type="submit"' . $actionField . ' class="button bx-update" onclick="return confirm(\''
            . sprintf(MODULE_BX_MODULE_UPDATER_UPDATE_CONFIRM, htmlspecialchars($version, ENT_QUOTES, 'UTF-8'))
            . '\');">'
            . sprintf(MODULE_BX_MODULE_UPDATER_UPDATE_BUTTON, htmlspecialchars($version, ENT_QUOTES, 'UTF-8'))
            . '</button>' . $formEnd . '<br>';
    }

    private function createRefreshButton(): string {
        $label = defined('MODULE_BX_MODULE_UPDATER_UPDATE_CHECK')
            ? MODULE_BX_MODULE_UPDATER_UPDATE_CHECK
            : 'Jetzt prüfen';
        return '<a class="button btnbox" href="'
            . xtc_href_link(FILENAME_MODULE_EXPORT, xtc_get_all_get_params(['bx_updater_refresh']) . 'bx_updater_refresh=1')
            . '" style="width: auto; display: inline-block;">' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</a>';
    }

    private function secureDelete(string $path): bool {
        if (!file_exists($path)) return true;
        $realPath  = realpath($path);
        $adminRoot = realpath(DIR_FS_ADMIN);
        if (empty($realPath) || empty($adminRoot)) return false;
        if ($realPath === $adminRoot || $realPath === DIRECTORY_SEPARATOR) return false;
        if (!is_writable($realPath)) return false;
        if (!is_dir($realPath) || is_link($realPath)) return unlink($realPath);
        try {
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($realPath, FilesystemIterator::SKIP_DOTS),
                RecursiveIteratorIterator::CHILD_FIRST
            );
            foreach ($iterator as $item) {
                $itemPath = $item->getRealPath();
                if (!is_writable($itemPath)) return false;
                if ($item->isDir() && !$item->isLink()) {
                    if (!rmdir($itemPath)) return false;
                } else {
                    if (!unlink($itemPath)) return false;
                }
            }
        } catch (UnexpectedValueException) {
            return false;
        }
        return rmdir($realPath);
    }
}
