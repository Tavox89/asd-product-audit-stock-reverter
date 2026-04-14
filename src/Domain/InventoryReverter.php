<?php

namespace ASDLabs\TVXWooChangeLog\Domain;

use ASDLabs\TVXWooChangeLog\DB\Repositories\ReversionRepository;
use ASDLabs\TVXWooChangeLog\Integrations\Invoice\InvoiceMetaResolver;
use ASDLabs\TVXWooChangeLog\Support\RuntimeContext;
use ASDLabs\TVXWooChangeLog\Support\Time;
use WP_Error;

final class InventoryReverter {
	private $eligibility_checker;
	private $lock_manager;
	private $reversion_repository;
	private $invoice_meta_resolver;

	public function __construct( EligibilityChecker $eligibility_checker, LockManager $lock_manager, ReversionRepository $reversion_repository, InvoiceMetaResolver $invoice_meta_resolver ) {
		$this->eligibility_checker  = $eligibility_checker;
		$this->lock_manager         = $lock_manager;
		$this->reversion_repository = $reversion_repository;
		$this->invoice_meta_resolver = $invoice_meta_resolver;
	}

	public function execute( $order_id, $executed_by_user_id = 0 ) {
		if ( ! function_exists( 'wc_get_order' ) || ! function_exists( 'wc_update_product_stock' ) ) {
			return new WP_Error( 'tvx_wcl_missing_woocommerce', 'WooCommerce no está disponible en runtime.' );
		}

		$order_id = absint( $order_id );
		$order    = wc_get_order( $order_id );
		if ( ! $order ) {
			return new WP_Error( 'tvx_wcl_invalid_order', 'Pedido no encontrado.' );
		}

		$lock_key = $this->lock_manager->build_order_lock_key( $order_id );
		if ( ! $this->lock_manager->acquire( $lock_key ) ) {
			$this->record_attempt( $order, $executed_by_user_id, $lock_key, 'locked', array(), array(), array( 'message' => 'Lock activo para este pedido.' ) );
			return new WP_Error( 'tvx_wcl_order_locked', 'Ya existe una reversión en curso para este pedido.' );
		}

		try {
			$preview = $this->eligibility_checker->build_preview( $order );
			if ( empty( $preview['eligible'] ) ) {
				$this->record_attempt( $order, $executed_by_user_id, $lock_key, 'blocked', array(), array(), array( 'blocking_reasons' => $preview['blocking_reasons'] ?? array() ) );
				return new WP_Error( 'tvx_wcl_ineligible_order', implode( ' ', (array) ( $preview['blocking_reasons'] ?? array( 'Pedido no elegible.' ) ) ) );
			}

			$restored = array();
			$skipped  = array();
			$user     = get_user_by( 'id', absint( $executed_by_user_id ) );
			$user_name = $user instanceof \WP_User ? $user->display_name : 'system';

			$runtime_context = array(
				'source_context' => 'arbitrary_revert',
				'actor_type'     => $executed_by_user_id > 0 ? 'user' : 'system',
				'order_id'       => $order_id,
				'order_number'   => (string) $order->get_order_number(),
				'invoice_number' => (string) $this->invoice_meta_resolver->resolve_invoice_number( $order ),
				'detector'       => 'inventory_reverter',
			);

			RuntimeContext::run(
				$runtime_context,
				function () use ( $order, $preview, &$restored, &$skipped ) {
					foreach ( (array) $preview['items'] as $item_preview ) {
						$item = $order->get_item( (int) $item_preview['item_id'] );
						if ( ! $item instanceof \WC_Order_Item_Product ) {
							continue;
						}

						$reduced_qty = (float) $item->get_meta( '_reduced_stock', true );
						$product     = $item->get_product();

						if ( $reduced_qty <= 0 ) {
							$skipped[] = $this->build_item_summary( $item_preview, 'Sin _reduced_stock positivo' );
							continue;
						}

						if ( ! $product ) {
							$skipped[] = $this->build_item_summary( $item_preview, 'Producto no encontrado' );
							continue;
						}

						if ( ! $product->managing_stock() ) {
							$skipped[] = $this->build_item_summary( $item_preview, 'Producto sin manage_stock' );
							continue;
						}

						$before_stock = method_exists( $product, 'get_stock_quantity' ) ? $product->get_stock_quantity( 'edit' ) : null;
						$after_stock  = wc_update_product_stock( $product, $reduced_qty, 'increase' );

						$item->delete_meta_data( '_reduced_stock' );
						if ( '' !== (string) $item->get_meta( '_op_reduced_stock', true ) ) {
							$item->delete_meta_data( '_op_reduced_stock' );
						}
						$item->save();

						$restored[] = array(
							'item_id'       => (int) $item->get_id(),
							'product_id'    => (int) $product->get_id(),
							'variation_id'  => method_exists( $item, 'get_variation_id' ) ? (int) $item->get_variation_id() : 0,
							'name'          => sanitize_text_field( (string) $item->get_name() ),
							'sku'           => sanitize_text_field( (string) $product->get_sku() ),
							'restored_qty'  => $reduced_qty,
							'stock_before'  => is_numeric( $before_stock ) ? (float) $before_stock : null,
							'stock_after'   => is_numeric( $after_stock ) ? (float) $after_stock : null,
						);
					}
				}
			);

			if ( empty( $restored ) ) {
				$this->record_attempt( $order, $executed_by_user_id, $lock_key, 'blocked', array(), $skipped, array( 'message' => 'No hubo ítems restaurados.' ) );
				return new WP_Error( 'tvx_wcl_no_restored_items', 'La reversión fue bloqueada porque no hubo ítems restaurables.' );
			}

			$this->clear_order_reduction_flags( $order, $executed_by_user_id );

			$note = $this->build_final_note( $user_name, $restored, $skipped );
			$order->add_order_note( $note, false, true );
			$order->save();

			$reversion_id = $this->record_attempt( $order, $executed_by_user_id, $lock_key, 'success', $restored, $skipped, array( 'note' => $note ) );

			return array(
				'reversion_id' => $reversion_id,
				'order_id'     => $order_id,
				'restored'     => $restored,
				'skipped'      => $skipped,
				'note'         => $note,
			);
		} catch ( \Throwable $throwable ) {
			$this->record_attempt( $order, $executed_by_user_id, $lock_key, 'failed', array(), array(), array( 'message' => $throwable->getMessage() ) );
			return new WP_Error( 'tvx_wcl_revert_failed', $throwable->getMessage() );
		} finally {
			$this->lock_manager->release( $lock_key );
		}
	}

	private function clear_order_reduction_flags( $order, $executed_by_user_id ) {
		if ( method_exists( $order, 'get_data_store' ) ) {
			$data_store = $order->get_data_store();
			if ( $data_store && method_exists( $data_store, 'set_stock_reduced' ) ) {
				$data_store->set_stock_reduced( $order->get_id(), false );
			}
		}

		$order->delete_meta_data( '_order_stock_reduced' );
		$order->update_meta_data( '_tvx_wcl_arbitrary_reverted_at_utc', Time::now_utc() );
		$order->update_meta_data( '_tvx_wcl_arbitrary_reverted_by', absint( $executed_by_user_id ) );

		if ( $order->get_meta( '_csfx_lb_stock_reduced_sync', true ) ) {
			$order->update_meta_data( '_csfx_lb_stock_reverted', current_time( 'mysql' ) );
			$order->delete_meta_data( '_csfx_lb_restock_via_woo' );
		}
	}

	private function build_final_note( $user_name, array $restored, array $skipped ) {
		$parts = array();

		foreach ( array_slice( $restored, 0, 8 ) as $item ) {
			$parts[] = sprintf( '%s +%s', $item['name'], $item['restored_qty'] );
		}

		if ( count( $restored ) > 8 ) {
			$parts[] = sprintf( '%d más', count( $restored ) - 8 );
		}

		$note = sprintf(
			'Reversión arbitraria de stock por %1$s el %2$s. Restaurados: %3$s.',
			sanitize_text_field( (string) $user_name ),
			Time::site_now(),
			implode( ', ', $parts )
		);

		if ( ! empty( $skipped ) ) {
			$note .= sprintf( ' Omitidos: %d.', count( $skipped ) );
		}

		return $note;
	}

	private function record_attempt( $order, $executed_by_user_id, $lock_key, $result_status, array $restored, array $skipped, array $context ) {
		$user      = get_user_by( 'id', absint( $executed_by_user_id ) );
		$user_name = $user instanceof \WP_User ? $user->display_name : 'system';

		return $this->reversion_repository->insert(
			array(
				'order_id'             => (int) $order->get_id(),
				'order_number'         => sanitize_text_field( (string) $order->get_order_number() ),
				'invoice_number'       => sanitize_text_field( (string) $this->invoice_meta_resolver->resolve_invoice_number( $order ) ),
				'executed_by_user_id'  => absint( $executed_by_user_id ),
				'executed_by_name'     => sanitize_text_field( (string) $user_name ),
				'executed_at_utc'      => Time::now_utc(),
				'reason_label'         => 'arbitrary_revert',
				'lock_key'             => sanitize_key( (string) $lock_key ),
				'result_status'        => sanitize_key( (string) $result_status ),
				'items_restored_json'  => ! empty( $restored ) ? wp_json_encode( $restored ) : null,
				'items_skipped_json'   => ! empty( $skipped ) ? wp_json_encode( $skipped ) : null,
				'context_json'         => ! empty( $context ) ? wp_json_encode( $context ) : null,
			)
		);
	}

	private function build_item_summary( array $item_preview, $reason ) {
		return array(
			'item_id'      => (int) ( $item_preview['item_id'] ?? 0 ),
			'product_id'   => (int) ( $item_preview['product_id'] ?? 0 ),
			'variation_id' => (int) ( $item_preview['variation_id'] ?? 0 ),
			'name'         => sanitize_text_field( (string) ( $item_preview['name'] ?? '' ) ),
			'sku'          => sanitize_text_field( (string) ( $item_preview['sku'] ?? '' ) ),
			'reason'       => sanitize_text_field( (string) $reason ),
		);
	}
}
