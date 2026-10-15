<?php
/**
 * Shared database operations for core repositories.
 *
 * @package DropKeyV2
 */
namespace DropKey\V2;
defined( 'ABSPATH' ) || exit;
abstract class Abstract_Repository {
	protected $table;

	public function __construct( $table ) {
		$this->table = Database::table( $table );
	}

	public function create( array $data ) {
		global $wpdb;
		$normalized = $this->normalize( $data, true );
		if ( is_wp_error( $normalized ) ) {
			return $normalized;
		}
		$now                      = current_time( 'mysql', true );
		$normalized['created_at'] = $now;
		$normalized['updated_at'] = $now;
		if ( false === $wpdb->insert( $this->table, $normalized ) ) {
			$this->log_database_failure( 'insert', $normalized );
			return new \WP_Error( 'dk2_database_insert_failed', __( 'The record could not be saved. Check the supplied values and database configuration.', 'dropkey-v2' ) );
		}
		return (int) $wpdb->insert_id;
	}

	public function update( $id, array $data ) {
		global $wpdb;
		$id = absint( $id );
		if ( ! $id || ! $this->get( $id ) ) {
			return new \WP_Error( 'dk2_record_not_found', __( 'The requested record was not found.', 'dropkey-v2' ) );
		}
		$data['id'] = $id;
		$normalized = $this->normalize( $data, false );
		if ( is_wp_error( $normalized ) ) {
			return $normalized;
		}
		if ( empty( $normalized ) ) {
			return new \WP_Error( 'dk2_empty_update', __( 'Provide at least one valid field to update.', 'dropkey-v2' ) );
		}
		$normalized['updated_at'] = current_time( 'mysql', true );
		$result = $wpdb->update( $this->table, $normalized, array( 'id' => $id ) );
		if ( false === $result ) {
			$this->log_database_failure( 'update', $normalized );
			return new \WP_Error( 'dk2_database_update_failed', __( 'The record could not be updated. Check the supplied values and database configuration.', 'dropkey-v2' ) );
		}
		return true;
	}

	public function get( $id ) {
		global $wpdb;
		$id = absint( $id );
		if ( ! $id ) {
			return null;
		}
		$query  = $wpdb->prepare( "SELECT * FROM {$this->table} WHERE id = %d LIMIT 1", $id );
		$result = $wpdb->get_row( $query );
		return is_object( $result ) ? $result : null;
	}

	public function all( $limit = 100 ) {
		global $wpdb;
		$limit = max( 1, min( 500, absint( $limit ) ) );
		$query = $wpdb->prepare( "SELECT * FROM {$this->table} ORDER BY id DESC LIMIT %d", $limit );
		return $wpdb->get_results( $query );
	}

	public function delete( $id ) {
		global $wpdb;
		$id = absint( $id );
		if ( ! $id || ! $this->get( $id ) ) {
			return new \WP_Error( 'dk2_record_not_found', __( 'The requested record was not found.', 'dropkey-v2' ) );
		}
		$can_delete = $this->can_delete( $id );
		if ( is_wp_error( $can_delete ) ) {
			return $can_delete;
		}
		if ( false === $wpdb->delete( $this->table, array( 'id' => $id ), array( '%d' ) ) ) {
			$this->log_database_failure( 'delete', array( 'id' => $id ) );
			return new \WP_Error( 'dk2_database_delete_failed', __( 'The record could not be deleted.', 'dropkey-v2' ) );
		}
		return true;
	}

	/**
	 * Log database failure details without logging submitted field values.
	 *
	 * @param string $operation Failed database operation.
	 * @param array  $data      Data keys associated with the operation.
	 */
	protected function log_database_failure( $operation, array $data = array() ) {
		global $wpdb;

		$details = array(
			'operation'      => sanitize_key( $operation ),
			'table'          => $this->table,
			'database_error' => isset( $wpdb->last_error ) ? (string) $wpdb->last_error : '',
			'columns'        => array_map( 'sanitize_key', array_keys( $data ) ),
		);

		error_log( '[DropKey V2] Database operation failed: ' . wp_json_encode( $details ) );
		Logger::error('database','repository_write_failed','A repository database operation failed',array('operation'=>$details['operation'],'table'=>basename(str_replace($GLOBALS['wpdb']->prefix,'',$this->table)),'columns'=>$details['columns']));
	}

	abstract protected function normalize( array $data, $creating );

	protected function can_delete( $id ) {
		return true;
	}
}
