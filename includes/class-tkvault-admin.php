<?php
defined( 'ABSPATH' ) || exit;

class TKVault_Admin {

	public function __construct() {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'wp_ajax_tkvault_run_backup', array( $this, 'ajax_run_backup' ) );
		add_action( 'wp_ajax_tkvault_run_restore', array( $this, 'ajax_run_restore' ) );
		add_action( 'wp_ajax_tkvault_delete_backup', array( $this, 'ajax_delete_backup' ) );
		add_action( 'admin_post_tkvault_download_backup', array( $this, 'handle_download' ) );
		add_action( 'admin_post_tkvault_save_settings', array( $this, 'save_settings' ) );
	}

	public function register_menu() {
		add_menu_page(
			__( 'Takumi Vault', 'takumi-vault' ),
			__( 'Takumi Vault', 'takumi-vault' ),
			'manage_options',
			'takumi-vault',
			array( $this, 'render_dashboard' ),
			'dashicons-shield',
			80
		);

		add_submenu_page(
			'takumi-vault',
			__( 'Dashboard', 'takumi-vault' ),
			__( 'Dashboard', 'takumi-vault' ),
			'manage_options',
			'takumi-vault',
			array( $this, 'render_dashboard' )
		);

		add_submenu_page(
			'takumi-vault',
			__( 'Backups', 'takumi-vault' ),
			__( 'Backups', 'takumi-vault' ),
			'manage_options',
			'takumi-vault-list',
			array( $this, 'render_backup_list' )
		);

		add_submenu_page(
			'takumi-vault',
			__( 'Settings', 'takumi-vault' ),
			__( 'Settings', 'takumi-vault' ),
			'manage_options',
			'takumi-vault-settings',
			array( $this, 'render_settings' )
		);
	}

	public function enqueue_assets( string $hook ) {
		if ( false === strpos( $hook, 'takumi-vault' ) ) {
			return;
		}
		wp_enqueue_style(
			'tkvault-admin',
			TKVAULT_PLUGIN_URL . 'admin/css/takumi-vault-admin.css',
			array(),
			TKVAULT_VERSION
		);
		wp_enqueue_script(
			'tkvault-admin',
			TKVAULT_PLUGIN_URL . 'admin/js/takumi-vault-admin.js',
			array( 'jquery' ),
			TKVAULT_VERSION,
			true
		);
		wp_localize_script(
			'tkvault-admin',
			'tkvaultAdmin',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'tkvault_nonce' ),
				'i18n'    => array(
					'confirmRestore' => __( 'Restoring will overwrite your current data. Continue?', 'takumi-vault' ),
					'confirmDelete'  => __( 'Delete this backup?', 'takumi-vault' ),
					'running'        => __( 'Working...', 'takumi-vault' ),
					'idle'           => __( 'Start backup', 'takumi-vault' ),
					'success'        => __( 'Done.', 'takumi-vault' ),
					'error'          => __( 'Something went wrong.', 'takumi-vault' ),
				),
			)
		);
	}

	public function render_dashboard() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'takumi-vault' ) );
		}
		include TKVAULT_PLUGIN_DIR . 'admin/views/dashboard.php';
	}

	public function render_backup_list() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'takumi-vault' ) );
		}
		include TKVAULT_PLUGIN_DIR . 'admin/views/backup-list.php';
	}

	public function render_settings() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'takumi-vault' ) );
		}
		include TKVAULT_PLUGIN_DIR . 'admin/views/settings.php';
	}

	public function ajax_run_backup() {
		check_ajax_referer( 'tkvault_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission to do that.', 'takumi-vault' ) ) );
		}

		$type = isset( $_POST['type'] ) ? sanitize_text_field( wp_unslash( $_POST['type'] ) ) : 'full';
		$note = isset( $_POST['note'] ) ? sanitize_textarea_field( wp_unslash( $_POST['note'] ) ) : '';

		$backup = new TKVault_Backup();
		$result = $backup->run( $type, $note );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		wp_send_json_success( array( 'message' => __( 'Backup complete.', 'takumi-vault' ) ) );
	}

	public function ajax_run_restore() {
		check_ajax_referer( 'tkvault_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission to do that.', 'takumi-vault' ) ) );
		}

		$backup_id = isset( $_POST['backup_id'] ) ? absint( $_POST['backup_id'] ) : 0;
		$type      = isset( $_POST['type'] ) ? sanitize_text_field( wp_unslash( $_POST['type'] ) ) : 'full';

		if ( ! $backup_id ) {
			wp_send_json_error( array( 'message' => __( 'Invalid backup ID.', 'takumi-vault' ) ) );
		}

		$restore = new TKVault_Restore();
		$result  = $restore->run( $backup_id, $type );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		wp_send_json_success( array( 'message' => __( 'Restore complete.', 'takumi-vault' ) ) );
	}

	public function ajax_delete_backup() {
		check_ajax_referer( 'tkvault_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission to do that.', 'takumi-vault' ) ) );
		}

		$backup_id = isset( $_POST['backup_id'] ) ? absint( $_POST['backup_id'] ) : 0;
		if ( ! $backup_id ) {
			wp_send_json_error( array( 'message' => __( 'Invalid backup ID.', 'takumi-vault' ) ) );
		}

		$record = TKVault_DB::get_backup_by_id( $backup_id );
		if ( $record ) {
			$filepath = self::resolve_backup_file( $record->filename );
			if ( $filepath ) {
				wp_delete_file( $filepath );
			}
			TKVault_DB::delete_backup_record( $backup_id );
		}

		wp_send_json_success( array( 'message' => __( 'Deleted.', 'takumi-vault' ) ) );
	}

	/**
	 * Resolve a stored filename to a real path inside the backup directory.
	 *
	 * Returns false unless the resolved path is genuinely contained in the
	 * backup directory. The separator matters: without it, a sibling such as
	 * "/_backup-evil" would pass a plain prefix test against "/_backup".
	 *
	 * @return string|false
	 */
	private static function resolve_backup_file( string $filename ) {
		$real_dir = realpath( tkvault_get_backup_dir() );
		if ( false === $real_dir ) {
			return false;
		}

		$candidate = trailingslashit( $real_dir ) . basename( $filename );
		$real_file = realpath( $candidate );

		if ( false === $real_file || ! is_file( $real_file ) ) {
			return false;
		}
		if ( 0 !== strpos( $real_file, trailingslashit( $real_dir ) ) ) {
			return false;
		}

		return $real_file;
	}

	public function handle_download() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'takumi-vault' ) );
		}

		$backup_id = isset( $_GET['backup_id'] ) ? absint( $_GET['backup_id'] ) : 0;
		check_admin_referer( 'tkvault_download_' . $backup_id );

		$record = TKVault_DB::get_backup_by_id( $backup_id );
		if ( ! $record ) {
			wp_die( esc_html__( 'Backup not found.', 'takumi-vault' ) );
		}

		$filepath = self::resolve_backup_file( $record->filename );
		if ( ! $filepath ) {
			wp_die( esc_html__( 'Backup file not found.', 'takumi-vault' ) );
		}

		nocache_headers();
		header( 'Content-Description: File Transfer' );
		header( 'Content-Type: application/zip' );
		header( 'Content-Disposition: attachment; filename="' . basename( $filepath ) . '"' );
		header( 'Content-Length: ' . filesize( $filepath ) );
		readfile( $filepath ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile
		exit;
	}

	public function save_settings() {
		check_admin_referer( 'tkvault_settings' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'takumi-vault' ) );
		}

		$backup_dir        = isset( $_POST['tkvault_backup_dir'] ) ? sanitize_text_field( wp_unslash( $_POST['tkvault_backup_dir'] ) ) : '';
		$schedule          = isset( $_POST['tkvault_schedule'] ) ? sanitize_text_field( wp_unslash( $_POST['tkvault_schedule'] ) ) : 'none';
		$keep_generations  = isset( $_POST['tkvault_keep_generations'] ) ? absint( $_POST['tkvault_keep_generations'] ) : 10;
		$notify_email      = isset( $_POST['tkvault_notify_email'] ) ? sanitize_email( wp_unslash( $_POST['tkvault_notify_email'] ) ) : '';
		$notify_on_success = isset( $_POST['tkvault_notify_on_success'] ) ? 1 : 0;

		// TODO(step 1): run the write test and the canary exposure probe before
		// accepting a new path. Until then the value is stored as entered.
		if ( $backup_dir ) {
			update_option( 'tkvault_backup_dir', $backup_dir );
		}
		update_option( 'tkvault_schedule', $schedule );
		update_option( 'tkvault_keep_generations', $keep_generations );
		update_option( 'tkvault_notify_email', $notify_email );
		update_option( 'tkvault_notify_on_success', $notify_on_success );

		$scheduler = new TKVault_Scheduler();
		if ( 'none' === $schedule ) {
			TKVault_Scheduler::clear_scheduled_events();
		} else {
			$scheduler->schedule( $schedule );
		}

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'    => 'takumi-vault-settings',
					'updated' => '1',
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}
}
