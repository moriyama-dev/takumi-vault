<?php
/**
 * File restore behaviour tests.
 *
 *   bin/deploy.sh && cd /var/www/html && wp eval-file <path>/tests/test-file-restore.php
 *
 * Extraction writes to the live site, so most of these cases are about
 * refusing to start, and about what must never be written over.
 *
 * @package TakumiVault
 */

require_once __DIR__ . '/bootstrap.php';

global $wpdb;

tkv_block_loopback();

$root    = wp_normalize_path( untrailingslashit( WP_CONTENT_DIR ) );
$sandbox = $root . '/tkv-test-tree';
$store   = TKVault_Storage::get_store_dir();

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

function tkv_backup( $note, $full = false ) {
	$job  = TKVault_File_Backup::start( $note, $full );
	$snap = tkv_drive( $job['id'] );
	return $snap['state']['base'];
}

function tkv_backup_id( $base ) {
	global $wpdb;
	$manifest = json_decode( file_get_contents( trailingslashit( TKVault_Storage::get_store_dir() ) . $base . '_manifest.json' ), true );
	$first    = $manifest['volumes'][0]['name'];
	return (int) $wpdb->get_var(
		$wpdb->prepare( 'SELECT id FROM ' . $wpdb->prefix . 'tkvault_backups WHERE filename = %s ORDER BY id DESC LIMIT 1', $first )
	);
}

function tkv_restore( $backup_id ) {
	$job = TKVault_File_Restore::start( $backup_id );
	if ( is_wp_error( $job ) ) {
		return array( 'status' => 'failed', 'message' => $job->get_error_message(), 'state' => array() );
	}
	return tkv_drive( $job['id'] );
}

/* ================================================================= */
tkv_section( '0. a sandbox, a full backup, then an incremental' );

tkv_rmtree( $sandbox );
mkdir( $sandbox . '/keep', 0755, true );
file_put_contents( $sandbox . '/one.txt', 'original one' );
file_put_contents( $sandbox . '/two.txt', 'original two' );
file_put_contents( $sandbox . '/keep/three.txt', str_repeat( 'three ', 200 ) );

tkv_only_sandbox( $sandbox );
delete_option( TKVault_File_Backup::OPTION_LAST_MANIFEST );
delete_option( TKVault_File_Restore::OPTION_REPLACED );

$full = tkv_backup( 'full', true );
tkv_info( 'full: ' . $full );

file_put_contents( $sandbox . '/one.txt', 'edited one' );
touch( $sandbox . '/one.txt', time() + 5 );
file_put_contents( $sandbox . '/keep/four.txt', 'added later' );

$incremental = tkv_backup( 'incremental' );
tkv_info( 'incremental: ' . $incremental );

$expected = array();
foreach ( array( 'one.txt', 'two.txt', 'keep/three.txt', 'keep/four.txt' ) as $rel ) {
	$expected[ $rel ] = file_get_contents( $sandbox . '/' . $rel );
}

/* ================================================================= */
tkv_section( '1. restoring the chain reconstructs the tree' );

tkv_rmtree( $sandbox );
tkv_check( 'the tree is gone', is_dir( $sandbox ), false );

$snap = tkv_restore( tkv_backup_id( $incremental ) );
tkv_check( 'completed', $snap['status'], 'complete' );
tkv_info( sprintf( 'restored=%d replaced=%d skipped=%d', $snap['state']['restored'], $snap['state']['replaced'], $snap['state']['skipped'] ) );

$same = true;
foreach ( $expected as $rel => $contents ) {
	if ( ! file_exists( $sandbox . '/' . $rel ) || file_get_contents( $sandbox . '/' . $rel ) !== $contents ) {
		$same = false;
		tkv_info( 'wrong or missing: ' . $rel );
	}
}
tkv_check( 'every file is back, byte for byte', $same, true );
tkv_check( 'the edited version won, not the original', file_get_contents( $sandbox . '/one.txt' ), 'edited one' );

/* ================================================================= */
tkv_section( '2. restoring the full backup alone gives the earlier state' );

$snap = tkv_restore( tkv_backup_id( $full ) );
tkv_check( 'completed', $snap['status'], 'complete' );
tkv_check( 'the original content is back', file_get_contents( $sandbox . '/one.txt' ), 'original one' );
tkv_check( 'a file added after it is left alone', file_exists( $sandbox . '/keep/four.txt' ), true );

/* ================================================================= */
tkv_section( '3. the replaced files can be put back' );

tkv_check( 'something was displaced', TKVault_File_Restore::replaced_count() > 0, true );
$undo = TKVault_File_Restore::undo();
tkv_check( 'undo succeeded', $undo, true );
tkv_check( 'the newer content is back', file_get_contents( $sandbox . '/one.txt' ), 'edited one' );
tkv_check( 'the record is cleared', TKVault_File_Restore::replaced_count(), 0 );

/* ================================================================= */
tkv_section( '4. a damaged archive is refused' );

$manifest = json_decode( file_get_contents( trailingslashit( $store ) . $full . '_manifest.json' ), true );
$archive  = trailingslashit( $store ) . $manifest['volumes'][0]['name'];
$intact   = file_get_contents( $archive );

file_put_contents( $archive, $intact . 'corruption' );

$before = file_get_contents( $sandbox . '/one.txt' );
$snap   = tkv_restore( tkv_backup_id( $full ) );
tkv_check( 'refused', $snap['status'], 'failed' );
tkv_check( 'says the checksum does not match', false !== strpos( $snap['message'], 'checksum recorded' ), true );
tkv_check( 'nothing was written', file_get_contents( $sandbox . '/one.txt' ), $before );

file_put_contents( $archive, $intact );

/* ----------------------------------------------------------------- */
tkv_section( '5. a tampered manifest is refused' );

$path   = trailingslashit( $store ) . $full . '_manifest.json';
$intact = file_get_contents( $path );
$edited = json_decode( $intact, true );
$edited['counts']['archived'] = 9999;          // self hash left alone
file_put_contents( $path, wp_json_encode( $edited ) );

$snap = tkv_restore( tkv_backup_id( $full ) );
tkv_check( 'refused', $snap['status'], 'failed' );
tkv_check( 'names the checksum', false !== strpos( $snap['message'], 'own checksum' ), true );

file_put_contents( $path, $intact );

/* ----------------------------------------------------------------- */
tkv_section( '6. a broken chain is refused' );

$parent = trailingslashit( $store ) . $full . '_manifest.json';
$saved  = file_get_contents( $parent );
unlink( $parent );

$snap = tkv_restore( tkv_backup_id( $incremental ) );
tkv_check( 'refused', $snap['status'], 'failed' );
tkv_check( 'explains the chain is incomplete', false !== strpos( $snap['message'], 'chain' ), true );

file_put_contents( $parent, $saved );

/* ================================================================= */
tkv_section( '7. path traversal entries are refused' );

$safe = new ReflectionMethod( 'TKVault_File_Restore', 'is_safe_entry' );

tkv_check( 'a normal path', TKVault_File_Restore::is_safe_entry( 'themes/x/style.css' ), true );
tkv_check( 'parent traversal', TKVault_File_Restore::is_safe_entry( '../../wp-config.php' ), false );
tkv_check( 'traversal in the middle', TKVault_File_Restore::is_safe_entry( 'themes/../../wp-config.php' ), false );
tkv_check( 'absolute path', TKVault_File_Restore::is_safe_entry( '/etc/passwd' ), false );
tkv_check( 'windows absolute path', TKVault_File_Restore::is_safe_entry( 'C:\\windows\\system32' ), false );
tkv_check( 'backslash traversal', TKVault_File_Restore::is_safe_entry( '..\\..\\wp-config.php' ), false );
tkv_check( 'empty', TKVault_File_Restore::is_safe_entry( '' ), false );

// And end to end: an archive that actually contains one.
$evil = trailingslashit( $store ) . 'tkv-evil.zip';
@unlink( $evil );
$zip = new ZipArchive();
$zip->open( $evil, ZipArchive::CREATE );
$zip->addFromString( 'tkv-test-tree/harmless.txt', 'fine' );
$zip->addFromString( '../../tkv-escaped.txt', 'should never be written' );
$zip->close();

$evil_manifest = array(
	'format'   => 1,
	'plugin'   => 'takumi-vault',
	'kind'     => 'files',
	'mode'     => 'full',
	'parent'   => null,
	'archiver' => 'zip',
	'counts'   => array( 'present' => 2, 'archived' => 2, 'unchanged' => 0, 'deleted' => 0, 'bytes' => 0 ),
	'volumes'  => array(
		array(
			'name'   => 'tkv-evil.zip',
			'size'   => filesize( $evil ),
			'sha256' => hash_file( 'sha256', $evil ),
		),
	),
	'deleted'  => array(),
	'files'    => array(),
);
$evil_manifest['self_sha256'] = hash( 'sha256', wp_json_encode( $evil_manifest ) );
file_put_contents( trailingslashit( $store ) . 'tkv-evil_manifest.json', wp_json_encode( $evil_manifest ) );

$wpdb->insert(
	$wpdb->prefix . 'tkvault_backups',
	array( 'filename' => 'tkv-evil.zip', 'type' => 'files', 'size' => 1, 'status' => 'completed', 'note' => 'evil' )
);
$evil_id = (int) $wpdb->insert_id;

$escaped = dirname( ABSPATH ) . '/tkv-escaped.txt';
@unlink( $escaped );
@unlink( ABSPATH . 'tkv-escaped.txt' );

$snap = tkv_restore( $evil_id );
tkv_check( 'the restore completed', $snap['status'], 'complete' );
tkv_check( 'the harmless file was written', file_exists( $sandbox . '/harmless.txt' ), true );
tkv_check( 'the traversal entry was skipped', (int) $snap['state']['skipped'] >= 1, true );
tkv_check( 'nothing escaped above wp-content', file_exists( $escaped ) || file_exists( ABSPATH . 'tkv-escaped.txt' ), false );

@unlink( $evil );
@unlink( trailingslashit( $store ) . 'tkv-evil_manifest.json' );

/* ================================================================= */
tkv_section( "8. the plugin never restores over itself" );

$own = wp_normalize_path( untrailingslashit( TKVAULT_PLUGIN_DIR ) );
tkv_check( 'its own directory', TKVault_File_Restore::is_own_file( $own ), true );
tkv_check( 'a file inside it', TKVault_File_Restore::is_own_file( $own . '/includes/class-tkvault-admin.php' ), true );
tkv_check( 'a neighbour with a similar name', TKVault_File_Restore::is_own_file( $own . '-other/file.php' ), false );
tkv_check( 'somewhere else entirely', TKVault_File_Restore::is_own_file( WP_CONTENT_DIR . '/themes/x/style.css' ), false );

$before = md5_file( TKVAULT_PLUGIN_DIR . 'takumi-vault.php' );

// An archive claiming to hold the plugin's own main file.
$sneaky = trailingslashit( $store ) . 'tkv-sneaky.zip';
@unlink( $sneaky );
$relative = ltrim( substr( $own, strlen( $root ) ), '/' ) . '/takumi-vault.php';
$zip      = new ZipArchive();
$zip->open( $sneaky, ZipArchive::CREATE );
$zip->addFromString( $relative, '<?php /* replaced */' );
$zip->close();

$sneaky_manifest = $evil_manifest;
$sneaky_manifest['volumes'] = array(
	array( 'name' => 'tkv-sneaky.zip', 'size' => filesize( $sneaky ), 'sha256' => hash_file( 'sha256', $sneaky ) ),
);
unset( $sneaky_manifest['self_sha256'] );
$sneaky_manifest['self_sha256'] = hash( 'sha256', wp_json_encode( $sneaky_manifest ) );
file_put_contents( trailingslashit( $store ) . 'tkv-sneaky_manifest.json', wp_json_encode( $sneaky_manifest ) );

$wpdb->insert(
	$wpdb->prefix . 'tkvault_backups',
	array( 'filename' => 'tkv-sneaky.zip', 'type' => 'files', 'size' => 1, 'status' => 'completed', 'note' => 'sneaky' )
);

$snap = tkv_restore( (int) $wpdb->insert_id );
tkv_check( 'the restore completed', $snap['status'], 'complete' );
tkv_check( "the plugin's own file is untouched", md5_file( TKVAULT_PLUGIN_DIR . 'takumi-vault.php' ), $before );
tkv_check( 'it was counted as skipped', (int) $snap['state']['skipped'] >= 1, true );

@unlink( $sneaky );
@unlink( trailingslashit( $store ) . 'tkv-sneaky_manifest.json' );

/* ================================================================= */
tkv_section( '9. chunk boundaries' );

tkv_rmtree( $sandbox );
mkdir( $sandbox . '/bulk', 0755, true );
for ( $i = 1; $i <= 30; $i++ ) {
	file_put_contents( $sandbox . '/bulk/file-' . $i . '.txt', "bulk {$i}" );
}

delete_option( TKVault_File_Backup::OPTION_LAST_MANIFEST );
$bulk = tkv_backup( 'bulk', true );
tkv_rmtree( $sandbox );

add_filter( 'tkvault_restore_file_batch', function () { return 3; }, 99 );
tkv_single_chunk();

$snap = tkv_restore( tkv_backup_id( $bulk ) );

tkv_normal_chunking();
remove_all_filters( 'tkvault_restore_file_batch' );

tkv_check( 'completed', $snap['status'], 'complete' );
tkv_info( sprintf( 'requests=%d restored=%d', $snap['requests'], $snap['state']['restored'] ) );

$found = 0;
for ( $i = 1; $i <= 30; $i++ ) {
	if ( file_exists( $sandbox . '/bulk/file-' . $i . '.txt' )
		&& file_get_contents( $sandbox . '/bulk/file-' . $i . '.txt' ) === "bulk {$i}" ) {
		$found++;
	}
}
tkv_check( 'every file restored exactly once', $found, 30 );
tkv_check( 'the count matches', (int) $snap['state']['restored'], 30 );

/* ================================================================= */
tkv_section( '10. a stale undo record is discarded, not acted on' );

// undo() deletes its staging directory recursively. The path comes out of an
// option, and a database restore can roll wp_options back to an older row -
// which is how a stale tkvault_old_tables once got through. Point the record
// at a directory that is not ours and confirm nothing is touched.
$decoy = $sandbox . '/decoy';
mkdir( $decoy, 0755, true );
file_put_contents( $decoy . '/precious.txt', 'must survive' );

update_option(
	TKVault_File_Restore::OPTION_REPLACED,
	array( 'dir' => $decoy, 'count' => 1, 'base' => 'forged' )
);

$undo = TKVault_File_Restore::undo();
tkv_check( 'undo refuses', is_wp_error( $undo ), true );
tkv_check( 'says the record does not point at the backup folder', is_wp_error( $undo ) ? $undo->get_error_code() : '', 'tkvault_undo_stale' );
tkv_check( 'the decoy directory is untouched', is_dir( $decoy ), true );
tkv_check( 'the file inside it survived', file_get_contents( $decoy . '/precious.txt' ), 'must survive' );
tkv_check( 'the bad record was cleared', get_option( TKVault_File_Restore::OPTION_REPLACED ), false );

// The same path handed straight to the private remover must also be refused.
$reflect = new ReflectionMethod( 'TKVault_File_Restore', 'remove_tree' );
$reflect->setAccessible( true );
$reflect->invoke( null, $decoy );
tkv_check( 'remove_tree refuses a directory outside the store', is_dir( $decoy ), true );

// And a real staging directory must still be removable, or undo would leak.
$real = trailingslashit( $store ) . 'tkv-probe_' . TKVault_File_Restore::REPLACED_DIR;
mkdir( $real . '/nested', 0755, true );
file_put_contents( $real . '/nested/x.txt', 'x' );
$reflect->invoke( null, $real );
tkv_check( 'a genuine staging directory is still removed', is_dir( $real ), false );

/* ================================================================= */
tkv_section( 'cleanup' );

tkv_rmtree( $sandbox );
@unlink( dirname( ABSPATH ) . '/tkv-escaped.txt' );
@unlink( ABSPATH . 'tkv-escaped.txt' );
foreach ( (array) glob( trailingslashit( $store ) . '*' ) as $path ) {
	if ( is_file( $path ) ) {
		@unlink( $path );
	} else {
		tkv_rmtree( $path );
	}
}
$wpdb->query( 'DELETE FROM ' . TKVault_Jobs::table() );
$wpdb->query( 'DELETE FROM ' . $wpdb->prefix . 'tkvault_backups' );
delete_option( TKVault_File_Backup::OPTION_LAST_MANIFEST );
delete_option( TKVault_File_Restore::OPTION_REPLACED );
remove_all_filters( 'tkvault_file_exclusions' );
tkv_unblock_loopback();
echo "  done\n";

tkv_report();
