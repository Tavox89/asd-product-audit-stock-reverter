<?php

namespace ASDLabs\TVXWooChangeLog\Admin;

use ASDLabs\TVXWooChangeLog\Core\CapabilityManager;
use ASDLabs\TVXWooChangeLog\Core\Contracts\Module;

final class Menu implements Module {
	private $change_log_page;
	private $revert_inventory_page;

	public function __construct() {
		$this->change_log_page        = new ChangeLogPage();
		$this->revert_inventory_page  = new RevertInventoryPage();
	}

	public function register() {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		$this->change_log_page->register();
		$this->revert_inventory_page->register();
	}

	public function register_menu() {
		if ( CapabilityManager::current_user_can_view_log() ) {
			add_submenu_page(
				'woocommerce',
				'TVX Change Log',
				'TVX Change Log',
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
	}
}
