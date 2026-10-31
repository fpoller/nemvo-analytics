<?php
/**
 * Minimal MaxMind DB (.mmdb) reader.
 *
 * Reads GeoLite2 / GeoIP2 / DB-IP databases without Composer or the
 * maxminddb PHP extension. Implements the format described at
 * https://maxmind.github.io/MaxMind-DB/ (search tree + data section).
 *
 * @package NemvoAnalytics
 */

namespace NemvoAnalytics;

defined( 'ABSPATH' ) || exit;

/**
 * Read-only lookups by IP address.
 */
class Mmdb_Reader {

	const METADATA_MARKER = "\xAB\xCD\xEFMaxMind.com";

	/**
	 * File handle.
	 *
	 * @var resource
	 */
	private $fh;

	/**
	 * Parsed metadata map.
	 *
	 * @var array
	 */
	public $metadata;

	/**
	 * Nodes in the search tree.
	 *
	 * @var int
	 */
	private $node_count;

	/**
	 * Bits per record (24, 28 or 32).
	 *
	 * @var int
	 */
	private $record_size;

	/**
	 * Size of the search tree in bytes.
	 *
	 * @var int
	 */
	private $tree_size;

	/**
	 * Base offset that data pointers are relative to.
	 *
	 * @var int
	 */
	private $pointer_base = 0;

	/**
	 * Node where IPv4 lookups start in an IPv6 tree.
	 *
	 * @var int|null
	 */
	private $ipv4_start = null;

	/**
	 * Open a database file.
	 *
	 * @param string $file Path to an .mmdb file.
	 * @throws \RuntimeException When the file is not a valid database.
	 */
	public function __construct( $file ) {
		$fh = @fopen( $file, 'rb' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		if ( ! $fh ) {
			throw new \RuntimeException( 'Cannot open ' . esc_html( $file ) );
		}
		$this->fh = $fh;
		$size     = filesize( $file );
		$chunk    = min( $size, 128 * 1024 );
		fseek( $fh, $size - $chunk );
		$tail = fread( $fh, $chunk ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread
		$pos  = strrpos( $tail, self::METADATA_MARKER );
		if ( false === $pos ) {
			throw new \RuntimeException( 'Not a MaxMind DB file' );
		}
		$meta_start         = $size - $chunk + $pos + strlen( self::METADATA_MARKER );
		$this->pointer_base = $meta_start;
		list( $meta )       = $this->decode( $meta_start );
		if ( ! is_array( $meta ) || ! isset( $meta['node_count'], $meta['record_size'] ) ) {
			throw new \RuntimeException( 'Invalid MaxMind DB metadata' );
		}
		$this->metadata     = $meta;
		$this->node_count   = (int) $meta['node_count'];
		$this->record_size  = (int) $meta['record_size'];
		$this->tree_size    = (int) ( $this->node_count * $this->record_size * 2 / 8 );
		$this->pointer_base = $this->tree_size + 16;
		if ( ! in_array( $this->record_size, array( 24, 28, 32 ), true ) ) {
			throw new \RuntimeException( 'Unsupported record size' );
		}
	}

	/**
	 * Close the file.
	 */
	public function __destruct() {
		if ( is_resource( $this->fh ) ) {
			fclose( $this->fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		}
	}

	/**
	 * Look up an IP address.
	 *
	 * @param string $ip IPv4 or IPv6 address.
	 * @return array|null Record, or null when not found.
	 */
	public function get( $ip ) {
		$packed = @inet_pton( $ip ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		if ( false === $packed ) {
			return null;
		}
		$bits = strlen( $packed ) * 8;
		if ( 32 === $bits && 6 === (int) $this->metadata['ip_version'] ) {
			$node = $this->ipv4_start();
		} elseif ( 128 === $bits && 4 === (int) $this->metadata['ip_version'] ) {
			return null;
		} else {
			$node = 0;
		}

		for ( $i = 0; $i < $bits && $node < $this->node_count; $i++ ) {
			$bit  = 1 & ( ord( $packed[ $i >> 3 ] ) >> ( 7 - ( $i % 8 ) ) );
			$node = $this->read_node( $node, $bit );
		}
		if ( $node === $this->node_count ) {
			return null;
		}
		if ( $node > $this->node_count ) {
			list( $record ) = $this->decode( $node - $this->node_count + $this->tree_size );
			return is_array( $record ) ? $record : null;
		}
		return null;
	}

	/**
	 * IPv4 addresses live under ::/96 in an IPv6 tree.
	 *
	 * @return int
	 */
	private function ipv4_start() {
		if ( null === $this->ipv4_start ) {
			$node = 0;
			for ( $i = 0; $i < 96 && $node < $this->node_count; $i++ ) {
				$node = $this->read_node( $node, 0 );
			}
			$this->ipv4_start = $node;
		}
		return $this->ipv4_start;
	}

	/**
	 * Read the left (0) or right (1) record of a node.
	 *
	 * @param int $node  Node number.
	 * @param int $index 0 or 1.
	 * @return int
	 */
	private function read_node( $node, $index ) {
		$base = $node * $this->record_size * 2 / 8;
		switch ( $this->record_size ) {
			case 24:
				$b = $this->read( $base + $index * 3, 3 );
				return unpack( 'N', "\0" . $b )[1];
			case 28:
				$b = $this->read( $base + 3 * $index, 4 );
				if ( 0 === $index ) {
					return ( ( ord( $b[3] ) & 0xF0 ) << 20 ) | unpack( 'N', "\0" . substr( $b, 0, 3 ) )[1];
				}
				return ( ( ord( $b[0] ) & 0x0F ) << 24 ) | unpack( 'N', "\0" . substr( $b, 1, 3 ) )[1];
			default:
				return unpack( 'N', $this->read( $base + $index * 4, 4 ) )[1];
		}
	}

	/**
	 * Read bytes at an absolute offset.
	 *
	 * @param int $offset Offset.
	 * @param int $length Length.
	 * @return string
	 */
	private function read( $offset, $length ) {
		if ( 0 === $length ) {
			return '';
		}
		fseek( $this->fh, (int) $offset );
		return (string) fread( $this->fh, $length ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread
	}

	/**
	 * Decode one data field.
	 *
	 * @param int $offset Absolute offset.
	 * @return array [value, next offset]
	 * @throws \RuntimeException On corrupt data.
	 */
	private function decode( $offset ) {
		$ctrl = ord( $this->read( $offset, 1 ) );
		++$offset;
		$type = $ctrl >> 5;

		if ( 1 === $type ) {
			$psize = ( ( $ctrl >> 3 ) & 0x3 ) + 1;
			$buf   = $this->read( $offset, $psize );
			$vvv   = $ctrl & 0x7;
			switch ( $psize ) {
				case 1:
					$pointer = ( $vvv << 8 ) | ord( $buf[0] );
					break;
				case 2:
					$pointer = 2048 + ( ( $vvv << 16 ) | unpack( 'n', $buf )[1] );
					break;
				case 3:
					$pointer = 526336 + ( ( $vvv << 24 ) | unpack( 'N', "\0" . $buf )[1] );
					break;
				default:
					$pointer = unpack( 'N', $buf )[1];
			}
			list( $value ) = $this->decode( $this->pointer_base + $pointer );
			return array( $value, $offset + $psize );
		}

		if ( 0 === $type ) {
			$type = 7 + ord( $this->read( $offset, 1 ) );
			++$offset;
		}

		$size = $ctrl & 0x1f;
		if ( $size >= 29 ) {
			if ( 29 === $size ) {
				$size = 29 + ord( $this->read( $offset, 1 ) );
				++$offset;
			} elseif ( 30 === $size ) {
				$size    = 285 + unpack( 'n', $this->read( $offset, 2 ) )[1];
				$offset += 2;
			} else {
				$size    = 65821 + unpack( 'N', "\0" . $this->read( $offset, 3 ) )[1];
				$offset += 3;
			}
		}

		switch ( $type ) {
			case 2: // UTF-8 string.
			case 4: // Bytes.
				return array( $this->read( $offset, $size ), $offset + $size );
			case 3: // Double.
				return array( unpack( 'E', $this->read( $offset, 8 ) )[1], $offset + 8 );
			case 15: // Float.
				return array( unpack( 'G', $this->read( $offset, 4 ) )[1], $offset + 4 );
			case 5: // uint16.
			case 6: // uint32.
			case 9: // uint64.
			case 10: // uint128.
				return array( $this->unsigned( $this->read( $offset, $size ) ), $offset + $size );
			case 8: // int32.
				$n = $this->unsigned( $this->read( $offset, $size ) );
				if ( 4 === $size && $n >= 0x80000000 ) {
					$n -= 0x100000000;
				}
				return array( $n, $offset + $size );
			case 7: // Map.
				$map = array();
				for ( $i = 0; $i < $size; $i++ ) {
					list( $key, $offset )   = $this->decode( $offset );
					list( $value, $offset ) = $this->decode( $offset );
					$map[ $key ]            = $value;
				}
				return array( $map, $offset );
			case 11: // Array.
				$list = array();
				for ( $i = 0; $i < $size; $i++ ) {
					list( $value, $offset ) = $this->decode( $offset );
					$list[]                 = $value;
				}
				return array( $list, $offset );
			case 14: // Boolean (value is in the size field).
				return array( 0 !== $size, $offset );
			case 13: // End marker.
			case 12: // Data cache container.
				return array( null, $offset );
		}
		throw new \RuntimeException( 'Unknown MaxMind DB data type ' . (int) $type );
	}

	/**
	 * Big-endian unsigned integer (large values degrade to float).
	 *
	 * @param string $bytes Bytes.
	 * @return int|float
	 */
	private function unsigned( $bytes ) {
		$n   = 0;
		$len = strlen( $bytes );
		for ( $i = 0; $i < $len; $i++ ) {
			$n = $n * 256 + ord( $bytes[ $i ] );
		}
		return $n;
	}
}
