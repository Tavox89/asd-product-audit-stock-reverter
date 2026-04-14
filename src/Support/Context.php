<?php

namespace ASDLabs\TVXWooChangeLog\Support;

final class Context {
	private static $pending_order_context = array();

	public function remember_order_reduce_context( $can_reduce, $order ) {
		if ( $can_reduce ) {
			self::$pending_order_context = $this->order_context_from_order( $order, 'order_reduce' );
		}

		return $can_reduce;
	}

	public function remember_order_restore_context( $can_restore, $order ) {
		if ( $can_restore ) {
			self::$pending_order_context = $this->order_context_from_order( $order, 'order_restore' );
		}

		return $can_restore;
	}

	public function clear_pending_order_context( $order = null ) {
		unset( $order );
		self::$pending_order_context = array();
	}

	public function detect( $post_id, $field_key, $meta_key = '' ) {
		$post_id   = absint( $post_id );
		$field_key = sanitize_key( (string) $field_key );
		$meta_key  = sanitize_key( (string) $meta_key );

		$user       = wp_get_current_user();
		$user_id    = $user instanceof \WP_User ? (int) $user->ID : 0;
		$user_name  = ( $user_id > 0 ) ? $user->display_name : '';
		$runtime    = RuntimeContext::current();
		$backtrace  = debug_backtrace( DEBUG_BACKTRACE_IGNORE_ARGS, 24 );
		$request    = $this->request_markers();
		$meta       = array();
		$source     = 'unknown';

		if ( ! empty( $runtime['source_context'] ) ) {
			$source = sanitize_key( (string) $runtime['source_context'] );
			$meta   = $this->clean_meta(
				array(
					'order_id'      => absint( $runtime['order_id'] ?? 0 ),
					'order_number'  => sanitize_text_field( (string) ( $runtime['order_number'] ?? '' ) ),
					'invoice_number'=> sanitize_text_field( (string) ( $runtime['invoice_number'] ?? '' ) ),
					'detector'      => sanitize_key( (string) ( $runtime['detector'] ?? '' ) ),
				)
			);
		} elseif ( 'stock' === $field_key && ! empty( self::$pending_order_context ) ) {
			$source = sanitize_key( (string) self::$pending_order_context['source_context'] );
			$meta   = $this->clean_meta( self::$pending_order_context );
		} elseif ( $this->backtrace_has_function( $backtrace, array( 'wc_reduce_stock_levels' ) ) ) {
			$source = 'order_reduce';
		} elseif ( $this->backtrace_has_function( $backtrace, array( 'wc_increase_stock_levels', 'wc_maybe_increase_stock_levels', 'wc_restock_refunded_items' ) ) ) {
			$source = 'order_restore';
		} elseif ( $this->is_stock_manager_request( $request, $backtrace ) ) {
			$source = 'stock_manager';
		} elseif ( $this->is_rest_request( $request, $backtrace ) ) {
			$source = 'rest_api';
		} elseif ( $this->is_import_request( $request, $backtrace ) ) {
			$source = 'import';
		} elseif ( $this->is_quick_edit_request( $request ) ) {
			$source = 'quick_edit';
		} elseif ( $this->is_bulk_edit_request( $request ) ) {
			$source = 'bulk_edit';
		} elseif ( $this->is_manual_edit_request( $request ) ) {
			$source = 'manual_edit';
		}

		$actor_type = 'system';
		if ( in_array( $source, array( 'order_reduce', 'order_restore' ), true ) ) {
			$actor_type = 'order';
		} elseif ( 'rest_api' === $source ) {
			$actor_type = 'api';
		} elseif ( 'import' === $source ) {
			$actor_type = 'import';
		} elseif ( $user_id > 0 ) {
			$actor_type = 'user';
		} elseif ( 'stock_manager' === $source ) {
			$actor_type = 'plugin';
		}

		$meta = array_merge(
			$meta,
			$this->clean_meta(
				array(
					'request_action' => $request['action'],
					'request_page'   => $request['page'],
					'meta_key'       => $meta_key,
					'post_id'        => $post_id,
				)
			)
		);

		return array(
			'user_id'            => $user_id,
			'user_display_name'  => sanitize_text_field( $user_name ),
			'actor_type'         => $actor_type,
			'source_context'     => $source,
			'request_fingerprint'=> $this->build_request_fingerprint( $source, $post_id, $field_key, $request, $meta ),
			'meta'               => $meta,
		);
	}

	private function order_context_from_order( $order, $source_context ) {
		if ( ! $order || ! is_object( $order ) || ! method_exists( $order, 'get_id' ) ) {
			return array();
		}

		return $this->clean_meta(
			array(
				'source_context' => sanitize_key( (string) $source_context ),
				'order_id'       => (int) $order->get_id(),
				'order_number'   => method_exists( $order, 'get_order_number' ) ? sanitize_text_field( (string) $order->get_order_number() ) : '',
				'order_status'   => method_exists( $order, 'get_status' ) ? sanitize_key( (string) $order->get_status() ) : '',
			)
		);
	}

	private function request_markers() {
		return array(
			'action' => sanitize_key( (string) ( $_REQUEST['action'] ?? '' ) ),
			'page'   => sanitize_key( (string) ( $_REQUEST['page'] ?? '' ) ),
			'uri'    => sanitize_text_field( (string) ( $_SERVER['REQUEST_URI'] ?? '' ) ),
			'method' => sanitize_key( (string) ( $_SERVER['REQUEST_METHOD'] ?? '' ) ),
		);
	}

	private function is_quick_edit_request( array $request ) {
		return wp_doing_ajax() && 'inline-save' === $request['action'];
	}

	private function is_bulk_edit_request( array $request ) {
		return ! empty( $_REQUEST['bulk_edit'] ) || ( isset( $_REQUEST['bulk_edit'] ) && 'bulk' === sanitize_key( (string) $_REQUEST['bulk_edit'] ) );
	}

	private function is_manual_edit_request( array $request ) {
		if ( ! is_admin() ) {
			return false;
		}

		if ( isset( $_POST['post_ID'] ) ) {
			return true;
		}

		return false !== strpos( $request['uri'], 'post.php' );
	}

	private function is_rest_request( array $request, array $backtrace ) {
		if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
			return true;
		}

		if ( false !== strpos( $request['uri'], '/wp-json/' ) ) {
			return true;
		}

		return $this->backtrace_has_any( $backtrace, array( 'WP_REST_', 'Automattic\\WooCommerce\\RestApi', 'WC_REST_' ) );
	}

	private function is_import_request( array $request, array $backtrace ) {
		if ( in_array( $request['action'], array( 'woocommerce_do_ajax_product_import', 'pmxi_after_post_import', 'pmxi_saved_post' ), true ) ) {
			return true;
		}

		if ( false !== strpos( $request['uri'], 'product_importer' ) ) {
			return true;
		}

		return $this->backtrace_has_any( $backtrace, array( 'Importer', 'PMXI', 'WC_Product_CSV_Importer' ) );
	}

	private function is_stock_manager_request( array $request, array $backtrace ) {
		if ( false !== strpos( $request['page'], 'stock-manager' ) ) {
			return true;
		}

		return $this->backtrace_has_any( $backtrace, array( 'Stock_Manager', 'WSM_Save' ) );
	}

	private function backtrace_has_function( array $backtrace, array $functions ) {
		foreach ( $backtrace as $frame ) {
			$current = (string) ( $frame['function'] ?? '' );
			if ( in_array( $current, $functions, true ) ) {
				return true;
			}
		}

		return false;
	}

	private function backtrace_has_any( array $backtrace, array $needles ) {
		foreach ( $backtrace as $frame ) {
			$class    = (string) ( $frame['class'] ?? '' );
			$function = (string) ( $frame['function'] ?? '' );
			$current  = $class . '::' . $function;

			foreach ( $needles as $needle ) {
				if ( '' !== $needle && false !== stripos( $current, $needle ) ) {
					return true;
				}
			}
		}

		return false;
	}

	private function build_request_fingerprint( $source, $post_id, $field_key, array $request, array $meta ) {
		static $cache = array();

		$key = implode(
			'|',
			array(
				$source,
				$post_id,
				$field_key,
				$request['action'],
				$request['page'],
				$request['uri'],
				get_current_user_id(),
			)
		);

		if ( isset( $cache[ $key ] ) ) {
			return $cache[ $key ];
		}

		$cache[ $key ] = hash(
			'sha256',
			wp_json_encode(
				array(
					'source' => $source,
					'post'   => $post_id,
					'field'  => $field_key,
					'action' => $request['action'],
					'page'   => $request['page'],
					'uri'    => $request['uri'],
					'user'   => get_current_user_id(),
					'meta'   => $meta,
				)
			)
		);

		return $cache[ $key ];
	}

	private function clean_meta( array $meta ) {
		$clean = array();

		foreach ( $meta as $key => $value ) {
			if ( '' === $value || null === $value || array() === $value ) {
				continue;
			}

			$clean[ sanitize_key( (string) $key ) ] = is_scalar( $value )
				? sanitize_text_field( (string) $value )
				: wp_json_encode( $value );
		}

		return $clean;
	}
}
