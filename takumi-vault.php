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

require_once TKVAULT_PLUGIN_DIR . 'includes/class-tkvault-storage.php';
require_once TKVAULT_PLUGIN_DIR . 'includes/class-tkvault-db.php';
require_once TKVAULT_PLUGIN_DIR . 'includes/class-tkvault-backup.php';
require_once TKVAULT_PLUGIN_DIR . 'includes/class-tkvault-restore.php';
require_once TKVAULT_PLUGIN_DIR . 'includes/class-tkvault-scheduler.php';
require_once TKVAULT_PLUGIN_DIR . 'includes/class-tkvault-admin.php';

register_activation_hook( __FILE__, 'tkvault_activate' );
register_deactivation_hook( __FILE__, 'tkvault_deactivate' );

function tkvault_activate() {
	TKVault_DB::create_tables();

	// Pick a destination now so the first backup does not have to. A failure
	// here is not fatal to activation: the admin screens report it and the
	// user can set a path manually.
	$dir = TKVault_Storage::auto_configure();
	if ( is_wp_error( $dir ) ) {
		update_option( 'tkvault_setup_error', $dir->get_error_message(), false );
	} else {
		delete_option( 'tkvault_setup_error' );
	}
}

function tkvault_deactivate() {
	TKVault_Scheduler::clear_scheduled_events();
}

/**
 * The configured backup directory, or an empty string when none has been
 * successfully claimed. Callers must handle the empty case rather than
 * assuming a path exists.
 */
function tkvault_get_backup_dir() {
	return TKVault_Storage::get_dir();
}

/**
 * Resolve a usable backup directory, re-running the checks if needed.
 *
 * @return string|WP_Error
 */
function tkvault_ensure_backup_dir() {
	$dir = TKVault_Storage::get_dir();

	if ( ! $dir ) {
		return TKVault_Storage::auto_configure();
	}

	$result = TKVault_Storage::evaluate( $dir, false );
	if ( ! $result['ok'] ) {
		return $result['error'];
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
