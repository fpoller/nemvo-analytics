<?php
/**
 * Reverse DNS lookups with a hard timeout.
 *
 * @package NemvoAnalytics
 */

namespace NemvoAnalytics;

defined( 'ABSPATH' ) || exit;

/**
 * A minimal, dependency-free stub resolver. gethostbyaddr() can block for
 * seconds and cannot be time-limited, so queries are built by hand and sent
 * over UDP to the system's nameservers, with a deadline per query.
 */
class Dns {

	const TYPE_A     = 1;
	const TYPE_CNAME = 5;
	const TYPE_PTR   = 12;
	const TYPE_AAAA  = 28;

	/**
	 * Nameservers tried per query, at most.
	 */
	const MAX_SERVERS = 3;

	/**
	 * Nameservers from /etc/resolv.conf (null until read).
	 *
	 * @var string[]|null
	 */
	private static $system_servers = null;

	/**
	 * Reverse DNS (PTR) for an IPv4 or IPv6 address, forward-confirmed: the
	 * hostname must resolve back to the same IP (A or AAAA), so a PTR record
	 * cannot claim someone else's domain.
	 *
	 * Worst case it takes 2 queries x $timeout x nameservers tried.
	 *
	 * @param string $ip      Address.
	 * @param float  $timeout Seconds per query (default 0.4).
	 * @return string Lowercase hostname without trailing dot, or '' (none, timeout, not confirmed, private/reserved address).
	 */
	public static function reverse( $ip, $timeout = 0.4 ) {
		$ip = trim( (string) $ip );
		if ( ! filter_var( $ip, FILTER_VALIDATE_IP ) ) {
			return '';
		}
		$packed = inet_pton( $ip );
		// IPv4-mapped IPv6 (::ffff:192.0.2.1, from dual-stack sockets): use the IPv4 address.
		if ( 16 === strlen( $packed ) && 0 === strpos( $packed, str_repeat( "\0", 10 ) . "\xff\xff" ) ) {
			$packed = substr( $packed, 12 );
			$ip     = (string) inet_ntop( $packed );
		}
		// Private, loopback, link-local, reserved and carrier-grade NAT (100.64.0.0/10) addresses.
		if ( ! filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) || ( 4 === strlen( $packed ) && 100 === ord( $packed[0] ) && 64 === ( ord( $packed[1] ) & 0xC0 ) ) ) {
			return '';
		}

		$servers = self::servers();
		if ( ! $servers ) {
			return '';
		}
		$timeout = min( 5.0, max( 0.05, (float) $timeout ) );

		$host = '';
		foreach ( self::query( $servers, self::ptr_name( $packed ), self::TYPE_PTR, $timeout ) as $name ) {
			if ( self::is_hostname( $name ) ) {
				$host = $name;
				break;
			}
		}
		if ( '' === $host ) {
			return '';
		}

		$type = 4 === strlen( $packed ) ? self::TYPE_A : self::TYPE_AAAA;
		foreach ( self::query( $servers, $host, $type, $timeout ) as $address ) {
			if ( $address === $packed ) {
				return $host;
			}
		}
		return '';
	}

	/**
	 * Nameservers to ask: the first two from /etc/resolv.conf, filterable.
	 * There is deliberately no fallback to public resolvers.
	 *
	 * @return string[] IP addresses.
	 */
	private static function servers() {
		if ( null === self::$system_servers ) {
			self::$system_servers = array();
			$lines                = @file( '/etc/resolv.conf', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- may be missing or outside open_basedir.
			if ( is_array( $lines ) ) {
				foreach ( $lines as $line ) {
					// Link-local servers with a zone (fe80::1%eth0) cannot be addressed by stream sockets.
					if ( preg_match( '/^\s*nameserver\s+([0-9a-fA-F.:]+)\s*(?:[#;].*)?$/', $line, $m ) && filter_var( $m[1], FILTER_VALIDATE_IP ) ) {
						self::$system_servers[] = $m[1];
						if ( 2 === count( self::$system_servers ) ) {
							break;
						}
					}
				}
			}
		}

		/**
		 * Filters the nameservers used for reverse DNS lookups of visitors.
		 *
		 * @param string[] $servers IP addresses (at most two, from /etc/resolv.conf).
		 */
		$servers = (array) apply_filters( 'nemvo_analytics_dns_servers', self::$system_servers );
		$valid   = array();
		foreach ( $servers as $server ) {
			$server = is_string( $server ) ? trim( $server ) : '';
			if ( '' !== $server && filter_var( $server, FILTER_VALIDATE_IP ) && ! in_array( $server, $valid, true ) ) {
				$valid[] = $server;
			}
		}
		return array_slice( $valid, 0, self::MAX_SERVERS );
	}

	/**
	 * Ask the nameservers in turn until one gives a usable answer.
	 *
	 * @param string[] $servers Nameserver IPs.
	 * @param string   $name    Query name.
	 * @param int      $type    Record type.
	 * @param float    $timeout Seconds per nameserver.
	 * @return array Record data: hostnames (PTR) or packed addresses (A/AAAA).
	 */
	private static function query( array $servers, $name, $type, $timeout ) {
		$id     = self::random_id();
		$packet = self::packet( $id, $name, $type );
		if ( '' === $packet ) {
			return array();
		}
		foreach ( $servers as $server ) {
			$response = self::exchange( $server, $packet, $id, $timeout );
			if ( null === $response ) {
				continue; // Timeout or network error: try the next server.
			}
			$records = self::parse( $response, $id, $name, $type );
			if ( null !== $records ) {
				return $records;
			}
		}
		return array();
	}

	/**
	 * Send a query over UDP and wait for the matching response.
	 *
	 * @param string $server  Nameserver IP.
	 * @param string $packet  Query packet.
	 * @param int    $id      Query id.
	 * @param float  $timeout Seconds.
	 * @return string|null Response, or null on timeout/error.
	 */
	private static function exchange( $server, $packet, $id, $timeout ) {
		$address = false !== strpos( $server, ':' ) ? '[' . $server . ']' : $server;
		$errno   = 0;
		$errstr  = '';
		$socket  = @stream_socket_client( 'udp://' . $address . ':53', $errno, $errstr, $timeout ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		if ( ! $socket ) {
			return null;
		}
		$deadline = microtime( true ) + $timeout;
		$response = null;
		if ( strlen( $packet ) === @fwrite( $socket, $packet ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
			while ( null === $response ) {
				$left = $deadline - microtime( true );
				if ( $left < 0.001 ) {
					break;
				}
				stream_set_timeout( $socket, (int) $left, (int) ( ( $left - floor( $left ) ) * 1000000 ) );
				// One datagram per read; fails on timeout or ICMP "port unreachable".
				$data = @fread( $socket, 4096 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_fread
				if ( ! is_string( $data ) || '' === $data ) {
					break;
				}
				// Ignore stray datagrams (late answers to an earlier query).
				if ( strlen( $data ) >= 12 && ( ( ord( $data[0] ) << 8 ) | ord( $data[1] ) ) === $id ) {
					$response = $data;
				}
			}
		}
		fclose( $socket ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		return $response;
	}

	/**
	 * Build a query packet: header with the RD flag, one question, class IN.
	 *
	 * @param int    $id   Query id.
	 * @param string $name Query name.
	 * @param int    $type Record type.
	 * @return string Packet, or '' when the name cannot be encoded.
	 */
	private static function packet( $id, $name, $type ) {
		$qname = '';
		foreach ( explode( '.', $name ) as $label ) {
			$length = strlen( $label );
			if ( $length < 1 || $length > 63 ) {
				return '';
			}
			$qname .= chr( $length ) . $label;
		}
		$qname .= "\0";
		if ( strlen( $qname ) > 255 ) {
			return '';
		}
		return pack( 'nnnnnn', $id, 0x0100, 1, 0, 0, 0 ) . $qname . pack( 'nn', $type, 1 );
	}

	/**
	 * Parse a response. Every read is bounds-checked: truncated or garbage
	 * packets yield null, never a PHP warning.
	 *
	 * @param string $message Response packet.
	 * @param int    $id      Expected query id.
	 * @param string $name    Query name.
	 * @param int    $type    Query type.
	 * @return array|null Record data for $name (following CNAMEs in the answer);
	 *                    an empty array for NXDOMAIN / no data; null when the
	 *                    response is unusable and the next server should be asked.
	 */
	private static function parse( $message, $id, $name, $type ) {
		$length = strlen( $message );
		if ( $length < 12 ) {
			return null;
		}
		$header = unpack( 'nid/nflags/nqd/nan', $message );
		if ( $id !== $header['id'] || ! ( $header['flags'] & 0x8000 ) ) {
			return null;
		}
		$rcode = $header['flags'] & 0x000F;
		if ( 3 === $rcode ) {
			return array(); // NXDOMAIN.
		}
		if ( 0 !== $rcode || 1 !== $header['qd'] ) {
			return null; // SERVFAIL, REFUSED, ...
		}

		// The question must be ours.
		$offset = 12;
		$qname  = self::read_name( $message, $offset );
		if ( null === $qname || $offset + 4 > $length || 0 !== strcasecmp( $qname, $name ) ) {
			return null;
		}
		$question = unpack( 'ntype/nclass', substr( $message, $offset, 4 ) );
		if ( $type !== $question['type'] ) {
			return null;
		}
		$offset += 4;

		$aliases = array();
		$records = array();
		for ( $i = 0; $i < $header['an']; $i++ ) {
			$owner = self::read_name( $message, $offset );
			if ( null === $owner || $offset + 10 > $length ) {
				break;
			}
			$rr      = unpack( 'ntype/nclass/Nttl/nlength', substr( $message, $offset, 10 ) );
			$offset += 10;
			$end     = $offset + $rr['length'];
			if ( $end > $length ) {
				break;
			}
			if ( 1 === $rr['class'] ) {
				$owner = strtolower( $owner );
				if ( self::TYPE_CNAME === $rr['type'] || ( self::TYPE_PTR === $type && self::TYPE_PTR === $rr['type'] ) ) {
					$at     = $offset;
					$target = self::read_name( $message, $at );
					if ( null !== $target && $at <= $end ) {
						if ( self::TYPE_CNAME === $rr['type'] ) {
							$aliases[ $owner ] = strtolower( $target );
						} else {
							$records[] = array( $owner, strtolower( $target ) );
						}
					}
				} elseif ( $type === $rr['type'] && ( ( self::TYPE_A === $type && 4 === $rr['length'] ) || ( self::TYPE_AAAA === $type && 16 === $rr['length'] ) ) ) {
					$records[] = array( $owner, substr( $message, $offset, $rr['length'] ) );
				}
			}
			$offset = $end;
		}

		// Follow CNAMEs within the answer (aliases, RFC 2317 classless reverse delegation).
		$current = strtolower( $name );
		for ( $hops = 0; isset( $aliases[ $current ] ) && $hops < 8; $hops++ ) {
			$current = $aliases[ $current ];
		}
		$out = array();
		foreach ( $records as $record ) {
			if ( $record[0] === $current ) {
				$out[] = $record[1];
			}
		}
		return array_values( array_unique( $out ) );
	}

	/**
	 * Read a possibly compressed domain name.
	 *
	 * @param string $message Packet.
	 * @param int    $offset  Start; moved past the name (or its first pointer).
	 * @return string|null Dotted name, or null when malformed.
	 */
	private static function read_name( $message, &$offset ) {
		$length = strlen( $message );
		$pos    = $offset;
		$labels = array();
		$size   = 0;
		$jumped = false;
		// A name has at most 127 labels; the guard also stops pointer loops.
		for ( $guard = 0; $guard < 160; $guard++ ) {
			if ( $pos >= $length ) {
				return null;
			}
			$byte = ord( $message[ $pos ] );
			if ( 0 === $byte ) {
				if ( ! $jumped ) {
					$offset = $pos + 1;
				}
				return implode( '.', $labels );
			}
			if ( 0xC0 === ( $byte & 0xC0 ) ) {
				if ( $pos + 1 >= $length ) {
					return null;
				}
				if ( ! $jumped ) {
					$offset = $pos + 2;
					$jumped = true;
				}
				$pos = ( ( $byte & 0x3F ) << 8 ) | ord( $message[ $pos + 1 ] );
				continue;
			}
			if ( $byte & 0xC0 ) {
				return null; // Reserved label types.
			}
			if ( $pos + 1 + $byte > $length ) {
				return null;
			}
			$size += $byte + 1;
			if ( $size > 255 ) {
				return null;
			}
			$labels[] = substr( $message, $pos + 1, $byte );
			$pos     += 1 + $byte;
		}
		return null;
	}

	/**
	 * Reverse lookup name: 4.3.2.1.in-addr.arpa or nibbles under ip6.arpa.
	 *
	 * @param string $packed Packed address (inet_pton).
	 * @return string
	 */
	private static function ptr_name( $packed ) {
		if ( 4 === strlen( $packed ) ) {
			return implode( '.', array_reverse( array_map( 'ord', str_split( $packed ) ) ) ) . '.in-addr.arpa';
		}
		return implode( '.', array_reverse( str_split( bin2hex( $packed ) ) ) ) . '.ip6.arpa';
	}

	/**
	 * Whether a PTR target is a sane hostname.
	 *
	 * @param string $name Name from a PTR record.
	 * @return bool
	 */
	private static function is_hostname( $name ) {
		return strlen( $name ) <= 253 && (bool) preg_match( '/^(?:[a-z0-9_](?:[a-z0-9_-]{0,61}[a-z0-9_])?\.)+[a-z](?:[a-z0-9-]{0,61}[a-z0-9])?$/', $name );
	}

	/**
	 * Unpredictable 16-bit query id.
	 *
	 * @return int
	 */
	private static function random_id() {
		try {
			return random_int( 0, 0xFFFF );
		} catch ( \Exception $e ) {
			return wp_rand( 0, 0xFFFF );
		}
	}
}
