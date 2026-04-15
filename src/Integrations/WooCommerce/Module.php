<?php

namespace ASDLabs\TVXWooChangeLog\Integrations\WooCommerce;

use ASDLabs\TVXWooChangeLog\Core\Contracts\Module as ModuleContract;
use ASDLabs\TVXWooChangeLog\DB\Repositories\ChangeLogRepository;
use ASDLabs\TVXWooChangeLog\Domain\ChangeDetector;
use ASDLabs\TVXWooChangeLog\Domain\ChangeLogger;
use ASDLabs\TVXWooChangeLog\Integrations\YITH\CostMetaResolver;
use ASDLabs\TVXWooChangeLog\StockManagerCompat\BridgeWriter;
use ASDLabs\TVXWooChangeLog\Support\Context;

final class Module implements ModuleContract {
	private $detector;
	private $context;

	public function __construct() {
		$this->context  = new Context();
		$this->detector = new ChangeDetector(
			new ChangeLogger( new ChangeLogRepository() ),
			new CostMetaResolver(),
			$this->context,
			new BridgeWriter()
		);
	}

	public function register() {
		add_filter( 'update_post_metadata', array( $this->detector, 'capture_before_update' ), 10, 5 );
		add_filter( 'add_post_metadata', array( $this->detector, 'capture_before_add' ), 10, 5 );
		add_action( 'updated_post_meta', array( $this->detector, 'handle_updated_meta' ), 10, 4 );
		add_action( 'added_post_meta', array( $this->detector, 'handle_added_meta' ), 10, 4 );
		add_action( 'woocommerce_product_before_set_stock', array( $this->detector, 'capture_before_stock_change' ), 10, 1 );
		add_action( 'woocommerce_variation_before_set_stock', array( $this->detector, 'capture_before_stock_change' ), 10, 1 );
		add_action( 'woocommerce_product_set_stock', array( $this->detector, 'handle_stock_changed' ), 10, 1 );
		add_action( 'woocommerce_variation_set_stock', array( $this->detector, 'handle_stock_changed' ), 10, 1 );

		add_filter( 'woocommerce_can_reduce_order_stock', array( $this->context, 'remember_order_reduce_context' ), 1, 2 );
		add_filter( 'woocommerce_can_restore_order_stock', array( $this->context, 'remember_order_restore_context' ), 1, 2 );
		add_action( 'woocommerce_reduce_order_stock', array( $this->context, 'clear_pending_order_context' ), 999, 1 );
		add_action( 'woocommerce_restore_order_stock', array( $this->context, 'clear_pending_order_context' ), 999, 1 );
	}
}
