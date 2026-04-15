<?php

namespace ASDLabs\TVXWooChangeLog\StockManagerCompat;

use ASDLabs\TVXWooChangeLog\DB\Repositories\ChangeLogRepository;
use ASDLabs\TVXWooChangeLog\Support\Time;

final class Detector {
	const OPTION_STATE           = 'tvx_wcl_stock_manager_compat_state';
	const OPTION_IMPORT_STATE    = 'tvx_wcl_stock_manager_import_state';
	const OPTION_BRIDGE_DISABLED = 'tvx_wcl_stock_manager_bridge_disabled';

	private $inspector;
	private $mode_resolver;
	private $change_log_repository;

	public function __construct( ?SchemaInspector $inspector = null, ?ModeResolver $mode_resolver = null, ?ChangeLogRepository $change_log_repository = null ) {
		$this->inspector              = $inspector ?: new SchemaInspector();
		$this->mode_resolver          = $mode_resolver ?: new ModeResolver();
		$this->change_log_repository  = $change_log_repository ?: new ChangeLogRepository();
	}

	public function maybe_refresh_cache() {
		$state      = get_option( self::OPTION_STATE, array() );
		$signature  = $this->inspector->quick_signature();
		$has_cached = is_array( $state ) && ! empty( $state );

		if ( ! $has_cached ) {
			return $this->refresh_cache();
		}

		$cached_version = sanitize_text_field( (string) ( $state['plugin_version'] ?? '' ) );
		$cached_active  = ! empty( $state['plugin_active'] );
		$cached_file    = sanitize_text_field( (string) ( $state['plugin_file'] ?? '' ) );

		if ( $cached_version !== sanitize_text_field( (string) ( $signature['plugin_version'] ?? '' ) )
			|| $cached_active !== ! empty( $signature['plugin_active'] )
			|| $cached_file !== sanitize_text_field( (string) ( $signature['plugin_file'] ?? '' ) ) ) {
			return $this->refresh_cache();
		}

		return $state;
	}

	public function get_state( $force_refresh = false ) {
		if ( $force_refresh ) {
			return $this->refresh_cache();
		}

		$state = get_option( self::OPTION_STATE, array() );
		if ( ! is_array( $state ) || empty( $state ) ) {
			return $this->refresh_cache();
		}

		return $state;
	}

	public function refresh_cache() {
		$inspection      = $this->inspector->inspect();
		$bridge_disabled = (bool) get_option( self::OPTION_BRIDGE_DISABLED, false );
		$mode            = $this->mode_resolver->resolve( $inspection, $bridge_disabled );
		$imported_count  = $this->change_log_repository->count_by_source_system( 'stock_manager', true );
		$latest_import   = $this->change_log_repository->find_latest_by_source_system( 'stock_manager' );

		$state = array_merge(
			$inspection,
			array(
				'mode'                 => $mode,
				'bridge_disabled'      => $bridge_disabled,
				'imported_count'       => $imported_count,
				'last_imported_at_utc' => sanitize_text_field( (string) ( $latest_import['created_at_utc'] ?? '' ) ),
				'inspected_at_utc'     => Time::now_utc(),
			)
		);

		update_option( self::OPTION_STATE, $state, false );

		return $state;
	}

	public function get_import_state() {
		$state = get_option( self::OPTION_IMPORT_STATE, array() );

		return is_array( $state ) ? $state : array();
	}

	public function update_import_state( array $state ) {
		update_option( self::OPTION_IMPORT_STATE, $state, false );
	}

	public function set_bridge_disabled( $disabled ) {
		update_option( self::OPTION_BRIDGE_DISABLED, $disabled ? 1 : 0, false );

		return $this->refresh_cache();
	}
}
