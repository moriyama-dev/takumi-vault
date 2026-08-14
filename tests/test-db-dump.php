<?php
/**
 * Database dump behaviour tests.
 *
 *   bin/deploy.sh && cd /var/www/html && wp eval-file <path>/tests/test-db-dump.php
 *
 * The expensive bug this file exists to catch is a dump that looks fine and is
 * missing rows, so most of it is about counting things twice and comparing
 * against a tool that already works.
 *
 * @package TakumiVault
 */

require_once __DIR__ . '/bootstrap.php';

global $wpdb;

tkv_block_loopback();

$fixtures = array( 'wp_tkv_int', 'wp_tkv_nopk', 'wp_tkv_composite', 'wp_tkv_varchar', 'wp_tkv_nasty', 'wp_tkv_chunk' );

/* ================================================================= */
tkv_section( '0. fixtures: every primary key shape that exists in the wild' );

$wpdb->query( 'DROP TABLE IF EXISTS ' . implode( ', ', $fixtures ) );

$wpdb->query( 'CREATE TABLE wp_tkv_int (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, v VARCHAR(64)) DEFAULT CHARSET=utf8mb4' );
$wpdb->query( 'CREATE TABLE wp_tkv_nopk (a INT, b VARCHAR(64)) DEFAULT CHARSET=utf8mb4' );
$wpdb->query( 'CREATE TABLE wp_tkv_composite (a INT, b INT, v VARCHAR(64), PRIMARY KEY (a,b)) DEFAULT CHARSET=utf8mb4' );
$wpdb->query( 'CREATE TABLE wp_tkv_varchar (slug VARCHAR(64) PRIMARY KEY, v TEXT) DEFAULT CHARSET=utf8mb4' );
$wpdb->query( 'CREATE TABLE wp_tkv_nasty (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, txt LONGTEXT, bin BLOB, maybe VARCHAR(32) NULL) DEFAULT CHARSET=utf8mb4' );
$wpdb->query( 'CREATE TABLE wp_tkv_chunk (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, n INT) DEFAULT CHARSET=utf8mb4' );

for ( $i = 1; $i <= 20; $i++ ) {
	$wpdb->query( $wpdb->prepare( 'INSERT INTO wp_tkv_int (v) VALUES (%s)', "row {$i}" ) );
	$wpdb->query( $wpdb->prepare( 'INSERT INTO wp_tkv_nopk (a,b) VALUES (%d,%s)', $i, "nopk {$i}" ) );
	$wpdb->query( $wpdb->prepare( 'INSERT INTO wp_tkv_composite (a,b,v) VALUES (%d,%d,%s)', $i % 5, $i, "comp {$i}" ) );
	$wpdb->query( $wpdb->prepare( 'INSERT INTO wp_tkv_varchar (slug,v) VALUES (%s,%s)', sprintf( 'slug-%03d', $i ), "varchar {$i}" ) );
}

// Two rows nothing can tell apart. A keyless table still has to emit both.
$wpdb->query( "INSERT INTO wp_tkv_nopk (a,b) VALUES (999,'duplicate'), (999,'duplicate')" );

$nasty = array(
	"quote ' and double \" quote",
	'backslash \\ and \\\' escaped quote',
	"nul\0byte inside",
	'emoji and CJK: 🛡️ 日本語 ñ',
	'four byte: 𝔘𝔫𝔦𝔠𝔬𝔡𝔢 𝓶𝓪𝓽𝓱',
	str_repeat( 'long text block. ', 5000 ),
	"line\nbreak; and semicolon; inside",
	'%s %d %% percent signs',
);
foreach ( $nasty as $i => $value ) {
	$wpdb->query(
		$wpdb->prepare(
			'INSERT INTO wp_tkv_nasty (txt, bin, maybe) VALUES (%s, %s, ' . ( 0 === $i % 3 ? 'NULL' : '%s' ) . ')',
			...( 0 === $i % 3
				? array( $value, random_bytes( 64 ) )
				: array( $value, random_bytes( 64 ), 'set' ) )
		)
	);
}

// 1234 is deliberately not a multiple of the page size.
$rows = array();
for ( $i = 1; $i <= 1234; $i++ ) {
	$rows[] = "({$i})";
}
$wpdb->query( 'INSERT INTO wp_tkv_chunk (n) VALUES ' . implode( ',', $rows ) );

foreach ( $fixtures as $table ) {
	tkv_info( sprintf( '%-20s %d rows', $table, (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" ) ) );
}

/* ================================================================= */
tkv_section( '1. pagination strategy per primary key shape' );

$expected = array(
	'wp_tkv_int'       => 'keyset',
	'wp_tkv_chunk'     => 'keyset',
	'wp_tkv_nopk'      => 'offset',
	'wp_tkv_composite' => 'offset',
	'wp_tkv_varchar'   => 'offset',
);
foreach ( $expected as $table => $want ) {
	$plan = TKVault_DB_Dump::plan_pagination( $table );
	tkv_check( $table, $plan['strategy'], $want );
}

/* ================================================================= */
tkv_section( '2. the dump completes and is well formed' );

$job  = TKVault_DB_Dump::start( 'fixture dump' );
$dump = tkv_drive( $job['id'] );
tkv_check( 'completed', $dump['status'], 'complete' );

$file = $dump['state']['file'];
tkv_check( 'archive exists', file_exists( $file ), true );

$sql = tkv_gz_read_all( $file );
tkv_check( 'end marker present', false !== strpos( $sql, TKVault_DB_Dump::END_MARKER ), true );
tkv_info(
	sprintf(
		'requests=%d rows=%d size=%d bytes members=%d',
		$dump['requests'],
		$dump['processed'],
		filesize( $file ),
		substr_count( file_get_contents( $file ), "\x1f\x8b\x08" )
	)
);

/* ================================================================= */
tkv_section( '3. gzdecode() silently truncates; gzopen() does not' );

$partial = gzdecode( file_get_contents( $file ) );
tkv_check( 'gzopen reads more than gzdecode', strlen( $sql ) > strlen( (string) $partial ), true );
tkv_check( 'gzdecode misses the end marker', false === strpos( (string) $partial, TKVault_DB_Dump::END_MARKER ), true );
tkv_info( sprintf( 'gzopen=%d bytes, gzdecode=%d bytes (%.1f%%)', strlen( $sql ), strlen( (string) $partial ), 100 * strlen( (string) $partial ) / strlen( $sql ) ) );

/* ================================================================= */
tkv_section( '4. the manifest' );

$manifest_file = dirname( $file ) . '/' . $dump['state']['base'] . '_manifest.json';
tkv_check( 'manifest exists', file_exists( $manifest_file ), true );

$manifest = json_decode( file_get_contents( $manifest_file ), true );
tkv_check( 'records the archive hash', $manifest['files'][0]['sha256'], hash_file( 'sha256', $file ) );

$claimed = $manifest['self_sha256'];
$copy    = $manifest;
unset( $copy['self_sha256'] );
tkv_check( 'signs itself', hash( 'sha256', wp_json_encode( $copy ) ), $claimed );
tkv_check( 'records the scope', $manifest['scope'], 'site_prefix' );

$by_name = array();
foreach ( $manifest['tables'] as $entry ) {
	$by_name[ $entry['name'] ] = $entry;
}
foreach ( $fixtures as $table ) {
	tkv_check( "counts {$table}", $by_name[ $table ]['rows_written'], (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" ) );
}

/* ================================================================= */
tkv_section( '5. chunk boundaries: no duplicates, no gaps' );

tkv_check( 'integers written unquoted, like mysqldump', false !== strpos( $sql, 'VALUES (1,1),' ), true );

$ids = array();
foreach ( explode( "\n", $sql ) as $line ) {
	if ( 0 !== strpos( $line, 'INSERT INTO `wp_tkv_chunk` VALUES ' ) ) {
		continue;
	}
	preg_match_all( '/\((\d+),(\d+)\)/', $line, $pairs, PREG_SET_ORDER );
	foreach ( $pairs as $pair ) {
		$ids[] = (int) $pair[1];
	}
}
tkv_check( 'row count', count( $ids ), 1234 );
tkv_check( 'no duplicates', count( array_unique( $ids ) ), 1234 );
tkv_check( 'no gaps', $ids ? min( $ids ) . '-' . max( $ids ) : 'empty', '1-1234' );

/* ================================================================= */
tkv_section( '6. round trip: replay into a parallel prefix and compare' );

$prefix = 'tkvrt_';
foreach ( $fixtures as $table ) {
	$wpdb->query( "DROP TABLE IF EXISTS {$prefix}{$table}" );
}

// The plugin's own parser does the splitting; a hand-rolled one in the test
// would be a second implementation to keep correct.
$reader  = new TKVault_SQL_Reader( $file );
$applied = 0;
$failed  = 0;

while ( null !== ( $statement = $reader->next_statement() ) ) {
	if ( '' === $statement ) {
		continue;
	}

	$rewritten = $statement;
	foreach ( $fixtures as $table ) {
		$rewritten = str_replace( "`{$table}`", "`{$prefix}{$table}`", $rewritten );
	}
	if ( false === strpos( $rewritten, $prefix ) ) {
		continue; // Not one of the fixture tables.
	}

	$wpdb->suppress_errors( true );
	if ( false === $wpdb->query( $rewritten ) ) {
		$failed++;
		if ( $failed <= 2 ) {
			tkv_info( 'failed: ' . substr( $wpdb->last_error, 0, 80 ) );
		}
	} else {
		$applied++;
	}
	$wpdb->suppress_errors( false );
}
TKVault_SQL_Reader::close_cached();

tkv_check( 'every statement applied', $failed, 0 );
tkv_info( sprintf( 'applied=%d', $applied ) );

foreach ( $fixtures as $table ) {
	tkv_check(
		"{$table} row count survives",
		(int) $wpdb->get_var( "SELECT COUNT(*) FROM {$prefix}{$table}" ),
		(int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" )
	);
}

/* ================================================================= */
tkv_section( '7. awkward values survive byte for byte' );

$original = $wpdb->get_results( 'SELECT id, txt, bin, maybe FROM wp_tkv_nasty ORDER BY id', ARRAY_A );
$restored = $wpdb->get_results( "SELECT id, txt, bin, maybe FROM {$prefix}wp_tkv_nasty ORDER BY id", ARRAY_A );
tkv_check( 'same number of rows', count( $restored ), count( $original ) );

$labels = array( 'single quote', 'backslash', 'NUL byte', 'emoji + CJK', '4-byte UTF-8', 'long TEXT', 'newline + semicolon', 'percent signs' );
foreach ( $original as $i => $row ) {
	$same = isset( $restored[ $i ] )
		&& $restored[ $i ]['txt'] === $row['txt']
		&& $restored[ $i ]['bin'] === $row['bin']
		&& $restored[ $i ]['maybe'] === $row['maybe'];

	tkv_check( $labels[ $i ] . ( null === $row['maybe'] ? ' (+NULL)' : '' ) . ' (+BLOB)', $same, true );
}

/* ================================================================= */
tkv_section( '8. cross-check against mysqldump' );

$ours = array();
foreach ( explode( "\n", $sql ) as $line ) {
	if ( preg_match( '/^CREATE TABLE `([^`]+)`/', $line, $hit ) ) {
		$ours[] = $hit[1];
	}
}
sort( $ours );

$in_scope = TKVault_DB_Dump::resolve_tables( false );
sort( $in_scope );
tkv_check( 'dumped every table in scope', $ours === $in_scope, true );

$reference = '/tmp/tkv-cross.sql';
exec( 'cd ' . escapeshellarg( ABSPATH ) . ' && wp db export ' . escapeshellarg( $reference ) . ' --tables=' . escapeshellarg( implode( ',', $in_scope ) ) . ' 2>&1', $out, $code );
tkv_check( 'mysqldump produced a file', 0 === $code && file_exists( $reference ), true );

$theirs = array();
foreach ( file( $reference ) as $line ) {
	if ( preg_match( '/^CREATE TABLE `([^`]+)`/', $line, $hit ) ) {
		$theirs[] = $hit[1];
	}
}
sort( $theirs );
tkv_check( 'same table set as mysqldump', $ours === $theirs, true );

foreach ( $fixtures as $table ) {
	tkv_check(
		"{$table}: matches the live count",
		$by_name[ $table ]['rows_written'],
		(int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" )
	);
}
@unlink( $reference );

/* ================================================================= */
tkv_section( '9. the database changing underneath the dump' );

add_filter( 'tkvault_dump_tables', function () { return array( 'wp_options', 'wp_tkv_chunk' ); } );
tkv_single_chunk();

$job      = TKVault_DB_Dump::start( 'concurrent change' );
$injected = 0;
$requests = 0;
do {
	$snap = TKVault_Runner::run( $job['id'] );
	// A visitor arrives mid-dump: transients appear, cron gets rewritten.
	if ( $injected < 25 ) {
		$wpdb->query(
			$wpdb->prepare(
				'INSERT INTO ' . $wpdb->options . ' (option_name, option_value, autoload) VALUES (%s, %s, %s)',
				'tkv_churn_' . $injected . '_' . wp_generate_password( 6, false ),
				'x',
				'no'
			)
		);
		$injected++;
	}
	$requests++;
} while ( in_array( $snap['status'], array( 'pending', 'running' ), true ) && $requests < 4000 );

remove_all_filters( 'tkvault_dump_tables' );
tkv_normal_chunking();

tkv_check( 'completed despite concurrent writes', $snap['status'], 'complete' );
tkv_info( sprintf( 'injected %d rows during the dump', $injected ) );

$state    = TKVault_Jobs::decode( TKVault_Jobs::get( $job['id'] )->state );
$manifest = json_decode( file_get_contents( dirname( $state['file'] ) . '/' . $state['base'] . '_manifest.json' ), true );

$options_row = null;
foreach ( $manifest['tables'] as $entry ) {
	if ( $wpdb->options === $entry['name'] ) {
		$options_row = $entry;
	}
}

tkv_check( 'the manifest recorded the change', $options_row['rows_after'] > $options_row['rows_before'], true );
tkv_check( 'flagged as not consistent', $manifest['consistent'], false );
tkv_check( 'the warning names the table', false !== strpos( implode( ' ', $manifest['warnings'] ), $wpdb->options ), true );
tkv_info( sprintf( '%s: before=%d after=%d written=%d', $wpdb->options, $options_row['rows_before'], $options_row['rows_after'], $options_row['rows_written'] ) );

$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE 'tkv\\_churn\\_%'" );

/* ================================================================= */
tkv_section( '10. the row-loss rule' );

$verify = new ReflectionMethod( 'TKVault_DB_Dump', 'verify_table' );
$verify->setAccessible( true );

$rule = function ( $before, $written ) use ( $verify ) {
	try {
		$verify->invoke(
			null,
			array(
				'name'         => 'wp_tkv_chunk',
				'rows_before'  => $before,
				'rows_written' => $written,
				'rows_after'   => null,
			)
		);
		return 'accepted';
	} catch ( TKVault_Job_Fatal $e ) {
		return 'refused';
	}
};

$live = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM wp_tkv_chunk' );
tkv_check( 'wrote everything', $rule( $live, $live ), 'accepted' );
tkv_check( 'the table grew during the dump', $rule( $live - 50, $live - 50 ), 'accepted' );
tkv_check( 'the table shrank during the dump', $rule( $live + 50, $live ), 'accepted' );
tkv_check( 'one row lost', $rule( $live, $live - 1 ), 'refused' );
tkv_check( 'many rows lost', $rule( $live, 0 ), 'refused' );

/* ================================================================= */
tkv_section( '11. a failed dump is not recorded as a backup' );

$before = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . $wpdb->prefix . 'tkvault_backups' );

add_filter( 'tkvault_dump_tables', function () { return array( 'wp_tkv_int', 'wp_tkv_doomed' ); } );
tkv_single_chunk();

$wpdb->query( 'DROP TABLE IF EXISTS wp_tkv_doomed' );
$wpdb->query( 'CREATE TABLE wp_tkv_doomed (id INT PRIMARY KEY)' );
$wpdb->query( 'INSERT INTO wp_tkv_doomed VALUES (1),(2),(3)' );

$job = TKVault_DB_Dump::start( 'doomed' );
TKVault_Runner::run( $job['id'] );            // begin: counts the rows
$wpdb->query( 'DROP TABLE wp_tkv_doomed' );   // and then it disappears

$requests = 0;
do {
	$snap = TKVault_Runner::run( $job['id'] );
	$requests++;
} while ( in_array( $snap['status'], array( 'pending', 'running' ), true ) && $requests < 50 );

remove_all_filters( 'tkvault_dump_tables' );
tkv_normal_chunking();

tkv_check( 'the job failed', $snap['status'], 'failed' );
tkv_check( 'it stopped immediately, with no retry storm', $requests <= 2, true );
tkv_check( 'nothing was recorded as a backup', (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . $wpdb->prefix . 'tkvault_backups' ), $before );
tkv_info( 'message: ' . substr( $snap['message'], 0, 90 ) );

/* ================================================================= */
tkv_section( '12. cancelling leaves nothing behind' );

$before = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . $wpdb->prefix . 'tkvault_backups' );
tkv_single_chunk();

$job = TKVault_DB_Dump::start( 'cancelled' );
TKVault_Runner::run( $job['id'] );
TKVault_Runner::run( $job['id'] );

$state   = TKVault_Jobs::decode( TKVault_Jobs::get( $job['id'] )->state );
$partial = $state['file'];
tkv_check( 'a partial archive exists mid-dump', file_exists( $partial ), true );

TKVault_Jobs::cancel( $job['id'] );
tkv_normal_chunking();

tkv_check( 'the archive was removed', file_exists( $partial ), false );
tkv_check( 'no manifest was written', file_exists( dirname( $partial ) . '/' . $state['base'] . '_manifest.json' ), false );
tkv_check( 'nothing in the backup list', (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . $wpdb->prefix . 'tkvault_backups' ), $before );
tkv_check( 'and it does not resume', TKVault_Runner::run( $job['id'] )['status'], 'cancelled' );

/* ================================================================= */
tkv_section( '13. the plugin\'s own scratch tables stay out of the backup' );

$wpdb->query( 'DROP TABLE IF EXISTS wp_tkv_scratch_tkvold, tkvaulttmp_wp_tkv_scratch' );
$wpdb->query( 'CREATE TABLE wp_tkv_scratch_tkvold (id INT PRIMARY KEY)' );
$wpdb->query( 'CREATE TABLE tkvaulttmp_wp_tkv_scratch (id INT PRIMARY KEY)' );

$scope = TKVault_DB_Dump::resolve_tables( false );
tkv_check( 'displaced copies excluded', in_array( 'wp_tkv_scratch_tkvold', $scope, true ), false );
tkv_check( 'import staging excluded', in_array( 'tkvaulttmp_wp_tkv_scratch', $scope, true ), false );

$wpdb->query( 'DROP TABLE wp_tkv_scratch_tkvold, tkvaulttmp_wp_tkv_scratch' );

/* ================================================================= */
tkv_section( 'cleanup' );

foreach ( $fixtures as $table ) {
	$wpdb->query( "DROP TABLE IF EXISTS {$table}" );
	$wpdb->query( "DROP TABLE IF EXISTS {$prefix}{$table}" );
}
$wpdb->query( 'DELETE FROM ' . TKVault_Jobs::table() );
tkv_unblock_loopback();
echo "  done\n";

tkv_report();
