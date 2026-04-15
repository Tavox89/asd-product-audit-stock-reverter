<?php

namespace ASDLabs\TVXWooChangeLog\Core;

final class Schema {
	const VERSION = '2026.04.14-beta2';

	public static function get_queries() {
		global $wpdb;

		$charset_collate = $wpdb->get_charset_collate();
		$change_log      = Tables::name( 'change_log' );
		$reversions      = Tables::name( 'reversions' );

		return array(
			"CREATE TABLE {$change_log} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				event_hash char(64) NOT NULL,
				product_id bigint(20) unsigned NOT NULL DEFAULT 0,
				variation_id bigint(20) unsigned DEFAULT NULL,
				parent_id bigint(20) unsigned DEFAULT NULL,
				product_type varchar(40) DEFAULT '',
				sku varchar(191) DEFAULT '',
				product_name varchar(255) NOT NULL,
				field_key varchar(32) NOT NULL,
				old_value longtext NULL,
				new_value longtext NULL,
				delta_value decimal(20,6) DEFAULT NULL,
				changed_by_user_id bigint(20) unsigned DEFAULT NULL,
				changed_by_name varchar(191) DEFAULT '',
				actor_type varchar(20) DEFAULT 'system',
				source_system varchar(40) DEFAULT 'native',
				source_external_id varchar(191) DEFAULT '',
				import_flag tinyint(1) unsigned NOT NULL DEFAULT 0,
				bridge_flag tinyint(1) unsigned NOT NULL DEFAULT 0,
				source_context varchar(40) DEFAULT 'unknown',
				request_fingerprint char(64) DEFAULT '',
				context_json longtext NULL,
				created_at_utc datetime NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY event_hash (event_hash),
				KEY product_id (product_id),
				KEY variation_id (variation_id),
				KEY field_key (field_key),
				KEY changed_by_user_id (changed_by_user_id),
				KEY source_system (source_system),
				KEY import_flag (import_flag),
				KEY created_at_utc (created_at_utc),
				KEY request_fingerprint (request_fingerprint)
			) {$charset_collate};",
			"CREATE TABLE {$reversions} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				order_id bigint(20) unsigned NOT NULL,
				order_number varchar(191) DEFAULT '',
				invoice_number varchar(191) DEFAULT '',
				executed_by_user_id bigint(20) unsigned DEFAULT NULL,
				executed_by_name varchar(191) DEFAULT '',
				executed_at_utc datetime NOT NULL,
				reason_label varchar(40) DEFAULT 'arbitrary_revert',
				lock_key varchar(191) DEFAULT '',
				result_status varchar(40) DEFAULT 'success',
				items_restored_json longtext NULL,
				items_skipped_json longtext NULL,
				items_failed_json longtext NULL,
				context_json longtext NULL,
				PRIMARY KEY  (id),
				KEY order_id (order_id),
				KEY executed_by_user_id (executed_by_user_id),
				KEY executed_at_utc (executed_at_utc),
				KEY result_status (result_status)
			) {$charset_collate};",
		);
	}
}
