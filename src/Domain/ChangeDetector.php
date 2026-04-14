<?php

namespace ASDLabs\TVXWooChangeLog\Domain;

use ASDLabs\TVXWooChangeLog\Integrations\YITH\CostMetaResolver;
use ASDLabs\TVXWooChangeLog\Support\Context;

final class ChangeDetector {
	private static $pending = array();
	private static $post_type_cache = array();

	private $logger;
	private $cost_meta_resolver;
	private $context;

	public function __construct( ChangeLogger $logger, CostMetaResolver $cost_meta_resolver, Context $context ) {
		$this->logger             = $logger;
		$this->cost_meta_resolver = $cost_meta_resolver;
		$this->context            = $context;
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
				'context'   => $this->context->detect( $object_id, $this->field_key_for_meta_key( $meta_key ), $meta_key ),
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
				'context'   => $this->context->detect( $object_id, $this->field_key_for_meta_key( $meta_key ), $meta_key ),
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

	private function finalize_change( $object_id, $meta_key, $meta_value ) {
		if ( ! $this->should_track( $object_id, $meta_key ) ) {
			return;
		}

		$pending = $this->pop_pending( $object_id, $meta_key );
		$context = is_array( $pending['context'] ?? null )
			? $pending['context']
			: $this->context->detect( $object_id, $this->field_key_for_meta_key( $meta_key ), $meta_key );

		$product_snapshot = ProductSnapshot::from_post_id( $object_id );
		if ( empty( $product_snapshot ) ) {
			return;
		}

		$old_value = $pending['old_value'] ?? '';
		$field_key = $pending['field_key'] ?? $this->field_key_for_meta_key( $meta_key );

		$this->logger->log_change( $product_snapshot, $field_key, $old_value, $meta_value, $context );
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
}
