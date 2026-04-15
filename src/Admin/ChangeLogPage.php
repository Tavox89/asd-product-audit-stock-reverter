<?php

namespace ASDLabs\TVXWooChangeLog\Admin;

use ASDLabs\TVXWooChangeLog\Admin\ListTables\ChangeLogListTable;
use ASDLabs\TVXWooChangeLog\Core\CapabilityManager;
use ASDLabs\TVXWooChangeLog\DB\Repositories\ChangeLogRepository;
use ASDLabs\TVXWooChangeLog\Integrations\YITH\CostMetaResolver;
use ASDLabs\TVXWooChangeLog\Support\Time;

final class ChangeLogPage {
	const SLUG = 'tvx-wcl-change-log';

	private $repository;
	private $cost_meta_resolver;

	public function __construct() {
		$this->repository         = new ChangeLogRepository();
		$this->cost_meta_resolver = new CostMetaResolver();
	}

	public function register() {
		// Página solo de render por ahora.
	}

	public function render() {
		if ( ! CapabilityManager::current_user_can_view_log() ) {
			wp_die( esc_html__( 'No tienes permisos para ver el change log.', 'tvx-woo-change-log' ) );
		}

		$filters    = $this->build_filters();
		$list_table = new ChangeLogListTable( $this->repository, $filters );
		$list_table->prepare_items();
		$users = $this->repository->get_distinct_users();

		echo '<div class="wrap asdl-wcl-admin">';
		echo '<h1>ASD Labs Product Audit Log</h1>';
		echo '<p>Auditoría canónica de stock, regular price, costo YITH cuando exista una meta válida y eventos de reversión arbitraria. La tabla visible siempre sale de la base ASD Labs.</p>';

		if ( ! $this->cost_meta_resolver->is_available() ) {
			echo '<div class="notice notice-info"><p>' . esc_html( $this->cost_meta_resolver->get_status_label() ) . '</p></div>';
		}

		if ( current_user_can( CapabilityManager::MANAGE_CONFIGURATION ) ) {
			echo '<p><a class="button button-secondary" href="' . esc_url( admin_url( 'admin.php?page=' . StockManagerCompatPage::SLUG ) ) . '">Ver compatibilidad Stock Manager</a></p>';
		}

		echo '<form method="get">';
		echo '<input type="hidden" name="page" value="' . esc_attr( self::SLUG ) . '" />';

		$list_table->search_box( 'Buscar producto / SKU / ID', 'tvx-wcl-change-log' );

		echo '<div style="margin:12px 0 16px; display:flex; gap:8px; flex-wrap:wrap; align-items:flex-end;">';
		$this->render_field_select( $filters['field_key'] ?? '' );
		$this->render_user_select( $users, $filters['user_id'] ?? 0 );
		$this->render_scope_select( $filters['scope'] ?? '' );
		$this->render_date_inputs( $filters );
		submit_button( 'Filtrar', 'secondary', '', false );
		echo '<a class="button" href="' . esc_url( admin_url( 'admin.php?page=' . self::SLUG ) ) . '">Limpiar</a>';
		echo '</div>';

		$list_table->display();
		echo '</form>';
		echo '</div>';
	}

	private function build_filters() {
		$date_from = sanitize_text_field( (string) ( $_GET['date_from'] ?? '' ) );
		$date_to   = sanitize_text_field( (string) ( $_GET['date_to'] ?? '' ) );

		return array(
			'search'        => sanitize_text_field( (string) ( $_GET['s'] ?? '' ) ),
			'field_key'     => sanitize_key( (string) ( $_GET['field_key'] ?? '' ) ),
			'user_id'       => absint( $_GET['user_id'] ?? 0 ),
			'scope'         => sanitize_key( (string) ( $_GET['scope'] ?? '' ) ),
			'date_from'     => $date_from,
			'date_to'       => $date_to,
			'date_from_utc' => '' !== $date_from ? Time::site_date_to_utc_boundary( $date_from, 'start' ) : '',
			'date_to_utc'   => '' !== $date_to ? Time::site_date_to_utc_boundary( $date_to, 'end' ) : '',
		);
	}

	private function render_field_select( $selected ) {
		$options = array(
			''              => 'Todos los cambios',
			'stock'         => 'Stock',
			'regular_price' => 'Regular price',
			'yith_cost'     => 'Costo YITH',
		);

		echo '<label>';
		echo '<span style="display:block; margin-bottom:4px;">Campo</span>';
		echo '<select name="field_key">';
		foreach ( $options as $value => $label ) {
			echo '<option value="' . esc_attr( $value ) . '" ' . selected( $selected, $value, false ) . '>' . esc_html( $label ) . '</option>';
		}
		echo '</select>';
		echo '</label>';
	}

	private function render_user_select( array $users, $selected ) {
		echo '<label>';
		echo '<span style="display:block; margin-bottom:4px;">Usuario</span>';
		echo '<select name="user_id">';
		echo '<option value="">Todos</option>';

		foreach ( $users as $row ) {
			$user_id = absint( $row['user_id'] ?? 0 );
			$name    = sanitize_text_field( (string) ( $row['display_name'] ?? '' ) );
			echo '<option value="' . esc_attr( $user_id ) . '" ' . selected( $selected, $user_id, false ) . '>' . esc_html( $name ?: 'Usuario #' . $user_id ) . '</option>';
		}

		echo '</select>';
		echo '</label>';
	}

	private function render_scope_select( $selected ) {
		$options = array(
			''          => 'Todos',
			'product'   => 'Productos',
			'variation' => 'Variaciones',
		);

		echo '<label>';
		echo '<span style="display:block; margin-bottom:4px;">Ámbito</span>';
		echo '<select name="scope">';
		foreach ( $options as $value => $label ) {
			echo '<option value="' . esc_attr( $value ) . '" ' . selected( $selected, $value, false ) . '>' . esc_html( $label ) . '</option>';
		}
		echo '</select>';
		echo '</label>';
	}

	private function render_date_inputs( array $filters ) {
		echo '<label>';
		echo '<span style="display:block; margin-bottom:4px;">Desde</span>';
		echo '<input type="date" name="date_from" value="' . esc_attr( $filters['date_from'] ?? '' ) . '" />';
		echo '</label>';

		echo '<label>';
		echo '<span style="display:block; margin-bottom:4px;">Hasta</span>';
		echo '<input type="date" name="date_to" value="' . esc_attr( $filters['date_to'] ?? '' ) . '" />';
		echo '</label>';
	}
}
