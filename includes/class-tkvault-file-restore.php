<?php
/**
 * File restore, as a job handler.
 *
 *   verify   resolve the chain back to a full backup and check every archive
 *   space    refuse to start if the extraction cannot fit
 *   extract  apply full, then each incremental, in order
 *   finalise report what was replaced and what was left alone
 *
 * Two decisions are worth reading before changing anything.
 *
 * THE PLUGIN DOES NOT RESTORE OVER ITSELF. Its own directory lives inside
 * wp-content, so a restore would replace the PHP files of the code that is
 * running, mid-job, and the next chunk would load a mixture of two versions.
 * The same reasoning kept the job table out of the database restore.
 *
 * NOTHING IS DELETED. Files that exist now but are absent from the backup are
 * reported, not removed. Deleting them would make this a true point-in-time
 * restore, and that is the right default for someone cleaning up after an
 * intrusion - but it also throws away everything uploaded since the backup,
 * which is the more common situation. Until there is a UI that makes the
 * choice explicit, the safe half is the one that ships.
 *
 * @package TakumiVault
 */

defined( 'ABSPATH' ) || exit;

class TKVault_File_Restore {

	const TYPE = 'file_restore';

	/** Entries extracted per chunk. */
	const BATCH = 200;

	/** Where the files being replaced are kept so this can be undone. */
	const REPLACED_DIR = 'replaced';

	const OPTION_REPLACED = 'tkvault_replaced_files';

	public static function init() {
		add_filter( 'tkvault_job_handlers', array( __CLASS__, 'register_handler' ) );
	}

	public static function register_handler( $handlers ) {
		$handlers[ self::TYPE ] = array( __CLASS__, 'run_chunk' );
		return $handlers;
	}

	/**
	 * @return array{id:int, secret:string}|WP_Error
	 */
	public static function start( $backup_id ) {
		$record = TKVault_DB::get_backup_by_id( (int) $backup_id );
		if ( ! $record ) {
			return new WP_Error( 'tkvault_no_backup', __( 'That backup does not exist.', 'takumi-vault' ) );
		}

		$manifest = self::manifest_for_volume( $record->filename );
		if ( is_wp_error( $manifest ) ) {
			return $manifest;
		}

		return TKVault_Jobs::create(
			self::TYPE,
			array(
				'backup_id' => (int) $backup_id,
				'base'      => $manifest,
			),
			0
		);
	}

	/**
	 * The manifest base name that owns a given volume file.
	 *
	 * @return string|WP_Error
	 */
	private static function manifest_for_volume( $filename ) {
		$store = TKVault_Storage::get_store_dir();
		$base  = preg_replace( '/(_part\d+)?\.(zip|tar\.gz)$/', '', basename( $filename ) );

		if ( ! $base || ! file_exists( trailingslashit( $store ) . $base . '_manifest.json' ) ) {
			return new WP_Error( 'tkvault_no_manifest', __( 'This backup has no manifest, so it cannot be verified before restoring.', 'takumi-vault' ) );
		}

		return $base;
	}

	/* --------------------------------------------------------------- */

	public static function run_chunk( array $state, array $payload, $job_id = 0 ) {
		$phase = empty( $state['phase'] ) ? 'verify' : $state['phase'];

		switch ( $phase ) {
			case 'verify':
				return self::verify( $payload );
			case 'space':
				return self::check_space( $state );
			case 'extract':
				return self::extract( $state );
			default:
				return self::finalise( $state );
		}
	}

	/**
	 * Walk the chain back to a full backup and check every archive in it.
	 */
	private static function verify( array $payload ) {
		$store = TKVault_Storage::get_store_dir();
		$chain = array();
		$base  = $payload['base'];
		$seen  = array();

		while ( $base ) {
			if ( isset( $seen[ $base ] ) ) {
				throw new TKVault_Job_Fatal( esc_html__( 'The backup chain refers back to itself.', 'takumi-vault' ) );
			}
			$seen[ $base ] = true;

			$manifest = self::read_manifest( $base );
			if ( is_wp_error( $manifest ) ) {
				throw new TKVault_Job_Fatal( esc_html( $manifest->get_error_message() ) );
			}

			array_unshift( $chain, $manifest );
			$base = isset( $manifest['parent'] ) ? $manifest['parent'] : null;
		}

		if ( ! $chain || 'full' !== $chain[0]['mode'] ) {
			throw new TKVault_Job_Fatal( esc_html__( 'The chain does not start with a full backup, so it cannot be restored.', 'takumi-vault' ) );
		}

		$entries = 0;
		$bytes   = 0;

		foreach ( $chain as $manifest ) {
			foreach ( $manifest['volumes'] as $volume ) {
				$path = trailingslashit( $store ) . $volume['name'];

				if ( ! file_exists( $path ) ) {
					throw new TKVault_Job_Fatal(
						sprintf(
							/* translators: %s: archive file name */
							esc_html__( 'The archive %s is missing, so the chain is incomplete.', 'takumi-vault' ),
							esc_html( $volume['name'] )
						)
					);
				}

				if ( ! hash_equals( $volume['sha256'], (string) hash_file( 'sha256', $path ) ) ) {
					throw new TKVault_Job_Fatal(
						sprintf(
							/* translators: %s: archive file name */
							esc_html__( 'The archive %s does not match the checksum recorded when it was created. It is damaged or was modified.', 'takumi-vault' ),
							esc_html( $volume['name'] )
						)
					);
				}

				$measured = self::measure( $path );
				$entries += $measured['entries'];
				$bytes   += $measured['bytes'];
			}
		}

		return array(
			'state'     => array(
				'phase'     => 'space',
				'chain'     => array_map( array( __CLASS__, 'slim' ), $chain ),
				'entries'   => $entries,
				'bytes'     => $bytes,
				'link'      => 0,
				'volume'    => 0,
				'offset'    => 0,
				'restored'  => 0,
				'replaced'  => 0,
				'skipped'   => 0,
				'base'      => $payload['base'],
				'warnings'  => array(),
			),
			'processed' => 0,
			'done'      => false,
			'total'     => $entries,
		);
	}

	/**
	 * The chain is carried in the job state, so drop everything the extract
	 * phase does not read. A full file list can be megabytes.
	 */
	private static function slim( array $manifest ) {
		return array(
			'mode'     => $manifest['mode'],
			'archiver' => isset( $manifest['archiver'] ) ? $manifest['archiver'] : 'zip',
			'volumes'  => wp_list_pluck( $manifest['volumes'], 'name' ),
		);
	}

	private static function read_manifest( $base ) {
		$path = trailingslashit( TKVault_Storage::get_store_dir() ) . $base . '_manifest.json';

		if ( ! file_exists( $path ) ) {
			return new WP_Error(
				'tkvault_missing_link',
				sprintf(
					/* translators: %s: manifest file name */
					__( 'The manifest %s is missing, so the chain cannot be followed back to a full backup.', 'takumi-vault' ),
					basename( $path )
				)
			);
		}

		$manifest = json_decode( (string) file_get_contents( $path ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		if ( ! is_array( $manifest ) || empty( $manifest['volumes'] ) ) {
			return new WP_Error( 'tkvault_bad_manifest', __( 'A manifest in the chain could not be read.', 'takumi-vault' ) );
		}

		$claimed = isset( $manifest['self_sha256'] ) ? $manifest['self_sha256'] : '';
		$copy    = $manifest;
		unset( $copy['self_sha256'] );

		if ( ! $claimed || ! hash_equals( $claimed, hash( 'sha256', wp_json_encode( $copy ) ) ) ) {
			return new WP_Error( 'tkvault_manifest_altered', __( 'A manifest in the chain does not match its own checksum.', 'takumi-vault' ) );
		}

		return $manifest;
	}

	/**
	 * Uncompressed size and entry count of an archive.
	 */
	private static function measure( $path ) {
		if ( substr( $path, -4 ) === '.zip' ) {
			$zip = new ZipArchive();
			if ( true !== $zip->open( $path ) ) {
				throw new TKVault_Job_Fatal( esc_html__( 'An archive in the chain could not be opened.', 'takumi-vault' ) );
			}

			$bytes = 0;
			for ( $i = 0; $i < $zip->numFiles; $i++ ) {
				$stat   = $zip->statIndex( $i );
				$bytes += isset( $stat['size'] ) ? (int) $stat['size'] : 0;
			}
			$count = $zip->numFiles;
			$zip->close();

			return array(
				'entries' => $count,
				'bytes'   => $bytes,
			);
		}

		$entries = 0;
		$bytes   = 0;
		try {
			foreach ( new RecursiveIteratorIterator( new PharData( $path ) ) as $file ) {
				$entries++;
				$bytes += (int) $file->getSize();
			}
		} catch ( Exception $e ) {
			throw new TKVault_Job_Fatal( esc_html( $e->getMessage() ) );
		}

		return array(
			'entries' => $entries,
			'bytes'   => $bytes,
		);
	}

	private static function check_space( array $state ) {
		$free = function_exists( 'disk_free_space' ) ? @disk_free_space( WP_CONTENT_DIR ) : false; // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged

		if ( false === $free ) {
			$state['warnings'][] = __( 'Free space could not be measured before restoring.', 'takumi-vault' );
		} elseif ( $free < (int) $state['bytes'] ) {
			throw new TKVault_Job_Fatal(
				sprintf(
					/* translators: 1: space needed, 2: space available */
					esc_html__( 'Not enough room to restore: %1$s is needed and %2$s is free.', 'takumi-vault' ),
					esc_html( size_format( (int) $state['bytes'] ) ),
					esc_html( size_format( $free ) )
				)
			);
		}

		$state['phase'] = 'extract';

		return array(
			'state'     => $state,
			'processed' => 0,
			'done'      => false,
		);
	}

	/**
	 * Extract a bounded slice of the current volume.
	 */
	private static function extract( array $state ) {
		$store = TKVault_Storage::get_store_dir();
		$root  = wp_normalize_path( untrailingslashit( WP_CONTENT_DIR ) );

		$link = (int) $state['link'];
		if ( ! isset( $state['chain'][ $link ] ) ) {
			$state['phase'] = 'finalise';
			return array(
				'state'     => $state,
				'processed' => 0,
				'done'      => false,
			);
		}

		$volumes = $state['chain'][ $link ]['volumes'];
		$index   = (int) $state['volume'];

		if ( ! isset( $volumes[ $index ] ) ) {
			$state['link']++;
			$state['volume'] = 0;
			$state['offset'] = 0;
			return array(
				'state'     => $state,
				'processed' => 0,
				'done'      => false,
			);
		}

		$path = trailingslashit( $store ) . $volumes[ $index ];
		$done = self::extract_slice( $path, $root, $state );

		if ( $done ) {
			$state['volume']++;
			$state['offset'] = 0;
		}

		return array(
			'state'     => $state,
			'processed' => (int) $state['restored'] - (int) $state['processed_mark'],
			'done'      => false,
		);
	}

	/**
	 * @return bool True when this volume is finished.
	 */
	private static function extract_slice( $path, $root, array &$state ) {
		$state['processed_mark'] = (int) $state['restored'];

		if ( substr( $path, -4 ) !== '.zip' ) {
			return self::extract_tar( $path, $root, $state );
		}

		$zip = new ZipArchive();
		if ( true !== $zip->open( $path ) ) {
			throw new TKVault_Job_Fatal( esc_html__( 'An archive in the chain could not be opened.', 'takumi-vault' ) );
		}

		$offset = (int) $state['offset'];
		$names  = array();
		$limit  = min( $zip->numFiles, $offset + (int) apply_filters( 'tkvault_restore_file_batch', self::BATCH ) );

		for ( $i = $offset; $i < $limit; $i++ ) {
			$name = $zip->getNameIndex( $i );

			if ( ! self::is_safe_entry( $name ) ) {
				$state['skipped']++;
				continue;
			}
			if ( self::is_own_file( $root . '/' . $name ) ) {
				$state['skipped']++;
				continue;
			}

			self::displace( $root . '/' . $name, $name, $state );
			$names[] = $name;
		}

		if ( $names && ! $zip->extractTo( $root, $names ) ) {
			$zip->close();
			throw new TKVault_Job_Fatal( esc_html__( 'The archive could not be extracted.', 'takumi-vault' ) );
		}

		$state['restored'] += count( $names );
		$state['offset']    = $limit;
		$finished           = $limit >= $zip->numFiles;

		$zip->close();

		return $finished;
	}

	/**
	 * PharData has no partial extract, so a tar volume is done in one go.
	 * Volumes are size-capped, which is what keeps that bounded.
	 */
	private static function extract_tar( $path, $root, array &$state ) {
		try {
			$phar  = new PharData( $path );
			$names = array();

			foreach ( new RecursiveIteratorIterator( $phar ) as $file ) {
				$name = ltrim( str_replace( 'phar://' . $path, '', $file->getPathname() ), '/' );

				if ( ! self::is_safe_entry( $name ) || self::is_own_file( $root . '/' . $name ) ) {
					$state['skipped']++;
					continue;
				}

				self::displace( $root . '/' . $name, $name, $state );
				$names[] = $name;
			}

			if ( $names ) {
				$phar->extractTo( $root, $names, true );
			}

			$state['restored'] += count( $names );
		} catch ( Exception $e ) {
			throw new TKVault_Job_Fatal( esc_html( $e->getMessage() ) );
		}

		return true;
	}

	/**
	 * Reject anything that would write outside wp-content.
	 *
	 * Archive entries are attacker-controlled the moment a backup file is:
	 * "../../wp-config.php" in a zip is the classic path traversal, and
	 * extractTo() will happily follow it.
	 */
	public static function is_safe_entry( $name ) {
		if ( '' === $name || '/' === $name[0] ) {
			return false;
		}
		if ( false !== strpos( $name, '../' ) || false !== strpos( $name, '..\\' ) ) {
			return false;
		}
		if ( preg_match( '#^[a-zA-Z]:#', $name ) ) {
			return false; // Absolute Windows path.
		}

		return true;
	}

	/**
	 * Whether a path belongs to this plugin.
	 *
	 * Replacing our own files while a job is running would leave the next
	 * chunk executing a mixture of two versions of this code.
	 */
	public static function is_own_file( $path ) {
		$own = wp_normalize_path( untrailingslashit( TKVAULT_PLUGIN_DIR ) );
		$path = wp_normalize_path( $path );

		return $path === $own || 0 === strpos( $path, $own . '/' );
	}

	/**
	 * Move the file that is about to be overwritten somewhere safe.
	 *
	 * Only files actually being replaced are kept, so the cost is bounded by
	 * the size of the restore rather than the size of the site.
	 */
	private static function displace( $path, $relative, array &$state ) {
		if ( ! file_exists( $path ) ) {
			return;
		}

		$staging = trailingslashit( TKVault_Storage::get_store_dir() ) . $state['base'] . '_' . self::REPLACED_DIR;
		$target  = trailingslashit( $staging ) . $relative;

		if ( ! wp_mkdir_p( dirname( $target ) ) ) {
			return;
		}

		if ( TKVault_Storage::filesystem()->move( $path, $target, true ) ) {
			$state['replaced']++;
		}
	}

	private static function finalise( array $state ) {
		$staging = trailingslashit( TKVault_Storage::get_store_dir() ) . $state['base'] . '_' . self::REPLACED_DIR;

		if ( is_dir( $staging ) ) {
			update_option(
				self::OPTION_REPLACED,
				array(
					'dir'      => $staging,
					'created'  => time(),
					'count'    => (int) $state['replaced'],
				),
				false
			);
		}

		$state['phase'] = 'complete';

		return array(
			'state'     => $state,
			'processed' => 0,
			'done'      => true,
		);
	}

	/**
	 * Put the replaced files back.
	 *
	 * @return true|WP_Error
	 */
	public static function undo() {
		$record = get_option( self::OPTION_REPLACED );
		if ( ! is_array( $record ) || empty( $record['dir'] ) || ! is_dir( $record['dir'] ) ) {
			return new WP_Error( 'tkvault_nothing_to_undo', __( 'There is no file restore to undo.', 'takumi-vault' ) );
		}

		if ( ! self::is_staging_dir( $record['dir'] ) ) {
			delete_option( self::OPTION_REPLACED );
			return new WP_Error(
				'tkvault_undo_stale',
				__( 'The record of replaced files does not point at this site\'s backup folder, so it was discarded rather than acted on.', 'takumi-vault' )
			);
		}

		$root    = wp_normalize_path( untrailingslashit( WP_CONTENT_DIR ) );
		$staging = wp_normalize_path( untrailingslashit( $record['dir'] ) );
		$moved   = 0;

		$items = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $staging, RecursiveDirectoryIterator::SKIP_DOTS ),
			RecursiveIteratorIterator::SELF_FIRST
		);

		foreach ( $items as $item ) {
			if ( $item->isDir() ) {
				continue;
			}

			$relative = ltrim( substr( wp_normalize_path( $item->getPathname() ), strlen( $staging ) ), '/' );
			$target   = $root . '/' . $relative;

			if ( ! self::is_safe_entry( $relative ) ) {
				continue;
			}

			wp_mkdir_p( dirname( $target ) );
			if ( TKVault_Storage::filesystem()->move( $item->getPathname(), $target, true ) ) {
				$moved++;
			}
		}

		self::remove_tree( $staging );
		delete_option( self::OPTION_REPLACED );

		return true;
	}

	public static function replaced_count() {
		$record = get_option( self::OPTION_REPLACED );
		return is_array( $record ) && isset( $record['count'] ) ? (int) $record['count'] : 0;
	}

	/**
	 * Refuse to touch anything that is not one of our own staging directories.
	 *
	 * remove_tree() deletes recursively, and the path it is handed comes out
	 * of an option. A restore can roll wp_options back to an older row - that
	 * is exactly how a stale tkvault_old_tables once slipped through - so the
	 * path is re-derived and checked rather than trusted.
	 *
	 * @param string $dir Directory to test.
	 * @return bool
	 */
	private static function is_staging_dir( $dir ) {
		$store = TKVault_Storage::get_store_dir();
		if ( ! $store ) {
			return false;
		}

		$store = wp_normalize_path( untrailingslashit( $store ) );
		$dir   = wp_normalize_path( untrailingslashit( $dir ) );

		// Directly inside the store, and named like a staging directory.
		return dirname( $dir ) === $store
			&& (bool) preg_match( '/_' . preg_quote( self::REPLACED_DIR, '/' ) . '$/', basename( $dir ) );
	}

	private static function remove_tree( $dir ) {
		if ( ! is_dir( $dir ) || ! self::is_staging_dir( $dir ) ) {
			return;
		}

		TKVault_Storage::filesystem()->delete( $dir, true );
	}
}
