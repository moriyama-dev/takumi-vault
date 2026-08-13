<?php
defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

require_once plugin_dir_path( __FILE__ ) . 'includes/class-tkvault-db.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/class-tkvault-jobs.php';

TKVault_DB::drop_tables();
TKVault_Jobs::drop_table();

$tkvault_options = array(
	'tkvault_backup_dir',
	'tkvault_schedule',
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
);

foreach ( $tkvault_options as $tkvault_option ) {
	delete_option( $tkvault_option );
}

$tkvault_timestamp = wp_next_scheduled( 'tkvault_scheduled_backup' );
if ( $tkvault_timestamp ) {
	wp_unschedule_event( $tkvault_timestamp, 'tkvault_scheduled_backup' );
}
