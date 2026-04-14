<?php

namespace ASDLabs\TVXWooChangeLog\Support;

final class RuntimeContext {
	private static $stack = array();

	public static function push( array $context ) {
		self::$stack[] = $context;
	}

	public static function pop() {
		if ( empty( self::$stack ) ) {
			return array();
		}

		return array_pop( self::$stack );
	}

	public static function current() {
		if ( empty( self::$stack ) ) {
			return array();
		}

		return (array) end( self::$stack );
	}

	public static function run( array $context, callable $callback ) {
		self::push( $context );

		try {
			return $callback();
		} finally {
			self::pop();
		}
	}
}
