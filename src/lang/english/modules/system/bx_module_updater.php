<?php
/* -----------------------------------------------------------------------------------------
   $Id: lang/english/modules/system/bx_module_updater.php 1000 2026-11-24 00:00:00Z benax $
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
    ' . xtc_image(DIR_WS_ICONS.'heading/bx_module_updater.png', 'BX Module Updater') . '
    <span class="bxac-title">BX Module Updater</span>
  </summary>
  <div class="bxac-body">
    <h3 style="margin-top: 0;">Central Update Management for all bx-codemaster Modules</h3>
    <p>This module provides the shared updater infrastructure used by all
       bx-codemaster modules for secure, signed GitHub updates.</p>
    <ul>
      <li>Self-update of the updater core</li>
      <li>Overview of all installed modules with updater integration</li>
      <li>Per-module update status</li>
      <li>Cache management & force check</li>
      <li>Audit log & diagnostic tools</li>
    </ul>';

if((!defined('MODULE_BX_MODULE_UPDATER_STATUS')) || (MODULE_BX_MODULE_UPDATER_STATUS != 'true') && basename($_SERVER['PHP_SELF']) == 'module_export.php') {
   $module_description .= '<p><a class="button btnbox but_red" style="text-align: center; color: #FFF;" onclick="return confirmLink(\'Delete all module files?\', \'\' ,this);" href="' . xtc_href_link(FILENAME_MODULE_EXPORT, 'set=system&module=bx_module_updater&action=custom&delete=true') . '">Delete all module files</a></p>';
}
$module_description .= '</div></details>';

define('MODULE_BX_MODULE_UPDATER_DESC', $module_description);

define('MODULE_BX_MODULE_UPDATER_STATUS_TITLE' , 'Status');
define('MODULE_BX_MODULE_UPDATER_STATUS_DESC' , 'Enable module?');

define('MODULE_BX_MODULE_UPDATER_SORT_ORDER_TITLE' , 'Sort order');
define('MODULE_BX_MODULE_UPDATER_SORT_ORDER_DESC' , 'Position in module list');

define('MODULE_BX_MODULE_UPDATER_VERSION_TITLE' , 'Version');
define('MODULE_BX_MODULE_UPDATER_VERSION_DESC' , 'Installed updater version');

define('MODULE_BX_MODULE_UPDATER_CONFIG_ID_TITLE' , 'Configuration ID');
define('MODULE_BX_MODULE_UPDATER_CONFIG_ID_DESC' , 'Automatically determined.');

define('MODULE_BX_MODULE_UPDATER_UPDATE_AVAILABLE' , 'Version %s is available. Existing module files will be backed up before updating.');
define('MODULE_BX_MODULE_UPDATER_UPDATE_BUTTON' , 'Update to version %s');
define('MODULE_BX_MODULE_UPDATER_UPDATE_CONFIRM' , 'Update the updater to version %s now?');
define('MODULE_BX_MODULE_UPDATER_UPDATE_NOT_INSTALLABLE' , 'The release is not installable yet.');
define('MODULE_BX_MODULE_UPDATER_UPDATE_CHECK' , 'Check for updates now');

defined('CFG_TXT_PRODUCTION') || define('CFG_TXT_PRODUCTION', 'Production');
defined('CFG_TXT_DEVELOPMENT') || define('CFG_TXT_DEVELOPMENT', 'Development');
defined('CFG_TXT_RELEASE') || define('CFG_TXT_RELEASE', 'Release');
defined('CFG_TXT_UNSTABLE') || define('CFG_TXT_UNSTABLE', 'Unstable');