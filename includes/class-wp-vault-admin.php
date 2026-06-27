<?php
defined( 'ABSPATH' ) || exit;

class WP_Vault_Admin {

	public function __construct() {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'wp_ajax_wpvault_run_backup', array( $this, 'ajax_run_backup' ) );
		add_action( 'wp_ajax_wpvault_run_restore', array( $this, 'ajax_run_restore' ) );
		add_action( 'wp_ajax_wpvault_delete_backup', array( $this, 'ajax_delete_backup' ) );
		add_action( 'wp_ajax_wpvault_download_backup', array( $this, 'handle_download' ) );
		add_action( 'admin_post_wpvault_save_settings', array( $this, 'save_settings' ) );
	}

	public function register_menu() {
		add_menu_page(
			__( 'WP Vault', 'wp-vault' ),
			__( 'WP Vault', 'wp-vault' ),
			'manage_options',
			'wp-vault',
			array( $this, 'render_dashboard' ),
			'dashicons-shield',
			80
		);

		add_submenu_page(
			'wp-vault',
			__( 'ダッシュボード', 'wp-vault' ),
			__( 'ダッシュボード', 'wp-vault' ),
			'manage_options',
			'wp-vault',
			array( $this, 'render_dashboard' )
		);

		add_submenu_page(
			'wp-vault',
			__( 'バックアップ一覧', 'wp-vault' ),
			__( 'バックアップ一覧', 'wp-vault' ),
			'manage_options',
			'wp-vault-list',
			array( $this, 'render_backup_list' )
		);

		add_submenu_page(
			'wp-vault',
			__( '設定', 'wp-vault' ),
			__( '設定', 'wp-vault' ),
			'manage_options',
			'wp-vault-settings',
			array( $this, 'render_settings' )
		);
	}

	public function enqueue_assets( string $hook ) {
		if ( false === strpos( $hook, 'wp-vault' ) ) {
			return;
		}
		wp_enqueue_style(
			'wp-vault-admin',
			WPVAULT_PLUGIN_URL . 'admin/css/wp-vault-admin.css',
			array(),
			WPVAULT_VERSION
		);
		wp_enqueue_script(
			'wp-vault-admin',
			WPVAULT_PLUGIN_URL . 'admin/js/wp-vault-admin.js',
			array( 'jquery' ),
			WPVAULT_VERSION,
			true
		);
		wp_localize_script(
			'wp-vault-admin',
			'wpVault',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'wpvault_nonce' ),
				'i18n'    => array(
					'confirmRestore' => __( 'リストアを実行すると現在のデータが上書きされます。本当に続けますか？', 'wp-vault' ),
					'confirmDelete'  => __( 'このバックアップを削除しますか？', 'wp-vault' ),
					'running'        => __( '実行中...', 'wp-vault' ),
					'success'        => __( '完了しました。', 'wp-vault' ),
					'error'          => __( 'エラーが発生しました。', 'wp-vault' ),
				),
			)
		);
	}

	public function render_dashboard() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( '権限がありません。', 'wp-vault' ) );
		}
		include WPVAULT_PLUGIN_DIR . 'admin/views/dashboard.php';
	}

	public function render_backup_list() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( '権限がありません。', 'wp-vault' ) );
		}
		include WPVAULT_PLUGIN_DIR . 'admin/views/backup-list.php';
	}

	public function render_settings() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( '権限がありません。', 'wp-vault' ) );
		}
		include WPVAULT_PLUGIN_DIR . 'admin/views/settings.php';
	}

	public function ajax_run_backup() {
		check_ajax_referer( 'wpvault_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( '権限がありません。', 'wp-vault' ) ) );
		}

		$type = isset( $_POST['type'] ) ? sanitize_text_field( wp_unslash( $_POST['type'] ) ) : 'full';
		$note = isset( $_POST['note'] ) ? sanitize_textarea_field( wp_unslash( $_POST['note'] ) ) : '';

		$backup = new WP_Vault_Backup();
		$result = $backup->run( $type, $note );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		wp_send_json_success( array( 'message' => __( 'バックアップが完了しました。', 'wp-vault' ) ) );
	}

	public function ajax_run_restore() {
		check_ajax_referer( 'wpvault_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( '権限がありません。', 'wp-vault' ) ) );
		}

		$backup_id = isset( $_POST['backup_id'] ) ? absint( $_POST['backup_id'] ) : 0;
		$type      = isset( $_POST['type'] ) ? sanitize_text_field( wp_unslash( $_POST['type'] ) ) : 'full';

		if ( ! $backup_id ) {
			wp_send_json_error( array( 'message' => __( '無効なバックアップIDです。', 'wp-vault' ) ) );
		}

		$restore = new WP_Vault_Restore();
		$result  = $restore->run( $backup_id, $type );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		wp_send_json_success( array( 'message' => __( 'リストアが完了しました。', 'wp-vault' ) ) );
	}

	public function ajax_delete_backup() {
		check_ajax_referer( 'wpvault_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( '権限がありません。', 'wp-vault' ) ) );
		}

		$backup_id = isset( $_POST['backup_id'] ) ? absint( $_POST['backup_id'] ) : 0;
		if ( ! $backup_id ) {
			wp_send_json_error( array( 'message' => __( '無効なバックアップIDです。', 'wp-vault' ) ) );
		}

		$record = WP_Vault_DB::get_backup_by_id( $backup_id );
		if ( $record ) {
			$filepath = trailingslashit( wpvault_get_backup_dir() ) . $record->filename;
			if ( file_exists( $filepath ) ) {
				@unlink( $filepath ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			}
			WP_Vault_DB::delete_backup_record( $backup_id );
		}

		wp_send_json_success( array( 'message' => __( '削除しました。', 'wp-vault' ) ) );
	}

	public function handle_download() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( '権限がありません。', 'wp-vault' ) );
		}

		$backup_id = isset( $_GET['backup_id'] ) ? absint( $_GET['backup_id'] ) : 0;
		check_admin_referer( 'wpvault_download_' . $backup_id );

		$record = WP_Vault_DB::get_backup_by_id( $backup_id );
		if ( ! $record ) {
			wp_die( esc_html__( 'バックアップが見つかりません。', 'wp-vault' ) );
		}

		$backup_dir = wpvault_get_backup_dir();
		$filepath   = trailingslashit( $backup_dir ) . $record->filename;

		$real_backup_dir = realpath( $backup_dir );
		$real_filepath   = realpath( $filepath );

		if ( false === $real_filepath || 0 !== strpos( $real_filepath, $real_backup_dir ) || ! file_exists( $real_filepath ) ) {
			wp_die( esc_html__( 'ファイルが見つかりません。', 'wp-vault' ) );
		}

		header( 'Content-Description: File Transfer' );
		header( 'Content-Type: application/zip' );
		header( 'Content-Disposition: attachment; filename="' . esc_attr( basename( $real_filepath ) ) . '"' );
		header( 'Content-Length: ' . filesize( $real_filepath ) );
		header( 'Pragma: no-cache' );
		readfile( $real_filepath ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile
		exit;
	}

	public function save_settings() {
		check_admin_referer( 'wpvault_settings' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( '権限がありません。', 'wp-vault' ) );
		}

		$backup_dir        = isset( $_POST['wpvault_backup_dir'] ) ? sanitize_text_field( wp_unslash( $_POST['wpvault_backup_dir'] ) ) : '';
		$schedule          = isset( $_POST['wpvault_schedule'] ) ? sanitize_text_field( wp_unslash( $_POST['wpvault_schedule'] ) ) : 'none';
		$keep_generations  = isset( $_POST['wpvault_keep_generations'] ) ? absint( $_POST['wpvault_keep_generations'] ) : 10;
		$notify_email      = isset( $_POST['wpvault_notify_email'] ) ? sanitize_email( wp_unslash( $_POST['wpvault_notify_email'] ) ) : '';
		$notify_on_success = isset( $_POST['wpvault_notify_on_success'] ) ? 1 : 0;

		if ( $backup_dir ) {
			update_option( 'wpvault_backup_dir', $backup_dir );
		}
		update_option( 'wpvault_schedule', $schedule );
		update_option( 'wpvault_keep_generations', $keep_generations );
		update_option( 'wpvault_notify_email', $notify_email );
		update_option( 'wpvault_notify_on_success', $notify_on_success );

		$scheduler = new WP_Vault_Scheduler();
		if ( 'none' === $schedule ) {
			WP_Vault_Scheduler::clear_scheduled_events();
		} else {
			$scheduler->schedule( $schedule );
		}

		wp_safe_redirect( add_query_arg( array( 'page' => 'wp-vault-settings', 'updated' => '1' ), admin_url( 'admin.php' ) ) );
		exit;
	}
}
