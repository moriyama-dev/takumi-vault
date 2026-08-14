<?php
/**
 * Job runner behaviour tests.
 *
 *   bin/deploy.sh && cd /var/www/html && wp eval-file <path>/tests/test-job-runner.php
 *
 * Every case drives the dangerous side of a branch: a loopback that is really
 * blocked, a secret that is really wrong, a chunk that really dies.
 *
 * @package TakumiVault
 */

require_once __DIR__ . '/bootstrap.php';

global $wpdb;

/**
 * Failure injection lives here, not in the plugin.
 *
 * Registering these through the public filter keeps code whose only purpose
 * is to break out of the distributed package, and exercises the extension
 * point at the same time.
 */
add_filter(
	'tkvault_job_handlers',
	function ( $handlers ) {
		$handlers['test_always_throws'] = function ( $state, $payload ) {
			throw new RuntimeException( 'chunk failed on purpose' );
		};

		$handlers['test_throws_once'] = function ( $state, $payload ) {
			$index = isset( $state['index'] ) ? (int) $state['index'] : 0;

			if ( 2 === $index && ! get_option( 'tkv_test_thrown' ) ) {
				update_option( 'tkv_test_thrown', 1, false );
				throw new RuntimeException( 'chunk failed once on purpose' );
			}

			++$index;

			return array(
				'state'     => array( 'index' => $index ),
				'processed' => 1,
				'done'      => $index >= (int) $payload['chunks'],
			);
		};

		return $handlers;
	}
);

/* ================================================================= */
tkv_section( '1. loopback blocked: polling alone carries the job to the end' );

tkv_block_loopback();
$job = TKVault_Jobs::create( 'demo', array( 'chunks' => 12 ), 12 );
tkv_check( 'dispatch reports failure', TKVault_Runner::dispatch( $job['id'] ), false );
tkv_check( 'loopback recorded as unavailable', (int) get_option( TKVault_Runner::OPTION_LOOPBACK ), 0 );

$snap = tkv_drive( $job['id'], 50 );
tkv_check( 'completed', $snap['status'], 'complete' );
tkv_check( 'all chunks processed', $snap['processed'], 12 );
tkv_info( sprintf( 'polls=%d, loopback attempts blocked=%d', $snap['requests'], $GLOBALS['tkv_blocked'] ) );
tkv_unblock_loopback();

/* ================================================================= */
tkv_section( '2. the loopback endpoint, over real HTTP' );

$job    = TKVault_Jobs::create( 'demo', array( 'chunks' => 3 ), 3 );
$secret = TKVault_Jobs::rotate_secret( $job['id'] );

$response = wp_remote_post(
	admin_url( 'admin-ajax.php' ),
	array(
		'timeout' => 30,
		'body'    => array(
			'action' => 'tkvault_run_job',
			'job'    => $job['id'],
			'secret' => $secret,
		),
	)
);

tkv_check( 'request succeeded', ! is_wp_error( $response ), true );
tkv_check( 'HTTP status', (int) wp_remote_retrieve_response_code( $response ), 200 );
tkv_check( 'job advanced', (int) tkv_job_row( $job['id'] )->processed > 0, true );

/* ----------------------------------------------------------------- */
tkv_section( '3. a wrong secret is refused' );

$job = TKVault_Jobs::create( 'demo', array( 'chunks' => 5 ), 5 );
TKVault_Jobs::rotate_secret( $job['id'] );

$response = wp_remote_post(
	admin_url( 'admin-ajax.php' ),
	array(
		'timeout' => 30,
		'body'    => array(
			'action' => 'tkvault_run_job',
			'job'    => $job['id'],
			'secret' => 'wrong-secret-value',
		),
	)
);

tkv_check( 'still HTTP 200, so it is not an oracle', (int) wp_remote_retrieve_response_code( $response ), 200 );
tkv_check( 'job did not advance', (int) tkv_job_row( $job['id'] )->processed, 0 );
tkv_check( 'job still pending', tkv_job_row( $job['id'] )->status, 'pending' );

tkv_section( '4. a missing secret is refused' );
wp_remote_post(
	admin_url( 'admin-ajax.php' ),
	array(
		'timeout' => 30,
		'body'    => array( 'action' => 'tkvault_run_job', 'job' => $job['id'] ),
	)
);
tkv_check( 'job did not advance', (int) tkv_job_row( $job['id'] )->processed, 0 );

tkv_section( '5. a secret that has been rotated away cannot be replayed' );
$stale = TKVault_Jobs::rotate_secret( $job['id'] );
TKVault_Jobs::rotate_secret( $job['id'] );
wp_remote_post(
	admin_url( 'admin-ajax.php' ),
	array(
		'timeout' => 30,
		'body'    => array( 'action' => 'tkvault_run_job', 'job' => $job['id'], 'secret' => $stale ),
	)
);
tkv_check( 'job did not advance', (int) tkv_job_row( $job['id'] )->processed, 0 );

/* ----------------------------------------------------------------- */
tkv_section( '6. an unknown job id does nothing and creates nothing' );

$before   = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . TKVault_Jobs::table() );
$response = wp_remote_post(
	admin_url( 'admin-ajax.php' ),
	array(
		'timeout' => 30,
		'body'    => array( 'action' => 'tkvault_run_job', 'job' => 999999, 'secret' => 'anything' ),
	)
);
tkv_check( 'HTTP status', (int) wp_remote_retrieve_response_code( $response ), 200 );
tkv_check( 'no job created', (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . TKVault_Jobs::table() ), $before );

tkv_section( '7. the endpoint cannot be used to create work' );
$before = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . TKVault_Jobs::table() );
foreach ( array( array(), array( 'type' => 'demo' ), array( 'job' => 0, 'secret' => 'x' ) ) as $body ) {
	$body['action'] = 'tkvault_run_job';
	wp_remote_post( admin_url( 'admin-ajax.php' ), array( 'timeout' => 30, 'body' => $body ) );
}
tkv_check( 'still no new jobs', (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . TKVault_Jobs::table() ), $before );

/* ================================================================= */
tkv_section( '8. a cancelled job is not resumed by anything' );

$job    = TKVault_Jobs::create( 'demo', array( 'chunks' => 20 ), 20 );
$secret = TKVault_Jobs::rotate_secret( $job['id'] );
TKVault_Jobs::cancel( $job['id'] );
tkv_check( 'status', tkv_job_row( $job['id'] )->status, 'cancelled' );

wp_remote_post(
	admin_url( 'admin-ajax.php' ),
	array(
		'timeout' => 30,
		'body'    => array( 'action' => 'tkvault_run_job', 'job' => $job['id'], 'secret' => $secret ),
	)
);
tkv_check( 'the loopback did not resume it', (int) tkv_job_row( $job['id'] )->processed, 0 );

TKVault_Jobs::update( $job['id'], array( 'heartbeat' => gmdate( 'Y-m-d H:i:s', time() - 9999 ) ) );
$stalled = array_map( function ( $r ) { return (int) $r->id; }, TKVault_Jobs::stalled( 50 ) );
tkv_check( 'the watchdog does not pick it up', in_array( $job['id'], $stalled, true ), false );

TKVault_Runner::watchdog();
tkv_check( 'still cancelled', tkv_job_row( $job['id'] )->status, 'cancelled' );
tkv_check( 'still not advanced', (int) tkv_job_row( $job['id'] )->processed, 0 );

/* ================================================================= */
tkv_section( '9. a job whose worker died is picked up again' );

tkv_block_loopback();
$job = TKVault_Jobs::create( 'demo', array( 'chunks' => 6 ), 6 );

// A worker that took the job and vanished without releasing it.
TKVault_Jobs::update(
	$job['id'],
	array(
		'status'     => 'running',
		'lock_token' => 'deadworkerdeadworkerdeadworker00',
		'heartbeat'  => gmdate( 'Y-m-d H:i:s', time() - 9999 ),
	)
);

$stalled = array_map( function ( $r ) { return (int) $r->id; }, TKVault_Jobs::stalled( 50 ) );
tkv_check( 'the watchdog sees it as stalled', in_array( $job['id'], $stalled, true ), true );
tkv_check( 'the stale lock can be taken over', (bool) TKVault_Jobs::claim( $job['id'] ), true );
tkv_unblock_loopback();

/* ================================================================= */
tkv_section( '10. a chunk that throws once recovers' );

tkv_block_loopback();
delete_option( 'tkv_test_thrown' );
$job  = TKVault_Jobs::create( 'test_throws_once', array( 'chunks' => 6 ), 6 );
$snap = tkv_drive( $job['id'], 50 );

tkv_check( 'eventually completed', $snap['status'], 'complete' );
tkv_check( 'all chunks processed', $snap['processed'], 6 );
tkv_unblock_loopback();

/* ----------------------------------------------------------------- */
tkv_section( '11. a chunk that always throws is given up on' );

tkv_block_loopback();
$job  = TKVault_Jobs::create( 'test_always_throws', array( 'chunks' => 6 ), 6 );
$snap = tkv_drive( $job['id'], 50 );

tkv_check( 'gave up', $snap['status'], 'failed' );
tkv_check( 'stopped at the cap rather than looping', $snap['requests'] <= TKVault_Jobs::MAX_ATTEMPTS + 2, true );
tkv_check( 'attempts recorded', (int) tkv_job_row( $job['id'] )->attempts, TKVault_Jobs::MAX_ATTEMPTS );
tkv_check( 'explains why', false !== strpos( tkv_job_row( $job['id'] )->message, 'attempts at the same point' ), true );
tkv_info( 'message: ' . substr( tkv_job_row( $job['id'] )->message, 0, 90 ) );
tkv_unblock_loopback();

/* ================================================================= */
tkv_section( '12. two workers cannot advance the same job at once' );

$job   = TKVault_Jobs::create( 'demo', array( 'chunks' => 5 ), 5 );
$first = TKVault_Jobs::claim( $job['id'] );

tkv_check( 'the first claim wins', (bool) $first, true );
tkv_check( 'the second is refused', TKVault_Jobs::claim( $job['id'] ), false );
tkv_check( 'the holder can heartbeat', TKVault_Jobs::heartbeat( $job['id'], $first ), true );
tkv_check( 'a non-holder cannot', TKVault_Jobs::heartbeat( $job['id'], 'not-the-token' ), false );

// Two heartbeats inside the same second write identical values, so MySQL
// reports zero rows changed. That must not read as a lost lock.
tkv_check( 'a repeated heartbeat is still the holder', TKVault_Jobs::heartbeat( $job['id'], $first ), true );

$snap = TKVault_Runner::run( $job['id'] );
tkv_check( 'a second worker does not advance it', $snap['processed'], 0 );

TKVault_Jobs::release( $job['id'], $first );
tkv_check( 'after release it can be claimed again', (bool) TKVault_Jobs::claim( $job['id'] ), true );

/* ================================================================= */
tkv_section( '13. the time budget follows the host' );

$original = ini_get( 'max_execution_time' );

ini_set( 'max_execution_time', '30' );
tkv_check( '30s limit gives 18s', TKVault_Runner::budget(), 18.0 );

ini_set( 'max_execution_time', '300' );
tkv_check( '300s limit gives 180s', TKVault_Runner::budget(), 180.0 );

ini_set( 'max_execution_time', '0' );
tkv_check( 'unlimited falls back to 20s', TKVault_Runner::budget(), 20.0 );

ini_set( 'max_execution_time', '5' );
tkv_check( 'a tiny limit is floored at 5s', TKVault_Runner::budget(), 5.0 );

ini_set( 'max_execution_time', $original );

/* ================================================================= */
tkv_section( '14. a job too big for one request hands over and resumes' );

tkv_block_loopback();
$original = ini_get( 'max_execution_time' );
ini_set( 'max_execution_time', '10' );   // budget becomes 6s
tkv_info( sprintf( 'budget=%.1fs', TKVault_Runner::budget() ) );

$job     = TKVault_Jobs::create( 'demo', array( 'chunks' => 12, 'work_ms' => 900 ), 12 );
$seen    = array();
$requests = 0;
do {
	$snap     = TKVault_Runner::run( $job['id'] );
	$seen[]   = $snap['processed'];
	$requests++;
} while ( in_array( $snap['status'], array( 'pending', 'running' ), true ) && $requests < 20 );

tkv_check( 'completed', $snap['status'], 'complete' );
tkv_check( 'all chunks processed', $snap['processed'], 12 );
tkv_check( 'needed more than one request', $requests > 1, true );
tkv_check( 'a hand-over was recorded', (int) tkv_job_row( $job['id'] )->handovers >= 1, true );
tkv_check( 'progress on every request', count( array_unique( $seen ) ) === count( $seen ), true );
tkv_info( sprintf( 'requests=%d, progress: %s', $requests, implode( ' -> ', $seen ) ) );

ini_set( 'max_execution_time', $original );
tkv_unblock_loopback();

/* ================================================================= */
tkv_section( '15. forward progress is guaranteed even with no budget left' );

tkv_block_loopback();
tkv_single_chunk();

$job  = TKVault_Jobs::create( 'demo', array( 'chunks' => 4 ), 4 );
$snap = TKVault_Runner::run( $job['id'] );

// A while() loop tested before the first chunk would advance nothing here,
// and the job would never finish on a host whose budget is already spent.
tkv_check( 'one chunk still ran', $snap['processed'] >= 1, true );

$snap = tkv_drive( $job['id'], 30 );
tkv_check( 'and it still completes', $snap['status'], 'complete' );

tkv_normal_chunking();
tkv_unblock_loopback();

/* ================================================================= */
tkv_section( '16. a real loopback chain, across separate processes' );

$job = TKVault_Jobs::create( 'demo', array( 'chunks' => 10, 'work_ms' => 2200 ), 10 );
tkv_check( 'dispatch accepted', TKVault_Runner::dispatch( $job['id'] ), true );

$waited = 0;
do {
	sleep( 3 );
	$waited += 3;
	$row     = tkv_job_row( $job['id'] );
} while ( in_array( $row->status, array( 'pending', 'running' ), true ) && $waited < 120 );

tkv_check( 'completed with nobody polling', $row->status, 'complete' );
tkv_check( 'all chunks processed', (int) $row->processed, 10 );
tkv_check( 'it was split across requests', (int) $row->handovers >= 1, true );
tkv_info( sprintf( 'waited=%ds, handovers=%d', $waited, (int) $row->handovers ) );

/* ================================================================= */
tkv_section( 'cleanup' );
$wpdb->query( 'DELETE FROM ' . TKVault_Jobs::table() );
delete_option( 'tkv_test_thrown' );
delete_option( TKVault_Runner::OPTION_LOOPBACK );
echo "  done\n";

tkv_report();
