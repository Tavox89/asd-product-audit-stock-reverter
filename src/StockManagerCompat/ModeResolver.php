<?php

namespace ASDLabs\TVXWooChangeLog\StockManagerCompat;

final class ModeResolver {
	public function resolve( array $inspection, $bridge_disabled = false ) {
		$has_import = ! empty( $inspection['import_supported'] );
		$has_bridge = ! empty( $inspection['bridge_supported'] );
		$is_active  = ! empty( $inspection['plugin_active'] );

		if ( ! $has_import ) {
			return 'native_only';
		}

		if ( $bridge_disabled || ! $has_bridge || ! $is_active ) {
			return 'stock_manager_import_only';
		}

		return 'stock_manager_bridge';
	}
}
