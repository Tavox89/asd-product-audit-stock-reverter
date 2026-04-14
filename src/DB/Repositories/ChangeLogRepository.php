<?php

namespace ASDLabs\TVXWooChangeLog\DB\Repositories;

use ASDLabs\TVXWooChangeLog\Core\Tables;

final class ChangeLogRepository {
	private $table;

	public function __construct() {
		$this->table = Tables::name( 'change_log' );
	}

	public function insert( array $row ) {
		global $wpdb;

		$inserted = $wpdb->insert( $this->table, $row ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching

		if ( false === $inserted ) {
			if ( false !== stripos( (string) $wpdb->last_error, 'Duplicate entry' ) ) {
				return true;
			}

			return false;
		}

		return true;
	}

	public function get_paginated( array $filters, $page = 1, $per_page = 20 ) {
		global $wpdb;

		$page     = max( 1, absint( $page ) );
		$per_page = max( 1, min( 100, absint( $per_page ) ) );
		$offset   = ( $page - 1 ) * $per_page;

		list( $where_sql, $params ) = $this->build_where_sql( $filters );

		$count_sql = "SELECT COUNT(*) FROM {$this->table} {$where_sql}";
		$data_sql  = "SELECT * FROM {$this->table} {$where_sql} ORDER BY created_at_utc DESC, id DESC LIMIT %d OFFSET %d";

		if ( ! empty( $params ) ) {
			$total = (int) $wpdb->get_var( $wpdb->prepare( $count_sql, $params ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		} else {
			$total = (int) $wpdb->get_var( $count_sql ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		}

		$data_params   = $params;
		$data_params[] = $per_page;
		$data_params[] = $offset;
		$rows          = $wpdb->get_results( $wpdb->prepare( $data_sql, $data_params ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching

		return array(
			'rows'  => is_array( $rows ) ? $rows : array(),
			'total' => $total,
		);
	}

	public function get_distinct_users() {
		global $wpdb;

		$sql = "SELECT changed_by_user_id AS user_id, MAX(changed_by_name) AS display_name
			FROM {$this->table}
			WHERE changed_by_user_id IS NOT NULL AND changed_by_user_id > 0
			GROUP BY changed_by_user_id
			ORDER BY display_name ASC";

		$rows = $wpdb->get_results( $sql, ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching

		return is_array( $rows ) ? $rows : array();
	}

	private function build_where_sql( array $filters ) {
		global $wpdb;

		$where  = array( 'WHERE 1=1' );
		$params = array();

		$search = sanitize_text_field( (string) ( $filters['search'] ?? '' ) );
		if ( '' !== $search ) {
			$like     = '%' . $wpdb->esc_like( $search ) . '%';
			$where[]  = 'AND (product_name LIKE %s OR sku LIKE %s OR CAST(product_id AS CHAR) = %s OR CAST(variation_id AS CHAR) = %s)';
			$params[] = $like;
			$params[] = $like;
			$params[] = $search;
			$params[] = $search;
		}

		$field_key = sanitize_key( (string) ( $filters['field_key'] ?? '' ) );
		if ( in_array( $field_key, array( 'stock', 'regular_price', 'yith_cost' ), true ) ) {
			$where[]  = 'AND field_key = %s';
			$params[] = $field_key;
		}

		$user_id = absint( $filters['user_id'] ?? 0 );
		if ( $user_id > 0 ) {
			$where[]  = 'AND changed_by_user_id = %d';
			$params[] = $user_id;
		}

		$scope = sanitize_key( (string) ( $filters['scope'] ?? '' ) );
		if ( 'variation' === $scope ) {
			$where[] = 'AND variation_id IS NOT NULL AND variation_id > 0';
		} elseif ( 'product' === $scope ) {
			$where[] = 'AND (variation_id IS NULL OR variation_id = 0)';
		}

		$date_from = sanitize_text_field( (string) ( $filters['date_from_utc'] ?? '' ) );
		if ( '' !== $date_from ) {
			$where[]  = 'AND created_at_utc >= %s';
			$params[] = $date_from;
		}

		$date_to = sanitize_text_field( (string) ( $filters['date_to_utc'] ?? '' ) );
		if ( '' !== $date_to ) {
			$where[]  = 'AND created_at_utc <= %s';
			$params[] = $date_to;
		}

		return array( implode( ' ', $where ), $params );
	}
}
