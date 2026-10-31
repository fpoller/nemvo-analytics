<?php
/**
 * "Check tracking": answers "why are my statistics empty?".
 *
 * @package NemvoAnalytics
 */

namespace NemvoAnalytics;

defined( 'ABSPATH' ) || exit;

/**
 * Two parts:
 *  - checks() loads the home page and sends a test hit from the server
 *    itself, and reports whether the tracker is on the page, whether a cache
 *    or a Content Security Policy is in the way, and whether hits get stored;
 *  - for 30 minutes after start(), the collector logs what happened to each
 *    hit it receives (stored as a person or as automated traffic and why, or
 *    ignored and why), so an administrator can browse the site and watch.
 * Nothing is logged outside that window, and the log holds no IP addresses.
 */
class Diagnostics {

	const LOG_OPTION = 'nemvo_analytics_diag_log';
	const DURATION   = 1800;
	const MAX        = 60;

	/**
	 * Whether the live log is on (reads an autoloaded option: free).
	 *
	 * @return bool
	 */
	public static function active() {
		$env = get_option( Health::OPTION, array() );
		return is_array( $env ) && ! empty( $env['diag_until'] ) && (int) $env['diag_until'] > time();
	}

	/**
	 * Turn the live log on for 30 minutes (or off).
	 *
	 * @param bool $on On or off.
	 */
	public static function toggle( $on ) {
		$env               = get_option( Health::OPTION, array() );
		$env               = is_array( $env ) ? $env : array();
		$env['diag_until'] = $on ? time() + self::DURATION : 0;
		update_option( Health::OPTION, $env, true );
		if ( $on ) {
			update_option( self::LOG_OPTION, array(), false );
		}
	}

	/**
	 * Record what happened to a hit (only while the live log is on).
	 *
	 * @param string $type    pv|e|ev|v.
	 * @param string $outcome stored|queued|ignored.
	 * @param string $reason  Why it was ignored (code), or ''.
	 * @param array  $detail  path, score, flags, network, provider, country, browser, host.
	 */
	public static function log( $type, $outcome, $reason = '', array $detail = array() ) {
		if ( ! self::active() ) {
			return;
		}
		$log = get_option( self::LOG_OPTION, array() );
		$log = is_array( $log ) ? $log : array();
		array_unshift(
			$log,
			array_merge(
				array(
					't'       => time(),
					'type'    => $type,
					'outcome' => $outcome,
					'reason'  => $reason,
				),
				array_map(
					static function ( $v ) {
						return is_scalar( $v ) ? mb_substr( (string) $v, 0, 120 ) : '';
					},
					$detail
				)
			)
		);
		update_option( self::LOG_OPTION, array_slice( $log, 0, self::MAX ), false );
	}

	/**
	 * The live log, newest first, with readable reasons and signals.
	 *
	 * @return array
	 */
	public static function entries() {
		$reasons = self::reasons();
		$labels  = Bot::labels();
		$types   = Network::types();
		$out     = array();
		foreach ( (array) get_option( self::LOG_OPTION, array() ) as $entry ) {
			$signals = array();
			$flags   = (int) ( $entry['flags'] ?? 0 );
			if ( $flags & Bot::JS ) {
				$flags &= ~Bot::NO_JS;
			}
			foreach ( array_filter( Bot::weights() ) as $flag => $weight ) {
				if ( $flags & $flag ) {
					$signals[] = $labels[ $flag ] . ' (' . ( $weight > 0 ? '+' : '' ) . $weight . ')';
				}
			}
			$entry['when']    = human_time_diff( (int) $entry['t'] );
			$entry['why']     = $reasons[ $entry['reason'] ] ?? $entry['reason'];
			$entry['signals'] = implode( ', ', $signals );
			$entry['network'] = isset( $entry['network'] ) ? ( $types[ $entry['network'] ] ?? $entry['network'] ) : '';
			$out[]            = $entry;
		}
		return $out;
	}

	/**
	 * Why a hit can be ignored.
	 *
	 * @return string[]
	 */
	public static function reasons() {
		return array(
			'tracking_off' => __( 'Tracking is paused in the settings.', 'nemvo-analytics' ),
			'payload'      => __( 'The request was not a valid tracker hit.', 'nemvo-analytics' ),
			'gpc'          => __( 'The browser sent Global Privacy Control (Firefox and Brave private windows, DuckDuckGo) and "Respect Do Not Track and Global Privacy Control" is on.', 'nemvo-analytics' ),
			'dnt'          => __( 'The browser sent Do Not Track and "Respect Do Not Track and Global Privacy Control" is on.', 'nemvo-analytics' ),
			'ip'           => __( 'The visitor\'s IP address is in "Excluded IP addresses".', 'nemvo-analytics' ),
			'host'         => __( 'The page address is not this site\'s address (see "host" in the details).', 'nemvo-analytics' ),
			'path'         => __( 'The page is in "Excluded paths".', 'nemvo-analytics' ),
			'database'     => __( 'The statistics database could not be written.', 'nemvo-analytics' ),
			'client_gpc'   => __( 'The tracker stopped in the browser: it sends Global Privacy Control (Firefox and Brave private windows, Brave, DuckDuckGo) and Tracking › "Respect Do Not Track and Global Privacy Control" is on.', 'nemvo-analytics' ),
			'client_dnt'   => __( 'The tracker stopped in the browser: it sends Do Not Track (in Chrome: Settings › Privacy and security › Third-party cookies › "Send a Do Not Track request") and Tracking › "Respect Do Not Track and Global Privacy Control" is on.', 'nemvo-analytics' ),
			'client_ignore' => __( 'This browser excluded itself with #nva-ignore. Open any page with #nva-track at the end of the address to undo it.', 'nemvo-analytics' ),
			'client_error' => __( 'The tracker failed in the browser:', 'nemvo-analytics' ),
		);
	}

	/**
	 * Server-side checks of the home page and the collection endpoint.
	 *
	 * @return array[] List of [status good|warning|critical, title, detail].
	 */
	public static function checks() {
		$out  = array();
		$args = array(
			'timeout'     => 15,
			'redirection' => 3,
			'sslverify'   => apply_filters( 'https_local_ssl_verify', false ), // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- a WordPress core filter.
			'headers'     => array( 'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36' ),
		);

		// 1. The home page as a visitor sees it, and a fresh copy past any page cache.
		$plain = wp_remote_get( home_url( '/' ), $args );
		$fresh = wp_remote_get( add_query_arg( 'nva-check', wp_rand(), home_url( '/' ) ), $args );
		if ( is_wp_error( $plain ) ) {
			/* translators: %s: error message */
			$out[] = array( 'warning', __( 'The server cannot load its own home page', 'nemvo-analytics' ), sprintf( __( 'So the page could not be checked from here (%s). Use the live check below instead.', 'nemvo-analytics' ), $plain->get_error_message() ) );
		} else {
			$has_tracker = static function ( $response ) {
				return ! is_wp_error( $response ) && false !== strpos( wp_remote_retrieve_body( $response ), 'id="nva-tracker"' );
			};
			$code = (int) wp_remote_retrieve_response_code( $plain );
			if ( $has_tracker( $plain ) ) {
				$out[] = array( 'good', __( 'The tracker is on the home page', 'nemvo-analytics' ), '' );
			} elseif ( $has_tracker( $fresh ) ) {
				$out[] = array( 'critical', __( 'A page cache serves the home page without the tracker', 'nemvo-analytics' ), __( 'A fresh copy has the tracker, the cached one does not. Clear the page cache (in your caching plugin or hosting panel), and exclude the tracker from it. Nemvo Analytics is made for sites without page caching.', 'nemvo-analytics' ) );
			} elseif ( 200 !== $code ) {
				/* translators: %d: HTTP status */
				$out[] = array( 'warning', sprintf( __( 'The home page answered HTTP %d', 'nemvo-analytics' ), $code ), __( 'For example a maintenance mode or password protection. Visitors only get the tracker on pages that load normally.', 'nemvo-analytics' ) );
			} else {
				$out[] = array( 'critical', __( 'The tracker is missing from the home page', 'nemvo-analytics' ), __( 'Check that "Collect statistics" is on, that your theme calls wp_footer(), and that no plugin removes footer scripts.', 'nemvo-analytics' ) );
			}

			$cache = array();
			foreach ( array( 'cf-cache-status', 'x-cache', 'x-varnish', 'x-litespeed-cache', 'x-proxy-cache', 'x-sg-cache', 'x-kinsta-cache', 'x-wpe-cached', 'x-cache-status', 'x-fastcgi-cache', 'age' ) as $header ) {
				$value = wp_remote_retrieve_header( $plain, $header );
				if ( '' !== $value && ! ( 'age' === $header && '0' === (string) $value ) ) {
					$cache[] = $header . ': ' . ( is_array( $value ) ? reset( $value ) : $value );
				}
			}
			if ( $cache ) {
				/* translators: %s: response headers */
				$out[] = array( 'warning', __( 'A page cache or CDN answers for the site', 'nemvo-analytics' ), sprintf( __( 'Seen: %s. Pages cached before Nemvo Analytics was activated do not have the tracker; clear the cache.', 'nemvo-analytics' ), implode( ', ', $cache ) ) );
			}
			$csp = (string) wp_remote_retrieve_header( $plain, 'content-security-policy' );
			if ( '' !== $csp && preg_match( '/script-src[^;]*/i', $csp, $m ) && false === stripos( $m[0], "'unsafe-inline'" ) && Settings::get( 'inline_script' ) ) {
				$out[] = array( 'critical', __( 'A Content Security Policy blocks the inline tracker', 'nemvo-analytics' ), __( 'Turn off "Print the tracker inline" under Tracking, so the tracker loads as a file from your own site.', 'nemvo-analytics' ) );
			}
		}

		// 2. A test hit to the collection endpoint.
		$pv       = substr( md5( wp_generate_password( 20, false ) ), 0, 16 );
		$response = wp_remote_post(
			rest_url( Collector::NS . '/e' ),
			array_merge(
				$args,
				array(
					'headers' => $args['headers'] + array(
						'Content-Type'    => 'text/plain',
						'Accept-Language' => 'en',
						'Sec-Fetch-Site'  => 'same-origin',
					),
					'body'    => wp_json_encode(
						array(
							't'  => 'pv',
							'id' => $pv,
							'u'  => home_url( '/nemvo-analytics-check/' ),
							'r'  => '',
							'ti' => 'Nemvo Analytics check',
							'b'  => 0,
							'c'  => array( 'type' => 'other' ),
						)
					),
				)
			)
		);
		if ( is_wp_error( $response ) ) {
			/* translators: %s: error message */
			$out[] = array( 'warning', __( 'The collection endpoint could not be tested from the server', 'nemvo-analytics' ), sprintf( __( '%s. Use the live check below.', 'nemvo-analytics' ), $response->get_error_message() ) );
		} else {
			$code = (int) wp_remote_retrieve_response_code( $response );
			$db   = DB::conn();
			$row  = $db ? $db->get_row( $db->prepare( 'SELECT session_id FROM `' . DB::table( 'pageviews' ) . '` WHERE pv = %s', $pv ) ) : null; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
			if ( Queue::enabled() && in_array( $code, array( 200, 204 ), true ) && ! $row ) {
				$out[] = array( 'good', __( 'Hits are accepted and queued', 'nemvo-analytics' ), __( 'The worker writes them to the database.', 'nemvo-analytics' ) );
			} elseif ( $row ) {
				$out[] = array( 'good', __( 'Hits are received and stored', 'nemvo-analytics' ), '' );
				$db->query( $db->prepare( 'DELETE FROM `' . DB::table( 'pageviews' ) . '` WHERE pv = %s', $pv ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
				$db->query( $db->prepare( 'DELETE FROM `' . DB::table( 'sessions' ) . '` WHERE id = %d AND pageviews <= 1', $row->session_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
			} elseif ( in_array( $code, array( 401, 403 ), true ) ) {
				/* translators: %d: HTTP status */
				$out[] = array( 'critical', sprintf( __( 'The collection endpoint is blocked (HTTP %d)', 'nemvo-analytics' ), $code ), __( 'A security plugin or firewall blocks /wp-json/nemvo/v1/e. Allow it: it must accept anonymous POST requests.', 'nemvo-analytics' ) );
			} elseif ( $code >= 500 ) {
				/* translators: %d: HTTP status */
				$out[] = array( 'critical', sprintf( __( 'The collection endpoint fails (HTTP %d)', 'nemvo-analytics' ), $code ), __( 'Check the PHP error log; with WP_DEBUG_LOG on, WordPress writes it to wp-content/debug.log.', 'nemvo-analytics' ) );
			} else {
				/* translators: %d: HTTP status */
				$out[] = array( 'warning', sprintf( __( 'The test hit was answered (HTTP %d) but not stored', 'nemvo-analytics' ), $code ), __( 'Usually the server\'s own address is excluded, or tracking is paused. The live check below shows the reason for real visits.', 'nemvo-analytics' ) );
			}
		}

		if ( Settings::get( 'respect_dnt' ) ) {
			$out[] = array( 'warning', __( 'Visits from private windows in Firefox and Brave are not counted', 'nemvo-analytics' ), __( 'Those browsers send Global Privacy Control, which this site respects (Tracking › Respect Do Not Track and Global Privacy Control). Test in a Chrome, Edge or Safari private window, or turn that setting off.', 'nemvo-analytics' ) );
		}
		return $out;
	}
}
