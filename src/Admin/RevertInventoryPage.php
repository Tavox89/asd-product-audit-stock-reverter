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

		echo '<div class="wrap">';
		echo '<h1>Revertir inventario</h1>';
		echo '<p>Busca por ID de pedido, order number o invoice number según las metas detectadas localmente. La acción no cambia el estado del pedido ni sus totales.</p>';

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
			sprintf( 'Reversión aplicada correctamente. Ítems restaurados: %d.', count( (array) ( $result['restored'] ?? array() ) ) ),
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
		echo '<div class="notice notice-info"><p><strong>Estrategia de búsqueda local:</strong> ';
		echo esc_html( implode( ' | ', $this->invoice_meta_resolver->get_detected_strategy_notes() ) );
		echo '</p></div>';
	}

	private function render_search_form( $query ) {
		echo '<form method="get" style="margin:16px 0 20px;">';
		echo '<input type="hidden" name="page" value="' . esc_attr( self::SLUG ) . '" />';
		echo '<label for="tvx-wcl-order-query"><strong>Pedido / factura</strong></label><br />';
		echo '<input type="text" id="tvx-wcl-order-query" name="order_query" value="' . esc_attr( $query ) . '" class="regular-text" placeholder="Ej: 1234, FACT-001, 000045" /> ';
		submit_button( 'Buscar', 'primary', '', false );
		echo '</form>';
	}

	private function render_search_results( array $results, $query ) {
		echo '<h2>Coincidencias</h2>';

		if ( empty( $results ) ) {
			echo '<p>No se encontraron pedidos con ese criterio.</p>';
			return;
		}

		echo '<table class="widefat striped">';
		echo '<thead><tr><th>Pedido</th><th>Factura / referencia</th><th>Estado</th><th>Cliente</th><th></th></tr></thead><tbody>';

		foreach ( $results as $row ) {
			$url = add_query_arg(
				array(
					'page'        => self::SLUG,
					'order_query' => $query,
					'order_id'    => (int) $row['order_id'],
				),
				admin_url( 'admin.php' )
			);

			echo '<tr>';
			echo '<td><strong>#' . esc_html( $row['order_number'] ) . '</strong><br /><small>ID ' . esc_html( $row['order_id'] ) . '</small></td>';
			echo '<td>' . esc_html( $row['invoice_number'] ?: '—' ) . '</td>';
			echo '<td>' . esc_html( $row['status'] ) . '</td>';
			echo '<td>' . esc_html( $row['customer'] ?: '—' ) . '</td>';
			echo '<td><a class="button" href="' . esc_url( $url ) . '">Ver preview</a></td>';
			echo '</tr>';
		}

		echo '</tbody></table>';
	}

	private function render_preview( array $preview, $query ) {
		echo '<hr style="margin:24px 0;" />';
		echo '<h2>Preview de reversión</h2>';
		echo '<table class="form-table" role="presentation">';
		echo '<tbody>';
		echo '<tr><th scope="row">Pedido</th><td>#' . esc_html( (string) ( $preview['order_number'] ?? '' ) ) . ' (ID ' . esc_html( (string) ( $preview['order_id'] ?? 0 ) ) . ')</td></tr>';
		echo '<tr><th scope="row">Factura / referencia</th><td>' . esc_html( (string) ( $preview['invoice_number'] ?? '—' ) ) . '</td></tr>';
		echo '<tr><th scope="row">Cliente</th><td>' . esc_html( (string) ( $preview['customer_label'] ?? '—' ) ) . '</td></tr>';
		echo '<tr><th scope="row">Estado</th><td>' . esc_html( (string) ( $preview['status'] ?? '' ) ) . '</td></tr>';
		echo '<tr><th scope="row">Flag stock reducido</th><td>' . ( ! empty( $preview['order_stock_reduced'] ) ? 'Sí' : 'No' ) . '</td></tr>';
		echo '<tr><th scope="row">Notas compatibles</th><td>' . esc_html( (string) count( (array) ( $preview['matched_notes'] ?? array() ) ) ) . '</td></tr>';
		echo '</tbody>';
		echo '</table>';

		if ( ! empty( $preview['blocking_reasons'] ) ) {
			echo '<div class="notice notice-error"><p><strong>Bloqueado:</strong> ' . esc_html( implode( ' ', (array) $preview['blocking_reasons'] ) ) . '</p></div>';
		} else {
			echo '<div class="notice notice-success"><p>El pedido es elegible para reversión arbitraria segura.</p></div>';
		}

		if ( ! empty( $preview['matched_notes'] ) ) {
			echo '<p><strong>Notas coincidentes:</strong> ';
			echo esc_html( implode( ' | ', wp_list_pluck( (array) $preview['matched_notes'], 'content' ) ) );
			echo '</p>';
		}

		echo '<table class="widefat striped">';
		echo '<thead><tr><th>Ítem</th><th>SKU</th><th>Cantidad pedido</th><th>Cantidad reducida</th><th>Estado</th></tr></thead><tbody>';
		foreach ( (array) ( $preview['items'] ?? array() ) as $item ) {
			echo '<tr>';
			echo '<td>' . esc_html( (string) ( $item['name'] ?? '' ) ) . '</td>';
			echo '<td>' . esc_html( (string) ( $item['sku'] ?? '—' ) ) . '</td>';
			echo '<td>' . esc_html( (string) ( $item['ordered_qty'] ?? 0 ) ) . '</td>';
			echo '<td>' . esc_html( (string) ( $item['reduced_stock_qty'] ?? 0 ) ) . '</td>';
			echo '<td>' . esc_html( ! empty( $item['can_restore'] ) ? 'Restaurable' : ( $item['skip_reason'] ?: 'Omitido' ) ) . '</td>';
			echo '</tr>';
		}
		echo '</tbody></table>';

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
}
