<?php
/**
 * Main plugin bootstrap.
 *
 * @package DropKeyV2
 */
namespace DropKey\V2;
defined( 'ABSPATH' ) || exit;
final class Plugin {
	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'plugins_loaded', array( $this, 'maybe_upgrade_database' ) );
		add_action( 'init', array( $this, 'load_textdomain' ) );
	}

	public function load_textdomain() {
		load_plugin_textdomain( 'dropkey-v2', false, dirname( plugin_basename( DK2_FILE ) ) . '/languages' );
	}

	public function maybe_upgrade_database() {
		if ( DK2_VERSION !== get_option( Database::VERSION_OPTION, '' ) ) {
			Database::install();
		}
	}
}
