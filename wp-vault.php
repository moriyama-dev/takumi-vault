<?php
/**
 * Plugin Name: WP Vault - Backup & Restore Manager
 * Plugin URI:  https://takumi.ca
 * Description: A clean, client-friendly backup & restore manager for WordPress. Back up your database and files, and restore them with a single click.
 * Version:     1.0.0
 * Author:      Yoshiro Moriyama (Takumi Web Services)
 * Author URI:  https://takumi.ca
 * License:     GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: wp-vault
 * Domain Path: /languages
 *
 * Developed and maintained by Yoshiro Moriyama, founder of Takumi Web Services
 * — a WordPress development studio based in Toronto, Canada.
 *
 * @package WPVault
 */

defined( 'ABSPATH' ) || exit;

define( 'WPVAULT_VERSION', '1.0.0' );
define( 'WPVAULT_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'WPVAULT_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'WPVAULT_PLUGIN_FILE', __FILE__ );

require_once WPVAULT_PLUGIN_DIR . 'includes/class-wp-vault-db.php';
require_once WPVAULT_PLUGIN_DIR . 'includes/class-wp-vault-backup.php';
require_once WPVAULT_PLUGIN_DIR . 'includes/class-wp-vault-restore.php';
require_once WPVAULT_PLUGIN_DIR . 'includes/class-wp-vault-scheduler.php';
require_once WPVAULT_PLUGIN_DIR . 'includes/class-wp-vault-admin.php';

register_activation_hook( __FILE__, 'wpvault_activate' );
register_deactivation_hook( __FILE__, 'wpvault_deactivate' );

function wpvault_activate() {
	WP_Vault_DB::create_tables();
	wpvault_ensure_backup_dir();
}

function wpvault_deactivate() {
	WP_Vault_Scheduler::clear_scheduled_events();
}

function wpvault_get_backup_dir() {
	$default = dirname( ABSPATH ) . '/_backup';
	return get_option( 'wpvault_backup_dir', $default );
}

function wpvault_ensure_backup_dir() {
	$dir = wpvault_get_backup_dir();
	if ( ! is_dir( $dir ) ) {
		if ( ! wp_mkdir_p( $dir ) ) {
			return new WP_Error(
				'dir_create_failed',
				__( 'バックアップディレクトリの作成に失敗しました。', 'wp-vault' )
			);
		}
		// Prevent direct web access on Apache.
		file_put_contents( $dir . '/.htaccess', 'deny from all' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
	}
	return $dir;
}

add_action( 'plugins_loaded', 'wpvault_init' );

function wpvault_init() {
	load_plugin_textdomain( 'wp-vault', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
	new WP_Vault_Admin();
	new WP_Vault_Scheduler();
}
