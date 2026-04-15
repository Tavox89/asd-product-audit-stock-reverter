<?php

namespace ASDLabs\TVXWooChangeLog\Domain;

use ASDLabs\TVXWooChangeLog\DB\Repositories\ReversionRepository;
use ASDLabs\TVXWooChangeLog\Integrations\Invoice\InvoiceMetaResolver;

final class EligibilityChecker {
	private $note_matcher;
	private $invoice_meta_resolver;
	private $reversion_repository;

	public function __construct( NotePatternMatcher $note_matcher, InvoiceMetaResolver $invoice_meta_resolver, ReversionRepository $reversion_repository ) {
		$this->note_matcher          = $note_matcher;
		$this->invoice_meta_resolver = $invoice_meta_resolver;
		$this->reversion_repository  = $reversion_repository;
	}

	public function build_preview( $order ) {
		if ( ! $order || ! is_object( $order ) || ! method_exists( $order, 'get_id' ) ) {
			return array(
				'eligible'         => false,
				'blocking_reasons' => array( 'Pedido no válido.' ),
				'items'            => array(),
			);
		}

		$order_id               = (int) $order->get_id();
		$matched_notes          = $this->note_matcher->find_matching_notes( $order );
		$items                  = $this->collect_item_preview( $order );
		$positive_reduced_count = $this->count_positive_reduced_items( $items );
		$restorable_items       = $this->filter_items_by_key( $items, 'can_restore', true );
		$skipped_items          = $this->filter_items_by_key( $items, 'can_restore', false );
		$has_reduced_item_meta  = $positive_reduced_count > 0;
		$has_restorable_items   = ! empty( $restorable_items );
		$order_stock_reduced    = $this->order_has_stock_reduced_flag( $order );
		$already_reverted_meta  = sanitize_text_field( (string) $order->get_meta( '_tvx_wcl_arbitrary_reverted_at_utc', true ) );
		$partial_reverted_meta  = sanitize_text_field( (string) $order->get_meta( '_tvx_wcl_arbitrary_revert_partial_at_utc', true ) );
		$reversion_record       = $this->reversion_repository->find_latest_successful_by_order_id( $order_id );
		$latest_record          = $this->reversion_repository->find_latest_by_order_id( $order_id );
		$already_reverted       = '' !== $already_reverted_meta || ! empty( $reversion_record );
		$blocking_reasons       = array();

		if ( $already_reverted ) {
			$blocking_reasons[] = 'Este pedido ya fue revertido previamente por este módulo.';
		}

		if ( empty( $matched_notes ) ) {
			$blocking_reasons[] = 'No se encontró una nota/evidencia de descuento de inventario compatible con los patrones configurados.';
		}

		if ( ! $has_reduced_item_meta ) {
			$blocking_reasons[] = 'No hay metadata _reduced_stock positiva en las líneas del pedido. No se puede restaurar con seguridad.';
		}

		if ( ! $has_restorable_items ) {
			$blocking_reasons[] = 'No hay ítems elegibles para restaurar stock.';
		}

		return array(
			'order_id'            => $order_id,
			'order_number'        => method_exists( $order, 'get_order_number' ) ? (string) $order->get_order_number() : (string) $order_id,
			'invoice_number'      => $this->invoice_meta_resolver->resolve_invoice_number( $order ),
			'status'              => method_exists( $order, 'get_status' ) ? (string) $order->get_status() : '',
			'customer_label'      => $this->build_customer_label( $order ),
			'matched_notes'       => $matched_notes,
			'items'               => $items,
			'restorable_items'    => $restorable_items,
			'skipped_items'       => $skipped_items,
			'order_stock_reduced' => $order_stock_reduced,
			'already_reverted'    => $already_reverted,
			'partial_reverted'    => '' !== $partial_reverted_meta,
			'has_reduced_item_meta' => $has_reduced_item_meta,
			'has_restorable_items'  => $has_restorable_items,
			'note_evidence'       => array(
				'has_matching_note' => ! empty( $matched_notes ),
				'count'             => count( $matched_notes ),
				'notes'             => $matched_notes,
			),
			'line_evidence'       => array(
				'has_reduced_item_meta' => $has_reduced_item_meta,
				'positive_reduced_count'=> $positive_reduced_count,
				'restorable_count'      => count( $restorable_items ),
			),
			'order_flag_evidence' => array(
				'stock_reduced' => $order_stock_reduced,
			),
			'last_reversion'      => $latest_record,
			'eligible'            => empty( $blocking_reasons ),
			'blocking_reasons'    => $blocking_reasons,
		);
	}

	private function collect_item_preview( $order ) {
		$items = array();

		foreach ( $order->get_items() as $item_id => $item ) {
			if ( ! $item instanceof \WC_Order_Item_Product ) {
				continue;
			}

			$product      = $item->get_product();
			$reduced_qty  = (float) $item->get_meta( '_reduced_stock', true );
			$product_id   = $product ? (int) $product->get_id() : 0;
			$variation_id = method_exists( $item, 'get_variation_id' ) ? (int) $item->get_variation_id() : 0;
			$sku          = $product && method_exists( $product, 'get_sku' ) ? (string) $product->get_sku() : '';
			$name         = $product && method_exists( $product, 'get_name' ) ? (string) $product->get_name() : (string) $item->get_name();
			$managing     = $product && method_exists( $product, 'managing_stock' ) ? (bool) $product->managing_stock() : false;
			$exists       = (bool) $product;
			$skip_reason  = '';
			$current_stock = $product && method_exists( $product, 'get_stock_quantity' ) ? $product->get_stock_quantity( 'edit' ) : null;

			if ( $reduced_qty <= 0 ) {
				$skip_reason = 'Sin _reduced_stock positivo';
			} elseif ( ! $exists ) {
				$skip_reason = 'Producto ya no existe';
			} elseif ( ! $managing ) {
				$skip_reason = 'Producto sin manage_stock';
			}

			$items[] = array(
				'item_id'            => (int) $item_id,
				'product_id'         => $product_id,
				'variation_id'       => $variation_id,
				'name'               => sanitize_text_field( $name ),
				'sku'                => sanitize_text_field( $sku ),
				'ordered_qty'        => (float) $item->get_quantity(),
				'reduced_stock_qty'  => $reduced_qty,
				'current_stock_qty'  => is_numeric( $current_stock ) ? (float) $current_stock : null,
				'product_exists'     => $exists,
				'manage_stock'       => $managing,
				'has_op_reduced_stock' => '' !== (string) $item->get_meta( '_op_reduced_stock', true ),
				'can_restore'        => '' === $skip_reason,
				'skip_reason'        => $skip_reason,
			);
		}

		return $items;
	}

	private function count_positive_reduced_items( array $items ) {
		$count = 0;

		foreach ( $items as $item ) {
			if ( (float) ( $item['reduced_stock_qty'] ?? 0 ) > 0 ) {
				$count++;
			}
		}

		return $count;
	}

	private function filter_items_by_key( array $items, $key, $expected ) {
		$filtered = array();

		foreach ( $items as $item ) {
			if ( ( $item[ $key ] ?? null ) === $expected ) {
				$filtered[] = $item;
			}
		}

		return $filtered;
	}

	private function order_has_stock_reduced_flag( $order ) {
		if ( method_exists( $order, 'get_data_store' ) ) {
			$data_store = $order->get_data_store();
			if ( $data_store && method_exists( $data_store, 'get_stock_reduced' ) ) {
				return (bool) $data_store->get_stock_reduced( $order->get_id() );
			}
		}

		return (bool) $order->get_meta( '_order_stock_reduced', true );
	}

	private function build_customer_label( $order ) {
		$parts = array_filter(
			array(
				method_exists( $order, 'get_billing_first_name' ) ? (string) $order->get_billing_first_name() : '',
				method_exists( $order, 'get_billing_last_name' ) ? (string) $order->get_billing_last_name() : '',
			)
		);

		$label = trim( implode( ' ', $parts ) );
		if ( '' !== $label ) {
			return sanitize_text_field( $label );
		}

		return sanitize_text_field( (string) ( method_exists( $order, 'get_formatted_billing_full_name' ) ? $order->get_formatted_billing_full_name() : '' ) );
	}
}
