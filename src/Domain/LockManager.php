<?php

namespace ASDLabs\TVXWooChangeLog\Domain;

final class LockManager {
	public function build_order_lock_key( $order_id ) {
		return 'tvx_wcl_lock_' . md5( 'order:' . absint( $order_id ) );
	}

	public function acquire( $lock_key, $ttl = 600 ) {
		$lock_key = sanitize_key( (string) $lock_key );
		$ttl      = max( 60, absint( $ttl ) );
		$now      = time();
		$current  = get_option( $lock_key, array() );

		if ( is_array( $current ) && ! empty( $current['acquired_at'] ) ) {
			$acquired_at = absint( $current['acquired_at'] );
			if ( $acquired_at > 0 && ( $acquired_at + $ttl ) > $now ) {
				return false;
			}

			delete_option( $lock_key );
		}

		return add_option(
			$lock_key,
			array(
				'acquired_at' => $now,
			),
			'',
			false
		);
	}

	public function release( $lock_key ) {
		delete_option( sanitize_key( (string) $lock_key ) );
	}
}
