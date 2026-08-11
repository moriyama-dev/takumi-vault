<?php
defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

require_once plugin_dir_path( __FILE__ ) . 'includes/class-tkvault-db.php';

TKVault_DB::drop_tables();

$tkvault_options = array(
	'tkvault_backup_dir',
	'tkvault_schedule',
	'tkvault_keep_generations',
	'tkvault_notify_email',
	'tkvault_notify_on_success',
);

foreach ( $tkvault_options as $tkvault_option ) {
	delete_option( $tkvault_option );
}

$tkvault_timestamp = wp_next_scheduled( 'tkvault_scheduled_backup' );
if ( $tkvault_timestamp ) {
	wp_unschedule_event( $tkvault_timestamp, 'tkvault_scheduled_backup' );
}
