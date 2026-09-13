<?php
defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

global $wpdb;

require_once plugin_dir_path( __FILE__ ) . 'includes/class-tkvault-db.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/class-tkvault-jobs.php';

TKVault_DB::drop_tables();
TKVault_Jobs::drop_table();

/*
 * The copies of the site's own tables that a restore left behind so that it
 * could be undone. They are full duplicates of real data - a restored site can
 * be carrying a second copy of every table - and once this plugin is gone,
 * nothing is left that would ever drop them. Waiting for a retention window
 * that will never be checked again is not an option, so they go now.
 *
 * Only the names this site recorded itself are touched. Sweeping the database
 * for the temporary prefix would reach another WordPress sharing it and delete
 * the copies that installation is still keeping.
 */
$tkvault_old_tables = (array) get_option( 'tkvault_old_tables', array() );

foreach ( $tkvault_old_tables as $tkvault_entry ) {
	if ( empty( $tkvault_entry['old'] ) ) {
		continue;
	}

	$tkvault_name = '`' . str_replace( '`', '``', $tkvault_entry['old'] ) . '`';
	$wpdb->query( "DROP TABLE IF EXISTS {$tkvault_name}" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL
}

$tkvault_options = array(
	'tkvault_backup_dir',
	'tkvault_schedule',
	'tkvault_schedule_time',
	'tkvault_keep_generations',
	'tkvault_notify_email',
	'tkvault_notify_on_success',
	'tkvault_exposure_verdict',
	'tkvault_exposure_checked',
	'tkvault_exposure_alert',
	'tkvault_public_dir_acknowledged',
	'tkvault_relax_guard',
	'tkvault_loopback_ok',
	'tkvault_probed_version',
	'tkvault_setup_error',
	'tkvault_settings_error',
	'tkvault_old_tables',
	'tkvault_old_table_retention_days',
	'tkvault_last_file_manifest',
	'tkvault_replaced_files',
	'tkvault_scheduled_run',
	'tkvault_dir_modes',
);

foreach ( $tkvault_options as $tkvault_option ) {
	delete_option( $tkvault_option );
}

/*
 * Every hook this plugin can have scheduled, not only the backup one.
 * Deactivation clears them and normally runs first, but an uninstall that
 * happens without a clean deactivation would otherwise leave cron entries
 * pointing at code that no longer exists. wp_clear_scheduled_hook() removes
 * every occurrence, where unscheduling one timestamp removes only the next.
 */
$tkvault_hooks = array(
	'tkvault_scheduled_backup',
	'tkvault_reprobe_event',
	'tkvault_watchdog_event',
	'tkvault_old_tables_event',
);

foreach ( $tkvault_hooks as $tkvault_hook ) {
	wp_clear_scheduled_hook( $tkvault_hook );
}
