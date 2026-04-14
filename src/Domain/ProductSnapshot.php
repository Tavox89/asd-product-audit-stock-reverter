<?php

namespace ASDLabs\TVXWooChangeLog\Domain;

final class ProductSnapshot {
	public static function from_post_id( $post_id ) {
		$post_id   = absint( $post_id );
		$post_type = get_post_type( $post_id );

		if ( ! in_array( $post_type, array( 'product', 'product_variation' ), true ) ) {
			return array();
		}

		$product = function_exists( 'wc_get_product' ) ? wc_get_product( $post_id ) : null;
		$name    = '';
		$sku     = '';
		$type    = ( 'product_variation' === $post_type ) ? 'variation' : 'product';

		if ( $product && is_object( $product ) ) {
			if ( method_exists( $product, 'get_formatted_name' ) ) {
				$name = (string) $product->get_formatted_name();
			} elseif ( method_exists( $product, 'get_name' ) ) {
				$name = (string) $product->get_name();
			}

			$sku = method_exists( $product, 'get_sku' ) ? (string) $product->get_sku() : '';
			$type = method_exists( $product, 'get_type' ) ? (string) $product->get_type() : $type;
		}

		if ( '' === $name ) {
			$name = (string) get_the_title( $post_id );
		}

		if ( '' === $sku ) {
			$sku = (string) get_post_meta( $post_id, '_sku', true );
		}

		$parent_id    = ( 'product_variation' === $post_type ) ? absint( wp_get_post_parent_id( $post_id ) ) : 0;
		$variation_id = ( 'product_variation' === $post_type ) ? $post_id : 0;

		return array(
			'product_id'   => $post_id,
			'variation_id' => $variation_id,
			'parent_id'    => $parent_id,
			'product_type' => sanitize_key( $type ),
			'sku'          => sanitize_text_field( $sku ),
			'product_name' => sanitize_text_field( $name ),
		);
	}
}
