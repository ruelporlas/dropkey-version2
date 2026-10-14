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
		$email_service=new Email_Service();
		$email_service->register_hooks();
		add_action('dk2_process_subscription_lifecycle',array(new Commerce_Service(),'process_subscription_lifecycle'));
		add_action('init',array($this,'schedule_maintenance'));
		if (is_admin()) {
			add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_assets'));
			$this->admin=new Admin();
			add_action('admin_menu',array($this->admin,'register_menu'));
			add_action('admin_init',array($this->admin,'register_handlers'));
		}
	}
	public function schedule_maintenance() {
		if (!wp_next_scheduled('dk2_process_subscription_lifecycle')) wp_schedule_event(time()+300,'daily','dk2_process_subscription_lifecycle');
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
	public function enqueue_admin_assets($hook) {
		if (false === strpos((string) $hook, 'dk2-')) return;
		wp_enqueue_style('dropkey-v2-admin', DK2_URL . 'assets/css/admin.css', array(), DK2_VERSION);
	}
}