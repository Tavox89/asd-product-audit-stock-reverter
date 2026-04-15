<?php

namespace ASDLabs\TVXWooChangeLog\Admin\ListTables;

use ASDLabs\TVXWooChangeLog\DB\Repositories\ChangeLogRepository;
use ASDLabs\TVXWooChangeLog\Support\Time;
use WP_List_Table;

if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

final class ChangeLogListTable extends WP_List_Table {
	private $repository;
	private $filters;

	public function __construct( ChangeLogRepository $repository, array $filters ) {
		$this->repository = $repository;
		$this->filters    = $filters;

		parent::__construct(
			array(
				'singular' => 'tvx_change_log_entry',
				'plural'   => 'tvx_change_log_entries',
				'ajax'     => false,
			)
		);
	}

	public function prepare_items() {
		$per_page = 20;
		$paged    = max( 1, absint( $_GET['paged'] ?? 1 ) );
		$result   = $this->repository->get_paginated( $this->filters, $paged, $per_page );

		$this->items = $result['rows'];

		$this->_column_headers = array(
			$this->get_columns(),
			array(),
			$this->get_sortable_columns(),
			'date',
		);

		$this->set_pagination_args(
			array(
				'total_items' => (int) $result['total'],
				'per_page'    => $per_page,
				'total_pages' => (int) ceil( ( (int) $result['total'] ) / $per_page ),
			)
		);
	}

	public function get_columns() {
		return array(
			'date'           => 'Fecha',
			'product'        => 'Producto',
			'sku'            => 'SKU',
			'field_key'      => 'Campo',
			'old_value'      => 'Antes',
			'new_value'      => 'Después',
			'delta_value'    => 'Delta',
			'actor'          => 'Usuario / actor',
			'source_context' => 'Origen / contexto',
		);
	}

	public function get_sortable_columns() {
		return array();
	}

	public function no_items() {
		echo 'No hay cambios registrados para los filtros actuales.';
	}

	public function column_date( $item ) {
		$utc     = sanitize_text_field( (string) ( $item['created_at_utc'] ?? '' ) );
		$display = Time::utc_to_site_datetime( $utc );
		$is_legacy = ! empty( $item['import_flag'] );
		$source_system = sanitize_key( (string) ( $item['source_system'] ?? 'native' ) );

		$meta = array();
		if ( $is_legacy ) {
			$meta[] = $this->badge( 'legacy import', 'warning' );
		} elseif ( 'stock_manager' === $source_system ) {
			$meta[] = $this->badge( 'stock manager', 'info' );
		} else {
			$meta[] = $this->badge( 'native', 'neutral' );
		}

		return sprintf(
			'<strong>%1$s</strong><br /><small>UTC: %2$s</small><div style="margin-top:6px;">%3$s</div>',
			esc_html( $display ?: '—' ),
			esc_html( $utc ?: '—' ),
			implode( ' ', $meta )
		);
	}

	public function column_product( $item ) {
		$product_id   = absint( $item['product_id'] ?? 0 );
		$variation_id = absint( $item['variation_id'] ?? 0 );
		$parent_id    = absint( $item['parent_id'] ?? 0 );
		$name         = sanitize_text_field( (string) ( $item['product_name'] ?? '' ) );
		$edit_id      = $variation_id > 0 ? $variation_id : $product_id;
		$link         = $edit_id > 0 ? get_edit_post_link( $edit_id ) : '';
		$meta_bits    = array();
		$type         = sanitize_key( (string) ( $item['product_type'] ?? ( $variation_id > 0 ? 'variation' : 'product' ) ) );

		if ( $variation_id > 0 ) {
			$meta_bits[] = 'Variación #' . $variation_id;
		}

		if ( $parent_id > 0 ) {
			$meta_bits[] = 'Padre #' . $parent_id;
		}

		$label = $link
			? '<a href="' . esc_url( $link ) . '">' . esc_html( $name ?: 'Producto #' . $edit_id ) . '</a>'
			: esc_html( $name ?: 'Producto #' . $edit_id );

		if ( ! empty( $meta_bits ) ) {
			$label .= '<br /><small>' . esc_html( implode( ' · ', $meta_bits ) ) . '</small>';
		}

		return $label . '<div style="margin-top:6px;">' . $this->badge( $type, 'neutral' ) . '</div>';
	}

	public function column_sku( $item ) {
		$sku = sanitize_text_field( (string) ( $item['sku'] ?? '' ) );
		return '' !== $sku ? esc_html( $sku ) : '—';
	}

	public function column_field_key( $item ) {
		$labels = array(
			'stock'         => 'Stock',
			'regular_price' => 'Regular price',
			'yith_cost'     => 'Costo YITH',
		);

		$field = sanitize_key( (string) ( $item['field_key'] ?? '' ) );

		return $this->badge( $labels[ $field ] ?? $field, $this->field_tone( $field ) );
	}

	public function column_old_value( $item ) {
		return esc_html( $this->format_value( $item['old_value'] ?? '' ) );
	}

	public function column_new_value( $item ) {
		return esc_html( $this->format_value( $item['new_value'] ?? '' ) );
	}

	public function column_delta_value( $item ) {
		$value = $item['delta_value'] ?? null;
		if ( null === $value || '' === $value ) {
			return '—';
		}

		$numeric = (float) $value;
		$prefix  = $numeric > 0 ? '+' : '';

		return esc_html( $prefix . $this->format_value( $value ) );
	}

	public function column_actor( $item ) {
		$name       = sanitize_text_field( (string) ( $item['changed_by_name'] ?? '' ) );
		$actor_type = sanitize_key( (string) ( $item['actor_type'] ?? 'system' ) );

		if ( '' === $name ) {
			$name = ucfirst( $actor_type );
		}

		return sprintf(
			'<strong>%1$s</strong><br /><span style="margin-top:6px; display:inline-block;">%2$s</span>',
			esc_html( $name ),
			$this->badge( $actor_type, $this->actor_tone( $actor_type ) )
		);
	}

	public function column_source_context( $item ) {
		$source = sanitize_key( (string) ( $item['source_context'] ?? 'unknown' ) );
		$source_system = sanitize_key( (string) ( $item['source_system'] ?? 'native' ) );
		$badges        = array(
			$this->badge( $source, $this->source_tone( $source ) ),
		);

		if ( ! empty( $item['bridge_flag'] ) ) {
			$badges[] = $this->badge( 'bridge', 'info' );
		}

		if ( ! empty( $item['import_flag'] ) ) {
			$badges[] = $this->badge( 'legacy', 'warning' );
		}

		if ( '' !== $source_system && 'native' !== $source_system ) {
			$badges[] = $this->badge( $source_system, 'neutral' );
		}

		return implode( ' ', $badges );
	}

	protected function column_default( $item, $column_name ) {
		return esc_html( $this->format_value( $item[ $column_name ] ?? '' ) );
	}

	private function format_value( $value ) {
		$value = (string) $value;
		if ( '' === $value ) {
			return '—';
		}

		return $value;
	}

	private function badge( $label, $tone = 'neutral' ) {
		return '<span class="asdl-wcl-badge asdl-wcl-badge-' . esc_attr( sanitize_html_class( (string) $tone ) ) . '">' . esc_html( (string) $label ) . '</span>';
	}

	private function field_tone( $field ) {
		if ( 'stock' === $field ) {
			return 'info';
		}

		if ( 'regular_price' === $field ) {
			return 'success';
		}

		if ( 'yith_cost' === $field ) {
			return 'warning';
		}

		return 'neutral';
	}

	private function actor_tone( $actor_type ) {
		if ( 'user' === $actor_type ) {
			return 'success';
		}

		if ( in_array( $actor_type, array( 'order', 'plugin' ), true ) ) {
			return 'info';
		}

		if ( 'system' === $actor_type ) {
			return 'neutral';
		}

		return 'warning';
	}

	private function source_tone( $source ) {
		if ( in_array( $source, array( 'arbitrary_revert', 'order_reduce', 'order_restore' ), true ) ) {
			return 'warning';
		}

		if ( in_array( $source, array( 'stock_manager', 'rest_api' ), true ) ) {
			return 'info';
		}

		if ( in_array( $source, array( 'manual_edit', 'quick_edit', 'bulk_edit' ), true ) ) {
			return 'success';
		}

		return 'neutral';
	}
}
