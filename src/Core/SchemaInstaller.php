<?php

namespace ASDLabs\TVXWooChangeLog\Core;

use ASDLabs\TVXWooChangeLog\Core\Contracts\Module;

final class SchemaInstaller implements Module {
	const OPTION_SCHEMA_VERSION = 'tvx_wcl_schema_version';

	public function register() {
		add_action( 'plugins_loaded', array( __CLASS__, 'maybe_upgrade' ), 5 );
	}

	public static function activate() {
		self::install();
	}

	public static function maybe_upgrade() {
		$current_version = get_option( self::OPTION_SCHEMA_VERSION, '' );

		if ( Schema::VERSION !== $current_version ) {
			self::install();
		}
	}

	public static function install() {
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		foreach ( Schema::get_queries() as $sql ) {
			dbDelta( $sql );
		}

		update_option( self::OPTION_SCHEMA_VERSION, Schema::VERSION, false );
	}
}
