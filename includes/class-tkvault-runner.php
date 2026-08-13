<?php
/**
 * Chunked job execution.
 *
 * There is no CLI to lean on, so long work has to survive being chopped into
 * whatever fraction of it fits inside one HTTP request. A worker takes the
 * job, runs chunks until it is nearly out of time, saves its cursor, and asks
 * someone else to carry on.
 *
 * Three things can be that someone else, in descending order of preference:
 *
 *   1. A loopback request the worker fires at itself before it exits.
 *   2. The admin screen polling for progress, which advances the job as a
 *      side effect. This is what keeps hosts with blocked loopbacks working.
 *   3. A WP-Cron watchdog, for jobs whose worker died without handing over.
 *
 * @package TakumiVault
 */

defined( 'ABSPATH' ) || exit;

/**
 * Thrown by a handler when retrying cannot help.
 *
 * An ordinary exception means "this attempt failed"; the runner retries and
 * only gives up at the attempt cap. Some failures are known-final - a row
 * count that does not add up, a destination that vanished - and burning five
 * attempts on them just delays the report.
 */
class TKVault_Job_Fatal extends RuntimeException {}

class TKVault_Runner {

	const LOOPBACK_ACTION = 'tkvault_run_job';
	const POLL_ACTION     = 'tkvault_job_poll';
	const WATCHDOG_HOOK   = 'tkvault_watchdog_event';
	const OPTION_LOOPBACK = 'tkvault_loopback_ok';

	/** Fallback budget when the host reports no execution limit. */
	const DEFAULT_BUDGET = 20.0;

	/** Fraction of the host's limit we are willing to spend. */
	const BUDGET_FRACTION = 0.6;

	public static function init() {
		// Loopback continuation. Unauthenticated by necessity: the request
		// carries no cookies. See verify_request() for what stands in for a
		// nonce here.
		add_action( 'wp_ajax_nopriv_' . self::LOOPBACK_ACTION, array( __CLASS__, 'handle_loopback' ) );
		add_action( 'wp_ajax_' . self::LOOPBACK_ACTION, array( __CLASS__, 'handle_loopback' ) );

		// Progress polling from the admin screen, which also drives the job.
		add_action( 'wp_ajax_' . self::POLL_ACTION, array( __CLASS__, 'handle_poll' ) );

		add_action( self::WATCHDOG_HOOK, array( __CLASS__, 'watchdog' ) );
	}

	/**
	 * Registered chunk handlers, keyed by job type.
	 *
	 * A handler receives the saved state and the job payload and returns
	 * array{state:array, processed:int, done:bool}. Throwing is a legitimate
	 * way to report failure; the runner counts attempts and gives up rather
	 * than retrying for ever.
	 */
	public static function handlers() {
		return apply_filters(
			'tkvault_job_handlers',
			array(
				'demo' => array( 'TKVault_Demo_Job', 'run_chunk' ),
			)
		);
	}

	/**
	 * How long this request may spend before handing over.
	 *
	 * Twenty seconds hard-coded is wrong in both directions: it overruns a
	 * host capped at 10 seconds, and wastes three quarters of a host that
	 * allows 300. Spend a fraction of whatever the host actually permits, and
	 * only fall back to a fixed number when the host claims no limit at all.
	 */
	public static function budget() {
		$limit = (int) ini_get( 'max_execution_time' );

		if ( $limit <= 0 ) {
			/** This filter is documented below. */
			return (float) apply_filters( 'tkvault_job_budget', self::DEFAULT_BUDGET ); // 0 or -1 means unlimited.
		}

		/**
		 * Filters how long one request may spend advancing a job.
		 *
		 * @param float $budget Seconds.
		 */
		return (float) apply_filters( 'tkvault_job_budget', max( 5.0, $limit * self::BUDGET_FRACTION ) );
	}

	/**
	 * Advance a job for as long as this request can afford.
	 *
	 * @return array{status:string, processed:int, total:int, message:string}
	 */
	public static function run( $job_id ) {
		$job = TKVault_Jobs::get( $job_id );
		if ( ! TKVault_Jobs::is_open( $job ) ) {
			return self::snapshot( $job );
		}

		$token = TKVault_Jobs::claim( $job_id );
		if ( ! $token ) {
			// Someone else holds a live claim. Not an error: the job is moving.
			return self::snapshot( TKVault_Jobs::get( $job_id ) );
		}

		$handlers = self::handlers();
		$job      = TKVault_Jobs::get( $job_id );

		if ( ! isset( $handlers[ $job->type ] ) ) {
			TKVault_Jobs::finish( $job_id, TKVault_Jobs::STATUS_FAILED, __( 'No handler is registered for this job type.', 'takumi-vault' ) );
			return self::snapshot( TKVault_Jobs::get( $job_id ) );
		}

		if ( ! self::count_attempt( $job ) ) {
			return self::snapshot( TKVault_Jobs::get( $job_id ) );
		}

		$state    = TKVault_Jobs::decode( $job->state );
		$payload  = TKVault_Jobs::decode( $job->payload );
		$handler  = $handlers[ $job->type ];
		$started  = microtime( true );
		$budget   = self::budget();
		$done     = false;
		$processed = (int) $job->processed;

		// Best effort; the time-based hand-over is what we actually rely on.
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 0 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, Generic.PHP.NoSilencedErrors
		}
		ignore_user_abort( true );

		// A do-while, not a while: the budget must never be able to stop the
		// first chunk. Testing it up front means a host that is already out of
		// time hands the job straight back, and the job advances no further on
		// every attempt until the watchdog gives up on it.
		do {
			// Cancellation must take effect between chunks, not only at the
			// start of a request.
			$current = TKVault_Jobs::get( $job_id );
			if ( ! TKVault_Jobs::is_open( $current ) ) {
				TKVault_Jobs::release( $job_id, $token );
				return self::snapshot( $current );
			}

			try {
				$result = call_user_func( $handler, $state, $payload, $job_id );
			} catch ( TKVault_Job_Fatal $e ) {
				// Known-final. Do not spend the attempt budget on it.
				TKVault_Jobs::release( $job_id, $token );
				TKVault_Jobs::finish( $job_id, TKVault_Jobs::STATUS_FAILED, $e->getMessage() );
				return self::snapshot( TKVault_Jobs::get( $job_id ) );
			} catch ( Throwable $e ) {
				TKVault_Jobs::release( $job_id, $token );
				TKVault_Jobs::update( $job_id, array( 'message' => $e->getMessage() ) );
				self::dispatch( $job_id ); // Let the attempt counter decide when to stop.
				return self::snapshot( TKVault_Jobs::get( $job_id ) );
			}

			$state      = isset( $result['state'] ) && is_array( $result['state'] ) ? $result['state'] : array();
			$processed += isset( $result['processed'] ) ? (int) $result['processed'] : 0;
			$done       = ! empty( $result['done'] );

			// A handler only learns the real size of the work once it starts.
			if ( isset( $result['total'] ) ) {
				TKVault_Jobs::update( $job_id, array( 'total' => (int) $result['total'] ) );
			}

			TKVault_Jobs::set_state( $job_id, $state, $processed );

			if ( ! TKVault_Jobs::heartbeat( $job_id, $token ) ) {
				// The claim was stolen; stop touching the job.
				return self::snapshot( TKVault_Jobs::get( $job_id ) );
			}

			if ( $done ) {
				break;
			}
		} while ( microtime( true ) - $started < $budget );

		if ( $done ) {
			TKVault_Jobs::finish( $job_id, TKVault_Jobs::STATUS_COMPLETE );
			return self::snapshot( TKVault_Jobs::get( $job_id ) );
		}

		// Out of time rather than out of work. Record the hand-over: it is the
		// only evidence afterwards that the job really was split across
		// requests, and it is what a support conversation about a slow host
		// will want to see.
		TKVault_Jobs::update( $job_id, array( 'handovers' => (int) $job->handovers + 1 ) );
		TKVault_Jobs::release( $job_id, $token );
		self::dispatch( $job_id );

		return self::snapshot( TKVault_Jobs::get( $job_id ) );
	}

	/**
	 * Count this pick-up against the job, and fail it if it keeps dying in
	 * the same place.
	 *
	 * The counter is keyed on the cursor rather than incremented blindly: a
	 * long job legitimately resumes hundreds of times, and capping that would
	 * stop honest work. What must not be allowed is resuming from the same
	 * position for ever, which is what a chunk that fatals deterministically
	 * looks like.
	 *
	 * The increment is written before the chunk runs, so it survives a fatal
	 * error that takes the whole process with it.
	 *
	 * @return bool False when the job has been failed and must not run.
	 */
	private static function count_attempt( $job ) {
		$cursor = hash( 'sha256', (string) $job->state );

		if ( hash_equals( (string) $job->attempt_cursor, $cursor ) ) {
			$attempts = (int) $job->attempts + 1;
		} else {
			$attempts = 1;
		}

		if ( $attempts > TKVault_Jobs::MAX_ATTEMPTS ) {
			TKVault_Jobs::finish(
				$job->id,
				TKVault_Jobs::STATUS_FAILED,
				sprintf(
					/* translators: 1: number of attempts, 2: last error message */
					__( 'Stopped after %1$d attempts at the same point. Last error: %2$s', 'takumi-vault' ),
					TKVault_Jobs::MAX_ATTEMPTS,
					$job->message ? $job->message : __( 'the process ended without reporting one.', 'takumi-vault' )
				)
			);
			return false;
		}

		TKVault_Jobs::update(
			$job->id,
			array(
				'attempts'       => $attempts,
				'attempt_cursor' => $cursor,
			)
		);

		return true;
	}

	/**
	 * Ask the site to continue the job in a separate request.
	 *
	 * Fire and forget: the response is irrelevant, only that the request left.
	 * A fresh secret is minted for each dispatch so a token seen once cannot
	 * be replayed later.
	 */
	public static function dispatch( $job_id ) {
		$job = TKVault_Jobs::get( $job_id );
		if ( ! TKVault_Jobs::is_open( $job ) ) {
			return false;
		}

		$secret = TKVault_Jobs::rotate_secret( $job_id );

		$response = wp_remote_post(
			admin_url( 'admin-ajax.php' ),
			array(
				'timeout'   => 0.01,
				'blocking'  => false,
				'sslverify' => false,
				'body'      => array(
					'action' => self::LOOPBACK_ACTION,
					'job'    => (int) $job_id,
					'secret' => $secret,
				),
			)
		);

		$ok = ! is_wp_error( $response );
		update_option( self::OPTION_LOOPBACK, $ok ? 1 : 0, false );

		return $ok;
	}

	/**
	 * The unauthenticated continuation endpoint.
	 *
	 * A loopback request carries no cookies, so there is no logged-in user, no
	 * nonce and nothing for current_user_can() to answer. What replaces them:
	 *
	 *   - the caller must present the job's current secret, which is compared
	 *     with hash_equals() against a stored hash and rotated on every
	 *     dispatch;
	 *   - the endpoint can only advance a job that already exists. It has no
	 *     path that creates one, so an attacker who somehow guessed a secret
	 *     gains the ability to make the site do work it had already decided
	 *     to do;
	 *   - anything unknown, finished or cancelled is a silent no-op, so the
	 *     endpoint cannot be used to probe which job ids exist.
	 */
	public static function handle_loopback() {
		$job_id = isset( $_POST['job'] ) ? absint( $_POST['job'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$secret = isset( $_POST['secret'] ) ? sanitize_text_field( wp_unslash( $_POST['secret'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing

		if ( ! $job_id || '' === $secret ) {
			wp_die( '', '', array( 'response' => 200 ) );
		}

		$job = TKVault_Jobs::get( $job_id );

		// Same silent exit for "no such job", "already finished", "cancelled"
		// and "wrong secret". Nothing here distinguishes them to the caller.
		if ( ! TKVault_Jobs::is_open( $job ) || ! TKVault_Jobs::secret_matches( $job, $secret ) ) {
			wp_die( '', '', array( 'response' => 200 ) );
		}

		self::run( $job_id );
		wp_die( '', '', array( 'response' => 200 ) );
	}

	/**
	 * Progress polling for the admin screen.
	 *
	 * Advancing the job here is the point, not a side effect: on a host where
	 * loopback requests are blocked this is the only thing moving it forward.
	 */
	public static function handle_poll() {
		check_ajax_referer( 'tkvault_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission to do that.', 'takumi-vault' ) ) );
		}

		$job_id = isset( $_POST['job'] ) ? absint( $_POST['job'] ) : 0;
		if ( ! $job_id ) {
			wp_send_json_error( array( 'message' => __( 'Invalid job.', 'takumi-vault' ) ) );
		}

		wp_send_json_success( self::run( $job_id ) );
	}

	/**
	 * Restart jobs whose worker never came back.
	 */
	public static function watchdog() {
		foreach ( TKVault_Jobs::stalled() as $job ) {
			self::dispatch( $job->id );
		}
	}

	public static function schedule_watchdog() {
		if ( ! wp_next_scheduled( self::WATCHDOG_HOOK ) ) {
			wp_schedule_event( time() + 300, 'tkvault_five_minutes', self::WATCHDOG_HOOK );
		}
	}

	public static function clear_watchdog() {
		$timestamp = wp_next_scheduled( self::WATCHDOG_HOOK );
		if ( $timestamp ) {
			wp_unschedule_event( $timestamp, self::WATCHDOG_HOOK );
		}
	}

	public static function snapshot( $job ) {
		if ( ! $job ) {
			return array(
				'status'    => 'missing',
				'processed' => 0,
				'total'     => 0,
				'percent'   => 0,
				'message'   => '',
			);
		}

		$total = (int) $job->total;

		return array(
			'id'        => (int) $job->id,
			'status'    => $job->status,
			'processed' => (int) $job->processed,
			'total'     => $total,
			'percent'   => $total > 0 ? min( 100, (int) round( ( $job->processed / $total ) * 100 ) ) : 0,
			'attempts'  => (int) $job->attempts,
			'handovers' => (int) $job->handovers,
			'message'   => (string) $job->message,
		);
	}
}

/**
 * Placeholder workload used to exercise the runner before there is a real one.
 *
 * Replaced in step 3 by the database dump. Kept afterwards as the background
 * processing self-test on the diagnostics screen, because "does chunked work
 * complete on this host" is a genuinely useful thing to be able to answer.
 *
 * Deliberately contains no failure-injection hooks. Tests that need a chunk to
 * throw register their own handler through the tkvault_job_handlers filter, so
 * nothing whose only purpose is to break ships to users.
 */
class TKVault_Demo_Job {

	public static function run_chunk( array $state, array $payload ) {
		$index  = isset( $state['index'] ) ? (int) $state['index'] : 0;
		$chunks = isset( $payload['chunks'] ) ? (int) $payload['chunks'] : 5;

		if ( isset( $payload['work_ms'] ) ) {
			usleep( (int) $payload['work_ms'] * 1000 );
		}

		++$index;

		return array(
			'state'     => array( 'index' => $index ),
			'processed' => 1,
			'done'      => $index >= $chunks,
		);
	}
}
