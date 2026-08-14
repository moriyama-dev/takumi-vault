<?php
defined( 'ABSPATH' ) || exit;

class TKVault_DB {

	public static function create_tables() {
		global $wpdb;

		$table_name      = $wpdb->prefix . 'tkvault_backups';
		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table_name} (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			filename VARCHAR(255) NOT NULL,
			type VARCHAR(20) NOT NULL,
			size BIGINT(20) DEFAULT 0,
			status VARCHAR(20) DEFAULT 'completed',
			note TEXT DEFAULT NULL,
			created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY (id)
		) {$charset_collate};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}

	public static function drop_tables() {
		global $wpdb;
		$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}tkvault_backups" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	public static function insert_backup( array $data ) {
		global $wpdb;
		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prefix . 'tkvault_backups',
			array(
				'filename' => sanitize_text_field( $data['filename'] ),
				'type'     => sanitize_text_field( $data['type'] ),
				'size'     => absint( $data['size'] ),
				'status'   => sanitize_text_field( $data['status'] ?? 'completed' ),
				'note'     => sanitize_textarea_field( $data['note'] ?? '' ),
			),
			array( '%s', '%s', '%d', '%s', '%s' )
		);
		return $wpdb->insert_id;
	}

	public static function get_all_backups( int $per_page = 20, int $page = 1 ) {
		global $wpdb;
		$offset = ( $page - 1 ) * $per_page;
		return $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT * FROM {$wpdb->prefix}tkvault_backups ORDER BY created_at DESC LIMIT %d OFFSET %d",
				$per_page,
				$offset
			)
		);
	}

	public static function get_total_count() {
		global $wpdb;
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}tkvault_backups" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	public static function get_total_size() {
		global $wpdb;
		return (int) $wpdb->get_var( "SELECT COALESCE(SUM(size),0) FROM {$wpdb->prefix}tkvault_backups" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	public static function get_backup_by_id( int $id ) {
		global $wpdb;
		return $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare( "SELECT * FROM {$wpdb->prefix}tkvault_backups WHERE id = %d", $id )
		);
	}

	/**
	 * Backups beyond the generations the site is keeping, oldest last.
	 *
	 * Safety dumps are excluded. They are taken automatically right before a
	 * restore, and counting them as generations would let a run of restores
	 * push out the real backups the site is meant to be keeping.
	 *
	 * @param int $keep Generations to keep.
	 * @return array
	 */
	public static function get_old_backups( int $keep = 10 ) {
		global $wpdb;
		return $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT * FROM {$wpdb->prefix}tkvault_backups WHERE type != 'db-safety' ORDER BY created_at DESC LIMIT 9999 OFFSET %d",
				$keep
			)
		);
	}

	public static function delete_backup_record( int $id ) {
		global $wpdb;
		$wpdb->delete( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prefix . 'tkvault_backups',
			array( 'id' => $id ),
			array( '%d' )
		);
	}
}
