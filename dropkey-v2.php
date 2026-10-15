<?php
/**
 * Plugin Name: DropKey Version 2
 * Description: Digital product administration, licensing, private ZIP downloads, and versioned licensing API.
 * Version: 0.3.2
 * Requires at least: 6.4
 * Requires PHP: 7.4
 * Text Domain: dropkey-v2
 * Domain Path: /languages
 * @package DropKeyV2
 */
defined('ABSPATH') || exit;
define('DK2_VERSION','0.3.1');
define('DK2_FILE',__FILE__);
define('DK2_PATH',plugin_dir_path(__FILE__));
define('DK2_URL',plugin_dir_url(__FILE__));
require_once DK2_PATH.'includes/class-database.php';
require_once DK2_PATH.'includes/abstract-repository.php';
require_once DK2_PATH.'includes/class-product-repository.php';
require_once DK2_PATH.'includes/class-tier-repository.php';
require_once DK2_PATH.'includes/class-billing-option-repository.php';
require_once DK2_PATH.'includes/class-private-storage.php';
require_once DK2_PATH.'includes/class-license-service.php';
require_once DK2_PATH.'includes/class-download-service.php';
require_once DK2_PATH.'includes/class-payment-gateway.php';
require_once DK2_PATH.'includes/class-stripe-gateway.php';
require_once DK2_PATH.'includes/class-paypal-gateway.php';
require_once DK2_PATH.'includes/class-email-service.php';
require_once DK2_PATH.'includes/class-commerce-service.php';
require_once DK2_PATH.'includes/class-storefront.php';
require_once DK2_PATH.'includes/class-customer-account.php';
require_once DK2_PATH.'includes/class-rest-api.php';
require_once DK2_PATH.'includes/class-admin.php';
require_once DK2_PATH.'includes/class-plugin.php';
register_activation_hook(__FILE__,array('DropKey\\V2\\Database','install'));
\DropKey\V2\Plugin::instance();
