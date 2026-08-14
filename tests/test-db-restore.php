<?php
/**
 * Step 4 database restore tests.
 *   /home/yoshi/projects/takumi-vault/bin/deploy.sh && cd /var/www/html && wp eval-file <this file>
 */

require_once __DIR__ . '/bootstrap.php';

tkv_block_loopback();

// Thin aliases so the assertions below read the same as the other suites
// while the counting stays in one place.
function c( $label, $got, $want ) {
	return tkv_check( $label, $got, $want );
}

function drive( $job_id, $limit = 4000 ) {
	return tkv_drive( $job_id, $limit );
}

function make_dump( $note = 'test' ) {
	$job = TKVault_DB_Dump::start( $note );
	$s   = drive( $job['id'] );
	$st  = TKVault_Jobs::decode( TKVault_Jobs::get( $job['id'] )->state );
	return array(
		'status'   => $s['status'],
		'file'     => isset( $st['file'] ) ? $st['file'] : '',
		'manifest' => isset( $st['base'] ) ? dirname( $st['file'] ) . '/' . $st['base'] . '_manifest.json' : '',
	);
}

function backup_id_for( $file ) {
	global $wpdb;
	return (int) $wpdb->get_var(
		$wpdb->prepare( 'SELECT id FROM ' . $wpdb->prefix . 'tkvault_backups WHERE filename = %s ORDER BY id DESC LIMIT 1', basename( $file ) )
	);
}

global $wpdb;

/* ================================================================= */
echo "\n=== 1. the SQL reader ===\n";

$cases = array(
	"INSERT INTO `t` VALUES ('a; b');"                 => array( "INSERT INTO `t` VALUES ('a; b')" ),
	"SELECT 1; SELECT 2;"                              => array( 'SELECT 1', 'SELECT 2' ),
	"INSERT INTO `t` VALUES ('it\\'s');"               => array( "INSERT INTO `t` VALUES ('it\\'s')" ),
	"INSERT INTO `t` VALUES ('a''b');"                 => array( "INSERT INTO `t` VALUES ('a''b')" ),
	"-- comment; with semicolon\nSELECT 3;"            => array( 'SELECT 3' ),
	"/* block ; comment */ SELECT 4;"                  => array( 'SELECT 4' ),
	"# hash comment;\nSELECT 5;"                       => array( 'SELECT 5' ),
	"INSERT INTO `weird;name` VALUES (1);"             => array( 'INSERT INTO `weird;name` VALUES (1)' ),
	"INSERT INTO `t` VALUES (\"double ; quoted\");"    => array( 'INSERT INTO `t` VALUES ("double ; quoted")' ),
);

$tmp = '/tmp/tkv-reader-test.sql.gz';
foreach ( $cases as $input => $expected ) {
	@unlink( $tmp );
	$h = gzopen( $tmp, 'wb' );
	gzwrite( $h, $input );
	gzclose( $h );

	$reader = new TKVault_SQL_Reader( $tmp );
	$got    = array();
	while ( null !== ( $s = $reader->next_statement() ) ) {
		if ( '' !== $s ) {
			$got[] = trim( $s );
		}
	}
	TKVault_SQL_Reader::close_cached();
	c( substr( str_replace( "\n", '\\n', $input ), 0, 46 ), $got, $expected );
}
@unlink( $tmp );

/* ================================================================= */
echo "\n=== 2. fixtures and a good backup ===\n";
$wpdb->query( 'DROP TABLE IF EXISTS wp_tkv_data' );
$wpdb->query( 'CREATE TABLE wp_tkv_data (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, v TEXT) DEFAULT CHARSET=utf8mb4' );
for ( $i = 1; $i <= 50; $i++ ) {
	$wpdb->query( $wpdb->prepare( 'INSERT INTO wp_tkv_data (v) VALUES (%s)', "original {$i}" ) );
}
update_option( 'tkv_marker', 'before-restore', false );

$good = make_dump( 'restore source' );
c( 'dump completed', $good['status'], 'complete' );
$backup_id = backup_id_for( $good['file'] );
c( 'recorded in the backup list', $backup_id > 0, true );

/* ================================================================= */
echo "\n=== 3. a damaged archive is refused ===\n";
$copy   = dirname( $good['file'] ) . '/tkvtest-tampered.sql.gz';
$mcopy  = dirname( $good['file'] ) . '/tkvtest-tampered_manifest.json';
copy( $good['file'], $copy );
file_put_contents( $copy, file_get_contents( $copy ) . 'corruption', FILE_APPEND );
// Name the archive but keep the ORIGINAL hash, so the checksum is what fails.
$mf = json_decode( file_get_contents( $good['manifest'] ), true );
$mf['files'][0]['name'] = basename( $copy );
unset( $mf['self_sha256'] );
$mf['self_sha256'] = hash( 'sha256', wp_json_encode( $mf ) );
file_put_contents( $mcopy, wp_json_encode( $mf ) );

$wpdb->insert( $wpdb->prefix . 'tkvault_backups', array( 'filename' => basename( $copy ), 'type' => 'db', 'size' => 1, 'status' => 'completed', 'note' => 'tampered' ) );
$tampered_id = (int) $wpdb->insert_id;

$job  = TKVault_DB_Restore::start( $tampered_id );
$snap = drive( $job['id'] );
c( 'refused', $snap['status'], 'failed' );
c( 'says the checksum does not match', false !== strpos( $snap['message'], 'checksum recorded' ), true );
@unlink( $copy );
@unlink( $mcopy );

/* ================================================================= */
echo "\n=== 4. a tampered manifest is refused ===\n";
$copy  = dirname( $good['file'] ) . '/tkvtest-badmf.sql.gz';
$mcopy = dirname( $good['file'] ) . '/tkvtest-badmf_manifest.json';
copy( $good['file'], $copy );
$mf = json_decode( file_get_contents( $good['manifest'] ), true );
$mf['rows_total'] = 999999;                      // edited, self hash left alone
$mf['files'][0]['name'] = basename( $copy );
file_put_contents( $mcopy, wp_json_encode( $mf ) );

$wpdb->insert( $wpdb->prefix . 'tkvault_backups', array( 'filename' => basename( $copy ), 'type' => 'db', 'size' => 1, 'status' => 'completed', 'note' => 'bad manifest' ) );
$job  = TKVault_DB_Restore::start( (int) $wpdb->insert_id );
$snap = drive( $job['id'] );
c( 'refused', $snap['status'], 'failed' );
c( 'says the manifest is inconsistent', false !== strpos( $snap['message'], 'own checksum' ), true );
@unlink( $copy );
@unlink( $mcopy );

/* ================================================================= */
echo "\n=== 5. a truncated archive is refused ===\n";
$copy  = dirname( $good['file'] ) . '/tkvtest-cut.sql.gz';
$mcopy = dirname( $good['file'] ) . '/tkvtest-cut_manifest.json';
$raw   = file_get_contents( $good['file'] );
file_put_contents( $copy, substr( $raw, 0, (int) ( strlen( $raw ) * 0.5 ) ) );
$mf = json_decode( file_get_contents( $good['manifest'] ), true );
$mf['files'][0]['name']   = basename( $copy );
$mf['files'][0]['sha256'] = hash_file( 'sha256', $copy );
unset( $mf['self_sha256'] );
$mf['self_sha256'] = hash( 'sha256', wp_json_encode( $mf ) );
file_put_contents( $mcopy, wp_json_encode( $mf ) );

$wpdb->insert( $wpdb->prefix . 'tkvault_backups', array( 'filename' => basename( $copy ), 'type' => 'db', 'size' => 1, 'status' => 'completed', 'note' => 'truncated' ) );
$job  = TKVault_DB_Restore::start( (int) $wpdb->insert_id );
$snap = drive( $job['id'] );
c( 'refused', $snap['status'], 'failed' );
c( 'names the missing end marker', false !== strpos( $snap['message'], 'end marker' ), true );
@unlink( $copy );
@unlink( $mcopy );

/* ================================================================= */
echo "\n=== 6. a backup from another prefix is refused ===\n";
$copy  = dirname( $good['file'] ) . '/tkvtest-prefix.sql.gz';
$mcopy = dirname( $good['file'] ) . '/tkvtest-prefix_manifest.json';
copy( $good['file'], $copy );
$mf = json_decode( file_get_contents( $good['manifest'] ), true );
$mf['prefix']             = 'other_';
$mf['files'][0]['name']   = basename( $copy );
$mf['files'][0]['sha256'] = hash_file( 'sha256', $copy );
unset( $mf['self_sha256'] );
$mf['self_sha256'] = hash( 'sha256', wp_json_encode( $mf ) );
file_put_contents( $mcopy, wp_json_encode( $mf ) );

$wpdb->insert( $wpdb->prefix . 'tkvault_backups', array( 'filename' => basename( $copy ), 'type' => 'db', 'size' => 1, 'status' => 'completed', 'note' => 'prefix' ) );
$job  = TKVault_DB_Restore::start( (int) $wpdb->insert_id );
$snap = drive( $job['id'] );
c( 'refused', $snap['status'], 'failed' );
c( 'explains the prefix mismatch', false !== strpos( $snap['message'], 'table prefix' ), true );
@unlink( $copy );
@unlink( $mcopy );

/* ================================================================= */
echo "\n=== 7. nothing was touched by any of those refusals ===\n";
c( 'fixture rows intact', (int) $wpdb->get_var( 'SELECT COUNT(*) FROM wp_tkv_data' ), 50 );
c( 'marker intact', get_option( 'tkv_marker' ), 'before-restore' );
c( 'no temporary tables left', (int) $wpdb->get_var( "SELECT COUNT(*) FROM information_schema.TABLES WHERE table_schema = DATABASE() AND table_name LIKE 'tkvaulttmp\\_%'" ), 0 );

/* ================================================================= */
echo "\n=== 8. the real thing: change the site, then restore ===\n";
$wpdb->query( "UPDATE wp_tkv_data SET v = 'DAMAGED'" );
$wpdb->query( 'DELETE FROM wp_tkv_data WHERE id > 40' );
update_option( 'tkv_marker', 'after-damage', false );
c( 'damage applied', (int) $wpdb->get_var( 'SELECT COUNT(*) FROM wp_tkv_data' ), 40 );

$safety_before = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}tkvault_backups WHERE type = 'db-safety'" );

$job  = TKVault_DB_Restore::start( $backup_id, true );
$snap = drive( $job['id'] );
c( 'restore completed', $snap['status'], 'complete' );
printf( "  (info) requests=%d\n", $snap['requests'] );

c( 'rows are back', (int) $wpdb->get_var( 'SELECT COUNT(*) FROM wp_tkv_data' ), 50 );
c( 'values are back', $wpdb->get_var( 'SELECT v FROM wp_tkv_data WHERE id = 7' ), 'original 7' );
wp_cache_flush();
c( 'options are back', get_option( 'tkv_marker' ), 'before-restore' );

/* ================================================================= */
echo "\n=== 9. the safety copy ===\n";
c( 'a safety backup was taken', (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}tkvault_backups WHERE type = 'db-safety'" ) > $safety_before, true );
$safety = $wpdb->get_row( "SELECT * FROM {$wpdb->prefix}tkvault_backups WHERE type = 'db-safety' ORDER BY id DESC LIMIT 1" );
c( 'its file exists', file_exists( TKVault_Storage::get_store_dir() . '/' . $safety->filename ), true );

$prunable = TKVault_DB::get_old_backups( 0 );
$names    = wp_list_pluck( $prunable, 'type' );
c( 'safety copies are exempt from pruning', in_array( 'db-safety', $names, true ), false );

/* ================================================================= */
echo "\n=== 10. the plugin's own tables survived the swap ===\n";
c( 'the running job still exists', null !== TKVault_Jobs::get( $job['id'] ), true );
c( 'the safety backup record survived', (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}tkvault_backups WHERE id = {$safety->id}" ), 1 );
c( 'no temporary tables left behind', (int) $wpdb->get_var( "SELECT COUNT(*) FROM information_schema.TABLES WHERE table_schema = DATABASE() AND table_name LIKE 'tkvaulttmp\\_%'" ), 0 );

/* ================================================================= */
echo "\n=== 11. the previous database is kept, and can be put back ===\n";
$olds = (array) get_option( TKVault_DB_Restore::OPTION_OLD_TABLES, array() );
c( 'old copies recorded', count( $olds ) > 0, true );
c( 'wp_tkv_data_tkvold exists', TKVault_DB_Restore::table_exists( 'wp_tkv_data_tkvold' ), true );
c( 'it holds the damaged data', (int) $wpdb->get_var( 'SELECT COUNT(*) FROM wp_tkv_data_tkvold' ), 40 );

$undo = TKVault_DB_Restore::undo();
c( 'undo succeeded', true === $undo, true );
c( 'the damaged data is live again', (int) $wpdb->get_var( 'SELECT COUNT(*) FROM wp_tkv_data' ), 40 );
c( 'old table list cleared', get_option( TKVault_DB_Restore::OPTION_OLD_TABLES, array() ), array() );

// Put the good data back for the remaining tests.
$job  = TKVault_DB_Restore::start( $backup_id, true );
$snap = drive( $job['id'] );
c( 'restored again', $snap['status'], 'complete' );
c( 'rows restored', (int) $wpdb->get_var( 'SELECT COUNT(*) FROM wp_tkv_data' ), 50 );

/* ================================================================= */
echo "\n=== 12. retention drops the old copies when they expire ===\n";
$olds = (array) get_option( TKVault_DB_Restore::OPTION_OLD_TABLES, array() );
c( 'old copies exist', count( $olds ) > 0, true );

foreach ( $olds as $i => $entry ) {
	$olds[ $i ]['created'] = time() - ( 40 * DAY_IN_SECONDS );
}
update_option( TKVault_DB_Restore::OPTION_OLD_TABLES, $olds, false );
update_option( TKVault_DB_Restore::OPTION_RETENTION, 7 );

TKVault_DB_Restore::drop_expired_old_tables();
c( 'expired copies dropped', TKVault_DB_Restore::table_exists( 'wp_tkv_data_tkvold' ), false );
c( 'list cleared', get_option( TKVault_DB_Restore::OPTION_OLD_TABLES, array() ), array() );

update_option( TKVault_DB_Restore::OPTION_RETENTION, -1 );
c( 'retention -1 means keep', TKVault_DB_Restore::retention_days(), -1 );
update_option( TKVault_DB_Restore::OPTION_RETENTION, 7 );

/* ================================================================= */
echo "\n=== 13. the guard against writing to a live table ===\n";
$m = new ReflectionMethod( 'TKVault_DB_Restore', 'assert_temp_only' );
$m->setAccessible( true );
$map = array( 'wp_tkv_data' => 'tkvaulttmp_wp_tkv_data' );

function guard( $m, $map, $sql ) {
	try {
		$m->invoke( null, $sql, $map );
		return 'allowed';
	} catch ( TKVault_Job_Fatal $e ) {
		return 'refused';
	}
}
c( 'temp target allowed', guard( $m, $map, 'DROP TABLE `tkvaulttmp_wp_tkv_data`' ), 'allowed' );
c( 'LIVE table refused', guard( $m, $map, 'DROP TABLE `wp_tkv_data`' ), 'refused' );
c( 'live INSERT refused', guard( $m, $map, 'INSERT INTO `wp_posts` VALUES (1)' ), 'refused' );
c( 'SET passes through', guard( $m, $map, "SET NAMES utf8mb4" ), 'allowed' );

/* ================================================================= */
echo "\n=== 14. cancelling mid-restore ===\n";
add_filter( 'tkvault_job_budget', function () { return 0.0; }, 99 );
add_filter( 'tkvault_restore_batch', function () { return 1; }, 99 );
$job = TKVault_DB_Restore::start( $backup_id, true );
$n   = 0;
$state = array();
while ( $n < 400 ) {
	$snap  = TKVault_Runner::run( $job['id'] );
	$state = TKVault_Jobs::decode( TKVault_Jobs::get( $job['id'] )->state );
	$n++;
	// Stop the moment the import has actually written something.
	if ( isset( $state['phase'] ) && 'import' === $state['phase'] && (int) $state['imported'] >= 8 ) {
		break;
	}
	if ( ! in_array( $snap['status'], array( 'pending', 'running' ), true ) ) {
		break;
	}
}

c( 'reached the import phase', $state['phase'], 'import' );
$temp_count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM information_schema.TABLES WHERE table_schema = DATABASE() AND table_name LIKE 'tkvaulttmp\\_%'" );
c( 'temporary tables exist mid-import', $temp_count > 0, true );

TKVault_Jobs::cancel( $job['id'] );
remove_all_filters( 'tkvault_job_budget' );
remove_all_filters( 'tkvault_restore_batch' );

c( 'temporary tables removed', (int) $wpdb->get_var( "SELECT COUNT(*) FROM information_schema.TABLES WHERE table_schema = DATABASE() AND table_name LIKE 'tkvaulttmp\\_%'" ), 0 );
c( 'live data untouched', (int) $wpdb->get_var( 'SELECT COUNT(*) FROM wp_tkv_data' ), 50 );
c( 'job is cancelled', TKVault_Jobs::get( $job['id'] )->status, 'cancelled' );

/* ================================================================= */
echo "\n=== cleanup ===\n";
$wpdb->query( 'DROP TABLE IF EXISTS wp_tkv_data' );
$wpdb->query( "DELETE FROM {$wpdb->prefix}tkvault_backups WHERE note IN ('tampered','bad manifest','truncated','prefix')" );
delete_option( 'tkv_marker' );
TKVault_SQL_Reader::close_cached();
echo "  done\n";

tkv_report();
