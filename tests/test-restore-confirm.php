<?php
/**
 * Restore confirmation summary tests.
 *
 *   bin/deploy.sh && cd /var/www/html && wp eval-file <path>/tests/test-restore-confirm.php
 *
 * describe() is what the confirmation screen reads. It has to be right about
 * what a restore would replace, and it has to say so before the button
 * appears rather than after it is pressed - a summary that looks fine and
 * then fails mid-restore is worse than no summary.
 *
 * @package TakumiVault
 */

require_once __DIR__ . '/bootstrap.php';

global $wpdb;

tkv_block_loopback();

$store   = TKVault_Storage::get_store_dir();
$sandbox = wp_normalize_path( untrailingslashit( WP_CONTENT_DIR ) ) . '/tkv-confirm';

function tkv_rmtree_c( $dir ) {
	if ( ! is_dir( $dir ) ) {
		return;
	}
	$items = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator( $dir, RecursiveDirectoryIterator::SKIP_DOTS ),
		RecursiveIteratorIterator::CHILD_FIRST
	);
	foreach ( $items as $item ) {
		$path = $item->getPathname();
		if ( is_link( $path ) || ! $item->isDir() ) {
			@unlink( $path );
		} else {
			@rmdir( $path );
		}
	}
	@rmdir( $dir );
}

function tkv_only( $sandbox ) {
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
}

function tkv_id_for( $filename ) {
	global $wpdb;
	return (int) $wpdb->get_var(
		$wpdb->prepare( 'SELECT id FROM ' . $wpdb->prefix . 'tkvault_backups WHERE filename = %s ORDER BY id DESC LIMIT 1', $filename )
	);
}

/* ================================================================= */
tkv_section( '0. fixtures' );

$wpdb->query( 'DELETE FROM ' . TKVault_Jobs::table() );
$wpdb->query( 'DELETE FROM ' . $wpdb->prefix . 'tkvault_backups' );
foreach ( (array) glob( trailingslashit( $store ) . '*' ) as $p ) {
	if ( is_file( $p ) ) {
		unlink( $p );
	}
}
delete_option( TKVault_File_Backup::OPTION_LAST_MANIFEST );

tkv_rmtree_c( $sandbox );
mkdir( $sandbox . '/sub', 0755, true );
file_put_contents( $sandbox . '/one.txt', 'one' );
file_put_contents( $sandbox . '/sub/two.txt', 'two' );
tkv_only( $sandbox );

$db_job = TKVault_DB_Dump::start( 'for confirm' );
tkv_drive( $db_job['id'] );
$db_file = $wpdb->get_var( "SELECT filename FROM {$wpdb->prefix}tkvault_backups WHERE type = 'db' ORDER BY id DESC LIMIT 1" );
$db_id   = tkv_id_for( $db_file );
tkv_check( 'a database backup exists', $db_id > 0, true );

$f_job = TKVault_File_Backup::start( 'full', true );
$f_snap = tkv_drive( $f_job['id'] );
$full_base = $f_snap['state']['base'];

file_put_contents( $sandbox . '/three.txt', 'three' );
$i_job  = TKVault_File_Backup::start( 'incremental' );
$i_snap = tkv_drive( $i_job['id'] );
$inc_base = $i_snap['state']['base'];

$full_id = tkv_id_for( json_decode( file_get_contents( trailingslashit( $store ) . $full_base . '_manifest.json' ), true )['volumes'][0]['name'] );
$inc_id  = tkv_id_for( json_decode( file_get_contents( trailingslashit( $store ) . $inc_base . '_manifest.json' ), true )['volumes'][0]['name'] );
tkv_check( 'a full and an incremental exist', $full_id > 0 && $inc_id > 0, true );

/* ================================================================= */
tkv_section( '1. a database backup describes what it replaces' );

$d = TKVault_DB_Restore::describe( $db_id );
tkv_check( 'it is not an error', is_wp_error( $d ), false );
tkv_check( 'kind', $d['kind'], 'db' );
tkv_check( 'usable', $d['ok'], true );
tkv_check( 'it counts the tables', $d['tables'] > 0, true );
tkv_check( 'it counts the rows', $d['rows'] > 0, true );
tkv_check( 'it names the prefix', $d['prefix'], $wpdb->prefix );
tkv_check( 'the backup is from this site', $d['foreign'], false );
tkv_check( 'it reports the undo window', $d['retention'], (int) TKVault_DB_Restore::retention_days() );
tkv_info( sprintf( '%d tables, %s rows', $d['tables'], number_format_i18n( $d['rows'] ) ) );

/* ================================================================= */
tkv_section( '2. a backup from another site is called out' );

// Restoring another site's database leaves its URLs in the content. The
// plugin does not rewrite them, so the screen has to say so beforehand.
$path     = trailingslashit( $store ) . preg_replace( '/\.sql\.gz$/', '', $db_file ) . '_manifest.json';
$intact   = file_get_contents( $path );
$m        = json_decode( $intact, true );
$m['site_url'] = 'https://somewhere-else.example';
file_put_contents( $path, wp_json_encode( $m, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );

$d = TKVault_DB_Restore::describe( $db_id );
tkv_check( 'flagged as foreign', $d['foreign'], true );
tkv_check( 'and the address is carried through', $d['site_url'], 'https://somewhere-else.example' );

file_put_contents( $path, $intact );
tkv_check( 'restored fixture is not foreign again', TKVault_DB_Restore::describe( $db_id )['foreign'], false );

/* ================================================================= */
tkv_section( '3. an incremental reports its whole chain' );

$d = TKVault_File_Restore::describe( $inc_id );
tkv_check( 'kind', $d['kind'], 'files' );
tkv_check( 'usable', $d['ok'], true );
tkv_check( 'two links in the chain', $d['links'], 2 );
tkv_check( 'the chain starts with the full backup', $d['chain'][0]['mode'], 'full' );
tkv_check( 'and ends with the incremental', $d['chain'][1]['base'], $inc_base );
tkv_check( 'the file count is the sum, not just the last link', $d['files'], $d['chain'][0]['archived'] + $d['chain'][1]['archived'] );
tkv_info( sprintf( '%d links, %d files', $d['links'], $d['files'] ) );

$d = TKVault_File_Restore::describe( $full_id );
tkv_check( 'the full backup alone is one link', $d['links'], 1 );

/* ================================================================= */
tkv_section( '4. a broken chain is reported before the button, not after' );

$parent = trailingslashit( $store ) . $full_base . '_manifest.json';
$saved  = file_get_contents( $parent );
unlink( $parent );

$d = TKVault_File_Restore::describe( $inc_id );
tkv_check( 'not usable', $d['ok'], false );
tkv_check( 'and it says why', false !== strpos( $d['problem'], 'chain' ), true );

file_put_contents( $parent, $saved );
tkv_check( 'usable again once the link is back', TKVault_File_Restore::describe( $inc_id )['ok'], true );

/* ================================================================= */
tkv_section( '5. a missing archive is reported, and named' );

$manifest = json_decode( file_get_contents( trailingslashit( $store ) . $full_base . '_manifest.json' ), true );
$volume   = $manifest['volumes'][0]['name'];
$archive  = trailingslashit( $store ) . $volume;
$bytes    = file_get_contents( $archive );
unlink( $archive );

$d = TKVault_File_Restore::describe( $inc_id );
tkv_check( 'not usable', $d['ok'], false );
tkv_check( 'the missing file is named', in_array( $volume, $d['missing'], true ), true );

file_put_contents( $archive, $bytes );
TKVault_Storage::secure_file( $archive );
tkv_check( 'usable again once it is back', TKVault_File_Restore::describe( $inc_id )['ok'], true );

/* ================================================================= */
tkv_section( '6. a tampered manifest is refused here too' );

$path   = trailingslashit( $store ) . $inc_base . '_manifest.json';
$intact = file_get_contents( $path );
$m      = json_decode( $intact, true );
$m['counts']['archived'] = 9999;              // self hash left alone
file_put_contents( $path, wp_json_encode( $m ) );

$d = TKVault_File_Restore::describe( $inc_id );
tkv_check( 'not usable', $d['ok'], false );
tkv_check( 'it names the checksum', false !== strpos( $d['problem'], 'checksum' ), true );

file_put_contents( $path, $intact );

/* ================================================================= */
tkv_section( '7. a backup that does not exist' );

$d = TKVault_File_Restore::describe( 999999 );
tkv_check( 'is an error, not an empty summary', is_wp_error( $d ), true );
$d = TKVault_DB_Restore::describe( 999999 );
tkv_check( 'for the database side too', is_wp_error( $d ), true );

/* ================================================================= */
tkv_section( '8. describe() changes nothing' );

// The confirmation screen is read-only. It runs before the site owner has
// agreed to anything, so it must not touch the database, the archives, or
// the job table.
$before_jobs    = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . TKVault_Jobs::table() );
$before_backups = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}tkvault_backups" );
$before_files   = array();
foreach ( (array) glob( trailingslashit( $store ) . '*' ) as $p ) {
	$before_files[ basename( $p ) ] = filesize( $p );
}
$before_replaced = TKVault_File_Restore::replaced_count();

TKVault_DB_Restore::describe( $db_id );
TKVault_File_Restore::describe( $inc_id );
TKVault_File_Restore::describe( $full_id );

$after_files = array();
foreach ( (array) glob( trailingslashit( $store ) . '*' ) as $p ) {
	$after_files[ basename( $p ) ] = filesize( $p );
}

tkv_check( 'no jobs created', (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . TKVault_Jobs::table() ), $before_jobs );
tkv_check( 'no backup records touched', (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}tkvault_backups" ), $before_backups );
tkv_check( 'no archive changed', $after_files, $before_files );
tkv_check( 'nothing was displaced', TKVault_File_Restore::replaced_count(), $before_replaced );

/* ================================================================= */
tkv_section( 'cleanup' );

remove_all_filters( 'tkvault_file_exclusions', 99 );
tkv_rmtree_c( $sandbox );
foreach ( (array) glob( trailingslashit( $store ) . '*' ) as $p ) {
	if ( is_file( $p ) ) {
		unlink( $p );
	}
}
$wpdb->query( 'DELETE FROM ' . TKVault_Jobs::table() );
$wpdb->query( 'DELETE FROM ' . $wpdb->prefix . 'tkvault_backups' );
delete_option( TKVault_File_Backup::OPTION_LAST_MANIFEST );
tkv_unblock_loopback();
echo "  done\n";

tkv_report();
