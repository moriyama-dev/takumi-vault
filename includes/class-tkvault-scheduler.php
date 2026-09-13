<?php
defined( 'ABSPATH' ) || exit;

class TKVault_Scheduler {

	/** Weekly re-check that the destination is still not web-served. */
	const REPROBE_HOOK = 'tkvault_reprobe_event';

	/** Which stage of an automatic run is in flight, and as which job. */
	const OPTION_RUN = 'tkvault_scheduled_run';

	/** Local time of day an automatic run should start, as HH:MM. */
	const OPTION_TIME = 'tkvault_schedule_time';

	/** Used when no start time has been chosen: the small hours, local time. */
	const DEFAULT_TIME = '03:00';

	public function __construct() {
		add_action( 'tkvault_scheduled_backup', array( $this, 'run_scheduled_backup' ) );
		add_action( 'tkvault_job_finished', array( $this, 'on_job_finished' ), 10, 2 );
		add_action( 'tkvault_job_cancelled', array( $this, 'on_job_cancelled' ) );
	}

	/**
	 * Registered at file scope rather than in the constructor.
	 *
	 * The activation hook fires after plugins_loaded has already passed, so
	 * no instance of this class exists yet during activation. Registering the
	 * intervals from the constructor would leave tkvault_weekly undefined at
	 * exactly the moment activation tries to schedule the re-probe with it,
	 * and wp_schedule_event() would silently refuse.
	 */
	public static function add_cron_intervals( array $schedules ) {
		// The watchdog needs a tighter interval than anything WordPress ships.
		$schedules['tkvault_five_minutes'] = array(
			'interval' => 5 * MINUTE_IN_SECONDS,
			'display'  => __( 'Every five minutes', 'takumi-vault' ),
		);
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

	/**
	 * Turn the schedule on, starting at a chosen time of day.
	 *
	 * The first run is placed at the next occurrence of that local time
	 * rather than at time(), which is what this used to pass. Scheduling from
	 * "now" made the run recur at whatever moment the administrator happened
	 * to press Save, so a daily backup configured at lunchtime kept dumping
	 * the database at lunchtime every day after that.
	 *
	 * @param string      $frequency One of daily, tkvault_weekly, tkvault_monthly.
	 * @param string|null $time      Local HH:MM. Falls back to the stored setting.
	 */
	public function schedule( string $frequency, $time = null ) {
		$this->clear_scheduled_events();

		$valid = array( 'daily', 'tkvault_weekly', 'tkvault_monthly' );
		if ( ! in_array( $frequency, $valid, true ) ) {
			return;
		}

		if ( ! wp_next_scheduled( 'tkvault_scheduled_backup' ) ) {
			wp_schedule_event( self::next_run_timestamp( $time ), $frequency, 'tkvault_scheduled_backup' );
		}
	}

	/**
	 * The stored start time, always as a valid HH:MM.
	 */
	public static function get_time() {
		return self::sanitize_time( get_option( self::OPTION_TIME, self::DEFAULT_TIME ) );
	}

	/**
	 * Normalise a submitted time, or fall back to the default.
	 *
	 * Anything unparseable becomes DEFAULT_TIME rather than an error. To
	 * reject it instead would mean either refusing to save the rest of the
	 * form or leaving automatic backups off, and neither is what someone who
	 * mistyped one field was asking for.
	 *
	 * @param mixed $time Candidate value.
	 * @return string HH:MM.
	 */
	public static function sanitize_time( $time ) {
		if ( is_string( $time ) && preg_match( '/^\s*(\d{1,2}):(\d{2})\s*$/', $time, $matches ) ) {
			$hours   = (int) $matches[1];
			$minutes = (int) $matches[2];
			if ( $hours <= 23 && $minutes <= 59 ) {
				return sprintf( '%02d:%02d', $hours, $minutes );
			}
		}

		return self::DEFAULT_TIME;
	}

	/**
	 * When the next run at a given local time falls, as a UTC timestamp.
	 *
	 * Worked out in the site's own timezone, so a site that asks for 03:00
	 * keeps getting 03:00 across a daylight-saving change instead of a fixed
	 * offset from UTC that slips an hour twice a year.
	 *
	 * @param string|null $time Local HH:MM. Falls back to the stored setting.
	 * @return int
	 */
	public static function next_run_timestamp( $time = null ) {
		$time  = ( null === $time ) ? self::get_time() : self::sanitize_time( $time );
		$parts = explode( ':', $time );

		$zone = wp_timezone();
		$now  = new DateTimeImmutable( 'now', $zone );
		$next = $now->setTime( (int) $parts[0], (int) $parts[1], 0 );

		// Never schedule into the past: today's occurrence has usually gone by
		// the time someone saves the form.
		if ( $next->getTimestamp() <= $now->getTimestamp() ) {
			$next = $next->modify( '+1 day' );
		}

		return $next->getTimestamp();
	}

	public static function clear_scheduled_events() {
		$timestamp = wp_next_scheduled( 'tkvault_scheduled_backup' );
		if ( $timestamp ) {
			wp_unschedule_event( $timestamp, 'tkvault_scheduled_backup' );
		}
	}

	/**
	 * The exposure re-check runs whether or not scheduled backups are on. A
	 * site with automatic backups switched off still has archives sitting in
	 * a directory that may stop being private.
	 */
	public static function schedule_reprobe_event() {
		if ( ! wp_next_scheduled( self::REPROBE_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'tkvault_weekly', self::REPROBE_HOOK );
		}
	}

	public static function clear_reprobe_event() {
		$timestamp = wp_next_scheduled( self::REPROBE_HOOK );
		if ( $timestamp ) {
			wp_unschedule_event( $timestamp, self::REPROBE_HOOK );
		}
	}

	/**
	 * Queue an automatic backup.
	 *
	 * This used to call TKVault_Backup::run() and wait for it. That was wrong
	 * twice over. It shelled out to mysqldump, which a host can disable and
	 * many do; and it opened with a current_user_can() check, which under
	 * WP-Cron has no user to test and so refused every automatic run the
	 * plugin ever attempted. Both are gone: the work is queued as jobs and the
	 * runner carries it across as many requests as it needs.
	 *
	 * Capability is not checked here on purpose. Nothing reached this point
	 * through a request - it arrives from the site's own schedule - so there
	 * is no user whose permission could be meaningful. What guards it is that
	 * only an administrator can turn the schedule on.
	 */
	public function run_scheduled_backup() {
		// Do not stack runs. A site whose backup takes longer than its interval
		// would otherwise start a second one on top of the first.
		$current = get_option( self::OPTION_RUN );
		if ( is_array( $current ) && ! empty( $current['job'] ) && TKVault_Jobs::is_open( TKVault_Jobs::get( (int) $current['job'] ) ) ) {
			return;
		}

		$this->begin_stage( 'db' );
	}

	/**
	 * Start one stage of an automatic run and remember which it is.
	 *
	 * The database and the files go one after the other rather than together.
	 * Running both at once doubles the memory and CPU a shared host has to
	 * find at that moment, and both stages write into the same destination.
	 *
	 * @param string $stage 'db' or 'files'.
	 */
	private function begin_stage( $stage ) {
		$note = __( 'Scheduled automatic backup', 'takumi-vault' );

		$job = ( 'db' === $stage )
			? TKVault_DB_Dump::start( $note )
			: TKVault_File_Backup::start( $note );

		if ( is_wp_error( $job ) ) {
			delete_option( self::OPTION_RUN );
			$this->send_failure_alert( $job->get_error_message() );
			return;
		}

		update_option(
			self::OPTION_RUN,
			array(
				'stage' => $stage,
				'job'   => (int) $job['id'],
			),
			false
		);

		TKVault_Runner::dispatch( (int) $job['id'] );
	}

	/**
	 * Advance or end an automatic run when one of its jobs finishes.
	 *
	 * Every job on the site comes through here, so the recorded job id is what
	 * decides whether this one is ours. Matching on job type would pick up a
	 * backup an administrator started by hand.
	 *
	 * @param object $job    The finished job.
	 * @param string $status Terminal status.
	 */
	public function on_job_finished( $job, $status ) {
		$run = get_option( self::OPTION_RUN );
		if ( ! is_array( $run ) || empty( $run['job'] ) || ! $job || (int) $run['job'] !== (int) $job->id ) {
			return;
		}

		if ( TKVault_Jobs::STATUS_COMPLETE !== $status ) {
			delete_option( self::OPTION_RUN );
			$this->send_failure_alert( $job->message ? $job->message : __( 'The job stopped without reporting a reason.', 'takumi-vault' ) );
			return;
		}

		if ( 'db' === $run['stage'] ) {
			$this->begin_stage( 'files' );
			return;
		}

		delete_option( self::OPTION_RUN );

		if ( (bool) get_option( 'tkvault_notify_on_success', true ) ) {
			$this->send_success_notification();
		}

		$this->prune_old_backups();
	}

	/**
	 * An administrator cancelling a stage cancels the run, silently.
	 *
	 * No failure alert: they know, they did it.
	 *
	 * @param object $job The cancelled job.
	 */
	public function on_job_cancelled( $job ) {
		$run = get_option( self::OPTION_RUN );
		if ( is_array( $run ) && $job && (int) $run['job'] === (int) $job->id ) {
			delete_option( self::OPTION_RUN );
		}
	}

	private function prune_old_backups() {
		$keep = (int) get_option( 'tkvault_keep_generations', 10 );
		$old  = TKVault_DB::get_old_backups( $keep );
		$dir  = TKVault_Storage::get_store_dir();

		// Never delete inside a directory that does not carry our ownership
		// marker. If the destination was pointed at something we did not
		// create, generation pruning must not touch it.
		if ( ! $dir || ! TKVault_Storage::is_owned( TKVault_Storage::get_dir() ) ) {
			return;
		}

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
