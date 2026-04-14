<?php

namespace ASDLabs\TVXWooChangeLog\Core;

final class Autoloader {
	private $base_dir;
	private $prefix = 'ASDLabs\\TVXWooChangeLog\\';

	public function __construct( $base_dir ) {
		$this->base_dir = rtrim( $base_dir, '/\\' ) . DIRECTORY_SEPARATOR;
	}

	public function register() {
		spl_autoload_register( array( $this, 'load' ) );
	}

	public function load( $class ) {
		if ( 0 !== strpos( $class, $this->prefix ) ) {
			return;
		}

		$relative_class = substr( $class, strlen( $this->prefix ) );
		$file           = $this->base_dir . str_replace( '\\', DIRECTORY_SEPARATOR, $relative_class ) . '.php';

		if ( file_exists( $file ) ) {
			require_once $file;
		}
	}
}
