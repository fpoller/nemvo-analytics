<?php
/**
 * Privacy-friendly geolocation and network lookup.
 *
 * @package NemvoAnalytics
 */

namespace NemvoAnalytics;

defined( 'ABSPATH' ) || exit;

/**
 * Resolves location and network without storing the IP address:
 *  1. Country headers set by a CDN/proxy (Cloudflare, CloudFront, ...).
 *  2. A local MaxMind/DB-IP .mmdb database, looked up in memory.
 *  3. The browser's time zone (no IP needed at all).
 *  4. The region part of the browser language (sv-SE => SE).
 * The network (ASN and its owner) comes from an ASN .mmdb database.
 */
class Geo {

	const STATE_OPTION = 'nemvo_analytics_geo';
	const DBIP_URL     = 'https://download.db-ip.com/free/dbip-%1$s-lite-%2$s.mmdb.gz';
	const EDITIONS     = array( 'country', 'city', 'asn' );

	/**
	 * Readers per kind (false when unavailable).
	 *
	 * @var array
	 */
	private static $readers = array();

	/**
	 * Locate a visitor.
	 *
	 * @param string $ip       Client IP (used in memory only).
	 * @param string $timezone IANA time zone reported by the browser.
	 * @param string $language Browser language, e.g. sv-SE.
	 * @return array{country:string,region:string,city:string}
	 */
	public static function locate( $ip, $timezone, $language ) {
		$out = array(
			'country' => '',
			'region'  => '',
			'city'    => '',
		);

		if ( Settings::get( 'geo_headers' ) ) {
			$out = array_merge( $out, self::from_headers() );
		}

		if ( '' === $out['country'] ) {
			$record = self::lookup( 'geo', $ip );
			if ( $record ) {
				$out['country'] = strtoupper( (string) ( $record['country']['iso_code'] ?? $record['registered_country']['iso_code'] ?? '' ) );
				$out['region']  = (string) ( $record['subdivisions'][0]['names']['en'] ?? '' );
				$out['city']    = (string) ( $record['city']['names']['en'] ?? '' );
			}
		}

		if ( '' === $out['country'] && Settings::get( 'geo_timezone' ) ) {
			$out['country'] = self::from_timezone( $timezone );
		}

		if ( '' === $out['country'] && preg_match( '/^[a-z]{2,3}[-_]([A-Z]{2})$/i', (string) $language, $m ) ) {
			$out['country'] = strtoupper( $m[1] );
		}

		$countries = self::countries();
		if ( ! isset( $countries[ $out['country'] ] ) ) {
			$out['country'] = '';
		}

		$precision = Settings::get( 'geo_precision' );
		if ( 'country' === $precision ) {
			$out['region'] = '';
			$out['city']   = '';
		} elseif ( 'region' === $precision ) {
			$out['city'] = '';
		}
		$out['region'] = mb_substr( sanitize_text_field( $out['region'] ), 0, 100 );
		$out['city']   = mb_substr( sanitize_text_field( $out['city'] ), 0, 100 );
		return $out;
	}

	/**
	 * Network of an IP: ASN, its owner and the network type. The reverse DNS
	 * lookup that separates offices from homes on ISP networks only runs when
	 * $hostname is true and the ASN alone cannot tell.
	 *
	 * @param string $ip       Client IP (memory only).
	 * @param bool   $hostname Look up reverse DNS when useful.
	 * @return array{asn:int,provider:string,network:string,company:string}
	 */
	public static function network( $ip, $hostname ) {
		$out = array(
			'asn'      => 0,
			'provider' => '',
			'network'  => '',
			'company'  => '',
		);
		if ( ! Settings::get( 'network_lookup' ) ) {
			return $out;
		}
		try {
			$record = self::lookup( 'asn', $ip );
			$asn    = (int) ( $record['autonomous_system_number'] ?? 0 );
			$org    = (string) ( $record['autonomous_system_organization'] ?? '' );
			$host   = '';
			if ( $hostname && Settings::get( 'rdns_lookup' ) && Network::needs_hostname( $asn, $org ) ) {
				$host = Dns::reverse( $ip );
			}
			$net = Network::classify( $asn, $org, $host );
		} catch ( \Throwable $e ) {
			// A lookup problem must never cost the hit itself.
			return $out;
		}
		return array(
			'asn'      => $asn,
			'provider' => mb_substr( (string) $net['provider'], 0, 100 ),
			'network'  => (string) $net['type'],
			'company'  => mb_substr( (string) $net['company'], 0, 100 ),
		);
	}

	/**
	 * Country (and city where available) from trusted proxy headers.
	 *
	 * @return array
	 */
	private static function from_headers() {
		$out = array();
		foreach ( array( 'HTTP_CF_IPCOUNTRY', 'HTTP_CLOUDFRONT_VIEWER_COUNTRY', 'HTTP_X_VERCEL_IP_COUNTRY', 'HTTP_X_APPENGINE_COUNTRY', 'HTTP_X_COUNTRY_CODE', 'HTTP_X_GEO_COUNTRY', 'GEOIP_COUNTRY_CODE' ) as $header ) {
			if ( ! empty( $_SERVER[ $header ] ) ) {
				$code = strtoupper( substr( sanitize_text_field( wp_unslash( $_SERVER[ $header ] ) ), 0, 2 ) );
				if ( preg_match( '/^[A-Z]{2}$/', $code ) && ! in_array( $code, array( 'XX', 'T1', 'ZZ' ), true ) ) {
					$out['country'] = $code;
					break;
				}
			}
		}
		foreach ( array( 'HTTP_CF_REGION', 'HTTP_CLOUDFRONT_VIEWER_COUNTRY_REGION_NAME', 'HTTP_X_VERCEL_IP_COUNTRY_REGION' ) as $header ) {
			if ( ! empty( $_SERVER[ $header ] ) ) {
				$out['region'] = rawurldecode( sanitize_text_field( wp_unslash( $_SERVER[ $header ] ) ) );
				break;
			}
		}
		foreach ( array( 'HTTP_CF_IPCITY', 'HTTP_CLOUDFRONT_VIEWER_CITY', 'HTTP_X_VERCEL_IP_CITY' ) as $header ) {
			if ( ! empty( $_SERVER[ $header ] ) ) {
				$out['city'] = rawurldecode( sanitize_text_field( wp_unslash( $_SERVER[ $header ] ) ) );
				break;
			}
		}
		return $out;
	}

	/**
	 * Country for an IANA time zone.
	 *
	 * @param string $timezone Time zone.
	 * @return string
	 */
	public static function from_timezone( $timezone ) {
		static $map = null;
		if ( null === $map ) {
			$map = require NEMVO_ANALYTICS_DIR . 'includes/data/timezones.php';
		}
		return isset( $map[ $timezone ] ) ? $map[ $timezone ] : '';
	}

	/**
	 * ISO country code => English name.
	 *
	 * @return array
	 */
	public static function countries() {
		static $countries = null;
		if ( null === $countries ) {
			$countries = require NEMVO_ANALYTICS_DIR . 'includes/data/countries.php';
		}
		return $countries;
	}

	/**
	 * Directory for downloaded databases.
	 *
	 * @return string
	 */
	public static function storage_dir() {
		$uploads = wp_upload_dir( null, false );
		return trailingslashit( $uploads['basedir'] ) . 'nemvo-analytics';
	}

	/**
	 * The database file in use for a kind: the configured path, else the
	 * newest matching .mmdb in the storage directory (a City database is
	 * preferred over a Country one).
	 *
	 * @param string $kind geo|asn.
	 * @return string Empty when none.
	 */
	public static function mmdb_path( $kind = 'geo' ) {
		$path = trim( (string) Settings::get( 'asn' === $kind ? 'asn_mmdb_path' : 'geo_mmdb_path' ) );
		if ( '' !== $path ) {
			if ( ! path_is_absolute( $path ) ) {
				$path = ABSPATH . ltrim( $path, '/' );
			}
			return is_readable( $path ) ? $path : '';
		}
		$files = array_filter(
			(array) glob( self::storage_dir() . '/*.mmdb' ),
			static function ( $file ) use ( $kind ) {
				return ( 'asn' === $kind ) === ( false !== stripos( basename( $file ), 'asn' ) );
			}
		);
		if ( ! $files ) {
			return '';
		}
		usort(
			$files,
			static function ( $a, $b ) {
				$city = (int) ( false !== stripos( basename( $b ), 'city' ) ) - (int) ( false !== stripos( basename( $a ), 'city' ) );
				return $city ? $city : filemtime( $b ) - filemtime( $a );
			}
		);
		return reset( $files );
	}

	/**
	 * Look an IP up in a database.
	 *
	 * @param string $kind geo|asn.
	 * @param string $ip   Address.
	 * @return array|null
	 */
	public static function lookup( $kind, $ip ) {
		$reader = self::reader( $kind );
		if ( ! $reader || '' === $ip ) {
			return null;
		}
		try {
			$record = $reader->get( $ip );
		} catch ( \Exception $e ) {
			return null;
		}
		return is_array( $record ) ? $record : null;
	}

	/**
	 * Shared reader for a kind.
	 *
	 * @param string $kind geo|asn.
	 * @return Mmdb_Reader|null
	 */
	public static function reader( $kind = 'geo' ) {
		if ( ! isset( self::$readers[ $kind ] ) ) {
			self::$readers[ $kind ] = false;
			$path                   = self::mmdb_path( $kind );
			if ( $path ) {
				try {
					self::$readers[ $kind ] = new Mmdb_Reader( $path );
				} catch ( \Exception $e ) {
					self::$readers[ $kind ] = false;
				}
			}
		}
		return self::$readers[ $kind ] ? self::$readers[ $kind ] : null;
	}

	/**
	 * Information about a database, for the settings page.
	 *
	 * @param string $kind geo|asn.
	 * @return array Empty when none.
	 */
	public static function database_info( $kind = 'geo' ) {
		$path = self::mmdb_path( $kind );
		if ( ! $path ) {
			return array();
		}
		$info   = array(
			'path'  => $path,
			'size'  => filesize( $path ),
			'type'  => '',
			'built' => '',
			'age'   => 0,
		);
		$reader = self::reader( $kind );
		if ( $reader ) {
			$info['type'] = (string) ( $reader->metadata['database_type'] ?? '' );
			if ( isset( $reader->metadata['build_epoch'] ) ) {
				$info['built'] = gmdate( 'Y-m-d', (int) $reader->metadata['build_epoch'] );
				$info['age']   = (int) floor( ( time() - (int) $reader->metadata['build_epoch'] ) / DAY_IN_SECONDS );
			}
		}
		return $info;
	}

	/**
	 * Download a free DB-IP Lite database (CC BY 4.0): country (4 MB),
	 * city (60 MB download, about 130 MB on disk) or asn (5 MB). Only runs
	 * when an administrator asks for it, or monthly when auto-update is on.
	 *
	 * @param string $edition country|city|asn.
	 * @return string|\WP_Error Path to the database.
	 */
	public static function download_dbip( $edition ) {
		if ( ! in_array( $edition, self::EDITIONS, true ) ) {
			return new \WP_Error( 'nemvo_geo_edition', __( 'Unknown database edition.', 'nemvo-analytics' ) );
		}
		require_once ABSPATH . 'wp-admin/includes/file.php';

		$dir = self::storage_dir();
		if ( ! wp_mkdir_p( $dir ) ) {
			return new \WP_Error( 'nemvo_geo_dir', __( 'Could not create the upload directory for the IP databases.', 'nemvo-analytics' ) );
		}
		if ( ! file_exists( $dir . '/index.php' ) ) {
			file_put_contents( $dir . '/index.php', "<?php\n// Silence is golden.\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		}

		// A new edition is published at the start of each month.
		$tmp = null;
		foreach ( array( 'now', 'first day of last month' ) as $when ) {
			$tmp = download_url( sprintf( self::DBIP_URL, $edition, gmdate( 'Y-m', strtotime( $when ) ) ), 300 );
			if ( ! is_wp_error( $tmp ) ) {
				break;
			}
		}
		if ( is_wp_error( $tmp ) ) {
			return $tmp;
		}

		$target = $dir . '/dbip-' . $edition . '-lite.mmdb';
		$part   = $target . '.part';
		$in     = gzopen( $tmp, 'rb' );
		$out    = fopen( $part, 'wb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		$ok     = $in && $out;
		while ( $ok && ! gzeof( $in ) ) {
			$chunk = gzread( $in, 1048576 );
			$ok    = false !== $chunk && false !== fwrite( $out, $chunk ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
		}
		if ( $in ) {
			gzclose( $in );
		}
		if ( $out ) {
			fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		}
		wp_delete_file( $tmp );

		if ( ! $ok || ! self::valid_mmdb( $part ) ) {
			wp_delete_file( $part );
			return new \WP_Error( 'nemvo_geo_corrupt', __( 'The downloaded IP database is not valid.', 'nemvo-analytics' ) );
		}
		if ( ! rename( $part, $target ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename
			return new \WP_Error( 'nemvo_geo_write', __( 'Could not save the IP database.', 'nemvo-analytics' ) );
		}
		// One location database at a time: city replaces country and the other way round.
		if ( 'asn' !== $edition ) {
			wp_delete_file( $dir . '/dbip-' . ( 'city' === $edition ? 'country' : 'city' ) . '-lite.mmdb' );
		}

		$state               = get_option( self::STATE_OPTION, array() );
		$state               = is_array( $state ) ? $state : array();
		$state[ $edition ]   = time();
		update_option( self::STATE_OPTION, $state, false );
		self::$readers = array();
		return $target;
	}

	/**
	 * Refresh downloaded DB-IP databases older than a month (daily cron).
	 */
	public static function auto_update() {
		$state = get_option( self::STATE_OPTION, array() );
		foreach ( self::EDITIONS as $edition ) {
			if ( is_readable( self::storage_dir() . '/dbip-' . $edition . '-lite.mmdb' ) && ( empty( $state[ $edition ] ) || time() - (int) $state[ $edition ] > 30 * DAY_IN_SECONDS ) ) {
				self::download_dbip( $edition );
			}
		}
	}

	/**
	 * Whether a file ends with MaxMind DB metadata.
	 *
	 * @param string $file Path.
	 * @return bool
	 */
	private static function valid_mmdb( $file ) {
		$size = (int) @filesize( $file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		if ( $size < 1024 ) {
			return false;
		}
		$fh = fopen( $file, 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		fseek( $fh, max( 0, $size - 131072 ) );
		$tail = (string) fread( $fh, 131072 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread
		fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		return false !== strpos( $tail, Mmdb_Reader::METADATA_MARKER );
	}
}
