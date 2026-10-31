<?php
/**
 * Built-in proof of work.
 *
 * @package NemvoAnalytics
 */

namespace NemvoAnalytics;

defined( 'ABSPATH' ) || exit;

/**
 * A small SHA-256 proof of work the tracker solves once per visit, in the
 * background (about 4,000 hashes on average, a few dozen milliseconds).
 * It costs scripts that fake hits without a browser, and a solved challenge
 * counts as a sign of a real browser. Challenges are stateless: signed with
 * an HMAC and bound to the pageview, valid for ten minutes.
 */
class Pow {

	const BITS = 12;
	const TTL  = 600;

	/**
	 * A challenge for a pageview.
	 *
	 * @param string $pv Pageview id.
	 * @return string
	 */
	public static function challenge( $pv ) {
		$payload = $pv . '.' . ( time() + self::TTL );
		return $payload . '.' . substr( hash_hmac( 'sha256', $payload, self::key() ), 0, 24 );
	}

	/**
	 * Check a solution.
	 *
	 * @param string $challenge Challenge from challenge().
	 * @param string $nonce     Nonce found by the browser.
	 * @param string $pv        Pageview id the solution was sent for.
	 * @return bool
	 */
	public static function verify( $challenge, $nonce, $pv ) {
		$parts = explode( '.', (string) $challenge );
		if ( 3 !== count( $parts ) || $parts[0] !== $pv || (int) $parts[1] < time() || ! preg_match( '/^[0-9a-z]{1,16}$/', (string) $nonce ) ) {
			return false;
		}
		$expected = substr( hash_hmac( 'sha256', $parts[0] . '.' . $parts[1], self::key() ), 0, 24 );
		if ( ! hash_equals( $expected, $parts[2] ) ) {
			return false;
		}
		$hash = hash( 'sha256', $challenge . $nonce, true );
		// Leading zero bits: the first BITS bits of the hash must be 0.
		$full = intdiv( self::BITS, 8 );
		for ( $i = 0; $i < $full; $i++ ) {
			if ( "\0" !== $hash[ $i ] ) {
				return false;
			}
		}
		$rest = self::BITS % 8;
		return 0 === $rest || 0 === ( ord( $hash[ $full ] ) >> ( 8 - $rest ) );
	}

	/**
	 * Secret for signing challenges.
	 *
	 * @return string
	 */
	private static function key() {
		return wp_salt( 'nonce' ) . '|nemvo-analytics-pow';
	}
}
