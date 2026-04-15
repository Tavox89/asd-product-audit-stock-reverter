<?php

namespace ASDLabs\TVXWooChangeLog\Domain;

use ASDLabs\TVXWooChangeLog\DB\Repositories\ChangeLogRepository;
use ASDLabs\TVXWooChangeLog\Support\Time;

final class ChangeLogger {
	private static $seen_hashes = array();
	private $repository;

	public function __construct( ChangeLogRepository $repository ) {
		$this->repository = $repository;
	}

	public function log_change( array $product_snapshot, $field_key, $old_value, $new_value, array $context, array $options = array() ) {
		if ( empty( $product_snapshot ) ) {
			return false;
		}

		$field_key = sanitize_key( (string) $field_key );
		$old_value = $this->normalize_value( $old_value );
		$new_value = $this->normalize_value( $new_value );

		if ( $old_value === $new_value ) {
			return false;
		}

		$source_system      = sanitize_key( (string) ( $options['source_system'] ?? 'native' ) );
		$source_external_id = sanitize_text_field( (string) ( $options['source_external_id'] ?? '' ) );
		$import_flag        = ! empty( $options['import_flag'] ) ? 1 : 0;
		$bridge_flag        = ! empty( $options['bridge_flag'] ) ? 1 : 0;
		$created_at_utc     = sanitize_text_field( (string) ( $options['created_at_utc'] ?? '' ) );

		$context_meta = array();
		if ( ! empty( $context['meta'] ) && is_array( $context['meta'] ) ) {
			$context_meta = $context['meta'];
		}

		$event_hash = hash(
			'sha256',
			wp_json_encode(
				array(
					'request_fingerprint' => (string) ( $context['request_fingerprint'] ?? '' ),
					'product_id'          => (int) $product_snapshot['product_id'],
					'variation_id'        => (int) $product_snapshot['variation_id'],
					'field_key'           => $field_key,
					'old_value'           => $old_value,
					'new_value'           => $new_value,
					'source_system'       => $source_system,
					'source_external_id'  => $source_external_id,
					'source_context'      => (string) ( $context['source_context'] ?? 'unknown' ),
					'order_id'            => (int) ( $context_meta['order_id'] ?? 0 ),
				)
			)
		);

		if ( isset( self::$seen_hashes[ $event_hash ] ) ) {
			return false;
		}

		self::$seen_hashes[ $event_hash ] = true;

		$row = array(
			'event_hash'          => $event_hash,
			'product_id'          => (int) $product_snapshot['product_id'],
			'variation_id'        => ! empty( $product_snapshot['variation_id'] ) ? (int) $product_snapshot['variation_id'] : null,
			'parent_id'           => ! empty( $product_snapshot['parent_id'] ) ? (int) $product_snapshot['parent_id'] : null,
			'product_type'        => sanitize_key( (string) ( $product_snapshot['product_type'] ?? '' ) ),
			'sku'                 => sanitize_text_field( (string) ( $product_snapshot['sku'] ?? '' ) ),
			'product_name'        => sanitize_text_field( (string) ( $product_snapshot['product_name'] ?? '' ) ),
			'field_key'           => $field_key,
			'old_value'           => $old_value,
			'new_value'           => $new_value,
			'delta_value'         => $this->maybe_calculate_delta( $field_key, $old_value, $new_value ),
			'changed_by_user_id'  => ! empty( $context['user_id'] ) ? absint( $context['user_id'] ) : null,
			'changed_by_name'     => sanitize_text_field( (string) ( $context['user_display_name'] ?? '' ) ),
			'actor_type'          => sanitize_key( (string) ( $context['actor_type'] ?? 'system' ) ),
			'source_system'       => $source_system,
			'source_external_id'  => $source_external_id,
			'import_flag'         => $import_flag,
			'bridge_flag'         => $bridge_flag,
			'source_context'      => sanitize_key( (string) ( $context['source_context'] ?? 'unknown' ) ),
			'request_fingerprint' => sanitize_text_field( (string) ( $context['request_fingerprint'] ?? '' ) ),
			'context_json'        => ! empty( $context_meta ) ? wp_json_encode( $context_meta ) : null,
			'created_at_utc'      => '' !== $created_at_utc ? $created_at_utc : Time::now_utc(),
		);

		return $this->repository->insert( $row );
	}

	private function maybe_calculate_delta( $field_key, $old_value, $new_value ) {
		if ( 'stock' !== $field_key || ! is_numeric( $old_value ) || ! is_numeric( $new_value ) ) {
			return null;
		}

		return round( (float) $new_value - (float) $old_value, 6 );
	}

	private function normalize_value( $value ) {
		if ( null === $value ) {
			return '';
		}

		if ( is_bool( $value ) ) {
			return $value ? '1' : '0';
		}

		if ( is_numeric( $value ) ) {
			$formatted = number_format( (float) $value, 6, '.', '' );
			$formatted = rtrim( rtrim( $formatted, '0' ), '.' );
			return '' === $formatted ? '0' : $formatted;
		}

		return sanitize_text_field( (string) $value );
	}
}
