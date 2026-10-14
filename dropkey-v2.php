<?php
/**
 * Plugin Name: DropKey Version 2
 * Description: A WordPress-native foundation for digital products, tiers, billing options, and licenses.
 * Version: 0.1.0
 * Requires at least: 6.4
 * Requires PHP: 7.4
 * Text Domain: dropkey-v2
 * Domain Path: /languages
 *
 * @package DropKeyV2
 */
defined( 'ABSPATH' ) || exit;
define( 'DK2_VERSION', '0.1.0' );
define( 'DK2_FILE', __FILE__ );
define( 'DK2_PATH', plugin_dir_path( __FILE__ ) );
define( 'DK2_URL', plugin_dir_url( __FILE__ ) );
require_once DK2_PATH . 'includes/class-database.php';
require_once DK2_PATH . 'includes/abstract-repository.php';
require_once DK2_PATH . 'includes/class-product-repository.php';
require_once DK2_PATH . 'includes/class-tier-repository.php';
require_once DK2_PATH . 'includes/class-billing-option-repository.php';
require_once DK2_PATH . 'includes/class-plugin.php';
register_activation_hook( __FILE__, array( '\DropKey\V2\Database', 'install' ) );
\DropKey\V2\Plugin::instance();
