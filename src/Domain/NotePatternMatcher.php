<?php

namespace ASDLabs\TVXWooChangeLog\Domain;

final class NotePatternMatcher {
	public function get_patterns() {
		$patterns = array(
			'/inventario\s+descontado/i',
			'/stock\s+(?:was\s+)?reduced/i',
			'/reduced\s+stock/i',
			'/order\s+stock\s+reduced/i',
			'/inventory\s+reduced/i',
		);

		$patterns = apply_filters( 'tvx_wcl_stock_reduction_note_patterns', $patterns );

		if ( ! is_array( $patterns ) ) {
			return array();
		}

		return array_values( array_filter( array_map( 'strval', $patterns ) ) );
	}

	public function find_matching_notes( $order ) {
		if ( ! $order || ! function_exists( 'wc_get_order_notes' ) ) {
			return array();
		}

		$notes   = wc_get_order_notes( array( 'order_id' => $order->get_id() ) );
		$matches = array();

		foreach ( (array) $notes as $note ) {
			$content = $this->extract_note_content( $note );
			if ( '' === $content ) {
				continue;
			}

			foreach ( $this->get_patterns() as $pattern ) {
				if ( @preg_match( $pattern, $content ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
					$matches[] = array(
						'id'      => $this->extract_note_id( $note ),
						'content' => wp_trim_words( wp_strip_all_tags( $content ), 18, '…' ),
					);
					break;
				}
			}
		}

		return $matches;
	}

	private function extract_note_content( $note ) {
		if ( is_object( $note ) ) {
			if ( method_exists( $note, 'get_content' ) ) {
				return (string) $note->get_content();
			}

			if ( isset( $note->content ) ) {
				return (string) $note->content;
			}
		}

		return '';
	}

	private function extract_note_id( $note ) {
		if ( is_object( $note ) ) {
			if ( method_exists( $note, 'get_id' ) ) {
				return (int) $note->get_id();
			}

			if ( isset( $note->id ) ) {
				return (int) $note->id;
			}
		}

		return 0;
	}
}
