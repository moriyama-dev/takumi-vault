<?php
/**
 * Database restore, as a job handler.
 *
 * Restoring is the one operation that can destroy the site it is trying to
 * save, so the order of work matters more than anything else here:
 *
 *   verify   the archive is the one the manifest describes, and is complete
 *   scan     read the whole file once and check it parses, before writing
 *   space    refuse to start if the swap cannot fit
 *   safety   dump the current database, unconditionally
 *   import   load everything under a temporary prefix
 *   swap     one RENAME TABLE, which is near-instant and atomic
 *   finalise keep the site reachable and record what happened
 *
 * Nothing touches a live table until the import has completely succeeded. A
 * failure at any earlier point leaves the site exactly as it was and costs
 * only the temporary tables, which are dropped.
 *
 * @package TakumiVault
 */

defined( 'ABSPATH' ) || exit;

class TKVault_DB_Restore {

	const TYPE = 'db_restore';

	/** Prefix for tables being imported. Never written to a live table. */
	const TEMP_PREFIX = 'tkvaulttmp_';

	/** Suffix for the displaced live tables, kept so a restore can be undone. */
	const OLD_SUFFIX = '_tkvold';

	const OPTION_OLD_TABLES = 'tkvault_old_tables';
	const OPTION_RETENTION  = 'tkvault_old_table_retention_days';
	const CLEANUP_HOOK      = 'tkvault_old_tables_event';

	/** Statements executed per chunk before handing back to the runner. */
	const BATCH = 200;

	public static function init() {
		add_filter( 'tkvault_job_handlers', array( __CLASS__, 'register_handler' ) );
		add_action( 'tkvault_job_cancelled', array( __CLASS__, 'clean_up_cancelled' ) );
		add_action( self::CLEANUP_HOOK, array( __CLASS__, 'drop_expired_old_tables' ) );
	}

	public static function register_handler( $handlers ) {
		$handlers[ self::TYPE ] = array( __CLASS__, 'run_chunk' );
		return $handlers;
	}

	/**
	 * Queue a restore.
	 *
	 * @return array{id:int, secret:string}|WP_Error
	 */
	public static function start( $backup_id, $confirm_space = false ) {
		$record = TKVault_DB::get_backup_by_id( (int) $backup_id );
		if ( ! $record ) {
			return new WP_Error( 'tkvault_no_backup', __( 'That backup does not exist.', 'takumi-vault' ) );
		}

		$store = TKVault_Storage::get_store_dir();
		$file  = trailingslashit( $store ) . basename( $record->filename );

		if ( ! file_exists( $file ) ) {
			return new WP_Error( 'tkvault_file_missing', __( 'The backup file is missing from the destination.', 'takumi-vault' ) );
		}

		return TKVault_Jobs::create(
			self::TYPE,
			array(
				'backup_id'     => (int) $backup_id,
				'file'          => $file,
				'confirm_space' => (bool) $confirm_space,
			),
			0
		);
	}

	/* --------------------------------------------------------------- */

	public static function run_chunk( array $state, array $payload, $job_id = 0 ) {
		$phase = empty( $state['phase'] ) ? 'verify' : $state['phase'];

		switch ( $phase ) {
			case 'verify':
				return self::verify( $payload );
			case 'scan':
				return self::scan( $state );
			case 'space':
				return self::check_space( $state, $payload );
			case 'safety':
				return self::safety( $state, $payload, $job_id );
			case 'import':
				return self::import( $state );
			case 'swap':
				return self::swap( $state );
			default:
				return self::finalise( $state );
		}
	}

	/**
	 * The archive is what the manifest says it is.
	 */
	private static function verify( array $payload ) {
		global $wpdb;

		$file     = $payload['file'];
		$manifest = self::manifest_for( $file );

		if ( is_wp_error( $manifest ) ) {
			throw new TKVault_Job_Fatal( esc_html( $manifest->get_error_message() ) );
		}

		// The manifest signs itself, so a truncated or edited manifest is as
		// detectable as a damaged archive.
		$claimed = isset( $manifest['self_sha256'] ) ? $manifest['self_sha256'] : '';
		$copy    = $manifest;
		unset( $copy['self_sha256'] );
		if ( ! $claimed || ! hash_equals( $claimed, hash( 'sha256', wp_json_encode( $copy ) ) ) ) {
			throw new TKVault_Job_Fatal( esc_html__( 'The backup manifest does not match its own checksum. Refusing to restore from it.', 'takumi-vault' ) );
		}

		$recorded = '';
		foreach ( $manifest['files'] as $entry ) {
			if ( basename( $file ) === $entry['name'] ) {
				$recorded = $entry['sha256'];
			}
		}
		if ( ! $recorded ) {
			throw new TKVault_Job_Fatal( esc_html__( 'The manifest does not describe this archive.', 'takumi-vault' ) );
		}
		if ( ! hash_equals( $recorded, (string) hash_file( 'sha256', $file ) ) ) {
			throw new TKVault_Job_Fatal( esc_html__( 'The archive does not match the checksum recorded when it was created. It is damaged or was modified.', 'takumi-vault' ) );
		}

		// Restoring a dump taken under a different table prefix would write
		// tables this site never reads. Out of scope for v1, and silently
		// doing the wrong thing is worse than refusing.
		if ( isset( $manifest['prefix'] ) && $manifest['prefix'] !== $wpdb->prefix ) {
			throw new TKVault_Job_Fatal(
				sprintf(
					/* translators: 1: prefix in the backup, 2: prefix of this site */
					esc_html__( 'This backup was taken from a site using the table prefix %1$s, but this site uses %2$s. Restoring across prefixes is not supported.', 'takumi-vault' ),
					esc_html( $manifest['prefix'] ),
					esc_html( $wpdb->prefix )
				)
			);
		}

		return array(
			'state'     => array(
				'phase'    => 'scan',
				'file'     => $file,
				'manifest' => $manifest,
				'offset'   => 0,
				'scan'     => array(
					'statements' => 0,
					'creates'    => array(),
					'max_length' => 0,
					'bytes'      => 0,
					'end_marker' => false,
				),
				'warnings' => array(),
				'siteurl'  => get_option( 'siteurl' ),
				'home'     => get_option( 'home' ),
			),
			'processed' => 0,
			'done'      => false,
			'total'     => isset( $manifest['rows_total'] ) ? (int) $manifest['rows_total'] : 0,
		);
	}

	private static function manifest_for( $file ) {
		$path = preg_replace( '/\.sql\.gz$/', '_manifest.json', $file );

		if ( ! $path || ! file_exists( $path ) ) {
			return new WP_Error( 'tkvault_no_manifest', __( 'This backup has no manifest, so it cannot be verified before restoring.', 'takumi-vault' ) );
		}

		$manifest = json_decode( (string) file_get_contents( $path ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		if ( ! is_array( $manifest ) ) {
			return new WP_Error( 'tkvault_bad_manifest', __( 'The backup manifest could not be read.', 'takumi-vault' ) );
		}

		return $manifest;
	}

	/**
	 * Read the whole archive once without executing anything.
	 *
	 * The point is to find out that the file is broken before half of it has
	 * been applied. It also measures the uncompressed size, which the space
	 * check needs and which nothing else knows.
	 */
	private static function scan( array $state ) {
		$reader = TKVault_SQL_Reader::open_at( $state['file'], (int) $state['offset'] );
		$scan   = $state['scan'];
		$seen   = 0;

		while ( $seen < 2000 ) {
			$statement = $reader->next_statement();
			if ( null === $statement ) {
				break;
			}
			$seen++;

			if ( '' === $statement ) {
				continue;
			}

			$scan['statements']++;
			$scan['max_length'] = max( $scan['max_length'], strlen( $statement ) );

			if ( preg_match( '/^CREATE\s+TABLE(?:\s+IF\s+NOT\s+EXISTS)?\s+`([^`]+)`/i', $statement, $m ) ) {
				$scan['creates'][] = $m[1];
			}
		}

		$state['offset'] = $reader->tell();
		$scan['bytes']   = $state['offset'];

		if ( null === $statement ) {
			// End of file. Everything below is a reason to stop before writing.
			$scan['end_marker'] = self::has_end_marker( $state['file'] );
			$state['scan']      = $scan;

			if ( ! $scan['end_marker'] ) {
				throw new TKVault_Job_Fatal( esc_html__( 'The archive has no end marker, so it was truncated before it finished being written. Refusing to restore a partial dump.', 'takumi-vault' ) );
			}

			$expected = isset( $state['manifest']['tables'] ) ? count( $state['manifest']['tables'] ) : 0;
			if ( $expected && count( $scan['creates'] ) !== $expected ) {
				throw new TKVault_Job_Fatal(
					sprintf(
						/* translators: 1: tables found, 2: tables expected */
						esc_html__( 'The archive contains %1$s tables but the manifest describes %2$s. Refusing to restore.', 'takumi-vault' ),
						esc_html( (string) count( $scan['creates'] ) ),
						esc_html( (string) $expected )
					)
				);
			}

			$packet = TKVault_DB_Dump::packet_budget() * 2;
			if ( $scan['max_length'] > $packet ) {
				throw new TKVault_Job_Fatal(
					sprintf(
						/* translators: 1: largest statement size, 2: server limit */
						esc_html__( 'The archive contains a statement of %1$s bytes, larger than this server accepts (%2$s). It would fail part way through.', 'takumi-vault' ),
						esc_html( (string) $scan['max_length'] ),
						esc_html( (string) $packet )
					)
				);
			}

			$state['phase']  = 'space';
			$state['offset'] = 0;

			return array(
				'state'     => $state,
				'processed' => 0,
				'done'      => false,
			);
		}

		$state['scan'] = $scan;

		return array(
			'state'     => $state,
			'processed' => 0,
			'done'      => false,
		);
	}

	/**
	 * The end marker is the last thing the dump writes.
	 *
	 * Reading the tail specifically catches the multi-member gzip trap: a
	 * reader that stopped at the first member never sees this, and neither
	 * does a file whose last chunk was lost.
	 */
	private static function has_end_marker( $file ) {
		$handle = gzopen( $file, 'rb' );
		if ( ! $handle ) {
			return false;
		}

		$tail = '';
		while ( ! gzeof( $handle ) ) {
			$tail = substr( $tail . gzread( $handle, 65536 ), -4096 );
		}
		gzclose( $handle );

		return false !== strpos( $tail, TKVault_DB_Dump::END_MARKER );
	}

	/**
	 * The swap needs the old and new copies to exist at once.
	 */
	private static function check_space( array $state, array $payload ) {
		global $wpdb;

		$db_size = (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COALESCE(SUM(data_length + index_length),0) FROM information_schema.TABLES WHERE table_schema = %s',
				DB_NAME
			)
		);

		$required = (int) ( ( $db_size + (int) $state['scan']['bytes'] ) * 1.2 );
		$free     = function_exists( 'disk_free_space' ) ? @disk_free_space( ABSPATH ) : false; // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged

		if ( false === $free ) {
			// Shared hosting rarely lets PHP see the database volume at all.
			// Refusing every restore would be useless, so this is the one
			// place the user is asked to take responsibility.
			if ( empty( $payload['confirm_space'] ) ) {
				throw new TKVault_Job_Fatal(
					sprintf(
						/* translators: %s: estimated space needed, human readable */
						esc_html__( 'Free space cannot be measured on this host. The restore needs roughly %s while both copies exist. Confirm you have that available and start again.', 'takumi-vault' ),
						esc_html( size_format( $required ) )
					)
				);
			}
			$state['warnings'][] = __( 'Free space could not be verified before restoring.', 'takumi-vault' );
		} elseif ( $free < $required ) {
			throw new TKVault_Job_Fatal(
				sprintf(
					/* translators: 1: space needed, 2: space available */
					esc_html__( 'Not enough room to restore: about %1$s is needed while the old and new copies both exist, and %2$s is free.', 'takumi-vault' ),
					esc_html( size_format( $required ) ),
					esc_html( size_format( $free ) )
				)
			);
		}

		// disk_free_space() reports the filesystem holding WordPress, which is
		// not necessarily the one holding MySQL. Say so rather than imply a
		// guarantee.
		$state['warnings'][] = __( 'Space was checked against the WordPress filesystem, which may not be where the database is stored.', 'takumi-vault' );

		$state['phase']  = 'safety';
		$state['safety'] = array();

		return array(
			'state'     => $state,
			'processed' => 0,
			'done'      => false,
		);
	}

	/**
	 * Dump the current database before touching it.
	 *
	 * Not optional and not configurable. "I restored a backup and it made
	 * things worse" is the most common way this feature hurts someone, and
	 * the only useful answer is a copy of what they had a minute ago.
	 *
	 * The dump's own state machine is driven here rather than queued as a
	 * separate job, so the restore cannot start before it has finished.
	 */
	private static function safety( array $state, array $payload, $job_id ) {
		$result = TKVault_DB_Dump::run_chunk(
			$state['safety'],
			array(
				'note'      => __( 'Automatic safety copy taken before a restore', 'takumi-vault' ),
				'safety'    => true,
				'timestamp' => isset( $state['safety_timestamp'] ) ? $state['safety_timestamp'] : gmdate( 'Y-m-d_His' ),
			),
			$job_id
		);

		$state['safety_timestamp'] = isset( $state['safety']['base'] )
			? substr( $state['safety']['base'], 0, 17 )
			: ( isset( $state['safety_timestamp'] ) ? $state['safety_timestamp'] : gmdate( 'Y-m-d_His' ) );

		$state['safety'] = $result['state'];

		if ( ! empty( $result['done'] ) ) {
			$state['phase']       = 'import';
			$state['offset']      = 0;
			$state['imported']    = 0;
			$state['temp_map']    = self::temp_map( $state['scan']['creates'] );
			$state['safety_file'] = isset( $result['state']['file'] ) ? $result['state']['file'] : '';
		}

		return array(
			'state'     => $state,
			'processed' => 0,
			'done'      => false,
		);
	}

	/**
	 * Tables belonging to the plugin itself, which a restore must leave alone.
	 *
	 * They match the site prefix, so they are in the dump, and swapping them
	 * in would be self-defeating in two ways: the running restore job lives in
	 * tkvault_jobs and would delete its own row mid-flight, and the backup
	 * history would be rolled back to a state that does not know about the
	 * safety copy taken thirty seconds earlier. Neither table describes the
	 * site's content, so nothing of the user's is lost by keeping them.
	 */
	public static function is_own_table( $name ) {
		global $wpdb;
		return in_array(
			$name,
			array( $wpdb->prefix . 'tkvault_jobs', $wpdb->prefix . 'tkvault_backups' ),
			true
		);
	}

	/**
	 * Map each real table to the temporary one it is imported as.
	 *
	 * MySQL stops at 64 characters, and a long plugin table name plus the
	 * prefix can cross that, so anything at risk is hashed instead of
	 * truncated - two different long names must not collide.
	 */
	public static function temp_map( array $tables ) {
		$map = array();

		foreach ( $tables as $table ) {
			if ( self::is_own_table( $table ) ) {
				continue;
			}

			$candidate = self::TEMP_PREFIX . $table;
			if ( strlen( $candidate ) > 64 ) {
				$candidate = self::TEMP_PREFIX . substr( md5( $table ), 0, 12 ) . '_' . substr( $table, -30 );
			}
			$map[ $table ] = $candidate;
		}

		return $map;
	}

	/**
	 * Load statements under the temporary prefix.
	 */
	private static function import( array $state ) {
		global $wpdb;

		$reader = TKVault_SQL_Reader::open_at( $state['file'], (int) $state['offset'] );
		$map    = $state['temp_map'];

		// Every chunk may be a fresh request and therefore a fresh connection.
		$wpdb->query( 'SET SESSION FOREIGN_KEY_CHECKS=0' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->query( "SET SESSION SQL_MODE='NO_AUTO_VALUE_ON_ZERO'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

		$done      = 0;
		$statement = '';

		/**
		 * Filters how many statements one chunk applies before handing back.
		 *
		 * @param int $batch Statement count.
		 */
		$batch = (int) apply_filters( 'tkvault_restore_batch', self::BATCH );
		$batch = max( 1, $batch );

		while ( $done < $batch ) {
			$statement = $reader->next_statement();
			if ( null === $statement ) {
				break;
			}
			if ( '' === $statement ) {
				continue;
			}

			if ( self::targets_own_table( $statement ) ) {
				continue;
			}

			$rewritten = self::rewrite( $statement, $map );
			if ( '' === $rewritten ) {
				continue;
			}

			self::assert_temp_only( $rewritten, $map );

			$wpdb->suppress_errors( true );
			$ok = $wpdb->query( $rewritten ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL
			$wpdb->suppress_errors( false );

			if ( false === $ok ) {
				self::drop_temp_tables( $map );
				throw new TKVault_Job_Fatal(
					sprintf(
						/* translators: 1: database error, 2: first part of the failing statement */
						esc_html__( 'The restore stopped on a statement the database rejected: %1$s (%2$s). Nothing was changed; the temporary tables have been removed.', 'takumi-vault' ),
						esc_html( $wpdb->last_error ),
						esc_html( substr( $rewritten, 0, 120 ) )
					)
				);
			}

			$state['imported']++;
			$done++;
		}

		$state['offset'] = $reader->tell();

		if ( null === $statement ) {
			$state['phase'] = 'swap';
		}

		return array(
			'state'     => $state,
			'processed' => $done,
			'done'      => false,
		);
	}

	private static function targets_own_table( $sql ) {
		if ( ! preg_match( '/^\s*(?:DROP\s+TABLE(?:\s+IF\s+EXISTS)?|CREATE\s+TABLE(?:\s+IF\s+NOT\s+EXISTS)?|INSERT(?:\s+IGNORE)?\s+INTO|REPLACE\s+INTO|ALTER\s+TABLE|TRUNCATE(?:\s+TABLE)?|LOCK\s+TABLES)\s+`([^`]+)`/i', $sql, $m ) ) {
			return false;
		}
		return self::is_own_table( $m[1] );
	}

	/**
	 * Point a statement at the temporary tables.
	 */
	public static function rewrite( $sql, array $map ) {
		$sql = preg_replace_callback(
			'/^(\s*(?:DROP\s+TABLE(?:\s+IF\s+EXISTS)?|CREATE\s+TABLE(?:\s+IF\s+NOT\s+EXISTS)?|INSERT(?:\s+IGNORE)?\s+INTO|REPLACE\s+INTO|ALTER\s+TABLE|TRUNCATE(?:\s+TABLE)?|LOCK\s+TABLES)\s+)`([^`]+)`/i',
			static function ( $m ) use ( $map ) {
				return $m[1] . '`' . ( isset( $map[ $m[2] ] ) ? $map[ $m[2] ] : $m[2] ) . '`';
			},
			$sql,
			1
		);

		// Foreign keys inside a CREATE TABLE must follow the tables they point
		// at, or the temporary copy would reference the live one.
		return preg_replace_callback(
			'/REFERENCES\s+`([^`]+)`/i',
			static function ( $m ) use ( $map ) {
				return 'REFERENCES `' . ( isset( $map[ $m[1] ] ) ? $map[ $m[1] ] : $m[1] ) . '`';
			},
			$sql
		);
	}

	/**
	 * Last line of defence before anything is executed.
	 *
	 * If the rewriter ever misses a table name, this is what stops a DROP
	 * TABLE landing on the live site instead of the temporary copy. It costs
	 * one regex per statement and it guards the only truly unrecoverable
	 * mistake this plugin can make.
	 */
	private static function assert_temp_only( $sql, array $map ) {
		if ( ! preg_match( '/^\s*(DROP\s+TABLE|CREATE\s+TABLE|INSERT|REPLACE|ALTER\s+TABLE|TRUNCATE)/i', $sql ) ) {
			return;
		}

		if ( ! preg_match( '/`([^`]+)`/', $sql, $m ) ) {
			return;
		}

		if ( ! in_array( $m[1], $map, true ) ) {
			throw new TKVault_Job_Fatal(
				sprintf(
					/* translators: %s: table name */
					esc_html__( 'Refusing to run a statement that targets %s instead of a temporary table. This is a bug; nothing was changed.', 'takumi-vault' ),
					esc_html( $m[1] )
				)
			);
		}
	}

	/**
	 * Put the imported tables live in a single statement.
	 *
	 * RENAME TABLE moves every table at once and takes about as long as
	 * touching the file system, so the window where the site is half restored
	 * is as small as it can be made without a transaction.
	 */
	private static function swap( array $state ) {
		global $wpdb;

		$map    = $state['temp_map'];
		$suffix = self::OLD_SUFFIX;
		$pairs  = array();
		$olds   = array();

		foreach ( $map as $live => $temp ) {
			if ( ! self::table_exists( $temp ) ) {
				throw new TKVault_Job_Fatal(
					sprintf(
						/* translators: %s: table name */
						esc_html__( 'The imported copy of %s is missing, so the swap was not attempted.', 'takumi-vault' ),
						esc_html( $live )
					)
				);
			}

			if ( self::table_exists( $live ) ) {
				$old = $live . $suffix;
				if ( strlen( $old ) > 64 ) {
					$old = substr( $live, 0, 64 - strlen( $suffix ) ) . $suffix;
				}
				if ( self::table_exists( $old ) ) {
					$wpdb->query( 'DROP TABLE ' . TKVault_DB_Dump::ident( $old ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL
				}
				$pairs[] = TKVault_DB_Dump::ident( $live ) . ' TO ' . TKVault_DB_Dump::ident( $old );
				$olds[]  = array(
					'old'      => $old,
					'original' => $live,
					'created'  => time(),
				);
			}

			$pairs[] = TKVault_DB_Dump::ident( $temp ) . ' TO ' . TKVault_DB_Dump::ident( $live );
		}

		$wpdb->suppress_errors( true );
		$ok = $wpdb->query( 'RENAME TABLE ' . implode( ', ', $pairs ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL
		$wpdb->suppress_errors( false );

		if ( false === $ok ) {
			$error = $wpdb->last_error;
			self::drop_temp_tables( $map );
			throw new TKVault_Job_Fatal(
				sprintf(
					/* translators: %s: database error */
					esc_html__( 'The swap failed and was rolled back: %s. The site is unchanged.', 'takumi-vault' ),
					esc_html( $error )
				)
			);
		}

		// Replace rather than merge. The list lives in wp_options, which this
		// restore has just overwritten with whatever the backup contained, so
		// anything already in there describes a different moment in the site's
		// history and its tables are gone or renamed. Only the copies this
		// swap displaced can be undone.
		update_option( self::OPTION_OLD_TABLES, $olds, false );

		$state['phase'] = 'finalise';
		$state['olds']  = $olds;

		return array(
			'state'     => $state,
			'processed' => 0,
			'done'      => false,
		);
	}

	/**
	 * Keep the site reachable and say what still needs attention.
	 */
	private static function finalise( array $state ) {
		global $wpdb;

		wp_cache_flush();

		// A backup from another address would otherwise send every visitor,
		// and the administrator, to a domain this install does not answer on.
		$restored_siteurl = get_option( 'siteurl' );
		if ( $restored_siteurl && $restored_siteurl !== $state['siteurl'] ) {
			update_option( 'siteurl', $state['siteurl'] );
			update_option( 'home', $state['home'] );
			$state['warnings'][] = sprintf(
				/* translators: 1: address stored in the backup, 2: address of this site */
				__( 'The backup was taken at %1$s. The site address has been kept as %2$s so this install stays reachable, but URLs stored inside posts and options still point at the old address.', 'takumi-vault' ),
				$restored_siteurl,
				$state['siteurl']
			);
		}

		TKVault_SQL_Reader::close_cached();

		$state['phase'] = 'complete';

		return array(
			'state'     => $state,
			'processed' => 0,
			'done'      => true,
		);
	}

	/* --------------------------------------------------------------- */

	public static function table_exists( $name ) {
		global $wpdb;
		return (bool) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $name ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL
	}

	private static function drop_temp_tables( array $map ) {
		global $wpdb;

		foreach ( $map as $temp ) {
			if ( self::table_exists( $temp ) ) {
				$wpdb->query( 'DROP TABLE ' . TKVault_DB_Dump::ident( $temp ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL
			}
		}
	}

	/**
	 * A cancelled restore must not leave temporary tables behind.
	 */
	public static function clean_up_cancelled( $job ) {
		if ( ! $job || self::TYPE !== $job->type ) {
			return;
		}

		$state = TKVault_Jobs::decode( $job->state );
		if ( ! empty( $state['temp_map'] ) ) {
			self::drop_temp_tables( $state['temp_map'] );
		}

		TKVault_SQL_Reader::close_cached();
	}

	/**
	 * Put the displaced tables back.
	 *
	 * @return true|WP_Error
	 */
	public static function undo() {
		global $wpdb;

		$olds = (array) get_option( self::OPTION_OLD_TABLES, array() );
		if ( ! $olds ) {
			return new WP_Error( 'tkvault_nothing_to_undo', __( 'There is no restore to undo.', 'takumi-vault' ) );
		}

		// Skip anything whose saved copy has gone rather than refusing outright.
		// Undo is a recovery path, and one stale entry - a table dropped by
		// hand, or a list inherited from a restored wp_options - must not be
		// able to block putting the rest back.
		$pairs   = array();
		$skipped = array();

		foreach ( $olds as $entry ) {
			if ( ! self::table_exists( $entry['old'] ) ) {
				$skipped[] = $entry['original'];
				continue;
			}
			if ( self::table_exists( $entry['original'] ) ) {
				$pairs[] = TKVault_DB_Dump::ident( $entry['original'] ) . ' TO ' . TKVault_DB_Dump::ident( $entry['original'] . '_undone' );
			}
			$pairs[] = TKVault_DB_Dump::ident( $entry['old'] ) . ' TO ' . TKVault_DB_Dump::ident( $entry['original'] );
		}

		if ( ! $pairs ) {
			return new WP_Error(
				'tkvault_undo_missing',
				__( 'None of the saved copies are still there, so the restore cannot be undone.', 'takumi-vault' )
			);
		}

		$wpdb->suppress_errors( true );
		$ok = $wpdb->query( 'RENAME TABLE ' . implode( ', ', $pairs ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL
		$wpdb->suppress_errors( false );

		if ( false === $ok ) {
			return new WP_Error( 'tkvault_undo_failed', $wpdb->last_error );
		}

		foreach ( $olds as $entry ) {
			if ( self::table_exists( $entry['original'] . '_undone' ) ) {
				$wpdb->query( 'DROP TABLE ' . TKVault_DB_Dump::ident( $entry['original'] . '_undone' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL
			}
		}

		delete_option( self::OPTION_OLD_TABLES );
		wp_cache_flush();

		return true;
	}

	public static function retention_days() {
		$days = get_option( self::OPTION_RETENTION, 7 );
		return is_numeric( $days ) ? (int) $days : 7;
	}

	/**
	 * Drop displaced tables once the retention period has passed.
	 *
	 * They are a full second copy of the database, so keeping them for ever by
	 * default would quietly double the size of every site that ever restored.
	 */
	public static function drop_expired_old_tables() {
		global $wpdb;

		$days = self::retention_days();
		if ( $days < 0 ) {
			return; // -1 means keep indefinitely.
		}

		$cutoff = time() - ( $days * DAY_IN_SECONDS );
		$olds   = (array) get_option( self::OPTION_OLD_TABLES, array() );
		$keep   = array();

		foreach ( $olds as $entry ) {
			if ( (int) $entry['created'] > $cutoff ) {
				$keep[] = $entry;
				continue;
			}
			if ( self::table_exists( $entry['old'] ) ) {
				$wpdb->query( 'DROP TABLE ' . TKVault_DB_Dump::ident( $entry['old'] ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL
			}
		}

		if ( $keep ) {
			update_option( self::OPTION_OLD_TABLES, $keep, false );
		} else {
			delete_option( self::OPTION_OLD_TABLES );
		}
	}

	public static function schedule_cleanup() {
		if ( ! wp_next_scheduled( self::CLEANUP_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::CLEANUP_HOOK );
		}
	}

	public static function clear_cleanup() {
		$timestamp = wp_next_scheduled( self::CLEANUP_HOOK );
		if ( $timestamp ) {
			wp_unschedule_event( $timestamp, self::CLEANUP_HOOK );
		}
	}
}
