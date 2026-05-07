<?php
/* -----------------------------------------------------------------------------------------
   $Id: admin/includes/modules/system/bx_block_contacts.php 1000 2022-05-5221 13:00:00Z benax $
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

defined( '_VALID_XTC' ) or die( 'Direct Access to this location is not allowed.' );

class bx_block_contacts {
	public string $code;
	public string $version;
	public string $development_status; // 'p' = production ready, 'd' = in development
	public string $title;
	public string $description;
	public int $sort_order;
	public bool $enabled;
	private bool $_check;

  public function __construct() {
		$this->code        = 'bx_block_contacts';
    $this->version     = '1.0.0';
    $this->title       = MODULE_BLOCK_CONTACTS_TEXT_TITLE;
    $this->description = MODULE_BX_BLOCK_CONTACTS_DESC;
    $this->sort_order  = defined('MODULE_BLOCK_CONTACTS_SORT_ORDER') ? MODULE_BLOCK_CONTACTS_SORT_ORDER : 0;
    $this->enabled     = defined('MODULE_BLOCK_CONTACTS_STATUS') ? ((MODULE_BLOCK_CONTACTS_STATUS == 'true') ? true : false) : 0;
		$this->development_status = 'p';
   }

  public function process(): never {
		var_dump($_GET);
		$json = array( 'emails' => array(), 'domains' => array(), 'localparts' => array(), );
		
		if(!empty(MODULE_BLOCKED_CONTACTS)) {
			$json = json_decode(MODULE_BLOCKED_CONTACTS, true);
		}

		if(isset($_POST["block_email"]) && !empty($_POST["block_email"])) {
			if( false === array_search(trim($_POST["block_email"]), $json["emails"])) {
				array_push($json["emails"], xtc_db_input($_POST["block_email"]));
			}
		}

		if(isset($_POST["block_domain"]) && !empty($_POST["block_domain"])) {
			if( false === array_search(trim($_POST["block_domain"]), $json["domains"])) {
				array_push($json["domains"], xtc_db_input($_POST["block_domain"]));
			}
		}

		if(isset($_POST["block_localparts"]) && !empty($_POST["block_localparts"])) {
			if( false === array_search(trim($_POST["block_localparts"]), $json["localparts"])) {
				array_push($json["localparts"], xtc_db_input($_POST["block_localparts"]));
			}
		}

		if(isset($_GET["area"]) && !empty($_GET["area"])) {
			switch($_GET["area"]) {
				case 'emails';
					if(isset($_GET["value"]) && !empty($_GET["value"])){
						$key = array_search(urldecode($_GET["value"]), $json["emails"]);
						if (false !== $key) {
							unset($json["emails"][$key]);
						}
					}
					break;
				case 'domains';
					if(isset($_GET["value"]) && !empty($_GET["value"])){
						$key = array_search(urldecode($_GET["value"]), $json["domains"]);
						if (false !== $key) {
							unset($json["domains"][$key]);
						}
					}
					break;
				case 'localparts';
					if(isset($_GET["value"]) && !empty($_GET["value"])){
						$key = array_search(urldecode($_GET["value"]), $json["localparts"]);
						if (false !== $key) {
							unset($json["localparts"][$key]);
						}
					}
					break;
			}			
		}

		$blocked = json_encode($json);
		xtc_db_perform(TABLE_CONFIGURATION, array('configuration_value' => $blocked,
																							'last_modified' => 'now()'),
																							'update', "configuration_key='MODULE_BLOCKED_CONTACTS'");
		
		xtc_redirect(xtc_href_link(FILENAME_MODULE_EXPORT,'set=system&module='.$this->code.'&action=edit'));
  }

  public function display(): array {
    $txt = '<input type="hidden" name="area" value="" /><input type="hidden" name="areavalue" value="" />
		<table class="tableConfig">'. "\n"
         . '  <tbody>'. "\n"
         . '    <tr>'. "\n"
         . '      <th class="txta-l">'.MODULE_BLOCKED_CONTACTS_PLS_BLOCK.':</th>'. "\n"
         . '      <th>&nbsp;</th>'. "\n"
         . '      <th>&nbsp;</th>'. "\n"
         . '    </tr>'. "\n"
         . '    <tr>'. "\n"
         . '      <td class="col-left">'.MODULE_BLOCKED_CONTACTS_TXT_EMAIL_ADDRESSES.':</td>'. "\n"
         . '      <td class="col-middle">'.xtc_draw_input_field('block_email', '', '').'</td>'. "\n"
         .'       <td class="col-right">'.MODULE_BLOCKED_CONTACTS_DESC_EMAIL_ADDRESSES.'</td>'. "\n"
         . '    </tr>'. "\n"
         . '    <tr>'. "\n"
         . '      <td class="col-left">'.MODULE_BLOCKED_CONTACTS_TXT_DOMAINS.':</td>'. "\n"
         . '      <td class="col-middle">'.xtc_draw_input_field('block_domain', '', '').'</td>'. "\n"
         .'       <td class="col-right">'.MODULE_BLOCKED_CONTACTS_DESC_DOMAINS.'</td>'. "\n"
         . '    </tr>'. "\n"
         . '    <tr>'. "\n"
         . '      <td class="col-left">'.MODULE_BLOCKED_CONTACTS_TXT_LOCAL_PARTS.':</td>'. "\n"
         . '      <td class="col-middle">'.xtc_draw_input_field('block_localparts', '', '').'</td>'. "\n"
         .'       <td class="col-right">'.MODULE_BLOCKED_CONTACTS_DESC_LOCAL_PARTS.'</td>'. "\n"
         . '    </tr>'. "\n"
         . '    <tr>'. "\n"
         . '      <td colspan="3"></td>'. "\n"
         . '    </tr>'. "\n"
         . '  </tbody>'. "\n"
         . '</table>'. "\n"
         . '<div style="text-align: center;">'. "\n"
         . xtc_button(BUTTON_SAVE).xtc_button_link(BUTTON_BACK, xtc_href_link(FILENAME_MODULE_EXPORT, 'set='.$_GET['set'].'&module='.$this->code)). "\n"
         . '</div>'. "\n";
    return array('text' => $txt);
	}

  public function check(): bool {
    if (!isset($this->_check)) {
      $check_query = xtc_db_query("SELECT configuration_value 
                                     FROM ".TABLE_CONFIGURATION."
                                    WHERE configuration_key = 'MODULE_BLOCK_CONTACTS_STATUS'");
      $this->_check = xtc_db_num_rows($check_query);
    }
    return $this->_check;
  }
    
  public function install(): void {
		xtc_db_query("ALTER TABLE ".TABLE_ADMIN_ACCESS." ADD bx_block_contacts INTEGER(1)");
		xtc_db_query("UPDATE ".TABLE_ADMIN_ACCESS." SET bx_block_contacts = 1");

  	$freeId_query = xtc_db_query("SELECT (configuration_group_id+1) AS id 
	                                FROM " . TABLE_CONFIGURATION_GROUP . " 
																	WHERE (configuration_group_id+1) NOT IN (SELECT configuration_group_id FROM " . TABLE_CONFIGURATION_GROUP . " WHERE configuration_group_id IS NOT NULL) 
																	LIMIT 1;");
		$freeId = xtc_db_fetch_array($freeId_query);

		$freeSort_query = xtc_db_query("SELECT (sort_order+1) AS sort_order 
	                                  FROM ".TABLE_CONFIGURATION_GROUP." 
																		WHERE (sort_order+1) NOT IN (SELECT sort_order FROM ".TABLE_CONFIGURATION_GROUP." WHERE sort_order IS NOT NULL) 
																		LIMIT 1;");
		$freeSort = xtc_db_fetch_array($freeSort_query);
		
		xtc_db_query("INSERT INTO ".TABLE_CONFIGURATION_GROUP." ( configuration_group_id,
																															configuration_group_title, 
																															configuration_group_description, 
																															sort_order, 
																															visible) 
																										 VALUES ( ".$freeId["id"].", 
																										 					'BX Block Contacts', 
																															'Kontaktverbot für Störenfriede!', 
																															".$freeSort["sort_order"].", 
																															1)");

		xtc_db_query("INSERT INTO ".TABLE_CONFIGURATION." ( configuration_id,
		                                                    configuration_key, 
																												configuration_value, 
																												configuration_group_id, 
																												sort_order, 
																												date_added, 
																												use_function, 
																												set_function )
																							 VALUES ( '', 
																							          'MODULE_BLOCK_CONTACTS_STATUS',
																												'true', 
																												'6', 
																												'1', 
																												now(), 
																												'', 
																												'xtc_cfg_select_option(array(\'true\', \'false\'), ')");

		xtc_db_query("INSERT INTO ".TABLE_CONFIGURATION." ( configuration_id, 
		                                                    configuration_key, 
																												configuration_value, 
																												configuration_group_id, 
																												sort_order, 
																												date_added, 
																												use_function, 
																												set_function )
																							 VALUES ( '', 
																							          'MODULE_BLOCK_CONTACTS_CONFIG_ID',
																							          '".$freeId["id"]."', 
																												'6', 
																												'2', 
																												now(), 
																												'bx_bc_get_group_id', 
																												'xtc_convert_value( ')");
		
		xtc_db_query("INSERT INTO ".TABLE_CONFIGURATION." ( configuration_id, 
		                                                    configuration_key, 
																												configuration_value, 
																												configuration_group_id, 
																												sort_order, 
																												date_added, 
																												use_function, 
																												set_function )
																							 VALUES ( '', 
																							          'MODULE_BLOCKED_CONTACTS',
																							          '', 
																												'".$freeId["id"]."', 
																												'2', 
																												now(), 
																												'', 
																												'bx_show_blocked( ')");
	}

  public function remove(): void {
    xtc_db_query("DELETE FROM ".TABLE_CONFIGURATION." WHERE configuration_key in ('".implode("', '", $this->keys())."')");
		xtc_db_query("DELETE FROM ".TABLE_CONFIGURATION_GROUP." WHERE configuration_group_title = 'BX Block Contacts'");
		xtc_db_query("ALTER TABLE ".TABLE_ADMIN_ACCESS." DROP bx_block_contacts");
  }

  public function keys(): array {
    $key = array(
      'MODULE_BLOCK_CONTACTS_STATUS',
			'MODULE_BLOCK_CONTACTS_CONFIG_ID',
      'MODULE_BLOCKED_CONTACTS',
    );
    return $key;
  }
}
