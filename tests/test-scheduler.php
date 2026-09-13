<?php
/**
 * Scheduled backup behaviour tests.
 *
 *   bin/deploy.sh && cd /var/www/html && wp eval-file <path>/tests/test-scheduler.php
 *
 * The old scheduler called TKVault_Backup::run() synchronously. It shelled
 * out to mysqldump, and it opened with current_user_can(), which under cron
 * has no user to test. These cases exist to keep both mistakes from coming
 * back: everything here runs with no user logged in.
 *
 * @package TakumiVault
 */

require_once __DIR__ . '/bootstrap.php';

global $wpdb;

tkv_block_loopback();

$store = TKVault_Storage::get_store_dir();

/** Collect mail instead of sending it. */
$GLOBALS['tkv_mail'] = array();
add_filter(
	'pre_wp_mail',
	function ( $short, $atts ) {
		$GLOBALS['tkv_mail'][] = $atts;
		return true;   // Handled; wp_mail() returns without sending.
	},
	10,
	2
);

function tkv_mail_count() {
	return count( $GLOBALS['tkv_mail'] );
}

function tkv_last_mail() {
	$all = $GLOBALS['tkv_mail'];
	return $all ? end( $all ) : array( 'subject' => '', 'message' => '' );
}

function tkv_run_record() {
	return get_option( TKVault_Scheduler::OPTION_RUN );
}

/** The instance the plugin built on plugins_loaded already has the hooks. */
function tkv_cron_tick() {
	do_action( 'tkvault_scheduled_backup' );
}

/** Drive whatever job the scheduler currently has in flight, to the end. */
function tkv_drive_current() {
	$run = tkv_run_record();
	if ( ! is_array( $run ) || empty( $run['job'] ) ) {
		return null;
	}
	return tkv_drive( (int) $run['job'] );
}

function tkv_reset() {
	global $wpdb;
	delete_option( TKVault_Scheduler::OPTION_RUN );
	$wpdb->query( 'DELETE FROM ' . TKVault_Jobs::table() );
	$wpdb->query( 'DELETE FROM ' . $wpdb->prefix . 'tkvault_backups' );
	$GLOBALS['tkv_mail'] = array();
}

/* ================================================================= */
tkv_section( '0. there is no user, which is the whole point' );

wp_set_current_user( 0 );
tkv_check( 'no user is logged in', get_current_user_id(), 0 );
tkv_check( 'and that user cannot manage options', current_user_can( 'manage_options' ), false );

// Keep the run small: the file stage would otherwise archive all of
// wp-content, which is not what these cases are about.
$sandbox = wp_normalize_path( untrailingslashit( WP_CONTENT_DIR ) ) . '/tkv-sched-tree';
if ( ! is_dir( $sandbox ) ) {
	mkdir( $sandbox, 0755, true );
}
file_put_contents( $sandbox . '/a.txt', 'a' );

add_filter(
	'tkvault_file_exclusions',
	function ( $rules ) use ( $sandbox ) {
		$root = wp_normalize_path( untrailingslashit( WP_CONTENT_DIR ) );
		foreach ( (array) scandir( $root ) as $entry ) {
			if ( '.' === $entry || '..' === $entry ) {
				continue;
			}
			if ( $root . '/' . $entry !== $sandbox ) {
				$rules['paths'][] = $root . '/' . $entry;
			}
		}
		return $rules;
	},
	99
);

/* ================================================================= */
tkv_section( '1. a cron tick queues the database stage' );

tkv_reset();
tkv_cron_tick();

$run = tkv_run_record();
tkv_check( 'a run was recorded', is_array( $run ), true );
tkv_check( 'it starts with the database', is_array( $run ) ? $run['stage'] : '', 'db' );

$job = TKVault_Jobs::get( (int) $run['job'] );
tkv_check( 'the job is a db_dump', $job ? $job->type : '', 'db_dump' );
tkv_check( 'and it is open, not run inline', TKVault_Jobs::is_open( $job ), true );
tkv_check( 'no mail yet', tkv_mail_count(), 0 );

/* ================================================================= */
tkv_section( '2. finishing the database stage moves on to the files' );

$snap = tkv_drive_current();
tkv_check( 'the database stage completed', $snap['status'], 'complete' );

$run = tkv_run_record();
tkv_check( 'the run advanced', is_array( $run ) ? $run['stage'] : '', 'files' );

$job = TKVault_Jobs::get( (int) $run['job'] );
tkv_check( 'the second job is a file_backup', $job ? $job->type : '', 'file_backup' );
tkv_check( 'still no mail', tkv_mail_count(), 0 );

/* ================================================================= */
tkv_section( '3. finishing the file stage ends the run and reports' );

update_option( 'tkvault_notify_on_success', true );
$snap = tkv_drive_current();
tkv_check( 'the file stage completed', $snap['status'], 'complete' );
tkv_check( 'the run record is cleared', tkv_run_record(), false );
tkv_check( 'one success mail was sent', tkv_mail_count(), 1 );
tkv_check( 'it says completed', false !== strpos( tkv_last_mail()['subject'], 'completed' ), true );

$types = $wpdb->get_col( "SELECT type FROM {$wpdb->prefix}tkvault_backups" );
tkv_check( 'both stages recorded a backup', in_array( 'db', $types, true ) && in_array( 'files', $types, true ), true );

/* ================================================================= */
tkv_section( '4. success mail can be switched off' );

tkv_reset();
update_option( 'tkvault_notify_on_success', false );
tkv_cron_tick();
tkv_drive_current();
tkv_drive_current();
tkv_check( 'the run finished', tkv_run_record(), false );
tkv_check( 'and stayed quiet', tkv_mail_count(), 0 );
update_option( 'tkvault_notify_on_success', true );

/* ================================================================= */
tkv_section( '5. runs do not stack' );

tkv_reset();
tkv_cron_tick();
$first = (int) tkv_run_record()['job'];

// A site whose backup takes longer than its interval gets a second tick
// while the first is still going. It must not start another.
tkv_cron_tick();
tkv_check( 'the same job is still the one in flight', (int) tkv_run_record()['job'], $first );
tkv_check( 'only one job exists', (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . TKVault_Jobs::table() ), 1 );

/* ================================================================= */
tkv_section( '6. a failing stage alerts and stops the run' );

tkv_reset();

// Fail the db stage deterministically, through the handler filter so the
// failure injection never ships.
add_filter(
	'tkvault_job_handlers',
	function ( $handlers ) {
		$handlers['db_dump'] = function () {
			throw new TKVault_Job_Fatal( 'disk melted' );
		};
		return $handlers;
	},
	99
);

tkv_cron_tick();
$snap = tkv_drive_current();
tkv_check( 'the job failed', $snap['status'], 'failed' );
tkv_check( 'the run was abandoned', tkv_run_record(), false );
tkv_check( 'one alert was sent', tkv_mail_count(), 1 );
tkv_check( 'it says failed', false !== strpos( tkv_last_mail()['subject'], 'failed' ), true );
tkv_check( 'and carries the reason', false !== strpos( tkv_last_mail()['message'], 'disk melted' ), true );
tkv_check( 'the file stage never started', (int) $wpdb->get_var( "SELECT COUNT(*) FROM " . TKVault_Jobs::table() . " WHERE type = 'file_backup'" ), 0 );

remove_all_filters( 'tkvault_job_handlers', 99 );

/* ================================================================= */
tkv_section( '7. cancelling is not a failure' );

tkv_reset();
tkv_cron_tick();
$job_id = (int) tkv_run_record()['job'];

TKVault_Jobs::cancel( $job_id );
tkv_check( 'the run record is cleared', tkv_run_record(), false );
tkv_check( 'no alert was sent', tkv_mail_count(), 0 );

/* ================================================================= */
tkv_section( '8. the end-of-job hook fires once per job' );

tkv_reset();
$GLOBALS['tkv_finished'] = array();
add_action(
	'tkvault_job_finished',
	function ( $job, $status ) {
		$GLOBALS['tkv_finished'][] = (int) $job->id . ':' . $status;
	},
	10,
	2
);

$job = TKVault_DB_Dump::start( 'hook count' );
tkv_drive( $job['id'] );

// Calling finish() again on a job that is already finished must not
// re-announce it: notifications and pruning both hang off this hook.
TKVault_Jobs::finish( $job['id'], TKVault_Jobs::STATUS_COMPLETE );
TKVault_Jobs::finish( $job['id'], TKVault_Jobs::STATUS_FAILED, 'late' );

$mine = array_filter(
	$GLOBALS['tkv_finished'],
	function ( $entry ) use ( $job ) {
		return 0 === strpos( $entry, (int) $job['id'] . ':' );
	}
);
tkv_check( 'announced exactly once', count( $mine ), 1 );
tkv_check( 'as complete', array_values( $mine )[0], (int) $job['id'] . ':complete' );

$row = TKVault_Jobs::get( $job['id'] );
tkv_check( 'and the late failure did not overwrite the status', $row->status, 'complete' );

remove_all_actions( 'tkvault_job_finished', 10 );

/* ================================================================= */
tkv_section( '9. pruning keeps its hands off a directory we do not own' );

tkv_reset();
update_option( 'tkvault_keep_generations', 0 );

// Two records with real files behind them.
$victims = array();
foreach ( array( 'prune-a.sql.gz', 'prune-b.sql.gz' ) as $name ) {
	$path = trailingslashit( $store ) . $name;
	file_put_contents( $path, 'x' );
	TKVault_DB::insert_backup(
		array(
			'filename' => $name,
			'type'     => 'db',
			'size'     => 1,
			'status'   => 'completed',
			'note'     => 'prune test',
		)
	);
	$victims[] = $path;
}

$marker = trailingslashit( TKVault_Storage::get_dir() ) . TKVault_Storage::MARKER;
$saved  = file_exists( $marker ) ? file_get_contents( $marker ) : null;
if ( null !== $saved ) {
	unlink( $marker );
}

tkv_check( 'the directory now reads as not ours', TKVault_Storage::is_owned( TKVault_Storage::get_dir() ), false );

$prune = new ReflectionMethod( 'TKVault_Scheduler', 'prune_old_backups' );
$prune->setAccessible( true );
$prune->invoke( new TKVault_Scheduler() );

$still = 0;
foreach ( $victims as $path ) {
	if ( file_exists( $path ) ) {
		$still++;
	}
}
tkv_check( 'nothing was deleted', $still, 2 );
tkv_check( 'and the records survived', (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}tkvault_backups WHERE note = 'prune test'" ), 2 );

// Put the marker back and confirm pruning does work when it should.
if ( null !== $saved ) {
	file_put_contents( $marker, $saved );
}
tkv_check( 'the directory is ours again', TKVault_Storage::is_owned( TKVault_Storage::get_dir() ), true );

$prune->invoke( new TKVault_Scheduler() );

$still = 0;
foreach ( $victims as $path ) {
	if ( file_exists( $path ) ) {
		$still++;
	}
}
tkv_check( 'now they are gone', $still, 0 );
tkv_check( 'and so are the records', (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}tkvault_backups WHERE note = 'prune test'" ), 0 );

update_option( 'tkvault_keep_generations', 10 );

/* ================================================================= */
tkv_section( '10. the schedule starts at the chosen time of day' );

tkv_check( 'a plain time survives', TKVault_Scheduler::sanitize_time( '03:00' ), '03:00' );
tkv_check( 'a single-digit hour is padded', TKVault_Scheduler::sanitize_time( '4:05' ), '04:05' );
tkv_check( 'midnight is a valid choice', TKVault_Scheduler::sanitize_time( '00:00' ), '00:00' );
tkv_check( 'so is the last minute of the day', TKVault_Scheduler::sanitize_time( '23:59' ), '23:59' );
tkv_check( 'an impossible hour falls back', TKVault_Scheduler::sanitize_time( '24:00' ), TKVault_Scheduler::DEFAULT_TIME );
tkv_check( 'so do impossible minutes', TKVault_Scheduler::sanitize_time( '12:60' ), TKVault_Scheduler::DEFAULT_TIME );
tkv_check( 'and so does nonsense', TKVault_Scheduler::sanitize_time( 'tea time' ), TKVault_Scheduler::DEFAULT_TIME );
tkv_check( 'and so does a non-string', TKVault_Scheduler::sanitize_time( null ), TKVault_Scheduler::DEFAULT_TIME );

// The point of the whole change: the next run lands on the requested local
// time, in the future, rather than at whatever moment this happened to run.
$zone = wp_timezone();
foreach ( array( '00:00', '03:00', '13:37', '23:59' ) as $wanted ) {
	$stamp = TKVault_Scheduler::next_run_timestamp( $wanted );
	$local = ( new DateTimeImmutable( '@' . $stamp ) )->setTimezone( $zone );

	tkv_check( "the next run for {$wanted} is in the future", $stamp > time(), true );
	tkv_check( "the next run for {$wanted} lands on {$wanted}", $local->format( 'H:i' ), $wanted );
	tkv_check( "the next run for {$wanted} is within a day", ( $stamp - time() ) <= ( DAY_IN_SECONDS + 2 * HOUR_IN_SECONDS ), true );
}

// And it has to reach WP-Cron, not merely be computed.
tkv_reset();
TKVault_Scheduler::clear_scheduled_events();
update_option( TKVault_Scheduler::OPTION_TIME, '02:30' );

$scheduler = new TKVault_Scheduler();
$scheduler->schedule( 'daily' );

$booked = wp_next_scheduled( 'tkvault_scheduled_backup' );
tkv_check( 'a daily run is booked', (bool) $booked, true );
tkv_check(
	'at the time that was asked for',
	( new DateTimeImmutable( '@' . (int) $booked ) )->setTimezone( $zone )->format( 'H:i' ),
	'02:30'
);

$event = wp_get_scheduled_event( 'tkvault_scheduled_backup' );
tkv_check( 'with a daily recurrence', $event ? $event->schedule : '', 'daily' );

// A frequency the plugin does not offer must leave nothing behind rather
// than fall through to some default.
$scheduler->schedule( 'hourly' );
tkv_check( 'an unsupported frequency books nothing', wp_next_scheduled( 'tkvault_scheduled_backup' ), false );

delete_option( TKVault_Scheduler::OPTION_TIME );

/* ================================================================= */
tkv_section( '11. no shelling out is left anywhere' );

$found = array();
foreach ( (array) glob( TKVAULT_PLUGIN_DIR . 'includes/*.php' ) as $file ) {
	if ( preg_match( '/\b(exec|shell_exec|passthru|proc_open|system|popen)\s*\(/', file_get_contents( $file ) ) ) {
		$found[] = basename( $file );
	}
}
tkv_check( 'no process functions in the shipped classes', $found, array() );
tkv_check( 'the old backup class is gone', class_exists( 'TKVault_Backup' ), false );
tkv_check( 'the old restore class is gone', class_exists( 'TKVault_Restore' ), false );

// The two endpoints that reached them must be gone too, or an authenticated
// admin request could still drive an unguarded extractTo().
tkv_check( 'no run_backup endpoint', has_action( 'wp_ajax_tkvault_run_backup' ), false );
tkv_check( 'no run_restore endpoint', has_action( 'wp_ajax_tkvault_run_restore' ), false );

/* ================================================================= */
tkv_section( 'cleanup' );

tkv_reset();
delete_option( TKVault_File_Backup::OPTION_LAST_MANIFEST );
foreach ( (array) glob( trailingslashit( $store ) . '*' ) as $path ) {
	if ( is_file( $path ) ) {
		unlink( $path );
	}
}
foreach ( (array) glob( $sandbox . '/*' ) as $path ) {
	unlink( $path );
}
rmdir( $sandbox );
remove_all_filters( 'tkvault_file_exclusions', 99 );
remove_all_filters( 'pre_wp_mail' );
tkv_unblock_loopback();
echo "  done\n";

tkv_report();
