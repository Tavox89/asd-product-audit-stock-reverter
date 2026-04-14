<?php

namespace ASDLabs\TVXWooChangeLog\Core;

final class Tables {
	private static $map = array(
		'change_log' => 'asdl_tvx_wc_change_log',
		'reversions' => 'asdl_tvx_wc_reversions',
	);

	public static function name( $logical_name ) {
		global $wpdb;

		if ( ! isset( self::$map[ $logical_name ] ) ) {
			return $wpdb->prefix . 'asdl_tvx_wc_' . sanitize_key( $logical_name );
		}

		return $wpdb->prefix . self::$map[ $logical_name ];
	}

	public static function all() {
		$tables = array();

		foreach ( array_keys( self::$map ) as $logical_name ) {
			$tables[ $logical_name ] = self::name( $logical_name );
		}

		return $tables;
	}
}
