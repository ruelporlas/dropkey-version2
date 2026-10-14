<?php
/**
 * Tier billing-option data access.
 *
 * @package DropKeyV2
 */
namespace DropKey\V2;
defined( 'ABSPATH' ) || exit;
final class Billing_Option_Repository extends Abstract_Repository {
	public function __construct() {
		parent::__construct( 'billing_options' );
	}

	public function for_tier( $tier_id ) {
		global $wpdb;
		$tier_id = absint( $tier_id );
		if ( ! $tier_id ) {
			return array();
		}
		$query = $wpdb->prepare( "SELECT * FROM {$this->table} WHERE tier_id = %d ORDER BY id ASC", $tier_id );
		return $wpdb->get_results( $query );
	}

	protected function normalize( array $data, $creating ) {
		global $wpdb;
		$allowed = array();
		$types   = array( 'free', 'one_time', 'monthly', 'yearly' );

		if ( $creating ) {
			$tier_id = isset( $data['tier_id'] ) ? absint( $data['tier_id'] ) : 0;
			$tiers   = Database::table( 'tiers' );
			$exists  = $tier_id ? $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$tiers} WHERE id = %d", $tier_id ) ) : 0;
			if ( ! $exists ) {
				return new \WP_Error( 'dk2_billing_tier_required', __( 'Choose an existing tier for this billing option.', 'dropkey-v2' ) );
			}
			$allowed['tier_id'] = $tier_id;
			if ( ! isset( $data['billing_type'] ) || ! in_array( $data['billing_type'], $types, true ) ) {
				return new \WP_Error( 'dk2_invalid_billing_type', __( 'Choose free, one-time, monthly, or yearly billing.', 'dropkey-v2' ) );
			}
			$allowed['billing_type'] = $data['billing_type'];
		}
		if ( isset( $data['billing_type'] ) ) {
			if ( ! in_array( $data['billing_type'], $types, true ) ) {
				return new \WP_Error( 'dk2_invalid_billing_type', __( 'Choose free, one-time, monthly, or yearly billing.', 'dropkey-v2' ) );
			}
			$allowed['billing_type'] = $data['billing_type'];
		}
		$billing_type = isset( $allowed['billing_type'] ) ? $allowed['billing_type'] : '';
		if ( 'free' === $billing_type ) {
			$allowed['price']    = '0.00';
			$allowed['currency'] = null;
		} elseif ( isset( $data['price'] ) || $creating ) {
			if ( ! isset( $data['price'] ) || ! is_numeric( $data['price'] ) || (float) $data['price'] < 0 ) {
				return new \WP_Error( 'dk2_invalid_price', __( 'Enter a valid non-negative price.', 'dropkey-v2' ) );
			}
			$allowed['price'] = number_format( (float) $data['price'], 2, '.', '' );
		}
		if ( 'free' !== $billing_type && ( isset( $data['currency'] ) || $creating ) ) {
			$currency = isset( $data['currency'] ) ? strtoupper( sanitize_text_field( $data['currency'] ) ) : '';
			if ( ! preg_match( '/^[A-Z]{3}$/', $currency ) ) {
				return new \WP_Error( 'dk2_invalid_currency', __( 'Enter a three-letter currency code for paid billing options.', 'dropkey-v2' ) );
			}
			$allowed['currency'] = $currency;
		}
		if ( isset( $data['status'] ) || $creating ) {
			$status = isset( $data['status'] ) ? sanitize_key( $data['status'] ) : 'draft';
			if ( ! in_array( $status, array( 'draft', 'published', 'archived' ), true ) ) {
				return new \WP_Error( 'dk2_invalid_billing_status', __( 'Choose a valid billing-option status.', 'dropkey-v2' ) );
			}
			$allowed['status'] = $status;
		}
		return $allowed;
	}
}
