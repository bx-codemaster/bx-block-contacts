<?php
/* -----------------------------------------------------------------------------------------
   $Id: lang/german/modules/system/bx_module_updater.php 1000 2026-11-24 00:00:00Z benax $
    _
   | |__   ___ _ __   __ ___  __
   | '_ \ / _ \ '_ \ / _ \ \/ /
   | |_) |  __/ | | | (_| |>  <
   |_.__/ \___|_| |_|\__,_/_/\_\
   xxxxxxxxxxxxxxxxxxxxxxxxxxxxx

   modified eCommerce Shopsoftware
   http://www.modified-shop.org

   Copyright (c) 2009 - 2013 [www.modified-shop.org]
   -----------------------------------------------------------------------------------------
   Released under the GNU General Public License
   ---------------------------------------------------------------------------------------*/

define('MODULE_BX_MODULE_UPDATER_TITLE', 'BX Module Updater');

$module_description = '
<details class="bxac-card">
  <summary class="bxac-summary" style="list-style: none;">
    <span class="bxac-arrow">▸</span>
    ' . xtc_image(DIR_WS_ICONS.'heading/bx_module_updater.png', 'BX Module Updater',) . '
    <span class="bxac-title">BX Module Updater</span>
  </summary>
  <div class="bxac-body">
    <h3 style="margin-top: 0;">Zentrale Update-Verwaltung für alle bx-codemaster Module</h3>
    <p>Dieses Modul stellt die gemeinsame Updater-Infrastruktur bereit, die von allen
       bx-codemaster Modulen für sichere, signierte GitHub-Updates genutzt wird.</p>
    <ul>
      <li>Selbstupdate des Updater-Kerns</li>
      <li>Übersicht aller installierten Module mit Updater-Integration</li>
      <li>Update-Status pro Modul</li>
      <li>Cache-Verwaltung & Force-Check</li>
      <li>Audit-Log & Diagnose-Tools</li>
    </ul>';

if((!defined('MODULE_BX_MODULE_UPDATER_STATUS')) || (MODULE_BX_MODULE_UPDATER_STATUS != 'true') && basename($_SERVER['PHP_SELF']) == 'module_export.php') {
   $module_description .= '<p><a class="button btnbox but_red" style="text-align: center; color: #FFF;" onclick="return confirmLink(\'Alle Moduldateien löschen?\', \'\' ,this);" href="' . xtc_href_link(FILENAME_MODULE_EXPORT, 'set=system&module=bx_module_updater&action=custom&delete=true') . '">Alle Moduldateien löschen</a></p>';
}
$module_description .= '</div></details>';

define('MODULE_BX_MODULE_UPDATER_DESC', $module_description);

define('MODULE_BX_MODULE_UPDATER_STATUS_TITLE' , 'Status');
define('MODULE_BX_MODULE_UPDATER_STATUS_DESC' , 'Modul aktivieren?');

define('MODULE_BX_MODULE_UPDATER_SORT_ORDER_TITLE' , 'Sortierreihenfolge');
define('MODULE_BX_MODULE_UPDATER_SORT_ORDER_DESC' , 'Position in der Modul-Liste');

define('MODULE_BX_MODULE_UPDATER_VERSION_TITLE' , 'Version');
define('MODULE_BX_MODULE_UPDATER_VERSION_DESC' , 'Installierte Updater-Version');

define('MODULE_BX_MODULE_UPDATER_CONFIG_ID_TITLE' , 'Konfigurations-ID');
define('MODULE_BX_MODULE_UPDATER_CONFIG_ID_DESC' , 'Automatisch ermittelt.');

define('MODULE_BX_MODULE_UPDATER_UPDATE_AVAILABLE' , 'Version %s ist verfügbar. Vorhandene Moduldateien werden vor dem Update gesichert.');
define('MODULE_BX_MODULE_UPDATER_UPDATE_BUTTON' , 'Auf Version %s aktualisieren');
define('MODULE_BX_MODULE_UPDATER_UPDATE_CONFIRM' , 'Soll der Updater jetzt auf Version %s aktualisiert werden?');
define('MODULE_BX_MODULE_UPDATER_UPDATE_NOT_INSTALLABLE' , 'Das Release ist noch nicht installierbar.');
define('MODULE_BX_MODULE_UPDATER_UPDATE_CHECK' , 'Jetzt auf Updates prüfen');

defined('CFG_TXT_PRODUCTION') || define('CFG_TXT_PRODUCTION', 'Produktiv');
defined('CFG_TXT_DEVELOPMENT') || define('CFG_TXT_DEVELOPMENT', 'Entwicklung');
defined('CFG_TXT_RELEASE') || define('CFG_TXT_RELEASE', 'Release');
defined('CFG_TXT_UNSTABLE') || define('CFG_TXT_UNSTABLE', 'Instabil');