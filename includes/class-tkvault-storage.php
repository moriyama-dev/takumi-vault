<?php
/**
 * Backup destination resolution and safety checks.
 *
 * Two independent questions are answered here, and they must not be
 * confused with each other:
 *
 *   1. Can the web server serve this directory?  Answered by an actual HTTP
 *      request for a canary file, never by comparing path strings.
 *   2. Does this directory already belong to something else?  Answered by
 *      looking at what is inside it and whether our ownership marker is
 *      present.
 *
 * @package TakumiVault
 */

defined( 'ABSPATH' ) || exit;

class TKVault_Storage {

	/**
	 * Marker file identifying a directory as ours.
	 *
	 * Nothing in this plugin deletes anything in a directory that does not
	 * carry this file. That includes generation pruning.
	 */
	const MARKER = '.takumi-vault';

	const OPTION_DIR = 'tkvault_backup_dir';
	const OPTION_ACK = 'tkvault_public_dir_acknowledged';

	/** Files allowed to pre-exist in a directory we are about to adopt. */
	const IGNORABLE = array( '.', '..', '.htaccess', 'index.php', 'index.html', self::MARKER );

	/** Exposure verdicts. */
	const PUBLIC_YES     = 'public';
	const PUBLIC_NO      = 'not_reachable';
	const PUBLIC_UNKNOWN = 'unknown';

	/**
	 * Get the configured backup directory, or an empty string when the
	 * plugin has not successfully claimed one yet.
	 *
	 * This never creates anything. Taking a directory is an explicit act -
	 * see evaluate().
	 */
	public static function get_dir() {
		return (string) get_option( self::OPTION_DIR, '' );
	}

	/**
	 * Candidate destinations in preference order.
	 *
	 * The random suffix is a mitigation, not a control: it makes the path
	 * hard to guess if the directory ever ends up served by the web server.
	 * It is not a substitute for the exposure probe.
	 *
	 * @return array List of array{path:string, label:string, in_webroot:bool}.
	 */
	public static function candidates() {
		$suffix     = wp_generate_password( 8, false, false );
		$candidates = array();

		$candidates[] = array(
			'path'       => wp_normalize_path( dirname( untrailingslashit( ABSPATH ) ) ) . '/_backup-' . $suffix,
			'label'      => __( 'Beside the WordPress installation', 'takumi-vault' ),
			'in_webroot' => false,
		);

		$home = self::home_dir();
		if ( $home ) {
			$candidates[] = array(
				'path'       => untrailingslashit( $home ) . '/_backup-' . $suffix,
				'label'      => __( 'Account home directory', 'takumi-vault' ),
				'in_webroot' => false,
			);
		}

		// Last resort. This one is certainly inside the document root, so it
		// only ever runs with the full set of hardening files.
		$uploads = wp_upload_dir();
		if ( empty( $uploads['error'] ) ) {
			$candidates[] = array(
				'path'       => wp_normalize_path( untrailingslashit( $uploads['basedir'] ) ) . '/_tkvault-' . wp_generate_password( 16, false, false ),
				'label'      => __( 'Inside the uploads directory (hardened)', 'takumi-vault' ),
				'in_webroot' => true,
			);
		}

		return $candidates;
	}

	/**
	 * Best-effort account home directory, excluding anything under ABSPATH.
	 *
	 * @return string Empty string when nothing usable was found.
	 */
	private static function home_dir() {
		$home = '';

		if ( function_exists( 'posix_getpwuid' ) && function_exists( 'posix_geteuid' ) ) {
			$info = posix_getpwuid( posix_geteuid() );
			if ( ! empty( $info['dir'] ) ) {
				$home = $info['dir'];
			}
		}
		if ( ! $home && ! empty( $_SERVER['HOME'] ) ) {
			$home = sanitize_text_field( wp_unslash( $_SERVER['HOME'] ) );
		}
		if ( ! $home ) {
			return '';
		}

		$home    = wp_normalize_path( untrailingslashit( $home ) );
		$abspath = wp_normalize_path( untrailingslashit( ABSPATH ) );

		// A home directory that contains the site is no safer than ABSPATH itself.
		if ( 0 === strpos( $abspath . '/', $home . '/' ) ) {
			return '';
		}

		return $home;
	}

	/**
	 * Whether the directory can be adopted without stepping on another tool.
	 *
	 * A directory we already own is fine. An empty directory is fine. A
	 * directory holding anyone else's files is refused, because generation
	 * pruning would eventually be pointed at it.
	 *
	 * @return true|WP_Error
	 */
	public static function is_adoptable( $dir ) {
		if ( ! is_dir( $dir ) ) {
			return true; // Does not exist yet; evaluate() will create it.
		}

		if ( self::is_owned( $dir ) ) {
			return true;
		}

		$entries = @scandir( $dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		if ( false === $entries ) {
			return new WP_Error(
				'tkvault_unreadable_dir',
				__( 'The directory exists but cannot be read.', 'takumi-vault' )
			);
		}

		$foreign = array_values( array_diff( $entries, self::IGNORABLE ) );
		if ( $foreign ) {
			return new WP_Error(
				'tkvault_dir_not_empty',
				sprintf(
					/* translators: %d: number of files already in the directory */
					__( 'This directory already contains %d item(s) that Takumi Vault did not create. Choose an empty directory, or confirm explicitly that you want to share this one.', 'takumi-vault' ),
					count( $foreign )
				),
				array( 'entries' => array_slice( $foreign, 0, 10 ) )
			);
		}

		return true;
	}

	/**
	 * Whether this directory carries our ownership marker.
	 *
	 * Every destructive operation in the plugin gates on this.
	 */
	public static function is_owned( $dir ) {
		return is_file( trailingslashit( $dir ) . self::MARKER );
	}

	private static function write_marker( $dir ) {
		$payload = wp_json_encode(
			array(
				'created'  => gmdate( 'c' ),
				'site_url' => site_url(),
				'plugin'   => 'takumi-vault',
			)
		);
		self::put( trailingslashit( $dir ) . self::MARKER, $payload );
	}

	/**
	 * Defence in depth for the case where the directory is, or might be,
	 * inside the document root.
	 *
	 * .htaccess does nothing on nginx, so it is never the only measure - the
	 * random directory name and the exposure probe carry the real weight.
	 */
	private static function harden( $dir, $in_webroot ) {
		$dir = trailingslashit( $dir );

		self::put( $dir . '.htaccess', "Require all denied\n<IfModule !mod_authz_core.c>\n\tdeny from all\n</IfModule>\n" );
		self::put( $dir . 'index.php', "<?php\n// Silence is golden.\n" );

		if ( $in_webroot ) {
			self::put( $dir . 'index.html', '' );
		}
	}

	private static function chmod( $path, $mode ) {
		$fs = self::filesystem();
		return $fs ? $fs->chmod( $path, $mode ) : false;
	}

	private static function filesystem() {
		global $wp_filesystem;
		if ( ! $wp_filesystem ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
			WP_Filesystem();
		}
		return $wp_filesystem;
	}

	private static function put( $path, $contents ) {
		$fs = self::filesystem();
		return $fs ? $fs->put_contents( $path, $contents, FS_CHMOD_FILE ) : false;
	}

	/**
	 * Build the URLs that might serve $dir.
	 *
	 * An empty result means no mapping could be derived. That is "unknown",
	 * never "safe".
	 */
	public static function candidate_urls_for_path( $dir ) {
		$dir        = wp_normalize_path( untrailingslashit( $dir ) );
		$candidates = array();

		$abspath = wp_normalize_path( untrailingslashit( ABSPATH ) );
		if ( 0 === strpos( $dir . '/', $abspath . '/' ) ) {
			$candidates[] = untrailingslashit( site_url() ) . substr( $dir, strlen( $abspath ) );
		}

		// WordPress in a subdirectory: the path may sit under the document
		// root while being outside ABSPATH. This is the case the old design
		// missed entirely.
		if ( ! empty( $_SERVER['DOCUMENT_ROOT'] ) ) {
			$docroot = realpath( sanitize_text_field( wp_unslash( $_SERVER['DOCUMENT_ROOT'] ) ) );
			$docroot = $docroot ? wp_normalize_path( untrailingslashit( $docroot ) ) : '';
			if ( $docroot && 0 === strpos( $dir . '/', $docroot . '/' ) ) {
				$parts = wp_parse_url( home_url() );
				if ( ! empty( $parts['scheme'] ) && ! empty( $parts['host'] ) ) {
					$origin = $parts['scheme'] . '://' . $parts['host'];
					if ( ! empty( $parts['port'] ) ) {
						$origin .= ':' . $parts['port'];
					}
					$candidates[] = $origin . substr( $dir, strlen( $docroot ) );
				}
			}
		}

		return array_values( array_unique( $candidates ) );
	}

	/**
	 * Ask the web server whether it will serve a file from $dir.
	 *
	 * Asymmetric on purpose. A hit proves the directory is public and that
	 * verdict is final. A miss only means this particular URL guess failed;
	 * it is never proof of safety, which is why the hardening files are
	 * written regardless of the outcome.
	 *
	 * A second trap sits underneath the first one. A directory at mode 0700
	 * owned by the PHP user is unreadable to the web server whenever the two
	 * are different accounts, so the canary comes back 403 and the probe
	 * reports "not reachable" for a directory sitting in the middle of the
	 * document root. That is the permissions answering, not the location.
	 * While the directory holds nothing but the canary we widen it just long
	 * enough to ask the question honestly, then put it back.
	 *
	 * When widening is not possible - the directory already holds archives -
	 * a negative result is downgraded to "unknown", because we cannot tell
	 * "not in web space" apart from "blocked by its own permissions". A
	 * positive result is always trustworthy and is never downgraded.
	 *
	 * @param string $dir       Absolute path.
	 * @param bool   $may_relax Whether the directory is safe to widen for the
	 *                          duration of the probe.
	 * @return string One of the PUBLIC_* constants.
	 */
	public static function probe_public_exposure( $dir, $may_relax = false ) {
		$urls = self::candidate_urls_for_path( $dir );
		if ( ! $urls ) {
			return self::PUBLIC_UNKNOWN;
		}

		$token = wp_generate_password( 32, false, false );
		$name  = 'tkvault-canary-' . wp_generate_password( 16, false, false ) . '.txt';
		$file  = trailingslashit( $dir ) . $name;

		if ( ! self::put( $file, $token ) ) {
			return self::PUBLIC_UNKNOWN;
		}

		$original = @fileperms( $dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		$original = false !== $original ? $original & 0777 : null;
		$relaxed  = false;

		if ( $may_relax && self::holds_nothing_but( $dir, array_merge( self::IGNORABLE, array( $name ) ) ) ) {
			$relaxed = self::chmod( $dir, 0755 ) && self::chmod( $file, 0644 );
		}

		$verdict = self::PUBLIC_NO;
		foreach ( $urls as $url ) {
			$response = wp_remote_get(
				trailingslashit( $url ) . $name,
				array(
					'timeout'     => 10,
					'sslverify'   => false,
					'redirection' => 0,
				)
			);

			if ( is_wp_error( $response ) ) {
				continue;
			}

			// Both conditions required. A bare 200 may be a custom 404 page.
			if ( 200 === wp_remote_retrieve_response_code( $response )
				&& false !== strpos( wp_remote_retrieve_body( $response ), $token ) ) {
				$verdict = self::PUBLIC_YES;
				break;
			}
		}

		// Remove the canary and restore the mode whatever happened.
		wp_delete_file( $file );
		if ( $relaxed && null !== $original ) {
			self::chmod( $dir, $original );
		}

		// Could not ask the question properly, so do not claim a clean bill.
		if ( self::PUBLIC_NO === $verdict && ! $relaxed && $may_relax ) {
			return self::PUBLIC_UNKNOWN;
		}

		return $verdict;
	}

	/**
	 * Whether $dir contains nothing outside $allowed.
	 */
	private static function holds_nothing_but( $dir, array $allowed ) {
		$entries = @scandir( $dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		return false !== $entries && ! array_diff( $entries, $allowed );
	}

	/**
	 * Evaluate a single destination end to end.
	 *
	 * Order matters, and it is not the obvious one. The probe runs against a
	 * BARE directory, before any hardening is written.
	 *
	 * Writing .htaccess first and probing afterwards measures the wrong
	 * thing: on Apache our own "Require all denied" answers the canary
	 * request, the probe reports "not reachable", and we happily store
	 * backups inside the document root. The moment that .htaccess stops
	 * being honoured - a move to nginx, a lost file, a host that disables
	 * AllowOverride - every archive is public. So the probe must answer
	 * "is this directory in web-served space", which is a property of the
	 * location, not of the files we just put in it.
	 *
	 * @param string $dir        Absolute path.
	 * @param bool   $in_webroot True only for the deliberate last-resort
	 *                           candidate under uploads, which is known to be
	 *                           public and is accepted with hardening.
	 * @return array{ok:bool, verdict:string, error:WP_Error|null}
	 */
	public static function evaluate( $dir, $in_webroot = false ) {
		$adoptable = self::is_adoptable( $dir );
		if ( is_wp_error( $adoptable ) ) {
			return array(
				'ok'      => false,
				'verdict' => self::PUBLIC_UNKNOWN,
				'error'   => $adoptable,
			);
		}

		$existed = is_dir( $dir );

		$prepared = self::prepare( $dir );
		if ( is_wp_error( $prepared ) ) {
			return array(
				'ok'      => false,
				'verdict' => self::PUBLIC_UNKNOWN,
				'error'   => $prepared,
			);
		}

		// Probe before hardening, and let it widen the mode while the
		// directory still holds nothing worth reading. See the notes above.
		$verdict = self::probe_public_exposure( $dir, true );

		if ( self::PUBLIC_YES === $verdict && ! $in_webroot ) {
			if ( ! $existed ) {
				self::discard( $dir );
			}
			return array(
				'ok'      => false,
				'verdict' => $verdict,
				'error'   => new WP_Error(
					'tkvault_dir_public',
					__( 'This directory is served by the web server: a test file placed there was downloadable over HTTP. Backups will not be written here.', 'takumi-vault' )
				),
			);
		}

		// Only now do we take ownership, write the defence-in-depth files and
		// close the permissions back down.
		self::write_marker( $dir );
		self::harden( $dir, $in_webroot || self::PUBLIC_YES === $verdict );
		self::tighten( $dir );

		return array(
			'ok'      => true,
			'verdict' => $verdict,
			'error'   => null,
		);
	}

	/**
	 * Create the directory and confirm it is writable, without writing
	 * anything that could interfere with the exposure probe.
	 *
	 * @return true|WP_Error
	 */
	private static function prepare( $dir ) {
		if ( ! is_dir( $dir ) && ! wp_mkdir_p( $dir ) ) {
			return new WP_Error(
				'tkvault_mkdir_failed',
				__( 'Could not create the backup directory.', 'takumi-vault' )
			);
		}

		// Deliberately no chmod here: the exposure probe runs next and must
		// not be answered by our own permissions. tighten() runs afterwards.
		if ( ! wp_is_writable( $dir ) ) {
			return new WP_Error(
				'tkvault_not_writable',
				__( 'The backup directory is not writable.', 'takumi-vault' )
			);
		}

		return true;
	}

	/**
	 * Close the directory down to the tightest mode the host will accept.
	 */
	private static function tighten( $dir ) {
		foreach ( array( 0700, 0750, 0755 ) as $mode ) {
			if ( self::chmod( $dir, $mode ) ) {
				return $mode;
			}
		}
		return null;
	}

	/**
	 * Remove a directory we created moments ago and then rejected.
	 *
	 * Refuses if anything unexpected is inside. At this point the directory
	 * holds at most the canary, which the probe already removed.
	 */
	private static function discard( $dir ) {
		$entries = @scandir( $dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		if ( false === $entries || array_diff( $entries, self::IGNORABLE ) ) {
			return false;
		}

		foreach ( array_diff( $entries, array( '.', '..' ) ) as $entry ) {
			wp_delete_file( trailingslashit( $dir ) . $entry );
		}

		$fs = self::filesystem();
		return $fs ? $fs->rmdir( $dir ) : false;
	}

	/**
	 * Walk the fallback chain and store the first destination that passes.
	 *
	 * @return string|WP_Error The chosen path.
	 */
	public static function auto_configure() {
		$errors = array();

		foreach ( self::candidates() as $candidate ) {
			$result = self::evaluate( $candidate['path'], $candidate['in_webroot'] );
			if ( $result['ok'] ) {
				update_option( self::OPTION_DIR, $candidate['path'], false );
				return $candidate['path'];
			}
			$errors[ $candidate['path'] ] = $result['error']->get_error_message();
		}

		return new WP_Error(
			'tkvault_no_destination',
			__( 'No usable backup destination was found. Set one manually under Takumi Vault > Settings.', 'takumi-vault' ),
			$errors
		);
	}
}
