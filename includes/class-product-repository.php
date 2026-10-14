<?php
/**
 * Product data access.
 *
 * @package DropKeyV2
 */
namespace DropKey\V2;
defined( 'ABSPATH' ) || exit;
final class Product_Repository extends Abstract_Repository {
	public function __construct() {
		parent::__construct( 'products' );
	}

	protected function normalize( array $data, $creating ) {
		$allowed = array();
		$name    = isset( $data['name'] ) ? sanitize_text_field( $data['name'] ) : '';
		$slug    = isset( $data['slug'] ) ? sanitize_title( $data['slug'] ) : '';

		if ( $creating && '' === $name ) {
			return new \WP_Error( 'dk2_product_name_required', __( 'A product name is required.', 'dropkey-v2' ) );
		}
		if ( '' !== $name ) {
			$allowed['name'] = $name;
			if ( '' === $slug ) {
				$slug = sanitize_title( $name );
			}
		}
		if ( '' !== $slug ) {
			$allowed['slug'] = $slug;
		}
		if ( isset( $data['description'] ) ) {
			$allowed['description'] = wp_kses_post( $data['description'] );
		} elseif ( $creating ) {
			$allowed['description'] = '';
		}
		if ( isset( $data['status'] ) || $creating ) {
			$status = isset( $data['status'] ) ? sanitize_key( $data['status'] ) : 'draft';
			if ( ! in_array( $status, array( 'draft', 'published', 'archived' ), true ) ) {
				return new \WP_Error( 'dk2_invalid_product_status', __( 'Choose a valid product status.', 'dropkey-v2' ) );
			}
			$allowed['status'] = $status;
		}
		return $allowed;
	}

	protected function can_delete( $id ) {
		global $wpdb;
		$tiers = Database::table( 'tiers' );
		$count = $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$tiers} WHERE product_id = %d", $id ) );
		if ( (int) $count > 0 ) {
			return new \WP_Error( 'dk2_product_has_tiers', __( 'Remove this product’s tiers before deleting the product.', 'dropkey-v2' ) );
		}
		return true;
	}
}
