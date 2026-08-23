<?php
/**
 * WP-CLI commands.
 *
 * These commands do not implement backup or restore. They create exactly the
 * jobs the admin screens create, and then drive the runner in a loop until the
 * job is finished. That is the whole point of having them: the command line has
 * no browser waiting on it, needs no loopback request and does not depend on
 * WP-Cron, so a host where the browser path struggles can still complete the
 * work here - and a real cron entry can back up a site with no visitors, which
 * WP-Cron cannot.
 *
 * The strings below are not translated. Command output is read by operators and
 * by scripts, and every other WP-CLI command on the machine answers in English;
 * a backup command that answers in a different language than `wp core update`
 * standing next to it in the same shell script helps nobody.
 *
 * @package TakumiVault
 */

defined( 'ABSPATH' ) || exit;

/**
 * Back up and restore this site from the command line.
 */
class TKVault_CLI {

	/**
	 * Seconds of no progress before a command gives up.
	 *
	 * Longer than TKVault_Jobs::LOCK_TIMEOUT on purpose: a claim left behind by
	 * a browser-driven run that died has to be given time to go stale, so that
	 * this loop can take the job over instead of reporting a failure that would
	 * have resolved itself.
	 */
	const STALL_LIMIT = 180;

	public static function register() {
		WP_CLI::add_command( 'takumi-vault', __CLASS__ );
	}

	/**
	 * Take a backup.
	 *
	 * ## OPTIONS
	 *
	 * [--type=<type>]
	 * : What to back up.
	 * ---
	 * default: db
	 * options:
	 *   - db
	 *   - files
	 *   - all
	 * ---
	 *
	 * [--note=<note>]
	 * : A note stored with the backup, so it can be recognised later.
	 *
	 * [--full]
	 * : Force a full file backup instead of an incremental one.
	 *
	 * [--all-tables]
	 * : Include every table in the database, not only the ones belonging to
	 * this site. Restoring such a dump overwrites any other WordPress sharing
	 * the database, so this is off by default.
	 *
	 * ## EXAMPLES
	 *
	 *     # Nightly database backup from the server's own cron.
	 *     wp takumi-vault backup
	 *
	 *     # Everything, before a risky change.
	 *     wp takumi-vault backup --type=all --note="before the theme change"
	 *
	 * @when after_wp_load
	 */
	public function backup( $args, $assoc_args ) {
		$type = isset( $assoc_args['type'] ) ? (string) $assoc_args['type'] : 'db';
		$note = isset( $assoc_args['note'] ) ? (string) $assoc_args['note'] : '';

		if ( ! in_array( $type, array( 'db', 'files', 'all' ), true ) ) {
			WP_CLI::error( 'Unknown --type. Use db, files or all.' );
		}

		self::ensure_destination();

		foreach ( ( 'all' === $type ? array( 'db', 'files' ) : array( $type ) ) as $one ) {
			$label = 'files' === $one ? 'Files' : 'Database';

			$job = 'files' === $one
				? TKVault_File_Backup::start( $note, ! empty( $assoc_args['full'] ) )
				: TKVault_DB_Dump::start( $note, ! empty( $assoc_args['all-tables'] ) );

			if ( is_wp_error( $job ) ) {
				WP_CLI::error( $job->get_error_message() );
			}

			$snapshot = self::drive( $job['id'], $label );

			if ( TKVault_Jobs::STATUS_COMPLETE !== $snapshot['status'] ) {
				WP_CLI::error(
					sprintf(
						'%s backup %s. %s',
						$label,
						$snapshot['status'],
						$snapshot['message'] ? $snapshot['message'] : 'No reason was recorded.'
					)
				);
			}

			WP_CLI::success( sprintf( '%s backup finished in %d hand-over(s).', $label, $snapshot['handovers'] ) );
		}
	}

	/**
	 * Restore a backup over the live site.
	 *
	 * ## OPTIONS
	 *
	 * <id>
	 * : Which backup to restore. Run `wp takumi-vault list` for the ids.
	 *
	 * [--confirm-space]
	 * : Proceed even though free space could not be measured on this host. A
	 * database restore needs room for both copies while it runs.
	 *
	 * [--yes]
	 * : Do not ask for confirmation.
	 *
	 * ## EXAMPLES
	 *
	 *     wp takumi-vault restore 42
	 *
	 * @when after_wp_load
	 */
	public function restore( $args, $assoc_args ) {
		$backup_id = isset( $args[0] ) ? absint( $args[0] ) : 0;
		$record    = TKVault_DB::get_backup_by_id( $backup_id );

		if ( ! $record ) {
			WP_CLI::error( 'That backup does not exist.' );
		}

		$description = 'files' === $record->type
			? TKVault_File_Restore::describe( $backup_id )
			: TKVault_DB_Restore::describe( $backup_id );

		if ( is_wp_error( $description ) ) {
			WP_CLI::error( $description->get_error_message() );
		}

		self::report_restore( $record, $description );

		if ( empty( $description['ok'] ) ) {
			WP_CLI::error(
				! empty( $description['problem'] )
					? $description['problem']
					: 'This backup cannot be restored.'
			);
		}

		WP_CLI::confirm( 'This replaces live data. Continue?', $assoc_args );

		$job = 'files' === $record->type
			? TKVault_File_Restore::start( $backup_id )
			: TKVault_DB_Restore::start( $backup_id, ! empty( $assoc_args['confirm-space'] ) );

		if ( is_wp_error( $job ) ) {
			WP_CLI::error( $job->get_error_message() );
		}

		$snapshot = self::drive( $job['id'], 'Restore' );

		if ( TKVault_Jobs::STATUS_COMPLETE !== $snapshot['status'] ) {
			WP_CLI::error(
				sprintf(
					'The restore %s. %s',
					$snapshot['status'],
					$snapshot['message'] ? $snapshot['message'] : 'No reason was recorded.'
				)
			);
		}

		WP_CLI::success( 'Restore finished. Run `wp takumi-vault undo` if this was not what you wanted.' );
	}

	/**
	 * Put back what the last restore replaced.
	 *
	 * ## OPTIONS
	 *
	 * <kind>
	 * : What to put back.
	 * ---
	 * options:
	 *   - db
	 *   - files
	 * ---
	 *
	 * [--yes]
	 * : Do not ask for confirmation.
	 *
	 * @when after_wp_load
	 */
	public function undo( $args, $assoc_args ) {
		$kind = isset( $args[0] ) ? (string) $args[0] : '';

		if ( ! in_array( $kind, array( 'db', 'files' ), true ) ) {
			WP_CLI::error( 'Say what to put back: db or files.' );
		}

		WP_CLI::confirm( 'This replaces what the last restore put in place. Continue?', $assoc_args );

		$result = 'files' === $kind ? TKVault_File_Restore::undo() : TKVault_DB_Restore::undo();

		if ( is_wp_error( $result ) ) {
			WP_CLI::error( $result->get_error_message() );
		}

		WP_CLI::success(
			'files' === $kind
				? 'The replaced files have been put back.'
				: 'The previous database has been put back.'
		);
	}

	/**
	 * List the backups this site is keeping.
	 *
	 * ## OPTIONS
	 *
	 * [--limit=<number>]
	 * : How many to show, newest first.
	 * ---
	 * default: 50
	 * ---
	 *
	 * [--format=<format>]
	 * : Render output in a particular format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - csv
	 *   - json
	 *   - yaml
	 *   - ids
	 * ---
	 *
	 * @subcommand list
	 * @when after_wp_load
	 */
	public function list_backups( $args, $assoc_args ) {
		$limit   = isset( $assoc_args['limit'] ) ? max( 1, absint( $assoc_args['limit'] ) ) : 50;
		$format  = isset( $assoc_args['format'] ) ? (string) $assoc_args['format'] : 'table';
		$records = TKVault_DB::get_all_backups( $limit, 1 );

		if ( ! $records ) {
			WP_CLI::log( 'There are no backups yet.' );
			return;
		}

		$rows = array();
		foreach ( $records as $record ) {
			$rows[] = array(
				'id'      => (int) $record->id,
				'created' => $record->created_at,
				'type'    => $record->type,
				'size'    => size_format( (int) $record->size ),
				'file'    => $record->filename,
				'note'    => (string) $record->note,
			);
		}

		if ( 'ids' === $format ) {
			WP_CLI::log( implode( ' ', wp_list_pluck( $rows, 'id' ) ) );
			return;
		}

		WP_CLI\Utils\format_items( $format, $rows, array( 'id', 'created', 'type', 'size', 'file', 'note' ) );
	}

	/**
	 * Report what this host can and cannot do.
	 *
	 * Exits non-zero when something would stop a backup, so it can gate a
	 * deployment script.
	 *
	 * ## EXAMPLES
	 *
	 *     wp takumi-vault check && wp takumi-vault backup --type=all
	 *
	 * @when after_wp_load
	 */
	public function check( $args, $assoc_args ) {
		$checks = TKVault_Preflight::run( true );

		WP_CLI\Utils\format_items( 'table', $checks, array( 'label', 'status', 'detail' ) );

		$blockers = TKVault_Preflight::blockers( $checks );
		if ( $blockers ) {
			WP_CLI::error(
				sprintf(
					'%d check(s) would stop a backup from running.',
					count( $blockers )
				)
			);
		}

		WP_CLI::success( 'Nothing here would stop a backup.' );
	}

	/* --------------------------------------------------------------- */

	/**
	 * Refuse to start if the destination is unusable or publicly readable.
	 *
	 * The same two guards the admin screen applies before queueing a backup.
	 */
	private static function ensure_destination() {
		$dir = tkvault_ensure_backup_dir();
		if ( is_wp_error( $dir ) ) {
			WP_CLI::error( $dir->get_error_message() );
		}

		if ( TKVault_Storage::PUBLIC_YES === TKVault_Storage::reprobe() ) {
			WP_CLI::error( 'The backup directory is downloadable over HTTP. Change the destination before backing up.' );
		}
	}

	/**
	 * Run a job to completion in this process.
	 *
	 * @param int    $job_id Job to advance.
	 * @param string $label  What to call it in the output.
	 * @return array The final snapshot.
	 */
	private static function drive( $job_id, $label ) {
		TKVault_Runner::set_inline( true );

		$snapshot = TKVault_Runner::snapshot( TKVault_Jobs::get( $job_id ) );
		$reported = '';
		$stalled  = 0;

		try {
			while ( in_array( $snapshot['status'], array( TKVault_Jobs::STATUS_PENDING, TKVault_Jobs::STATUS_RUNNING ), true ) ) {
				$before   = $snapshot['processed'];
				$snapshot = TKVault_Runner::run( $job_id );

				$line = self::progress_line( $label, $snapshot );
				if ( $line !== $reported ) {
					WP_CLI::log( $line );
					$reported = $line;
				}

				if ( $snapshot['processed'] !== $before ) {
					$stalled = 0;
					continue;
				}

				// No progress. Either another worker holds the claim, or the
				// job is dying in the same place and the attempt counter has
				// not caught up with it yet. Waiting is right for both.
				$stalled++;
				if ( $stalled >= self::STALL_LIMIT ) {
					WP_CLI::error(
						sprintf(
							'%s stopped making progress. %s',
							$label,
							$snapshot['message'] ? $snapshot['message'] : 'Nothing was reported.'
						)
					);
				}

				sleep( 1 );
			}
		} finally {
			TKVault_Runner::set_inline( false );
		}

		return $snapshot;
	}

	/**
	 * One line of progress.
	 *
	 * Deliberately not WP_CLI\Utils\make_progress_bar(): a handler only learns
	 * the real size of its work once it has started, and revises it as it goes,
	 * so a bar drawn up front would be measuring against a number that is not
	 * yet true.
	 */
	private static function progress_line( $label, array $snapshot ) {
		if ( $snapshot['total'] > 0 ) {
			return sprintf(
				'  %s: %d%% (%d/%d)',
				$label,
				$snapshot['percent'],
				$snapshot['processed'],
				$snapshot['total']
			);
		}

		return sprintf( '  %s: %d done', $label, $snapshot['processed'] );
	}

	/**
	 * Say what restoring this backup would replace, before asking.
	 *
	 * The confirmation screen in the admin does the same thing. A restore is
	 * the one operation here that destroys data, so it does not get a bare
	 * yes/no prompt in either place.
	 */
	private static function report_restore( $record, array $description ) {
		WP_CLI::log( sprintf( 'Backup #%d, taken %s', (int) $record->id, $record->created_at ) );
		WP_CLI::log( sprintf( '  file:   %s (%s)', $record->filename, size_format( (int) $record->size ) ) );

		if ( '' !== (string) $record->note ) {
			WP_CLI::log( sprintf( '  note:   %s', $record->note ) );
		}

		if ( 'files' === $description['kind'] ) {
			WP_CLI::log( sprintf( '  puts back %d file(s) under %s', (int) $description['files'], $description['root'] ) );
			WP_CLI::log( sprintf( '  reads %d archive(s) in the incremental chain', (int) $description['links'] ) );

			if ( ! empty( $description['missing'] ) ) {
				WP_CLI::warning( sprintf( '%d archive(s) in that chain are missing.', count( $description['missing'] ) ) );
			}

			return;
		}

		WP_CLI::log( sprintf( '  replaces %d table(s), %d row(s)', (int) $description['tables'], (int) $description['rows'] ) );
		WP_CLI::log( sprintf( '  table prefix: %s (%s)', $description['prefix'], $description['scope'] ) );

		if ( ! empty( $description['foreign'] ) ) {
			WP_CLI::warning( sprintf( 'This dump came from %s, not from this site.', $description['site_url'] ) );
		}

		if ( empty( $description['consistent'] ) ) {
			WP_CLI::warning( 'This dump was not taken in a single consistent read.' );
		}

		foreach ( (array) $description['warnings'] as $warning ) {
			WP_CLI::warning( $warning );
		}

		$retention = (int) $description['retention'];
		if ( 0 === $retention ) {
			WP_CLI::warning( 'The database being replaced will NOT be kept: this cannot be undone.' );
		} else {
			WP_CLI::log(
				sprintf(
					'  the database being replaced is kept %s, so this can be undone',
					$retention < 0 ? 'indefinitely' : sprintf( 'for %d day(s)', $retention )
				)
			);
		}
	}
}
