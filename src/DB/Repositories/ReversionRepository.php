<?php

namespace ASDLabs\TVXWooChangeLog\DB\Repositories;

use ASDLabs\TVXWooChangeLog\Core\Tables;

final class ReversionRepository {
	private $table;

	public function __construct() {
		$this->table = Tables::name( 'reversions' );
	}

	public function insert( array $row ) {
		global $wpdb;

		$inserted = $wpdb->insert( $this->table, $row ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		if ( false === $inserted ) {
			return 0;
		}

		return (int) $wpdb->insert_id;
	}

	public function find_latest_successful_by_order_id( $order_id ) {
		global $wpdb;

		$order_id = absint( $order_id );
		if ( $order_id <= 0 ) {
			return array();
		}

		$sql = "SELECT * FROM {$this->table}
			WHERE order_id = %d AND result_status = 'success'
			ORDER BY executed_at_utc DESC, id DESC
			LIMIT 1";

		$row = $wpdb->get_row( $wpdb->prepare( $sql, $order_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching

		return is_array( $row ) ? $row : array();
	}

	public function find_latest_by_order_id( $order_id ) {
		global $wpdb;

		$order_id = absint( $order_id );
		if ( $order_id <= 0 ) {
			return array();
		}

		$sql = "SELECT * FROM {$this->table}
			WHERE order_id = %d
			ORDER BY executed_at_utc DESC, id DESC
			LIMIT 1";

		$row = $wpdb->get_row( $wpdb->prepare( $sql, $order_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching

		return is_array( $row ) ? $row : array();
	}
}
