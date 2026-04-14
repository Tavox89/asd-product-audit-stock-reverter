<?php

namespace ASDLabs\TVXWooChangeLog\Integrations\Invoice;

final class InvoiceMetaResolver {
	public function get_search_meta_keys() {
		return array(
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
			'Integración local detectada con secuenciales OpenPOS/Woo por _order_number y _order_number_formatted.',
			'Integración local detectada con OpenPOS por _op_order_number, _op_order_number_format, _op_wc_custom_order_number y variantes.',
			'Referencia local adicional a _invoice_number y aliases operativos desde OP Guard.',
			'No se encontró una meta específica de número de factura expuesta por WPO/WCPDF en este árbol local.',
		);
	}
}
