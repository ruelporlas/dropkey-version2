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
		$current = null;

		if ( ! $creating && isset( $data['id'] ) ) {
			$current = $this->get( $data['id'] );
		}
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

		$billing_type = isset( $allowed['billing_type'] ) ? $allowed['billing_type'] : ( $current ? $current->billing_type : '' );
		$type_changed = $current && isset( $data['billing_type'] ) && $data['billing_type'] !== $current->billing_type;
		$must_set_paid_fields = $creating || ( $type_changed && 'free' === $current->billing_type && 'free' !== $billing_type );

		if ( 'free' === $billing_type ) {
			$allowed['price']    = '0.00';
			$allowed['currency'] = null;
		} elseif ( isset( $data['price'] ) || $must_set_paid_fields ) {
			if ( ! isset( $data['price'] ) || ! is_numeric( $data['price'] ) || (float) $data['price'] < 0 ) {
				return new \WP_Error( 'dk2_invalid_price', __( 'Enter a valid non-negative price.', 'dropkey-v2' ) );
			}
			$allowed['price'] = number_format( (float) $data['price'], 2, '.', '' );
		}
		if ( 'free' !== $billing_type && ( isset( $data['currency'] ) || $must_set_paid_fields ) ) {
			$currency = isset( $data['currency'] ) ? strtoupper( sanitize_text_field( $data['currency'] ) ) : '';
			if ( ! preg_match( '/^[A-Z]{3}$/', $currency ) ) {
				return new \WP_Error( 'dk2_invalid_currency', __( 'Enter a three-letter currency code for paid billing options.', 'dropkey-v2' ) );
			}
			$allowed['currency'] = $currency;
		}
		$plan_configuration_changed = false;
		if ( $current ) {
			$new_billing_type = isset( $allowed['billing_type'] ) ? $allowed['billing_type'] : $current->billing_type;
			$new_price        = isset( $allowed['price'] ) ? (float) $allowed['price'] : (float) $current->price;
			$new_currency     = array_key_exists( 'currency', $allowed ) ? (string) $allowed['currency'] : (string) $current->currency;
			$plan_configuration_changed = $new_billing_type !== $current->billing_type
				|| abs( $new_price - (float) $current->price ) > 0.00001
				|| strtoupper( $new_currency ) !== strtoupper( (string) $current->currency );
		}
		if ( $plan_configuration_changed ) {
			// A plan's amount or interval cannot be changed by editing the local option.
			// Clear its reference so PayPal provisions a matching plan at the next checkout.
			$allowed['paypal_plan_id'] = '';
		} elseif ( isset( $data['paypal_plan_id'] ) ) {
			$allowed['paypal_plan_id'] = sanitize_text_field( $data['paypal_plan_id'] );
		} elseif ( $creating ) {
			$allowed['paypal_plan_id'] = '';
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
