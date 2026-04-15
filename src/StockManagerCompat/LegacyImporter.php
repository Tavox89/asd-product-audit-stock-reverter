<?php

namespace ASDLabs\TVXWooChangeLog\StockManagerCompat;

use ASDLabs\TVXWooChangeLog\DB\Repositories\ChangeLogRepository;
use ASDLabs\TVXWooChangeLog\Domain\ChangeLogger;
use ASDLabs\TVXWooChangeLog\Domain\ProductSnapshot;
use ASDLabs\TVXWooChangeLog\Support\Time;
use WP_Error;

final class LegacyImporter {
	private $detector;
	private $repository;
	private $logger;

	public function __construct( ?Detector $detector = null, ?ChangeLogRepository $repository = null, ?ChangeLogger $logger = null ) {
		$this->detector   = $detector ?: new Detector();
		$this->repository = $repository ?: new ChangeLogRepository();
		$this->logger     = $logger ?: new ChangeLogger( $this->repository );
	}

	public function preview( $limit = 10 ) {
		$state = $this->detector->get_state();

		if ( empty( $state['import_supported'] ) ) {
			return new WP_Error( 'tvx_wcl_stock_manager_import_unavailable', 'No hay una tabla compatible de Stock Manager para importar.' );
		}

		$rows = $this->fetch_rows( $state, $limit, true );

		return array(
			'total_detected' => absint( $state['record_count'] ?? 0 ),
			'imported_count' => absint( $state['imported_count'] ?? 0 ),
			'pending_count'  => $this->count_remaining_rows( $state ),
			'sample_rows'    => array_map( array( $this, 'map_preview_row' ), $rows ),
		);
	}

	public function import_batch( $limit = 250, $dry_run = false ) {
		$state = $this->detector->get_state();

		if ( empty( $state['import_supported'] ) ) {
			return new WP_Error( 'tvx_wcl_stock_manager_import_unavailable', 'No hay una tabla compatible de Stock Manager para importar.' );
		}

		$rows          = $this->fetch_rows( $state, $limit, true );
		$import_state  = $this->detector->get_import_state();
		$processed     = 0;
		$imported      = 0;
		$duplicates    = 0;
		$last_external = absint( $import_state['last_external_id'] ?? 0 );

		foreach ( $rows as $row ) {
			$processed++;
			$external_id = sanitize_text_field( (string) ( $row['external_id'] ?? '' ) );
			if ( '' === $external_id ) {
				continue;
			}

			$last_external = max( $last_external, absint( $external_id ) );

			if ( $this->repository->has_source_reference( 'stock_manager', $external_id ) ) {
				$duplicates++;
				continue;
			}

			if ( $dry_run ) {
				$imported++;
				continue;
			}

			$product_id = absint( $row['product_id'] ?? 0 );
			$snapshot   = ProductSnapshot::from_post_id( $product_id );
			if ( empty( $snapshot ) ) {
				$snapshot = ProductSnapshot::fallback(
					$product_id,
					array(
						'product_name' => 'Legacy product #' . $product_id,
					)
				);
			}

			$context = array(
				'user_id'           => 0,
				'user_display_name' => '',
				'actor_type'        => 'legacy_plugin',
				'source_context'    => 'stock_manager_legacy_import',
				'request_fingerprint' => hash( 'sha256', 'stock_manager_legacy_import|' . $external_id ),
				'meta'              => array(
					'legacy_table'          => sanitize_text_field( (string) ( $state['table_name'] ?? '' ) ),
					'legacy_external_id'    => $external_id,
					'legacy_date_created'   => sanitize_text_field( (string) ( $row['date_created'] ?? '' ) ),
					'legacy_missing_fields' => array(
						'old_value',
						'actor',
						'context',
					),
				),
			);

			$logged = $this->logger->log_change(
				$snapshot,
				'stock',
				'',
				$row['qty'],
				$context,
				array(
					'source_system'      => 'stock_manager',
					'source_external_id' => $external_id,
					'import_flag'        => true,
					'created_at_utc'     => $this->resolve_created_at_utc( (string) ( $row['date_created'] ?? '' ) ),
				)
			);

			if ( $logged ) {
				$imported++;
			}
		}

		$pending_count = $this->count_remaining_rows( $state, $last_external );
		$new_state     = array(
			'last_external_id'    => $last_external,
			'processed_count'     => absint( $processed + ( $import_state['processed_count'] ?? 0 ) ),
			'imported_count'      => absint( $imported + ( $import_state['imported_count'] ?? 0 ) ),
			'duplicate_count'     => absint( $duplicates + ( $import_state['duplicate_count'] ?? 0 ) ),
			'last_run_at_utc'     => Time::now_utc(),
			'last_table_name'     => sanitize_text_field( (string) ( $state['table_name'] ?? '' ) ),
			'last_batch_size'     => absint( $limit ),
			'remaining_count'     => absint( $pending_count ),
			'completed'           => $pending_count <= 0,
		);

		if ( ! $dry_run ) {
			$this->detector->update_import_state( $new_state );
			$state = $this->detector->refresh_cache();
		}

		return array(
			'processed'     => $processed,
			'imported'      => $imported,
			'duplicates'    => $duplicates,
			'remaining'     => $pending_count,
			'completed'     => $pending_count <= 0,
			'dry_run'       => (bool) $dry_run,
			'state'         => $state,
			'import_state'  => $new_state,
		);
	}

	private function fetch_rows( array $state, $limit, $use_checkpoint ) {
		global $wpdb;

		$limit       = max( 1, min( 1000, absint( $limit ) ) );
		$table_name  = $this->safe_table_name( (string) ( $state['table_name'] ?? '' ) );
		$primary_key = sanitize_key( (string) ( $state['primary_key'] ?? 'ID' ) );
		$columns     = array_map( 'sanitize_key', (array) ( $state['columns'] ?? array() ) );
		$checkpoint  = $use_checkpoint ? absint( $this->detector->get_import_state()['last_external_id'] ?? 0 ) : 0;

		if ( '' === $table_name ) {
			return array();
		}

		if ( ! in_array( $primary_key, $columns, true ) ) {
			$primary_key = in_array( 'id', $columns, true ) ? 'id' : 'ID';
		}

		$sql = "SELECT {$primary_key} AS external_id, product_id, qty, date_created
			FROM {$table_name}
			WHERE {$primary_key} > %d
			ORDER BY {$primary_key} ASC
			LIMIT %d";

		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $checkpoint, $limit ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching

		return is_array( $rows ) ? $rows : array();
	}

	private function count_remaining_rows( array $state, $checkpoint = null ) {
		global $wpdb;

		$table_name  = $this->safe_table_name( (string) ( $state['table_name'] ?? '' ) );
		$primary_key = sanitize_key( (string) ( $state['primary_key'] ?? 'ID' ) );
		$columns     = array_map( 'sanitize_key', (array) ( $state['columns'] ?? array() ) );
		$checkpoint  = null === $checkpoint ? absint( $this->detector->get_import_state()['last_external_id'] ?? 0 ) : absint( $checkpoint );

		if ( '' === $table_name ) {
			return 0;
		}

		if ( ! in_array( $primary_key, $columns, true ) ) {
			$primary_key = in_array( 'id', $columns, true ) ? 'id' : 'ID';
		}

		$sql = "SELECT COUNT(*) FROM {$table_name} WHERE {$primary_key} > %d";

		return (int) $wpdb->get_var( $wpdb->prepare( $sql, $checkpoint ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
	}

	private function resolve_created_at_utc( $legacy_datetime ) {
		$utc = Time::site_datetime_to_utc( $legacy_datetime );

		return '' !== $utc ? $utc : Time::now_utc();
	}

	private function map_preview_row( array $row ) {
		return array(
			'external_id'  => sanitize_text_field( (string) ( $row['external_id'] ?? '' ) ),
			'product_id'   => absint( $row['product_id'] ?? 0 ),
			'qty'          => sanitize_text_field( (string) ( $row['qty'] ?? '' ) ),
			'date_created' => sanitize_text_field( (string) ( $row['date_created'] ?? '' ) ),
		);
	}

	private function safe_table_name( $table_name ) {
		$table_name = sanitize_text_field( (string) $table_name );

		return preg_match( '/^[A-Za-z0-9_]+$/', $table_name ) ? $table_name : '';
	}
}
