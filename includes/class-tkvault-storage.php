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
 * Layout:
 *
 *   _backup-a7f3k2m9/      the probe target. Holds only the marker and the
 *   |                      hardening files, so it can always be widened for
 *   |                      the duration of a probe.
 *   +-- .takumi-vault      ownership marker
 *   +-- .htaccess          defence in depth, not counted on - see below
 *   +-- index.php
 *   +-- store/             0700. Every archive lives here and nowhere else.
 *
 * Keeping archives one level down is what makes re-probing possible. If they
 * sat in the probe target we could never widen it again after the first
 * backup, and every later check would come back "unknown" - which is exactly
 * when a site is most worth checking.
 *
 * What actually protects the archives is (a) the directory not being in
 * web-served space and (b) the unguessable directory name. The .htaccess is
 * written but never counted on: a host with AllowOverride None ignores it
 * completely, as does nginx.
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

	/** Sub-directory holding the archives. Never the probe target. */
	const STORE = 'store';

	const OPTION_DIR      = 'tkvault_backup_dir';
	const OPTION_VERDICT  = 'tkvault_exposure_verdict';
	const OPTION_CHECKED  = 'tkvault_exposure_checked';
	const OPTION_ALERT    = 'tkvault_exposure_alert';
	const OPTION_ACK      = 'tkvault_public_dir_acknowledged';
	const OPTION_GUARD    = 'tkvault_relax_guard';

	/** Entries allowed to pre-exist in a directory we are about to adopt. */
	const IGNORABLE = array( '.', '..', '.htaccess', 'index.php', 'index.html', self::MARKER, self::STORE );

	/** Exposure verdicts. */
	const PUBLIC_YES     = 'public';
	const PUBLIC_NO      = 'not_reachable';
	const PUBLIC_UNKNOWN = 'unknown';

	/**
	 * The configured backup directory, or an empty string when the plugin has
	 * not successfully taken one yet. Never creates anything.
	 */
	public static function get_dir() {
		return (string) get_option( self::OPTION_DIR, '' );
	}

	/**
	 * Where archives go. Callers that write backups must use this, not
	 * get_dir(), or re-probing breaks.
	 */
	public static function get_store_dir() {
		$dir = self::get_dir();
		return $dir ? trailingslashit( $dir ) . self::STORE : '';
	}

	/**
	 * A base name nothing in the store is already using.
	 *
	 * Timestamps have one-second resolution, so two backups started in the
	 * same second would otherwise share a name. That is not a cosmetic clash:
	 * ZipArchive::CREATE appends to an existing archive rather than replacing
	 * it, so the second backup would be mixed into the first, and both
	 * manifests would describe only one of them.
	 */
	public static function unique_base( $base ) {
		$store = self::get_store_dir();
		if ( ! $store ) {
			return $base;
		}

		$candidate = $base;
		$suffix    = 2;

		while ( glob( trailingslashit( $store ) . $candidate . '*' ) ) {
			$candidate = $base . '-' . $suffix;
			$suffix++;

			if ( $suffix > 100 ) {
				return $base . '-' . wp_generate_password( 6, false, false );
			}
		}

		return $candidate;
	}

	/**
	 * Bring an older destination up to the store/ layout.
	 *
	 * Installations configured before archives moved down a level have their
	 * files sitting directly in the destination. Left alone they would keep
	 * the probe permanently unable to widen, which is the exact failure the
	 * layout exists to prevent, so the files are relocated rather than merely
	 * tolerated.
	 *
	 * @return true|WP_Error
	 */
	public static function ensure_store() {
		$dir = self::get_dir();
		if ( ! $dir || ! is_dir( $dir ) ) {
			return new WP_Error( 'tkvault_no_dir', __( 'No backup destination is configured.', 'takumi-vault' ) );
		}

		$store = trailingslashit( $dir ) . self::STORE;
		if ( ! is_dir( $store ) && ! wp_mkdir_p( $store ) ) {
			return new WP_Error(
				'tkvault_store_failed',
				__( 'Could not create the archive sub-directory.', 'takumi-vault' )
			);
		}

		$entries = @scandir( $dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		if ( is_array( $entries ) ) {
			$fs = self::filesystem();
			foreach ( array_diff( $entries, self::IGNORABLE ) as $entry ) {
				$from = trailingslashit( $dir ) . $entry;
				if ( is_file( $from ) && $fs ) {
					$fs->move( $from, trailingslashit( $store ) . $entry, true );
				}
			}
		}

		self::tighten( $store );

		return true;
	}

	/**
	 * Candidate destinations in preference order.
	 *
	 * The random suffix is a mitigation, not a control: it makes the path hard
	 * to guess if the directory ever ends up served by the web server. It is
	 * not a substitute for the exposure probe.
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

		// Last resort, and known to be inside the document root. Accepted only
		// with the full set of hardening files and a standing warning.
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
	 * Best-effort account home directory, excluding anything containing the site.
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

		// A home directory that contains the site is no safer than ABSPATH.
		if ( 0 === strpos( $abspath . '/', $home . '/' ) ) {
			return '';
		}

		return $home;
	}

	/**
	 * Whether the directory can be taken without stepping on another tool.
	 *
	 * A directory we already own is fine. An empty one is fine. One holding
	 * anyone else's files is refused, because generation pruning would
	 * eventually be pointed at it.
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
					/* translators: %d: number of items already in the directory */
					__( 'This directory already contains %d item(s) that Takumi Vault did not create. Choose an empty directory instead.', 'takumi-vault' ),
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
		self::put(
			trailingslashit( $dir ) . self::MARKER,
			wp_json_encode(
				array(
					'created'  => gmdate( 'c' ),
					'site_url' => site_url(),
					'plugin'   => 'takumi-vault',
				)
			)
		);
	}

	/**
	 * Defence in depth, written always and relied upon never.
	 *
	 * Measured on a stock Ubuntu Apache with AllowOverride None: a directory
	 * carrying "Require all denied" still returned 200 with a full listing.
	 * The same is true of nginx, which never reads .htaccess at all.
	 */
	private static function harden( $dir, $in_webroot ) {
		$dir = trailingslashit( $dir );

		self::put( $dir . '.htaccess', "Require all denied\n<IfModule !mod_authz_core.c>\n\tdeny from all\n</IfModule>\n" );
		self::put( $dir . 'index.php', "<?php\n// Silence is golden.\n" );

		if ( $in_webroot ) {
			self::put( $dir . 'index.html', '' );
		}
	}

	/**
	 * The WP_Filesystem instance, built on first use.
	 *
	 * Public because the restore classes move and delete files too, and
	 * Plugin Check requires those to go through WP_Filesystem rather than
	 * rename()/rmdir().
	 */
	public static function filesystem() {
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

	private static function chmod( $path, $mode ) {
		$fs = self::filesystem();
		return $fs ? $fs->chmod( $path, $mode ) : false;
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
		// root while being outside ABSPATH. The original design missed this.
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
	 * verdict is final. A miss only means this particular URL guess failed; it
	 * is never proof of safety, which is why the hardening files are written
	 * regardless of the outcome.
	 *
	 * A second trap sits underneath the first. A directory at 0700 owned by
	 * the PHP user is unreadable to the web server whenever the two are
	 * different accounts, so the canary comes back 403 and the probe reports
	 * "not reachable" for a directory sitting in the middle of the document
	 * root. That is the permissions answering, not the location. While the
	 * directory holds nothing but the canary we widen it just long enough to
	 * ask the question honestly, then put it back.
	 *
	 * Widening costs nothing in either hosting shape. Where the web server and
	 * PHP are the same account - mod_php, a shared PHP-FPM pool - the owner
	 * bit already grants the web server everything, so 0700 was never
	 * protecting the archives. Where they are different accounts, store/ stays
	 * at 0700 owned by the PHP user and remains unreadable throughout.
	 *
	 * @param string $dir       Absolute path.
	 * @param bool   $may_relax Whether the directory may be widened for the
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

		$relaxed = false;
		if ( $may_relax && self::holds_nothing_but( $dir, array_merge( self::IGNORABLE, array( $name ) ) ) ) {
			$relaxed = self::relax( $dir, $file );
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

		wp_delete_file( $file );
		if ( $relaxed ) {
			self::unrelax();
		}

		// Could not ask the question properly, so do not claim a clean bill.
		if ( self::PUBLIC_NO === $verdict && ! $relaxed && $may_relax ) {
			return self::PUBLIC_UNKNOWN;
		}

		return $verdict;
	}

	/**
	 * Widen the directory for one probe, with three ways back.
	 *
	 * The mode to restore is written to an option before anything changes, a
	 * shutdown callback restores it if the request ends early, and heal()
	 * catches whatever is left after a fatal or a killed process.
	 */
	private static function relax( $dir, $canary ) {
		$original = @fileperms( $dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		if ( false === $original ) {
			return false;
		}
		$original &= 0777;

		update_option(
			self::OPTION_GUARD,
			array(
				'dir'  => $dir,
				'mode' => $original,
			),
			false
		);

		if ( ! self::chmod( $dir, 0755 ) ) {
			delete_option( self::OPTION_GUARD );
			return false;
		}
		self::chmod( $canary, 0644 );

		register_shutdown_function( array( __CLASS__, 'unrelax' ) );

		return true;
	}

	/**
	 * Restore the mode recorded by relax(). Safe to call more than once.
	 */
	public static function unrelax() {
		$guard = get_option( self::OPTION_GUARD );
		if ( ! is_array( $guard ) || empty( $guard['dir'] ) ) {
			return;
		}

		if ( is_dir( $guard['dir'] ) ) {
			self::chmod( $guard['dir'], (int) $guard['mode'] );
		}
		delete_option( self::OPTION_GUARD );
	}

	/**
	 * Self-heal a directory left open by a request that died mid-probe.
	 * Cheap enough to run on every admin load.
	 */
	public static function heal() {
		if ( get_option( self::OPTION_GUARD ) ) {
			self::unrelax();
		}
	}

	/**
	 * Corroborating signal that needs no permission changes: does the
	 * directory URL resolve at all?
	 *
	 * Never enough to declare a directory public on its own. Blanket 401/403
	 * from basic auth, a WAF or a security plugin looks identical to a real
	 * hit, and a catch-all rewrite can return 200 for anything. Used only to
	 * add context to an "unknown", never to produce a "public".
	 *
	 * @return array{status:int, url:string}|null
	 */
	public static function directory_status( $dir ) {
		$urls = self::candidate_urls_for_path( $dir );
		if ( ! $urls ) {
			return null;
		}

		$response = wp_remote_head(
			trailingslashit( $urls[0] ),
			array(
				'timeout'     => 10,
				'sslverify'   => false,
				'redirection' => 0,
			)
		);

		if ( is_wp_error( $response ) ) {
			return null;
		}

		return array(
			'status' => (int) wp_remote_retrieve_response_code( $response ),
			'url'    => trailingslashit( $urls[0] ),
		);
	}

	/**
	 * Whether $dir contains nothing outside $allowed.
	 */
	private static function holds_nothing_but( $dir, array $allowed ) {
		$entries = @scandir( $dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		return false !== $entries && ! array_diff( $entries, $allowed );
	}

	/**
	 * Evaluate a destination end to end.
	 *
	 * Order matters, and it is not the obvious one. The probe runs against a
	 * BARE directory, before any hardening is written. Writing .htaccess first
	 * and probing afterwards measures the wrong thing: on a host that honours
	 * it, our own "Require all denied" answers the canary request, the probe
	 * reports "not reachable", and we store backups inside the document root.
	 * The moment that file stops being honoured every archive is public.
	 *
	 * @param string $dir        Absolute path.
	 * @param bool   $in_webroot True only for the deliberate last-resort
	 *                           candidate under uploads.
	 * @return array{ok:bool, verdict:string, error:WP_Error|null}
	 */
	public static function evaluate( $dir, $in_webroot = false ) {
		$adoptable = self::is_adoptable( $dir );
		if ( is_wp_error( $adoptable ) ) {
			return self::result( false, self::PUBLIC_UNKNOWN, $adoptable );
		}

		$existed  = is_dir( $dir );
		$prepared = self::prepare( $dir );
		if ( is_wp_error( $prepared ) ) {
			return self::result( false, self::PUBLIC_UNKNOWN, $prepared );
		}

		$verdict = self::probe_public_exposure( $dir, true );

		if ( self::PUBLIC_YES === $verdict && ! $in_webroot ) {
			if ( ! $existed ) {
				self::discard( $dir );
			}
			return self::result(
				false,
				$verdict,
				new WP_Error(
					'tkvault_dir_public',
					__( 'This directory is served by the web server: a test file placed there was downloadable over HTTP. Backups will not be written here.', 'takumi-vault' )
				)
			);
		}

		// Only now take ownership, write the hardening files, create the store
		// and close the permissions back down.
		self::write_marker( $dir );
		self::harden( $dir, $in_webroot || self::PUBLIC_YES === $verdict );

		$store = trailingslashit( $dir ) . self::STORE;
		if ( ! is_dir( $store ) && ! wp_mkdir_p( $store ) ) {
			return self::result(
				false,
				$verdict,
				new WP_Error(
					'tkvault_store_failed',
					__( 'Could not create the archive sub-directory.', 'takumi-vault' )
				)
			);
		}
		self::tighten( $store );
		self::tighten( $dir );

		return self::result( true, $verdict, null );
	}

	private static function result( $ok, $verdict, $error ) {
		return array(
			'ok'      => $ok,
			'verdict' => $verdict,
			'error'   => $error,
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

		// Deliberately no chmod here: the probe runs next and must not be
		// answered by our own permissions. tighten() runs afterwards.
		if ( ! wp_is_writable( $dir ) ) {
			return new WP_Error(
				'tkvault_not_writable',
				__( 'The backup directory is not writable.', 'takumi-vault' )
			);
		}

		return true;
	}

	/**
	 * Close a directory down to the tightest mode the host will accept.
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
	 */
	private static function discard( $dir ) {
		$entries = @scandir( $dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		if ( false === $entries || array_diff( $entries, self::IGNORABLE ) ) {
			return false;
		}

		foreach ( array_diff( $entries, array( '.', '..', self::STORE ) ) as $entry ) {
			wp_delete_file( trailingslashit( $dir ) . $entry );
		}

		$fs = self::filesystem();
		if ( ! $fs ) {
			return false;
		}
		if ( is_dir( trailingslashit( $dir ) . self::STORE ) ) {
			$fs->rmdir( trailingslashit( $dir ) . self::STORE );
		}
		return $fs->rmdir( $dir );
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
				self::record_verdict( $result['verdict'] );
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

	/**
	 * Re-run the exposure probe against the configured destination.
	 *
	 * This is the whole point of the store/ layout. A site that was safe when
	 * the plugin was installed can stop being safe later - Apache to nginx, a
	 * changed document root, a host reshuffling its configuration - and the
	 * moment that matters most is when the directory is full of archives.
	 *
	 * @return string|WP_Error The verdict.
	 */
	public static function reprobe() {
		$dir = self::get_dir();
		if ( ! $dir ) {
			return new WP_Error( 'tkvault_no_dir', __( 'No backup destination is configured.', 'takumi-vault' ) );
		}
		if ( ! is_dir( $dir ) ) {
			return new WP_Error( 'tkvault_dir_missing', __( 'The configured backup directory no longer exists.', 'takumi-vault' ) );
		}

		$store = self::ensure_store();
		if ( is_wp_error( $store ) ) {
			return $store;
		}

		$verdict  = self::probe_public_exposure( $dir, true );
		$previous = self::last_verdict();

		self::record_verdict( $verdict );

		if ( self::PUBLIC_YES === $verdict && self::PUBLIC_YES !== $previous ) {
			self::on_became_public();
		}

		return $verdict;
	}

	/**
	 * A verdict belongs to the directory it was measured on.
	 *
	 * Storing it as a bare string meant that pointing the plugin at a new
	 * destination silently inherited the old answer - including a "public"
	 * that would keep blocking backups against a directory that had never
	 * been tested, and a clean result that would vouch for one.
	 */
	public static function record_verdict( $verdict ) {
		update_option(
			self::OPTION_VERDICT,
			array(
				'dir'     => self::get_dir(),
				'verdict' => $verdict,
			),
			false
		);
		update_option( self::OPTION_CHECKED, time(), false );

		if ( self::PUBLIC_YES !== $verdict ) {
			delete_option( self::OPTION_ALERT );
		}
	}

	/**
	 * A destination that used to be safe is now downloadable.
	 *
	 * Stop writing more archives into it on a schedule and make the admin
	 * screens say so until someone acts.
	 */
	private static function on_became_public() {
		update_option( self::OPTION_ALERT, time(), false );

		if ( class_exists( 'TKVault_Scheduler' ) ) {
			TKVault_Scheduler::clear_scheduled_events();
		}
	}

	/**
	 * The recorded verdict, but only if it was measured on the directory
	 * currently configured. Anything else is treated as never checked.
	 */
	public static function last_verdict() {
		$record = get_option( self::OPTION_VERDICT );

		if ( ! is_array( $record ) || ! isset( $record['dir'], $record['verdict'] ) ) {
			return '';
		}
		if ( $record['dir'] !== self::get_dir() ) {
			return '';
		}

		return (string) $record['verdict'];
	}

	public static function last_checked() {
		return (int) get_option( self::OPTION_CHECKED, 0 );
	}

	public static function has_alert() {
		return (bool) get_option( self::OPTION_ALERT, false );
	}
}
