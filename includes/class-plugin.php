<?php
/**
 * Main plugin bootstrap.
 * @package DropKeyV2
 */
namespace DropKey\V2;
defined('ABSPATH') || exit;
final class Plugin {
	private static $instance=null;
	private $admin=null;
	public static function instance() {
		if (null===self::$instance) self::$instance=new self();
		return self::$instance;
	}
	private function __construct() {
		add_action('plugins_loaded',array($this,'maybe_upgrade_database'));
		add_action('init',array($this,'load_textdomain'));
		add_action('rest_api_init',array($this,'register_rest_api'));
		if (is_admin()) {
			$this->admin=new Admin();
			add_action('admin_menu',array($this->admin,'register_menu'));
			add_action('admin_init',array($this->admin,'register_handlers'));
		}
	}
	public function load_textdomain() {
		load_plugin_textdomain('dropkey-v2',false,dirname(plugin_basename(DK2_FILE)).'/languages');
	}
	public function maybe_upgrade_database() {
		if (Database::SCHEMA_VERSION !== get_option(Database::VERSION_OPTION,'')) Database::install();
	}
	public function register_rest_api() {
		$api=new REST_API();
		$api->register_routes();
	}
}