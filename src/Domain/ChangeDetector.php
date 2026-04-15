<?php

namespace ASDLabs\TVXWooChangeLog\Domain;

use ASDLabs\TVXWooChangeLog\Integrations\YITH\CostMetaResolver;
use ASDLabs\TVXWooChangeLog\StockManagerCompat\BridgeWriter;
use ASDLabs\TVXWooChangeLog\Support\Context;

final class ChangeDetector {
	private static $pending = array();
	private static $pending_stock = array();
	private static $post_type_cache = array();

	private $logger;
	private $cost_meta_resolver;
	private $context;
	private $bridge_writer;

	public function __construct( ChangeLogger $logger, CostMetaResolver $cost_meta_resolver, Context $context, ?BridgeWriter $bridge_writer = null ) {
		$this->logger             = $logger;
		$this->cost_meta_resolver = $cost_meta_resolver;
		$this->context            = $context;
		$this->bridge_writer      = $bridge_writer ?: new BridgeWriter();
	}

	public function capture_before_update( $check, $object_id, $meta_key, $meta_value, $prev_value ) {
		unset( $meta_value, $prev_value );

		if ( ! $this->should_track( $object_id, $meta_key ) ) {
			return $check;
		}

		$this->push_pending(
			$object_id,
			$meta_key,
			array(
				'old_value' => get_post_meta( $object_id, $meta_key, true ),
				'field_key' => $this->field_key_for_meta_key( $meta_key ),
				'context'   => $this->with_detector_meta(
					$this->context->detect( $object_id, $this->field_key_for_meta_key( $meta_key ), $meta_key ),
					'meta_hook'
				),
			)
		);

		return $check;
	}

	public function capture_before_add( $check, $object_id, $meta_key, $meta_value, $unique ) {
		unset( $meta_value, $unique );

		if ( ! $this->should_track( $object_id, $meta_key ) ) {
			return $check;
		}

		$this->push_pending(
			$object_id,
			$meta_key,
			array(
				'old_value' => get_post_meta( $object_id, $meta_key, true ),
				'field_key' => $this->field_key_for_meta_key( $meta_key ),
				'context'   => $this->with_detector_meta(
					$this->context->detect( $object_id, $this->field_key_for_meta_key( $meta_key ), $meta_key ),
					'meta_hook'
				),
			)
		);

		return $check;
	}

	public function handle_updated_meta( $meta_id, $object_id, $meta_key, $meta_value ) {
		unset( $meta_id );
		$this->finalize_change( $object_id, $meta_key, $meta_value );
	}

	public function handle_added_meta( $meta_id, $object_id, $meta_key, $meta_value ) {
		unset( $meta_id );
		$this->finalize_change( $object_id, $meta_key, $meta_value );
	}

	public function capture_before_stock_change( $product ) {
		$product_id = $this->product_id_from_object( $product );
		if ( $product_id <= 0 ) {
			return;
		}

		$this->push_pending_stock(
			$product_id,
			array(
				'old_value' => is_object( $product ) && method_exists( $product, 'get_stock_quantity' ) ? $product->get_stock_quantity( 'edit' ) : null,
				'context'   => $this->with_detector_meta(
					$this->context->detect( $product_id, 'stock', '_stock' ),
					'stock_hook'
				),
			)
		);
	}

	public function handle_stock_changed( $product ) {
		$product_id = $this->product_id_from_object( $product );
		if ( $product_id <= 0 ) {
			return;
		}

		$pending = $this->pop_pending_stock( $product_id );
		$context = is_array( $pending['context'] ?? null )
			? $pending['context']
			: $this->with_detector_meta( $this->context->detect( $product_id, 'stock', '_stock' ), 'stock_hook' );

		$product_snapshot = ProductSnapshot::from_product( $product );
		if ( empty( $product_snapshot ) ) {
			$product_snapshot = ProductSnapshot::from_post_id( $product_id );
		}

		if ( empty( $product_snapshot ) ) {
			return;
		}

		$old_value = $pending['old_value'] ?? null;
		$new_value = is_object( $product ) && method_exists( $product, 'get_stock_quantity' ) ? $product->get_stock_quantity( 'edit' ) : null;

		$this->logger->log_change( $product_snapshot, 'stock', $old_value, $new_value, $context );
	}

	private function finalize_change( $object_id, $meta_key, $meta_value ) {
		if ( ! $this->should_track( $object_id, $meta_key ) ) {
			return;
		}

		$pending = $this->pop_pending( $object_id, $meta_key );
		$context = is_array( $pending['context'] ?? null )
			? $pending['context']
			: $this->with_detector_meta( $this->context->detect( $object_id, $this->field_key_for_meta_key( $meta_key ), $meta_key ), 'meta_hook' );

		$product_snapshot = ProductSnapshot::from_post_id( $object_id );
		if ( empty( $product_snapshot ) ) {
			return;
		}

		$old_value = $pending['old_value'] ?? '';
		$field_key = $pending['field_key'] ?? $this->field_key_for_meta_key( $meta_key );

		$logged = $this->logger->log_change( $product_snapshot, $field_key, $old_value, $meta_value, $context );

		if ( $logged && 'stock' === $field_key ) {
			$this->bridge_writer->maybe_write_stock_event( $product_snapshot, $meta_value, $context );
		}
	}

	private function push_pending( $object_id, $meta_key, array $payload ) {
		$key = $this->queue_key( $object_id, $meta_key );

		if ( ! isset( self::$pending[ $key ] ) ) {
			self::$pending[ $key ] = array();
		}

		self::$pending[ $key ][] = $payload;
	}

	private function pop_pending( $object_id, $meta_key ) {
		$key = $this->queue_key( $object_id, $meta_key );

		if ( empty( self::$pending[ $key ] ) ) {
			return array();
		}

		return array_shift( self::$pending[ $key ] );
	}

	private function queue_key( $object_id, $meta_key ) {
		return absint( $object_id ) . ':' . sanitize_key( (string) $meta_key );
	}

	private function push_pending_stock( $product_id, array $payload ) {
		$product_id = absint( $product_id );
		if ( $product_id <= 0 ) {
			return;
		}

		if ( ! isset( self::$pending_stock[ $product_id ] ) ) {
			self::$pending_stock[ $product_id ] = array();
		}

		self::$pending_stock[ $product_id ][] = $payload;
	}

	private function pop_pending_stock( $product_id ) {
		$product_id = absint( $product_id );
		if ( $product_id <= 0 || empty( self::$pending_stock[ $product_id ] ) ) {
			return array();
		}

		return array_shift( self::$pending_stock[ $product_id ] );
	}

	private function should_track( $object_id, $meta_key ) {
		$meta_key = sanitize_key( (string) $meta_key );
		if ( ! in_array( $meta_key, $this->tracked_meta_keys(), true ) ) {
			return false;
		}

		$object_id = absint( $object_id );
		if ( $object_id <= 0 ) {
			return false;
		}

		if ( isset( self::$post_type_cache[ $object_id ] ) ) {
			return in_array( self::$post_type_cache[ $object_id ], array( 'product', 'product_variation' ), true );
		}

		self::$post_type_cache[ $object_id ] = get_post_type( $object_id );

		return in_array( self::$post_type_cache[ $object_id ], array( 'product', 'product_variation' ), true );
	}

	private function tracked_meta_keys() {
		return array_merge(
			array(
				'_stock',
				'_regular_price',
			),
			$this->cost_meta_resolver->get_monitored_meta_keys()
		);
	}

	private function field_key_for_meta_key( $meta_key ) {
		$meta_key = sanitize_key( (string) $meta_key );

		if ( '_stock' === $meta_key ) {
			return 'stock';
		}

		if ( '_regular_price' === $meta_key ) {
			return 'regular_price';
		}

		if ( in_array( $meta_key, $this->cost_meta_resolver->get_monitored_meta_keys(), true ) ) {
			return 'yith_cost';
		}

		return 'unknown';
	}

	private function product_id_from_object( $product ) {
		if ( ! $product || ! is_object( $product ) || ! method_exists( $product, 'get_id' ) ) {
			return 0;
		}

		return (int) $product->get_id();
	}

	private function with_detector_meta( array $context, $detector ) {
		if ( empty( $context['meta'] ) || ! is_array( $context['meta'] ) ) {
			$context['meta'] = array();
		}

		if ( ! empty( $context['meta']['detector'] ) && sanitize_key( (string) $context['meta']['detector'] ) !== sanitize_key( (string) $detector ) ) {
			$context['meta']['runtime_detector'] = sanitize_key( (string) $context['meta']['detector'] );
		}

		$context['meta']['detector'] = sanitize_key( (string) $detector );

		return $context;
	}
}
