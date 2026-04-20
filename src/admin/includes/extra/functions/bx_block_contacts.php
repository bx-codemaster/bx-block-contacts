<?php
/* ----------------------------------------------------------------------------------------------
   $Id: admin/includes/extra/functions/bx_block_contacts.php 1000 2023-05-22 13:00:00Z benax $
    _                           
   | |__   ___ _ __   __ ___  __
   | '_ \ / _ \ '_ \ / _ \ \/ /
   | |_) |  __/ | | | (_| |>  < 
   |_.__/ \___|_| |_|\__,_/_/\_\
   xxxxxxxxxxxxxxxxxxxxxxxxxxxxx

   modified eCommerce Shopsoftware
   http://www.modified-shop.org

   Copyright (c) 2009 - 2013 [www.modified-shop.org]
   ----------------------------------------------------------------------------------------------
   Released under the GNU General Public License
   ----------------------------------------------------------------------------------------------*/

function bx_show_blocked () {
   if(!empty(MODULE_BLOCKED_CONTACTS)) {
      $json = array( 'emails' => array(), 'domains' => array(), 'localparts' => array(), );
      $html = '<table class="collapse" style="width: 100%;">'."\n"
            . '  <tr class="dataTableHeadingRow">'."\n"
            . '    <td class="dataTableHeadingContent">'.MODULE_BLOCKED_CONTACTS_TXT_EMAIL_ADDRESSES.'</td>'."\n"
            . '    <td class="dataTableHeadingContent">'.MODULE_BLOCKED_CONTACTS_TXT_DOMAINS.'</td>'."\n"
            . '    <td class="dataTableHeadingContent">'.MODULE_BLOCKED_CONTACTS_TXT_LOCAL_PARTS.'</td>'."\n"
            . '  </tr>'."\n"
            . '  <tr class="dataTableRow">'."\n";

      $json = json_decode(MODULE_BLOCKED_CONTACTS, true);

      foreach ($json as $key => $value) {
         switch ($key) {
            case 'emails':
               $html .= '  <td class="dataTableContent" style="width: 33.3%; vertical-align: top !important;">'."\n";
               if(is_array($value)) {
                  foreach($value as $val) {
                     $html .= '<table class="collapse" style="width: 100%;">'."\n"
                            . '  <tr>'."\n"
                            . '    <td style="width: 16px;">'."\n"
                            . '      <a href="'.xtc_href_link(FILENAME_MODULE_EXPORT, xtc_get_all_get_params(array('action','area', 'value')).'action=save&area=emails&value='.urldecode($val)).'">'.xtc_image(DIR_WS_ICONS.'cross.gif', ICON_DELETE).'</a>'."\n"
                            . '    </td>'."\n"
                            . '    <td>'.$val.'</td>'."\n"
                            . '  </tr>'."\n"
                            . '</table>'."\n";
                  }
               }
               $html .= '  </td>'."\n";
               break;
            case 'domains':
               $html .= '  <td class="dataTableContent" style="width: 33.3%; vertical-align: top !important;">'."\n";
               if(is_array($value)) {
                  foreach($value as $val) {
                     $html .= '<table class="collapse" style="width: 100%;">'."\n"
                            . '  <tr>'."\n"
                            . '    <td style="width: 16px;">'."\n"
                            . '      <a href="'.xtc_href_link(FILENAME_MODULE_EXPORT, xtc_get_all_get_params(array('action','area', 'value')).'action=save&area=domains&value='.urldecode($val)).'">'.xtc_image(DIR_WS_ICONS.'cross.gif', ICON_DELETE).'</a>'."\n"
                            . '    </td>'."\n"
                            . '    <td>'.$val.'</td>'."\n"
                            . '  </tr>'."\n"
                            . '</table>'."\n";
                  }
               }
               $html .= '  </td>'."\n";
               break;
            case 'localparts':
               $html .= '  <td class="dataTableContent" style="width: 33.3%; vertical-align: top !important;">'."\n";
               if(is_array($value)) {
                  foreach($value as $val) {
                     $html .= '<table class="collapse" style="width: 100%;">'."\n"
                            . '  <tr>'."\n"
                            . '    <td style="width: 16px;">'."\n"
                            . '      <a href="'.xtc_href_link(FILENAME_MODULE_EXPORT, xtc_get_all_get_params(array('action','area', 'value')).'action=save&area=localparts&value='.urldecode($val)).'">'.xtc_image(DIR_WS_ICONS.'cross.gif', ICON_DELETE).'</a>'."\n"
                            . '    </td>'."\n"
                            . '    <td>'.$val.'</td>'."\n"
                            . '  </tr>'."\n"
                            . '</table>'."\n";
                  }
               }
               $html .= '  </td>'."\n";
               break;
        }
      }
      $html .= '  </tr>'."\n"
             . '</table>'."\n"
             . '<hr />'."\n";
   }
   return $html;
}

function bx_bc_get_group_id() {
	$result = array();	
	$result_query_raw = xtc_db_query("SELECT configuration_value AS value 
                                      FROM ".TABLE_CONFIGURATION."
                                     WHERE configuration_key = 'MODULE_BLOCK_CONTACTS_CONFIG_ID'");
	if( 0 < xtc_db_num_rows($result_query_raw)) {
	 	$result_query= xtc_db_fetch_array($result_query_raw);
	}
	$result = $result_query['value'];
  return $result;
}
