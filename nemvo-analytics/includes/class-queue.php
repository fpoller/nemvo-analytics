<?php
/**
 * Optional Valkey (or Redis) queue for collected hits.
 *
 * @package NemvoAnalytics
 */

namespace NemvoAnalytics;

defined( 'ABSPATH' ) || exit;

/**
 * With the queue on, the collection endpoint only validates and enriches a
 * hit and appends it to a Valkey list (one network round trip). A worker
 * (WP-CLI or WP-Cron) pops the list in batches and writes each batch in one
 * database transaction, which keeps the database calm during traffic peaks.
 *
 * Talks RESP over a plain socket, so neither the phpredis extension nor a
 * Composer library is needed. If Valkey cannot be reached, hits are written
 * directly to the database instead; nothing is lost.
 */
class Queue {

	const STATE_OPTION = 'nemvo_analytics_queue_state';

	/**
	 * Cached configuration (false = not read yet).
	 *
	 * @var array|null|false
	 */
	private static $cfg = false;

	/**
	 * Forget the cached configuration (after settings change, in tests).
	 */
	public static function reset() {
		self::$cfg = false;
		self::close();
	}

	/**
	 * Open socket (false after a failed attempt).
	 *
	 * @var resource|false|null
	 */
	private static $sock = null;

	/**
	 * Last error.
	 *
	 * @var string
	 */
	private static $error = '';

	/**
	 * The queue configuration, or null when the queue is off.
	 * NEMVO_ANALYTICS_QUEUE_URL (redis://[:password@]host[:port][/db]) turns
	 * it on and wins over the settings page.
	 *
	 * @return array{source:string,host:string,port:int,password:string,db:int,prefix:string}|null
	 */
	public static function config() {
		if ( false !== self::$cfg ) {
			return self::$cfg;
		}
		$cfg = null;
		$url = Settings::env( 'NEMVO_ANALYTICS_QUEUE_URL' );
		if ( '' !== $url ) {
			$parts = wp_parse_url( $url );
			$cfg   = array(
				'source'   => defined( 'NEMVO_ANALYTICS_QUEUE_URL' ) ? 'constant' : 'env',
				'host'     => (string) ( $parts['host'] ?? '' ),
				'port'     => (int) ( $parts['port'] ?? 6379 ),
				'password' => isset( $parts['pass'] ) ? rawurldecode( $parts['pass'] ) : ( isset( $parts['user'] ) && ! isset( $parts['pass'] ) ? rawurldecode( $parts['user'] ) : '' ),
				'db'       => (int) trim( (string) ( $parts['path'] ?? '' ), '/' ),
				'prefix'   => '' !== Settings::env( 'NEMVO_ANALYTICS_QUEUE_PREFIX' ) ? Settings::env( 'NEMVO_ANALYTICS_QUEUE_PREFIX' ) : 'nemvo:',
			);
			self::$cfg = $cfg;
		return $cfg;
		}
		$s = Settings::services();
		if ( ! $s['queue_enabled'] || '' === $s['queue_host'] ) {
			$cfg = null;
			self::$cfg = $cfg;
		return $cfg;
		}
		$cfg = self::from_settings( $s );
		self::$cfg = $cfg;
		return $cfg;
	}

	/**
	 * Connection settings from the services option.
	 *
	 * @param array $s Services settings.
	 * @return array
	 */
	public static function from_settings( array $s ) {
		return array(
			'source'   => 'settings',
			'host'     => $s['queue_host'],
			'port'     => (int) $s['queue_port'],
			'password' => $s['queue_password'],
			'db'       => (int) $s['queue_db'],
			'prefix'   => $s['queue_prefix'],
		);
	}

	/**
	 * Whether hits go through the queue.
	 *
	 * @return bool
	 */
	public static function enabled() {
		return null !== self::config();
	}

	/**
	 * Last error.
	 *
	 * @return string
	 */
	public static function error() {
		return self::$error;
	}

	/**
	 * Name of the list holding hits.
	 *
	 * @return string
	 */
	private static function key( $name = 'hits' ) {
		$cfg = self::config();
		return ( $cfg ? $cfg['prefix'] : 'nemvo:' ) . $name;
	}

	/**
	 * Append a hit. Returns false when the queue is unreachable, so the
	 * caller can write it directly.
	 *
	 * @param array $hit Enriched hit.
	 * @return bool
	 */
	public static function push( array $hit ) {
		$reply = self::command( array( 'RPUSH', self::key(), wp_json_encode( $hit ) ) );
		return is_int( $reply );
	}

	/**
	 * Take up to $count hits from the front of the queue.
	 *
	 * @param int $count Batch size.
	 * @return array[]|null Null when the queue is unreachable.
	 */
	public static function pop( $count ) {
		$reply = self::command( array( 'LPOP', self::key(), (string) max( 1, (int) $count ) ) );
		if ( null === $reply ) {
			return '' === self::$error ? array() : null;
		}
		if ( ! is_array( $reply ) ) {
			return null;
		}
		$hits = array();
		foreach ( $reply as $raw ) {
			$hit = json_decode( (string) $raw, true );
			if ( is_array( $hit ) ) {
				$hits[] = $hit;
			}
		}
		return $hits;
	}

	/**
	 * Put hits back at the front (a batch that failed to write).
	 *
	 * @param array[] $hits Hits in their original order.
	 */
	public static function requeue( array $hits ) {
		if ( ! $hits ) {
			return;
		}
		$args = array( 'LPUSH', self::key() );
		foreach ( array_reverse( $hits ) as $hit ) {
			$args[] = wp_json_encode( $hit );
		}
		self::command( $args );
	}

	/**
	 * Hits waiting.
	 *
	 * @return int|null Null when unreachable.
	 */
	public static function length() {
		$reply = self::command( array( 'LLEN', self::key() ) );
		return is_int( $reply ) ? $reply : null;
	}

	/**
	 * Remember a short-lived fact about a visitor ("seen", "verified").
	 * Returns true when the key was new.
	 *
	 * @param string $name    Fact.
	 * @param string $visitor Visitor hash.
	 * @param int    $ttl     Seconds.
	 * @return bool
	 */
	public static function mark( $name, $visitor, $ttl ) {
		return 'OK' === self::command( array( 'SET', self::key( $name . ':' . $visitor ), '1', 'NX', 'EX', (string) $ttl ) );
	}

	/**
	 * Whether a fact about a visitor is known.
	 *
	 * @param string $name    Fact.
	 * @param string $visitor Visitor hash.
	 * @return bool
	 */
	public static function has( $name, $visitor ) {
		return 1 === self::command( array( 'EXISTS', self::key( $name . ':' . $visitor ) ) );
	}

	/**
	 * Process queued hits for up to $seconds.
	 *
	 * @param int $seconds Time budget.
	 * @param int $batch   Hits per transaction.
	 * @return int Hits processed.
	 */
	public static function work( $seconds = 20, $batch = 500 ) {
		$start = microtime( true );
		$done  = 0;
		do {
			$hits = self::pop( $batch );
			if ( ! $hits ) {
				break;
			}
			if ( ! Processor::process( $hits ) ) {
				self::requeue( $hits );
				break;
			}
			$done += count( $hits );
		} while ( count( $hits ) === $batch && microtime( true ) - $start < $seconds );

		if ( null !== $hits ) {
			update_option(
				self::STATE_OPTION,
				array(
					'last_run' => time(),
					'last_n'   => $done,
				),
				false
			);
		}
		return $done;
	}

	/**
	 * Check a connection (settings page).
	 *
	 * @param array $cfg Connection settings.
	 * @return array{ok:bool,message:string}
	 */
	public static function test( array $cfg ) {
		self::close();
		$sock = self::connect( $cfg );
		if ( ! $sock ) {
			return array(
				'ok'      => false,
				'message' => self::$error,
			);
		}
		self::$sock = $sock;
		$pong       = self::command( array( 'PING' ) );
		$info       = (string) self::command( array( 'INFO', 'server' ) );
		self::close();
		if ( 'PONG' !== $pong ) {
			return array(
				'ok'      => false,
				'message' => self::$error ? self::$error : __( 'The server did not answer PING.', 'nemvo-analytics' ),
			);
		}
		$version = preg_match( '/valkey_version:([\d.]+)/', $info, $m ) || preg_match( '/redis_version:([\d.]+)/', $info, $m ) ? $m[1] : '';
		$product = false !== strpos( $info, 'valkey_version' ) ? 'Valkey' : 'Redis';
		return array(
			'ok'      => true,
			/* translators: 1: Valkey or Redis, 2: version */
			'message' => sprintf( __( 'Connected to %1$s %2$s.', 'nemvo-analytics' ), $product, $version ),
		);
	}

	/**
	 * Close the socket.
	 */
	public static function close() {
		if ( is_resource( self::$sock ) ) {
			fclose( self::$sock ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		}
		self::$sock = null;
	}

	/**
	 * Open a connection, authenticate and select the database.
	 *
	 * @param array $cfg Connection settings.
	 * @return resource|false
	 */
	private static function connect( array $cfg ) {
		$host = $cfg['host'];
		if ( ! function_exists( 'stream_socket_client' ) ) {
			self::$error = __( 'This server does not allow socket connections (stream_socket_client is disabled).', 'nemvo-analytics' );
			return false;
		}
		$addr = 0 === strpos( $host, '/' ) ? 'unix://' . $host : 'tcp://' . $host . ':' . (int) $cfg['port'];
		$sock = @stream_socket_client( $addr, $errno, $errstr, 0.5 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- reported through $errstr.
		if ( ! $sock ) {
			/* translators: 1: address, 2: error */
			self::$error = sprintf( __( 'Could not connect to %1$s: %2$s', 'nemvo-analytics' ), $host, $errstr );
			return false;
		}
		stream_set_timeout( $sock, 1 );
		$prev       = self::$sock;
		self::$sock = $sock;
		$ok         = ( '' === (string) $cfg['password'] || 'OK' === self::command( array( 'AUTH', (string) $cfg['password'] ), false ) )
			&& ( (int) $cfg['db'] < 1 || 'OK' === self::command( array( 'SELECT', (string) (int) $cfg['db'] ), false ) );
		self::$sock = $prev;
		if ( ! $ok ) {
			if ( is_resource( $sock ) ) {
				fclose( $sock ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
			}
			return false;
		}
		return $sock;
	}

	/**
	 * Send one command and read the reply.
	 *
	 * @param string[] $args    Command and arguments.
	 * @param bool     $connect Connect on demand.
	 * @return mixed Reply (string, int, array or null); null with error() set on failure.
	 */
	private static function command( array $args, $connect = true ) {
		self::$error = '';
		if ( $connect && null === self::$sock ) {
			$cfg        = self::config();
			self::$sock = $cfg ? self::connect( $cfg ) : false;
			if ( ! $cfg ) {
				self::$error = __( 'The queue is not configured.', 'nemvo-analytics' );
			}
		}
		if ( ! self::$sock ) {
			if ( '' === self::$error ) {
				self::$error = __( 'The queue is not reachable.', 'nemvo-analytics' );
			}
			return null;
		}
		$out = '*' . count( $args ) . "\r\n";
		foreach ( $args as $arg ) {
			$out .= '$' . strlen( $arg ) . "\r\n" . $arg . "\r\n";
		}
		if ( false === @fwrite( self::$sock, $out ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- a socket; failure is handled.
			self::fail( __( 'The connection to the queue was lost.', 'nemvo-analytics' ) );
			return null;
		}
		return self::read();
	}

	/**
	 * Read one RESP reply.
	 *
	 * @return mixed
	 */
	private static function read() {
		$line = fgets( self::$sock );
		if ( false === $line || '' === $line ) {
			self::fail( __( 'The queue did not answer in time.', 'nemvo-analytics' ) );
			return null;
		}
		$type = $line[0];
		$data = substr( $line, 1, -2 );
		switch ( $type ) {
			case '+':
				return $data;
			case '-':
				self::$error = $data;
				return null;
			case ':':
				return (int) $data;
			case '$':
				$len = (int) $data;
				if ( $len < 0 ) {
					return null;
				}
				$buf = '';
				while ( strlen( $buf ) < $len + 2 ) {
					$chunk = fread( self::$sock, $len + 2 - strlen( $buf ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread
					if ( false === $chunk || '' === $chunk ) {
						self::fail( __( 'The queue did not answer in time.', 'nemvo-analytics' ) );
						return null;
					}
					$buf .= $chunk;
				}
				return substr( $buf, 0, $len );
			case '*':
				$count = (int) $data;
				if ( $count < 0 ) {
					return null;
				}
				$items = array();
				for ( $i = 0; $i < $count; $i++ ) {
					$items[] = self::read();
					if ( '' !== self::$error ) {
						return null;
					}
				}
				return $items;
		}
		self::fail( __( 'Unexpected answer from the queue.', 'nemvo-analytics' ) );
		return null;
	}

	/**
	 * Drop a broken connection.
	 *
	 * @param string $message Error.
	 */
	private static function fail( $message ) {
		self::close();
		self::$sock  = false;
		self::$error = $message;
	}
}
