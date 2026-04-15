<?php

namespace ASDLabs\TVXWooChangeLog\Admin;

use ASDLabs\TVXWooChangeLog\Core\CapabilityManager;
use ASDLabs\TVXWooChangeLog\Core\Contracts\Module;

final class Menu implements Module {
	private $change_log_page;
	private $revert_inventory_page;
	private $stock_manager_compat_page;

	public function __construct() {
		$this->change_log_page          = new ChangeLogPage();
		$this->revert_inventory_page    = new RevertInventoryPage();
		$this->stock_manager_compat_page = new StockManagerCompatPage();
	}

	public function register() {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		$this->change_log_page->register();
		$this->revert_inventory_page->register();
		$this->stock_manager_compat_page->register();
	}

	public function register_menu() {
		if ( CapabilityManager::current_user_can_view_log() ) {
			add_submenu_page(
				'woocommerce',
				'ASD Labs Audit Log',
				'ASD Labs Audit Log',
				CapabilityManager::VIEW_CHANGE_LOG,
				ChangeLogPage::SLUG,
				array( $this->change_log_page, 'render' )
			);
		}

		if ( CapabilityManager::current_user_can_revert_inventory() ) {
			add_submenu_page(
				'woocommerce',
				'Revertir inventario',
				'Revertir inventario',
				CapabilityManager::REVERT_INVENTORY,
				RevertInventoryPage::SLUG,
				array( $this->revert_inventory_page, 'render' )
			);
		}

		if ( CapabilityManager::current_user_can_manage_configuration() ) {
			add_submenu_page(
				'woocommerce',
				'Compatibilidad Stock Manager',
				'Compatibilidad Stock Manager',
				CapabilityManager::MANAGE_CONFIGURATION,
				StockManagerCompatPage::SLUG,
				array( $this->stock_manager_compat_page, 'render' )
			);
		}
	}

	public function enqueue_assets( $hook_suffix ) {
		unset( $hook_suffix );

		$page = sanitize_key( (string) ( $_GET['page'] ?? '' ) );
		if ( ! in_array( $page, array( ChangeLogPage::SLUG, RevertInventoryPage::SLUG, StockManagerCompatPage::SLUG ), true ) ) {
			return;
		}

		wp_enqueue_style(
			'tvx-wcl-admin',
			TVX_WCL_URL . 'assets/css/admin.css',
			array(),
			TVX_WCL_VERSION
		);
	}
}
