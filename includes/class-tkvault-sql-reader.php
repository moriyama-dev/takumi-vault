<?php
/**
 * Streaming SQL statement reader.
 *
 * explode( ';' ) is the obvious implementation and it is wrong on the first
 * post that contains a semicolon. Statements have to be found by actually
 * tracking what the parser is inside of: single quotes, double quotes,
 * backtick identifiers, line comments, block comments, and backslash escapes.
 *
 * Resumability decides the shape of this class. A restore is chunked across
 * requests, so reading has to stop and continue later. It always stops on a
 * statement boundary, where the parser state is by definition clean, so the
 * only thing that has to survive between requests is a byte offset - plus the
 * delimiter, for dumps that change it.
 *
 * Seeking in a gzip stream costs a decompression from the beginning, so the
 * handle is cached statically and reused for every chunk inside one request.
 * Only the first chunk of a request pays for the seek.
 *
 * @package TakumiVault
 */

defined( 'ABSPATH' ) || exit;

class TKVault_SQL_Reader {

	const BLOCK = 65536;

	/** @var TKVault_SQL_Reader|null */
	private static $cached = null;

	private $file;
	private $handle;
	private $buffer    = '';
	private $offset    = 0;
	private $eof       = false;
	private $delimiter = ';';

	/**
	 * A reader positioned at $offset, reusing the live one when it is already
	 * exactly there.
	 *
	 * Caching the gzip handle alone is not enough, and getting that wrong is
	 * silent. The handle has a position of its own that moves as it is read,
	 * and the reader holds a buffer of decompressed bytes that have been taken
	 * from the handle but not yet consumed. Hand a fresh reader an old handle
	 * and it starts wherever that handle happens to be, with the buffered
	 * bytes gone - which reads nothing at all, or worse, skips a stretch of
	 * the dump without reporting anything. The whole reader is cached instead,
	 * so position and buffer always travel together.
	 */
	public static function open_at( $file, $offset = 0, $delimiter = ';' ) {
		if ( self::$cached
			&& self::$cached->file === $file
			&& self::$cached->offset === (int) $offset
			&& is_resource( self::$cached->handle ) ) {
			self::$cached->delimiter = $delimiter ? $delimiter : ';';
			return self::$cached;
		}

		self::close_cached();
		self::$cached = new self( $file, $offset, $delimiter );

		return self::$cached;
	}

	public function __construct( $file, $offset = 0, $delimiter = ';' ) {
		$this->file      = $file;
		$this->delimiter = $delimiter ? $delimiter : ';';

		$handle = gzopen( $file, 'rb' );
		if ( ! $handle ) {
			throw new RuntimeException( esc_html__( 'Could not open the backup archive.', 'takumi-vault' ) );
		}

		if ( $offset > 0 ) {
			gzseek( $handle, $offset );
		}

		$this->handle = $handle;
		$this->offset = (int) $offset;
	}

	public static function close_cached() {
		if ( self::$cached && is_resource( self::$cached->handle ) ) {
			gzclose( self::$cached->handle );
		}
		self::$cached = null;
	}

	public function tell() {
		return $this->offset;
	}

	public function delimiter() {
		return $this->delimiter;
	}

	private function fill() {
		if ( $this->eof ) {
			return false;
		}
		$chunk = gzread( $this->handle, self::BLOCK );
		if ( '' === $chunk || false === $chunk ) {
			$this->eof = true;
			return false;
		}
		$this->buffer .= $chunk;
		return true;
	}

	/**
	 * The next statement, or null at end of file.
	 *
	 * Comments are skipped but counted towards the offset, so a caller can
	 * resume exactly where this left off.
	 */
	public function next_statement() {
		$statement = '';
		$consumed  = 0;

		$in_single = false;
		$in_double = false;
		$in_tick   = false;
		$in_line   = false;
		$in_block  = false;
		$escaped   = false;

		$delimiter     = $this->delimiter;
		$delimiter_len = strlen( $delimiter );

		while ( true ) {
			if ( '' === $this->buffer && ! $this->fill() ) {
				break;
			}

			$length = strlen( $this->buffer );

			for ( $i = 0; $i < $length; $i++ ) {
				$ch = $this->buffer[ $i ];

				if ( $in_line ) {
					$statement .= $ch;
					if ( "\n" === $ch ) {
						$in_line = false;
					}
					continue;
				}

				if ( $in_block ) {
					$statement .= $ch;
					if ( '*' === $ch && isset( $this->buffer[ $i + 1 ] ) && '/' === $this->buffer[ $i + 1 ] ) {
						$statement .= '/';
						$i++;
						$in_block = false;
					}
					continue;
				}

				if ( $in_single || $in_double || $in_tick ) {
					$statement .= $ch;

					if ( $escaped ) {
						$escaped = false;
						continue;
					}
					if ( '\\' === $ch && ! $in_tick ) {
						$escaped = true;
						continue;
					}
					if ( $in_single && "'" === $ch ) {
						// '' inside a string is an escaped quote, not the end.
						if ( isset( $this->buffer[ $i + 1 ] ) && "'" === $this->buffer[ $i + 1 ] ) {
							$statement .= "'";
							$i++;
							continue;
						}
						$in_single = false;
					} elseif ( $in_double && '"' === $ch ) {
						if ( isset( $this->buffer[ $i + 1 ] ) && '"' === $this->buffer[ $i + 1 ] ) {
							$statement .= '"';
							$i++;
							continue;
						}
						$in_double = false;
					} elseif ( $in_tick && '`' === $ch ) {
						$in_tick = false;
					}
					continue;
				}

				// Outside any quoting.
				if ( "'" === $ch ) {
					$in_single  = true;
					$statement .= $ch;
					continue;
				}
				if ( '"' === $ch ) {
					$in_double  = true;
					$statement .= $ch;
					continue;
				}
				if ( '`' === $ch ) {
					$in_tick    = true;
					$statement .= $ch;
					continue;
				}
				if ( '#' === $ch ) {
					$in_line    = true;
					$statement .= $ch;
					continue;
				}
				if ( '-' === $ch && isset( $this->buffer[ $i + 1 ] ) && '-' === $this->buffer[ $i + 1 ] ) {
					$in_line    = true;
					$statement .= '--';
					$i++;
					continue;
				}
				if ( '/' === $ch && isset( $this->buffer[ $i + 1 ] ) && '*' === $this->buffer[ $i + 1 ] ) {
					$in_block   = true;
					$statement .= '/*';
					$i++;
					continue;
				}

				if ( $ch === $delimiter[0] && substr( $this->buffer, $i, $delimiter_len ) === $delimiter ) {
					$consumed     = $i + $delimiter_len;
					$this->buffer = substr( $this->buffer, $consumed );
					$this->offset += $consumed;
					return $this->finish( $statement );
				}

				$statement .= $ch;
			}

			// Whole buffer consumed without finding a terminator.
			$this->offset += $length;
			$this->buffer  = '';

			// A partially quoted statement needs more input; keep reading.
			if ( ! $this->fill() ) {
				break;
			}
		}

		$statement = trim( $statement );

		return '' === $statement ? null : $this->finish( $statement );
	}

	/**
	 * Strip comments, honour DELIMITER, and hand back real SQL or null.
	 */
	private function finish( $statement ) {
		$clean = trim( self::strip_comments( $statement ) );

		if ( '' === $clean ) {
			return '';
		}

		if ( preg_match( '/^DELIMITER\s+(\S+)/i', $clean, $m ) ) {
			$this->delimiter = $m[1];
			return '';
		}

		return $clean;
	}

	/**
	 * Remove leading comment lines. Conditional comments are kept: MySQL
	 * treats /*!40101 ... *\/ as executable and dumps rely on that.
	 */
	public static function strip_comments( $sql ) {
		$out    = '';
		$length = strlen( $sql );

		$in_single = false;
		$in_double = false;
		$in_tick   = false;
		$escaped   = false;

		for ( $i = 0; $i < $length; $i++ ) {
			$ch = $sql[ $i ];

			if ( $in_single || $in_double || $in_tick ) {
				$out .= $ch;
				if ( $escaped ) {
					$escaped = false;
					continue;
				}
				if ( '\\' === $ch && ! $in_tick ) {
					$escaped = true;
					continue;
				}
				if ( $in_single && "'" === $ch ) {
					$in_single = false;
				} elseif ( $in_double && '"' === $ch ) {
					$in_double = false;
				} elseif ( $in_tick && '`' === $ch ) {
					$in_tick = false;
				}
				continue;
			}

			if ( "'" === $ch ) {
				$in_single = true;
				$out      .= $ch;
				continue;
			}
			if ( '"' === $ch ) {
				$in_double = true;
				$out      .= $ch;
				continue;
			}
			if ( '`' === $ch ) {
				$in_tick = true;
				$out    .= $ch;
				continue;
			}

			if ( '#' === $ch || ( '-' === $ch && isset( $sql[ $i + 1 ] ) && '-' === $sql[ $i + 1 ] ) ) {
				while ( $i < $length && "\n" !== $sql[ $i ] ) {
					$i++;
				}
				$out .= "\n";
				continue;
			}

			if ( '/' === $ch && isset( $sql[ $i + 1 ] ) && '*' === $sql[ $i + 1 ] ) {
				$conditional = isset( $sql[ $i + 2 ] ) && '!' === $sql[ $i + 2 ];
				$end         = strpos( $sql, '*/', $i + 2 );
				$end         = false === $end ? $length : $end + 2;

				if ( $conditional ) {
					$out .= substr( $sql, $i, $end - $i );
				} else {
					$out .= ' ';
				}
				$i = $end - 1;
				continue;
			}

			$out .= $ch;
		}

		return $out;
	}
}
