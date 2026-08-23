<?php
/**
 * WP-CLI command behaviour tests.
 *
 *   bin/deploy.sh && cd /var/www/html && wp eval-file <path>/tests/test-cli.php
 *
 * Run through the command objects rather than by shelling out to `wp`: the
 * suite has to pass with exec, shell_exec and proc_open switched off, which is
 * the whole acceptance criterion for this plugin.
 *
 * WP_CLI::error() ends the process, so the cases below drive the paths that
 * succeed and the guards that can be observed without tripping it.
 *
 * @package TakumiVault
 */

require_once __DIR__ . '/bootstrap.php';

global $wpdb;

if ( ! class_exists( 'TKVault_CLI' ) ) {
	echo "  TKVault_CLI is not loaded. Run this through wp, not php.\n";
	return;
}

/* ================================================================= */
tkv_section( '1. every documented subcommand still exists' );

tkv_check( 'backup', method_exists( 'TKVault_CLI', 'backup' ), true );
tkv_check( 'restore', method_exists( 'TKVault_CLI', 'restore' ), true );
tkv_check( 'undo', method_exists( 'TKVault_CLI', 'undo' ), true );
tkv_check( 'list', method_exists( 'TKVault_CLI', 'list_backups' ), true );
tkv_check( 'check', method_exists( 'TKVault_CLI', 'check' ), true );

/* ================================================================= */
tkv_section( '2. inline mode keeps continuation out of the loopback' );

// The command drives the job itself. A dispatch fired anyway would put a
// second worker on the same job, and on a host whose PHP cannot reach its own
// URL it would also cost a timeout on every hand-over.
$job = TKVault_Jobs::create( 'demo', array( 'chunks' => 4 ), 4 );
delete_option( TKVault_Runner::OPTION_LOOPBACK );

TKVault_Runner::set_inline( true );
tkv_check( 'dispatch refuses to fire', TKVault_Runner::dispatch( $job['id'] ), false );
tkv_check( 'and records no verdict about the loopback', get_option( TKVault_Runner::OPTION_LOOPBACK ), false );

TKVault_Runner::set_inline( false );
tkv_check( 'the flag does not stick', is_bool( TKVault_Runner::dispatch( $job['id'] ) ), true );

/* ================================================================= */
tkv_section( '3. a backup started from the command line finishes in-process' );

tkv_normal_chunking();

$before = (int) TKVault_DB::get_total_count();
$cli    = new TKVault_CLI();

// Not captured: WP_CLI writes to STDOUT directly, so output buffering does
// not see it. The progress line is checked on its own below instead.
$cli->backup( array(), array( 'type' => 'db', 'note' => 'cli suite' ) );

$records = TKVault_DB::get_all_backups( 1, 1 );
$latest  = $records ? $records[0] : null;

tkv_check( 'a backup was recorded', (int) TKVault_DB::get_total_count(), $before + 1 );
tkv_check( 'it is a database backup', $latest ? $latest->type : '', 'db' );
tkv_check( 'the note was carried through', $latest ? $latest->note : '', 'cli suite' );
tkv_check(
	'the archive is on disk',
	$latest ? file_exists( trailingslashit( TKVault_Storage::get_store_dir() ) . $latest->filename ) : false,
	true
);
$job_row = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	'SELECT * FROM ' . TKVault_Jobs::table() . " WHERE type = 'db_dump' ORDER BY id DESC LIMIT 1"
);
tkv_check( 'its job completed', $job_row ? $job_row->status : '', TKVault_Jobs::STATUS_COMPLETE );

/* ================================================================= */
tkv_section( '4. progress reads correctly before the size of the work is known' );

// A handler only learns its real total once it has started, so the line has to
// stay honest while total is still zero rather than printing a false 0%.
$line = new ReflectionMethod( 'TKVault_CLI', 'progress_line' );
$line->setAccessible( true );

tkv_check(
	'with a known total',
	$line->invoke( null, 'Database', array( 'percent' => 50, 'processed' => 5, 'total' => 10 ) ),
	'  Database: 50% (5/10)'
);
tkv_check(
	'with the total not yet known',
	$line->invoke( null, 'Files', array( 'percent' => 0, 'processed' => 7, 'total' => 0 ) ),
	'  Files: 7 done'
);

/* ================================================================= */
tkv_section( '5. the restore description is readable before anything is replaced' );

$description = TKVault_DB_Restore::describe( (int) $latest->id );

tkv_check( 'describe() answered', is_array( $description ), true );
tkv_check( 'it knows what kind of backup this is', $description['kind'], 'db' );
tkv_check( 'it considers the backup restorable', ! empty( $description['ok'] ), true );
tkv_check( 'it counted the tables', (int) $description['tables'] > 0, true );

/* ================================================================= */
tkv_section( 'cleanup' );

if ( $latest ) {
	$path = trailingslashit( TKVault_Storage::get_store_dir() ) . $latest->filename;
	if ( file_exists( $path ) ) {
		wp_delete_file( $path );
	}
	TKVault_DB::delete_backup_record( (int) $latest->id );
}

$wpdb->query( 'DELETE FROM ' . TKVault_Jobs::table() ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
delete_option( TKVault_Runner::OPTION_LOOPBACK );
echo "  done\n";

tkv_report();
