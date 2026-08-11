<?php
/**
 * Job storage.
 *
 * State lives in a table rather than a transient because object caches drop
 * transients without warning, and a backup that forgets where it got to is
 * worse than one that never started.
 *
 * @package TakumiVault
 */

defined( 'ABSPATH' ) || exit;

class TKVault_Jobs {

	const STATUS_PENDING   = 'pending';
	const STATUS_RUNNING   = 'running';
	const STATUS_COMPLETE  = 'complete';
	const STATUS_FAILED    = 'failed';
	const STATUS_CANCELLED = 'cancelled';

	/** A worker that has not written a heartbeat for this long has lost the job. */
	const LOCK_TIMEOUT = 120;

	/** Consecutive failures at the same cursor before the job is given up on. */
	const MAX_ATTEMPTS = 5;

	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'tkvault_jobs';
	}

	public static function create_table() {
		global $wpdb;

		$table   = self::table();
		$collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table} (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			type VARCHAR(32) NOT NULL,
			status VARCHAR(20) NOT NULL DEFAULT 'pending',
			secret_hash CHAR(64) NOT NULL DEFAULT '',
			payload LONGTEXT NULL,
			state LONGTEXT NULL,
			processed BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
			total BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
			attempts INT(10) UNSIGNED NOT NULL DEFAULT 0,
			attempt_cursor CHAR(64) NOT NULL DEFAULT '',
			handovers INT(10) UNSIGNED NOT NULL DEFAULT 0,
			message TEXT NULL,
			lock_token CHAR(32) NULL,
			heartbeat DATETIME NULL,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NULL,
			PRIMARY KEY (id),
			KEY status_idx (status),
			KEY heartbeat_idx (heartbeat)
		) {$collate};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}

	public static function drop_table() {
		global $wpdb;
		$table = self::table();
		$wpdb->query( "DROP TABLE IF EXISTS {$table}" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL
	}

	/**
	 * Create a job and return its id plus the plaintext dispatch secret.
	 *
	 * The secret is never stored in the clear. Only its hash is kept, and a
	 * fresh one is minted for every dispatch, so a token captured from a
	 * request cannot be replayed against a later chunk.
	 *
	 * @return array{id:int, secret:string}
	 */
	public static function create( $type, array $payload = array(), $total = 0 ) {
		global $wpdb;

		$secret = wp_generate_password( 48, false, false );

		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			self::table(),
			array(
				'type'        => $type,
				'status'      => self::STATUS_PENDING,
				'secret_hash' => self::hash( $secret ),
				'payload'     => wp_json_encode( $payload ),
				'state'       => wp_json_encode( array() ),
				'total'       => (int) $total,
				'created_at'  => current_time( 'mysql', true ),
			),
			array( '%s', '%s', '%s', '%s', '%s', '%d', '%s' )
		);

		return array(
			'id'     => (int) $wpdb->insert_id,
			'secret' => $secret,
		);
	}

	public static function hash( $secret ) {
		return hash( 'sha256', (string) $secret );
	}

	public static function get( $id ) {
		global $wpdb;
		$table = self::table();
		return $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", (int) $id ) // phpcs:ignore WordPress.DB.PreparedSQL
		);
	}

	/**
	 * Verify a dispatch secret in constant time.
	 *
	 * Both sides are fixed-length hex from hash(), so hash_equals() is
	 * comparing like with like and cannot leak length.
	 */
	public static function secret_matches( $job, $presented ) {
		if ( ! $job || '' === $job->secret_hash || '' === (string) $presented ) {
			return false;
		}
		return hash_equals( $job->secret_hash, self::hash( $presented ) );
	}

	/**
	 * Mint a new dispatch secret for an existing job.
	 *
	 * @return string The plaintext to hand to the next worker.
	 */
	public static function rotate_secret( $id ) {
		global $wpdb;

		$secret = wp_generate_password( 48, false, false );
		$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			self::table(),
			array( 'secret_hash' => self::hash( $secret ) ),
			array( 'id' => (int) $id ),
			array( '%s' ),
			array( '%d' )
		);

		return $secret;
	}

	/**
	 * Take the job, atomically.
	 *
	 * A single conditional UPDATE decides the winner. Reading the row and
	 * then writing it would let two workers both see "unlocked" and both
	 * proceed; here MySQL settles it, and the number of affected rows tells
	 * us which caller won.
	 *
	 * GET_LOCK() was the obvious alternative and is avoided deliberately: it
	 * is scoped to the database connection, so with persistent connections a
	 * lock can outlive the request that took it, and a crashed worker can
	 * leave a job locked until the connection is recycled. A heartbeat column
	 * has no such dependency - if a worker dies, its lock simply goes stale
	 * and the next one takes over.
	 *
	 * @return string|false Lock token on success.
	 */
	public static function claim( $id ) {
		global $wpdb;

		$token = wp_generate_password( 32, false, false );
		$table = self::table();
		$now   = current_time( 'mysql', true );
		$stale = gmdate( 'Y-m-d H:i:s', time() - self::LOCK_TIMEOUT );

		// The fresh token guarantees the row really changes when the WHERE
		// matches, so affected-rows is a reliable "did I win" signal.
		$claimed = $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"UPDATE {$table}
					SET lock_token = %s, heartbeat = %s, status = %s, updated_at = %s
					WHERE id = %d
					  AND status IN ( %s, %s )
					  AND ( lock_token IS NULL OR heartbeat IS NULL OR heartbeat < %s )", // phpcs:ignore WordPress.DB.PreparedSQL
				$token,
				$now,
				self::STATUS_RUNNING,
				$now,
				(int) $id,
				self::STATUS_PENDING,
				self::STATUS_RUNNING,
				$stale
			)
		);

		return $claimed ? $token : false;
	}

	/**
	 * Keep the claim alive. Returns false only if the lock was taken away.
	 *
	 * The affected-rows count cannot answer this on its own. MySQL reports
	 * rows *changed*, not rows *matched*, and DATETIME has one-second
	 * resolution - so a heartbeat written twice inside the same second
	 * changes nothing and looks exactly like a lost lock. Reading the token
	 * back settles it. The claim is the critical section, not this, so a
	 * read-then-compare here races with nothing.
	 */
	public static function heartbeat( $id, $token ) {
		global $wpdb;

		$table = self::table();
		$now   = current_time( 'mysql', true );

		$affected = $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"UPDATE {$table} SET heartbeat = %s, updated_at = %s WHERE id = %d AND lock_token = %s", // phpcs:ignore WordPress.DB.PreparedSQL
				$now,
				$now,
				(int) $id,
				$token
			)
		);

		if ( $affected ) {
			return true;
		}

		$held = $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare( "SELECT lock_token FROM {$table} WHERE id = %d", (int) $id ) // phpcs:ignore WordPress.DB.PreparedSQL
		);

		return null !== $held && hash_equals( (string) $held, (string) $token );
	}

	public static function release( $id, $token ) {
		global $wpdb;

		$table = self::table();
		$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"UPDATE {$table} SET lock_token = NULL WHERE id = %d AND lock_token = %s", // phpcs:ignore WordPress.DB.PreparedSQL
				(int) $id,
				$token
			)
		);
	}

	public static function update( $id, array $data ) {
		global $wpdb;

		$data['updated_at'] = current_time( 'mysql', true );

		$formats = array();
		foreach ( $data as $key => $value ) {
			$formats[] = in_array( $key, array( 'processed', 'total', 'attempts', 'handovers' ), true ) ? '%d' : '%s';
		}

		return $wpdb->update( self::table(), $data, array( 'id' => (int) $id ), $formats, array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	public static function set_state( $id, array $state, $processed ) {
		return self::update(
			$id,
			array(
				'state'     => wp_json_encode( $state ),
				'processed' => (int) $processed,
			)
		);
	}

	public static function finish( $id, $status, $message = '' ) {
		return self::update(
			$id,
			array(
				'status'     => $status,
				'message'    => (string) $message,
				'lock_token' => null,
			)
		);
	}

	/**
	 * Cancellation is a status change and nothing else.
	 *
	 * The running worker notices before its next chunk, and the loopback
	 * endpoint and watchdog both refuse to touch a cancelled job, so there is
	 * no window in which a cancelled job quietly carries on.
	 */
	public static function cancel( $id ) {
		global $wpdb;

		$table = self::table();
		return (bool) $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"UPDATE {$table} SET status = %s, lock_token = NULL, updated_at = %s
					WHERE id = %d AND status IN ( %s, %s )", // phpcs:ignore WordPress.DB.PreparedSQL
				self::STATUS_CANCELLED,
				current_time( 'mysql', true ),
				(int) $id,
				self::STATUS_PENDING,
				self::STATUS_RUNNING
			)
		);
	}

	public static function is_open( $job ) {
		return $job && in_array( $job->status, array( self::STATUS_PENDING, self::STATUS_RUNNING ), true );
	}

	public static function decode( $json ) {
		$value = json_decode( (string) $json, true );
		return is_array( $value ) ? $value : array();
	}

	/**
	 * Jobs that look abandoned: still open, but nobody has written a
	 * heartbeat recently.
	 */
	public static function stalled( $limit = 5 ) {
		global $wpdb;

		$table = self::table();
		$stale = gmdate( 'Y-m-d H:i:s', time() - self::LOCK_TIMEOUT );

		return $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT * FROM {$table}
					WHERE status IN ( %s, %s )
					  AND ( heartbeat IS NULL OR heartbeat < %s )
					ORDER BY id ASC LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL
				self::STATUS_PENDING,
				self::STATUS_RUNNING,
				$stale,
				(int) $limit
			)
		);
	}

	public static function latest( $type = '' ) {
		global $wpdb;

		$table = self::table();

		if ( $type ) {
			return $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$wpdb->prepare( "SELECT * FROM {$table} WHERE type = %s ORDER BY id DESC LIMIT 1", $type ) // phpcs:ignore WordPress.DB.PreparedSQL
			);
		}

		return $wpdb->get_row( "SELECT * FROM {$table} ORDER BY id DESC LIMIT 1" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL
	}
}
