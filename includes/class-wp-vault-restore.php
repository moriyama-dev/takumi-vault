<?php
defined( 'ABSPATH' ) || exit;

class WP_Vault_Restore {

	public function run( int $backup_id, string $type = 'full' ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			return new WP_Error( 'forbidden', __( '権限がありません。', 'wp-vault' ) );
		}

		$record = WP_Vault_DB::get_backup_by_id( $backup_id );
		if ( ! $record ) {
			return new WP_Error( 'not_found', __( 'バックアップが見つかりません。', 'wp-vault' ) );
		}

		$backup_dir = wpvault_get_backup_dir();
		$filepath   = trailingslashit( $backup_dir ) . $record->filename;

		if ( ! file_exists( $filepath ) ) {
			return new WP_Error( 'file_missing', __( 'バックアップファイルが存在しません。', 'wp-vault' ) );
		}

		// Prevent path traversal.
		$real_backup_dir = realpath( $backup_dir );
		$real_filepath   = realpath( $filepath );
		if ( false === $real_filepath || 0 !== strpos( $real_filepath, $real_backup_dir ) ) {
			return new WP_Error( 'invalid_path', __( '不正なファイルパスです。', 'wp-vault' ) );
		}

		$record_type = sanitize_text_field( $record->type );

		if ( 'db' === $record_type && in_array( $type, array( 'db', 'full' ), true ) ) {
			$result = $this->restore_database( $real_filepath );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
		}

		if ( 'files' === $record_type && in_array( $type, array( 'files', 'full' ), true ) ) {
			$result = $this->restore_files( $real_filepath );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
		}

		do_action( 'wpvault_restore_completed', $backup_id, $type );

		return true;
	}

	private function restore_database( string $zip_path ) {
		$tmp_dir = get_temp_dir() . 'wpvault_restore_' . uniqid();
		wp_mkdir_p( $tmp_dir );

		$zip = new ZipArchive();
		if ( true !== $zip->open( $zip_path ) ) {
			return new WP_Error( 'zip_open_failed', __( 'ZIPファイルを開けませんでした。', 'wp-vault' ) );
		}
		$zip->extractTo( $tmp_dir );
		$zip->close();

		$sql_files = glob( trailingslashit( $tmp_dir ) . '*.sql' );
		if ( empty( $sql_files ) ) {
			$this->remove_dir( $tmp_dir );
			return new WP_Error( 'no_sql', __( 'SQLファイルが見つかりません。', 'wp-vault' ) );
		}

		$sql_file = $sql_files[0];
		$mysql    = $this->find_mysql();
		if ( ! $mysql ) {
			$this->remove_dir( $tmp_dir );
			return new WP_Error( 'mysql_not_found', __( 'mysql コマンドが見つかりません。', 'wp-vault' ) );
		}

		$cmd = sprintf(
			'MYSQL_PWD=%s %s -h %s -u %s %s < %s 2>&1',
			escapeshellarg( DB_PASSWORD ),
			escapeshellcmd( $mysql ),
			escapeshellarg( DB_HOST ),
			escapeshellarg( DB_USER ),
			escapeshellarg( DB_NAME ),
			escapeshellarg( $sql_file )
		);

		exec( $cmd, $output, $return_code ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_exec
		$this->remove_dir( $tmp_dir );

		if ( 0 !== $return_code ) {
			return new WP_Error( 'mysql_import_failed', __( 'データベースのリストアに失敗しました。', 'wp-vault' ) );
		}

		return true;
	}

	private function restore_files( string $zip_path ) {
		$zip = new ZipArchive();
		if ( true !== $zip->open( $zip_path ) ) {
			return new WP_Error( 'zip_open_failed', __( 'ZIPファイルを開けませんでした。', 'wp-vault' ) );
		}

		$extract_to = dirname( WP_CONTENT_DIR );
		$zip->extractTo( $extract_to );
		$zip->close();

		return true;
	}

	private function find_mysql() {
		$candidates = array( 'mysql', '/usr/bin/mysql', '/usr/local/bin/mysql' );
		foreach ( $candidates as $cmd ) {
			exec( 'which ' . escapeshellarg( $cmd ) . ' 2>/dev/null', $out, $code ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_exec
			if ( 0 === $code && ! empty( $out[0] ) ) {
				return trim( $out[0] );
			}
		}
		return false;
	}

	private function remove_dir( string $dir ) {
		if ( ! is_dir( $dir ) ) {
			return;
		}
		$files = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $dir, RecursiveDirectoryIterator::SKIP_DOTS ),
			RecursiveIteratorIterator::CHILD_FIRST
		);
		foreach ( $files as $file ) {
			$file->isDir() ? rmdir( $file->getRealPath() ) : unlink( $file->getRealPath() );
		}
		rmdir( $dir );
	}
}
