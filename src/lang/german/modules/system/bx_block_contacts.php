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

define('MODULE_BLOCK_CONTACTS_TEXT_TITLE', 'BX Block Contacts');

$module_description = '<table>
<tr>
   <td style="vertical-align: top;">'.xtc_image(DIR_WS_ICONS.'heading/bx_block_contacts.png', 'BX Block Contacts', '', '', 'style="max-height: 32px;"').'</td>
   <td>
   <h3 style="margin-top: 0;">BX Block Contacts</h3>
   <p>Emailkontakte blockieren einfach gemacht!</p>
   </td>
</tr></table>';

// Die physische Dateilöschung wird erst nach der Deinstallation angeboten.
if(!defined('MODULE_BX_BLOCK_CONTACTS_STATUS') && basename($_SERVER['PHP_SELF']) !== 'start.php') {
   $module_description .= '<p><a class="button btnbox but_red" style="text-align: center; color: #FFF;" onclick="return confirmLink(\'Alle Moduldateien löschen?\', \'\' ,this);" href="' . xtc_href_link(FILENAME_MODULE_EXPORT, 'set=system&module=bx_block_contacts&action=custom&delete=true') . '">Alle Moduldateien löschen</a></p>';
}
  
 define('MODULE_BX_BLOCK_CONTACTS_DESC', $module_description);

define('MODULE_BLOCK_CONTACTS_STATUS_TITLE' , 'Status');
define('MODULE_BLOCK_CONTACTS_STATUS_DESC' , 'Modul aktivieren?');
define('MODULE_BLOCK_CONTACTS_CONFIG_ID_TITLE' , 'Konfigurations-ID');
define('MODULE_BLOCK_CONTACTS_CONFIG_ID_DESC' , 'Automatisch ermittelt.');
define('MODULE_BLOCK_CONTACTS_DEBUG_TITLE' , 'Modul überwachen?');
define('MODULE_BLOCK_CONTACTS_DEBUG_DESC' , 'Ein- und Ausgaben des Moduls protokollieren.');
define('MODULE_BLOCKED_CONTACTS_TITLE' , 'BX Block Contacts');
define('MODULE_BLOCKED_CONTACTS_DESC' , 'Liste der blockierten Elemente:');
define('MODULE_BLOCKED_CONTACTS_PLS_BLOCK' , 'Bitte blockieren');
