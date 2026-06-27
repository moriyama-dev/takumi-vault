<?php
defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

require_once plugin_dir_path( __FILE__ ) . 'includes/class-wp-vault-db.php';

WP_Vault_DB::drop_tables();

$options = array(
	'wpvault_backup_dir',
	'wpvault_schedule',
	'wpvault_keep_generations',
	'wpvault_notify_email',
	'wpvault_notify_on_success',
);

foreach ( $options as $option ) {
	delete_option( $option );
}

$timestamp = wp_next_scheduled( 'wpvault_scheduled_backup' );
if ( $timestamp ) {
	wp_unschedule_event( $timestamp, 'wpvault_scheduled_backup' );
}
