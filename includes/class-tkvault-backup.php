<?php
defined( 'ABSPATH' ) || exit;

class TKVault_Backup {

	public function run( string $type = 'full', string $note = '' ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			return new WP_Error( 'forbidden', __( 'You do not have permission to do that.', 'takumi-vault' ) );
		}

		$backup_dir = tkvault_ensure_backup_dir();
		if ( is_wp_error( $backup_dir ) ) {
			return $backup_dir;
		}

		$timestamp = gmdate( 'Y-m-d_His' );
		$results   = array();

		if ( 'db' === $type || 'full' === $type ) {
			$db_result = $this->backup_database( $backup_dir, $timestamp );
			if ( is_wp_error( $db_result ) ) {
				return $db_result;
			}
			$results['db'] = $db_result;
		}

		if ( 'files' === $type || 'full' === $type ) {
			$files_result = $this->backup_files( $backup_dir, $timestamp );
			if ( is_wp_error( $files_result ) ) {
				return $files_result;
			}
			$results['files'] = $files_result;
		}

		foreach ( $results as $result_type => $file_path ) {
			$size = file_exists( $file_path ) ? filesize( $file_path ) : 0;
			TKVault_DB::insert_backup(
				array(
					'filename' => basename( $file_path ),
					'type'     => $result_type,
					'size'     => $size,
					'status'   => 'completed',
					'note'     => $note,
				)
			);
		}

		do_action( 'tkvault_backup_completed', $type, $results );

		return $results;
	}

	private function backup_database( string $backup_dir, string $timestamp ) {
		global $wpdb;

		$filename = "{$timestamp}_db.sql";
		$filepath = trailingslashit( $backup_dir ) . $filename;
		$zip_path = $filepath . '.zip';

		// Gather credentials from wp-config constants.
		$db_name = DB_NAME;
		$db_user = DB_USER;
		$db_pass = DB_PASSWORD;
		$db_host = DB_HOST;

		$mysqldump = $this->find_mysqldump();
		if ( ! $mysqldump ) {
			return new WP_Error( 'mysqldump_not_found', __( 'mysqldump was not found.', 'takumi-vault' ) );
		}

		// Build command; password passed via env to avoid exposure in process list.
		$cmd = sprintf(
			'MYSQL_PWD=%s %s --single-transaction --quick --lock-tables=false -h %s -u %s %s > %s 2>&1',
			escapeshellarg( $db_pass ),
			escapeshellcmd( $mysqldump ),
			escapeshellarg( $db_host ),
			escapeshellarg( $db_user ),
			escapeshellarg( $db_name ),
			escapeshellarg( $filepath )
		);

		exec( $cmd, $output, $return_code ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_exec

		if ( 0 !== $return_code ) {
			return new WP_Error( 'mysqldump_failed', __( 'Database backup failed.', 'takumi-vault' ) );
		}

		$zip_result = $this->zip_file( $filepath, $zip_path );
		wp_delete_file( $filepath );

		if ( is_wp_error( $zip_result ) ) {
			return $zip_result;
		}

		return $zip_path;
	}

	private function backup_files( string $backup_dir, string $timestamp ) {
		$zip_path   = trailingslashit( $backup_dir ) . "{$timestamp}_files.zip";
		$wp_content = WP_CONTENT_DIR;

		$zip = new ZipArchive();
		if ( true !== $zip->open( $zip_path, ZipArchive::CREATE ) ) {
			return new WP_Error( 'zip_open_failed', __( 'Could not create the ZIP file.', 'takumi-vault' ) );
		}

		$this->add_directory_to_zip( $zip, $wp_content, dirname( $wp_content ) );
		$zip->close();

		return $zip_path;
	}

	private function add_directory_to_zip( ZipArchive $zip, string $dir, string $base ) {
		$iterator = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $dir, RecursiveDirectoryIterator::SKIP_DOTS ),
			RecursiveIteratorIterator::SELF_FIRST
		);

		foreach ( $iterator as $file ) {
			$file_path   = $file->getRealPath();
			$relative    = ltrim( str_replace( $base, '', $file_path ), DIRECTORY_SEPARATOR );

			// Skip the backup directory itself to avoid recursion.
			if ( 0 === strpos( $file_path, tkvault_get_backup_dir() ) ) {
				continue;
			}

			if ( $file->isDir() ) {
				$zip->addEmptyDir( $relative );
			} else {
				$zip->addFile( $file_path, $relative );
			}
		}
	}

	private function zip_file( string $source, string $destination ) {
		$zip = new ZipArchive();
		if ( true !== $zip->open( $destination, ZipArchive::CREATE ) ) {
			return new WP_Error( 'zip_open_failed', __( 'Could not create the ZIP file.', 'takumi-vault' ) );
		}
		$zip->addFile( $source, basename( $source ) );
		$zip->close();
		return $destination;
	}

	private function find_mysqldump() {
		$candidates = array( 'mysqldump', '/usr/bin/mysqldump', '/usr/local/bin/mysqldump' );
		foreach ( $candidates as $cmd ) {
			exec( 'which ' . escapeshellarg( $cmd ) . ' 2>/dev/null', $out, $code ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_exec
			if ( 0 === $code && ! empty( $out[0] ) ) {
				return trim( $out[0] );
			}
		}
		return false;
	}

	public function get_old_backups( int $keep = 10 ) {
		global $wpdb;
		return $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT * FROM {$wpdb->prefix}tkvault_backups ORDER BY created_at DESC LIMIT 9999 OFFSET %d",
				$keep
			)
		);
	}
}
