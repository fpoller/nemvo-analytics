<?php
/**
 * Setup checks: the settings page checklist and Site Health.
 *
 * @package NemvoAnalytics
 */

namespace NemvoAnalytics;

defined( 'ABSPATH' ) || exit;

/**
 * Nemvo Analytics is built for sites without page caching or a CDN in
 * front: caches and JavaScript optimizers can delay or drop the tracker,
 * and a proxy hides visitor addresses (one "visitor" for everybody, no
 * location, no company). These checks find such setups and other loose
 * ends, for the settings page and Tools > Site Health.
 */
class Health {

	const OPTION = 'nemvo_analytics_env';

	/**
	 * Hook in.
	 */
	public static function init() {
		add_filter( 'site_status_tests', array( __CLASS__, 'site_health' ) );
	}

	/**
	 * Remember that hits arrive with proxy headers while proxies are not
	 * trusted (at most one option write per hour).
	 *
	 * @param array $headers Request headers (lowercase, underscores).
	 */
	public static function note_request( array $headers ) {
		if ( Settings::get( 'trust_proxy' ) ) {
			return;
		}
		foreach ( array( 'cf_connecting_ip', 'true_client_ip', 'fastly_client_ip', 'x_real_ip', 'x_forwarded_for' ) as $header ) {
			if ( ! empty( $headers[ $header ] ) ) {
				$env = get_option( self::OPTION, array() );
				$env = is_array( $env ) ? $env : array();
				if ( empty( $env['proxy'] ) || time() - (int) $env['proxy'] > HOUR_IN_SECONDS ) {
					$env['proxy']        = time();
					$env['proxy_header'] = str_replace( '_', '-', $header );
					update_option( self::OPTION, $env, true );
				}
				return;
			}
		}
	}

	/**
	 * Active page caching and JavaScript optimization plugins.
	 *
	 * @return string[]
	 */
	public static function caching_plugins() {
		$found = array_keys(
			array_filter(
				array(
					'WP Rocket'                  => defined( 'WP_ROCKET_VERSION' ),
					'W3 Total Cache'             => defined( 'W3TC' ),
					'WP Super Cache'             => defined( 'WPCACHEHOME' ),
					'LiteSpeed Cache'            => defined( 'LSCWP_V' ),
					'WP Fastest Cache'           => class_exists( 'WpFastestCache' ),
					'Cache Enabler'              => class_exists( 'Cache_Enabler' ),
					'Comet Cache'                => defined( 'COMET_CACHE_PLUGIN_FILE' ),
					'Hummingbird'                => defined( 'WPHB_VERSION' ),
					'SiteGround Speed Optimizer' => defined( 'SiteGround_Optimizer\VERSION' ),
					'Breeze'                     => defined( 'BREEZE_VERSION' ),
					'Swift Performance'          => defined( 'SWIFT_PERFORMANCE_VER' ),
					'NitroPack'                  => defined( 'NITROPACK_VERSION' ),
					'FlyingPress'                => defined( 'FLYING_PRESS_VERSION' ),
					'WP-Optimize'                => defined( 'WPO_VERSION' ),
					'Powered Cache'              => defined( 'POWERED_CACHE_VERSION' ),
					'Autoptimize'                => defined( 'AUTOPTIMIZE_PLUGIN_VERSION' ),
					'Perfmatters'                => defined( 'PERFMATTERS_VERSION' ),
					'Jetpack Boost'              => defined( 'JETPACK_BOOST_VERSION' ),
					'Cloudflare'                 => defined( 'CLOUDFLARE_PLUGIN_DIR' ),
				)
			)
		);
		if ( ! $found && defined( 'WP_CACHE' ) && WP_CACHE && file_exists( WP_CONTENT_DIR . '/advanced-cache.php' ) ) {
			$found[] = __( 'a page cache (advanced-cache.php)', 'nemvo-analytics' );
		}
		return $found;
	}

	/**
	 * The checklist: list of [status good|warning|critical, title, detail, settings anchor].
	 *
	 * @return array[]
	 */
	public static function checks() {
		$out = array();
		$s   = Settings::get();

		$out[] = $s['tracking_enabled']
			? array( 'good', __( 'Collecting statistics', 'nemvo-analytics' ), __( 'The tracker is printed on every public page.', 'nemvo-analytics' ), 'tracking' )
			: array( 'critical', __( 'Tracking is paused', 'nemvo-analytics' ), __( 'No new visits are recorded.', 'nemvo-analytics' ), 'tracking' );

		$db = DB::conn();
		if ( ! $db ) {
			/* translators: %s: error message */
			$out[] = array( 'critical', __( 'The statistics database is not reachable', 'nemvo-analytics' ), sprintf( __( 'Hits are not saved: %s', 'nemvo-analytics' ), DB::error() ), 'database' );
		} else {
			$out[] = array( 'good', DB::is_external() ? __( 'Statistics are stored in the external database', 'nemvo-analytics' ) : __( 'Statistics are stored in the WordPress database', 'nemvo-analytics' ), DB::is_external() ? DB::external_config()['host'] . ' / ' . DB::external_config()['name'] : __( 'Fine for most sites. A separate database is available if you need it.', 'nemvo-analytics' ), 'database' );
		}

		$caches = self::caching_plugins();
		if ( $caches ) {
			$out[] = array(
				'warning',
				/* translators: %s: plugin names */
				sprintf( __( 'Caching or optimization detected: %s', 'nemvo-analytics' ), implode( ', ', $caches ) ),
				__( 'Nemvo Analytics is made for sites without page caching. Cached pages are still counted, but "delay JavaScript", combine and minify features can hold back or break the tracker, and a cached page can be served to excluded logged-in users. Exclude the script with the id "nva-tracker" from those features, or turn the cache off.', 'nemvo-analytics' ),
				'tracking',
			);
		}

		$env = get_option( self::OPTION, array() );
		if ( ! $s['trust_proxy'] && ! empty( $env['proxy'] ) && time() - (int) $env['proxy'] < 7 * DAY_IN_SECONDS ) {
			$out[] = array(
				'warning',
				/* translators: %s: header name */
				sprintf( __( 'A proxy or CDN sits in front of the site (%s header seen)', 'nemvo-analytics' ), $env['proxy_header'] ?? '' ),
				__( 'Visitors then share the proxy\'s address: they count as fewer people, and location and company lookups fail. If you control the proxy, turn on "Trust proxy headers" under Geolocation. Otherwise, run the site without it.', 'nemvo-analytics' ),
				'geo',
			);
		}

		$geo = Geo::database_info( 'geo' );
		if ( ! $geo ) {
			$out[] = array( 'warning', __( 'No IP location database', 'nemvo-analytics' ), __( 'Countries come from the browser time zone only. Download the free DB-IP database for countries, or the city edition for cities.', 'nemvo-analytics' ), 'geo' );
		} elseif ( $geo['age'] > 62 ) {
			/* translators: %d: days */
			$out[] = array( 'warning', __( 'The IP location database is old', 'nemvo-analytics' ), sprintf( __( 'Built %d days ago. Update it, or turn on monthly updates.', 'nemvo-analytics' ), $geo['age'] ), 'geo' );
		}
		if ( $s['network_lookup'] && ! Geo::database_info( 'asn' ) ) {
			$out[] = array( 'warning', __( 'No network (ASN) database', 'nemvo-analytics' ), __( 'Without it, visiting companies, ISPs and datacenter traffic cannot be told apart. Download the free DB-IP ASN database under Companies & networks.', 'nemvo-analytics' ), 'networks' );
		}

		if ( Queue::enabled() ) {
			$length = Queue::length();
			$state  = get_option( Queue::STATE_OPTION, array() );
			$last   = (int) ( $state['last_run'] ?? 0 );
			if ( null === $length ) {
				/* translators: %s: error message */
				$out[] = array( 'critical', __( 'The queue is not reachable', 'nemvo-analytics' ), sprintf( __( 'Hits are written directly to the database meanwhile. %s', 'nemvo-analytics' ), Queue::error() ), 'queue' );
			} elseif ( $length > 0 && time() - $last > 5 * MINUTE_IN_SECONDS ) {
				/* translators: %s: number of hits */
				$out[] = array( 'critical', __( 'The queue is not being processed', 'nemvo-analytics' ), sprintf( __( '%s hits are waiting. Start the worker (wp nemvo-analytics queue work --loop) or make sure WP-Cron runs.', 'nemvo-analytics' ), number_format_i18n( $length ) ), 'queue' );
			} else {
				/* translators: %s: number of hits */
				$out[] = array( 'good', __( 'The queue is working', 'nemvo-analytics' ), sprintf( __( '%s hits waiting.', 'nemvo-analytics' ), number_format_i18n( $length ) ), 'queue' );
			}
		}

		$out[] = Cap::enabled()
			? array( 'good', __( 'Bot detection with Cap', 'nemvo-analytics' ), __( 'Every visit solves an invisible Cap challenge.', 'nemvo-analytics' ), 'bots' )
			: array( 'good', __( 'Bot detection is on', 'nemvo-analytics' ), $s['pow_enabled'] ? __( 'Request checks, browser checks, behaviour and the built-in proof of work. Cap can be added for stronger proof.', 'nemvo-analytics' ) : __( 'Request checks, browser checks and behaviour.', 'nemvo-analytics' ), 'bots' );

		return $out;
	}

	/**
	 * Register Site Health tests.
	 *
	 * @param array $tests Tests.
	 * @return array
	 */
	public static function site_health( $tests ) {
		$tests['direct']['nemvo_analytics'] = array(
			'label' => __( 'Nemvo Analytics setup', 'nemvo-analytics' ),
			'test'  => array( __CLASS__, 'site_health_result' ),
		);
		return $tests;
	}

	/**
	 * The Site Health result: the worst checklist item.
	 *
	 * @return array
	 */
	public static function site_health_result() {
		$checks = self::checks();
		$rank   = array(
			'good'     => 0,
			'warning'  => 1,
			'critical' => 2,
		);
		usort(
			$checks,
			static function ( $a, $b ) use ( $rank ) {
				return $rank[ $b[0] ] - $rank[ $a[0] ];
			}
		);
		$worst = $checks[0];
		$items = '';
		foreach ( $checks as $check ) {
			if ( 'good' !== $check[0] ) {
				$items .= '<li><strong>' . esc_html( $check[1] ) . '</strong>. ' . esc_html( $check[2] ) . '</li>';
			}
		}
		return array(
			'label'       => 'good' === $worst[0] ? __( 'Nemvo Analytics is set up correctly', 'nemvo-analytics' ) : $worst[1],
			'status'      => 'good' === $worst[0] ? 'good' : ( 'critical' === $worst[0] ? 'critical' : 'recommended' ),
			'badge'       => array(
				'label' => __( 'Analytics', 'nemvo-analytics' ),
				'color' => 'blue',
			),
			'description' => $items ? '<ul>' . $items . '</ul>' : '<p>' . esc_html__( 'Statistics are being collected and stored.', 'nemvo-analytics' ) . '</p>',
			'actions'     => sprintf( '<a href="%s">%s</a>', esc_url( admin_url( 'admin.php?page=' . Admin::SLUG_SETTINGS ) ), esc_html__( 'Analytics settings', 'nemvo-analytics' ) ),
			'test'        => 'nemvo_analytics',
		);
	}
}
