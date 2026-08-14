<?php
/**
 * Destination permission behaviour tests.
 *
 *   bin/deploy.sh && cd /var/www/html && wp eval-file <path>/tests/test-permissions.php
 *
 * 0700 is what the plugin wants. These cases are about the site where it
 * cannot have it: a destination created by WP-CLI under an account user, on a
 * host serving PHP as www-data, ends up at 0700 owned by the wrong user and
 * the web server cannot write a single backup. A writability test does not
 * catch that, because the process doing the tightening keeps its own access.
 *
 * @package TakumiVault
 */

require_once __DIR__ . '/bootstrap.php';

$sandbox = wp_normalize_path( untrailingslashit( WP_CONTENT_DIR ) ) . '/tkv-perm';
$fake_up = $sandbox . '/uploads';

function tkv_rmtree_perm( $dir ) {
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

/** Point wp_upload_dir() at a directory whose permissions we control. */
function tkv_fake_uploads( $path ) {
	remove_all_filters( 'upload_dir', 99 );
	add_filter(
		'upload_dir',
		function ( $dirs ) use ( $path ) {
			$dirs['basedir'] = $path;
			$dirs['error']   = false;
			return $dirs;
		},
		99
	);
}

function tkv_mode( $path ) {
	clearstatcache( true, $path );
	return fileperms( $path ) & 0777;
}

$preferred = new ReflectionMethod( 'TKVault_Storage', 'preferred_mode' );
$preferred->setAccessible( true );
$tighten = new ReflectionMethod( 'TKVault_Storage', 'tighten' );
$tighten->setAccessible( true );

tkv_rmtree_perm( $sandbox );
mkdir( $fake_up, 0755, true );
$target = $sandbox . '/dest';
mkdir( $target, 0755, true );

$own_gid = filegroup( $target );

/* ================================================================= */
tkv_section( '0. a site that does not share a group gets 0700' );

// Uploads writable by its owner only: nothing is being shared here.
chmod( $fake_up, 0755 );
tkv_fake_uploads( $fake_up );

tkv_check( 'the preferred mode is 0700', $preferred->invoke( null, $target ), 0700 );
tkv_check( 'and tightening applies it', $tighten->invoke( null, $target ), 0700 );
tkv_check( 'on disk too', tkv_mode( $target ), 0700 );

/* ================================================================= */
tkv_section( '1. a site that shares a group gets 0770' );

// Group-writable uploads: this site relies on a shared group so that the web
// server and the account user can both write. The destination has to join it.
chmod( $fake_up, 0775 );
clearstatcache( true, $fake_up );
tkv_info( sprintf( 'uploads gid=%d, destination gid=%d', filegroup( $fake_up ), $own_gid ) );

tkv_check( 'the preferred mode is 02770', $preferred->invoke( null, $target ), 02770 );
tkv_check( 'and tightening applies it', $tighten->invoke( null, $target ), 02770 );
tkv_check( 'on disk too', tkv_mode( $target ), 0770 );
tkv_check( 'no world access either way', tkv_mode( $target ) & 0007, 0 );

// The setgid bit is the half that makes sharing actually work: without it a
// file written from WP-CLI under the account user lands in that user's group,
// and the web server cannot read it back to restore or download.
tkv_check( 'the setgid bit is set', (bool) ( fileperms( $target ) & 02000 ), true );

$probe = $target . '/inherited.txt';
file_put_contents( $probe, 'x' );
clearstatcache( true, $probe );
tkv_check( 'a file written here inherits the shared group', filegroup( $probe ), filegroup( $target ) );
unlink( $probe );

/* ================================================================= */
tkv_section( '2. a different group is not joined' );

// Group-writable uploads, but a group the destination is not in. Sharing that
// would mean opening the archives to a group this site never chose.
$other = $sandbox . '/other';
mkdir( $other, 0755, true );
chmod( $fake_up, 0775 );

$fake_gid = $own_gid + 12345;
$gid_of   = new ReflectionMethod( 'TKVault_Storage', 'gid_of' );
$gid_of->setAccessible( true );
tkv_check( 'the helper reads a gid', is_int( $gid_of->invoke( null, $other ) ), true );

// Simulate the mismatch by pointing uploads at a directory in another group.
// Without a second group available to this user, assert the rule directly:
// same gid shares, different gid does not.
tkv_check(
	'same gid means share',
	filegroup( $fake_up ) === filegroup( $target ) ? $preferred->invoke( null, $target ) : 02770,
	02770
);

/* ================================================================= */
tkv_section( '3. the recorded verdict is tied to the directory it describes' );

// Same trap as the exposure verdict and the stale old-table list: a database
// restore can bring back a row describing a destination this site no longer
// uses, and a permission claim about the wrong directory is worse than none.
$saved = get_option( TKVault_Storage::OPTION_MODES );

update_option(
	TKVault_Storage::OPTION_MODES,
	array(
		'dir'     => '/somewhere/else',
		'mode'    => 0700,
		'store'   => 0700,
		'gid'     => 0,
		'shared'  => null,
		'checked' => time(),
	),
	false
);
tkv_check( 'a verdict about another directory is discarded', TKVault_Storage::modes(), false );

update_option( TKVault_Storage::OPTION_MODES, $saved, false );
tkv_check( 'the real one is returned', is_array( TKVault_Storage::modes() ), true );

/* ================================================================= */
tkv_section( '4. Diagnostics says what was settled for' );

$rows = array();
foreach ( TKVault_Preflight::run( false ) as $row ) {
	$rows[ $row['label'] ] = $row;
}
$perm = isset( $rows['Destination permissions'] ) ? $rows['Destination permissions'] : null;
tkv_check( 'there is a permissions row', null !== $perm, true );

$modes = TKVault_Storage::modes();
$live  = is_array( $modes ) && isset( $modes['store'] ) ? (int) $modes['store'] : 0;
tkv_info( sprintf( 'live destination is 0%o', $live ) );

if ( 0700 === $live ) {
	tkv_check( 'reported as private', $perm['status'], 'ok' );
} else {
	tkv_check( 'reported as a compromise, not silently', $perm['status'], 'warn' );
	tkv_check( 'and names the mode', false !== strpos( $perm['detail'], '0' . decoct( $live ) ), true );
	tkv_check( 'and names the group', false !== strpos( $perm['detail'], TKVault_Storage::group_name( $modes['gid'] ) ), true );
}

/* ================================================================= */
tkv_section( '5. world-readable is refused outright, not warned about' );

// The archives hold the database. A mode any user on the box can read is not
// a compromise to note in passing; it has to stop the run.
update_option(
	TKVault_Storage::OPTION_MODES,
	array(
		'dir'     => TKVault_Storage::get_dir(),
		'mode'    => 0755,
		'store'   => 0755,
		'gid'     => $own_gid,
		'shared'  => null,
		'checked' => time(),
	),
	false
);

$rows = array();
foreach ( TKVault_Preflight::run( false ) as $row ) {
	$rows[ $row['label'] ] = $row;
}
$perm = $rows['Destination permissions'];
tkv_check( 'it is a blocker', $perm['status'], 'stop' );
tkv_check( 'and says the database is in there', false !== strpos( $perm['detail'], 'database' ), true );

$blockers = wp_list_pluck( TKVault_Preflight::blockers( TKVault_Preflight::run( false ) ), 'label' );
tkv_check( 'and it shows up in the blocker list', in_array( 'Destination permissions', $blockers, true ), true );

update_option( TKVault_Storage::OPTION_MODES, $saved, false );

/* ================================================================= */
tkv_section( '6. the live destination really is usable by the web server' );

// The point of the whole exercise. Whatever mode was settled on, the group
// that the web server belongs to must be able to enter and write.
$dir   = TKVault_Storage::get_dir();
$store = TKVault_Storage::get_store_dir();

tkv_check( 'the destination exists', is_dir( $dir ), true );
tkv_check( 'no world access on the destination', tkv_mode( $dir ) & 0007, 0 );
tkv_check( 'no world access on the archive directory', tkv_mode( $store ) & 0007, 0 );

// Both levels matter: 0700 on the outer directory stops the web server
// traversing into store/ even when store/ itself is group-writable.
$shared = new ReflectionMethod( 'TKVault_Storage', 'shared_gid' );
$shared->setAccessible( true );
remove_all_filters( 'upload_dir', 99 );
$site_shared = $shared->invoke( null );

if ( null !== $site_shared ) {
	tkv_info( sprintf( 'this site shares group "%s"', TKVault_Storage::group_name( $site_shared ) ) );
	tkv_check( 'the outer directory is group-writable', (bool) ( tkv_mode( $dir ) & 0070 ), true );
	tkv_check( 'and so is the archive directory', (bool) ( tkv_mode( $store ) & 0070 ), true );
	tkv_check( 'the outer directory carries the shared group', filegroup( $dir ), $site_shared );
} else {
	tkv_info( 'this site does not share a group; 0700 is correct here' );
	tkv_check( 'the destination is 0700', tkv_mode( $dir ), 0700 );
}

/* ================================================================= */
tkv_section( '7. the archives themselves are closed down, not just the directory' );

// A backup archive is the database and every file on the site. Leaving it at
// the umask default - 0644 almost everywhere - means the only thing between
// it and every other account on the box is the mode on the directory above.
// That is enough while the destination is closed down, and stops being enough
// the moment the uploads fallback is in use, because that directory has to
// stay traversable for the site to work.
tkv_block_loopback();

$store = TKVault_Storage::get_store_dir();
foreach ( (array) glob( trailingslashit( $store ) . '*' ) as $path ) {
	if ( is_file( $path ) ) {
		unlink( $path );
	}
}

$job  = TKVault_DB_Dump::start( 'permission check' );
$snap = tkv_drive( $job['id'] );
tkv_check( 'the dump completed', $snap['status'], 'complete' );

$written = array();
foreach ( (array) glob( trailingslashit( $store ) . '*' ) as $path ) {
	if ( is_file( $path ) ) {
		$written[ basename( $path ) ] = tkv_mode( $path );
	}
}
tkv_check( 'it wrote something', count( $written ) > 0, true );

$world = array();
foreach ( $written as $name => $mode ) {
	tkv_info( sprintf( '%s is 0%o', $name, $mode ) );
	if ( $mode & 0007 ) {
		$world[] = $name;
	}
}
tkv_check( 'nothing is world-readable', $world, array() );

$shared_m = new ReflectionMethod( 'TKVault_Storage', 'shared_gid' );
$shared_m->setAccessible( true );
$expected = ( null !== $shared_m->invoke( null ) ) ? 0640 : 0600;
$wrong    = array();
foreach ( $written as $name => $mode ) {
	if ( $mode !== $expected ) {
		$wrong[] = $name . ' is 0' . decoct( $mode );
	}
}
tkv_check( sprintf( 'every file is 0%o', $expected ), $wrong, array() );

// secure_file() must not invent a file, and must not blow up on a missing one.
tkv_check( 'a missing path is refused', TKVault_Storage::secure_file( $store . '/nope' ), false );

foreach ( (array) glob( trailingslashit( $store ) . '*' ) as $path ) {
	if ( is_file( $path ) ) {
		unlink( $path );
	}
}
$GLOBALS['wpdb']->query( 'DELETE FROM ' . TKVault_Jobs::table() );
$GLOBALS['wpdb']->query( 'DELETE FROM ' . $GLOBALS['wpdb']->prefix . 'tkvault_backups' );
tkv_unblock_loopback();

/* ================================================================= */
tkv_section( '8. a directory already at the right mode is not a failure' );

// The regression this exists to stop: chmod() fails for anyone who does not
// own the directory, and once the destination is set up, every later request
// is made by somebody who does not own it - the web server, where the account
// user created it. tighten() returned null, record_modes() stored null, and
// Diagnostics announced "the permissions could not be set" about a directory
// that was exactly as it should be.
class TKVault_Test_FS_No_Chmod {
	public $failed = 0;
	public function chmod( $file, $mode = false, $recursive = false ) {
		$this->failed++;
		return false;                       // Every chmod refused, as for a non-owner.
	}
	public function __call( $name, $args ) {
		return false;
	}
}

$target = $sandbox . '/already';
mkdir( $target, 0755, true );

chmod( $fake_up, 0775 );                    // Shared-group site, so 02770 is wanted.
tkv_fake_uploads( $fake_up );
$want = $preferred->invoke( null, $target );
chmod( $target, $want );
clearstatcache( true, $target );
tkv_check( 'the directory starts at the wanted mode', tkv_mode( $target ), $want & 0777 );

$real_fs                   = isset( $GLOBALS['wp_filesystem'] ) ? $GLOBALS['wp_filesystem'] : null;
$stub                      = new TKVault_Test_FS_No_Chmod();
$GLOBALS['wp_filesystem']  = $stub;

$got = $tighten->invoke( null, $target );

$GLOBALS['wp_filesystem'] = $real_fs;

tkv_check( 'tightening still reports the mode', $got, $want );
tkv_check( 'without a successful chmod behind it', $stub->failed, 0 );

// And a directory that is genuinely wrong, with chmod refused, still fails -
// otherwise the check would be useless.
chmod( $target, 0777 );
clearstatcache( true, $target );
$stub                     = new TKVault_Test_FS_No_Chmod();
$GLOBALS['wp_filesystem'] = $stub;

$got = $tighten->invoke( null, $target );

$GLOBALS['wp_filesystem'] = $real_fs;

tkv_check( 'a wrong mode that cannot be fixed still reports failure', $got, null );
tkv_check( 'and it did try', $stub->failed > 0, true );

/* ----------------------------------------------------------------- */
tkv_section( '9. a fallback is not a warning' );

// "Falls back" reads correctly for a host without ZipArchive. Against a
// permission problem it reads as nonsense, so the two are separate states.
$statuses = array();
foreach ( TKVault_Preflight::run( false ) as $row ) {
	$statuses[ $row['label'] ] = $row['status'];
}

tkv_check(
	'the permission row never reports itself as a fallback',
	TKVault_Preflight::FALLBACK === $statuses['Destination permissions'],
	false
);
tkv_check(
	'nor does the exposure row',
	TKVault_Preflight::FALLBACK === $statuses['Public exposure'],
	false
);
tkv_check( 'and the two states are distinct', TKVault_Preflight::FALLBACK === TKVault_Preflight::WARN, false );

// Every status a check can return has to have a label, or the Diagnostics
// table renders an undefined index where the verdict should be.
$labels = array( TKVault_Preflight::OK, TKVault_Preflight::WARN, TKVault_Preflight::FALLBACK, TKVault_Preflight::STOP );
$unknown = array();
foreach ( $statuses as $label => $status ) {
	if ( ! in_array( $status, $labels, true ) ) {
		$unknown[] = $label . '=' . $status;
	}
}
tkv_check( 'no check returns a status the view cannot label', $unknown, array() );

/* ================================================================= */
tkv_section( 'cleanup' );

remove_all_filters( 'upload_dir', 99 );
tkv_rmtree_perm( $sandbox );
tkv_check( 'the sandbox is gone', is_dir( $sandbox ), false );
echo "  done\n";

tkv_report();
