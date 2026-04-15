<?php

namespace ASDLabs\TVXWooChangeLog\Integrations\YITH;

final class CostMetaResolver {
	public function get_monitored_meta_keys() {
		$keys = apply_filters( 'asdl_tvx_wc_yith_cost_meta_keys', array() );
		$keys = apply_filters( 'tvx_wcl_yith_cost_meta_keys', $keys );
		if ( ! is_array( $keys ) ) {
			return array();
		}

		return array_values( array_unique( array_filter( array_map( 'sanitize_key', $keys ) ) ) );
	}

	public function is_available() {
		return ! empty( $this->get_monitored_meta_keys() );
	}

	public function get_status_label() {
		if ( $this->is_available() ) {
			return 'YITH Cost of Goods habilitado mediante meta keys verificadas/configuradas en runtime.';
		}

		return 'YITH Cost of Goods no fue localizado en la inspección local. ASD Labs Product Audit & Stock Reverter sigue auditando stock y regular price sin romper la UI.';
	}

	public function get_locally_observed_notes() {
		return array(
			'No se encontró un plugin local de YITH Cost of Goods en este árbol.',
			'Se observaron referencias históricas a yith_cog_cost en otros plugins, pero no se activan por defecto por no estar verificadas localmente.',
			'También se detectaron costos no-YITH: _wc_cog_cost, _wc_cog_cost_variable y _op_cost_price.',
		);
	}
}
