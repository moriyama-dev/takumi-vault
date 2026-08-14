<?php
/**
 * Environment diagnostics.
 *
 * The guiding rule is inverted from the usual "check for a dependency, stop
 * if it is missing". Almost everything here has a fallback, so a missing
 * feature switches strategy and carries on. Only two conditions stop a
 * backup: nowhere writable to put it, and nowhere safe to put it.
 *
 * @package TakumiVault
 */

defined( 'ABSPATH' ) || exit;

class TKVault_Preflight {

	const OK   = 'ok';
	const WARN = 'warn';

	/**
	 * A missing feature the plugin works around, as opposed to something the
	 * site owner should look at. "Falls back" reads correctly for a host
	 * without ZipArchive; it reads as nonsense against a permission problem.
	 */
	const FALLBACK = 'fallback';
	const STOP = 'stop';

	/**
	 * Run every check.
	 *
	 * @param bool $probe Whether to re-run the exposure probe, which costs an
	 *                    HTTP round trip. False reads the recorded verdict.
	 * @return array List of array{label, status, detail}.
	 */
	public static function run( $probe = false ) {
		return array(
			self::check_archiver(),
			self::check_mbstring(),
			self::check_hardlink(),
			self::check_loopback(),
			self::check_cron(),
			self::check_destination(),
			self::check_permissions(),
			self::check_exposure( $probe ),
			self::check_free_space(),
		);
	}

	public static function blockers( array $checks ) {
		return array_values(
			array_filter(
				$checks,
				static function ( $check ) {
					return self::STOP === $check['status'];
				}
			)
		);
	}

	private static function row( $label, $status, $detail ) {
		return compact( 'label', 'status', 'detail' );
	}

	private static function check_archiver() {
		if ( class_exists( 'ZipArchive' ) ) {
			return self::row( __( 'Archive format', 'takumi-vault' ), self::OK, __( 'ZipArchive is available; backups will be ZIP files.', 'takumi-vault' ) );
		}
		if ( class_exists( 'PharData' ) ) {
			return self::row( __( 'Archive format', 'takumi-vault' ), self::FALLBACK, __( 'ZipArchive is missing. Falling back to PharData, so backups will be tar.gz files.', 'takumi-vault' ) );
		}
		return self::row( __( 'Archive format', 'takumi-vault' ), self::STOP, __( 'Neither ZipArchive nor PharData is available, so no archive can be created.', 'takumi-vault' ) );
	}

	private static function check_mbstring() {
		return extension_loaded( 'mbstring' )
			? self::row( __( 'mbstring', 'takumi-vault' ), self::OK, __( 'Available. Binary column values are detected by encoding check.', 'takumi-vault' ) )
			: self::row( __( 'mbstring', 'takumi-vault' ), self::FALLBACK, __( 'Missing. Binary values will be detected from the column type instead.', 'takumi-vault' ) );
	}

	private static function check_hardlink() {
		return function_exists( 'link' )
			? self::row( __( 'Hard links', 'takumi-vault' ), self::OK, __( 'Available. File snapshots are near-instant and use no extra disk.', 'takumi-vault' ) )
			: self::row( __( 'Hard links', 'takumi-vault' ), self::FALLBACK, __( 'Unavailable. Files are copied instead, which lengthens the maintenance window.', 'takumi-vault' ) );
	}

	/**
	 * Can the site reach itself over HTTP? Chained background processing
	 * depends on it; without it the admin screen drives progress instead.
	 */
	private static function check_loopback() {
		$response = wp_remote_post(
			admin_url( 'admin-ajax.php' ),
			array(
				'timeout'   => 10,
				'sslverify' => false,
				'body'      => array( 'action' => 'tkvault_loopback_ping' ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return self::row(
				__( 'Loopback requests', 'takumi-vault' ),
				self::FALLBACK,
				sprintf(
					/* translators: %s: error message from the HTTP request */
					__( 'Blocked (%s). Long jobs will advance only while an admin screen is open.', 'takumi-vault' ),
					$response->get_error_message()
				)
			);
		}

		// The absence of a transport error is not success. A request that
		// reaches the server and is turned away still comes back without a
		// WP_Error, so the reply itself has to be inspected - otherwise a site
		// where the plugin cannot even load reports its background processing
		// as healthy.
		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = (string) wp_remote_retrieve_body( $response );

		if ( 200 !== $code || false === strpos( $body, '"success":true' ) ) {
			return self::row(
				__( 'Loopback requests', 'takumi-vault' ),
				self::FALLBACK,
				sprintf(
					/* translators: %d: HTTP status code returned by the loopback request */
					__( 'The site answered its own request with HTTP %d instead of running the handler. Long jobs will advance only while an admin screen is open.', 'takumi-vault' ),
					$code
				)
			);
		}

		return self::row( __( 'Loopback requests', 'takumi-vault' ), self::OK, __( 'Working. Long jobs continue in the background.', 'takumi-vault' ) );
	}

	private static function check_cron() {
		if ( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ) {
			return self::row( __( 'WP-Cron', 'takumi-vault' ), self::FALLBACK, __( 'Disabled by DISABLE_WP_CRON. Scheduled backups and the periodic exposure re-check will not run on their own.', 'takumi-vault' ) );
		}
		return self::row( __( 'WP-Cron', 'takumi-vault' ), self::OK, __( 'Enabled.', 'takumi-vault' ) );
	}

	private static function check_destination() {
		$dir = TKVault_Storage::get_dir();

		if ( ! $dir ) {
			return self::row( __( 'Backup destination', 'takumi-vault' ), self::STOP, __( 'Not configured. Set a path under Settings.', 'takumi-vault' ) );
		}
		if ( ! is_dir( $dir ) ) {
			return self::row( __( 'Backup destination', 'takumi-vault' ), self::STOP, sprintf( '%s (%s)', __( 'The configured directory no longer exists.', 'takumi-vault' ), $dir ) );
		}
		if ( ! wp_is_writable( TKVault_Storage::get_store_dir() ) ) {
			return self::row( __( 'Backup destination', 'takumi-vault' ), self::STOP, sprintf( '%s (%s)', __( 'The archive sub-directory is not writable.', 'takumi-vault' ), TKVault_Storage::get_store_dir() ) );
		}

		return self::row( __( 'Backup destination', 'takumi-vault' ), self::OK, $dir );
	}

	/**
	 * Whether the destination came out as private as it should be.
	 *
	 * 0700 is what the plugin aims for. Anything looser is a compromise it
	 * made deliberately - a site whose web server and account user share a
	 * group has to be joined rather than fought - but the owner should be
	 * able to see that it happened, and which group can read the archives.
	 */
	private static function check_permissions() {
		$label = __( 'Destination permissions', 'takumi-vault' );
		$modes = TKVault_Storage::modes();

		if ( ! $modes ) {
			return self::row( $label, self::WARN, __( 'Not recorded yet. Re-check the destination under Settings.', 'takumi-vault' ) );
		}

		$mode  = isset( $modes['store'] ) ? $modes['store'] : null;
		$outer = isset( $modes['mode'] ) ? $modes['mode'] : null;

		if ( null === $mode || null === $outer ) {
			return self::row(
				$label,
				self::WARN,
				__( 'The permissions could not be set. Check that the destination is owned by the user PHP runs as.', 'takumi-vault' )
			);
		}

		if ( 0700 === $mode && 0700 === $outer ) {
			return self::row( $label, self::OK, __( 'Private to the user PHP runs as (0700).', 'takumi-vault' ) );
		}

		if ( ( $mode & 0007 ) || ( $outer & 0007 ) ) {
			return self::row(
				$label,
				self::STOP,
				sprintf(
					/* translators: %s: octal permission mode, e.g. 0755 */
					__( 'Readable by every user on this server (%s). The archives contain your database. Move the destination somewhere the plugin can close down.', 'takumi-vault' ),
					'0' . decoct( $mode )
				)
			);
		}

		$group = TKVault_Storage::group_name( isset( $modes['gid'] ) ? $modes['gid'] : null );

		return self::row(
			$label,
			self::WARN,
			sprintf(
				/* translators: 1: octal permission mode, e.g. 0770, 2: group name */
				__( 'Shared with the "%2$s" group (%1$s) rather than kept at 0700. This site already shares that group between its web server and its account user, and the destination has to match or one of them cannot write backups. No other user on the server has access.', 'takumi-vault' ),
				'0' . decoct( $mode ),
				$group ? $group : __( 'unknown', 'takumi-vault' )
			)
		);
	}

	/**
	 * The recorded exposure verdict, or a fresh one.
	 *
	 * "unknown" is deliberately not an error. It means the probe could not be
	 * carried out conclusively, which is a different thing from a clean bill
	 * of health, and the wording says so.
	 */
	private static function check_exposure( $probe ) {
		$dir = TKVault_Storage::get_dir();
		if ( ! $dir ) {
			return self::row( __( 'Public exposure', 'takumi-vault' ), self::WARN, __( 'Not checked: no destination is configured.', 'takumi-vault' ) );
		}

		$verdict = $probe ? TKVault_Storage::reprobe() : TKVault_Storage::last_verdict();
		if ( is_wp_error( $verdict ) ) {
			return self::row( __( 'Public exposure', 'takumi-vault' ), self::WARN, $verdict->get_error_message() );
		}

		$checked = TKVault_Storage::last_checked();
		$when    = $checked
			? sprintf(
				/* translators: %s: human-readable time difference, e.g. "2 hours" */
				__( 'Last checked %s ago.', 'takumi-vault' ),
				human_time_diff( $checked )
			)
			: __( 'Never checked.', 'takumi-vault' );

		switch ( $verdict ) {
			case TKVault_Storage::PUBLIC_YES:
				return self::row(
					__( 'Public exposure', 'takumi-vault' ),
					self::STOP,
					__( 'The backup directory is downloadable over HTTP. Move it before backing up again.', 'takumi-vault' ) . ' ' . $when
				);

			case TKVault_Storage::PUBLIC_NO:
				return self::row(
					__( 'Public exposure', 'takumi-vault' ),
					self::OK,
					__( 'A test file placed in the directory could not be fetched over HTTP.', 'takumi-vault' ) . ' ' . $when
				);

			default:
				return self::row(
					__( 'Public exposure', 'takumi-vault' ),
					self::WARN,
					__( 'Inconclusive: no URL could be derived for this path, so the check could not be carried out. This is not the same as being confirmed safe.', 'takumi-vault' ) . ' ' . $when
				);
		}
	}

	private static function check_free_space() {
		$dir = TKVault_Storage::get_dir();
		if ( ! $dir || ! is_dir( $dir ) ) {
			return self::row( __( 'Free space', 'takumi-vault' ), self::WARN, __( 'Not checked: no usable destination.', 'takumi-vault' ) );
		}
		if ( ! function_exists( 'disk_free_space' ) ) {
			return self::row( __( 'Free space', 'takumi-vault' ), self::WARN, __( 'disk_free_space() is disabled on this host, so free space cannot be verified before a backup starts.', 'takumi-vault' ) );
		}

		$free = @disk_free_space( $dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		if ( false === $free ) {
			return self::row( __( 'Free space', 'takumi-vault' ), self::WARN, __( 'Free space could not be read for this path.', 'takumi-vault' ) );
		}

		return self::row( __( 'Free space', 'takumi-vault' ), self::OK, size_format( $free ) );
	}
}
