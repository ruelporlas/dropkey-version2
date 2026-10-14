<?php
/**
 * Database schema and table-name helpers.
 *
 * @package DropKeyV2
 */
namespace DropKey\V2;
defined( 'ABSPATH' ) || exit;
final class Database {
	const VERSION_OPTION = 'dk2_db_version';

	public static function table( $table ) {
		global $wpdb;
		$allowed = array( 'products', 'tiers', 'billing_options' );
		if ( ! in_array( $table, $allowed, true ) ) {
			return '';
		}
		return $wpdb->prefix . 'dk2_' . $table;
	}

	public static function install() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$charset_collate = $wpdb->get_charset_collate();
		$products        = self::table( 'products' );
		$tiers           = self::table( 'tiers' );
		$billing_options = self::table( 'billing_options' );

		$sql_products = "CREATE TABLE {$products} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			name varchar(191) NOT NULL,
			slug varchar(191) NOT NULL,
			description longtext NOT NULL,
			status varchar(20) NOT NULL DEFAULT 'draft',
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY slug (slug),
			KEY status (status)
		) {$charset_collate};";

		$sql_tiers = "CREATE TABLE {$tiers} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			product_id bigint(20) unsigned NOT NULL,
			name varchar(191) NOT NULL,
			slug varchar(150) NOT NULL,
			description longtext NOT NULL,
			activation_limit smallint(5) unsigned NOT NULL,
			status varchar(20) NOT NULL DEFAULT 'draft',
			sort_order int(11) NOT NULL DEFAULT 0,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY product_slug (product_id,slug),
			KEY product_id (product_id),
			KEY status (status)
		) {$charset_collate};";

		$sql_billing_options = "CREATE TABLE {$billing_options} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			tier_id bigint(20) unsigned NOT NULL,
			billing_type varchar(20) NOT NULL,
			price decimal(12,2) unsigned NOT NULL DEFAULT 0.00,
			currency char(3) DEFAULT NULL,
			status varchar(20) NOT NULL DEFAULT 'draft',
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY tier_id (tier_id),
			KEY tier_billing (tier_id,billing_type),
			KEY status (status)
		) {$charset_collate};";

		dbDelta( $sql_products );
		dbDelta( $sql_tiers );
		dbDelta( $sql_billing_options );
		update_option( self::VERSION_OPTION, DK2_VERSION, false );
	}
}
