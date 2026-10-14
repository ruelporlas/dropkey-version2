<?php
/**
 * Versioned schema and table-name helpers.
 * @package DropKeyV2
 */
namespace DropKey\V2;
defined( 'ABSPATH' ) || exit;
final class Database {
	const VERSION_OPTION = 'dk2_db_version';
	const SCHEMA_VERSION = '3';
	public static function table( $table ) {
		global $wpdb;
		$allowed = array( 'products', 'tiers', 'billing_options', 'releases', 'licenses', 'activations', 'download_tokens' );
		return in_array( $table, $allowed, true ) ? $wpdb->prefix . 'dk2_' . $table : '';
	}
	public static function install() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$c = $wpdb->get_charset_collate();
		$p=self::table('products'); $t=self::table('tiers'); $b=self::table('billing_options');
		$r=self::table('releases'); $l=self::table('licenses'); $a=self::table('activations'); $d=self::table('download_tokens');
		dbDelta("CREATE TABLE {$p} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			name varchar(191) NOT NULL, slug varchar(191) NOT NULL, description longtext NOT NULL,
			status varchar(20) NOT NULL DEFAULT 'draft', created_at datetime NOT NULL, updated_at datetime NOT NULL,
			PRIMARY KEY  (id), UNIQUE KEY slug (slug), KEY status (status)
		) {$c};");
		dbDelta("CREATE TABLE {$t} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT, product_id bigint(20) unsigned NOT NULL,
			name varchar(191) NOT NULL, slug varchar(150) NOT NULL, description longtext NOT NULL,
			activation_limit smallint(5) unsigned NOT NULL, status varchar(20) NOT NULL DEFAULT 'draft',
			sort_order int(11) NOT NULL DEFAULT 0, created_at datetime NOT NULL, updated_at datetime NOT NULL,
			PRIMARY KEY  (id), UNIQUE KEY product_slug (product_id,slug), KEY product_id (product_id), KEY status (status)
		) {$c};");
		dbDelta("CREATE TABLE {$b} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT, tier_id bigint(20) unsigned NOT NULL,
			billing_type varchar(20) NOT NULL, price decimal(12,2) unsigned NOT NULL DEFAULT 0.00,
			currency char(3) DEFAULT NULL, status varchar(20) NOT NULL DEFAULT 'draft',
			created_at datetime NOT NULL, updated_at datetime NOT NULL,
			PRIMARY KEY  (id), KEY tier_id (tier_id), KEY tier_billing (tier_id,billing_type), KEY status (status)
		) {$c};");
		dbDelta("CREATE TABLE {$r} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT, product_id bigint(20) unsigned NOT NULL,
			version varchar(50) NOT NULL, package_path text NOT NULL, package_sha256 char(64) NOT NULL,
			package_size bigint(20) unsigned NOT NULL DEFAULT 0, release_notes longtext NOT NULL,
			status varchar(20) NOT NULL DEFAULT 'draft', created_at datetime NOT NULL, updated_at datetime NOT NULL,
			PRIMARY KEY  (id), UNIQUE KEY product_version (product_id,version), KEY product_id (product_id), KEY status (status)
		) {$c};");
		dbDelta("CREATE TABLE {$l} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT, product_id bigint(20) unsigned NOT NULL,
			tier_id bigint(20) unsigned NOT NULL, billing_option_id bigint(20) unsigned DEFAULT NULL,
			customer_email varchar(191) NOT NULL, license_hash char(64) NOT NULL, key_prefix varchar(24) NOT NULL,
			status varchar(20) NOT NULL DEFAULT 'active', expires_at datetime DEFAULT NULL,
			created_at datetime NOT NULL, updated_at datetime NOT NULL,
			PRIMARY KEY  (id), UNIQUE KEY license_hash (license_hash), KEY product_id (product_id),
			KEY tier_id (tier_id), KEY customer_email (customer_email), KEY status (status)
		) {$c};");
		dbDelta("CREATE TABLE {$a} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT, license_id bigint(20) unsigned NOT NULL,
			site_url varchar(500) NOT NULL, status varchar(20) NOT NULL DEFAULT 'active',
			activated_at datetime NOT NULL, deactivated_at datetime DEFAULT NULL, last_seen_at datetime DEFAULT NULL,
			PRIMARY KEY  (id), UNIQUE KEY license_site (license_id,site_url(191)), KEY license_id (license_id), KEY status (status)
		) {$c};");
		dbDelta("CREATE TABLE {$d} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT, license_id bigint(20) unsigned NOT NULL,
			release_id bigint(20) unsigned NOT NULL, token_hash char(64) NOT NULL, expires_at datetime NOT NULL,
			max_downloads smallint(5) unsigned NOT NULL DEFAULT 5, download_count smallint(5) unsigned NOT NULL DEFAULT 0,
			created_at datetime NOT NULL, PRIMARY KEY  (id), UNIQUE KEY token_hash (token_hash),
			KEY license_id (license_id), KEY release_id (release_id), KEY expires_at (expires_at)
		) {$c};");
		update_option(self::VERSION_OPTION,self::SCHEMA_VERSION,false);
		do_action('dk2_database_installed',self::SCHEMA_VERSION);
	}
}