<?php
defined( 'ABSPATH' ) || exit;

class TKVault_Scheduler {

	public function __construct() {
		add_filter( 'cron_schedules', array( $this, 'add_cron_intervals' ) );
		add_action( 'tkvault_scheduled_backup', array( $this, 'run_scheduled_backup' ) );
	}

	public function add_cron_intervals( array $schedules ) {
		$schedules['tkvault_weekly'] = array(
			'interval' => WEEK_IN_SECONDS,
			'display'  => __( 'Weekly', 'takumi-vault' ),
		);
		$schedules['tkvault_monthly'] = array(
			'interval' => 30 * DAY_IN_SECONDS,
			'display'  => __( 'Monthly', 'takumi-vault' ),
		);
		return $schedules;
	}

	public function schedule( string $frequency ) {
		$this->clear_scheduled_events();

		$valid = array( 'daily', 'tkvault_weekly', 'tkvault_monthly' );
		if ( ! in_array( $frequency, $valid, true ) ) {
			return;
		}

		if ( ! wp_next_scheduled( 'tkvault_scheduled_backup' ) ) {
			wp_schedule_event( time(), $frequency, 'tkvault_scheduled_backup' );
		}
	}

	public static function clear_scheduled_events() {
		$timestamp = wp_next_scheduled( 'tkvault_scheduled_backup' );
		if ( $timestamp ) {
			wp_unschedule_event( $timestamp, 'tkvault_scheduled_backup' );
		}
	}

	public function run_scheduled_backup() {
		$backup = new TKVault_Backup();
		$result = $backup->run( 'full', __( 'Scheduled automatic backup', 'takumi-vault' ) );

		if ( is_wp_error( $result ) ) {
			$this->send_failure_alert( $result->get_error_message() );
			return;
		}

		if ( (bool) get_option( 'tkvault_notify_on_success', true ) ) {
			$this->send_success_notification();
		}

		$this->prune_old_backups();
	}

	private function prune_old_backups() {
		$keep    = (int) get_option( 'tkvault_keep_generations', 10 );
		$backup  = new TKVault_Backup();
		$old     = $backup->get_old_backups( $keep );
		$dir     = tkvault_get_backup_dir();

		foreach ( $old as $record ) {
			$filepath = trailingslashit( $dir ) . $record->filename;
			if ( file_exists( $filepath ) ) {
				wp_delete_file( $filepath );
			}
			TKVault_DB::delete_backup_record( (int) $record->id );
		}
	}

	private function send_success_notification() {
		$to      = get_option( 'tkvault_notify_email', get_option( 'admin_email' ) );
		$subject = sprintf( '[%s] %s', get_bloginfo( 'name' ), __( 'Backup completed', 'takumi-vault' ) );
		$message = __( 'The scheduled Takumi Vault backup completed successfully.', 'takumi-vault' );
		wp_mail( sanitize_email( $to ), $subject, $message );
	}

	private function send_failure_alert( string $error_message ) {
		$to      = get_option( 'tkvault_notify_email', get_option( 'admin_email' ) );
		$subject = sprintf( '[%s] %s', get_bloginfo( 'name' ), __( 'Backup failed', 'takumi-vault' ) );
		$message = sprintf(
			/* translators: %s: error message */
			__( "The Takumi Vault backup failed.\n\nError: %s", 'takumi-vault' ),
			$error_message
		);
		wp_mail( sanitize_email( $to ), $subject, $message );
	}
}
