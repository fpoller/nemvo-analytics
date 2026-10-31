<?php
/**
 * Cookieless visitor identification and exclusion rules.
 *
 * @package NemvoAnalytics
 */

namespace NemvoAnalytics;

defined( 'ABSPATH' ) || exit;

/**
 * Visitors are recognised by a hash of a secret salt, the site, the IP
 * address and the user agent. The salt is random and replaced every day
 * (or week/month), so a hash cannot be linked across periods and cannot be
 * reversed to an IP. The IP itself is never stored.
 */
class Privacy {

	const SALT_OPTION = 'nemvo_analytics_salt';

	/**
	 * The current period key, e.g. 2026-10-31, 2026-W44 or 2026-10.
	 *
	 * @return string
	 */
	public static function period() {
		$now = current_datetime();
		switch ( Settings::get( 'salt_rotation' ) ) {
			case 'weekly':
				return $now->format( 'o-\WW' );
			case 'monthly':
				return $now->format( 'Y-m' );
			default:
				return $now->format( 'Y-m-d' );
		}
	}

	/**
	 * The salt for the current period; replaced when the period changes.
	 *
	 * @return string
	 */
	public static function salt() {
		$period = self::period();
		$stored = get_option( self::SALT_OPTION );
		if ( is_array( $stored ) && isset( $stored['period'], $stored['salt'] ) && $stored['period'] === $period ) {
			return $stored['salt'];
		}
		$salt = bin2hex( random_bytes( 32 ) );
		update_option(
			self::SALT_OPTION,
			array(
				'period' => $period,
				'salt'   => $salt,
			),
			true
		);
		return $salt;
	}

	/**
	 * Anonymous visitor id for this request.
	 *
	 * @param string $ip         Client IP.
	 * @param string $user_agent User agent.
	 * @return string 16 hex characters.
	 */
	public static function visitor_hash( $ip, $user_agent ) {
		$host = wp_parse_url( home_url(), PHP_URL_HOST );
		return substr( hash( 'sha256', self::salt() . '|' . $host . '|' . $ip . '|' . $user_agent ), 0, 16 );
	}

	/**
	 * How forwarded headers (X-Forwarded-For and friends) are treated.
	 *
	 * @return string auto|always|never
	 */
	public static function proxy_mode() {
		$mode = Settings::get( 'trust_proxy' );
		if ( 1 === $mode || '1' === $mode || 'always' === $mode ) {
			return 'always';
		}
		return 'never' === $mode ? 'never' : 'auto';
	}

	/**
	 * Client IP. Forwarded headers can be faked by anyone, so they are used
	 * only when the connection comes from a proxy: always, never, or (auto)
	 * when the connecting address is a private one, like a reverse proxy on
	 * the same network (Nginx Proxy Manager, Traefik, Caddy...).
	 *
	 * @return string
	 */
	public static function client_ip() {
		$remote = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		$mode   = self::proxy_mode();
		if ( 'never' === $mode || ( 'auto' === $mode && self::is_public( $remote ) ) ) {
			return $remote;
		}
		$forwarded = self::forwarded_ip();
		return '' !== $forwarded ? $forwarded : $remote;
	}

	/**
	 * The visitor address a proxy passed on: CDN headers first, then the
	 * right-most public address in X-Forwarded-For (the one the nearest proxy
	 * saw), then X-Real-IP.
	 *
	 * @return string Empty when there is none.
	 */
	public static function forwarded_ip() {
		foreach ( array( 'HTTP_CF_CONNECTING_IP', 'HTTP_TRUE_CLIENT_IP', 'HTTP_FASTLY_CLIENT_IP' ) as $header ) {
			$ip = isset( $_SERVER[ $header ] ) ? trim( sanitize_text_field( wp_unslash( $_SERVER[ $header ] ) ) ) : '';
			if ( self::is_public( $ip ) ) {
				return $ip;
			}
		}
		if ( ! empty( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) {
			$chain = array_reverse( array_map( 'trim', explode( ',', sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) ) ) );
			foreach ( $chain as $ip ) {
				if ( self::is_public( $ip ) ) {
					return $ip;
				}
			}
		}
		$ip = isset( $_SERVER['HTTP_X_REAL_IP'] ) ? trim( sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_REAL_IP'] ) ) ) : '';
		return self::is_public( $ip ) ? $ip : '';
	}

	/**
	 * Whether an address is a public one (not private, loopback or reserved).
	 *
	 * @param string $ip Address.
	 * @return bool
	 */
	public static function is_public( $ip ) {
		return '' !== $ip && false !== filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE );
	}

	/**
	 * Whether the browser asked not to be tracked (DNT or Global Privacy Control).
	 *
	 * @return bool
	 */
	public static function opted_out() {
		return '' !== self::opt_out_signal();
	}

	/**
	 * Which privacy signal the browser sent, when they are respected.
	 *
	 * @return string gpc, dnt or ''.
	 */
	public static function opt_out_signal() {
		if ( ! Settings::get( 'respect_dnt' ) ) {
			return '';
		}
		if ( isset( $_SERVER['HTTP_SEC_GPC'] ) && '1' === sanitize_text_field( wp_unslash( $_SERVER['HTTP_SEC_GPC'] ) ) ) {
			return 'gpc';
		}
		return isset( $_SERVER['HTTP_DNT'] ) && '1' === sanitize_text_field( wp_unslash( $_SERVER['HTTP_DNT'] ) ) ? 'dnt' : '';
	}

	/**
	 * Whether the IP matches an excluded address or CIDR range.
	 *
	 * @param string $ip Client IP.
	 * @return bool
	 */
	public static function ip_excluded( $ip ) {
		$rules = trim( (string) Settings::get( 'exclude_ips' ) );
		if ( '' === $rules || '' === $ip ) {
			return false;
		}
		foreach ( preg_split( '/\s+/', $rules ) as $rule ) {
			if ( self::ip_in_range( $ip, $rule ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * CIDR match for IPv4 and IPv6.
	 *
	 * @param string $ip   Address.
	 * @param string $rule Address or CIDR.
	 * @return bool
	 */
	public static function ip_in_range( $ip, $rule ) {
		$bits = null;
		if ( false !== strpos( $rule, '/' ) ) {
			list( $rule, $bits ) = explode( '/', $rule, 2 );
			$bits                = (int) $bits;
		}
		$a = @inet_pton( $ip ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		$b = @inet_pton( $rule ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		if ( false === $a || false === $b || strlen( $a ) !== strlen( $b ) ) {
			return false;
		}
		if ( null === $bits ) {
			return $a === $b;
		}
		$bytes = intdiv( $bits, 8 );
		if ( substr( $a, 0, $bytes ) !== substr( $b, 0, $bytes ) ) {
			return false;
		}
		$rest = $bits % 8;
		if ( 0 === $rest ) {
			return true;
		}
		$mask = 0xFF << ( 8 - $rest ) & 0xFF;
		return ( ord( $a[ $bytes ] ) & $mask ) === ( ord( $b[ $bytes ] ) & $mask );
	}

	/**
	 * Whether a path matches the excluded-path rules (* is a wildcard).
	 *
	 * @param string $path Request path.
	 * @return bool
	 */
	public static function path_excluded( $path ) {
		$rules = trim( (string) Settings::get( 'exclude_paths' ) );
		if ( '' === $rules ) {
			return false;
		}
		foreach ( preg_split( '/\r\n|\r|\n/', $rules ) as $rule ) {
			$rule = trim( $rule );
			if ( '' === $rule ) {
				continue;
			}
			$regex = '#^' . str_replace( '\*', '.*', preg_quote( $rule, '#' ) ) . '$#i';
			if ( preg_match( $regex, $path ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Text for Settings > Privacy > Policy guide.
	 *
	 * @return string
	 */
	public static function policy_text() {
		$paragraphs = array(
			__( 'We use a self-hosted, privacy-friendly analytics tool to understand how our website is used. It does not use cookies or similar technologies, does not store your IP address, and does not create profiles across websites or over time.', 'nemvo-analytics' ),
			__( 'To count unique visitors, a one-way hash of your IP address and browser user agent is combined with a secret that changes regularly; old secrets are deleted, so the hash cannot be linked back to you. We record the pages you visit, how long they are in view and how far you scroll, the referring website or campaign, your approximate location, your device, browser and operating system type, and the kind of network you visit from: for example a home connection, a mobile network, or the network of a company or organisation, whose name we may record. To tell people from automated traffic, your browser may solve a small computational puzzle in the background.', 'nemvo-analytics' ),
			__( 'The data is stored on our own servers, is never shared with third parties and is deleted automatically after a limited period. Browsers that send a Do Not Track or Global Privacy Control signal are not tracked.', 'nemvo-analytics' ),
		);
		return '<p class="privacy-policy-tutorial">' . esc_html__( 'Suggested text for your privacy policy. Adjust it to your configuration. The [nemvo_analytics_optout] shortcode adds an opt-out checkbox.', 'nemvo-analytics' ) . '</p>'
			. '<strong class="privacy-policy-tutorial">' . esc_html__( 'Suggested text:', 'nemvo-analytics' ) . ' </strong>'
			. implode( '', array_map( static function ( $p ) {
				return '<p>' . esc_html( $p ) . '</p>';
			}, $paragraphs ) );
	}
}
