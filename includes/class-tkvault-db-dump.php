<?php
/**
 * Pure-PHP database dump, as a job handler.
 *
 * Two decisions here are worth reading before changing anything.
 *
 * NO UNBUFFERED QUERIES. The original design called for MYSQLI_USE_RESULT to
 * stream large tables a row at a time. That cannot coexist with chunked
 * execution: the runner writes job state through $wpdb between chunks, and a
 * result set left open on the same connection makes the next query fail with
 * "Commands out of sync". The alternatives were a second mysqli connection or
 * plain keyset pagination with a modest LIMIT. Pagination won - fewer moving
 * parts, no extra connection on hosts that count them, and the page boundary
 * is exactly where the runner wants to save its cursor anyway.
 *
 * THE ARCHIVE IS A MULTI-MEMBER GZIP. Appending across requests means each
 * chunk closes and reopens the file, and every reopen starts a new gzip
 * member. That is valid gzip - gunzip, zcat, gzopen() and gzfile() all read
 * the whole thing - but PHP's gzdecode() returns ONLY THE FIRST MEMBER and
 * reports no error. Anything reading these archives must stream with
 * gzopen()/gzread(). The end marker written by finalise() exists so that a
 * reader which got this wrong finds out instead of silently restoring a
 * fraction of the database.
 *
 * @package TakumiVault
 */

defined( 'ABSPATH' ) || exit;

class TKVault_DB_Dump {

	const TYPE = 'db_dump';

	/** Rows fetched per page. Kept small so a chunk is always interruptible. */
	const PAGE_SIZE = 500;

	/** Marker proving the reader reached the end of the last gzip member. */
	const END_MARKER = '-- TAKUMI-VAULT-END';

	public static function init() {
		add_filter( 'tkvault_job_handlers', array( __CLASS__, 'register_handler' ) );
		add_action( 'tkvault_job_cancelled', array( __CLASS__, 'clean_up_cancelled' ) );
	}

	public static function register_handler( $handlers ) {
		$handlers[ self::TYPE ] = array( __CLASS__, 'run_chunk' );
		return $handlers;
	}

	/**
	 * Queue a dump.
	 *
	 * @return array{id:int, secret:string}|WP_Error
	 */
	public static function start( $note = '', $all_tables = false ) {
		$store = TKVault_Storage::get_store_dir();
		if ( ! $store || ! is_dir( $store ) ) {
			return new WP_Error( 'tkvault_no_store', __( 'No backup destination is configured.', 'takumi-vault' ) );
		}

		return TKVault_Jobs::create(
			self::TYPE,
			array(
				'note'       => (string) $note,
				'all_tables' => (bool) $all_tables,
				'timestamp'  => gmdate( 'Y-m-d_His' ),
			),
			0
		);
	}

	/* --------------------------------------------------------------- */

	/**
	 * One page of work.
	 *
	 * @param array $state   Saved cursor.
	 * @param array $payload Job arguments.
	 * @param int   $job_id  Job being advanced.
	 * @return array{state:array, processed:int, done:bool, total?:int}
	 */
	public static function run_chunk( array $state, array $payload, $job_id = 0 ) {
		if ( empty( $state['phase'] ) ) {
			return self::begin( $payload, $job_id );
		}

		if ( 'tables' === $state['phase'] ) {
			return self::dump_page( $state, $payload );
		}

		return self::finalise( $state, $payload );
	}

	/**
	 * Work out what to dump, and write the header.
	 */
	private static function begin( array $payload, $job_id ) {
		global $wpdb;

		$store     = TKVault_Storage::get_store_dir();
		$timestamp = isset( $payload['timestamp'] ) ? $payload['timestamp'] : gmdate( 'Y-m-d_His' );
		$base      = TKVault_Storage::unique_base( $timestamp . '_db' );
		$file      = trailingslashit( $store ) . $base . '.sql.gz';

		if ( ! $store || ! is_dir( $store ) ) {
			throw new TKVault_Job_Fatal( esc_html__( 'The backup destination is missing.', 'takumi-vault' ) );
		}

		$tables = self::resolve_tables( ! empty( $payload['all_tables'] ) );
		if ( ! $tables ) {
			throw new TKVault_Job_Fatal( esc_html__( 'No tables matched the backup scope.', 'takumi-vault' ) );
		}

		$plan  = array();
		$total = 0;
		foreach ( $tables as $table ) {
			$rows    = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . self::ident( $table ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL
			$total  += $rows;
			$plan[] = array(
				'name'           => $table,
				'rows_before'    => $rows,
				'rows_after'     => null,
				'rows_written'   => 0,
				'strategy'       => null,
				'key'            => null,
				'order'          => null,
				'cursor'         => null,
				'offset'         => 0,
				'schema_written' => false,
			);
		}

		$handle = gzopen( $file, 'wb9' );
		if ( ! $handle ) {
			throw new TKVault_Job_Fatal( esc_html__( 'Could not open the dump file for writing.', 'takumi-vault' ) );
		}
		gzwrite( $handle, self::header() );
		gzclose( $handle );

		return array(
			'state'     => array(
				'phase'      => 'tables',
				'file'       => $file,
				'base'       => $base,
				'all_tables' => ! empty( $payload['all_tables'] ),
				'tables'     => $plan,
				'index'      => 0,
				'statements' => 0,
				'packet'     => self::packet_budget(),
			),
			'processed' => 0,
			'done'      => false,
			'total'     => $total,
		);
	}

	/**
	 * Which tables belong to this site.
	 *
	 * Defaulting to SHOW TABLES would sweep in every other WordPress sharing
	 * the database under a different prefix. Restoring that dump would
	 * overwrite someone else's site, so the default is this site's prefix only
	 * and anything wider has to be asked for.
	 */
	public static function resolve_tables( $all_tables = false ) {
		global $wpdb;

		if ( $all_tables ) {
			return (array) $wpdb->get_col( 'SHOW TABLES' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL
		}

		$like   = $wpdb->esc_like( $wpdb->prefix ) . '%';
		$tables = (array) $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', $like ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL

		// On multisite $wpdb->prefix is the per-site prefix, so users and the
		// network tables live under the base prefix and would be left out. A
		// site restored without its users is not restored. Whole-network
		// backup is out of scope for v1 - see the design notes.
		if ( is_multisite() && $wpdb->prefix !== $wpdb->base_prefix ) {
			foreach ( array( 'users', 'usermeta', 'blogs', 'blogmeta', 'site', 'sitemeta', 'signups', 'registration_log' ) as $global ) {
				$name = $wpdb->base_prefix . $global;
				if ( ! in_array( $name, $tables, true ) && self::table_exists( $name ) ) {
					$tables[] = $name;
				}
			}
		}

		$tables = self::without_plugin_scratch( $tables );

		sort( $tables );

		return apply_filters( 'tkvault_dump_tables', $tables, $all_tables );
	}

	/**
	 * Drop the plugin's own working tables from the backup scope.
	 *
	 * Both kinds match the site prefix, so they would otherwise be treated as
	 * site data. The displaced copies a restore leaves behind are a second
	 * full database - including them would roughly double the size of every
	 * backup taken after a restore, and restoring such a backup would bring
	 * the displaced copies back to life as well.
	 */
	private static function without_plugin_scratch( array $tables ) {
		return array_values(
			array_filter(
				$tables,
				static function ( $name ) {
					return 0 !== strpos( $name, TKVault_DB_Restore::TEMP_PREFIX )
						&& substr( $name, -strlen( TKVault_DB_Restore::OLD_SUFFIX ) ) !== TKVault_DB_Restore::OLD_SUFFIX;
				}
			)
		);
	}

	private static function table_exists( $name ) {
		global $wpdb;
		return (bool) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $name ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL
	}

	/**
	 * Dump one page of one table.
	 */
	private static function dump_page( array $state, array $payload ) {
		global $wpdb;

		$index = (int) $state['index'];
		if ( ! isset( $state['tables'][ $index ] ) ) {
			$state['phase'] = 'finalise';
			return array(
				'state'     => $state,
				'processed' => 0,
				'done'      => false,
			);
		}

		$table = $state['tables'][ $index ];
		$name  = $table['name'];
		$sql   = '';

		if ( empty( $table['schema_written'] ) ) {
			$sql                    .= self::schema( $name );
			$table['schema_written'] = true;
			$table                   = array_merge( $table, self::plan_pagination( $name ) );
		}

		$rows = self::fetch_page( $table );
		$written = 0;

		if ( $rows ) {
			$sql    .= self::inserts( $name, $rows, (int) $state['packet'] );
			$written = count( $rows );

			$table['rows_written'] += $written;
			if ( 'keyset' === $table['strategy'] ) {
				$last            = end( $rows );
				$table['cursor'] = $last[ $table['key'] ];
			} else {
				$table['offset'] += $written;
			}
		}

		if ( '' !== $sql ) {
			self::append( $state['file'], $sql );
		}

		// Finished this table when the page came back short.
		if ( count( $rows ) < self::PAGE_SIZE ) {
			$table = self::verify_table( $table );
			++$state['index'];
		}

		$state['tables'][ $index ] = $table;

		$more = isset( $state['tables'][ (int) $state['index'] ] );
		if ( ! $more ) {
			$state['phase'] = 'finalise';
		}

		return array(
			'state'     => $state,
			'processed' => $written,
			'done'      => false,
		);
	}

	/**
	 * Decide how to walk a table.
	 *
	 * "WHERE id > n" only works on a single-column integer primary key.
	 * Composite keys, varchar keys and tables with no primary key at all are
	 * common - WordPress core ships wp_term_relationships with a composite
	 * key, and plugin tables are frequently keyless. Applying keyset
	 * pagination to those silently drops rows, which is the most expensive
	 * possible bug here: it produces a dump that looks fine and restores an
	 * incomplete database.
	 */
	public static function plan_pagination( $table ) {
		global $wpdb;

		$keys = $wpdb->get_results( 'SHOW KEYS FROM ' . self::ident( $table ) . " WHERE Key_name = 'PRIMARY'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL

		if ( 1 === count( $keys ) ) {
			$column = $keys[0]->Column_name;
			$type   = self::column_type( $table, $column );

			if ( preg_match( '/^(tiny|small|medium|big)?int/i', (string) $type ) ) {
				return array(
					'strategy' => 'keyset',
					'key'      => $column,
					'order'    => self::ident( $column ) . ' ASC',
					'cursor'   => null,
				);
			}
		}

		// Everything else walks in a fixed order with LIMIT/OFFSET. Ordering
		// by the primary key columns when there is one, and by every column
		// when there is not, gives a repeatable sequence over an unchanging
		// table, which is what a snapshot is.
		$columns = $keys ? wp_list_pluck( $keys, 'Column_name' ) : self::columns( $table );
		$order   = implode( ', ', array_map( array( __CLASS__, 'ident' ), $columns ) ) . ' ASC';

		return array(
			'strategy' => 'offset',
			'key'      => null,
			'order'    => $order,
			'cursor'   => null,
		);
	}

	private static function fetch_page( array $table ) {
		global $wpdb;

		$name  = self::ident( $table['name'] );
		$order = $table['order'];

		if ( 'keyset' === $table['strategy'] ) {
			$key = self::ident( $table['key'] );

			if ( null === $table['cursor'] ) {
				$sql = $wpdb->prepare( "SELECT * FROM {$name} ORDER BY {$order} LIMIT %d", self::PAGE_SIZE ); // phpcs:ignore WordPress.DB.PreparedSQL
			} else {
				$sql = $wpdb->prepare( "SELECT * FROM {$name} WHERE {$key} > %d ORDER BY {$order} LIMIT %d", (int) $table['cursor'], self::PAGE_SIZE ); // phpcs:ignore WordPress.DB.PreparedSQL
			}
		} else {
			$sql = $wpdb->prepare( "SELECT * FROM {$name} ORDER BY {$order} LIMIT %d OFFSET %d", self::PAGE_SIZE, (int) $table['offset'] ); // phpcs:ignore WordPress.DB.PreparedSQL
		}

		return (array) $wpdb->get_results( $sql, ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL
	}

	/**
	 * Check a finished table's row count without breaking live sites.
	 *
	 * Demanding that the count taken before the dump equals the number of rows
	 * written would fail on any site with visitors. wp_options alone changes
	 * constantly - transients appearing and expiring, the cron array being
	 * rewritten - so a single page view during a backup would abort it. A
	 * chunked dump cannot hold a transaction across requests, so exact
	 * consistency is not available to us and pretending otherwise just means
	 * backups that never succeed.
	 *
	 * What is still worth refusing is evidence that we lost rows we could see.
	 * Counting again at the end brackets the churn: if the table grew, rows
	 * written should be at least the starting count; if it shrank, at least
	 * the ending count. Falling below the smaller of the two cannot be
	 * explained by concurrent writes.
	 *
	 * @return array The table with its closing count filled in.
	 */
	private static function verify_table( array $table ) {
		global $wpdb;

		$after                = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . self::ident( $table['name'] ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL
		$table['rows_after']  = $after;
		$written              = (int) $table['rows_written'];
		$floor                = min( (int) $table['rows_before'], $after );

		if ( $written < $floor ) {
			throw new TKVault_Job_Fatal(
				sprintf(
					/* translators: 1: table name, 2: rows written, 3: row count before, 4: row count after */
					esc_html__( 'Rows were lost dumping %1$s: wrote %2$s, but the table held %3$s before and %4$s after. The backup was stopped rather than saved incomplete.', 'takumi-vault' ),
					esc_html( $table['name'] ),
					esc_html( (string) $written ),
					esc_html( (string) (int) $table['rows_before'] ),
					esc_html( (string) $after )
				)
			);
		}

		return $table;
	}

	/**
	 * Close the archive, hash it, write the manifest, record the backup.
	 */
	private static function finalise( array $state, array $payload ) {
		global $wpdb;

		$file  = $state['file'];
		$store = TKVault_Storage::get_store_dir();

		$rows = 0;
		foreach ( $state['tables'] as $table ) {
			$rows += (int) $table['rows_written'];
		}

		self::append(
			$file,
			"SET FOREIGN_KEY_CHECKS=1;\n" .
			sprintf( "%s tables=%d rows=%d\n", self::END_MARKER, count( $state['tables'] ), $rows )
		);

		$manifest_file = trailingslashit( $store ) . $state['base'] . '_manifest.json';
		$manifest      = self::manifest( $state, $file, $rows );

		if ( false === file_put_contents( $manifest_file, wp_json_encode( $manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			throw new TKVault_Job_Fatal( esc_html__( 'Could not write the backup manifest.', 'takumi-vault' ) );
		}

		TKVault_DB::insert_backup(
			array(
				'filename' => basename( $file ),
				// Safety copies taken before a restore are excluded from
				// generation pruning: the one backup you need after a bad
				// restore must not be the one the retention policy deleted.
				'type'     => empty( $payload['safety'] ) ? 'db' : 'db-safety',
				'size'     => (int) filesize( $file ),
				'status'   => 'completed',
				'note'     => isset( $payload['note'] ) ? $payload['note'] : '',
			)
		);

		$state['phase'] = 'complete';

		return array(
			'state'     => $state,
			'processed' => 0,
			'done'      => true,
		);
	}

	private static function manifest( array $state, $file, $rows ) {
		global $wpdb;

		$tables   = array();
		$warnings = array();
		foreach ( $state['tables'] as $table ) {
			$before  = (int) $table['rows_before'];
			$after   = null === $table['rows_after'] ? $before : (int) $table['rows_after'];
			$written = (int) $table['rows_written'];

			$tables[] = array(
				'name'         => $table['name'],
				'rows_written' => $written,
				'rows_before'  => $before,
				'rows_after'   => $after,
				'strategy'     => $table['strategy'],
			);

			// Not an error, but the restore side should know the table moved
			// underneath us rather than assume the count is authoritative.
			if ( $before !== $after || $written !== $before ) {
				$warnings[] = sprintf(
					'%s changed during the dump (before %d, after %d, written %d)',
					$table['name'],
					$before,
					$after,
					$written
				);
			}
		}

		$manifest = array(
			'format'     => 1,
			'plugin'     => 'takumi-vault',
			'version'    => TKVAULT_VERSION,
			'created'    => gmdate( 'c' ),
			'site_url'   => site_url(),
			'db_name'    => DB_NAME,
			'prefix'     => $wpdb->prefix,
			'charset'    => $wpdb->charset,
			'multisite'  => is_multisite(),
			'scope'      => empty( $state['all_tables'] ) ? 'site_prefix' : 'all_tables',
			'rows_total' => (int) $rows,
			'consistent' => empty( $warnings ),
			'warnings'   => $warnings,
			'tables'     => $tables,
			'files'      => array(
				array(
					'name'     => basename( $file ),
					'size'     => (int) filesize( $file ),
					'sha256'   => hash_file( 'sha256', $file ),
					'encoding' => 'gzip-multi-member',
					'reader'   => 'gzopen/gzread - gzdecode() returns only the first member',
				),
			),
		);

		// The manifest signs itself so that a tampered or truncated manifest
		// is as detectable as a tampered archive.
		$manifest['self_sha256'] = hash( 'sha256', wp_json_encode( $manifest ) );

		return $manifest;
	}

	/* --------------------------------------------------------------- */

	private static function header() {
		global $wpdb;

		return "-- Takumi Vault database dump\n"
			. '-- Created: ' . gmdate( 'c' ) . "\n"
			. '-- Site: ' . site_url() . "\n"
			. "-- Read this file with gzopen()/gzread(). gzdecode() returns only the first member.\n"
			. "SET NAMES " . ( $wpdb->charset ? $wpdb->charset : 'utf8mb4' ) . ";\n"
			. "SET FOREIGN_KEY_CHECKS=0;\n"
			. "SET SQL_MODE='NO_AUTO_VALUE_ON_ZERO';\n\n";
	}

	private static function schema( $table ) {
		global $wpdb;

		$row = $wpdb->get_row( 'SHOW CREATE TABLE ' . self::ident( $table ), ARRAY_N ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL
		if ( ! $row || ! isset( $row[1] ) ) {
			throw new TKVault_Job_Fatal(
				sprintf(
					/* translators: %s: table name */
					esc_html__( 'Could not read the schema for %s.', 'takumi-vault' ),
					esc_html( $table )
				)
			);
		}

		return "\n-- Table: {$table}\n"
			. 'DROP TABLE IF EXISTS ' . self::ident( $table ) . ";\n"
			. $row[1] . ";\n";
	}

	/**
	 * Build multi-row INSERTs no larger than the server will accept.
	 */
	private static function inserts( $table, array $rows, $packet ) {
		global $wpdb;

		$name   = self::ident( $table );
		$sql    = '';
		$buffer = array();
		$length = 0;

		foreach ( $rows as $row ) {
			$values = array();
			foreach ( $row as $column => $value ) {
				$values[] = self::literal( $table, $column, $value );
			}

			$tuple    = '(' . implode( ',', $values ) . ')';
			$length  += strlen( $tuple ) + 1;
			$buffer[] = $tuple;

			if ( $length >= $packet ) {
				$sql   .= 'INSERT INTO ' . $name . ' VALUES ' . implode( ',', $buffer ) . ";\n";
				$buffer = array();
				$length = 0;
			}
		}

		if ( $buffer ) {
			$sql .= 'INSERT INTO ' . $name . ' VALUES ' . implode( ',', $buffer ) . ";\n";
		}

		return $sql;
	}

	/**
	 * One column value as SQL.
	 *
	 * Numbers are written unquoted. Quoting them still restores - MySQL casts
	 * '1' to 1 happily - but it diverges from what every other dump tool
	 * produces, inflates the file, and leans on implicit conversion for no
	 * benefit. Anything that is not valid UTF-8 goes out as a hex literal:
	 * passing raw bytes through a quoted string works right up until the
	 * connection charset disagrees, and then it corrupts silently.
	 */
	private static function literal( $table, $column, $value ) {
		global $wpdb;

		if ( null === $value ) {
			return 'NULL';
		}

		$value = (string) $value;

		if ( ! self::is_utf8( $value ) || self::is_binary_column( $table, $column ) ) {
			return '' === $value ? "''" : '0x' . bin2hex( $value );
		}

		if ( self::is_numeric_column( $table, $column ) && self::is_sql_number( $value ) ) {
			return $value;
		}

		return $wpdb->prepare( '%s', $value ); // phpcs:ignore WordPress.DB.PreparedSQL
	}

	private static function is_numeric_column( $table, $column ) {
		$type = strtolower( self::column_type( $table, $column ) );
		return (bool) preg_match( '/^(tiny|small|medium|big)?int|^decimal|^numeric|^float|^double|^real/', $type );
	}

	/**
	 * Whether the value can be written as a bare SQL numeric literal.
	 *
	 * Deliberately strict. An empty string in a numeric column, or anything
	 * unexpected, falls back to a quoted value rather than producing SQL that
	 * will not parse.
	 */
	private static function is_sql_number( $value ) {
		return 1 === preg_match( '/^-?(0|[1-9][0-9]*)(\.[0-9]+)?([eE][+-]?[0-9]+)?$/', $value );
	}

	private static function is_utf8( $value ) {
		if ( function_exists( 'mb_check_encoding' ) ) {
			return mb_check_encoding( $value, 'UTF-8' );
		}
		// Without mbstring, PCRE's UTF-8 mode answers the same question.
		return 1 === preg_match( '//u', $value );
	}

	private static $column_types = array();

	private static function column_type( $table, $column ) {
		$types = self::columns_types( $table );
		return isset( $types[ $column ] ) ? $types[ $column ] : '';
	}

	private static function is_binary_column( $table, $column ) {
		$type = strtolower( self::column_type( $table, $column ) );
		return (bool) preg_match( '/(blob|binary)/', $type );
	}

	private static function columns_types( $table ) {
		global $wpdb;

		if ( isset( self::$column_types[ $table ] ) ) {
			return self::$column_types[ $table ];
		}

		$types = array();
		$rows  = $wpdb->get_results( 'SHOW COLUMNS FROM ' . self::ident( $table ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL
		foreach ( (array) $rows as $row ) {
			$types[ $row->Field ] = $row->Type;
		}

		self::$column_types[ $table ] = $types;

		return $types;
	}

	private static function columns( $table ) {
		return array_keys( self::columns_types( $table ) );
	}

	/**
	 * Quote an identifier, refusing anything that is not one.
	 */
	public static function ident( $name ) {
		$name = (string) $name;

		if ( ! preg_match( '/^[A-Za-z0-9_$\x{0080}-\x{FFFF}]+$/u', $name ) ) {
			throw new TKVault_Job_Fatal(
				sprintf(
					/* translators: %s: rejected identifier */
					esc_html__( 'Refusing to use %s as a table or column name.', 'takumi-vault' ),
					esc_html( $name )
				)
			);
		}

		return '`' . $name . '`';
	}

	/**
	 * How large a single statement may be.
	 *
	 * A dump built to a hard-coded 1MB succeeds and then fails on restore
	 * against a server configured lower, so ask the server instead.
	 */
	public static function packet_budget() {
		global $wpdb;

		$row    = $wpdb->get_row( "SHOW VARIABLES LIKE 'max_allowed_packet'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL
		$packet = $row && isset( $row->Value ) ? (int) $row->Value : 0;

		if ( $packet <= 0 ) {
			return 1048576; // Conservative default when the server will not say.
		}

		// Half, so the statement plus protocol overhead still fits.
		return max( 65536, min( (int) ( $packet / 2 ), 16 * 1024 * 1024 ) );
	}

	private static function append( $file, $sql ) {
		$handle = gzopen( $file, 'ab9' );
		if ( ! $handle ) {
			throw new TKVault_Job_Fatal( esc_html__( 'Could not append to the dump file.', 'takumi-vault' ) );
		}
		gzwrite( $handle, $sql );
		gzclose( $handle );
	}

	/**
	 * A cancelled dump leaves nothing behind that could be mistaken for one.
	 */
	public static function clean_up_cancelled( $job ) {
		if ( ! $job || self::TYPE !== $job->type ) {
			return;
		}

		$state = TKVault_Jobs::decode( $job->state );
		if ( empty( $state['file'] ) ) {
			return;
		}

		foreach ( array( $state['file'], dirname( $state['file'] ) . '/' . $state['base'] . '_manifest.json' ) as $path ) {
			if ( file_exists( $path ) ) {
				wp_delete_file( $path );
			}
		}
	}
}
