<?php

namespace ASDLabs\TVXWooChangeLog\Integrations\Invoice;

final class OrderSearchService {
	private $invoice_meta_resolver;

	public function __construct( InvoiceMetaResolver $invoice_meta_resolver ) {
		$this->invoice_meta_resolver = $invoice_meta_resolver;
	}

	public function search( $raw_query, $limit = 20 ) {
		if ( ! function_exists( 'wc_get_orders' ) || ! function_exists( 'wc_get_order' ) ) {
			return array();
		}

		$query = sanitize_text_field( (string) $raw_query );
		$query = trim( $query );
		$limit = max( 1, min( 50, absint( $limit ) ) );

		if ( '' === $query ) {
			return array();
		}

		$order_ids = array();
		$plain_id  = ltrim( $query, '#' );

		if ( ctype_digit( $plain_id ) ) {
			$order = wc_get_order( absint( $plain_id ) );
			if ( $order ) {
				$order_ids[] = (int) $order->get_id();
			}
		}

		$exact_ids = $this->query_by_meta( $query, '=', $limit );
		$order_ids = array_merge( $order_ids, $exact_ids );

		if ( count( $order_ids ) < $limit ) {
			$like_ids  = $this->query_by_meta( $query, 'LIKE', $limit );
			$order_ids = array_merge( $order_ids, $like_ids );
		}

		$order_ids = array_values( array_unique( array_filter( array_map( 'absint', $order_ids ) ) ) );
		$order_ids = array_slice( $order_ids, 0, $limit );

		$results = array();
		foreach ( $order_ids as $order_id ) {
			$order = wc_get_order( $order_id );
			if ( ! $order ) {
				continue;
			}

			$results[] = array(
				'order_id'       => (int) $order->get_id(),
				'order_number'   => sanitize_text_field( (string) $order->get_order_number() ),
				'invoice_number' => sanitize_text_field( (string) $this->invoice_meta_resolver->resolve_invoice_number( $order ) ),
				'status'         => sanitize_text_field( (string) $order->get_status() ),
				'customer'       => sanitize_text_field( trim( $order->get_formatted_billing_full_name() ) ),
				'edit_url'       => admin_url( 'post.php?post=' . (int) $order->get_id() . '&action=edit' ),
			);
		}

		return $results;
	}

	public function all_statuses() {
		$statuses = function_exists( 'wc_get_order_statuses' ) ? array_keys( wc_get_order_statuses() ) : array();

		return array_values(
			array_unique(
				array_merge(
					$statuses,
					array( 'draft', 'auto-draft', 'trash' )
				)
			)
		);
	}

	private function query_by_meta( $query, $compare, $limit ) {
		$clauses = array( 'relation' => 'OR' );

		foreach ( $this->invoice_meta_resolver->get_search_meta_keys() as $meta_key ) {
			$clauses[] = array(
				'key'     => $meta_key,
				'value'   => $query,
				'compare' => $compare,
			);
		}

		$order_ids = wc_get_orders(
			array(
				'type'       => 'shop_order',
				'status'     => $this->all_statuses(),
				'limit'      => max( 1, min( 50, absint( $limit ) ) ),
				'return'     => 'ids',
				'meta_query' => $clauses,
			)
		);

		return is_array( $order_ids ) ? $order_ids : array();
	}
}
