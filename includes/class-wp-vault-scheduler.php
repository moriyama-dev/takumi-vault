<?php
defined( 'ABSPATH' ) || exit;

class WP_Vault_Scheduler {

	public function __construct() {
		add_filter( 'cron_schedules', array( $this, 'add_cron_intervals' ) );
		add_action( 'wpvault_scheduled_backup', array( $this, 'run_scheduled_backup' ) );
	}

	public function add_cron_intervals( array $schedules ) {
		$schedules['wpvault_weekly'] = array(
			'interval' => WEEK_IN_SECONDS,
			'display'  => __( '毎週', 'wp-vault' ),
		);
		$schedules['wpvault_monthly'] = array(
			'interval' => 30 * DAY_IN_SECONDS,
			'display'  => __( '毎月', 'wp-vault' ),
		);
		return $schedules;
	}

	public function schedule( string $frequency ) {
		$this->clear_scheduled_events();

		$valid = array( 'daily', 'wpvault_weekly', 'wpvault_monthly' );
		if ( ! in_array( $frequency, $valid, true ) ) {
			return;
		}

		if ( ! wp_next_scheduled( 'wpvault_scheduled_backup' ) ) {
			wp_schedule_event( time(), $frequency, 'wpvault_scheduled_backup' );
		}
	}

	public static function clear_scheduled_events() {
		$timestamp = wp_next_scheduled( 'wpvault_scheduled_backup' );
		if ( $timestamp ) {
			wp_unschedule_event( $timestamp, 'wpvault_scheduled_backup' );
		}
	}

	public function run_scheduled_backup() {
		$backup = new WP_Vault_Backup();
		$result = $backup->run( 'full', __( 'スケジュール自動バックアップ', 'wp-vault' ) );

		if ( is_wp_error( $result ) ) {
			$this->send_failure_alert( $result->get_error_message() );
			return;
		}

		if ( (bool) get_option( 'wpvault_notify_on_success', true ) ) {
			$this->send_success_notification();
		}

		$this->prune_old_backups();
	}

	private function prune_old_backups() {
		$keep    = (int) get_option( 'wpvault_keep_generations', 10 );
		$backup  = new WP_Vault_Backup();
		$old     = $backup->get_old_backups( $keep );
		$dir     = wpvault_get_backup_dir();

		foreach ( $old as $record ) {
			$filepath = trailingslashit( $dir ) . $record->filename;
			if ( file_exists( $filepath ) ) {
				@unlink( $filepath ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			}
			WP_Vault_DB::delete_backup_record( (int) $record->id );
		}
	}

	private function send_success_notification() {
		$to      = get_option( 'wpvault_notify_email', get_option( 'admin_email' ) );
		$subject = sprintf( '[%s] %s', get_bloginfo( 'name' ), __( 'バックアップが完了しました', 'wp-vault' ) );
		$message = __( 'WP Vault によるスケジュールバックアップが正常に完了しました。', 'wp-vault' );
		wp_mail( sanitize_email( $to ), $subject, $message );
	}

	private function send_failure_alert( string $error_message ) {
		$to      = get_option( 'wpvault_notify_email', get_option( 'admin_email' ) );
		$subject = sprintf( '[%s] %s', get_bloginfo( 'name' ), __( 'バックアップが失敗しました', 'wp-vault' ) );
		$message = sprintf(
			/* translators: %s: error message */
			__( "WP Vault のバックアップに失敗しました。\n\nエラー: %s", 'wp-vault' ),
			$error_message
		);
		wp_mail( sanitize_email( $to ), $subject, $message );
	}
}
