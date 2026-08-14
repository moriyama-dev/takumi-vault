<?php
/**
 * Shared helpers for the behaviour suites.
 *
 * Included by each test file rather than being a suite of its own. Every
 * assertion prints its own line so a failure says what was expected without
 * anyone having to re-run under a debugger.
 *
 * @package TakumiVault
 */

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'tkv_check' ) ) {

	$GLOBALS['tkv_pass'] = 0;
	$GLOBALS['tkv_fail'] = 0;

	function tkv_check( $label, $got, $want ) {
		$ok = ( $got === $want );
		$ok ? $GLOBALS['tkv_pass']++ : $GLOBALS['tkv_fail']++;

		printf(
			"  [%s] %-52s got=%s want=%s\n",
			$ok ? 'PASS' : 'FAIL',
			$label,
			tkv_short( $got ),
			tkv_short( $want )
		);

		return $ok;
	}

	function tkv_short( $value ) {
		if ( is_string( $value ) && strlen( $value ) > 34 ) {
			return "'" . substr( $value, 0, 31 ) . "...'";
		}
		if ( is_array( $value ) && count( $value ) > 4 ) {
			return 'array(' . count( $value ) . ' items)';
		}
		return var_export( $value, true );
	}

	function tkv_section( $title ) {
		echo "\n=== " . $title . " ===\n";
	}

	function tkv_info( $line ) {
		echo '  (info) ' . $line . "\n";
	}

	function tkv_report() {
		printf( "\n==== %d passed, %d failed ====\n", $GLOBALS['tkv_pass'], $GLOBALS['tkv_fail'] );
		return 0 === $GLOBALS['tkv_fail'];
	}

	/**
	 * Stop the site dispatching work to itself.
	 *
	 * dispatch() fires a genuine loopback request, which on a development box
	 * runs as a different user than WP-CLI and races whatever the test is
	 * doing. Suites drive every job from their own process instead, and turn
	 * this off only where the loopback itself is the thing under test.
	 */
	function tkv_block_loopback() {
		$GLOBALS['tkv_blocked'] = 0;

		add_filter(
			'pre_http_request',
			function ( $pre, $args, $url ) {
				if ( false !== strpos( $url, 'admin-ajax.php' ) ) {
					$GLOBALS['tkv_blocked']++;
					return new WP_Error( 'http_request_failed', 'loopback blocked by test' );
				}
				return $pre;
			},
			10,
			3
		);
	}

	function tkv_unblock_loopback() {
		remove_all_filters( 'pre_http_request' );
	}

	/** One chunk per request, so a test can act between them. */
	function tkv_single_chunk() {
		add_filter( 'tkvault_job_budget', function () { return 0.0; }, 99 );
	}

	function tkv_normal_chunking() {
		remove_all_filters( 'tkvault_job_budget' );
	}

	/**
	 * Run a job to completion from this process.
	 *
	 * @return array The final snapshot, plus how many requests it took.
	 */
	function tkv_drive( $job_id, $limit = 4000 ) {
		$n = 0;
		do {
			$snap = TKVault_Runner::run( $job_id );
			$n++;
		} while ( in_array( $snap['status'], array( 'pending', 'running' ), true ) && $n < $limit );

		$snap['requests'] = $n;
		$snap['state']    = TKVault_Jobs::decode( TKVault_Jobs::get( $job_id )->state );

		return $snap;
	}

	function tkv_job_row( $id ) {
		return TKVault_Jobs::get( $id );
	}

	/** Read every member of a multi-member gzip. Never use gzdecode(). */
	function tkv_gz_read_all( $file ) {
		$handle = gzopen( $file, 'rb' );
		$out    = '';
		while ( ! gzeof( $handle ) ) {
			$out .= gzread( $handle, 1048576 );
		}
		gzclose( $handle );
		return $out;
	}

	function tkv_temp_table_count( $like ) {
		global $wpdb;
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM information_schema.TABLES WHERE table_schema = DATABASE() AND table_name LIKE %s',
				$like
			)
		);
	}

	function tkv_warn_destructive() {
		echo "These tests write to the database. Snapshot first:\n";
		echo "  cd /var/www/html && wp db export ~/db-snapshots/\$(date +%Y%m%d_%H%M%S).sql\n";
	}
}
