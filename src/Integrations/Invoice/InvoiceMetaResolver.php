<?php

namespace ASDLabs\TVXWooChangeLog\Integrations\Invoice;

final class InvoiceMetaResolver {
	public function get_search_meta_keys() {
		$keys = array(
			'_invoice_number',
			'_order_number',
			'_order_number_formatted',
			'_op_order_number',
			'_op_order_number_format',
			'_openpos_order_number',
			'_op_wc_custom_order_number',
			'_op_wc_custom_order_number_formatted',
			'_op_source_order_number',
			'_pos_order_no',
			'_op_receipt_number',
			'_wpos_order_number',
			'_billing_document',
		);

		$keys = apply_filters( 'tvx_wcl_invoice_meta_keys', $keys );

		return array_values( array_unique( array_filter( array_map( 'sanitize_key', (array) $keys ) ) ) );
	}

	public function resolve_invoice_number( $order ) {
		if ( ! $order || ! is_object( $order ) || ! method_exists( $order, 'get_meta' ) ) {
			return '';
		}

		foreach ( $this->get_search_meta_keys() as $meta_key ) {
			$value = sanitize_text_field( (string) $order->get_meta( $meta_key, true ) );
			if ( '' !== $value ) {
				return $value;
			}
		}

		return method_exists( $order, 'get_order_number' ) ? sanitize_text_field( (string) $order->get_order_number() ) : '';
	}

	public function get_detected_strategy_notes() {
		return array(
			'Búsqueda prioriza order ID y order number nativo de WooCommerce.',
			'Metas locales observadas para OpenPOS y secuenciales operativos: _order_number, _order_number_formatted, _op_order_number y variantes.',
			'Alias adicionales detectados localmente: _invoice_number, _pos_order_no, _op_receipt_number, _billing_document.',
			'No se verificó una integración WPO/WCPDF específica en este árbol; la estrategia queda basada en metas observadas localmente.',
		);
	}

	public function resolve_order_edit_url( $order ) {
		if ( ! $order || ! is_object( $order ) ) {
			return '';
		}

		if ( method_exists( $order, 'get_edit_order_url' ) ) {
			return (string) $order->get_edit_order_url();
		}

		$order_id = method_exists( $order, 'get_id' ) ? (int) $order->get_id() : 0;
		if ( $order_id <= 0 ) {
			return '';
		}

		return admin_url( 'post.php?post=' . $order_id . '&action=edit' );
	}
}
