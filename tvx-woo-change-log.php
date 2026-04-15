<?php
/**
 * Plugin Name: ASD Labs Product Audit & Stock Reverter
 * Plugin URI: https://asdlabs.com.ve
 * Description: Auditoría enriquecida de productos WooCommerce, compatibilidad con Stock Manager y reversión arbitraria segura de inventario por pedido.
 * Version: 1.1.0
 * Author: ASD Labs
 * Author URI: https://asdlabs.com.ve
 * Text Domain: tvx-woo-change-log
 * Requires at least: 6.4
 * Requires PHP: 7.4
 * WC requires at least: 7.8
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! defined( 'TVX_WCL_VERSION' ) ) {
	define( 'TVX_WCL_VERSION', '1.1.0' );
}

if ( ! defined( 'TVX_WCL_FILE' ) ) {
	define( 'TVX_WCL_FILE', __FILE__ );
}

if ( ! defined( 'TVX_WCL_DIR' ) ) {
	define( 'TVX_WCL_DIR', plugin_dir_path( __FILE__ ) );
}

if ( ! defined( 'TVX_WCL_URL' ) ) {
	define( 'TVX_WCL_URL', plugin_dir_url( __FILE__ ) );
}

require_once TVX_WCL_DIR . 'src/Core/Autoloader.php';

$tvx_wcl_autoloader = new \ASDLabs\TVXWooChangeLog\Core\Autoloader( TVX_WCL_DIR . 'src/' );
$tvx_wcl_autoloader->register();

add_action(
	'before_woocommerce_init',
	static function () {
		if ( ! class_exists( 'Automattic\\WooCommerce\\Utilities\\FeaturesUtil' ) ) {
			return;
		}

		Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
	}
);

\ASDLabs\TVXWooChangeLog\Core\Plugin::boot( __FILE__ );

register_activation_hook( __FILE__, array( '\ASDLabs\TVXWooChangeLog\Core\Plugin', 'activate' ) );
register_deactivation_hook( __FILE__, array( '\ASDLabs\TVXWooChangeLog\Core\Plugin', 'deactivate' ) );
