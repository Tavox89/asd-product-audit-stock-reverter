<?php

namespace ASDLabs\TVXWooChangeLog\Admin;

use ASDLabs\TVXWooChangeLog\Core\CapabilityManager;
use ASDLabs\TVXWooChangeLog\DB\Repositories\ReversionRepository;
use ASDLabs\TVXWooChangeLog\Domain\EligibilityChecker;
use ASDLabs\TVXWooChangeLog\Domain\InventoryReverter;
use ASDLabs\TVXWooChangeLog\Domain\LockManager;
use ASDLabs\TVXWooChangeLog\Domain\NotePatternMatcher;
use ASDLabs\TVXWooChangeLog\Integrations\Invoice\InvoiceMetaResolver;
use ASDLabs\TVXWooChangeLog\Integrations\Invoice\OrderSearchService;

final class RevertInventoryPage {
	const SLUG = 'tvx-wcl-revert-inventory';

	private $search_service;
	private $inventory_reverter;
	private $eligibility_checker;
	private $invoice_meta_resolver;

	public function __construct() {
		$this->invoice_meta_resolver = new InvoiceMetaResolver();
		$reversion_repository        = new ReversionRepository();

		$this->search_service       = new OrderSearchService( $this->invoice_meta_resolver );
		$this->eligibility_checker  = new EligibilityChecker( new NotePatternMatcher(), $this->invoice_meta_resolver, $reversion_repository );
		$this->inventory_reverter   = new InventoryReverter( $this->eligibility_checker, new LockManager(), $reversion_repository, $this->invoice_meta_resolver );
	}

	public function register() {
		add_action( 'admin_post_tvx_wcl_execute_revert', array( $this, 'handle_execute' ) );
	}

	public function render() {
		if ( ! CapabilityManager::current_user_can_revert_inventory() ) {
			wp_die( esc_html__( 'Solo administradores pueden ejecutar esta reversión.', 'tvx-woo-change-log' ) );
		}

		$query    = sanitize_text_field( (string) ( $_GET['order_query'] ?? '' ) );
		$order_id = absint( $_GET['order_id'] ?? 0 );
		$results  = '' !== $query ? $this->search_service->search( $query ) : array();
		$preview  = array();

		if ( $order_id > 0 && function_exists( 'wc_get_order' ) ) {
			$order = wc_get_order( $order_id );
			if ( $order ) {
				$preview = $this->eligibility_checker->build_preview( $order );
			}
		}

		echo '<div class="wrap asdl-wcl-admin">';
		echo '<h1>ASD Labs · Revertir inventario</h1>';
		echo '<p>Busca por pedido o factura en cualquier estado. La operación solo intenta restaurar el stock realmente descontado, no cambia estado, totales ni datos comerciales del pedido.</p>';

		$this->render_notice();
		$this->render_strategy_notes();
		$this->render_search_form( $query );

		if ( '' !== $query ) {
			$this->render_search_results( $results, $query );
		}

		if ( ! empty( $preview ) ) {
			$this->render_preview( $preview, $query );
		}

		echo '</div>';
	}

	public function handle_execute() {
		if ( ! CapabilityManager::current_user_can_revert_inventory() ) {
			wp_die( esc_html__( 'No tienes permisos para esta acción.', 'tvx-woo-change-log' ) );
		}

		$order_id = absint( $_POST['order_id'] ?? 0 );
		check_admin_referer( 'tvx_wcl_execute_revert_' . $order_id );

		$query  = sanitize_text_field( (string) ( $_POST['order_query'] ?? '' ) );
		$result = $this->inventory_reverter->execute( $order_id, get_current_user_id() );

		if ( is_wp_error( $result ) ) {
			$this->redirect_with_notice( 'error', $result->get_error_message(), $order_id, $query );
		}

		$this->redirect_with_notice(
			'success',
			sprintf(
				'Reversión %1$s. Ítems restaurados: %2$d. Omitidos: %3$d. Fallidos: %4$d.',
				'partial' === ( $result['status'] ?? '' ) ? 'parcial aplicada' : 'aplicada correctamente',
				count( (array) ( $result['restored'] ?? array() ) ),
				count( (array) ( $result['skipped'] ?? array() ) ),
				count( (array) ( $result['failed'] ?? array() ) )
			),
			$order_id,
			$query
		);
	}

	private function render_notice() {
		$notice      = sanitize_key( (string) ( $_GET['tvx_wcl_notice'] ?? '' ) );
		$notice_text = sanitize_text_field( (string) ( $_GET['tvx_wcl_notice_text'] ?? '' ) );

		if ( '' === $notice || '' === $notice_text ) {
			return;
		}

		$class = 'notice-info';
		if ( 'success' === $notice ) {
			$class = 'notice-success';
		} elseif ( 'error' === $notice ) {
			$class = 'notice-error';
		}

		echo '<div class="notice ' . esc_attr( $class ) . '"><p>' . esc_html( $notice_text ) . '</p></div>';
	}

	private function render_strategy_notes() {
		echo '<div class="asdl-wcl-panel">';
		echo '<h2>Estrategia de búsqueda</h2>';
		echo '<ul class="asdl-wcl-list">';
		foreach ( $this->invoice_meta_resolver->get_detected_strategy_notes() as $note ) {
			echo '<li>' . esc_html( (string) $note ) . '</li>';
		}
		echo '</ul>';
		echo '</div>';
	}

	private function render_search_form( $query ) {
		echo '<div class="asdl-wcl-panel">';
		echo '<h2>Buscar pedido o factura</h2>';
		echo '<form method="get">';
		echo '<input type="hidden" name="page" value="' . esc_attr( self::SLUG ) . '" />';
		echo '<label for="tvx-wcl-order-query"><strong>Pedido / factura</strong></label><br />';
		echo '<input type="text" id="tvx-wcl-order-query" name="order_query" value="' . esc_attr( $query ) . '" class="regular-text" placeholder="Ej: 1234, FACT-001, 000045" /> ';
		submit_button( 'Buscar', 'primary', '', false );
		echo '</form>';
		echo '</div>';
	}

	private function render_search_results( array $results, $query ) {
		echo '<h2>Coincidencias</h2>';

		if ( empty( $results ) ) {
			echo '<p>No se encontraron pedidos con ese criterio.</p>';
			return;
		}

		echo '<div class="asdl-wcl-search-results">';

		foreach ( $results as $row ) {
			$url = add_query_arg(
				array(
					'page'        => self::SLUG,
					'order_query' => $query,
					'order_id'    => (int) $row['order_id'],
				),
				admin_url( 'admin.php' )
			);

			echo '<div class="asdl-wcl-search-result">';
			echo '<div class="asdl-wcl-search-result-header">';
			echo '<div>';
			echo '<strong>#' . esc_html( $row['order_number'] ) . '</strong><br /><span class="asdl-wcl-meta">ID ' . esc_html( $row['order_id'] ) . '</span>';
			echo '</div>';
			echo '<div class="asdl-wcl-inline-badges">';
			echo $this->badge( (string) $row['status'], $this->status_tone( (string) $row['status'] ) );
			if ( ! empty( $row['invoice_number'] ) ) {
				echo $this->badge( 'Factura ' . (string) $row['invoice_number'], 'neutral' );
			}
			echo '</div>';
			echo '</div>';
			echo '<div class="asdl-wcl-data-grid">';
			echo '<div class="asdl-wcl-data-cell"><span>Cliente</span>' . esc_html( (string) ( $row['customer'] ?: '—' ) ) . '</div>';
			echo '<div class="asdl-wcl-data-cell"><span>Editar pedido</span>' . ( ! empty( $row['edit_url'] ) ? '<a href="' . esc_url( $row['edit_url'] ) . '">Abrir en WooCommerce</a>' : '—' ) . '</div>';
			echo '<div class="asdl-wcl-data-cell"><span>Acción</span><a class="button" href="' . esc_url( $url ) . '">Ver preview</a></div>';
			echo '</div>';
			echo '</div>';
		}

		echo '</div>';
	}

	private function render_preview( array $preview, $query ) {
		echo '<div class="asdl-wcl-order-overview">';
		echo '<div class="asdl-wcl-order-overview-header">';
		echo '<div><h2 style="margin:0;">Preview de reversión</h2><span class="asdl-wcl-meta">Pedido #' . esc_html( (string) ( $preview['order_number'] ?? '' ) ) . ' · ID ' . esc_html( (string) ( $preview['order_id'] ?? 0 ) ) . '</span></div>';
		echo '<div class="asdl-wcl-inline-badges">';
		echo $this->badge( (string) ( $preview['status'] ?? '' ), $this->status_tone( (string) ( $preview['status'] ?? '' ) ) );
		echo $this->badge( ! empty( $preview['eligible'] ) ? 'Elegible' : 'Bloqueado', ! empty( $preview['eligible'] ) ? 'success' : 'danger' );
		if ( ! empty( $preview['partial_reverted'] ) ) {
			echo $this->badge( 'Reversión parcial previa', 'warning' );
		}
		if ( ! empty( $preview['already_reverted'] ) ) {
			echo $this->badge( 'Ya revertido', 'danger' );
		}
		echo '</div>';
		echo '</div>';
		echo '<div class="asdl-wcl-data-grid">';
		echo '<div class="asdl-wcl-data-cell"><span>Factura / referencia</span>' . esc_html( (string) ( $preview['invoice_number'] ?? '—' ) ) . '</div>';
		echo '<div class="asdl-wcl-data-cell"><span>Cliente</span>' . esc_html( (string) ( $preview['customer_label'] ?? '—' ) ) . '</div>';
		echo '<div class="asdl-wcl-data-cell"><span>Flag stock reducido</span>' . ( ! empty( $preview['order_stock_reduced'] ) ? 'Sí' : 'No' ) . '</div>';
		echo '<div class="asdl-wcl-data-cell"><span>Notas compatibles</span>' . esc_html( (string) count( (array) ( $preview['matched_notes'] ?? array() ) ) ) . '</div>';
		echo '<div class="asdl-wcl-data-cell"><span>Restaurables</span>' . esc_html( (string) count( (array) ( $preview['restorable_items'] ?? array() ) ) ) . '</div>';
		echo '<div class="asdl-wcl-data-cell"><span>Omitidos / bloqueados</span>' . esc_html( (string) count( (array) ( $preview['skipped_items'] ?? array() ) ) ) . '</div>';
		echo '</div>';
		echo '</div>';

		echo '<div class="asdl-wcl-grid">';
		echo '<div class="asdl-wcl-panel">';
		echo '<h2>Evidencias detectadas</h2>';
		echo '<ul class="asdl-wcl-list">';
		echo '<li>Notas compatibles: ' . esc_html( ! empty( $preview['note_evidence']['has_matching_note'] ) ? 'sí' : 'no' ) . ' (' . esc_html( (string) absint( $preview['note_evidence']['count'] ?? 0 ) ) . ')</li>';
		echo '<li>_reduced_stock positivo en líneas: ' . esc_html( ! empty( $preview['line_evidence']['has_reduced_item_meta'] ) ? 'sí' : 'no' ) . '</li>';
		echo '<li>Ítems restaurables: ' . esc_html( (string) absint( $preview['line_evidence']['restorable_count'] ?? 0 ) ) . '</li>';
		echo '<li>Flag de pedido stock reducido: ' . esc_html( ! empty( $preview['order_flag_evidence']['stock_reduced'] ) ? 'sí' : 'no' ) . '</li>';
		if ( ! empty( $preview['matched_notes'] ) ) {
			echo '<li>Notas: ' . esc_html( implode( ' | ', wp_list_pluck( (array) $preview['matched_notes'], 'content' ) ) ) . '</li>';
		}
		echo '</ul>';
		echo '</div>';

		echo '<div class="asdl-wcl-panel">';
		echo '<h2>Motivos de bloqueo</h2>';
		if ( ! empty( $preview['blocking_reasons'] ) ) {
			echo '<ul class="asdl-wcl-list">';
			foreach ( (array) $preview['blocking_reasons'] as $reason ) {
				echo '<li>' . esc_html( (string) $reason ) . '</li>';
			}
			echo '</ul>';
		} else {
			echo '<p>No se detectaron bloqueos. El pedido es elegible para reversión arbitraria segura.</p>';
		}
		echo '</div>';
		echo '</div>';

		$this->render_items_table( 'Ítems restaurables', (array) ( $preview['restorable_items'] ?? array() ) );
		if ( ! empty( $preview['skipped_items'] ) ) {
			$this->render_items_table( 'Ítems omitidos / no restaurables', (array) $preview['skipped_items'] );
		}

		if ( ! empty( $preview['eligible'] ) ) {
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="margin-top:16px;">';
			echo '<input type="hidden" name="action" value="tvx_wcl_execute_revert" />';
			echo '<input type="hidden" name="order_id" value="' . esc_attr( (string) $preview['order_id'] ) . '" />';
			echo '<input type="hidden" name="order_query" value="' . esc_attr( $query ) . '" />';
			wp_nonce_field( 'tvx_wcl_execute_revert_' . (int) $preview['order_id'] );
			submit_button( 'Ejecutar reversión segura', 'primary', 'submit', false, array( 'onclick' => "return confirm('Esta acción restaurará el stock registrado para este pedido y no podrá ejecutarse dos veces. ¿Continuar?');" ) );
			echo '</form>';
		}
	}

	private function render_items_table( $title, array $items ) {
		echo '<div class="asdl-wcl-panel">';
		echo '<h2>' . esc_html( (string) $title ) . '</h2>';
		echo '<div class="asdl-wcl-table-wrap">';
		echo '<table class="widefat striped asdl-wcl-preview-table">';
		echo '<thead><tr><th>Ítem</th><th>SKU</th><th>Pedido</th><th>Reducido</th><th>Stock actual</th><th>Estado</th></tr></thead><tbody>';
		foreach ( $items as $item ) {
			echo '<tr>';
			echo '<td>' . esc_html( (string) ( $item['name'] ?? '' ) ) . '</td>';
			echo '<td>' . esc_html( (string) ( $item['sku'] ?? '—' ) ) . '</td>';
			echo '<td>' . esc_html( (string) ( $item['ordered_qty'] ?? 0 ) ) . '</td>';
			echo '<td>' . esc_html( (string) ( $item['reduced_stock_qty'] ?? 0 ) ) . '</td>';
			echo '<td>' . esc_html( isset( $item['current_stock_qty'] ) && null !== $item['current_stock_qty'] ? (string) $item['current_stock_qty'] : '—' ) . '</td>';
			echo '<td>' . ( ! empty( $item['can_restore'] ) ? $this->badge( 'Restaurable', 'success' ) : $this->badge( (string) ( $item['skip_reason'] ?: 'Omitido' ), 'warning' ) ) . '</td>';
			echo '</tr>';
		}
		echo '</tbody></table>';
		echo '</div>';
		echo '</div>';
	}

	private function redirect_with_notice( $type, $message, $order_id = 0, $query = '' ) {
		$args = array(
			'page'                 => self::SLUG,
			'tvx_wcl_notice'       => sanitize_key( (string) $type ),
			'tvx_wcl_notice_text'  => sanitize_text_field( (string) $message ),
		);

		if ( $order_id > 0 ) {
			$args['order_id'] = absint( $order_id );
		}

		if ( '' !== $query ) {
			$args['order_query'] = sanitize_text_field( (string) $query );
		}

		wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
		exit;
	}

	private function badge( $label, $tone ) {
		return '<span class="asdl-wcl-badge asdl-wcl-badge-' . esc_attr( sanitize_html_class( (string) $tone ) ) . '">' . esc_html( (string) $label ) . '</span>';
	}

	private function status_tone( $status ) {
		$status = sanitize_key( (string) $status );

		if ( in_array( $status, array( 'completed', 'processing', 'on-hold' ), true ) ) {
			return 'success';
		}

		if ( in_array( $status, array( 'pending', 'draft' ), true ) ) {
			return 'warning';
		}

		if ( in_array( $status, array( 'cancelled', 'failed', 'refunded', 'trash' ), true ) ) {
			return 'danger';
		}

		return 'neutral';
	}
}
