<?php

namespace ASDLabs\TVXWooChangeLog\StockManagerCompat;

final class SchemaInspector {
	const CANDIDATE_PLUGIN = 'woocommerce-stock-manager/woocommerce-stock-manager.php';

	public function quick_signature() {
		$plugin_file = $this->plugin_file_path();

		return array(
			'plugin_installed' => file_exists( $plugin_file ),
			'plugin_active'    => $this->is_plugin_active(),
			'plugin_version'   => $this->read_plugin_version(),
			'plugin_file'      => $plugin_file,
		);
	}

	public function inspect() {
		global $wpdb;

		$signature = $this->quick_signature();
		$table     = $this->detect_table();
		$notes     = array();
		$hooks     = array();

		if ( $signature['plugin_installed'] ) {
			$notes[] = 'Plugin local Stock Manager for WooCommerce detectado.';
		} else {
			$notes[] = 'Plugin Stock Manager for WooCommerce no detectado en plugins locales.';
		}

		$hook_scan = $this->inspect_hook_signature();
		if ( ! empty( $hook_scan['hooks'] ) ) {
			$hooks = $hook_scan['hooks'];
		}

		if ( ! empty( $hook_scan['notes'] ) ) {
			$notes = array_merge( $notes, $hook_scan['notes'] );
		}

		$table_name   = sanitize_text_field( (string) ( $table['table_name'] ?? '' ) );
		$columns      = array_values( array_filter( array_map( 'sanitize_key', (array) ( $table['columns'] ?? array() ) ) ) );
		$table_exists = ! empty( $table['table_exists'] );
		$record_count = $table_exists ? absint( $table['record_count'] ?? 0 ) : 0;
		$primary_key  = sanitize_key( (string) ( $table['primary_key'] ?? '' ) );

		if ( $table_exists ) {
			$notes[] = 'Tabla de historial detectada: ' . $table_name . '.';
		} elseif ( $signature['plugin_installed'] ) {
			$notes[] = 'No se validó una tabla de historial compatible para Stock Manager.';
		}

		return array(
			'plugin_installed' => ! empty( $signature['plugin_installed'] ),
			'plugin_active'    => ! empty( $signature['plugin_active'] ),
			'plugin_version'   => sanitize_text_field( (string) ( $signature['plugin_version'] ?? '' ) ),
			'plugin_file'      => sanitize_text_field( (string) ( $signature['plugin_file'] ?? '' ) ),
			'plugin_name'      => 'Stock Manager for WooCommerce',
			'table_name'       => $table_name,
			'table_exists'     => $table_exists,
			'columns'          => $columns,
			'primary_key'      => $primary_key,
			'record_count'     => $record_count,
			'import_supported' => $table_exists && $this->is_supported_schema( $columns ),
			'bridge_supported' => $table_exists && ! empty( $signature['plugin_active'] ) && $this->is_supported_schema( $columns ),
			'hooks'            => $hooks,
			'notes'            => array_values( array_unique( array_filter( $notes ) ) ),
			'wpdb_prefix'      => sanitize_text_field( (string) $wpdb->prefix ),
		);
	}

	private function detect_table() {
		global $wpdb;

		$pattern    = $wpdb->esc_like( $wpdb->prefix ) . '%stock%log%';
		$candidates = $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', $pattern ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching

		if ( ! is_array( $candidates ) ) {
			$candidates = array();
		}

		usort(
			$candidates,
			static function ( $left, $right ) use ( $wpdb ) {
				$exact = $wpdb->prefix . 'stock_log';
				if ( $left === $exact ) {
					return -1;
				}

				if ( $right === $exact ) {
					return 1;
				}

				return strcmp( $left, $right );
			}
		);

		foreach ( $candidates as $table_name ) {
			$table_name = sanitize_text_field( (string) $table_name );
			if ( '' === $table_name || ! preg_match( '/^[A-Za-z0-9_]+$/', $table_name ) ) {
				continue;
			}

			$columns = $this->describe_table( $table_name );
			if ( ! $this->is_supported_schema( $columns ) ) {
				continue;
			}

			$primary_key = '';
			foreach ( $columns as $column_name ) {
				if ( in_array( $column_name, array( 'ID', 'id' ), true ) ) {
					$primary_key = $column_name;
					break;
				}
			}

			if ( '' === $primary_key ) {
				$primary_key = 'ID';
			}

			return array(
				'table_name'   => $table_name,
				'table_exists' => true,
				'columns'      => $columns,
				'primary_key'  => $primary_key,
				'record_count' => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table_name}" ), // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			);
		}

		return array(
			'table_name'   => '',
			'table_exists' => false,
			'columns'      => array(),
			'primary_key'  => '',
			'record_count' => 0,
		);
	}

	private function describe_table( $table_name ) {
		global $wpdb;

		$rows = $wpdb->get_results( "SHOW COLUMNS FROM {$table_name}", ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		if ( ! is_array( $rows ) ) {
			return array();
		}

		$columns = array();
		foreach ( $rows as $row ) {
			$field = (string) ( $row['Field'] ?? '' );
			if ( '' !== $field ) {
				$columns[] = $field;
			}
		}

		return $columns;
	}

	private function is_supported_schema( array $columns ) {
		$normalized = array_map( 'sanitize_key', $columns );

		return in_array( 'product_id', $normalized, true )
			&& in_array( 'qty', $normalized, true )
			&& in_array( 'date_created', $normalized, true );
	}

	private function inspect_hook_signature() {
		$notes = array();
		$hooks = array();
		$file  = trailingslashit( dirname( $this->plugin_file_path() ) ) . 'public/class-stock-manager.php';

		if ( ! file_exists( $file ) || ! is_readable( $file ) ) {
			return array(
				'hooks' => $hooks,
				'notes' => $notes,
			);
		}

		$content = (string) file_get_contents( $file );
		if ( false !== strpos( $content, 'woocommerce_product_set_stock' ) ) {
			$hooks[] = 'woocommerce_product_set_stock';
		}

		if ( false !== strpos( $content, 'woocommerce_variation_set_stock' ) ) {
			$hooks[] = 'woocommerce_variation_set_stock';
		}

		if ( false !== strpos( $content, 'save_stock' ) ) {
			$notes[] = 'Se detectó callback save_stock sobre hooks nativos de stock.';
		}

		return array(
			'hooks' => array_values( array_unique( $hooks ) ),
			'notes' => $notes,
		);
	}

	private function plugin_file_path() {
		return trailingslashit( WP_PLUGIN_DIR ) . self::CANDIDATE_PLUGIN;
	}

	private function is_plugin_active() {
		if ( ! function_exists( 'is_plugin_active' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		return function_exists( 'is_plugin_active' ) && is_plugin_active( self::CANDIDATE_PLUGIN );
	}

	private function read_plugin_version() {
		$file = $this->plugin_file_path();
		if ( ! file_exists( $file ) ) {
			return '';
		}

		if ( ! function_exists( 'get_file_data' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		if ( ! function_exists( 'get_file_data' ) ) {
			return '';
		}

		$data = get_file_data(
			$file,
			array(
				'Version' => 'Version',
			)
		);

		return sanitize_text_field( (string) ( $data['Version'] ?? '' ) );
	}
}
