<?php
/*------------------------------------------------------------------------------
  $Id$

  AbanteCart, Ideal OpenSource Ecommerce Solution
  http://www.AbanteCart.com

  Copyright © 2011-2014 Belavier Commerce LLC

  This source file is subject to Open Software License (OSL 3.0)
  License details is bundled with this package in the file LICENSE.txt.
  It is also available at this URL:
  <http://www.opensource.org/licenses/OSL-3.0>

 UPGRADE NOTE:
   Do not edit or add to this file if you wish to upgrade AbanteCart to newer
   versions in the future. If you wish to customize AbanteCart for your
   needs please refer to http://www.AbanteCart.com for more information.
------------------------------------------------------------------------------*/
if (!defined('DIR_CORE')) {
	header('Location: static_pages/');
}
/**
 * @property ACache $cache
 * @property ASession $session
 * @property ADB $db
 * @property AConfig $config
 * @property ALog $log
 * @property AMessage $message
 * @property ALanguageManager $language
 */
class ALayoutManager {
	protected $registry;
	private $pages = array();
	private $layouts = array();
	private $blocks = array();
	private $main_placeholders = array('header', 'header_bottom', 'column_left', 'content_top', 'content_bottom', 'column_right', 'footer_top', 'footer');
	private $tmpl_id;
	private $layout_id;
	private $active_layout;
	private $page_id;
	private $custom_blocks = array();
	public $errors = 0;

	const LAYOUT_TYPE_DEFAULT = 0;
  const LAYOUT_TYPE_ACTIVE = 1;
  const LAYOUT_TYPE_DRAFT = 2;
  const LAYOUT_TYPE_TEMPLATE = 3;

	//Layout Manager Class to handle layout in the admin
	//NOTES: Object can be constructed with specific template, page or layout id provided
	//	     Possible to create an object with no specifics to access layout methods. 
	public function __construct($tmpl_id = '', $page_id = '', $layout_id = '') {
		if (!IS_ADMIN) { // forbid for non admin calls
			throw new AException (AC_ERR_LOAD, 'Error: permission denied to change page layout');
		}

		$this->registry = Registry::getInstance();

		$this->tmpl_id = !empty ($tmpl_id) ? $tmpl_id : $this->config->get('config_storefront_template');

		//load all pages specific to set template. No cross template page/layouts
		$this->pages = $this->getPages();
		//set current page for this object instance 
		$this->_set_current_page($page_id, $layout_id);
		$this->page_id = $this->page['page_id'];

		//preload all layouts for this page and template
		//NOTE: layout_type: 0 Default, 1 Active layout, 2 draft layout, 3 template layout
		$this->layouts = $this->getLayouts();
		//locate layout for the page instance. If not specified for this instance fist active layout is used 
		foreach ($this->layouts as $layout) {
			if (!empty ($layout_id)) {
				if ($layout ['layout_id'] == $layout_id) {
					$this->active_layout = $layout;
					break;
				}
			} else {
				if ($layout ['layout_type'] == 1) {
					$this->active_layout = $layout;
					break;
				}
			}
		}

		//if not layout set, use default (layout_type=0) layout 
		if (count($this->active_layout) == 0) {
			$this->active_layout = $this->getLayouts(0);
			if (count($this->active_layout) == 0) {
				$message_text = 'No layout found for page_id/controller ' . $this->page_id . '::' . $this->page ['controller'] . '!';
				$message_text .= ' Requested data: template: '.$tmpl_id.', page_id: '.$page_id.', layout_id: '.$layout_id;
				throw new AException (AC_ERR_LOAD_LAYOUT, $message_text);
			}
		}

		$this->layout_id = $this->active_layout['layout_id'];

		ADebug::variable('Template id', $this->tmpl_id);
		ADebug::variable('Page id', $this->page_id);
		ADebug::variable('Layout id', $this->layout_id);

		// Get blocks
		$this->all_blocks = $this->getAllBlocks();
		$this->blocks = $this->_getLayoutBlocks();
	}

	public function __get($key) {
		return $this->registry->get($key);
	}

	public function __set($key, $value) {
		$this->registry->set($key, $value);
	}

	/**
     * Select pages on specified parameters linked to layout and template. 
     * Note: returns an array of matching pages. 
	 * @param string $controller
	 * @param string $key_param
	 * @param string $key_value
	 * @param string $template_id
	 * @return array
	 */
	public function getPages($controller = '', $key_param = '', $key_value = '', $template_id = '') {
		if (!$template_id) {
			$template_id = $this->tmpl_id;
		}
	
		$language_id = $this->session->data['content_language_id'];
		$where = "WHERE l.template_id = '" . $this->db->escape($template_id) . "' ";
		if (!empty($controller)) { 
			$where .= "AND p.controller = '" . $this->db->escape($controller) . "' ";

			if (!empty ($key_param)) {
				$where .= empty ($key_param) ? "" : "AND p.key_param = '" . $this->db->escape($key_param) . "' ";
				$where .= empty ($key_value) ? "" : "AND p.key_value = '" . $this->db->escape($key_value) . "' ";
			}
		}	

		$sql = " SELECT p.page_id,
						p.controller,
						p.key_param,
						p.key_value,
						p.created,
						p.updated,
						CASE WHEN l.layout_type = 2 THEN CONCAT(pd.name,' (draft)') ELSE pd.name END as `name`,
						pd.title,
						pd.seo_url,
						pd.keywords,
						pd.description,
						pd.content,
						pl.layout_id,
						l.layout_name
				FROM " . DB_PREFIX . "pages p " . "
				LEFT JOIN " . DB_PREFIX . "page_descriptions pd ON (p.page_id = pd.page_id AND pd.language_id = '" . (int)$language_id . "' )
				LEFT JOIN " . DB_PREFIX . "pages_layouts pl ON pl.page_id = p.page_id
				LEFT JOIN " . DB_PREFIX . "layouts l ON l.layout_id = pl.layout_id
				" . $where . "
				ORDER BY p.page_id ASC";

		$query = $this->db->query($sql);
		$pages = $query->rows;
		//process pages and tag restricted layout/pages
		//rescticted layouts are the once without key_param and key_value
		foreach($pages as $count => $page) {
			if (!has_value($page['key_param']) && !has_value($page['key_value'])) {
				$pages[$count]['restricted'] = true; 
			}
		}
		return $pages;
	}

	/**
     * get available layouts for layout instance and layout types provided 
	 * @param string $layout_type
	 * @return array
	 */
	public function getLayouts($layout_type = '') {
		$cache_name = 'layout.a.layouts.' . $this->tmpl_id . '.' . $this->page_id . (!empty ($layout_type) ? '.' . $layout_type : '');
		if (( string )$layout_type == '0') {
			$cache_name = 'layout.a.default.' . $this->tmpl_id;
		}
		$layouts = $this->cache->get($cache_name, '', (int)$this->config->get('config_store_id'));
		if (!empty ($layouts)) {
			// return cached layouts
			return $layouts;
		}

		$where = 'WHERE template_id = "' . $this->db->escape($this->tmpl_id) . '" ';
		$join = '';

		if (( string )$layout_type != '0') {
			$where .= "AND pl.page_id = '" . ( int )$this->page_id . "' ";
			$join = "LEFT JOIN " . DB_PREFIX . "pages_layouts as pl ON (l.layout_id = pl.layout_id) ";
		}
		if (!empty ($layout_type)) {
			$where .= empty ($layout_type) ? "" : "AND layout_type = '" . ( int )$layout_type . "' ";
		}

		$sql = "SELECT " . "l.layout_id as layout_id, "
				. "l.template_id as template_id, "
				. "l.store_id as store_id, "
				. "l.layout_type as layout_type, "
				. "l.layout_name as layout_name, "
				. "l.created as created, "
				. "l.updated as updated "
				. "FROM " . DB_PREFIX . "layouts as l "
				. $join
				. $where
				. " ORDER BY " . "l.layout_id ASC";

		$query = $this->db->query($sql);

		if (( string )$layout_type == '0') {
			$layouts = $query->row;
		} else {
			$layouts = $query->rows;
		}

		$this->cache->set($cache_name, $layouts, '', (int)$this->config->get('config_store_id'));

		return $layouts;
	}

	/**
	 * Run logic to detect page ID and layout ID for given parameters
	 * This will detect if requested page already has layout or return default overwise.
	 * @param string $controller
	 * @param string $key_param
	 * @param string $key_value
	 * @return array
	 */
	public function getPageLayoutIDs($controller = '', $key_param = '', $key_value = '') {
		$ret_arr = array();
		if ( !has_value($controller) ) {
			return $ret_arr;
		}
		$pages = $this->getPages($controller, $key_param, $key_value);
		//check if we got most specific page/layout
		if ( count($pages) && has_value($pages[0]['page_id']) ) {
			$ret_arr['page_id'] = $pages[0]['page_id'];
			$ret_arr['layout_id'] = $pages[0]['layout_id'];
		} else {
			$pages = $this->getPages($controller);
			if(count($pages) && !$pages[0]['key_param']){ 
				$ret_arr['page_id'] = $pages[0]['page_id'];
				$ret_arr['layout_id'] = $pages[0]['layout_id'];
			}else{
				$pages = $this->getPages('generic');
				$ret_arr['page_id'] = $pages[0]['page_id'];
				$ret_arr['layout_id'] = $pages[0]['layout_id'];
			}
		}
		unset($pages);
		return $ret_arr;
	}
	
	
	private function _getLayoutBlocks($layout_id = 0) {
		$layout_id = !$layout_id ? $this->layout_id : $layout_id;

		$cache_name = 'layout.a.blocks.' . $layout_id;
		$blocks = $this->cache->get($cache_name, '', (int)$this->config->get('config_store_id'));
		if (!empty ($blocks)) {
			// return cached blocks
			return $blocks;
		}

		$sql = "SELECT bl.instance_id as instance_id,
					   bl.layout_id as layout_id,
					   b.block_id as block_id,
					   bl.custom_block_id as custom_block_id,
					   bl.parent_instance_id as parent_instance_id,
					   bl.position as position,
					   bl.status as status,
					   b.block_txt_id as block_txt_id,
					   b.controller as controller
				FROM " . DB_PREFIX . "blocks as b
				LEFT JOIN " . DB_PREFIX . "block_layouts as bl ON (bl.block_id = b.block_id)
				WHERE bl.layout_id = '" . $layout_id . "'
				ORDER BY bl.parent_instance_id ASC, bl.position ASC";

		$query = $this->db->query($sql);
		$blocks = $query->rows;

		$this->cache->set($cache_name, $blocks, '', (int)$this->config->get('config_store_id'));

		return $blocks;
	}

	public function getAllBlocks() {
		$cache_name = 'layout.a.blocks.all.' . $this->config->get('storefront_language_id');
		$blocks = $this->cache->get($cache_name, '', (int)$this->config->get('config_store_id'));
		if (!empty ($blocks)) {
			// return cached blocks
			return $blocks;
		}

		$sql = "SELECT b.block_id as block_id, "
				. "b.block_txt_id as block_txt_id, "
				. "b.controller as controller, "
				. "bt.parent_block_id as parent_block_id, "
				. "bt.template as template, "
				. "COALESCE(cb.custom_block_id,0) as custom_block_id, "
				. "b.created as block_date_added "
				. "FROM " . DB_PREFIX . "blocks as b "
				. "LEFT JOIN " . DB_PREFIX . "block_templates as bt ON (b.block_id = bt.block_id) "
				. "LEFT JOIN " . DB_PREFIX . "custom_blocks as cb ON (b.block_id = cb.block_id ) "
				. "ORDER BY b.block_id ASC";

		$query = $this->db->query($sql);
		if ($query->num_rows) {
			foreach ($query->rows as $block) {
				if ($block['custom_block_id']) {
					$block['block_name'] = $this->getCustomBlockName($block['custom_block_id'], (int)$this->config->get('storefront_language_id'));
				}
				$blocks[] = $block;
			}
		}

		$this->cache->set($cache_name, $blocks, '', (int)$this->config->get('config_store_id'));
		return $blocks;
	}

	/**
	 * @param string $data
	 * @param string $mode
	 * @return array|int
	 */
	public function getBlocksList($data = '', $mode = '') {
		$language_id = !(int)$data['language_id'] ? $this->config->get('storefront_language_id') : (int)$data['language_id'];

		if ($mode != 'total_only') {
			$sql = "SELECT b.block_id as block_id, "
					. "b.block_txt_id as block_txt_id, "
					. "COALESCE(cb.custom_block_id,0) as custom_block_id, "
					. "COALESCE(bd.name,'') as block_name, "
					. "(SELECT MAX(status) AS status
								FROM " . DB_PREFIX . "block_layouts bl
								WHERE bl.custom_block_id = cb.custom_block_id)  as status, "
					. "b.created as block_date_added ";
		} else {
			$sql = "SELECT COUNT(*) as total ";
		}

		$sql .= "FROM " . DB_PREFIX . "blocks as b "
				. "LEFT JOIN " . DB_PREFIX . "custom_blocks as cb ON (b.block_id = cb.block_id ) "
				. "LEFT JOIN " . DB_PREFIX . "block_descriptions as bd
						ON (bd.custom_block_id = cb.custom_block_id AND  bd.language_id = '" . $language_id . "') ";
		if ($mode != 'total_only') {

			if ($data['subsql_filter']) {
				$sql .= 'WHERE ' . $data['subsql_filter'] . ' ';
			}

			$sort_data = array(
				'name' => ' block_name',
				'block_txt_id' => 'b.block_txt_id',
				'status' => 'status'
			);

			if (isset($data['sort']) && in_array($data['sort'], array_keys($sort_data))) {
				$sql .= " ORDER BY " . $sort_data[$data['sort']];
			} else {
				$sql .= " ORDER BY b.block_id";
			}

			if (isset($data['order']) && ($data['order'] == 'DESC')) {
				$sql .= " DESC";
			} else {
				$sql .= " ASC";
			}

			if (isset($data['start']) || isset($data['limit'])) {
				if ($data['start'] < 0) {
					$data['start'] = 0;
				}

				if ($data['limit'] < 1) {
					$data['limit'] = 20;
				}

				$sql .= " LIMIT " . (int)$data['start'] . "," . (int)$data['limit'];
			}

		}

		$query = $this->db->query($sql);
		return $mode != 'total_only' ? $query->rows : $query->row['total'];
	}

	/**
	 * @return string
	 */
	public function getTemplateId() {
		return $this->tmpl_id;
	}

	/**
	 * Returns all pages for this instance (template)
	 * @return array
	 */
	public function getAllPages() {
		return $this->pages;
	}

	/**
	 * @return array
	 */
	public function getPageData() {
		return $this->page;
	}

	/**
	 * @param $controller string
	 * @return array
	 */
	public function getPageByController($controller) {
		foreach ($this->pages as $page) {
			if ($page ['controller'] == $controller) {
				return $page;
			}
		}
		return array();
	}

	/**
	 * @return array
	 */
	public function getActiveLayout() {
		return $this->active_layout;
	}

	/**
	 * @param $block_txt_id string
	 * @return array
	 */
	public function getLayoutBlockByTxtId($block_txt_id) {
		foreach ($this->blocks as $block) {
			if ($block ['block_txt_id'] == $block_txt_id) {
				return $block;
			}
		}
		return array();
	}

	/**
	 * @param $block_txt_id string
	 * @return array
	 */
	public function getBlockByTxtId($block_txt_id) {
		foreach ($this->all_blocks as $block) {
			if ($block ['block_txt_id'] == $block_txt_id) {
				return $block;
			}
		}
		return array();
	}

	/**
	 * @param int $instance_id
	 * @return array
	 */
	public function getBlockChildren($instance_id = 0) {
		$blocks = array();
		foreach ($this->blocks as $block) {
			if (( string )$block ['parent_instance_id'] == ( string )$instance_id) {
				array_push($blocks, $block);
			}
		}
		return $blocks;
	}

	/**
	 * @return array
	 */
	public function getInstalledBlocks() {
		$blocks = array();

		foreach ($this->all_blocks as $block) {
			// do not include main level blocks
			if (!in_array($block ['block_txt_id'], $this->main_placeholders)) {
				$blocks [] = $block;
			}
		}

		return $blocks;
	}

	/**
	 * @return array
	 */
	public function getLayoutBlocks() {
		$blocks = array();

		foreach ($this->main_placeholders as $placeholder) {
			$block = $this->getLayoutBlockByTxtId($placeholder);
			if (!empty ($block)) {
				$blocks [$block ['block_id']] = $block;
				$blocks [$block ['block_id']] ['children'] = $this->getBlockChildren($block ['instance_id']);
			}
		}

		return $blocks;
	}

	/**
	 * @param $layout_type
	 * @return array
	 */
	public function getLayoutByType($layout_type) {
		$layouts = array();

		foreach ($this->layouts as $layout) {
			if ($layout ['layout_type'] == $layout_type) {
				$layouts [] = $layout;
			}
		}

		return $layouts;
	}

	/**
	 * @return array
	 */
	public function getLayoutDrafts() {
		return $this->getLayoutByType(2);
	}

	/**
	 * @return array
	 */
	public function getLayoutTemplates() {
		return $this->getLayoutByType(3);
	}

	/**
	 * @return int
	 */
	public function getLayoutId() {
		return $this->layout_id;
	}

	/**
	 * Save Page/Layout and Layout Blocks
	 * @param $data array
	 * @return bool
	 */
	public function savePageLayout($data) {
		$page = $this->page;
		$layout = $this->active_layout;
		$new_layout = false;

		if ($layout ['layout_type'] == 0 && ($page ['controller'] != 'generic' || $data['controller'])) {
			$layout ['layout_name'] = $data ['layout_name'];
			$layout ['layout_type'] = self::LAYOUT_TYPE_ACTIVE;
			$this->layout_id = $this->saveLayout($layout);
			$new_layout = true;

			$this->db->query("INSERT INTO " . DB_PREFIX . "pages_layouts (layout_id,page_id)
								VALUES ('" . ( int )$this->layout_id . "','" . ( int )$this->page_id . "')");
		}

		foreach ($this->main_placeholders as $placeholder) {
			$block = $this->getLayoutBlockByTxtId($placeholder);
			if (!empty ($block)) {
				list($block ['block_id'], $block ['custom_block_id']) = explode("_", $block ['block_id']);
				if (!empty ($data ['blocks'] [$block ['block_id']])) {
					$block = array_merge($block, $data ['blocks'] [$block ['block_id']]);
					if ($new_layout) {
						$block ['layout_id'] = $this->layout_id;
						$instance_id = $this->saveLayoutBlocks($block);
					} else {
						$instance_id = $this->saveLayoutBlocks($block, $block ['instance_id']);
					}

					if (isset ($data ['blocks'] [$block ['block_id']] ['children'])) {
						$this->deleteLayoutBlocks($this->layout_id, $instance_id);

						foreach ($data ['blocks'] [$block ['block_id']] ['children'] as $key => $block_data) {
							$child = array();
							if (!empty ($block_data)) {
								$child ['layout_id'] = $this->layout_id;
								list($child ['block_id'], $child ['custom_block_id']) = explode("_", $block_data['block_id']);
								$child ['parent_instance_id'] = $instance_id;
								//NOTE: Blocks possitions are saved in 10th increment starting from 10
								$child ['position'] = ($key + 1) * 10;
								$child ['status'] = $block_data['status'];
								$this->saveLayoutBlocks($child);
							}
						}
					}
				}
			}
		}

		$this->cache->delete('layout');
		return true;
	}

	/**
	 * Save Page/Layout and Layout Blocks Draft
	 * @param $data array
	 * @return bool
	 */
	public function savePageLayoutAsDraft($data) {
		$page = $this->page;
		$layout = $this->active_layout;
		$layout ['layout_type'] = self::LAYOUT_TYPE_DRAFT;
		
		$new_layout_id = $this->saveLayout($layout);

		$this->db->query("INSERT INTO " . DB_PREFIX . "pages_layouts (layout_id,page_id)
							VALUES ('" . (int)$new_layout_id . "','" . (int)$this->page_id . "')");

		foreach ($this->main_placeholders as $placeholder) {
			$block = $this->getLayoutBlockByTxtId($placeholder);
			if (!empty ($block)) {
				list($block['block_id'], $block['custom_block_id']) = explode("_", $block['block_id']);

				if (!empty($data['blocks'][$block['block_id']])) {
					$block = array_merge($block, $data['blocks'][$block ['block_id']]);

					$block['layout_id'] = $new_layout_id;
					$instance_id = $this->saveLayoutBlocks($block);

					if (isset($data['blocks'][$block['block_id']]['children'])) {
						foreach ($data['blocks'][$block['block_id']]['children'] as $key => $block_data) {
							$child = array();
							if (!empty ($block_data)) {
								$child['layout_id'] = $new_layout_id;
								list($child['block_id'], $child['custom_block_id']) = explode("_", $block_data['block_id']);
								$child['parent_instance_id'] = $instance_id;
								$child['position'] = ($key + 1) * 10;
								$child['status'] = $block_data['status'];
								$this->saveLayoutBlocks($child);
							}
						}
					}
				}
			}
		}

		$this->cache->delete('layout');
		
		return $new_layout_id;
	}

	/**
	 * Function to clone layout linked to the page
	 * @param $src_layout_id
	 * @param string $dest_layout_id
	 * @param string $layout_name
	 * @return bool
	 */
	public function clonePageLayout($src_layout_id, $dest_layout_id = '', $layout_name = '') {
		if ( !has_value( $src_layout_id )) {
			return false;
		}

		$layout = $this->active_layout;

		//this is a new layout
		if ( !$dest_layout_id ) {
			if ($layout_name) {
				$layout ['layout_name'] = $layout_name;
			}
			$layout ['layout_type'] = 1;
			$this->layout_id = $this->saveLayout($layout);
			$dest_layout_id = $this->layout_id;

			$this->db->query("INSERT INTO " . DB_PREFIX . "pages_layouts (layout_id,page_id)
								VALUES ('" . ( int )$this->layout_id . "','" . ( int )$this->page_id . "')");
		} else {
			#delete existing layout data if provided cannot delete based on $this->layout_id (done on purpose for confirmation)
			$this->deleteAllLayoutBlocks($dest_layout_id);
		}

		#clone blocks from source layout
		$this->cloneLayoutBlocks($src_layout_id, $dest_layout_id);

		$this->cache->delete('layout');
		return true;
	}

	/**
	 * Function to delete page and layout linked to the page
	 * @param int $page_id
	 * @param int $layout_id
	 * @return bool
	 */
	public function deletePageLayoutByID($page_id, $layout_id) {
		if ( !has_value( $page_id ) || !has_value( $layout_id )) {
			return false;
		}

		$this->db->query("DELETE FROM " . DB_PREFIX . "layouts WHERE layout_id = '" . (int)$layout_id . "'");
		$this->db->query("DELETE FROM " . DB_PREFIX . "pages WHERE page_id = '" . (int)$page_id . "'");
		$this->db->query("DELETE FROM " . DB_PREFIX . "page_descriptions WHERE page_id = '" . (int)$page_id . "'");
		$this->db->query("DELETE FROM " . DB_PREFIX . "pages_layouts WHERE layout_id = '" . (int)$layout_id . "' AND page_id = '".(int)$page_id."'");
		$this->deleteAllLayoutBlocks($layout_id);

		$this->cache->delete('layout');
		return true;
	}

	/**
	 * Function to delete page and layout linked to the page
	 * @param $controller , $key_param, $key_value (all required)
	 * @param $key_param
	 * @param $key_value
	 * @return bool
	 */
	public function deletePageLayout( $controller, $key_param, $key_value ) {
		if (empty($controller) || empty($key_param) || empty($key_value)) return false;
		$pages = $this->getPages($controller, $key_param, $key_value);
		if ($pages) {
			foreach ($pages as $page) {
				$this->deletePageLayoutByID($page['page_id'], $page['layout_id']);
			}
		}
		return true;
	}

	/**
	 * @param $data array
	 * @param int $instance_id
	 * @return int
	 */
	public function saveLayoutBlocks($data, $instance_id = 0) {
		if (!$instance_id) {
			$this->db->query("INSERT INTO " . DB_PREFIX . "block_layouts (layout_id,block_id,custom_block_id,parent_instance_id,position,status,created,updated)
										VALUES ('" . ( int )$data ['layout_id'] . "',
												'" . ( int )$data ['block_id'] . "',
												'" . ( int )$data ['custom_block_id'] . "',
												'" . ( int )$data ['parent_instance_id'] . "',
												'" . ( int )$data ['position'] . "',
												'" . ( int )$data ['status'] . "',
												NOW(),
												NOW())");

			$instance_id = $this->db->getLastId();
		} else {
			$this->db->query("UPDATE " . DB_PREFIX . "block_layouts
										 SET layout_id = '" . ( int )$data ['layout_id'] . "',
												block_id = '" . ( int )$data ['block_id'] . "',
												custom_block_id = '" . ( int )$data ['custom_block_id'] . "',
												parent_instance_id = '" . ( int )$data ['parent_instance_id'] . "',
												position = '" . ( int )$data ['position'] . "',
												status = '" . ( int )$data ['status'] . "',
												updated = NOW()
										 WHERE instance_id = '" . ( int )$instance_id . "'");
		}

		$this->cache->delete('layout.a.blocks');
		$this->cache->delete('layout.blocks');

		return $instance_id;
	}

	/**
	 * Delete blocks from the layout based on instance ID
	 * @param int $layout_id
	 * @param int $parent_instance_id
	 * @return bool
	 * @throws AException
	 */
	public function deleteLayoutBlocks($layout_id = 0, $parent_instance_id = 0) {
		if (!$parent_instance_id && !$layout_id) {
			throw new AException (AC_ERR_LOAD, 'Error: Cannot to delete layout block, parent_instance_id "' . $parent_instance_id . '" and layout_id "' . $layout_id . '" doesn\'t exists.');
		} else {
			$this->db->query("DELETE FROM " . DB_PREFIX . "block_layouts
								WHERE layout_id = '" . ( int )$layout_id . "' AND parent_instance_id = '" . ( int )$parent_instance_id . "'");

			$this->cache->delete('layout.a.blocks');
			$this->cache->delete('layout.blocks');
		}
		return true;
	}

	/**
	 * Delete All blocks from the layout
	 * @param int $layout_id
	 * @return bool
	 * @throws AException
	 */
	public function deleteAllLayoutBlocks($layout_id = 0) {
		if (!$layout_id) {
			throw new AException (AC_ERR_LOAD, 'Error: Cannot to delete layout blocks. Missing layout ID!');
		} else {
			$this->db->query("DELETE FROM " . DB_PREFIX . "block_layouts WHERE layout_id = '" . ( int )$layout_id . "'");
			$this->cache->delete('layout.a.blocks');
			$this->cache->delete('layout.blocks');
		}
		return true;
	}

	/**
	 * @param $data array
	 * @param int $layout_id
	 * @return int
	 */
	public function saveLayout($data, $layout_id = 0) {
		if (!$layout_id) {
			$this->db->query("INSERT INTO " . DB_PREFIX . "layouts (template_id,store_id,layout_name,layout_type,created,updated)
								VALUES ('" . $this->db->escape($data ['template_id']) . "',
										'" . ( int )$data ['store_id'] . "',
										'" . $this->db->escape($data ['layout_name']) . "',
										'" . ( int )$data ['layout_type'] . "',
										NOW(),
										NOW())");
			$layout_id = $this->db->getLastId();
		} else {
			$this->db->query("UPDATE " . DB_PREFIX . "layouts
								SET template_id = '" . ( int )$data ['template_id'] . "',
									store_id = '" . ( int )$data ['store_id'] . "',
									layout_name = '" . $this->db->escape($data ['layout_name']) . "',
									layout_type = '" . ( int )$data ['layout_type'] . "',
									updated = NOW()
								WHERE layout_id = '" . ( int )$layout_id . "'");
		}

		$this->cache->delete('layout.a.default');
		$this->cache->delete('layout.a.layouts');
		$this->cache->delete('layout.default');
		$this->cache->delete('layout.layouts');
		$this->cache->delete('layout.a.block.descriptions');

		return $layout_id;
	}

	/**
	 * @param $block_id int
	 * @return array
	 */
	public function getBlockInfo($block_id) {
		$block_id = (int)$block_id;
		if (!$block_id) return array();
		
		//Note: Cannot restrict select block based on page_id and layout_id. Some pages, might use default layout and have no pages_layouts entry
		// Use OR to select all options and order by layout_id
		$where = '';	
		$sql = "SELECT DISTINCT b.block_id as block_id,
				b.block_txt_id as block_txt_id,
				b.controller as controller,
				(SELECT group_concat(template separator ',')
					 FROM " . DB_PREFIX . "block_templates
				 WHERE block_id='" . $block_id . "') as templates,
				b.created as block_date_added,
				l.layout_id,
				l.layout_name,
				l.store_id,
				l.template_id,
				pl.page_id
			FROM " . DB_PREFIX . "blocks as b
			LEFT JOIN " . DB_PREFIX . "block_layouts bl ON bl.block_id=b.block_id
			LEFT JOIN " . DB_PREFIX . "layouts l ON l.layout_id = bl.layout_id
			LEFT JOIN " . DB_PREFIX . "pages_layouts pl ON pl.layout_id = l.layout_id
			WHERE b.block_id='" . $block_id . "' " . $where . " 
			ORDER BY bl.layout_id ASC";

		$result = $this->db->query($sql);
		return $result->rows;
	}

	/**
	 * @param $block_id
	 * @return array
	 */
	public function getBlockTemplates($block_id) {
		$block_id = (int)$block_id;
		if (!$block_id) return array();

		$sql = "SELECT template
				FROM " . DB_PREFIX . "block_templates
				WHERE block_id='" . $block_id . "'";
		$result = $this->db->query($sql);
		return $result->rows;
	}

	/**
	 * @param $data array
	 * @param int $page_id
	 * @return int
	 */
	public function savePage($data, $page_id = 0) {
		if (!$page_id) {
			$this->db->query("INSERT INTO " . DB_PREFIX . "pages (   parent_page_id,
																	controller,
																	key_param,
																	key_value,
																	created,
																	updated)
								VALUES ('" . ( int )$data ['parent_page_id'] . "',
										'" . $this->db->escape($data ['controller']) . "',
										'" . $this->db->escape($data ['key_param']) . "',
										'" . $this->db->escape($data ['key_value']) . "',
										NOW(),
										NOW())");

			$page_id = $this->db->getLastId();


		} else {
			$this->db->query("UPDATE " . DB_PREFIX . "pages
								SET parent_page_id = '" . ( int )$data ['parent_page_id'] . "',
									controller = '" . $this->db->escape($data ['controller']) . "',
									key_param = '" . $this->db->escape($data ['key_param']) . "',
									key_param = '" . $this->db->escape($data ['key_value']) . "',
									updated = NOW()
								WHERE page_id = '" . ( int )$page_id . "'");

			// clear all page descriptions before write
			$this->db->query("DELETE FROM " . DB_PREFIX . "page_descriptions WHERE page_id = '" . ( int )$page_id . "'");

		}

		// page description
		if ($data ['page_descriptions']) {
			foreach ($data ['page_descriptions'] as $language_id => $description) {
				if (!has_value($language_id)) {
					continue;
				}

				$this->language->replaceDescriptions('page_descriptions',
					array('page_id' => (int)$page_id),
					array((int)$language_id => array(
						'name' => $description['name'],
						'title' => $description['title'],
						'seo_url' => $description['seo_url'],
						'keywords' => $description['keywords'],
						'description' => $description['description'],
						'content' => $description['content'],
					)));
			}
		}


		$this->cache->delete('layout.a.pages');
		$this->cache->delete('layout.pages');

		return $page_id;
	}

	/**
	 * @param array $data
	 * @param int $block_id
	 * @return int
	 */
	public function saveBlock($data, $block_id = 0) {
		//
		if (!(int)$block_id) {
			$block = $this->getBlockByTxtId($data ['block_txt_id']);
			$block_id = $block['block_id'];

		}

		if (!$block_id) {
			$this->db->query("INSERT INTO " . DB_PREFIX . "blocks (block_txt_id,
																	 controller,
																	 created,
																	 updated)
								VALUES ('" . $this->db->escape($data ['block_txt_id']) . "',
										'" . $this->db->escape($data ['controller']) . "',
										NOW(),
										NOW())");

			$block_id = $this->db->getLastId();

			if (isset ($data ['templates'])) {
				foreach ($data ['templates'] as $tmpl) {

					if (!isset($tmpl ['parent_block_id']) && $tmpl ['parent_block_txt_id']) {
						$parent = $this->getBlockByTxtId($tmpl ['parent_block_txt_id']);
						$tmpl['parent_block_id'] = $parent['block_id'];
					}

					$this->db->query("INSERT INTO " . DB_PREFIX . "block_templates (block_id,parent_block_id,template,created,updated)
										VALUES ('" . ( int )$block_id . "',
												'" . ( int )$tmpl ['parent_block_id'] . "',
												'" . $this->db->escape($tmpl ['template']) . "',
												NOW(),
												NOW())");
				}
			}
		} else {
			if ($data ['controller']) {
				$this->db->query("UPDATE " . DB_PREFIX . "blocks
										SET block_txt_id = '" . $this->db->escape($data ['block_txt_id']) . "',
											controller = '" . $this->db->escape($data ['controller']) . "',
											updated = NOW()
										WHERE block_id = '" . ( int )$block_id . "'");
			}


			if (isset ($data ['templates'])) {
				$this->deleteBlockTemplates($block_id);
				foreach ($data ['templates'] as $tmpl) {

					if (!isset($tmpl ['parent_block_id']) && $tmpl ['parent_block_txt_id']) {
						$parent = $this->getBlockByTxtId($tmpl ['parent_block_txt_id']);
						$tmpl['parent_block_id'] = $parent['block_id'];
					}
					$this->db->query("INSERT INTO " . DB_PREFIX . "block_templates (block_id,
																					  parent_block_id,
																					  template,
																					  created,
																					  updated)
										VALUES ('" . ( int )$block_id . "',
												'" . ( int )$tmpl ['parent_block_id'] . "',
												'" . $this->db->escape($tmpl ['template']) . "',
												NOW(),
												NOW())");
				}
			}
		}
		// save block descriptions by pass
		$data['block_descriptions'] = !isset($data['block_descriptions']) && $data['block_description'] ? array($data['block_description']) : $data['block_descriptions'];
		if ($data['block_descriptions']) {
			foreach ($data['block_descriptions'] as $block_description) {
				if (!isset($block_description ['language_id']) && $block_description ['language_name']) {
					$block_description ['language_id'] = $this->_getLanguageIdByName($block_description ['language_name']);
				}
				if (!$block_description ['language_id']) {
					continue;
				}
				$this->saveBlockDescription($block_id, $block_description['block_description_id'], $block_description);
			}
		}

		$this->cache->delete('layout.a.blocks');
		$this->cache->delete('layout.blocks');

		return $block_id;
	}

	/**
	 * @param int $block_id
	 * @param int $custom_block_id
	 * @param array $description
	 * @return bool|int
	 */
	public function saveBlockDescription($block_id = 0, $custom_block_id = 0, $description = array()) {
		$block_id = (int)$block_id;
		$custom_block_id = (int)$custom_block_id;
		if (!$description['language_id']) {
			$this->errors = 'Error: Can\'t save custom block description, because language_id is empty!';
			$this->log->write($this->errors);
			return false;
		}
		// if id is set - update only given data
		if ($custom_block_id) {
			$update = array();
			if (isset($description ['name'])) {
				$update["name"] = $description ['name'];
			}
			if (isset($description ['block_wrapper'])) {
				$update["block_wrapper"] = $description ['block_wrapper'];
			}
			if (isset($description ['block_framed'])) {
				$update["block_framed"] = (int)$description ['block_framed'];
			}
			if (isset($description ['title'])) {
				$update["title"] = $description ['title'];
			}
			if (isset($description ['description'])) {
				$update["description"] = $description ['description'];
			}
			if (isset($description ['content'])) {
				$update["content"] = $description ['content'];
			}

			if ($update) {
				$this->language->replaceDescriptions('block_descriptions',
					array('custom_block_id' => (int)$custom_block_id),
					array((int)$description['language_id'] => $update));
			}

			$this->cache->delete('layout.a.block.descriptions.' . $custom_block_id);
			$this->cache->delete('layout.a.blocks');
			$this->cache->delete('layout.blocks');
			return $custom_block_id;
		} else {
			if (!$block_id) {
				$this->errors = 'Error: Can\'t save custom block, because block_id is empty!';
				return false;
			}
			$this->db->query("INSERT INTO " . DB_PREFIX . "custom_blocks (block_id, created) VALUES ( '" . $block_id . "', NOW())");
			$custom_block_id = $this->db->getLastId();

			$this->language->replaceDescriptions('block_descriptions',
				array('custom_block_id' => (int)$custom_block_id),
				array((int)$description['language_id'] => array(
					"block_wrapper" => $description ['block_wrapper'],
					'block_framed' => (int)$description ['block_framed'],
					'name' => $description ['name'],
					'title' => $description ['title'],
					'description' => $description ['description'],
					'content' => $description ['content']
				)));

			$this->cache->delete('layout.a.block.descriptions.' . $custom_block_id);
			$this->cache->delete('layout.a.blocks');
			$this->cache->delete('layout.blocks');
			return $custom_block_id;
		}

	}

	/**
	 * @param int $custom_block_id
	 * @return array
	 */
	public function getBlockDescriptions($custom_block_id = 0) {
		if (!(int)$custom_block_id) {
			return array();
		}
		$cache_name = 'layout.a.block.descriptions.' . $custom_block_id;
		$output = $this->cache->get($cache_name);
		if (!is_null($output)) {
			return $output;
		}

		$output = array();
		$sql = "SELECT bd.*, COALESCE(bl.status,0) as status
				FROM " . DB_PREFIX . "block_descriptions bd
				LEFT JOIN " . DB_PREFIX . "block_layouts bl ON bl.custom_block_id = bd.custom_block_id
				WHERE bd.custom_block_id = '" . ( int )$custom_block_id . "'";
		$result = $this->db->query($sql);
		if ($result->num_rows) {
			foreach ($result->rows as $row) {
				$output[$row['language_id']] = $row;
			}
		}
		$this->cache->set($cache_name, $output);
		return $output;
	}

	/**
	 * @param int $custom_block_id
	 * @param int $language_id
	 * @return string
	 */
	public function getCustomBlockName($custom_block_id, $language_id = 0) {
		if (!(int)$custom_block_id) {
			return '';
		}
		$language_id = (int)$language_id;
		$info = $this->getBlockDescriptions($custom_block_id);
		$block_name = $info[$language_id] ? $info[$language_id]['name'] : '';
		$block_name = !$block_name ? $info[key($info)]['name'] : $block_name;
		return $block_name;
	}

	/**
	 * @param int $custom_block_id
	 * @return bool
	 */
	public function deleteCustomBlock($custom_block_id) {
		if (!(int)$custom_block_id) {
			return false;
		}
		//check for link with layouts
		$usage = $this->db->query("SELECT *
									 FROM " . DB_PREFIX . "block_layouts
									 WHERE custom_block_id = '" . ( int )$custom_block_id . "'");
		if ($usage->num_rows) {
			return false;
		}

		$this->db->query("DELETE FROM " . DB_PREFIX . "block_descriptions
								WHERE custom_block_id = '" . ( int )$custom_block_id . "'");
		$this->db->query("DELETE FROM " . DB_PREFIX . "custom_blocks
								WHERE custom_block_id = '" . ( int )$custom_block_id . "'");

		$this->cache->delete('layout.a.blocks');
		$this->cache->delete('layout.blocks');
		$this->cache->delete('layout.a.block.descriptions.' . $custom_block_id);
		return true;
	}

	/**
	 * @param int $block_id
	 * @param int $parent_block_id
	 * @return bool
	 * @throws AException
	 */
	public function deleteBlockTemplates($block_id = 0, $parent_block_id = 0) {
		if (!$block_id) {
			throw new AException (AC_ERR_LOAD, 'Error: Cannot to delete block template, block_id "' . $block_id . '" doesn\'t exists.');
		} else {
			$sql = "DELETE FROM " . DB_PREFIX . "block_templates WHERE block_id = '" . ( int )$block_id . "'";
			if ($parent_block_id) {
				$sql .= " AND parent_block_id = '" . ( int )$parent_block_id . "'";
			}
			$this->db->query($sql);
			$this->cache->delete('layout.a.blocks');
			$this->cache->delete('layout.blocks');
		}
		return true;
	}

	/**
	 * @param string $block_txt_id
	 * @param int $block_id
	 * @return bool
	 */
	public function deleteBlock($block_txt_id = '', $block_id = 0) {

		$block_id = (int)$block_id;
		if (!$block_id) {
			$block = $this->getBlockByTxtId($block_txt_id);
			$block_id = $block['block_id'];
		}

		if ($block_id) {
			$this->db->query("DELETE FROM " . DB_PREFIX . "block_templates
								WHERE block_id = '" . ( int )$block_id . "'");
			$this->db->query("DELETE FROM " . DB_PREFIX . "custom_blocks
								WHERE block_id = '" . ( int )$block_id . "'");
			$this->db->query("DELETE FROM " . DB_PREFIX . "block_layouts
								WHERE block_id = '" . ( int )$block_id . "'");
			$this->db->query("DELETE FROM " . DB_PREFIX . "blocks
								WHERE block_id = '" . ( int )$block_id . "'");

			$this->cache->delete('layout.a.blocks');
			$this->cache->delete('layout.blocks');
			return true;
		} else {
			return false;
		}
	}

	/**
	 * Clone Template layouts to new template
	 * @param $new_template
	 * @return bool
	 */
	public function cloneTemplateLayouts($new_template) {
		if ( empty($new_template) ) {
			return false;
		}

		$sql = "SELECT * FROM " . DB_PREFIX . "layouts WHERE template_id = '" . $this->tmpl_id . "' ";
		$result = $this->db->query($sql);
		foreach ($result->rows as $layout) {
			//clone layout
			$new_layout = array(
				'template_id' => $new_template,
				'store_id' => $layout['store_id'],
				'layout_name' => $layout['layout_name'],
				'layout_type' => $layout['layout_type'],
			);
			$layout_id = $this->saveLayout($new_layout);

			$sql = "SELECT *
					FROM " . DB_PREFIX . "pages_layouts
					WHERE layout_id = '" . $layout['layout_id'] . "' ";
			$result_pages = $this->db->query($sql);
			foreach ($result_pages->rows as $page) {
				//connect it to page
				$this->db->query("INSERT INTO " . DB_PREFIX . "pages_layouts (layout_id,page_id)
									VALUES ('" . ( int )$layout_id . "','" . ( int )$page['page_id'] . "')");
			}

			//clone blocks
			if ( !$this->cloneLayoutBlocks($layout['layout_id'], $layout_id) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Clone layout blocks to new layout ( update block instances)
	 * @param $source_layout_id $new_layout_id
	 * @param $new_layout_id
	 * @return bool
	 */
	public function cloneLayoutBlocks( $source_layout_id, $new_layout_id ) {
		if ( !has_value($source_layout_id) || !has_value($new_layout_id) ) {
			return false;
		}

		$blocks = $this->_getLayoutBlocks($source_layout_id);
		$instance_map = array();
		// insert top level block first
		foreach ($blocks as $block) {
		    if ($block['parent_instance_id'] == 0) {
		    	$block['layout_id'] = $new_layout_id;
		    	$b_id = $this->saveLayoutBlocks($block);
		    	$instance_map[$block['instance_id']] = $b_id;
		    }
		}
		// insert child blocks
		foreach ($blocks as $block) {
		    if ($block['parent_instance_id'] != 0) {
		    	$block['layout_id'] = $new_layout_id;
		    	$block['parent_instance_id'] = $instance_map[$block['parent_instance_id']];
		    	$this->saveLayoutBlocks($block);
		    }
		}

		return true;
	}


	/**
	 * @return bool
	 */
	public function deleteTemplateLayouts() {
		$sql = "SELECT *
				FROM " . DB_PREFIX . "layouts
				WHERE template_id = '" . $this->tmpl_id . "' ";
		$result = $this->db->query($sql);
		foreach ($result->rows as $layout) {
			$this->db->query("DELETE FROM " . DB_PREFIX . "layouts
								WHERE layout_id = '" . $layout['layout_id'] . "' ");
			$this->db->query("DELETE FROM " . DB_PREFIX . "pages_layouts
								WHERE layout_id = '" . $layout['layout_id'] . "' ");
			$this->db->query("DELETE FROM " . DB_PREFIX . "block_layouts
								WHERE layout_id = '" . $layout['layout_id'] . "' ");
		}
		$this->cache->delete('layout');
		return true;
	}


	/**
	 * loadXML() Load layout from XML file or XML String
	 *
	 * @param array $data
	 * @return bool
	 */
	public function loadXML($data) {
		// Input possible with XML string, File or both.
		// We process both one at a time. XML string processed first		
		if ($data ['xml']) {
			$xml_obj = simplexml_load_string($data ['xml']);
			if (!$xml_obj) {
				$err = "Failed loading XML data string";
				foreach (libxml_get_errors() as $error) {
					$err .= "  " . $error->message;
				}
				$error = new AError ($err);
				$error->toLog()->toDebug();
			} else {
				$this->_processXML($xml_obj);
			}
		}

		if (isset ($data ['file']) && is_file($data ['file'])) {
			$xml_obj = simplexml_load_file($data ['file']);
			if (!$xml_obj) {
				$err = "Failed loading XML file " . $data ['file'];
				foreach (libxml_get_errors() as $error) {
					$err .= "  " . $error->message;
				}
				$error = new AError ($err);
				$error->toLog()->toDebug();
			} else {
				$this->_processXML($xml_obj);
			}
		}
		return true;
	}


	private function _set_current_page ($page_id = '', $layout_id = '') {
		//find page used for this instance. If page_id is not specified for the instance, generic page/layout is used.
		if ( has_value($page_id) && has_value($layout_id)) {
			foreach ($this->pages as $page) {
				if ($page['page_id'] == $page_id && $page['layout_id'] == $layout_id) {
					$this->page = $page;
					break;
				}
			}
		} else if(has_value($page_id)) {
			//we have page not related to any layout yet. need to pull differently
			$language_id = $this->session->data['content_language_id'];
			$sql = " SELECT p.page_id,
							p.controller,
							p.key_param,
							p.key_value,
							p.created,
							p.updated,
							pd.title,
							pd.seo_url,
							pd.keywords,
							pd.description,
							pd.content
					FROM " . DB_PREFIX . "pages p " . "
					LEFT JOIN " . DB_PREFIX . "page_descriptions pd ON (p.page_id = pd.page_id AND pd.language_id = '" . (int)$language_id . "' )
					WHERE p.page_id = '" . $page_id . "'";
			$query = $this->db->query($sql);
			$this->pages[] = $query->row;
			$this->page = $query->row;
		} else {
			//set generic layout
			foreach ($this->pages as $page) {
				if ($page['controller'] == 'generic') {
					$this->page = $page;
					break;
				}
			}
		}	
	}


	/**
	 * @param object $xml_obj
	 * @return bool
	 */
	private function _processXML($xml_obj) {
		$template_layouts = $xml_obj->xpath('/template_layouts/layout');
		if (empty($template_layouts)) {
			return false;
		}
		//process each layout 
		foreach ($template_layouts as $layout) {

			/* Determin an action tag in all patent elements. Action can be insert, update and delete
			   Default action (if not provided) is update
			   ->>> action = insert
					Before loading the layout, determin if same layout exists with same name, template and type comdination.
					If does exists, return and log error
			   ->>> action = update (default)
					Before loading the layout, determin if same layout exists with same name, template and type comdination.
					If does exists, write new settings over existing
			   ->>> action = delete
					Delete the element provided from databse and delete relationships to other elements linked to currnet one

				NOTE: Parent level delete action is cascaded to all childer elements

				TODO: Need to use transaction sql here to prevent partual load or partual delete in case of error
			*/

			//check if layout with same name exists
			$sql = "SELECT layout_id
					FROM " . DB_PREFIX . "layouts
					WHERE layout_name='" . $this->db->escape($layout->name) . "'
						AND template_id='" . $this->db->escape($layout->template_id) . "'";
			$result = $this->db->query($sql);
			$layout_id = $result->row ['layout_id'];

			if (!$layout_id && in_array($layout->action, array("", null, "update"))) {
				$layout->action = 'insert';
			}
			if ($layout_id && $layout->action == 'insert') {
				$layout->action = 'update';
			}
			//layouts
			if ($layout->action == "delete") {

				if ($layout_id) {
					$sql = array();
					$sql[] = "DELETE FROM " . DB_PREFIX . "pages_layouts
							   WHERE layout_id = '" . $layout_id . "'";
					$sql[] = "DELETE FROM " . DB_PREFIX . "block_layouts
							   WHERE  layout_id = '" . $layout_id . "'";
					$sql[] = "DELETE FROM " . DB_PREFIX . "layouts
							   WHERE layout_id= " . $layout_id;
					foreach ($sql as $query) {
						$this->db->query($query);
					}
				}

			} elseif ($layout->action == 'insert') {

				if ($layout_id) {
					$errmessage = 'Error: cannot add new layout (layout name: "' . $layout->name . '") into database because it already exists.';
					$error = new AError ($errmessage);
					$error->toLog()->toDebug();
					$this->errors = 1;
					continue;
				}

				// if store name provided
				if (( string )$layout->store_name) {
					$sql = "SELECT store_id
							FROM " . DB_PREFIX . "stores
							WHERE store_name='" . $this->db->escape($layout->store_name) . "'";
					$result = $this->db->query($sql);
					$store_id = $result->row ['store_id'];

					if (!$store_id) {
						$this->error [] = 'Can\'t find store with name "' . $layout->store_name . '" for layout "' . $layout->name . '". Use default store.';
					}
				} else {
					$store_id = 0;
				}

				// check layout type
				$layout_type = $this->_getIntLayoutTypeByText((string)$layout->type);
				$sql = "INSERT INTO " . DB_PREFIX . "layouts (template_id,store_id,layout_name,layout_type,created,updated)
						VALUES ('" . $this->db->escape($layout->template_id) . "',
								'" . ( int )$store_id . "',
								'" . $this->db->escape($layout->name) . "',
								'" . $layout_type . "',NOW(),NOW())";
				$this->db->query($sql);
				$layout_id = $this->db->getLastId();

				// write pages section
				if ($layout->pages->page) {
					foreach ($layout->pages->page as $page) {
						$this->_processPage($layout_id, $page);
					}
				}

			} else { // layout update
				if (!$layout_id) {
					$errmessage = 'Error: cannot update layout (layout name: "' . $layout->name . '") because it not exists.';
					$error = new AError ($errmessage);
					$error->toLog()->toDebug();
					$this->errors = 1;
					continue;
				}

				// if store name provided
				$store_id = '';
				if (( string )$layout->store_name) {
					$sql = "SELECT store_id
							FROM " . DB_PREFIX . "stores
							WHERE store_name='" . $this->db->escape($layout->store_name) . "'";
					$result = $this->db->query($sql);
					$store_id = $result->row ['store_id'];

					if (!$store_id) {
						$this->error [] = 'Can\'t find store with name "' . $layout->store_name . '" for layout "' . $layout->name . '". Use default store.';
					}
				}

				// check layout type
				$layout_type = $this->_getIntLayoutTypeByText((string)$layout->type);

				$sql = "UPDATE " . DB_PREFIX . "layouts SET template_id = '" . $this->db->escape($layout->template_id) . "',
															store_id = '" . ( int )$store_id . "',
															layout_name = '" . $this->db->escape($layout->name) . "',
															layout_type = '" . $layout_type . "',
															created = NOW(),
															updated = NOW() WHERE layout_id='" . $layout_id . "'";
				$this->db->query($sql);

				// write pages section
				if ($layout->pages->page) {
					foreach ($layout->pages->page as $page) {
						$this->_processPage($layout_id, $page);
					}
				}
				//end layout manipulation
			}

			// block manipulation
			foreach ($layout->blocks->block as $block) {
				if (!$block->block_txt_id) {
					$errmessage = 'Error: cannot process block because block_txt_id is empty.';
					$error = new AError ($errmessage);
					$error->toLog()->toDebug();
					$this->errors = 1;
					continue;
				}
				$layout->layout_id = $layout_id;
				//start recursion on all blocks
				$this->_processBlock($layout, $block);
			}

		} //end of layout manipulation
		return true;
	}

	/**
	 * @param int $layout_id
	 * @param object $page
	 * @return bool
	 */
	private function _processPage($layout_id, $page) {

		$sql = "SELECT p.page_id
				FROM " . DB_PREFIX . "pages p
				WHERE controller='" . $this->db->escape($page->controller) . "'
						AND key_param = '" . $this->db->escape($page->key_param) . "'
						AND key_value = '" . $this->db->escape($page->key_value) . "'";

		$result = $this->db->query($sql);
		$page_id = ( int )$result->row ['page_id'];

		if ($page_id) {
			$sql = "SELECT layout_id
					FROM " . DB_PREFIX . "pages_layouts
					WHERE page_id = '" . $page_id . "' AND layout_id= '" . $layout_id . "'";
			$result = $this->db->query($sql);
			if (!( int )$result->row ['layout_id']) {
				$sql = "INSERT INTO " . DB_PREFIX . "pages_layouts (layout_id,page_id) VALUES ('" . ( int )$layout_id . "','" . ( int )$page_id . "')";
				$this->db->query($sql);
			}
		} else { // if page new
			$sql = "INSERT INTO " . DB_PREFIX . "pages (parent_page_id, controller, key_param, key_value, created, updated)
					VALUES ('0',
							'" . $this->db->escape($page->controller) . "',
							'" . $this->db->escape($page->key_param) . "',
							'" . $this->db->escape($page->key_value) . "',NOW(),NOW())";
			$this->db->query($sql);
			$page_id = $this->db->getLastId();
			$sql = "INSERT INTO " . DB_PREFIX . "pages_layouts (layout_id,page_id)
					VALUES ('" . ( int )$layout_id . "','" . ( int )$page_id . "')";
			$this->db->query($sql);
		}

		if ($page->page_descriptions->page_description) {
			foreach ($page->page_descriptions->page_description as $page_description) {
				$page_description->language = mb_strtolower($page_description->language, 'UTF-8');
				$query = "SELECT language_id FROM " . DB_PREFIX . "languages 
											WHERE LOWER(name) = '" . $this->db->escape($page_description->language) . "'";
				$result = $this->db->query($query);
				$language_id = $result->row ? $result->row ['language_id'] : 0;

				$this->language->replaceDescriptions('page_descriptions',
					array('page_id' => (int)$page_id),
					array((int)$language_id => array(
						'name' => $page_description->name,
						'title' => $page_description->title,
						'seo_url' => $page_description->seo_url,
						'keywords' => $page_description->keywords,
						'description' => $page_description->description,
						'content' => $page_description->content
					)));
			}
		}

		return true;
	}

	/**
	 * @param object $layout
	 * @param object $block
	 * @param int $parent_instance_id
	 * @return bool
	 */
	private function _processBlock($layout, $block, $parent_instance_id = 0) {
		$instance_id = null;
		$layout_id = (int)$layout->layout_id;

		if ((string)$block->type) {
			$this->_processCustomBlock($layout_id, $block, $parent_instance_id);
			return true;
		} /**
		 * @deprecated
		 * TODO : need to delete processing of tags <kind> from layout manager in the future
		 */
		elseif ((string)$block->kind == 'custom') {
			$this->_processCustomBlock($layout_id, $block, $parent_instance_id);
			return true;
		}

		//get block_id
		$sql = "SELECT block_id
				FROM " . DB_PREFIX . "blocks
				WHERE block_txt_id = '" . $this->db->escape($block->block_txt_id) . "'";
		$result = $this->db->query($sql);
		$block_id = ( int )$result->row ['block_id'];

		$action = (string)$block->action;
		if (!$block_id && in_array($action, array("", null, "update"))) {
			$action = 'insert';
		}

		if ($action == 'delete') {
			//Delete block and unlink from all layouts					
			$sql = array();
			$sql[] = "DELETE FROM " . DB_PREFIX . "block_layouts
					   WHERE block_id='" . $block_id . "' AND layout_id='" . $layout_id . "'";
			// check if block used by another layouts					
			$query = "SELECT *
					  FROM " . DB_PREFIX . "block_layouts
					  WHERE block_id='" . $block_id . "' AND layout_id<>'" . $layout_id . "'";
			$result = $this->db->query($query);
			if (!$result->row) {
				/*	$sql [] = "DELETE FROM " . DB_PREFIX . "block_descriptions
							   WHERE block_id='" . $block_id . "'";*/
				$sql[] = "DELETE FROM " . DB_PREFIX . "block_templates
						   WHERE block_id='" . $block_id . "'";
				$sql[] = "DELETE FROM " . DB_PREFIX . "blocks
						   WHERE block_id='" . $block_id . "'";
			}
			foreach ($sql as $query) {
				$this->db->query($query);
			}

		} elseif ($action == 'insert') {

			//If exists same block with same block_txt_id, return error and finish					
			if ($block_id) {
				$errmessage = 'Error: cannot insert block (block_txt_id: "' . $block->block_txt_id . '") into database because it already exists.';
				$error = new AError ($errmessage);
				$error->toLog()->toDebug();
				$this->errors = 1;

			}

			// if not exists - insert and get it's block_id
			$sql = "INSERT INTO " . DB_PREFIX . "blocks (block_txt_id, controller,created,updated) 
					VALUES ('" . $this->db->escape($block->block_txt_id) . "', '" . $this->db->escape($block->controller) . "',NOW(),NOW())";
			$this->db->query($sql);
			$block_id = $this->db->getLastId();

			$position = (int)$block->position;
			// if parent block exists
			if ($parent_instance_id && !$position) {
				$sql = "SELECT MAX(position) as maxpos
						FROM " . DB_PREFIX . "block_layouts
						WHERE  parent_instance_id = " . ( int )$parent_instance_id;
				$result = $this->db->query($sql);
				$position = $result->row ['maxpos'] + 10;
			}
			$position = !$position ? 10 : $position;
			$sql = "INSERT INTO " . DB_PREFIX . "block_layouts (layout_id,
																block_id,
																parent_instance_id,
																position,
																status,
																created,
																updated)
					VALUES ('" . ( int )$layout_id . "',
							'" . ( int )$block_id . "',
							'" . ( int )$parent_instance_id . "',
							'" . ( int )$position . "',
							'" . 1 . "',
							NOW(),
							NOW())";
			$this->db->query($sql);
			$instance_id = $this->db->getLastId();

			$sql = array();
			// insert block's info
			if ($block->block_descriptions->block_description) {
				foreach ($block->block_descriptions->block_description as $block_description) {
					$language_id = $this->_getLanguageIdByName(mb_strtolower((string)$block_description->language, 'UTF-8'));
					$this->language->replaceDescriptions('block_descriptions',
						array('instance_id' => (int)$instance_id,
							'block_id' => (int)$block_id),
						array((int)$language_id => array(
							'name' => (string)$block_description->name,
							'title' => (string)$block_description->title,
							'description' => (string)$block_description->description,
							'content' => (string)$block_description->content
						)));

				}
			}
			if ($block->templates->template) {
				foreach ($block->templates->template as $block_template) {
					// parent block_id by parent_name
					$query = "SELECT block_id
							  FROM " . DB_PREFIX . "blocks
							  WHERE block_txt_id = '" . $this->db->escape($block_template->parent_block) . "'";
					$result = $this->db->query($query);
					$parent_block_id = $result->row ['block_id'];

					$sql[] = "INSERT INTO " . DB_PREFIX . "block_templates (block_id,parent_block_id,template,created,updated)
							   VALUES ('" . ( int )$block_id . "',
										'" . ( int )$parent_block_id . "',
										'" . $this->db->escape($block_template->template_name) . "',NOW(),NOW())";
				}
			}

			foreach ($sql as $query) {
				$this->db->query($query);
			}

		} else { // Update or insert 


			if ($block_id) {
				$sql = "UPDATE " . DB_PREFIX . "blocks 
						SET controller = '" . $this->db->escape($block->controller) . "', updated = NOW()
						WHERE block_id='" . $block_id . "'";
				$this->db->query($sql);

				$sql = array();
				// insert block's info
				if ($block->block_descriptions->block_description) {
					foreach ($block->block_descriptions->block_description as $block_description) {
						$language_id = $this->_getLanguageIdByName(mb_strtolower((string)$block_description->language, 'UTF-8'));
						// if language unknown
						if (!$language_id) {
							$error = "ALayout_manager Error. Unknown language for block descriptions.'."
									. "(Block_id=" . $block_id . ", name=" . (string)$block_description->name . ", "
									. "title=" . (string)$block_description->title . ", "
									. "description=" . (string)$block_description->description . ", "
									. "content=" . (string)$block_description->content . ", "
									. ")";
							$this->log->write($error);
							$this->message->saveError('layout import error', $error);
							continue;
						}
						$this->language->replaceDescriptions('block_descriptions',
							array('block_id' => (int)$block_id),
							array((int)$language_id => array(
								'name' => (string)$block_description->name,
								'title' => (string)$block_description->title,
								'description' => (string)$block_description->description,
								'content' => (string)$block_description->content
							)));
					}
				}
				if ($block->templates->template) {
					foreach ($block->templates->template as $block_template) {
						// parent block_id by parent_name
						$query = "SELECT block_id
								  FROM " . DB_PREFIX . "blocks
								  WHERE block_txt_id = '" . $this->db->escape($block_template->parent_block) . "'";
						$result = $this->db->query($query);
						$parent_block_id = $result->row ? $result->row ['block_id'] : 0;

						$query = "SELECT block_id
								  FROM " . DB_PREFIX . "block_templates
								  WHERE block_id = '" . $block_id . "'
									  AND parent_block_id = '" . $parent_block_id . "'";
						$result = $this->db->query($query);
						$exists = $result->row ? $result->row ['block_id'] : 0;
						if (!$parent_block_id) {
							$errmessage = 'Error: block template "' . $block_template->template_name . '" (block_txt_id: "' . $block->block_txt_id . '") have not parent block!';
							$error = new AError ($errmessage);
							$error->toLog()->toDebug();
							$this->errors = 1;
						}

						if ($exists) {
							$sql[] = "UPDATE " . DB_PREFIX . "block_templates
									   SET parent_block_id = '" . ( int )$parent_block_id . "',
										   template = '" . $this->db->escape($block_template->template_name) . "',
										   updated = NOW()
									   WHERE block_id='" . $block_id . "' AND parent_block_id='" . $parent_block_id . "'";
						} else {
							$sql[] = "INSERT INTO " . DB_PREFIX . "block_templates (block_id,parent_block_id,template,created,updated)
										VALUES ('" . ( int )$block_id . "',
												'" . ( int )$parent_block_id . "',
												'" . $this->db->escape($block_template->template_name) . "',NOW(),NOW())";
						}
					}
				}

				foreach ($sql as $query) {
					$this->db->query($query);
				}

				// and finally relate block with layout						
				$query = "SELECT *
							FROM " . DB_PREFIX . "block_layouts
							WHERE layout_id = '" . ( int )$layout_id . "'
									AND block_id = '" . ( int )$block_id . "'
									AND parent_instance_id = '" . ( int )$parent_instance_id . "'";
				$result = $this->db->query($query);
				$exists = $result->row ['instance_id'];

				$status = $block->status ? (int)$block->status : 1;

				if (!$exists && $layout->action != "delete") {
					$position = (int)$block->position;
					// if parent block exists
					if ($parent_instance_id && !$position) {
						$sql = "SELECT MAX(position) as maxpos
								FROM " . DB_PREFIX . "block_layouts
								WHERE  parent_instance_id = " . ( int )$parent_instance_id;
						$result = $this->db->query($sql);
						$position = $result->row ['maxpos'] + 10;
					}
					$position = !$position ? 10 : $position;
					$query = "INSERT INTO " . DB_PREFIX . "block_layouts
									(layout_id,
									block_id,
									parent_instance_id,
									position,
									status,
									created,
									updated)
							  VALUES (  '" . ( int )$layout_id . "',
							  			'" . ( int )$block_id . "',
							  			'" . ( int )$parent_instance_id . "',
									    '" . (int)$position . "',
									    '" . $status . "',
									    NOW(),
									    NOW())";
					$this->db->query($query);
					$instance_id = (int)$this->db->getLastId();
				}
			} // end if block_id
		} // end of update block


		// start recursion for all included blocks
		if ($block->block) {
			foreach ($block->block as $childblock) {
				$this->_processBlock($layout, $childblock, $instance_id);
			}
		}
		return true;
	}

	/**
	 * @param int $layout_id
	 * @param object $block
	 * @param int $parent_instance_id
	 * @return bool
	 */
	private function _processCustomBlock($layout_id, $block, $parent_instance_id = 0) {

		//get block_id of custom block by block type(base block_txt_id)
		$sql = "SELECT block_id
				FROM " . DB_PREFIX . "blocks
				WHERE block_txt_id = '" . $this->db->escape($block->type) . "'";
		$result = $this->db->query($sql);
		$block_id = ( int )$result->row ['block_id'];


		// if base block not found - break processing
		if (!$block_id) {
			$errmessage = 'Error: layout.xml::cannot insert custom block (custom_block_txt_id: "' . $block->custom_block_txt_id . '") into database because it block_id of type "' . $block->type . '" does not exists.';
			$error = new AError ($errmessage);
			$error->toLog()->toDebug();
			$this->errors = 1;
			return false;
		}

		// get custom block by it's name and base block id
		$custom_block_info = $this->getBlocksList(array('subsql_filter' => "bd.name = '" . (string)$block->custom_block_txt_id . "' AND cb.block_id='" . $block_id . "'"));
		$custom_block_id = $custom_block_info[0]['custom_block_id'];

		$action = (string)$block->action;
		$status = (isset($block->status) ? (int)$block->status : 1);

		if (empty($action)) {
			$action = 'insert-update';
		}


		// DELETE BLOCK
		if ($action == 'delete') {
			if (!$custom_block_id) { // if we don't know about this custom block - break;
				return false;
			}
			//Delete block and unlink from all layouts
			$sql = array();
			$sql[] = "DELETE FROM " . DB_PREFIX . "block_layouts
					   WHERE block_id='" . $block_id . "' AND layout_id='" . $layout_id . "' AND custom_block_id='" . $custom_block_id . "'";
			// check if block used by another layouts
			$query = "SELECT *
					  FROM " . DB_PREFIX . "block_layouts
					  WHERE block_id='" . $block_id . "' AND layout_id<>'" . $layout_id . "' AND custom_block_id='" . $custom_block_id . "'";
			$result = $this->db->query($query);
			if (!$result->row) {
				$sql[] = "DELETE FROM " . DB_PREFIX . "block_descriptions
						   WHERE block_id='" . $custom_block_id . "'";
				$sql[] = "DELETE FROM " . DB_PREFIX . "custom_blocks
						   WHERE custom_block_id='" . $custom_block_id . "'";
			}
			foreach ($sql as $query) {
				$this->db->query($query);
			}
		} // insert or update custom block
		else {
			// check is this block was already inserted in previous loop by xml tree
			if (isset($this->custom_blocks[(string)$block->custom_block_txt_id])) {
				$custom_block_id = $this->custom_blocks[(string)$block->custom_block_txt_id];
			} else {
				if (!$custom_block_id) {
					// if block is new
					$sql = "INSERT INTO " . DB_PREFIX . "custom_blocks (block_id, created)
								VALUES ('" . $block_id . "', NOW())";
					$this->db->query($sql);
					$custom_block_id = $this->db->getLastId();
				}
				$this->custom_blocks[(string)$block->custom_block_txt_id] = $custom_block_id;
			}
			// if parent block exists
			if ($parent_instance_id) {
				$parent_inst[0] = $parent_instance_id;
			} else {
				$block_txt_id = $block->installed->placeholder;
				foreach ($block_txt_id as $parent_instance_txt_id) {
					$parent_inst[] = $this->_getInstanceIdByTxtId($layout_id, (string)$parent_instance_txt_id);
				}
			}


			foreach ($parent_inst as $par_inst) {
				$sql = "SELECT MAX(position) as maxpos
						FROM " . DB_PREFIX . "block_layouts
						WHERE  parent_instance_id = " . ( int )$par_inst;
				$result = $this->db->query($sql);
				$position = $result->row ['maxpos'] + 10;
				$sql = "INSERT INTO " . DB_PREFIX . "block_layouts (layout_id,
																	block_id,
																	custom_block_id,
																	parent_instance_id,
																	position,
																	status,
																	created,
																	updated)
						VALUES ('" . ( int )$layout_id . "',
								'" . ( int )$block_id . "',
								'" . (int)$custom_block_id . "',
								'" . ( int )$par_inst . "',
								'" . ( int )$position . "',
								'" . $status . "',
								NOW(),
								NOW())";
				$this->db->query($sql);
			}

			// insert custom block content
			if ($block->block_descriptions->block_description) {
				foreach ($block->block_descriptions->block_description as $block_description) {
					$language_id = $this->_getLanguageIdByName((string)$block_description->language);

					$desc_array = array('language_id' => $language_id);
					if ((string)$block_description->name) {
						$desc_array['name'] = (string)$block_description->name;
					}
					if ((string)$block_description->title) {
						$desc_array['title'] = (string)$block_description->title;
					}
					if (has_value((string)$block_description->block_wrapper)) {
						$desc_array['block_wrapper'] = (string)$block_description->block_wrapper;
					}

					if (has_value((string)$block_description->block_framed)) {
						$desc_array['block_framed'] = (int)$block_description->block_framed;
					}

					if ((string)$block_description->description) {
						$desc_array['description'] = (string)$block_description->description;
					}
					if ((string)$block_description->content) {
						$desc_array['content'] = (string)$block_description->content;
					}

					$this->saveBlockDescription($block_id, $custom_block_id, $desc_array);
				}
			}

		}

		return true;
	}

	/**
	 * @param string $language_name
	 * @return int
	 */
	private function _getLanguageIdByName($language_name = '') {
		$language_name = mb_strtolower($language_name, 'UTF-8');
		$query = "SELECT language_id
				  FROM " . DB_PREFIX . "languages
				  WHERE LOWER(filename) = '" . $this->db->escape($language_name) . "'";
		$result = $this->db->query($query);
		return $result->row ? $result->row ['language_id'] : 0;
	}

	/**
	 * @param $layout_id
	 * @param $block_txt_id
	 * @return bool
	 */
	private function _getInstanceIdByTxtId($layout_id, $block_txt_id) {

		$layout_id = (int)$layout_id;
		if (!$layout_id || !$block_txt_id) {
			return false;
		}


		$sql = "SELECT instance_id
				FROM " . DB_PREFIX . "block_layouts
				WHERE layout_id = '" . $layout_id . "' AND block_id = ( SELECT block_id
																	FROM " . DB_PREFIX . "blocks
																	WHERE block_txt_id='" . $block_txt_id . "')";
		$result = $this->db->query($sql);
		return $result->row ['instance_id'];
	}

	/*
	 * Function return integer type of layout by given text. Used by xml-import of layouts.
	 *
	 * @param string $text_type
	 * @return int
	 */

	private function _getIntLayoutTypeByText($text_type) {
		$text_type = ucfirst($text_type);
		switch ($text_type) {
			case 'Default':
			case 'General':
				return 0;
				break;
			case 'Active':
				return 1;
				break;
			case 'Draft':
				return 2;
				break;
			case 'Template':
				return 3;
				break;
			default:
				return 1;
		}
	}
}