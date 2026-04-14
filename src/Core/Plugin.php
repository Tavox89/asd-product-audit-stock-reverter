<?php

namespace ASDLabs\TVXWooChangeLog\Core;

use ASDLabs\TVXWooChangeLog\Admin\Menu;
use ASDLabs\TVXWooChangeLog\Core\Contracts\Module;
use ASDLabs\TVXWooChangeLog\Integrations\WooCommerce\Module as WooModule;

final class Plugin {
	private static $instance = null;
	private $modules = array();

	public static function boot( $plugin_file ) {
		if ( null === self::$instance ) {
			self::$instance = new self( $plugin_file );
		}

		return self::$instance;
	}

	public static function activate() {
		SchemaInstaller::activate();
		CapabilityManager::activate();
	}

	public static function deactivate() {
		// No-op por ahora. Mantener simple y predecible.
	}

	private function __construct( $plugin_file ) {
		unset( $plugin_file );

		$this->modules = array(
			new SchemaInstaller(),
			new CapabilityManager(),
			new Menu(),
			new WooModule(),
		);

		$this->register_modules();
		add_action( 'admin_notices', array( $this, 'maybe_render_woocommerce_notice' ) );
	}

	private function register_modules() {
		foreach ( $this->modules as $module ) {
			if ( $module instanceof Module ) {
				$module->register();
			}
		}
	}

	public function maybe_render_woocommerce_notice() {
		if ( class_exists( 'WooCommerce' ) || ! current_user_can( 'activate_plugins' ) ) {
			return;
		}

		echo '<div class="notice notice-error"><p><strong>TVX Woo Change Log:</strong> requiere WooCommerce activo para operar.</p></div>';
	}
}
