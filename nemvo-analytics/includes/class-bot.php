<?php
/**
 * Automated traffic detection.
 *
 * @package NemvoAnalytics
 */

namespace NemvoAnalytics;

defined( 'ABSPATH' ) || exit;

/**
 * Nothing is blocked: every visit is recorded with a bot score (0-100) and
 * the signals behind it (a bitmask), and the dashboard shows people unless
 * asked otherwise. Signals come from the request, the network, browser
 * checks in the tracker, behaviour, and an optional proof of work (built-in
 * or Cap). Positive weights point at automation, negative ones at a person.
 */
class Bot {

	const UA_BOT      = 1;
	const HEADLESS    = 2;
	const NO_CLIENT   = 4;
	const NO_LANGUAGE = 8;
	const DATACENTER  = 16;
	const RATE        = 32;
	const ANOMALY     = 64;
	const REF_SPAM    = 128;
	const FETCH_META  = 256;
	const PASSIVE     = 512;
	const INTERACTION = 1024;
	const POW         = 2048;
	const CAP         = 4096;
	const NO_JS       = 8192;
	const JS          = 16384;

	/**
	 * Weight per signal.
	 *
	 * @return int[]
	 */
	public static function weights() {
		return array(
			self::UA_BOT      => 100,
			self::HEADLESS    => 90,
			self::NO_CLIENT   => 60,
			self::NO_JS       => 50,
			self::REF_SPAM    => 60,
			self::DATACENTER  => 30,
			self::RATE        => 60,
			self::ANOMALY     => 30,
			self::NO_LANGUAGE => 30,
			self::PASSIVE     => 20,
			self::FETCH_META  => 20,
			self::INTERACTION => -30,
			self::POW         => -15,
			self::CAP         => -60,
			self::JS          => 0,
		);
	}

	/**
	 * Labels for the signals.
	 *
	 * @return string[]
	 */
	public static function labels() {
		return array(
			self::UA_BOT      => __( 'Crawler or tool user agent', 'nemvo-analytics' ),
			self::HEADLESS    => __( 'Automated browser', 'nemvo-analytics' ),
			self::NO_CLIENT   => __( 'Hit sent without the tracker', 'nemvo-analytics' ),
			self::NO_JS       => __( 'Did not run JavaScript', 'nemvo-analytics' ),
			self::JS          => __( 'Ran the tracker', 'nemvo-analytics' ),
			self::REF_SPAM    => __( 'Referrer spam', 'nemvo-analytics' ),
			self::DATACENTER  => __( 'Datacenter or VPN network', 'nemvo-analytics' ),
			self::RATE        => __( 'Too many pages too fast', 'nemvo-analytics' ),
			self::ANOMALY     => __( 'Browser inconsistencies', 'nemvo-analytics' ),
			self::NO_LANGUAGE => __( 'No Accept-Language header', 'nemvo-analytics' ),
			self::PASSIVE     => __( 'No interaction at all', 'nemvo-analytics' ),
			self::FETCH_META  => __( 'Missing browser request headers', 'nemvo-analytics' ),
			self::INTERACTION => __( 'Mouse, touch, keyboard or scroll', 'nemvo-analytics' ),
			self::POW         => __( 'Solved the proof of work', 'nemvo-analytics' ),
			self::CAP         => __( 'Passed Cap', 'nemvo-analytics' ),
		);
	}

	/**
	 * Score for a set of signals.
	 *
	 * @param int $flags Bitmask.
	 * @return int 0-100.
	 */
	public static function score( $flags ) {
		// A page request counts as "no JavaScript" only until the tracker confirms it.
		if ( $flags & self::JS ) {
			$flags &= ~self::NO_JS;
		}
		$score = 0;
		foreach ( self::weights() as $flag => $weight ) {
			if ( $flags & $flag ) {
				$score += $weight;
			}
		}
		return max( 0, min( 100, $score ) );
	}

	/**
	 * Score from which a visit counts as automated.
	 *
	 * @return int
	 */
	public static function threshold() {
		return (int) Settings::get( 'bot_threshold' );
	}

	/**
	 * Signals visible in the collection request itself.
	 *
	 * @param string $ua      User agent.
	 * @param array  $data    Hit payload.
	 * @param array  $headers Lowercase request headers (name => value).
	 * @param bool   $page    The page request itself (not a tracker hit).
	 * @return int Bitmask.
	 */
	public static function request_flags( $ua, array $data, array $headers, $page = false ) {
		$flags = 0;
		if ( User_Agent::is_bot( $ua ) ) {
			$flags |= self::UA_BOT;
		}
		if ( preg_match( '~HeadlessChrome|PhantomJS|Electron/|SlimerJS|Puppeteer|Playwright|Selenium~i', $ua ) ) {
			$flags |= self::HEADLESS;
		}
		if ( '' === trim( (string) ( $headers['accept_language'] ?? '' ) ) ) {
			$flags |= self::NO_LANGUAGE;
		}
		// Browsers from 2023 on send Fetch Metadata with every navigation, beacon and fetch.
		if ( $page ) {
			if ( '' === (string) ( $headers['sec_fetch_mode'] ?? '' ) && self::modern_browser( $ua ) ) {
				$flags |= self::FETCH_META;
			}
		} else {
			$site = (string) ( $headers['sec_fetch_site'] ?? '' );
			if ( '' === $site ? self::modern_browser( $ua ) : ! in_array( $site, array( 'same-origin', 'same-site' ), true ) ) {
				$flags |= self::FETCH_META;
			}
		}

		// Browser checks reported by the tracker; a hit without them was not sent by it.
		if ( $page ) {
			$flags |= self::NO_JS;
		} elseif ( ! isset( $data['b'] ) ) {
			$flags |= self::NO_CLIENT;
		} else {
			$flags |= self::JS;
			$client = absint( $data['b'] );
			if ( $client & ( 1 | 16 ) ) {
				$flags |= self::HEADLESS;
			}
			if ( $client & ( 2 | 4 | 8 ) ) {
				$flags |= self::ANOMALY;
			}
		}

		$referrer = Referrer::host_of( isset( $data['r'] ) ? (string) $data['r'] : '' );
		if ( '' !== $referrer && self::is_spam_referrer( $referrer ) ) {
			$flags |= self::REF_SPAM;
		}
		return $flags;
	}

	/**
	 * Whether the user agent claims a browser that always sends Fetch Metadata.
	 *
	 * @param string $ua User agent.
	 * @return bool
	 */
	private static function modern_browser( $ua ) {
		if ( preg_match( '~(?:Chrome|CriOS)/(\d+)~', $ua, $m ) ) {
			return (int) $m[1] >= 90;
		}
		if ( preg_match( '~Firefox/(\d+)~', $ua, $m ) ) {
			return (int) $m[1] >= 95;
		}
		if ( preg_match( '~Version/(\d+)(?:\.(\d+))?.*Safari/~', $ua, $m ) ) {
			return (int) $m[1] > 16 || ( 16 === (int) $m[1] && isset( $m[2] ) && (int) $m[2] >= 4 );
		}
		return false;
	}

	/**
	 * Known referrer spam domains (the long-running offenders; extend with the
	 * nemvo_analytics_spam_referrers filter).
	 *
	 * @param string $host Referrer host without www.
	 * @return bool
	 */
	public static function is_spam_referrer( $host ) {
		static $list = null;
		if ( null === $list ) {
			$list = (array) apply_filters(
				'nemvo_analytics_spam_referrers',
				array( 'semalt.com', 'buttons-for-website.com', 'buttons-for-your-website.com', 'darodar.com', 'ilovevitaly.com', 'priceg.com', 'blackhatworth.com', 'hulfingtonpost.com', 'best-seo-offer.com', 'best-seo-solution.com', 'free-share-buttons.com', 'get-free-traffic-now.com', 'trafficmonetize.org', 'success-seo.com', 'videos-for-your-business.com', 'social-buttons.com', 'simple-share-buttons.com', 'floating-share-buttons.com', 'webmonetizer.net', 'seo-platform.com', 'rank-checker.online', 'fix-website-errors.com', 'website-analyzer.info', 'site-auditor.online', 'traffic2cash.xyz', 'event-tracking.com', 'googlsucks.com', 'theguardlan.com', 'e-buyeasy.com', 'xn--q1a.xn--b1aube0e.xn--c1acygb.xn--p1ai' )
			);
		}
		foreach ( $list as $spam ) {
			if ( $host === $spam || substr( $host, -strlen( '.' . $spam ) ) === '.' . $spam ) {
				return true;
			}
		}
		return (bool) preg_match( '~(^|[.-])(free-?traffic|buttons-for|share-buttons|seo-?(offer|service|platform)|traffic-?(bot|monetiz))~', $host );
	}
}
