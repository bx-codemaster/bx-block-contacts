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

define('MODULE_BLOCK_CONTACTS_TEXT_TITLE', 'BX Block Contacts');

$module_description = '
<details class="bxac-card">
  <summary class="bxac-summary" style="list-style: none;">
    <span class="bxac-arrow">▸</span>
    <span class="bxac-title">' . xtc_image(DIR_WS_ICONS.'heading/bx_block_contacts.png', 'BX Block Contacts', '', '', 'style="max-height: 32px; vertical-align: middle; margin-right: 8px;"') . 'BX Block Contacts</span>
</summary>
  <div class="bxac-body">
    <h3 style="margin-top: 0;">Blocking email contacts made easy!</h3>';

// Physical file deletion is only offered after uninstallation.
if(!defined('MODULE_BX_BLOCK_CONTACTS_STATUS') && basename($_SERVER['PHP_SELF']) !== 'start.php') {
   $module_description .= '<p><a class="button btnbox but_red" style="text-align: center; color: #FFF;" onclick="return confirmLink(\'Delete all module files?\', \'\' ,this);" href="' . xtc_href_link(FILENAME_MODULE_EXPORT, 'set=system&module=bx_block_contacts&action=custom&delete=true') . '">Delete all module files</a></p>';
}
$module_description .= '</div></details>';
  
define('MODULE_BX_BLOCK_CONTACTS_DESC', $module_description);
define('MODULE_BLOCK_CONTACTS_STATUS_TITLE' , 'Status');
define('MODULE_BLOCK_CONTACTS_STATUS_DESC' , 'Activate module?');
define('MODULE_BLOCK_CONTACTS_CONFIG_ID_TITLE' , 'Configuration ID');
define('MODULE_BLOCK_CONTACTS_CONFIG_ID_DESC' , 'Automatically determined.');
define('MODULE_BLOCK_CONTACTS_DEBUG_TITLE' , 'Monitor module?');
define('MODULE_BLOCK_CONTACTS_DEBUG_DESC' , 'Log inputs and outputs of the module.');
define('MODULE_BLOCKED_CONTACTS_TITLE' , 'BX Block Contacts');
define('MODULE_BLOCKED_CONTACTS_DESC' , 'List of blocked elements:');
define('MODULE_BLOCKED_CONTACTS_PLS_BLOCK' , 'Please block');
