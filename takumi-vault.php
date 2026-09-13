<?php
/**
 * Plugin Name: Takumi Vault - Backup & Restore Manager
 * Plugin URI:  https://github.com/moriyama-dev/takumi-vault
 * Description: A clean, client-friendly backup & restore manager for WordPress. Back up your database and files, and restore them with a single click.
 * Version:     1.2.0
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

define( 'TKVAULT_VERSION', '1.2.0' );
define( 'TKVAULT_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'TKVAULT_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'TKVAULT_PLUGIN_FILE', __FILE__ );

require_once TKVAULT_PLUGIN_DIR . 'includes/class-tkvault-storage.php';
require_once TKVAULT_PLUGIN_DIR . 'includes/class-tkvault-preflight.php';
require_once TKVAULT_PLUGIN_DIR . 'includes/class-tkvault-jobs.php';
require_once TKVAULT_PLUGIN_DIR . 'includes/class-tkvault-runner.php';
require_once TKVAULT_PLUGIN_DIR . 'includes/class-tkvault-db.php';
require_once TKVAULT_PLUGIN_DIR . 'includes/class-tkvault-db-dump.php';
require_once TKVAULT_PLUGIN_DIR . 'includes/class-tkvault-sql-reader.php';
require_once TKVAULT_PLUGIN_DIR . 'includes/class-tkvault-db-restore.php';
require_once TKVAULT_PLUGIN_DIR . 'includes/class-tkvault-file-backup.php';
require_once TKVAULT_PLUGIN_DIR . 'includes/class-tkvault-file-restore.php';
require_once TKVAULT_PLUGIN_DIR . 'includes/class-tkvault-scheduler.php';
require_once TKVAULT_PLUGIN_DIR . 'includes/class-tkvault-admin.php';

// The command line is a first-class way to run this plugin, not an extra: it
// is the only path with no execution time limit, no loopback request and no
// dependency on WP-Cron, which is exactly what the hosts this plugin is built
// for struggle with.
if ( defined( 'WP_CLI' ) && WP_CLI ) {
	require_once TKVAULT_PLUGIN_DIR . 'includes/class-tkvault-cli.php';
	TKVault_CLI::register();
}

add_filter( 'cron_schedules', array( 'TKVault_Scheduler', 'add_cron_intervals' ) ); // phpcs:ignore WordPress.WP.CronInterval.ChangeDetected

TKVault_Runner::init();
TKVault_DB_Dump::init();
TKVault_DB_Restore::init();
TKVault_File_Backup::init();
TKVault_File_Restore::init();

register_activation_hook( __FILE__, 'tkvault_activate' );
register_deactivation_hook( __FILE__, 'tkvault_deactivate' );

function tkvault_activate() {
	TKVault_DB::create_tables();
	TKVault_Jobs::create_table();

	// Pick a destination now so the first backup does not have to. A failure
	// here is not fatal to activation: the admin screens report it and the
	// user can set a path manually.
	$dir = TKVault_Storage::auto_configure();
	if ( is_wp_error( $dir ) ) {
		update_option( 'tkvault_setup_error', $dir->get_error_message(), false );
	} else {
		delete_option( 'tkvault_setup_error' );
	}

	TKVault_Scheduler::schedule_reprobe_event();
	TKVault_Runner::schedule_watchdog();
	TKVault_DB_Restore::schedule_cleanup();
}

function tkvault_deactivate() {
	TKVault_Scheduler::clear_scheduled_events();
	TKVault_Scheduler::clear_reprobe_event();
	TKVault_Runner::clear_watchdog();
	TKVault_DB_Restore::clear_cleanup();
}

/**
 * Re-check exposure whenever something that could change the answer changes.
 *
 * A destination that was outside web-served space when the plugin was
 * installed does not stay that way by law. Moving to nginx, editing the
 * document root or a host reshuffling its configuration can all expose it,
 * and none of those events tell WordPress about themselves - so the triggers
 * below are proxies, backed by the weekly scheduled check.
 */
add_action( 'admin_init', 'tkvault_maybe_reprobe' );
add_action( 'update_option_siteurl', 'tkvault_force_reprobe' );
add_action( 'update_option_home', 'tkvault_force_reprobe' );
add_action( TKVault_Scheduler::REPROBE_HOOK, array( 'TKVault_Storage', 'reprobe' ) );

function tkvault_maybe_reprobe() {
	// Undo a widened directory left behind by a request that died mid-probe.
	TKVault_Storage::heal();

	if ( ! TKVault_Storage::get_dir() ) {
		return;
	}

	// A plugin update may change how any of this works, so re-check once.
	if ( get_option( 'tkvault_probed_version' ) !== TKVAULT_VERSION ) {
		update_option( 'tkvault_probed_version', TKVAULT_VERSION, false );
		TKVault_Storage::reprobe();
	}
}

function tkvault_force_reprobe() {
	if ( TKVault_Storage::get_dir() ) {
		TKVault_Storage::reprobe();
	}
}

/**
 * Endpoint used only by the preflight loopback test.
 */
add_action( 'wp_ajax_tkvault_loopback_ping', 'tkvault_loopback_ping' );
add_action( 'wp_ajax_nopriv_tkvault_loopback_ping', 'tkvault_loopback_ping' );

function tkvault_loopback_ping() {
	wp_send_json_success( array( 'pong' => true ) );
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

	$store = TKVault_Storage::ensure_store();
	if ( is_wp_error( $store ) ) {
		return $store;
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
