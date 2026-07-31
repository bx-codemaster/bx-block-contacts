<?php
/* -----------------------------------------------------------------------------------------
   $Id: lang/german/modules/system/bx_block_contacts.php 1000 2023-05-20 13:00:00Z benax $
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

define('MODULE_BX_BLOCK_CONTACTS_TITLE', 'BX Block Contacts');

$module_description = '
<details class="bxac-card">
  <summary class="bxac-summary" style="list-style: none;">
    <span class="bxac-arrow">▸</span>
    <span class="bxac-title">' . xtc_image(DIR_WS_ICONS.'heading/bx_block_contacts.png', 'BX Block Contacts', '', '', 'style="max-height: 32px; vertical-align: middle; margin-right: 8px;"') . 'BX Block Contacts</span>
</summary>
  <div class="bxac-body">
    <h3 style="margin-top: 0;">Emailkontakte blockieren einfach gemacht!</h3>';

// Die physische Dateilöschung wird erst nach der Deinstallation angeboten.
if((!defined('MODULE_BX_BLOCK_CONTACTS_STATUS')) || (MODULE_BX_BLOCK_CONTACTS_STATUS != 'true') && basename($_SERVER['PHP_SELF']) == 'module_export.php') {
   $module_description .= '<p><a class="button btnbox but_red" style="text-align: center; color: #FFF;" onclick="return confirmLink(\'Alle Moduldateien löschen?\', \'\' ,this);" href="' . xtc_href_link(FILENAME_MODULE_EXPORT, 'set=system&module=bx_block_contacts&action=custom&delete=true') . '">Alle Moduldateien löschen</a></p>';
}
$module_description .= '</div></details>';
  
define('MODULE_BX_BLOCK_CONTACTS_DESC', $module_description);

define('MODULE_BX_BLOCK_CONTACTS_STATUS_TITLE' , 'Status');
define('MODULE_BX_BLOCK_CONTACTS_STATUS_DESC' , 'Modul aktivieren?');

define('MODULE_BX_BLOCK_CONTACTS_CONFIG_ID_TITLE' , 'Konfigurations-ID');
define('MODULE_BX_BLOCK_CONTACTS_CONFIG_ID_DESC' , 'Automatisch ermittelt.');

define('MODULE_BX_BLOCK_CONTACTS_VERSION_TITLE' , 'Version');
define('MODULE_BX_BLOCK_CONTACTS_VERSION_DESC' , 'Modulversion.');

define('MODULE_BX_BLOCK_CONTACTS_DEVELOPMENT_TITLE' , 'Entwicklungsstatus');
define('MODULE_BX_BLOCK_CONTACTS_DEVELOPMENT_DESC' , 'AktuellerModulentwicklungsstatus.');

define('MODULE_BX_BLOCK_CONTACTS_DEBUG_TITLE' , 'Modul überwachen?');
define('MODULE_BX_BLOCK_CONTACTS_DEBUG_DESC' , 'Ein- und Ausgaben des Moduls protokollieren.');

define('MODULE_BX_BLOCK_CONTACTS_BLOCKED_TITLE' , 'BX Block Contacts');
define('MODULE_BX_BLOCK_CONTACTS_BLOCKED_DESC' , 'Liste der blockierten Elemente:');

define('MODULE_BLOCKED_CONTACTS_PLS_BLOCK' , 'Bitte blockieren');

define('MODULE_BX_BLOCK_CONTACTS_BLOCKED_TXT_EMAIL_ADDRESSES' , 'E-Mail-Adressen');
define('MODULE_BX_BLOCK_CONTACTS_BLOCKED_TXT_DOMAINS' , 'Domains');
define('MODULE_BX_BLOCK_CONTACTS_BLOCKED_TXT_LOCAL_PARTS' , 'Lokale Teile');

define('MODULE_BX_BLOCK_CONTACTS_TEXT_COULD_NOT_BE_DELETED', 'Konnte nicht gelöscht werden.');
define('MODULE_BX_BLOCK_CONTACTS_TEXT_SUCCESSFULLY_REMOVED', 'Erfolgreich entfernt.');
define('MODULE_BX_BLOCK_CONTACTS_TEXT_REMOVAL_INCOMPLETE', 'Entfernung unvollständig.');

defined('CFG_TXT_PRODUCTION') || define('CFG_TXT_PRODUCTION', 'Produktiv');
defined('CFG_TXT_DEVELOPMENT') || define('CFG_TXT_DEVELOPMENT', 'Entwicklung');
defined('CFG_TXT_RELEASE') || define('CFG_TXT_RELEASE', 'Release');
defined('CFG_TXT_UNSTABLE') || define('CFG_TXT_UNSTABLE', 'Instabil');

