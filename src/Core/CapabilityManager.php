<?php

namespace ASDLabs\TVXWooChangeLog\Core;

use ASDLabs\TVXWooChangeLog\Core\Contracts\Module;

final class CapabilityManager implements Module {
	const OPTION_VERSION       = 'tvx_wcl_capabilities_version';
	const VERSION              = '2026.04.14-beta2';
	const VIEW_CHANGE_LOG      = 'asdl_tvx_wc_view_change_log';
	const REVERT_INVENTORY     = 'asdl_tvx_wc_revert_inventory';
	const MANAGE_CONFIGURATION = 'asdl_tvx_wc_manage_change_log';

	public function register() {
		add_action( 'plugins_loaded', array( __CLASS__, 'maybe_upgrade' ) );
	}

	public static function activate() {
		self::install();
	}

	public static function maybe_upgrade() {
		if ( get_option( self::OPTION_VERSION, '' ) !== self::VERSION ) {
			self::install();
		}
	}

	public static function install() {
		$admin_role = get_role( 'administrator' );
		if ( $admin_role ) {
			$admin_role->add_cap( self::VIEW_CHANGE_LOG );
			$admin_role->add_cap( self::REVERT_INVENTORY );
			$admin_role->add_cap( self::MANAGE_CONFIGURATION );
		}

		$manager_role = get_role( 'shop_manager' );
		if ( $manager_role ) {
			$manager_role->add_cap( self::VIEW_CHANGE_LOG );
		}

		update_option( self::OPTION_VERSION, self::VERSION, false );
	}

	public static function uninstall() {
		foreach ( array( 'administrator', 'shop_manager' ) as $role_name ) {
			$role = get_role( $role_name );
			if ( ! $role ) {
				continue;
			}

			foreach ( array( self::VIEW_CHANGE_LOG, self::REVERT_INVENTORY, self::MANAGE_CONFIGURATION ) as $capability ) {
				$role->remove_cap( $capability );
			}
		}
	}

	public static function current_user_can_view_log() {
		return current_user_can( self::VIEW_CHANGE_LOG ) || current_user_can( self::MANAGE_CONFIGURATION );
	}

	public static function current_user_can_revert_inventory() {
		return current_user_can( self::REVERT_INVENTORY );
	}

	public static function current_user_can_manage_configuration() {
		return current_user_can( self::MANAGE_CONFIGURATION );
	}
}
