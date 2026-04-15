<?php

namespace ASDLabs\TVXWooChangeLog\StockManagerCompat;

final class BridgeWriter {
	private static $seen = array();
	private $detector;

	public function __construct( ?Detector $detector = null ) {
		$this->detector = $detector ?: new Detector();
	}

	public function maybe_write_stock_event( array $product_snapshot, $new_value, array $context ) {
		global $wpdb;

		$state = $this->detector->get_state();
		if ( 'stock_manager_bridge' !== (string) ( $state['mode'] ?? '' ) || empty( $state['bridge_supported'] ) ) {
			return $this->result( 'unavailable' );
		}

		$source_context = sanitize_key( (string) ( $context['source_context'] ?? 'unknown' ) );
		$detector_name  = sanitize_key( (string) ( $context['meta']['detector'] ?? '' ) );
		$table_name     = sanitize_text_field( (string) ( $state['table_name'] ?? '' ) );
		$product_id     = absint( $product_snapshot['product_id'] ?? 0 );

		if ( $product_id <= 0 || ! is_numeric( $new_value ) ) {
			return $this->result( 'invalid' );
		}

		if ( ! preg_match( '/^[A-Za-z0-9_]+$/', $table_name ) ) {
			return $this->result( 'incompatible' );
		}

		if ( 'stock_manager' === $source_context || in_array( $detector_name, array( 'legacy_import', 'stock_manager_bridge' ), true ) ) {
			return $this->result( 'skipped' );
		}

		$request_fingerprint = sanitize_text_field( (string) ( $context['request_fingerprint'] ?? '' ) );
		$stock_qty           = (int) round( (float) $new_value );
		$memory_key          = hash( 'sha256', $product_id . '|' . $stock_qty . '|' . $request_fingerprint );

		if ( isset( self::$seen[ $memory_key ] ) ) {
			return $this->result( 'duplicate' );
		}

		self::$seen[ $memory_key ] = true;

		if ( $this->native_stock_manager_hook_will_cover_event( $state, $detector_name ) ) {
			return $this->result( 'already_logged' );
		}

		$inserted = false !== $wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$table_name,
			array(
				'date_created' => current_time( 'mysql' ),
				'product_id'   => $product_id,
				'qty'          => $stock_qty,
			)
		);

		return $this->result( $inserted ? 'written' : 'failed' );
	}

	private function native_stock_manager_hook_will_cover_event( array $state, $detector_name ) {
		$detector_name = sanitize_key( (string) $detector_name );
		if ( 'stock_hook' !== $detector_name || empty( $state['plugin_active'] ) ) {
			return false;
		}

		$current_hook = (string) current_filter();
		$hooks        = array_map( 'strval', (array) ( $state['hooks'] ?? array() ) );

		return '' !== $current_hook && in_array( $current_hook, $hooks, true );
	}

	private function result( $status ) {
		$status = sanitize_key( (string) $status );

		return array(
			'status'      => $status,
			'bridge_flag' => in_array( $status, array( 'written', 'already_logged' ), true ),
		);
	}
}
