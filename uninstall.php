<?php

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

require_once __DIR__ . '/src/Core/Autoloader.php';

$autoloader = new \ASDLabs\TVXWooChangeLog\Core\Autoloader( __DIR__ . '/src/' );
$autoloader->register();

\ASDLabs\TVXWooChangeLog\Core\CapabilityManager::uninstall();

delete_option( \ASDLabs\TVXWooChangeLog\Core\CapabilityManager::OPTION_VERSION );
delete_option( \ASDLabs\TVXWooChangeLog\Core\SchemaInstaller::OPTION_SCHEMA_VERSION );
delete_option( \ASDLabs\TVXWooChangeLog\StockManagerCompat\Detector::OPTION_STATE );
delete_option( \ASDLabs\TVXWooChangeLog\StockManagerCompat\Detector::OPTION_IMPORT_STATE );
delete_option( \ASDLabs\TVXWooChangeLog\StockManagerCompat\Detector::OPTION_BRIDGE_DISABLED );

if ( defined( 'TVX_WCL_REMOVE_DATA_ON_UNINSTALL' ) && TVX_WCL_REMOVE_DATA_ON_UNINSTALL ) {
	global $wpdb;

	foreach ( \ASDLabs\TVXWooChangeLog\Core\Tables::all() as $table_name ) {
		$table_name = preg_replace( '/[^A-Za-z0-9_]/', '', (string) $table_name );
		if ( '' === $table_name ) {
			continue;
		}

		$wpdb->query( "DROP TABLE IF EXISTS {$table_name}" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
	}
}
