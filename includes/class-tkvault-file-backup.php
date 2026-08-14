<?php
/**
 * File backup, as a job handler.
 *
 * Three shapes of problem decide how this works.
 *
 * THE FILE LIST DOES NOT GO IN THE JOB STATE. A site with 200,000 uploads
 * produces a list of megabytes, and the runner rewrites the job state after
 * every chunk - which would turn the walk into quadratic writes against the
 * database. The list is written to a file next to the archive and read back by
 * byte offset, the same way the database dump is.
 *
 * THE WALK IS RESUMABLE. RecursiveDirectoryIterator cannot be stopped and
 * picked up again, so the scan keeps its own stack of directories still to
 * visit. That also stops a huge tree from spending the whole time budget
 * before a single file has been archived.
 *
 * ZipArchive DOES THE WORK AT close(). addFile() only records a path, so a
 * chunk opens the archive, registers a bounded batch, and closes it - which is
 * both where the bytes are written and where a crash would otherwise lose
 * everything registered since the last close.
 *
 * @package TakumiVault
 */

defined( 'ABSPATH' ) || exit;

class TKVault_File_Backup {

	const TYPE = 'file_backup';

	/** Directories visited per chunk while walking. */
	const SCAN_BATCH = 150;

	/** Files added per chunk, and the byte budget that also caps it. */
	const ADD_BATCH = 300;
	const ADD_BYTES = 33554432; // 32 MB

	/** Start a new volume past this size. */
	const VOLUME_BYTES = 2147483648; // 2 GB

	const OPTION_LAST_MANIFEST = 'tkvault_last_file_manifest';

	public static function init() {
		add_filter( 'tkvault_job_handlers', array( __CLASS__, 'register_handler' ) );
		add_action( 'tkvault_job_cancelled', array( __CLASS__, 'clean_up_cancelled' ) );
	}

	public static function register_handler( $handlers ) {
		$handlers[ self::TYPE ] = array( __CLASS__, 'run_chunk' );
		return $handlers;
	}

	/**
	 * Queue a file backup.
	 *
	 * @param string $note
	 * @param bool   $full Force a full backup instead of an incremental one.
	 * @return array{id:int, secret:string}|WP_Error
	 */
	public static function start( $note = '', $full = false ) {
		$store = TKVault_Storage::get_store_dir();
		if ( ! $store || ! is_dir( $store ) ) {
			return new WP_Error( 'tkvault_no_store', __( 'No backup destination is configured.', 'takumi-vault' ) );
		}

		return TKVault_Jobs::create(
			self::TYPE,
			array(
				'note'      => (string) $note,
				'full'      => (bool) $full,
				'timestamp' => gmdate( 'Y-m-d_His' ),
			),
			0
		);
	}

	/* --------------------------------------------------------------- */

	public static function run_chunk( array $state, array $payload, $job_id = 0 ) {
		$phase = empty( $state['phase'] ) ? 'begin' : $state['phase'];

		switch ( $phase ) {
			case 'begin':
				return self::begin( $payload );
			case 'scan':
				return self::scan( $state );
			case 'archive':
				return self::archive( $state );
			default:
				return self::finalise( $state, $payload );
		}
	}

	private static function begin( array $payload ) {
		$store = TKVault_Storage::get_store_dir();
		if ( ! $store || ! is_dir( $store ) ) {
			throw new TKVault_Job_Fatal( esc_html__( 'The backup destination is missing.', 'takumi-vault' ) );
		}

		$root = wp_normalize_path( untrailingslashit( WP_CONTENT_DIR ) );
		if ( ! is_dir( $root ) ) {
			throw new TKVault_Job_Fatal( esc_html__( 'The wp-content directory could not be found.', 'takumi-vault' ) );
		}

		$timestamp = isset( $payload['timestamp'] ) ? $payload['timestamp'] : gmdate( 'Y-m-d_His' );
		$base      = TKVault_Storage::unique_base( $timestamp . '_files' );

		$previous = empty( $payload['full'] ) ? self::previous_manifest() : null;

		return array(
			'state'     => array(
				'phase'      => 'scan',
				'base'       => $base,
				'root'       => $root,
				'list_file'  => trailingslashit( $store ) . $base . '.list',
				'dirs'       => array( $root ),
				'excluded'   => self::exclusions(),
				'mode'       => $previous ? 'incremental' : 'full',
				'parent'     => $previous ? $previous['base'] : null,
				'previous'   => $previous ? $previous['files'] : array(),
				'seen'       => 0,
				'queued'     => 0,
				'unchanged'  => 0,
				'bytes'      => 0,
				'format'     => class_exists( 'ZipArchive' ) ? 'zip' : 'tar.gz',
			),
			'processed' => 0,
			'done'      => false,
		);
	}

	/**
	 * What never goes into a backup.
	 *
	 * The destination is matched by resolved path, not by name. It carries a
	 * random suffix, and on the uploads fallback it sits inside the very tree
	 * being walked - so a name comparison would let the backup swallow itself.
	 */
	public static function exclusions() {
		$paths = array();

		$destination = TKVault_Storage::get_dir();
		if ( $destination ) {
			$real = realpath( $destination );
			if ( $real ) {
				$paths[] = wp_normalize_path( untrailingslashit( $real ) );
			}
		}

		return apply_filters(
			'tkvault_file_exclusions',
			array(
				'paths' => $paths,
				// Regenerable, or someone else's copy of the same site.
				'names' => array(
					'cache',
					'node_modules',
					'.git',
					'.svn',
					'ai1wm-backups',
					'updraft',
					'backwpup',
					'wpvivid',
					'backups',
					'upgrade',
					'upgrade-temp-backup',
				),
				'suffixes' => array( '.log' ),
			)
		);
	}

	private static function is_excluded( $path, $name, array $rules ) {
		if ( in_array( $name, $rules['names'], true ) ) {
			return true;
		}

		foreach ( $rules['suffixes'] as $suffix ) {
			if ( substr( $name, -strlen( $suffix ) ) === $suffix ) {
				return true;
			}
		}

		foreach ( $rules['paths'] as $excluded ) {
			if ( $path === $excluded || 0 === strpos( $path . '/', $excluded . '/' ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Walk part of the tree, appending to the list file.
	 */
	private static function scan( array $state ) {
		$handle = fopen( $state['list_file'], 'a' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		if ( ! $handle ) {
			throw new TKVault_Job_Fatal( esc_html__( 'Could not write the file list.', 'takumi-vault' ) );
		}

		$rules     = $state['excluded'];
		$previous  = $state['previous'];
		$visited   = 0;
		$queued    = 0;

		while ( $state['dirs'] && $visited < self::SCAN_BATCH ) {
			$dir     = array_pop( $state['dirs'] );
			$entries = @scandir( $dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			$visited++;

			if ( false === $entries ) {
				continue;
			}

			foreach ( $entries as $entry ) {
				if ( '.' === $entry || '..' === $entry ) {
					continue;
				}

				$path = $dir . '/' . $entry;

				if ( self::is_excluded( $path, $entry, $rules ) ) {
					continue;
				}

				if ( is_link( $path ) ) {
					// Following symlinks can walk out of wp-content entirely,
					// or in a circle. Record nothing and move on.
					continue;
				}

				if ( is_dir( $path ) ) {
					$state['dirs'][] = $path;
					continue;
				}

				$size  = (int) @filesize( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
				$mtime = (int) @filemtime( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
				$rel   = ltrim( substr( $path, strlen( $state['root'] ) ), '/' );

				$state['seen']++;

				// Unchanged since the previous backup: recorded, not archived.
				$known = isset( $previous[ $rel ] ) ? $previous[ $rel ] : null;
				if ( $known && (int) $known[0] === $size && (int) $known[1] === $mtime ) {
					$state['unchanged']++;
					fwrite( $handle, wp_json_encode( array( $rel, $size, $mtime, 0 ) ) . "\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
					continue;
				}

				$state['bytes'] += $size;
				$queued++;
				fwrite( $handle, wp_json_encode( array( $rel, $size, $mtime, 1 ) ) . "\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
			}
		}

		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose

		$state['queued'] += $queued;

		if ( ! $state['dirs'] ) {
			$state['phase']   = 'archive';
			$state['offset']  = 0;
			$state['added']   = 0;
			$state['volume']  = 1;
			$state['volumes'] = array();
			$state['deleted'] = self::deleted_since( $previous, $state['list_file'] );

			// The previous list is only needed while scanning, and keeping it
			// makes every later state write carry the whole thing.
			$state['previous'] = array();
		}

		return array(
			'state'     => $state,
			'processed' => 0,
			'done'      => false,
			'total'     => (int) $state['seen'],
		);
	}

	/**
	 * Files the previous backup knew about that are no longer there.
	 */
	private static function deleted_since( array $previous, $list_file ) {
		if ( ! $previous ) {
			return array();
		}

		$present = array();
		$handle  = fopen( $list_file, 'r' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		if ( $handle ) {
			while ( false !== ( $line = fgets( $handle ) ) ) {
				$row = json_decode( $line, true );
				if ( is_array( $row ) ) {
					$present[ $row[0] ] = true;
				}
			}
			fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		}

		return array_values( array_diff( array_keys( $previous ), array_keys( $present ) ) );
	}

	/**
	 * Add a bounded batch of files to the current volume.
	 */
	private static function archive( array $state ) {
		$store  = TKVault_Storage::get_store_dir();
		$handle = fopen( $state['list_file'], 'r' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		if ( ! $handle ) {
			throw new TKVault_Job_Fatal( esc_html__( 'Could not read the file list.', 'takumi-vault' ) );
		}
		fseek( $handle, (int) $state['offset'] );

		$batch    = array();
		$bytes    = 0;
		$max_files = (int) apply_filters( 'tkvault_add_batch', self::ADD_BATCH );

		while ( count( $batch ) < $max_files && $bytes < self::ADD_BYTES ) {
			$line = fgets( $handle );
			if ( false === $line ) {
				break;
			}

			$row = json_decode( $line, true );
			if ( ! is_array( $row ) || empty( $row[3] ) ) {
				continue; // Unchanged files are listed but not archived.
			}

			$batch[] = $row;
			$bytes  += (int) $row[1];
		}

		$state['offset'] = ftell( $handle );
		$eof             = feof( $handle );
		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose

		if ( $batch ) {
			$volume = self::volume_path( $store, $state['base'], (int) $state['volume'], $state['format'] );
			self::add_batch( $volume, $batch, $state['root'], $state['format'] );
			$state['added'] += count( $batch );

			/**
			 * Filters the size at which a new archive volume is started.
			 *
			 * @param int $bytes Volume threshold.
			 */
			$threshold = (int) apply_filters( 'tkvault_volume_bytes', self::VOLUME_BYTES );

			// Split once the volume is large, so a single archive never grows
			// past what the host's filesystem or the user's tools can handle.
			if ( filesize( $volume ) >= $threshold ) {
				$state['volumes'][] = $volume;
				$state['volume']++;
			}
		}

		if ( $eof ) {
			$current = self::volume_path( $store, $state['base'], (int) $state['volume'], $state['format'] );
			if ( file_exists( $current ) && ! in_array( $current, $state['volumes'], true ) ) {
				$state['volumes'][] = $current;
			}
			$state['phase'] = 'finalise';
		}

		return array(
			'state'     => $state,
			'processed' => count( $batch ),
			'done'      => false,
		);
	}

	private static function volume_path( $store, $base, $volume, $format ) {
		$suffix = $volume > 1 ? sprintf( '_part%d', $volume ) : '';
		return trailingslashit( $store ) . $base . $suffix . ( 'zip' === $format ? '.zip' : '.tar.gz' );
	}

	/**
	 * Write one batch into the archive and close it again.
	 */
	private static function add_batch( $archive, array $batch, $root, $format ) {
		if ( 'zip' === $format ) {
			$zip = new ZipArchive();
			if ( true !== $zip->open( $archive, ZipArchive::CREATE ) ) {
				throw new TKVault_Job_Fatal( esc_html__( 'Could not open the archive for writing.', 'takumi-vault' ) );
			}

			foreach ( $batch as $row ) {
				$path = $root . '/' . $row[0];
				if ( is_readable( $path ) ) {
					$zip->addFile( $path, $row[0] );
				}
			}

			// close() is where the files are actually read and written, and it
			// is also the point the batching exists to bound.
			if ( ! $zip->close() ) {
				throw new TKVault_Job_Fatal( esc_html__( 'The archive could not be written.', 'takumi-vault' ) );
			}

			return;
		}

		self::add_batch_tar( $archive, $batch, $root );
	}

	/**
	 * PharData fallback for hosts without ZipArchive.
	 *
	 * Kept uncompressed while it is being built and gzipped once at the end:
	 * PharData cannot append to a compressed archive, and recompressing the
	 * whole tar on every chunk would be quadratic.
	 */
	private static function add_batch_tar( $archive, array $batch, $root ) {
		$tar = preg_replace( '/\.tar\.gz$/', '.tar', $archive );

		try {
			$phar = new PharData( $tar );
			foreach ( $batch as $row ) {
				$path = $root . '/' . $row[0];
				if ( is_readable( $path ) ) {
					$phar->addFile( $path, $row[0] );
				}
			}
		} catch ( Exception $e ) {
			throw new TKVault_Job_Fatal( esc_html( $e->getMessage() ) );
		}
	}

	/**
	 * Compress the tar volumes, hash everything, write the manifest.
	 */
	private static function finalise( array $state, array $payload ) {
		$store = TKVault_Storage::get_store_dir();

		if ( 'zip' !== $state['format'] ) {
			$state['volumes'] = self::compress_tars( $state['volumes'] );
		}

		$volumes = array();
		$total   = 0;
		foreach ( $state['volumes'] as $volume ) {
			if ( ! file_exists( $volume ) ) {
				continue;
			}
			$size    = (int) filesize( $volume );
			$total  += $size;
			$volumes[] = array(
				'name'   => basename( $volume ),
				'size'   => $size,
				'sha256' => hash_file( 'sha256', $volume ),
			);
		}

		if ( ! $volumes && $state['queued'] > 0 ) {
			throw new TKVault_Job_Fatal( esc_html__( 'No archive was produced even though there were files to back up.', 'takumi-vault' ) );
		}

		$manifest_file = trailingslashit( $store ) . $state['base'] . '_manifest.json';
		$manifest      = self::manifest( $state, $volumes, $total );

		if ( false === file_put_contents( $manifest_file, wp_json_encode( $manifest, JSON_UNESCAPED_SLASHES ) ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			throw new TKVault_Job_Fatal( esc_html__( 'Could not write the backup manifest.', 'takumi-vault' ) );
		}

		// The list file was scratch space for this run only.
		if ( file_exists( $state['list_file'] ) ) {
			wp_delete_file( $state['list_file'] );
		}

		TKVault_DB::insert_backup(
			array(
				'filename' => $volumes ? $volumes[0]['name'] : $state['base'] . '_manifest.json',
				'type'     => 'files',
				'size'     => $total,
				'status'   => 'completed',
				'note'     => isset( $payload['note'] ) ? $payload['note'] : '',
			)
		);

		update_option( self::OPTION_LAST_MANIFEST, basename( $manifest_file ), false );

		$state['phase'] = 'complete';

		return array(
			'state'     => $state,
			'processed' => 0,
			'done'      => true,
		);
	}

	private static function compress_tars( array $volumes ) {
		$out = array();

		foreach ( $volumes as $volume ) {
			$tar = preg_replace( '/\.tar\.gz$/', '.tar', $volume );
			if ( ! file_exists( $tar ) ) {
				$out[] = $volume;
				continue;
			}

			try {
				$phar = new PharData( $tar );
				$phar->compress( Phar::GZ );
				unset( $phar );
				wp_delete_file( $tar );
				$out[] = $volume;
			} catch ( Exception $e ) {
				throw new TKVault_Job_Fatal( esc_html( $e->getMessage() ) );
			}
		}

		return $out;
	}

	/**
	 * The manifest doubles as the input to the next incremental backup, so it
	 * lists every file present, not only the ones in this archive.
	 */
	private static function manifest( array $state, array $volumes, $total ) {
		$files = array();

		$handle = fopen( $state['list_file'], 'r' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		if ( $handle ) {
			while ( false !== ( $line = fgets( $handle ) ) ) {
				$row = json_decode( $line, true );
				if ( is_array( $row ) ) {
					$files[ $row[0] ] = array( (int) $row[1], (int) $row[2], (int) $row[3] );
				}
			}
			fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		}

		$manifest = array(
			'format'    => 1,
			'plugin'    => 'takumi-vault',
			'kind'      => 'files',
			'version'   => TKVAULT_VERSION,
			'created'   => gmdate( 'c' ),
			'site_url'  => site_url(),
			'root'      => $state['root'],
			'mode'      => $state['mode'],
			'parent'    => $state['parent'],
			'archiver'  => $state['format'],
			'counts'    => array(
				'present'   => (int) $state['seen'],
				'archived'  => (int) $state['added'],
				'unchanged' => (int) $state['unchanged'],
				'deleted'   => count( $state['deleted'] ),
				'bytes'     => (int) $total,
			),
			'volumes'   => $volumes,
			'deleted'   => $state['deleted'],
			'files'     => $files,
		);

		$manifest['self_sha256'] = hash( 'sha256', wp_json_encode( $manifest ) );

		return $manifest;
	}

	/**
	 * The manifest of the most recent successful file backup, if any.
	 *
	 * @return array{base:string, files:array}|null
	 */
	public static function previous_manifest() {
		$name = get_option( self::OPTION_LAST_MANIFEST, '' );
		if ( ! $name ) {
			return null;
		}

		$path = trailingslashit( TKVault_Storage::get_store_dir() ) . basename( $name );
		if ( ! file_exists( $path ) ) {
			return null;
		}

		$manifest = json_decode( (string) file_get_contents( $path ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		if ( ! is_array( $manifest ) || empty( $manifest['files'] ) ) {
			return null;
		}

		return array(
			'base'  => preg_replace( '/_manifest\.json$/', '', basename( $name ) ),
			'files' => $manifest['files'],
		);
	}

	/**
	 * A cancelled run leaves no archive that could be mistaken for a backup.
	 */
	public static function clean_up_cancelled( $job ) {
		if ( ! $job || self::TYPE !== $job->type ) {
			return;
		}

		$state = TKVault_Jobs::decode( $job->state );
		if ( empty( $state['base'] ) ) {
			return;
		}

		$store = TKVault_Storage::get_store_dir();
		if ( ! $store ) {
			return;
		}

		foreach ( (array) glob( trailingslashit( $store ) . $state['base'] . '*' ) as $path ) {
			if ( is_file( $path ) ) {
				wp_delete_file( $path );
			}
		}
	}
}
