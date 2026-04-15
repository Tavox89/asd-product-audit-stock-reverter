<?php

namespace ASDLabs\TVXWooChangeLog\Admin;

use ASDLabs\TVXWooChangeLog\Core\CapabilityManager;
use ASDLabs\TVXWooChangeLog\StockManagerCompat\Detector;
use ASDLabs\TVXWooChangeLog\StockManagerCompat\LegacyImporter;
use ASDLabs\TVXWooChangeLog\Support\Time;

final class StockManagerCompatPage {
	const SLUG = 'tvx-wcl-stock-manager-compat';

	private $detector;
	private $importer;

	public function __construct() {
		$this->detector = new Detector();
		$this->importer = new LegacyImporter( $this->detector );
	}

	public function register() {
		add_action( 'admin_post_tvx_wcl_stock_manager_reanalyze', array( $this, 'handle_reanalyze' ) );
		add_action( 'admin_post_tvx_wcl_stock_manager_import', array( $this, 'handle_import' ) );
		add_action( 'admin_post_tvx_wcl_stock_manager_toggle_bridge', array( $this, 'handle_toggle_bridge' ) );
	}

	public function render() {
		if ( ! CapabilityManager::current_user_can_manage_configuration() ) {
			wp_die( esc_html__( 'No tienes permisos para administrar la compatibilidad.', 'tvx-woo-change-log' ) );
		}

		$state        = $this->detector->get_state();
		$import_state = $this->detector->get_import_state();
		$preview      = ! empty( $state['import_supported'] ) ? $this->importer->preview(8) : array();

		echo '<div class="wrap asdl-wcl-admin">';
		echo '<h1>ASD Labs · Compatibilidad Stock Manager</h1>';
		echo '<p>La auditoría canónica sigue viviendo en las tablas ASD Labs. Esta vista solo resume detección, importación histórica y bridge seguro hacia el historial simple de Stock Manager for WooCommerce.</p>';

		$this->render_notice();
		$this->render_summary_cards( $state, $import_state );
		$this->render_actions( $state, $import_state );
		$this->render_technical_table( $state, $import_state );
		$this->render_preview( $preview );
		echo '</div>';
	}

	public function handle_reanalyze() {
		$this->assert_manage_permission();
		check_admin_referer( 'tvx_wcl_stock_manager_reanalyze' );

		$state = $this->detector->refresh_cache();
		$this->redirect_with_notice(
			'success',
			sprintf( 'Compatibilidad reanalizada. Modo resuelto: %s.', (string) ( $state['mode'] ?? 'native_only' ) )
		);
	}

	public function handle_import() {
		$this->assert_manage_permission();
		check_admin_referer( 'tvx_wcl_stock_manager_import' );

		$batch_size = max( 50, min( 1000, absint( $_POST['batch_size'] ?? 250 ) ) );
		$result     = $this->importer->import_batch( $batch_size, false );

		if ( is_wp_error( $result ) ) {
			$this->redirect_with_notice( 'error', $result->get_error_message() );
		}

		$message = sprintf(
			'Lote procesado. Importados: %1$d. Duplicados omitidos: %2$d. Pendientes: %3$d.',
			(int) ( $result['imported'] ?? 0 ),
			(int) ( $result['duplicates'] ?? 0 ),
			(int) ( $result['remaining'] ?? 0 )
		);

		$this->redirect_with_notice( 'success', $message );
	}

	public function handle_toggle_bridge() {
		$this->assert_manage_permission();
		check_admin_referer( 'tvx_wcl_stock_manager_toggle_bridge' );

		$disabled = ! empty( $_POST['bridge_disabled'] );
		$state    = $this->detector->set_bridge_disabled( $disabled );
		$message  = $disabled
			? 'Bridge hacia Stock Manager desactivado. El modo queda import-only o native-only según la detección.'
			: sprintf( 'Bridge reactivado. Modo actual: %s.', (string) ( $state['mode'] ?? 'native_only' ) );

		$this->redirect_with_notice( 'success', $message );
	}

	private function render_summary_cards( array $state, array $import_state ) {
		$bridge_status = 'Incompatible';
		$bridge_tone   = 'warning';

		if ( ! empty( $state['bridge_supported'] ) && empty( $state['plugin_active'] ) ) {
			$bridge_status = 'No disponible';
		} elseif ( ! empty( $state['bridge_supported'] ) && ! empty( $state['bridge_disabled'] ) ) {
			$bridge_status = 'Deshabilitado';
			$bridge_tone   = 'warning';
		} elseif ( ! empty( $state['bridge_supported'] ) ) {
			$bridge_status = 'Habilitado';
			$bridge_tone   = 'success';
		}

		echo '<div class="asdl-wcl-cards">';
		$this->render_card( 'Plugin detectado', ! empty( $state['plugin_installed'] ) ? 'Sí' : 'No', ! empty( $state['plugin_installed'] ) ? 'success' : 'muted' );
		$this->render_card( 'Activo', ! empty( $state['plugin_active'] ) ? 'Sí' : 'No', ! empty( $state['plugin_active'] ) ? 'success' : 'warning' );
		$this->render_card( 'Modo actual', $this->format_mode_label( (string) ( $state['mode'] ?? 'native_only' ) ), 'info' );
		$this->render_card( 'Bridge', $bridge_status, $bridge_tone );
		$this->render_card( 'Histórico detectado', (string) absint( $state['record_count'] ?? 0 ), 'neutral' );
		$this->render_card( 'Importados en ASD', (string) absint( $state['imported_count'] ?? 0 ), 'neutral' );
		$this->render_card( 'Pendientes', (string) absint( $import_state['remaining_count'] ?? max( 0, (int) ( $state['record_count'] ?? 0 ) - (int) ( $state['imported_count'] ?? 0 ) ) ), 'neutral' );
		$this->render_card( 'Última importación', ! empty( $state['last_imported_at_utc'] ) ? Time::utc_to_site_datetime( (string) $state['last_imported_at_utc'] ) : '—', 'neutral' );
		echo '</div>';
	}

	private function render_actions( array $state, array $import_state ) {
		echo '<div class="asdl-wcl-panel">';
		echo '<h2>Acciones</h2>';
		echo '<div class="asdl-wcl-actions">';

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="tvx_wcl_stock_manager_reanalyze" />';
		wp_nonce_field( 'tvx_wcl_stock_manager_reanalyze' );
		submit_button( 'Re-analizar compatibilidad', 'secondary', 'submit', false );
		echo '</form>';

		if ( ! empty( $state['import_supported'] ) ) {
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
			echo '<input type="hidden" name="action" value="tvx_wcl_stock_manager_import" />';
			echo '<input type="hidden" name="batch_size" value="250" />';
			wp_nonce_field( 'tvx_wcl_stock_manager_import' );
			$button_label = ! empty( $import_state['processed_count'] ) && empty( $import_state['completed'] )
				? 'Reanudar importación'
				: 'Importar historial legado';
			submit_button( $button_label, 'primary', 'submit', false );
			echo '</form>';
		}

		if ( ! empty( $state['bridge_supported'] ) && ! empty( $state['plugin_active'] ) ) {
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
			echo '<input type="hidden" name="action" value="tvx_wcl_stock_manager_toggle_bridge" />';
			echo '<input type="hidden" name="bridge_disabled" value="' . esc_attr( ! empty( $state['bridge_disabled'] ) ? '0' : '1' ) . '" />';
			wp_nonce_field( 'tvx_wcl_stock_manager_toggle_bridge' );
			$toggle_label = ! empty( $state['bridge_disabled'] ) ? 'Activar bridge' : 'Desactivar bridge';
			submit_button( $toggle_label, 'secondary', 'submit', false );
			echo '</form>';
		}

		echo '</div>';

		if ( ! empty( $state['import_supported'] ) && empty( $state['plugin_active'] ) ) {
			echo '<p class="asdl-wcl-meta">Importación disponible; bridge no disponible porque Stock Manager no está activo.</p>';
		} elseif ( ! empty( $state['import_supported'] ) && empty( $state['bridge_supported'] ) ) {
			echo '<p class="asdl-wcl-meta">Importación disponible; bridge continuo deshabilitado porque el esquema no fue validado como seguro.</p>';
		}

		echo '</div>';
	}

	private function render_technical_table( array $state, array $import_state ) {
		echo '<div class="asdl-wcl-panel">';
		echo '<h2>Estado técnico</h2>';
		echo '<table class="widefat striped asdl-wcl-tech-table"><tbody>';
		$this->render_table_row( 'Versión detectada', (string) ( $state['plugin_version'] ?? '—' ) );
		$this->render_table_row( 'Modo resuelto', $this->format_mode_label( (string) ( $state['mode'] ?? 'native_only' ) ) );
		$this->render_table_row( 'Tabla detectada', (string) ( $state['table_name'] ?? '—' ) );
		$this->render_table_row( 'Columnas clave', ! empty( $state['columns'] ) ? implode( ', ', (array) $state['columns'] ) : '—' );
		$this->render_table_row( 'Hooks observados', ! empty( $state['hooks'] ) ? implode( ', ', (array) $state['hooks'] ) : '—' );
		$this->render_table_row( 'Registros históricos detectados', (string) absint( $state['record_count'] ?? 0 ) );
		$this->render_table_row( 'Procesados por el importador', (string) absint( $import_state['processed_count'] ?? 0 ) );
		$this->render_table_row( 'Importados sin duplicados', (string) absint( $import_state['imported_count'] ?? 0 ) );
		$this->render_table_row( 'Último lote ejecutado', ! empty( $import_state['last_run_at_utc'] ) ? Time::utc_to_site_datetime( (string) $import_state['last_run_at_utc'] ) : '—' );
		$this->render_table_row( 'Última inspección', ! empty( $state['inspected_at_utc'] ) ? Time::utc_to_site_datetime( (string) $state['inspected_at_utc'] ) : '—' );
		$this->render_table_row( 'Notas de detección', ! empty( $state['notes'] ) ? implode( ' | ', (array) $state['notes'] ) : '—' );
		echo '</tbody></table>';
		echo '</div>';
	}

	private function render_preview( $preview ) {
		echo '<div class="asdl-wcl-panel">';
		echo '<h2>Vista previa de importación</h2>';

		if ( is_wp_error( $preview ) ) {
			echo '<p>' . esc_html( $preview->get_error_message() ) . '</p>';
			echo '</div>';
			return;
		}

		if ( empty( $preview ) ) {
			echo '<p>No hay datos de preview disponibles.</p>';
			echo '</div>';
			return;
		}

		echo '<p>Dry run embebido: se muestran hasta 8 filas del log legado pendientes o disponibles para importación, sin tocar datos.</p>';
		echo '<p><strong>Total detectado:</strong> ' . esc_html( (string) absint( $preview['total_detected'] ?? 0 ) ) . ' · ';
		echo '<strong>Ya importado:</strong> ' . esc_html( (string) absint( $preview['imported_count'] ?? 0 ) ) . ' · ';
		echo '<strong>Pendiente:</strong> ' . esc_html( (string) absint( $preview['pending_count'] ?? 0 ) ) . '</p>';

		if ( empty( $preview['sample_rows'] ) ) {
			echo '<p>No hay filas de ejemplo pendientes.</p>';
			echo '</div>';
			return;
		}

		echo '<table class="widefat striped"><thead><tr><th>ID legado</th><th>Producto</th><th>Qty</th><th>Fecha original</th></tr></thead><tbody>';
		foreach ( (array) $preview['sample_rows'] as $row ) {
			echo '<tr>';
			echo '<td>' . esc_html( (string) ( $row['external_id'] ?? '—' ) ) . '</td>';
			echo '<td>#' . esc_html( (string) ( $row['product_id'] ?? 0 ) ) . '</td>';
			echo '<td>' . esc_html( (string) ( $row['qty'] ?? '' ) ) . '</td>';
			echo '<td>' . esc_html( (string) ( $row['date_created'] ?? '' ) ) . '</td>';
			echo '</tr>';
		}
		echo '</tbody></table>';
		echo '</div>';
	}

	private function render_card( $label, $value, $tone ) {
		echo '<div class="asdl-wcl-card">';
		echo '<span class="asdl-wcl-card-label">' . esc_html( (string) $label ) . '</span>';
		echo '<strong class="asdl-wcl-badge asdl-wcl-badge-' . esc_attr( sanitize_html_class( (string) $tone ) ) . '">' . esc_html( (string) $value ) . '</strong>';
		echo '</div>';
	}

	private function render_table_row( $label, $value ) {
		echo '<tr>';
		echo '<th scope="row">' . esc_html( (string) $label ) . '</th>';
		echo '<td>' . esc_html( (string) $value ) . '</td>';
		echo '</tr>';
	}

	private function render_notice() {
		$notice      = sanitize_key( (string) ( $_GET['tvx_wcl_sm_notice'] ?? '' ) );
		$notice_text = sanitize_text_field( (string) ( $_GET['tvx_wcl_sm_notice_text'] ?? '' ) );

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

	private function redirect_with_notice( $type, $message ) {
		wp_safe_redirect(
			add_query_arg(
				array(
					'page'                  => self::SLUG,
					'tvx_wcl_sm_notice'     => sanitize_key( (string) $type ),
					'tvx_wcl_sm_notice_text'=> sanitize_text_field( (string) $message ),
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	private function assert_manage_permission() {
		if ( ! CapabilityManager::current_user_can_manage_configuration() ) {
			wp_die( esc_html__( 'No tienes permisos para administrar esta integración.', 'tvx-woo-change-log' ) );
		}
	}

	private function format_mode_label( $mode ) {
		$labels = array(
			'native_only'               => 'native_only',
			'stock_manager_import_only' => 'import_only',
			'stock_manager_bridge'      => 'bridge',
		);

		return $labels[ $mode ] ?? $mode;
	}
}
