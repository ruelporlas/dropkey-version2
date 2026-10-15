<?php
/**
 * Versioned schema and table-name helpers.
 * @package DropKeyV2
 */
namespace DropKey\V2;
defined( 'ABSPATH' ) || exit;
final class Database {
	const VERSION_OPTION = 'dk2_db_version';
	const SCHEMA_VERSION = '12';
	public static function table( $table ) {
		global $wpdb;
		$allowed = array( 'products', 'tiers', 'billing_options', 'releases', 'licenses', 'activations', 'download_tokens', 'orders', 'subscriptions', 'payment_events', 'email_queue', 'payments', 'logs' );
		return in_array( $table, $allowed, true ) ? $wpdb->prefix . 'dk2_' . $table : '';
	}
	public static function install() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$c = $wpdb->get_charset_collate();
		$p=self::table('products'); $t=self::table('tiers'); $b=self::table('billing_options');
		$r=self::table('releases'); $l=self::table('licenses'); $a=self::table('activations'); $d=self::table('download_tokens');
		$o=self::table('orders'); $s=self::table('subscriptions'); $e=self::table('payment_events'); $q=self::table('email_queue'); $m=self::table('payments');
		dbDelta("CREATE TABLE {$p} (
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
		) {$c};");
		dbDelta("CREATE TABLE {$t} (
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
		) {$c};");
		dbDelta("CREATE TABLE {$b} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			tier_id bigint(20) unsigned NOT NULL,
			billing_type varchar(20) NOT NULL,
			price decimal(12,2) unsigned NOT NULL DEFAULT 0.00,
			currency char(3) DEFAULT NULL,
			paypal_plan_id varchar(191) NOT NULL DEFAULT '',
			status varchar(20) NOT NULL DEFAULT 'draft',
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY tier_id (tier_id),
			KEY tier_billing (tier_id,billing_type),
			KEY status (status)
		) {$c};");
		dbDelta("CREATE TABLE {$r} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			product_id bigint(20) unsigned NOT NULL,
			version varchar(50) NOT NULL,
			package_path text NOT NULL,
			package_sha256 char(64) NOT NULL,
			package_size bigint(20) unsigned NOT NULL DEFAULT 0,
			release_notes longtext NOT NULL,
			status varchar(20) NOT NULL DEFAULT 'draft',
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY product_version (product_id,version),
			KEY product_id (product_id),
			KEY status (status)
		) {$c};");
		dbDelta("CREATE TABLE {$l} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			product_id bigint(20) unsigned NOT NULL,
			tier_id bigint(20) unsigned NOT NULL,
			billing_option_id bigint(20) unsigned DEFAULT NULL,
			customer_email varchar(191) NOT NULL,
			customer_user_id bigint(20) unsigned DEFAULT NULL,
			license_hash char(64) NOT NULL,
			key_prefix varchar(24) NOT NULL,
			key_encrypted longtext DEFAULT NULL,
			status varchar(20) NOT NULL DEFAULT 'active',
			expires_at datetime DEFAULT NULL,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY license_hash (license_hash),
			KEY product_id (product_id),
			KEY tier_id (tier_id),
			KEY customer_email (customer_email),
			KEY customer_user_id (customer_user_id),
			KEY status (status)
		) {$c};");
		dbDelta("CREATE TABLE {$a} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			license_id bigint(20) unsigned NOT NULL,
			site_url varchar(500) NOT NULL,
			status varchar(20) NOT NULL DEFAULT 'active',
			activated_at datetime NOT NULL,
			deactivated_at datetime DEFAULT NULL,
			last_seen_at datetime DEFAULT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY license_site (license_id,site_url(191)),
			KEY license_id (license_id),
			KEY status (status)
		) {$c};");
		dbDelta("CREATE TABLE {$d} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			license_id bigint(20) unsigned NOT NULL,
			release_id bigint(20) unsigned NOT NULL,
			token_hash char(64) NOT NULL,
			expires_at datetime NOT NULL,
			max_downloads smallint(5) unsigned NOT NULL DEFAULT 5,
			download_count smallint(5) unsigned NOT NULL DEFAULT 0,
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY token_hash (token_hash),
			KEY license_id (license_id),
			KEY release_id (release_id),
			KEY expires_at (expires_at)
		) {$c};");

		dbDelta("CREATE TABLE {$o} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			public_id char(36) NOT NULL,
			product_id bigint(20) unsigned NOT NULL,
			tier_id bigint(20) unsigned NOT NULL,
			billing_option_id bigint(20) unsigned NOT NULL,
			license_id bigint(20) unsigned DEFAULT NULL,
			parent_order_id bigint(20) unsigned DEFAULT NULL,
			subscription_id bigint(20) unsigned DEFAULT NULL,
			order_type varchar(20) NOT NULL DEFAULT 'initial',
			customer_email varchar(191) NOT NULL,
			customer_user_id bigint(20) unsigned DEFAULT NULL,
			customer_first_name varchar(100) NOT NULL DEFAULT '',
			customer_last_name varchar(100) NOT NULL DEFAULT '',
			customer_phone varchar(40) NOT NULL DEFAULT '',
			billing_address_1 varchar(191) NOT NULL DEFAULT '',
			billing_address_2 varchar(191) NOT NULL DEFAULT '',
			billing_city varchar(100) NOT NULL DEFAULT '',
			billing_state varchar(100) NOT NULL DEFAULT '',
			billing_postcode varchar(30) NOT NULL DEFAULT '',
			billing_country char(2) NOT NULL DEFAULT '',
			gateway varchar(20) NOT NULL,
			gateway_order_id varchar(191) NOT NULL DEFAULT '',
			gateway_session_id varchar(191) NOT NULL DEFAULT '',
			gateway_payment_id varchar(191) NOT NULL DEFAULT '',
			status varchar(20) NOT NULL DEFAULT 'pending',
			amount decimal(12,2) unsigned NOT NULL DEFAULT 0.00,
			currency char(3) NOT NULL,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY public_id (public_id),
			KEY customer_email (customer_email),
			KEY gateway_order_id (gateway,gateway_order_id),
			KEY gateway_session_id (gateway,gateway_session_id),
			KEY gateway_payment_id (gateway,gateway_payment_id),
			KEY parent_order_id (parent_order_id),
			KEY subscription_id (subscription_id),
			KEY order_type (order_type),
			KEY status (status)
		) {$c};");
		dbDelta("CREATE TABLE {$s} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			order_id bigint(20) unsigned NOT NULL,
			license_id bigint(20) unsigned NOT NULL,
			gateway varchar(20) NOT NULL,
			gateway_subscription_id varchar(191) NOT NULL,
			status varchar(20) NOT NULL DEFAULT 'active',
			current_period_end datetime DEFAULT NULL,
			grace_until datetime DEFAULT NULL,
			cancel_at_period_end tinyint(1) NOT NULL DEFAULT 0,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY gateway_subscription (gateway,gateway_subscription_id),
			KEY order_id (order_id),
			KEY license_id (license_id),
			KEY status (status),
			KEY current_period_end (current_period_end)
		) {$c};");
		dbDelta("CREATE TABLE {$e} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			gateway varchar(20) NOT NULL,
			event_id varchar(191) NOT NULL,
			event_type varchar(100) NOT NULL,
			payload_hash char(64) NOT NULL,
			status varchar(20) NOT NULL DEFAULT 'processing',
			error_message text NOT NULL,
			created_at datetime NOT NULL,
			processed_at datetime DEFAULT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY gateway_event (gateway,event_id),
			KEY status (status)
		) {$c};");
		dbDelta("CREATE TABLE {$q} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			order_id bigint(20) unsigned NOT NULL,
			recipient varchar(191) NOT NULL,
			subject varchar(191) NOT NULL,
			encrypted_body longtext NOT NULL,
			status varchar(20) NOT NULL DEFAULT 'pending',
			attempts smallint(5) unsigned NOT NULL DEFAULT 0,
			next_attempt_at datetime DEFAULT NULL,
			last_error text NOT NULL,
			created_at datetime NOT NULL,
			sent_at datetime DEFAULT NULL,
			PRIMARY KEY  (id),
			KEY order_id (order_id),
			KEY status_next (status,next_attempt_at)
		) {$c};");

		dbDelta("CREATE TABLE {$m} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			order_id bigint(20) unsigned NOT NULL,
			gateway varchar(20) NOT NULL,
			gateway_payment_id varchar(191) NOT NULL,
			provider_event_id varchar(191) NOT NULL DEFAULT '',
			amount decimal(12,2) unsigned NOT NULL DEFAULT 0.00,
			currency char(3) NOT NULL,
			status varchar(20) NOT NULL DEFAULT 'paid',
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY gateway_payment (gateway,gateway_payment_id),
			KEY order_id (order_id),
			KEY status (status)
		) {$c};");

		$logs=self::table('logs');
		dbDelta("CREATE TABLE {$logs} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			level varchar(20) NOT NULL DEFAULT 'info',
			source varchar(50) NOT NULL DEFAULT '',
			event varchar(100) NOT NULL DEFAULT '',
			message varchar(500) NOT NULL DEFAULT '',
			context longtext NOT NULL,
			user_id bigint(20) unsigned DEFAULT NULL,
			request_id varchar(40) NOT NULL DEFAULT '',
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY level (level),
			KEY source (source),
			KEY event (event),
			KEY created_at (created_at),
			KEY request_id (request_id)
		) {$c};");
		// Do not mark this migration complete unless the new checkout columns exist.
		$required_order_columns=array('customer_first_name','customer_last_name','customer_phone','billing_address_1','billing_address_2','billing_city','billing_state','billing_postcode','billing_country');
		$existing_order_columns=$wpdb->get_col('SHOW COLUMNS FROM '.esc_sql($o),0);
		$missing_order_columns=array_values(array_diff($required_order_columns,(array)$existing_order_columns));
		if (!empty($missing_order_columns)) {
			$error='DropKey checkout schema migration incomplete; missing order columns: '.implode(',',$missing_order_columns);
			error_log('[DropKey V2] '.$error.'; database error: '.substr((string)$wpdb->last_error,0,300));
			Logger::error('database','checkout_schema_incomplete',$error,array('missing_columns'=>implode(',',$missing_order_columns),'db_error'=>substr((string)$wpdb->last_error,0,300)));
			return;
		}
		update_option(self::VERSION_OPTION,self::SCHEMA_VERSION,false);
		do_action('dk2_database_installed',self::SCHEMA_VERSION);
	}
}