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
	 * Client IP. Proxy headers are only honoured when explicitly enabled,
	 * otherwise anyone could spoof them.
	 *
	 * @return string
	 */
	public static function client_ip() {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		if ( Settings::get( 'trust_proxy' ) ) {
			foreach ( array( 'HTTP_CF_CONNECTING_IP', 'HTTP_TRUE_CLIENT_IP', 'HTTP_X_REAL_IP', 'HTTP_X_FORWARDED_FOR' ) as $header ) {
				if ( empty( $_SERVER[ $header ] ) ) {
					continue;
				}
				$candidate = trim( explode( ',', sanitize_text_field( wp_unslash( $_SERVER[ $header ] ) ) )[0] );
				if ( filter_var( $candidate, FILTER_VALIDATE_IP ) ) {
					$ip = $candidate;
					break;
				}
			}
		}
		return $ip;
	}

	/**
	 * Whether the browser asked not to be tracked (DNT or Global Privacy Control).
	 *
	 * @return bool
	 */
	public static function opted_out() {
		if ( ! Settings::get( 'respect_dnt' ) ) {
			return false;
		}
		$dnt = isset( $_SERVER['HTTP_DNT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_DNT'] ) ) : '';
		$gpc = isset( $_SERVER['HTTP_SEC_GPC'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_SEC_GPC'] ) ) : '';
		return '1' === $dnt || '1' === $gpc;
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
