<?php
/* -----------------------------------------------------------------------------------------
   $Id: lang/english/modules/system/bx_block_contacts.php 1000 2023-05-20 13:00:00Z benax $
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
    <h3 style="margin-top: 0;">Blocking email contacts made easy!</h3>';

// Die physische Dateilöschung wird erst nach der Deinstallation angeboten.
if((!defined('MODULE_BX_BLOCK_CONTACTS_STATUS')) || (MODULE_BX_BLOCK_CONTACTS_STATUS != 'true') && basename($_SERVER['PHP_SELF']) == 'module_export.php') {
   $module_description .= '<p><a class="button btnbox but_red" style="text-align: center; color: #FFF;" onclick="return confirmLink(\'Delete all module files?\', \'\' ,this);" href="' . xtc_href_link(FILENAME_MODULE_EXPORT, 'set=system&module=bx_block_contacts&action=custom&delete=true') . '">Delete all module files</a></p>';
}
$module_description .= '</div></details>';
  
define('MODULE_BX_BLOCK_CONTACTS_DESC', $module_description);

define('MODULE_BX_BLOCK_CONTACTS_STATUS_TITLE' , 'Status');
define('MODULE_BX_BLOCK_CONTACTS_STATUS_DESC' , 'Enable module?');

define('MODULE_BX_BLOCK_CONTACTS_CONFIG_ID_TITLE' , 'Configuration ID');
define('MODULE_BX_BLOCK_CONTACTS_CONFIG_ID_DESC' , 'Automatically determined.');

define('MODULE_BX_BLOCK_CONTACTS_VERSION_TITLE' , 'Version');
define('MODULE_BX_BLOCK_CONTACTS_VERSION_DESC' , 'Module version.');

define('MODULE_BX_BLOCK_CONTACTS_DEVELOPMENT_TITLE' , 'Development status');
define('MODULE_BX_BLOCK_CONTACTS_DEVELOPMENT_DESC' , 'Current module development status.');

define('MODULE_BX_BLOCK_CONTACTS_DEBUG_TITLE' , 'Monitor module?');
define('MODULE_BX_BLOCK_CONTACTS_DEBUG_DESC' , 'Log module input and output.');

define('MODULE_BX_BLOCK_CONTACTS_BLOCKED_TITLE' , 'BX Block Contacts');
define('MODULE_BX_BLOCK_CONTACTS_BLOCKED_DESC' , 'List of blocked items:');

define('MODULE_BLOCKED_CONTACTS_PLS_BLOCK' , 'Please block');

define('MODULE_BX_BLOCK_CONTACTS_BLOCKED_TXT_EMAIL_ADDRESSES' , 'Email addresses');
define('MODULE_BX_BLOCK_CONTACTS_BLOCKED_TXT_DOMAINS' , 'Domains');
define('MODULE_BX_BLOCK_CONTACTS_BLOCKED_TXT_LOCAL_PARTS' , 'Local parts');

define('MODULE_BX_BLOCK_CONTACTS_TEXT_COULD_NOT_BE_DELETED', 'Could not be deleted.');
define('MODULE_BX_BLOCK_CONTACTS_TEXT_SUCCESSFULLY_REMOVED', 'Successfully removed.');
define('MODULE_BX_BLOCK_CONTACTS_TEXT_REMOVAL_INCOMPLETE', 'Removal incomplete.');

defined('CFG_TXT_PRODUCTION') || define('CFG_TXT_PRODUCTION', 'Production');
defined('CFG_TXT_DEVELOPMENT') || define('CFG_TXT_DEVELOPMENT', 'Development');
defined('CFG_TXT_RELEASE') || define('CFG_TXT_RELEASE', 'Release');
defined('CFG_TXT_UNSTABLE') || define('CFG_TXT_UNSTABLE', 'Unstable');
