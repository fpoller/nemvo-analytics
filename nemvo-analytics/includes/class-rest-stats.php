<?php
/**
 * REST endpoints used by the dashboard and the settings page.
 *
 * @package NemvoAnalytics
 */

namespace NemvoAnalytics;

defined( 'ABSPATH' ) || exit;

/**
 * Read-only reporting endpoints (view capability) and admin actions
 * (manage_options). The dashboard sends its reports in one POST to
 * /stats/batch, so a refresh boots WordPress once instead of a dozen times.
 */
class Rest_Stats {

	/**
	 * Reporting routes: route => callback.
	 */
	const REPORTS = array(
		'overview'  => 'overview',
		'breakdown' => 'breakdown',
		'heatmap'   => 'heatmap',
		'journey'   => 'journey',
		'sessions'  => 'sessions',
		'session'   => 'session',
		'bots'      => 'bots',
		'realtime'  => 'realtime',
	);

	/**
	 * Register routes.
	 */
	public static function register_routes() {
		$view   = static function () {
			return current_user_can( Plugin::CAP_VIEW );
		};
		$manage = static function () {
			return current_user_can( 'manage_options' );
		};

		$routes = array(
			'/stats/batch'         => array( 'POST', 'batch', $view ),
			'/admin/db-test'       => array( 'POST', 'db_test', $manage ),
			'/admin/install'       => array( 'POST', 'install', $manage ),
			'/admin/purge'         => array( 'POST', 'purge', $manage ),
			'/admin/geo'           => array( 'POST', 'geo_download', $manage ),
			'/admin/lookup'        => array( 'POST', 'lookup', $manage ),
			'/admin/migrate'       => array( 'POST', 'migrate', $manage ),
			'/admin/queue-test'    => array( 'POST', 'queue_test', $manage ),
			'/admin/queue-work'    => array( 'POST', 'queue_work', $manage ),
			'/admin/cap-test'      => array( 'POST', 'cap_test', $manage ),
			'/admin/diagnostics'   => array( 'POST', 'diagnostics', $manage ),
		);
		foreach ( self::REPORTS as $route => $callback ) {
			$routes[ '/stats/' . $route ] = array( 'GET', $callback, $view );
		}
		foreach ( $routes as $route => $def ) {
			register_rest_route(
				Collector::NS,
				$route,
				array(
					'methods'             => $def[0],
					'callback'            => array( __CLASS__, $def[1] ),
					'permission_callback' => $def[2],
				)
			);
		}
	}

	/**
	 * Run several reports in one request: { requests: [ { route, params } ] }.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public static function batch( \WP_REST_Request $request ) {
		$out = array();
		foreach ( array_slice( (array) $request->get_param( 'requests' ), 0, 25 ) as $item ) {
			$route = is_array( $item ) ? (string) ( $item['route'] ?? '' ) : '';
			if ( ! isset( self::REPORTS[ $route ] ) ) {
				$out[] = array(
					'status' => 400,
					'error'  => 'Unknown report.',
				);
				continue;
			}
			$sub = new \WP_REST_Request( 'GET', '/' . Collector::NS . '/stats/' . $route );
			foreach ( (array) ( $item['params'] ?? array() ) as $key => $value ) {
				$sub->set_param( (string) $key, is_scalar( $value ) ? (string) $value : '' );
			}
			$result = call_user_func( array( __CLASS__, self::REPORTS[ $route ] ), $sub );
			if ( is_wp_error( $result ) ) {
				$out[] = array(
					'status' => (int) ( $result->get_error_data()['status'] ?? 500 ),
					'error'  => $result->get_error_message(),
				);
			} else {
				$out[] = array(
					'status' => 200,
					'data'   => $result instanceof \WP_REST_Response ? $result->get_data() : $result,
				);
			}
		}
		return rest_ensure_response( $out );
	}

	/**
	 * Connection or error response.
	 *
	 * @return \wpdb|\WP_Error
	 */
	private static function db() {
		$db = DB::conn();
		if ( ! $db ) {
			/* translators: %s: database error */
			return new \WP_Error( 'nemvo_db', sprintf( __( 'The statistics database is not reachable: %s', 'nemvo-analytics' ), DB::error() ), array( 'status' => 503 ) );
		}
		DB::maybe_install();
		return $db;
	}

	/**
	 * Parse range, filters and traffic scope.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return array{from:string,to:string,filters:array,traffic:string}
	 */
	private static function params( \WP_REST_Request $request ) {
		$today = current_datetime()->format( 'Y-m-d' );
		$from  = (string) $request->get_param( 'from' );
		$to    = (string) $request->get_param( 'to' );
		$re    = '/^\d{4}-\d{2}-\d{2}$/';
		if ( ! preg_match( $re, $to ) ) {
			$to = $today;
		}
		if ( ! preg_match( $re, $from ) ) {
			$from = gmdate( 'Y-m-d', strtotime( $to ) - 29 * DAY_IN_SECONDS );
		}
		if ( $from > $to ) {
			list( $from, $to ) = array( $to, $from );
		}

		$filters = array();
		$raw     = json_decode( (string) $request->get_param( 'filters' ), true );
		$dims    = array_merge( array_keys( Stats::dims() ), array( 'goal', 'event', 'bot_flag' ) );
		foreach ( is_array( $raw ) ? array_slice( $raw, 0, 10 ) : array() as $f ) {
			if ( ! is_array( $f ) || ! isset( $f['d'], $f['v'] ) || ! in_array( $f['d'], $dims, true ) ) {
				continue;
			}
			$filters[] = array(
				'd' => $f['d'],
				'o' => in_array( $f['o'] ?? 'is', array( 'is', 'is_not', 'contains' ), true ) ? ( $f['o'] ?? 'is' ) : 'is',
				'v' => mb_substr( (string) $f['v'], 0, 255 ),
			);
		}
		$traffic = (string) $request->get_param( 'traffic' );
		$traffic = in_array( $traffic, array( 'human', 'bot', 'all' ), true ) ? $traffic : 'human';
		return compact( 'from', 'to', 'filters', 'traffic' );
	}

	/**
	 * Stats for a request.
	 *
	 * @param \wpdb $db Connection.
	 * @param array $p  Parsed params.
	 * @return Stats
	 */
	private static function stats( $db, array $p ) {
		return new Stats( $db, $p['from'], $p['to'], $p['filters'], $p['traffic'] );
	}

	/**
	 * Cache a report in a persistent object cache, when the site has one:
	 * a minute for ranges that include today, an hour for the past.
	 *
	 * @param string   $name Report.
	 * @param array    $key  Parameters.
	 * @param callable $fn   Builds the report.
	 * @return mixed
	 */
	private static function cached( $name, array $key, $fn ) {
		if ( ! wp_using_ext_object_cache() ) {
			return $fn();
		}
		$id  = $name . ':' . md5( wp_json_encode( array( $key, DB::active(), Bot::threshold(), Settings::get( 'goals' ) ) ) );
		$hit = wp_cache_get( $id, 'nemvo_analytics' );
		if ( false !== $hit ) {
			return $hit;
		}
		$value = $fn();
		$past  = isset( $key['to'] ) && $key['to'] < current_datetime()->format( 'Y-m-d' );
		wp_cache_set( $id, $value, 'nemvo_analytics', $past ? HOUR_IN_SECONDS : MINUTE_IN_SECONDS );
		return $value;
	}

	/**
	 * The comparison range for a range.
	 *
	 * @param string $from Y-m-d.
	 * @param string $to   Y-m-d.
	 * @param string $mode previous|year|none.
	 * @return array|null [from, to]
	 */
	private static function compare_range( $from, $to, $mode ) {
		if ( 'year' === $mode ) {
			return array( gmdate( 'Y-m-d', strtotime( $from . ' -1 year' ) ), gmdate( 'Y-m-d', strtotime( $to . ' -1 year' ) ) );
		}
		if ( 'previous' === $mode ) {
			$days = (int) round( ( strtotime( $to ) - strtotime( $from ) ) / DAY_IN_SECONDS ) + 1;
			return array( gmdate( 'Y-m-d', strtotime( $from ) - $days * DAY_IN_SECONDS ), gmdate( 'Y-m-d', strtotime( $from ) - DAY_IN_SECONDS ) );
		}
		return null;
	}

	/**
	 * KPIs and time series, with an optional comparison period.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function overview( \WP_REST_Request $request ) {
		$db = self::db();
		if ( is_wp_error( $db ) ) {
			return $db;
		}
		$p        = self::params( $request );
		$interval = (string) $request->get_param( 'interval' );
		if ( ! in_array( $interval, array( 'hour', 'day', 'week', 'month' ), true ) ) {
			$interval = Stats::auto_interval( $p['from'], $p['to'] );
		}
		$days = (int) round( ( strtotime( $p['to'] ) - strtotime( $p['from'] ) ) / DAY_IN_SECONDS ) + 1;
		if ( 'hour' === $interval && $days > 14 ) {
			$interval = 'day';
		}
		$compare = (string) $request->get_param( 'compare' );

		return rest_ensure_response(
			self::cached(
				'overview',
				$p + compact( 'interval', 'compare' ),
				static function () use ( $db, $p, $interval, $compare ) {
					$stats = self::stats( $db, $p );
					$out   = array(
						'from'     => $p['from'],
						'to'       => $p['to'],
						'interval' => $interval,
						'current'  => $stats->overview(),
						'series'   => $stats->timeseries( $interval ),
						'compare'  => null,
					);
					$range = self::compare_range( $p['from'], $p['to'], $compare );
					if ( $range ) {
						$prev           = new Stats( $db, $range[0], $range[1], $p['filters'], $p['traffic'] );
						$out['compare'] = array(
							'from'    => $range[0],
							'to'      => $range[1],
							'current' => $prev->overview(),
							'series'  => $prev->timeseries( $interval ),
						);
					}
					return $out;
				}
			)
		);
	}

	/**
	 * Breakdown table.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function breakdown( \WP_REST_Request $request ) {
		$db = self::db();
		if ( is_wp_error( $db ) ) {
			return $db;
		}
		$p    = self::params( $request );
		$args = array(
			'limit'  => $request->get_param( 'limit' ),
			'offset' => $request->get_param( 'offset' ),
			'q'      => sanitize_text_field( (string) $request->get_param( 'q' ) ),
			'sort'   => sanitize_key( (string) $request->get_param( 'sort' ) ),
			'dir'    => sanitize_key( (string) $request->get_param( 'dir' ) ),
			'event'  => sanitize_text_field( (string) $request->get_param( 'event' ) ),
		);
		$dim  = sanitize_key( (string) $request->get_param( 'dim' ) );
		return rest_ensure_response(
			self::cached(
				'breakdown',
				$p + $args + array( 'dim' => $dim ),
				static function () use ( $db, $p, $dim, $args ) {
					return self::stats( $db, $p )->breakdown( $dim, $args );
				}
			)
		);
	}

	/**
	 * Weekday x hour matrix.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function heatmap( \WP_REST_Request $request ) {
		$db = self::db();
		if ( is_wp_error( $db ) ) {
			return $db;
		}
		$p = self::params( $request );
		return rest_ensure_response(
			self::cached(
				'heatmap',
				$p,
				static function () use ( $db, $p ) {
					return self::stats( $db, $p )->heatmap();
				}
			)
		);
	}

	/**
	 * Previous/next pages for one page.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function journey( \WP_REST_Request $request ) {
		$db = self::db();
		if ( is_wp_error( $db ) ) {
			return $db;
		}
		$p = self::params( $request );
		return rest_ensure_response( self::stats( $db, $p )->journey( Collector::clean_path( (string) $request->get_param( 'path' ) ) ) );
	}

	/**
	 * Visit log.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function sessions( \WP_REST_Request $request ) {
		$db = self::db();
		if ( is_wp_error( $db ) ) {
			return $db;
		}
		$p = self::params( $request );
		return rest_ensure_response( self::stats( $db, $p )->sessions( (int) $request->get_param( 'limit' ), (int) $request->get_param( 'offset' ) ) );
	}

	/**
	 * One visit in detail.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function session( \WP_REST_Request $request ) {
		$db = self::db();
		if ( is_wp_error( $db ) ) {
			return $db;
		}
		$visit = Stats::session( $db, absint( $request->get_param( 'id' ) ) );
		if ( ! $visit ) {
			return new \WP_Error( 'nemvo_not_found', __( 'This visit no longer exists.', 'nemvo-analytics' ), array( 'status' => 404 ) );
		}
		return rest_ensure_response( $visit );
	}

	/**
	 * People versus automated traffic.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function bots( \WP_REST_Request $request ) {
		$db = self::db();
		if ( is_wp_error( $db ) ) {
			return $db;
		}
		$p = self::params( $request );
		return rest_ensure_response(
			self::cached(
				'bots',
				$p,
				static function () use ( $db, $p ) {
					return self::stats( $db, $p )->bots();
				}
			)
		);
	}

	/**
	 * Live numbers.
	 *
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function realtime() {
		$db = self::db();
		if ( is_wp_error( $db ) ) {
			return $db;
		}
		return rest_ensure_response( Stats::realtime( $db ) );
	}

	/**
	 * Test external database credentials from the settings form.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public static function db_test( \WP_REST_Request $request ) {
		$cfg = DB::external_config();
		if ( $cfg && 'settings' !== $cfg['source'] ) {
			return rest_ensure_response( DB::test( $cfg ) );
		}
		$stored   = Settings::db_settings();
		$password = (string) $request->get_param( 'password' );
		return rest_ensure_response(
			DB::test(
				array(
					'host'     => sanitize_text_field( (string) $request->get_param( 'host' ) ),
					'name'     => sanitize_text_field( (string) $request->get_param( 'name' ) ),
					'user'     => sanitize_text_field( (string) $request->get_param( 'user' ) ),
					'password' => '' === $password ? $stored['password'] : $password,
				)
			)
		);
	}

	/**
	 * Create or repair tables.
	 *
	 * @return \WP_REST_Response
	 */
	public static function install() {
		$ok = DB::install();
		return rest_ensure_response(
			array(
				'ok'      => $ok,
				'message' => $ok ? __( 'Tables are ready.', 'nemvo-analytics' ) : DB::error(),
			)
		);
	}

	/**
	 * Delete statistics.
	 *
	 * @param \WP_REST_Request $request Request with "before" (Y-m-d), "all" or "inactive".
	 * @return \WP_REST_Response
	 */
	public static function purge( \WP_REST_Request $request ) {
		if ( $request->get_param( 'inactive' ) ) {
			$ok = Migration::purge_inactive();
			return rest_ensure_response(
				array(
					'ok'      => $ok,
					'message' => $ok ? __( 'The old copy was deleted.', 'nemvo-analytics' ) : __( 'The old copy could not be deleted.', 'nemvo-analytics' ),
				)
			);
		}
		if ( $request->get_param( 'all' ) ) {
			$ok = DB::truncate();
			return rest_ensure_response(
				array(
					'ok'      => $ok,
					'message' => $ok ? __( 'All statistics were deleted.', 'nemvo-analytics' ) : DB::error(),
				)
			);
		}
		$before = (string) $request->get_param( 'before' );
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $before ) ) {
			return rest_ensure_response(
				array(
					'ok'      => false,
					'message' => __( 'Pick a date.', 'nemvo-analytics' ),
				)
			);
		}
		$n = DB::purge_before( $before . ' 00:00:00' );
		return rest_ensure_response(
			array(
				'ok'      => true,
				/* translators: %s: number of rows */
				'message' => sprintf( __( '%s rows deleted.', 'nemvo-analytics' ), number_format_i18n( $n ) ),
			)
		);
	}

	/**
	 * Download a DB-IP database.
	 *
	 * @param \WP_REST_Request $request Request with "edition" (country|city|asn).
	 * @return \WP_REST_Response
	 */
	public static function geo_download( \WP_REST_Request $request ) {
		$edition = (string) $request->get_param( 'edition' );
		$result  = Geo::download_dbip( $edition );
		if ( is_wp_error( $result ) ) {
			return rest_ensure_response(
				array(
					'ok'      => false,
					'message' => $result->get_error_message(),
				)
			);
		}
		$info = Geo::database_info( 'asn' === $edition ? 'asn' : 'geo' );
		return rest_ensure_response(
			array(
				'ok'      => true,
				/* translators: 1: database type, 2: build date, 3: file size */
				'message' => sprintf( __( 'Installed %1$s (built %2$s, %3$s).', 'nemvo-analytics' ), $info['type'] ?? 'database', $info['built'] ?? '?', size_format( $info['size'] ?? 0, 1 ) ),
			)
		);
	}

	/**
	 * What the plugin would record for an IP (settings page test).
	 *
	 * @param \WP_REST_Request $request Request with "ip".
	 * @return \WP_REST_Response
	 */
	public static function lookup( \WP_REST_Request $request ) {
		$ip = trim( (string) $request->get_param( 'ip' ) );
		if ( '' === $ip ) {
			$ip = Privacy::client_ip();
		}
		if ( ! filter_var( $ip, FILTER_VALIDATE_IP ) ) {
			return rest_ensure_response(
				array(
					'ok'      => false,
					'message' => __( 'That is not an IP address.', 'nemvo-analytics' ),
				)
			);
		}
		$start = microtime( true );
		$geo   = Geo::locate( $ip, '', '' );
		$net   = Geo::network( $ip, true );
		$types = Network::types();
		return rest_ensure_response(
			array(
				'ok'       => true,
				'ip'       => $ip,
				'country'  => $geo['country'],
				'region'   => $geo['region'],
				'city'     => $geo['city'],
				'asn'      => $net['asn'],
				'provider' => $net['provider'],
				'network'  => $types[ $net['network'] ] ?? $net['network'],
				'company'  => $net['company'],
				'hostname' => Settings::get( 'rdns_lookup' ) ? Dns::reverse( $ip ) : '',
				'ms'       => (int) round( ( microtime( true ) - $start ) * 1000 ),
			)
		);
	}

	/**
	 * Start, continue or cancel a migration: { action: start|step|cancel, to, replace }.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public static function migrate( \WP_REST_Request $request ) {
		$action = (string) $request->get_param( 'action' );
		if ( 'cancel' === $action ) {
			Migration::cancel();
			return rest_ensure_response( array( 'ok' => true ) );
		}
		$result = 'start' === $action
			? Migration::start( (string) $request->get_param( 'to' ), (bool) $request->get_param( 'replace' ) )
			: Migration::step( 4.0 );
		if ( is_wp_error( $result ) ) {
			return rest_ensure_response(
				array(
					'ok'      => false,
					'code'    => $result->get_error_code(),
					'message' => $result->get_error_message(),
				)
			);
		}
		return rest_ensure_response( array( 'ok' => true ) + $result );
	}

	/**
	 * Test queue settings from the form.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public static function queue_test( \WP_REST_Request $request ) {
		$cfg = Queue::config();
		if ( ! $cfg || 'settings' === $cfg['source'] ) {
			$stored   = Settings::services();
			$password = (string) $request->get_param( 'password' );
			$cfg      = Queue::from_settings(
				array(
					'queue_host'     => sanitize_text_field( (string) $request->get_param( 'host' ) ),
					'queue_port'     => absint( $request->get_param( 'port' ) ? $request->get_param( 'port' ) : 6379 ),
					'queue_password' => '' === $password ? $stored['queue_password'] : $password,
					'queue_db'       => absint( $request->get_param( 'db' ) ),
					'queue_prefix'   => $stored['queue_prefix'],
				)
			);
		}
		return rest_ensure_response( Queue::test( $cfg ) );
	}

	/**
	 * Process the queue now.
	 *
	 * @return \WP_REST_Response
	 */
	public static function queue_work() {
		if ( ! Queue::enabled() ) {
			return rest_ensure_response(
				array(
					'ok'      => false,
					'message' => __( 'The queue is off.', 'nemvo-analytics' ),
				)
			);
		}
		$n = Queue::work( 15 );
		return rest_ensure_response(
			array(
				'ok'      => true,
				/* translators: %s: number of hits */
				'message' => sprintf( __( '%s hits written.', 'nemvo-analytics' ), number_format_i18n( $n ) ),
			)
		);
	}

	/**
	 * Check tracking: { action: start|status|stop }. Start runs the server-side
	 * checks and opens the live log for 30 minutes.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public static function diagnostics( \WP_REST_Request $request ) {
		$action = (string) $request->get_param( 'action' );
		$checks = null;
		if ( 'start' === $action ) {
			Diagnostics::toggle( true );
			$checks = Diagnostics::checks();
		} elseif ( 'stop' === $action ) {
			Diagnostics::toggle( false );
		}
		$env = get_option( Health::OPTION, array() );
		return rest_ensure_response(
			array(
				'ok'        => true,
				'active'    => Diagnostics::active(),
				'minutes'   => Diagnostics::active() ? (int) ceil( ( (int) $env['diag_until'] - time() ) / 60 ) : 0,
				'checks'    => $checks,
				'log'       => Diagnostics::entries(),
				'threshold' => Bot::threshold(),
			)
		);
	}

	/**
	 * Test Cap settings from the form.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public static function cap_test( \WP_REST_Request $request ) {
		$stored = Settings::services();
		$cfg    = Cap::config();
		if ( $cfg && '' !== Settings::env( 'NEMVO_ANALYTICS_CAP_URL' ) ) {
			$s = array(
				'cap_url'      => $cfg['url'],
				'cap_site_key' => $cfg['site_key'],
				'cap_secret'   => $cfg['secret'],
			);
		} else {
			$secret = (string) $request->get_param( 'secret' );
			$s      = array(
				'cap_url'      => untrailingslashit( esc_url_raw( (string) $request->get_param( 'url' ), array( 'http', 'https' ) ) ),
				'cap_site_key' => sanitize_text_field( (string) $request->get_param( 'site_key' ) ),
				'cap_secret'   => '' === $secret ? $stored['cap_secret'] : $secret,
			);
		}
		return rest_ensure_response( Cap::test( $s ) );
	}
}
