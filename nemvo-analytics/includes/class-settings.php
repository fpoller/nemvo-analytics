<?php
/**
 * Plugin settings: defaults, access and sanitizing.
 *
 * @package NemvoAnalytics
 */

namespace NemvoAnalytics;

defined( 'ABSPATH' ) || exit;

/**
 * Three options:
 *  - OPTION:          everything on the settings page except connections;
 *  - DB_OPTION:       the external statistics database;
 *  - SERVICES_OPTION: the Valkey queue and the Cap server.
 * Connections can also come from wp-config.php constants or environment
 * variables (see env()), which always win over the saved values.
 */
class Settings {

	const OPTION          = 'nemvo_analytics_settings';
	const DB_OPTION       = 'nemvo_analytics_db';
	const SERVICES_OPTION = 'nemvo_analytics_services';

	/**
	 * Request-level cache.
	 *
	 * @var array|null
	 */
	private static $cache = null;

	/**
	 * Default settings.
	 *
	 * @return array
	 */
	public static function defaults() {
		return array(
			'tracking_enabled'    => 1,
			'exclude_roles'       => array( 'administrator', 'editor' ),
			'respect_dnt'         => 1,
			'server_hits'         => 1,
			'exclude_ips'         => '',
			'exclude_paths'       => '',
			'session_timeout'     => 30,
			'track_outbound'      => 1,
			'track_downloads'     => 1,
			'track_forms'         => 1,
			'track_contact_links' => 1,
			'track_404'           => 1,
			'track_search'        => 1,
			'track_scroll'        => 1,
			'download_extensions' => 'pdf,doc,docx,xls,xlsx,ppt,pptx,odt,ods,zip,rar,7z,gz,csv,txt,epub,dmg,exe,msi,pkg,mp3,mp4,mov',
			'inline_script'       => 1,
			'salt_rotation'       => 'daily',
			'trust_proxy'         => 'auto',
			'geo_headers'         => 1,
			'geo_timezone'        => 1,
			'geo_mmdb_path'       => '',
			'asn_mmdb_path'       => '',
			'geo_auto_update'     => 0,
			'geo_precision'       => 'city',
			'network_lookup'      => 1,
			'rdns_lookup'         => 1,
			'bot_threshold'       => 50,
			'pow_enabled'         => 1,
			'retention_days'      => 395,
			'view_roles'          => array( 'administrator', 'editor' ),
			'goals'               => array(
				array(
					'name'  => 'Contact form submitted',
					'type'  => 'event',
					'match' => 'exact',
					'value' => 'Form submit',
				),
				array(
					'name'  => 'Reached thank-you page',
					'type'  => 'path',
					'match' => 'contains',
					'value' => 'thank-you',
				),
				array(
					'name'  => 'Downloaded a file',
					'type'  => 'event',
					'match' => 'exact',
					'value' => 'File download',
				),
			),
			'report_enabled'      => 0,
			'report_recipients'   => get_option( 'admin_email' ),
			'drop_on_uninstall'   => 0,
		);
	}

	/**
	 * Read one setting (or all of them).
	 *
	 * @param string|null $key Setting key.
	 * @return mixed
	 */
	public static function get( $key = null ) {
		if ( null === self::$cache ) {
			$stored      = get_option( self::OPTION, array() );
			self::$cache = array_merge( self::defaults(), is_array( $stored ) ? $stored : array() );
		}
		if ( null === $key ) {
			return self::$cache;
		}
		return isset( self::$cache[ $key ] ) ? self::$cache[ $key ] : null;
	}

	/**
	 * Drop the request cache (after saving).
	 */
	public static function flush() {
		self::$cache = null;
	}

	/**
	 * A configuration value from a wp-config.php constant or an environment
	 * variable (Docker). NAME_FILE may point at a file holding the value, the
	 * Docker secrets convention.
	 *
	 * @param string $name Constant / variable name.
	 * @return string Empty when not set.
	 */
	public static function env( $name ) {
		if ( defined( $name ) ) {
			return (string) constant( $name );
		}
		$value = getenv( $name );
		if ( false === $value || '' === $value ) {
			$file = getenv( $name . '_FILE' );
			if ( $file && is_readable( $file ) ) {
				return trim( (string) file_get_contents( $file ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- a local secrets file.
			}
			return '';
		}
		return (string) $value;
	}

	/**
	 * Saved credentials of the external statistics database.
	 *
	 * @return array
	 */
	public static function db_settings() {
		$stored = get_option( self::DB_OPTION, array() );
		return array_merge(
			array(
				'host'     => '',
				'name'     => '',
				'user'     => '',
				'password' => '',
				'prefix'   => 'nemvo_',
			),
			is_array( $stored ) ? $stored : array()
		);
	}

	/**
	 * Saved queue and Cap settings.
	 *
	 * @return array
	 */
	public static function services() {
		$stored = get_option( self::SERVICES_OPTION, array() );
		return array_merge(
			array(
				'queue_enabled'  => 0,
				'queue_host'     => '',
				'queue_port'     => 6379,
				'queue_password' => '',
				'queue_db'       => 0,
				'queue_prefix'   => 'nemvo:',
				'cap_enabled'    => 0,
				'cap_url'        => '',
				'cap_public_url' => '',
				'cap_site_key'   => '',
				'cap_secret'     => '',
			),
			is_array( $stored ) ? $stored : array()
		);
	}

	/**
	 * Sanitize the main settings array (register_setting callback).
	 *
	 * @param mixed $input Values from options.php (already unslashed).
	 * @return array
	 */
	public static function sanitize( $input ) {
		$input    = is_array( $input ) ? $input : array();
		$defaults = self::defaults();
		$out      = array();

		$flags = array(
			'tracking_enabled',
			'respect_dnt',
			'server_hits',
			'track_outbound',
			'track_downloads',
			'track_forms',
			'track_contact_links',
			'track_404',
			'track_search',
			'track_scroll',
			'inline_script',
			'geo_headers',
			'geo_timezone',
			'geo_auto_update',
			'network_lookup',
			'rdns_lookup',
			'pow_enabled',
			'report_enabled',
			'drop_on_uninstall',
		);
		foreach ( $flags as $flag ) {
			$out[ $flag ] = empty( $input[ $flag ] ) ? 0 : 1;
		}

		$roles                = array_keys( wp_roles()->get_names() );
		$out['exclude_roles'] = array_values( array_intersect( (array) ( $input['exclude_roles'] ?? array() ), $roles ) );
		$out['view_roles']    = array_values( array_intersect( (array) ( $input['view_roles'] ?? array() ), $roles ) );

		$out['exclude_ips']   = self::clean_lines( $input['exclude_ips'] ?? '' );
		$out['exclude_paths'] = self::clean_lines( $input['exclude_paths'] ?? '' );

		$out['session_timeout'] = min( 240, max( 5, absint( $input['session_timeout'] ?? $defaults['session_timeout'] ) ) );
		$out['retention_days']  = min( 3650, max( 0, absint( $input['retention_days'] ?? $defaults['retention_days'] ) ) );
		$out['bot_threshold']   = min( 95, max( 10, absint( $input['bot_threshold'] ?? $defaults['bot_threshold'] ) ) );

		$ext                        = strtolower( (string) ( $input['download_extensions'] ?? '' ) );
		$ext                        = array_filter( array_map( 'trim', explode( ',', (string) preg_replace( '/[^a-z0-9,]/', '', $ext ) ) ) );
		$out['download_extensions'] = $ext ? implode( ',', array_unique( $ext ) ) : $defaults['download_extensions'];

		$out['trust_proxy']   = in_array( $input['trust_proxy'] ?? '', array( 'auto', 'always', 'never' ), true ) ? $input['trust_proxy'] : 'auto';
		$out['salt_rotation'] = in_array( $input['salt_rotation'] ?? '', array( 'daily', 'weekly', 'monthly' ), true ) ? $input['salt_rotation'] : 'daily';
		$out['geo_precision'] = in_array( $input['geo_precision'] ?? '', array( 'country', 'region', 'city' ), true ) ? $input['geo_precision'] : 'city';
		$out['geo_mmdb_path'] = sanitize_text_field( $input['geo_mmdb_path'] ?? '' );
		$out['asn_mmdb_path'] = sanitize_text_field( $input['asn_mmdb_path'] ?? '' );

		$emails = array();
		foreach ( (array) preg_split( '/[\s,;]+/', (string) ( $input['report_recipients'] ?? '' ) ) as $email ) {
			$email = sanitize_email( $email );
			if ( $email && is_email( $email ) ) {
				$emails[] = $email;
			}
		}
		$out['report_recipients'] = implode( ', ', array_unique( $emails ) );

		$out['goals'] = array();
		foreach ( (array) ( $input['goals'] ?? array() ) as $goal ) {
			$name  = sanitize_text_field( $goal['name'] ?? '' );
			$value = sanitize_text_field( $goal['value'] ?? '' );
			if ( '' === $name || '' === $value ) {
				continue;
			}
			$out['goals'][] = array(
				'name'  => mb_substr( $name, 0, 80 ),
				'type'  => 'event' === ( $goal['type'] ?? '' ) ? 'event' : 'path',
				'match' => in_array( $goal['match'] ?? '', array( 'exact', 'prefix', 'contains' ), true ) ? $goal['match'] : 'exact',
				'value' => mb_substr( $value, 0, 190 ),
			);
		}

		self::flush();
		return $out;
	}

	/**
	 * Sanitize the external database credentials.
	 *
	 * @param mixed $input Values from options.php.
	 * @return array
	 */
	public static function sanitize_db( $input ) {
		$current = self::db_settings();
		// The fields are not shown when wp-config.php or the environment configures the database.
		if ( ! is_array( $input ) ) {
			return $current;
		}
		$prefix = preg_replace( '/[^A-Za-z0-9_]/', '', (string) ( $input['prefix'] ?? 'nemvo_' ) );
		return array(
			'host'     => sanitize_text_field( $input['host'] ?? '' ),
			'name'     => (string) preg_replace( '/[^A-Za-z0-9_\-$]/', '', (string) ( $input['name'] ?? '' ) ),
			'user'     => sanitize_text_field( $input['user'] ?? '' ),
			// An empty password field means "keep the stored one".
			'password' => ( isset( $input['password'] ) && '' !== $input['password'] ) ? (string) $input['password'] : $current['password'],
			'prefix'   => '' === $prefix ? 'nemvo_' : $prefix,
		);
	}

	/**
	 * Sanitize the queue and Cap settings.
	 *
	 * @param mixed $input Values from options.php.
	 * @return array
	 */
	public static function sanitize_services( $input ) {
		$current = self::services();
		if ( ! is_array( $input ) ) {
			return $current;
		}
		$url = static function ( $value ) {
			return untrailingslashit( esc_url_raw( trim( (string) $value ), array( 'http', 'https' ) ) );
		};
		$out = array(
			'queue_enabled'  => empty( $input['queue_enabled'] ) ? 0 : 1,
			'queue_host'     => (string) preg_replace( '/[^A-Za-z0-9.\-:\[\]_\/]/', '', (string) ( $input['queue_host'] ?? '' ) ),
			'queue_port'     => min( 65535, max( 1, absint( $input['queue_port'] ?? 6379 ) ) ),
			'queue_password' => ( isset( $input['queue_password'] ) && '' !== $input['queue_password'] ) ? (string) $input['queue_password'] : $current['queue_password'],
			'queue_db'       => min( 15, absint( $input['queue_db'] ?? 0 ) ),
			'queue_prefix'   => (string) preg_replace( '/[^A-Za-z0-9:_\-]/', '', (string) ( $input['queue_prefix'] ?? 'nemvo:' ) ),
			'cap_enabled'    => empty( $input['cap_enabled'] ) ? 0 : 1,
			'cap_url'        => $url( $input['cap_url'] ?? '' ),
			'cap_public_url' => $url( $input['cap_public_url'] ?? '' ),
			'cap_site_key'   => (string) preg_replace( '/[^A-Za-z0-9_\-]/', '', (string) ( $input['cap_site_key'] ?? '' ) ),
			'cap_secret'     => ( isset( $input['cap_secret'] ) && '' !== $input['cap_secret'] ) ? sanitize_text_field( $input['cap_secret'] ) : $current['cap_secret'],
		);
		if ( '' === $out['queue_prefix'] ) {
			$out['queue_prefix'] = 'nemvo:';
		}
		if ( $out['queue_enabled'] && '' === $out['queue_host'] && '' === self::env( 'NEMVO_ANALYTICS_QUEUE_URL' ) ) {
			$out['queue_enabled'] = 0;
			add_settings_error( self::SERVICES_OPTION, 'queue', __( 'The queue was not turned on because no Valkey host is set.', 'nemvo-analytics' ) );
		}
		if ( $out['cap_enabled'] && ! Cap::configured( $out ) ) {
			$out['cap_enabled'] = 0;
			add_settings_error( self::SERVICES_OPTION, 'cap', __( 'Cap was not turned on because the server address, site key or secret key is missing.', 'nemvo-analytics' ) );
		}
		return $out;
	}

	/**
	 * Normalise a textarea to trimmed, non-empty lines.
	 *
	 * @param string $text Raw text.
	 * @return string
	 */
	private static function clean_lines( $text ) {
		$lines = array_filter( array_map( 'trim', (array) preg_split( '/\r\n|\r|\n/', (string) $text ) ) );
		return implode( "\n", array_map( 'sanitize_text_field', $lines ) );
	}
}
