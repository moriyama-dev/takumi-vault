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
		add_action( 'admin_notices', array( $this, 'render_notices' ) );
		add_action( 'wp_ajax_tkvault_start_selftest', array( $this, 'ajax_start_selftest' ) );
		add_action( 'wp_ajax_tkvault_cancel_job', array( $this, 'ajax_cancel_job' ) );
		add_action( 'wp_ajax_tkvault_start_backup', array( $this, 'ajax_start_backup' ) );
		add_action( 'wp_ajax_tkvault_start_restore', array( $this, 'ajax_start_restore' ) );
		add_action( 'wp_ajax_tkvault_undo_restore', array( $this, 'ajax_undo_restore' ) );
	}

	/**
	 * Start the background-processing self-test.
	 *
	 * Creating jobs is deliberately kept here, behind a nonce and a capability
	 * check, and out of the loopback endpoint, which can only ever advance
	 * work that an authenticated request already asked for.
	 */
	public function ajax_start_selftest() {
		check_ajax_referer( 'tkvault_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission to do that.', 'takumi-vault' ) ) );
		}

		$chunks = isset( $_POST['chunks'] ) ? max( 1, min( 200, absint( $_POST['chunks'] ) ) ) : 20;

		$job = TKVault_Jobs::create(
			'demo',
			array(
				'chunks'  => $chunks,
				'work_ms' => 120,
			),
			$chunks
		);

		TKVault_Runner::dispatch( $job['id'] );

		wp_send_json_success( TKVault_Runner::snapshot( TKVault_Jobs::get( $job['id'] ) ) );
	}

	/**
	 * Queue a database backup and hand the job id back for polling.
	 */
	public function ajax_start_backup() {
		check_ajax_referer( 'tkvault_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission to do that.', 'takumi-vault' ) ) );
		}

		$note = isset( $_POST['note'] ) ? sanitize_textarea_field( wp_unslash( $_POST['note'] ) ) : '';

		$dir = tkvault_ensure_backup_dir();
		if ( is_wp_error( $dir ) ) {
			wp_send_json_error( array( 'message' => $dir->get_error_message() ) );
		}

		if ( TKVault_Storage::PUBLIC_YES === TKVault_Storage::reprobe() ) {
			wp_send_json_error(
				array( 'message' => __( 'The backup directory is downloadable over HTTP. Change the destination before backing up.', 'takumi-vault' ) )
			);
		}

		$job = TKVault_DB_Dump::start( $note );
		if ( is_wp_error( $job ) ) {
			wp_send_json_error( array( 'message' => $job->get_error_message() ) );
		}

		TKVault_Runner::dispatch( $job['id'] );

		wp_send_json_success( TKVault_Runner::snapshot( TKVault_Jobs::get( $job['id'] ) ) );
	}

	/**
	 * Queue a restore.
	 *
	 * The old synchronous handler is gone: a restore that cannot be watched is
	 * a restore nobody can tell has stalled.
	 */
	public function ajax_start_restore() {
		check_ajax_referer( 'tkvault_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission to do that.', 'takumi-vault' ) ) );
		}

		$backup_id = isset( $_POST['backup_id'] ) ? absint( $_POST['backup_id'] ) : 0;
		$confirm   = isset( $_POST['confirm_space'] ) && '1' === $_POST['confirm_space'];

		if ( ! $backup_id ) {
			wp_send_json_error( array( 'message' => __( 'Invalid backup ID.', 'takumi-vault' ) ) );
		}

		$job = TKVault_DB_Restore::start( $backup_id, $confirm );
		if ( is_wp_error( $job ) ) {
			wp_send_json_error( array( 'message' => $job->get_error_message() ) );
		}

		TKVault_Runner::dispatch( $job['id'] );

		wp_send_json_success( TKVault_Runner::snapshot( TKVault_Jobs::get( $job['id'] ) ) );
	}

	public function ajax_undo_restore() {
		check_ajax_referer( 'tkvault_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission to do that.', 'takumi-vault' ) ) );
		}

		$result = TKVault_DB_Restore::undo();
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		wp_send_json_success( array( 'message' => __( 'The previous database has been put back.', 'takumi-vault' ) ) );
	}

	public function ajax_cancel_job() {
		check_ajax_referer( 'tkvault_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission to do that.', 'takumi-vault' ) ) );
		}

		$job_id = isset( $_POST['job'] ) ? absint( $_POST['job'] ) : 0;
		if ( ! $job_id ) {
			wp_send_json_error( array( 'message' => __( 'Invalid job.', 'takumi-vault' ) ) );
		}

		TKVault_Jobs::cancel( $job_id );
		wp_send_json_success( TKVault_Runner::snapshot( TKVault_Jobs::get( $job_id ) ) );
	}

	/**
	 * Standing warnings that must survive navigating away.
	 *
	 * The exposure alert in particular is not dismissible: a directory that
	 * became downloadable stays downloadable until someone moves it, and a
	 * notice the user can wave away is no use for that.
	 */
	public function render_notices() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$settings_url = admin_url( 'admin.php?page=takumi-vault-settings' );

		if ( TKVault_Storage::PUBLIC_YES === TKVault_Storage::last_verdict() ) {
			printf(
				'<div class="notice notice-error"><p><strong>%s</strong> %s</p><p><a href="%s" class="button button-primary">%s</a></p></div>',
				esc_html__( 'Takumi Vault:', 'takumi-vault' ),
				esc_html__( 'the backup directory can be downloaded over HTTP. Anyone who guesses the path can take a copy of your database. Move it to a directory outside the web root.', 'takumi-vault' ),
				esc_url( $settings_url ),
				esc_html__( 'Change the destination', 'takumi-vault' )
			);

			if ( TKVault_Storage::has_alert() ) {
				printf(
					'<div class="notice notice-error"><p>%s</p></div>',
					esc_html__( 'Takumi Vault: this destination used to be private and is not any more, so scheduled backups have been stopped. Re-enable them once the destination is safe again.', 'takumi-vault' )
				);
			}
			return;
		}

		if ( ! TKVault_Storage::get_dir() ) {
			printf(
				'<div class="notice notice-warning"><p><strong>%s</strong> %s <a href="%s">%s</a></p></div>',
				esc_html__( 'Takumi Vault:', 'takumi-vault' ),
				esc_html__( 'no backup destination is configured, so backups cannot run.', 'takumi-vault' ),
				esc_url( $settings_url ),
				esc_html__( 'Set one now', 'takumi-vault' )
			);
		}
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

		add_submenu_page(
			'takumi-vault',
			__( 'Diagnostics', 'takumi-vault' ),
			__( 'Diagnostics', 'takumi-vault' ),
			'manage_options',
			'takumi-vault-preflight',
			array( $this, 'render_preflight' )
		);
	}

	public function render_preflight() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'takumi-vault' ) );
		}
		include TKVAULT_PLUGIN_DIR . 'admin/views/preflight.php';
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
					/* translators: 1: chunks processed so far, 2: total chunks */
					'jobRunning'     => __( 'Running: %1$d of %2$d', 'takumi-vault' ),
					/* translators: 1: chunks processed, 2: total chunks */
					'jobComplete'    => __( 'Finished: %1$d of %2$d chunks processed.', 'takumi-vault' ),
					'jobFailed'      => __( 'Failed.', 'takumi-vault' ),
					'jobCancelled'   => __( 'Cancelled.', 'takumi-vault' ),
					'jobStarting'    => __( 'Starting...', 'takumi-vault' ),
					'selfTest'       => __( 'Run self-test', 'takumi-vault' ),
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
		$real_dir = realpath( TKVault_Storage::get_store_dir() );
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
		$accept_public     = isset( $_POST['tkvault_accept_public'] ) && '1' === $_POST['tkvault_accept_public'];

		// A submitted path is put through exactly the same checks as an
		// automatically chosen one. Nothing is stored until they pass.
		if ( $backup_dir && $backup_dir !== TKVault_Storage::get_dir() ) {
			$saved = $this->save_backup_dir( $backup_dir, $accept_public );
			if ( is_wp_error( $saved ) ) {
				$this->redirect_with_error( $saved, $backup_dir );
			}
		}

		update_option( 'tkvault_schedule', $schedule );
		update_option( 'tkvault_keep_generations', $keep_generations );
		update_option( 'tkvault_notify_email', $notify_email );
		update_option( 'tkvault_notify_on_success', $notify_on_success );

		if ( isset( $_POST['tkvault_old_table_retention_days'] ) ) {
			$retention = (int) $_POST['tkvault_old_table_retention_days'];
			update_option( TKVault_DB_Restore::OPTION_RETENTION, max( -1, min( 365, $retention ) ) );
		}

		$scheduler = new TKVault_Scheduler();
		if ( 'none' === $schedule ) {
			TKVault_Scheduler::clear_scheduled_events();
		} else {
			$scheduler->schedule( $schedule );
		}

		delete_option( 'tkvault_settings_error' );

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

	/**
	 * Validate and store a user-supplied destination.
	 *
	 * The only way past a "public" verdict is the explicit checkbox. Without
	 * it the setting is refused outright rather than saved with a warning
	 * attached - a warning next to a saved value is indistinguishable from a
	 * warning next to a working one, and this is the failure that leaks the
	 * whole database.
	 *
	 * @return true|WP_Error
	 */
	private function save_backup_dir( $dir, $accept_public ) {
		$dir = wp_normalize_path( untrailingslashit( $dir ) );

		if ( ! path_is_absolute( $dir ) ) {
			return new WP_Error( 'tkvault_relative_path', __( 'Enter an absolute path.', 'takumi-vault' ) );
		}

		$result = TKVault_Storage::evaluate( $dir, false );

		if ( ! $result['ok'] && 'tkvault_dir_public' === $result['error']->get_error_code() ) {
			if ( ! $accept_public ) {
				return $result['error'];
			}
			// Accepted knowingly: re-run in the mode that keeps a public
			// directory, which also writes the full set of hardening files.
			$result = TKVault_Storage::evaluate( $dir, true );
			update_option( TKVault_Storage::OPTION_ACK, time(), false );
		}

		if ( ! $result['ok'] ) {
			return $result['error'];
		}

		update_option( TKVault_Storage::OPTION_DIR, $dir, false );
		TKVault_Storage::record_verdict( $result['verdict'] );
		delete_option( 'tkvault_setup_error' );

		return true;
	}

	private function redirect_with_error( WP_Error $error, $attempted ) {
		update_option(
			'tkvault_settings_error',
			array(
				'code'      => $error->get_error_code(),
				'message'   => $error->get_error_message(),
				'attempted' => $attempted,
			),
			false
		);

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'  => 'takumi-vault-settings',
					'error' => '1',
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}
}
