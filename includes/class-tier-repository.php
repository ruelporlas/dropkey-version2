<?php
/**
 * Product tier data access.
 *
 * @package DropKeyV2
 */
namespace DropKey\V2;
defined( 'ABSPATH' ) || exit;
final class Tier_Repository extends Abstract_Repository {
	public function __construct() {
		parent::__construct( 'tiers' );
	}

	public function for_product( $product_id ) {
		global $wpdb;
		$product_id = absint( $product_id );
		if ( ! $product_id ) {
			return array();
		}
		$query = $wpdb->prepare( "SELECT * FROM {$this->table} WHERE product_id = %d ORDER BY sort_order ASC, id ASC", $product_id );
		return $wpdb->get_results( $query );
	}

	protected function normalize( array $data, $creating ) {
		global $wpdb;
		$allowed = array();
		$name    = isset( $data['name'] ) ? sanitize_text_field( $data['name'] ) : '';
		$slug    = isset( $data['slug'] ) ? sanitize_title( $data['slug'] ) : '';

		if ( $creating ) {
			$product_id = isset( $data['product_id'] ) ? absint( $data['product_id'] ) : 0;
			$products   = Database::table( 'products' );
			$exists     = $product_id ? $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$products} WHERE id = %d", $product_id ) ) : 0;
			if ( ! $exists ) {
				return new \WP_Error( 'dk2_tier_product_required', __( 'Choose an existing product for this tier.', 'dropkey-v2' ) );
			}
			$allowed['product_id'] = $product_id;
			if ( '' === $name ) {
				return new \WP_Error( 'dk2_tier_name_required', __( 'A tier name is required.', 'dropkey-v2' ) );
			}
			if ( ! isset( $data['activation_limit'] ) || ! is_numeric( $data['activation_limit'] ) || (float) $data['activation_limit'] !== (float) (int) $data['activation_limit'] || (int) $data['activation_limit'] < 1 || (int) $data['activation_limit'] > 65535 ) {
				return new \WP_Error( 'dk2_invalid_activation_limit', __( 'The activation limit must be a whole number from 1 to 65535.', 'dropkey-v2' ) );
			}
			$allowed['activation_limit'] = (int) $data['activation_limit'];
		} elseif ( isset( $data['activation_limit'] ) ) {
			if ( ! is_numeric( $data['activation_limit'] ) || (float) $data['activation_limit'] !== (float) (int) $data['activation_limit'] || (int) $data['activation_limit'] < 1 || (int) $data['activation_limit'] > 65535 ) {
				return new \WP_Error( 'dk2_invalid_activation_limit', __( 'The activation limit must be a whole number from 1 to 65535.', 'dropkey-v2' ) );
			}
			$allowed['activation_limit'] = (int) $data['activation_limit'];
		}

		if ( '' !== $name ) {
			$allowed['name'] = $name;
			if ( '' === $slug ) {
				$slug = sanitize_title( $name );
			}
		}
		if ( $creating && '' === $slug ) {
			return new \WP_Error( 'dk2_tier_slug_required', __( 'A valid tier name or slug is required.', 'dropkey-v2' ) );
		}
		if ( '' !== $slug ) {
			$allowed['slug'] = substr( $slug, 0, 150 );
		}
		if ( isset( $data['description'] ) ) {
			$allowed['description'] = wp_kses_post( $data['description'] );
		} elseif ( $creating ) {
			$allowed['description'] = '';
		}
		if ( isset( $data['status'] ) || $creating ) {
			$status = isset( $data['status'] ) ? sanitize_key( $data['status'] ) : 'draft';
			if ( ! in_array( $status, array( 'draft', 'published', 'archived' ), true ) ) {
				return new \WP_Error( 'dk2_invalid_tier_status', __( 'Choose a valid tier status.', 'dropkey-v2' ) );
			}
			$allowed['status'] = $status;
		}
		if ( isset( $data['sort_order'] ) ) {
			$allowed['sort_order'] = (int) $data['sort_order'];
		} elseif ( $creating ) {
			$allowed['sort_order'] = 0;
		}
		return $allowed;
	}

	protected function can_delete( $id ) {
		global $wpdb;
		$options = Database::table( 'billing_options' );
		$count   = $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$options} WHERE tier_id = %d", $id ) );
		if ( (int) $count > 0 ) {
			return new \WP_Error( 'dk2_tier_has_billing_options', __( 'Remove this tier’s billing options before deleting the tier.', 'dropkey-v2' ) );
		}
		return true;
	}
}
