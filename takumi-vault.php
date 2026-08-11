<?php
/**
 * Plugin Name: Takumi Vault - Backup & Restore Manager
 * Plugin URI:  https://takumi.ca
 * Description: A clean, client-friendly backup & restore manager for WordPress. Back up your database and files, and restore them with a single click.
 * Version:     1.0.0
 * Author:      Yoshiro Moriyama (Takumi Web Services)
 * Author URI:  https://takumi.ca
 * License:     GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: takumi-vault
 * Domain Path: /languages
 *
 * Developed and maintained by Yoshiro Moriyama, founder of Takumi Web Services
 * — a WordPress development studio based in Toronto, Canada.
 *
 * @package TakumiVault
 */

defined( 'ABSPATH' ) || exit;

define( 'TKVAULT_VERSION', '1.0.0' );
define( 'TKVAULT_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'TKVAULT_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'TKVAULT_PLUGIN_FILE', __FILE__ );

require_once TKVAULT_PLUGIN_DIR . 'includes/class-tkvault-db.php';
require_once TKVAULT_PLUGIN_DIR . 'includes/class-tkvault-backup.php';
require_once TKVAULT_PLUGIN_DIR . 'includes/class-tkvault-restore.php';
require_once TKVAULT_PLUGIN_DIR . 'includes/class-tkvault-scheduler.php';
require_once TKVAULT_PLUGIN_DIR . 'includes/class-tkvault-admin.php';

register_activation_hook( __FILE__, 'tkvault_activate' );
register_deactivation_hook( __FILE__, 'tkvault_deactivate' );

function tkvault_activate() {
	TKVault_DB::create_tables();
	tkvault_ensure_backup_dir();
}

function tkvault_deactivate() {
	TKVault_Scheduler::clear_scheduled_events();
}

function tkvault_get_backup_dir() {
	$default = dirname( ABSPATH ) . '/_backup';
	return get_option( 'tkvault_backup_dir', $default );
}

function tkvault_ensure_backup_dir() {
	$dir = tkvault_get_backup_dir();
	if ( ! is_dir( $dir ) ) {
		if ( ! wp_mkdir_p( $dir ) ) {
			return new WP_Error(
				'dir_create_failed',
				__( 'Could not create the backup directory.', 'takumi-vault' )
			);
		}
		// Prevent direct web access on Apache.
		file_put_contents( $dir . '/.htaccess', 'deny from all' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
	}
	return $dir;
}

add_action( 'plugins_loaded', 'tkvault_init' );

function tkvault_init() {
	// No load_plugin_textdomain() call: WordPress has loaded translations for
	// plugins hosted on WordPress.org automatically since 4.6.
	new TKVault_Admin();
	new TKVault_Scheduler();
}
