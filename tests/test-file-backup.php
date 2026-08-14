<?php
/**
 * File backup behaviour tests.
 *
 *   bin/deploy.sh && cd /var/www/html && wp eval-file <path>/tests/test-file-backup.php
 *
 * The failures worth catching here are quiet ones: a file missing from the
 * archive, the backup directory ending up inside its own backup, an
 * incremental that skips something it should have kept.
 *
 * @package TakumiVault
 */

require_once __DIR__ . '/bootstrap.php';

global $wpdb;

tkv_block_loopback();

$root    = wp_normalize_path( untrailingslashit( WP_CONTENT_DIR ) );
$sandbox = $root . '/tkv-test-tree';
$store   = TKVault_Storage::get_store_dir();

/**
 * Restrict the walk to the sandbox so a suite run does not archive the whole
 * of wp-content, while leaving the real exclusion rules in force.
 */
function tkv_only_sandbox( $sandbox ) {
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

function tkv_rmtree( $dir ) {
	if ( ! is_dir( $dir ) ) {
		return;
	}

	// getPathname(), never getRealPath(). For a symlink the latter returns
	// the target, so deleting by it would try to unlink whatever the link
	// points at - the sandbox contains one aimed at /etc/passwd precisely so
	// the plugin can be tested against it.
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

function tkv_zip_entries( $archive ) {
	$zip = new ZipArchive();
	if ( true !== $zip->open( $archive ) ) {
		return array();
	}
	$names = array();
	for ( $i = 0; $i < $zip->numFiles; $i++ ) {
		$names[] = $zip->getNameIndex( $i );
	}
	$zip->close();
	sort( $names );
	return $names;
}

function tkv_run_backup( $note = 'test', $full = false ) {
	$job  = TKVault_File_Backup::start( $note, $full );
	$snap = tkv_drive( $job['id'] );
	return $snap;
}

function tkv_manifest_of( $snap ) {
	$store = TKVault_Storage::get_store_dir();
	return json_decode(
		(string) file_get_contents( trailingslashit( $store ) . $snap['state']['base'] . '_manifest.json' ),
		true
	);
}

/* ================================================================= */
tkv_section( '0. a sandbox tree under wp-content' );

tkv_rmtree( $sandbox );
mkdir( $sandbox . '/keep/nested', 0755, true );
mkdir( $sandbox . '/cache', 0755, true );
mkdir( $sandbox . '/node_modules', 0755, true );

file_put_contents( $sandbox . '/one.txt', 'file one' );
file_put_contents( $sandbox . '/two.bin', random_bytes( 2048 ) );
file_put_contents( $sandbox . '/keep/three.txt', str_repeat( 'three ', 100 ) );
file_put_contents( $sandbox . '/keep/nested/four.txt', 'deeply nested' );
file_put_contents( $sandbox . '/debug.log', 'should be skipped' );
file_put_contents( $sandbox . '/cache/junk.txt', 'regenerable' );
file_put_contents( $sandbox . '/node_modules/pkg.txt', 'huge and pointless' );
@symlink( '/etc/passwd', $sandbox . '/danger-link' );

tkv_only_sandbox( $sandbox );
delete_option( TKVault_File_Backup::OPTION_LAST_MANIFEST );

tkv_info( 'sandbox: ' . $sandbox );

/* ================================================================= */
tkv_section( '1. a full backup' );

$snap = tkv_run_backup( 'full backup' );
tkv_check( 'completed', $snap['status'], 'complete' );

$manifest = tkv_manifest_of( $snap );
tkv_check( 'recorded as a full backup', $manifest['mode'], 'full' );
tkv_check( 'has no parent', $manifest['parent'], null );
tkv_check( 'one volume', count( $manifest['volumes'] ), 1 );

$archive = trailingslashit( $store ) . $manifest['volumes'][0]['name'];
tkv_check( 'the archive exists', file_exists( $archive ), true );
tkv_check( 'the volume hash matches', $manifest['volumes'][0]['sha256'], hash_file( 'sha256', $archive ) );

$claimed = $manifest['self_sha256'];
$copy    = $manifest;
unset( $copy['self_sha256'] );
tkv_check( 'the manifest signs itself', hash( 'sha256', wp_json_encode( $copy ) ), $claimed );

$entries = tkv_zip_entries( $archive );
tkv_info( 'archived: ' . implode( ', ', $entries ) );

/* ================================================================= */
tkv_section( '2. exclusions' );

$expected = array(
	'tkv-test-tree/keep/nested/four.txt',
	'tkv-test-tree/keep/three.txt',
	'tkv-test-tree/one.txt',
	'tkv-test-tree/two.bin',
);
sort( $expected );
tkv_check( 'exactly the files that should be there', $entries, $expected );

tkv_check( 'cache skipped', in_array( 'tkv-test-tree/cache/junk.txt', $entries, true ), false );
tkv_check( 'node_modules skipped', in_array( 'tkv-test-tree/node_modules/pkg.txt', $entries, true ), false );
tkv_check( 'log files skipped', in_array( 'tkv-test-tree/debug.log', $entries, true ), false );
tkv_check( 'symlinks not followed', in_array( 'tkv-test-tree/danger-link', $entries, true ), false );

/* ----------------------------------------------------------------- */
tkv_section( '3. the backup destination is never inside its own backup' );

$rules       = TKVault_File_Backup::exclusions();
$destination = wp_normalize_path( untrailingslashit( realpath( TKVault_Storage::get_dir() ) ) );
tkv_check( 'the destination is excluded by resolved path', in_array( $destination, $rules['paths'], true ), true );

$is_excluded = new ReflectionMethod( 'TKVault_File_Backup', 'is_excluded' );
$is_excluded->setAccessible( true );
tkv_check(
	'a file inside it is excluded',
	$is_excluded->invoke( null, $destination . '/store/anything.zip', 'anything.zip', $rules ),
	true
);
tkv_check(
	'a directory that merely starts with the same name is not',
	$is_excluded->invoke( null, $destination . '-other/file.txt', 'file.txt', $rules ),
	false
);

/* ================================================================= */
tkv_section( '4. every file comes back out byte for byte' );

$zip = new ZipArchive();
$zip->open( $archive );
$same = true;
foreach ( $expected as $name ) {
	$original = file_get_contents( $root . '/' . $name );
	$stored   = $zip->getFromName( $name );
	if ( $original !== $stored ) {
		$same = false;
		tkv_info( 'differs: ' . $name );
	}
}
$zip->close();
tkv_check( 'contents identical', $same, true );

/* ================================================================= */
tkv_section( '5. an incremental backup skips what has not changed' );

file_put_contents( $sandbox . '/one.txt', 'file one, edited' );
touch( $sandbox . '/one.txt', time() + 5 );
file_put_contents( $sandbox . '/keep/five.txt', 'brand new' );
unlink( $sandbox . '/two.bin' );

$snap     = tkv_run_backup( 'incremental' );
$manifest = tkv_manifest_of( $snap );

tkv_check( 'completed', $snap['status'], 'complete' );
tkv_check( 'recorded as incremental', $manifest['mode'], 'incremental' );
tkv_check( 'points at its parent', is_string( $manifest['parent'] ), true );

$archive = trailingslashit( $store ) . $manifest['volumes'][0]['name'];
$entries = tkv_zip_entries( $archive );

tkv_check( 'the edited file is archived', in_array( 'tkv-test-tree/one.txt', $entries, true ), true );
tkv_check( 'the new file is archived', in_array( 'tkv-test-tree/keep/five.txt', $entries, true ), true );
tkv_check( 'untouched files are not', in_array( 'tkv-test-tree/keep/three.txt', $entries, true ), false );
tkv_check( 'the deleted file is recorded', in_array( 'tkv-test-tree/two.bin', $manifest['deleted'], true ), true );
tkv_check( 'unchanged files are still listed', isset( $manifest['files']['tkv-test-tree/keep/three.txt'] ), true );
tkv_info(
	sprintf(
		'present=%d archived=%d unchanged=%d deleted=%d',
		$manifest['counts']['present'],
		$manifest['counts']['archived'],
		$manifest['counts']['unchanged'],
		$manifest['counts']['deleted']
	)
);

/* ----------------------------------------------------------------- */
tkv_section( '6. a forced full backup ignores the previous one' );

$snap     = tkv_run_backup( 'forced full', true );
$manifest = tkv_manifest_of( $snap );
tkv_check( 'recorded as full', $manifest['mode'], 'full' );
tkv_check( 'nothing treated as unchanged', $manifest['counts']['unchanged'], 0 );
tkv_check( 'everything archived', $manifest['counts']['archived'], $manifest['counts']['present'] );

/* ================================================================= */
tkv_section( '7. chunk boundaries: nothing duplicated, nothing missed' );

for ( $i = 1; $i <= 40; $i++ ) {
	file_put_contents( $sandbox . '/keep/bulk-' . $i . '.txt', str_repeat( "bulk {$i} ", 50 ) );
}

// One file per chunk, so the batching is genuinely exercised.
add_filter( 'tkvault_add_batch', function () { return 1; }, 99 );
tkv_single_chunk();

$snap     = tkv_run_backup( 'chunked', true );
$manifest = tkv_manifest_of( $snap );

tkv_normal_chunking();
remove_all_filters( 'tkvault_add_batch' );

tkv_check( 'completed', $snap['status'], 'complete' );
tkv_info( sprintf( 'requests=%d', $snap['requests'] ) );

$entries = array();
foreach ( $manifest['volumes'] as $volume ) {
	$entries = array_merge( $entries, tkv_zip_entries( trailingslashit( $store ) . $volume['name'] ) );
}

$present = array_keys( array_filter( $manifest['files'], function ( $row ) { return ! empty( $row[2] ); } ) );
sort( $present );
sort( $entries );

tkv_check( 'no duplicates across volumes', count( $entries ), count( array_unique( $entries ) ) );
tkv_check( 'every queued file is in an archive', $entries, $present );
tkv_check( 'count matches the manifest', count( $entries ), (int) $manifest['counts']['archived'] );

/* ================================================================= */
tkv_section( '8. volumes split once they get large' );

add_filter( 'tkvault_volume_bytes', function () { return 2048; }, 99 );
add_filter( 'tkvault_add_batch', function () { return 5; }, 99 );

$snap     = tkv_run_backup( 'split', true );
$manifest = tkv_manifest_of( $snap );

remove_all_filters( 'tkvault_volume_bytes' );
remove_all_filters( 'tkvault_add_batch' );

tkv_check( 'completed', $snap['status'], 'complete' );
tkv_check( 'more than one volume', count( $manifest['volumes'] ) > 1, true );

$split = array();
foreach ( $manifest['volumes'] as $volume ) {
	$path = trailingslashit( $store ) . $volume['name'];
	tkv_check( 'volume ' . $volume['name'] . ' hashes correctly', $volume['sha256'], hash_file( 'sha256', $path ) );
	$split = array_merge( $split, tkv_zip_entries( $path ) );
}
sort( $split );

tkv_check( 'no file lost to the split', count( $split ), (int) $manifest['counts']['archived'] );
tkv_check( 'no file duplicated across volumes', count( $split ), count( array_unique( $split ) ) );
tkv_info( sprintf( 'volumes=%d files=%d', count( $manifest['volumes'] ), count( $split ) ) );

/* ================================================================= */
tkv_section( '9. the tar fallback' );

$add_tar = new ReflectionMethod( 'TKVault_File_Backup', 'add_batch_tar' );
$add_tar->setAccessible( true );
$compress = new ReflectionMethod( 'TKVault_File_Backup', 'compress_tars' );
$compress->setAccessible( true );

$target = trailingslashit( $store ) . 'tkv-tar-test.tar.gz';
@unlink( $target );
@unlink( str_replace( '.tar.gz', '.tar', $target ) );

$add_tar->invoke( null, $target, array( array( 'tkv-test-tree/one.txt', 16, time(), 1 ) ), $root );
tkv_check( 'a tar is built uncompressed first', file_exists( str_replace( '.tar.gz', '.tar', $target ) ), true );

$compress->invoke( null, array( $target ) );
tkv_check( 'and gzipped at the end', file_exists( $target ), true );
tkv_check( 'the uncompressed tar is removed', file_exists( str_replace( '.tar.gz', '.tar', $target ) ), false );

$phar  = new PharData( $target );
$found = false;
foreach ( new RecursiveIteratorIterator( $phar ) as $file ) {
	if ( false !== strpos( $file->getPathname(), 'one.txt' ) ) {
		$found = true;
	}
}
tkv_check( 'the file is readable inside it', $found, true );
unset( $phar );
@unlink( $target );

/* ================================================================= */
tkv_section( '10. cancelling leaves nothing behind' );

$before = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . $wpdb->prefix . 'tkvault_backups' );

tkv_single_chunk();
add_filter( 'tkvault_add_batch', function () { return 1; }, 99 );

$job = TKVault_File_Backup::start( 'cancelled', true );
for ( $i = 0; $i < 40; $i++ ) {
	TKVault_Runner::run( $job['id'] );
	$state = TKVault_Jobs::decode( TKVault_Jobs::get( $job['id'] )->state );
	if ( isset( $state['phase'] ) && 'archive' === $state['phase'] && (int) $state['added'] > 0 ) {
		break;
	}
}

tkv_check( 'reached the archive phase', $state['phase'], 'archive' );
$partials = glob( trailingslashit( $store ) . $state['base'] . '*' );
tkv_check( 'partial files exist mid-run', count( $partials ) > 0, true );

TKVault_Jobs::cancel( $job['id'] );

tkv_normal_chunking();
remove_all_filters( 'tkvault_add_batch' );

tkv_check( 'they were removed', count( (array) glob( trailingslashit( $store ) . $state['base'] . '*' ) ), 0 );
tkv_check( 'nothing recorded in the backup list', (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . $wpdb->prefix . 'tkvault_backups' ), $before );
tkv_check( 'the job is cancelled', TKVault_Jobs::get( $job['id'] )->status, 'cancelled' );

/* ================================================================= */
tkv_section( '11. the scratch list file does not survive' );

$snap = tkv_run_backup( 'tidy', true );
tkv_check( 'completed', $snap['status'], 'complete' );
tkv_check( 'the file list was cleaned up', file_exists( $snap['state']['list_file'] ), false );

/* ================================================================= */
tkv_section( 'cleanup' );

tkv_rmtree( $sandbox );
foreach ( (array) glob( trailingslashit( $store ) . '*' ) as $path ) {
	if ( is_file( $path ) ) {
		@unlink( $path );
	}
}
$wpdb->query( 'DELETE FROM ' . TKVault_Jobs::table() );
$wpdb->query( 'DELETE FROM ' . $wpdb->prefix . 'tkvault_backups' );
delete_option( TKVault_File_Backup::OPTION_LAST_MANIFEST );
remove_all_filters( 'tkvault_file_exclusions' );
tkv_unblock_loopback();
echo "  done\n";

tkv_report();
