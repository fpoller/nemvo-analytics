<?php
/**
 * Optional Cap proof-of-work verification (https://capjs.js.org, self-hosted).
 *
 * @package NemvoAnalytics
 */

namespace NemvoAnalytics;

defined( 'ABSPATH' ) || exit;

/**
 * When Cap is on, the tracker solves one invisible Cap challenge per visit
 * and the collector verifies the token with Cap's siteverify endpoint.
 * Browsers talk to WordPress, which relays challenge and redeem requests to
 * the Cap server; so Cap can stay on an internal network (e.g. http://cap:3000
 * in Docker) and visitors load nothing from another host. A public Cap URL
 * can be set instead to skip the relay.
 */
class Cap {

	/** Version of the Cap widget bundled in assets/vendor/cap. */
	const WIDGET_VERSION = '0.1.58';

	/**
	 * Cached configuration (false = not read yet).
	 *
	 * @var array|null|false
	 */
	private static $cfg = false;

	/**
	 * Forget the cached configuration (after settings change, in tests).
	 */
	public static function reset() {
		self::$cfg = false;
	}

	/**
	 * Configuration, or null when Cap is off. NEMVO_ANALYTICS_CAP_URL,
	 * _SITE_KEY and _SECRET (constants or environment) turn it on.
	 *
	 * @return array{url:string,public_url:string,site_key:string,secret:string}|null
	 */
	public static function config() {
		if ( false !== self::$cfg ) {
			return self::$cfg;
		}
		if ( '' !== Settings::env( 'NEMVO_ANALYTICS_CAP_URL' ) ) {
			$cfg = array(
				'url'        => untrailingslashit( Settings::env( 'NEMVO_ANALYTICS_CAP_URL' ) ),
				'public_url' => untrailingslashit( Settings::env( 'NEMVO_ANALYTICS_CAP_PUBLIC_URL' ) ),
				'site_key'   => Settings::env( 'NEMVO_ANALYTICS_CAP_SITE_KEY' ),
				'secret'     => Settings::env( 'NEMVO_ANALYTICS_CAP_SECRET' ),
			);
		} else {
			$s   = Settings::services();
			$cfg = $s['cap_enabled'] ? array(
				'url'        => $s['cap_url'],
				'public_url' => $s['cap_public_url'],
				'site_key'   => $s['cap_site_key'],
				'secret'     => $s['cap_secret'],
			) : array();
		}
		self::$cfg = $cfg && self::configured( $cfg ) ? $cfg : null;
		return self::$cfg;
	}

	/**
	 * Whether a settings array has everything Cap needs.
	 *
	 * @param array $s Settings with url/cap_url, site_key/cap_site_key and secret/cap_secret.
	 * @return bool
	 */
	public static function configured( array $s ) {
		$url = $s['url'] ?? ( $s['cap_url'] ?? '' );
		$key = $s['site_key'] ?? ( $s['cap_site_key'] ?? '' );
		$sec = $s['secret'] ?? ( $s['cap_secret'] ?? '' );
		return '' !== $url && '' !== $key && '' !== $sec;
	}

	/**
	 * Whether Cap is in use.
	 *
	 * @return bool
	 */
	public static function enabled() {
		return null !== self::config();
	}

	/**
	 * What the tracker needs: the API endpoint (the relay or the public
	 * server) and the bundled widget files.
	 *
	 * @return array
	 */
	public static function client_config() {
		$cfg = self::config();
		$dir = NEMVO_ANALYTICS_URL . 'assets/vendor/cap/';
		return array(
			'endpoint' => '' !== $cfg['public_url'] ? trailingslashit( $cfg['public_url'] ) . rawurlencode( $cfg['site_key'] ) . '/' : trailingslashit( rest_url( Collector::NS . '/cap' ) ),
			'script'   => $dir . 'cap.min.js?ver=' . self::WIDGET_VERSION,
			'wasm'     => $dir . 'cap_wasm_bg.wasm',
			'hashwx'   => $dir . 'hashwx.wasm',
			'pako'     => $dir . 'pako_inflate.min.js',
		);
	}

	/**
	 * Register the relay routes.
	 */
	public static function register_routes() {
		foreach ( array( 'challenge', 'redeem' ) as $action ) {
			register_rest_route(
				Collector::NS,
				'/cap/' . $action,
				array(
					'methods'             => 'POST',
					'callback'            => array( __CLASS__, 'relay' ),
					'permission_callback' => '__return_true',
				)
			);
		}
	}

	/**
	 * Forward a challenge or redeem request to the Cap server.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public static function relay( \WP_REST_Request $request ) {
		$cfg = self::config();
		if ( ! $cfg ) {
			return new \WP_REST_Response( array( 'error' => 'Cap is not enabled.' ), 404 );
		}
		$action = substr( $request->get_route(), strrpos( $request->get_route(), '/' ) + 1 );
		$body   = (string) $request->get_body();
		if ( strlen( $body ) > 65536 ) {
			return new \WP_REST_Response( array( 'error' => 'Too large.' ), 413 );
		}
		$response = wp_remote_post(
			trailingslashit( $cfg['url'] ) . rawurlencode( $cfg['site_key'] ) . '/' . $action,
			array(
				'timeout' => 10,
				'headers' => array(
					'Content-Type'    => 'application/json',
					// Cap rate-limits per visitor IP.
					'X-Forwarded-For' => Privacy::client_ip(),
				),
				'body'    => '' === $body ? '{}' : $body,
			)
		);
		if ( is_wp_error( $response ) ) {
			return new \WP_REST_Response( array( 'error' => 'Cap is not reachable.' ), 502 );
		}
		$out = new \WP_REST_Response( json_decode( wp_remote_retrieve_body( $response ), true ), (int) wp_remote_retrieve_response_code( $response ) );
		$out->header( 'Cache-Control', 'no-store' );
		return $out;
	}

	/**
	 * Verify a token with siteverify.
	 *
	 * @param string $token Token from the widget.
	 * @return bool
	 */
	public static function verify( $token ) {
		$cfg = self::config();
		if ( ! $cfg || '' === $token || strlen( $token ) > 512 ) {
			return false;
		}
		$response = wp_remote_post(
			trailingslashit( $cfg['url'] ) . rawurlencode( $cfg['site_key'] ) . '/siteverify',
			array(
				'timeout' => 5,
				'headers' => array( 'Content-Type' => 'application/json' ),
				'body'    => wp_json_encode(
					array(
						'secret'   => $cfg['secret'],
						'response' => $token,
					)
				),
			)
		);
		if ( is_wp_error( $response ) ) {
			return false;
		}
		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		return ! empty( $data['success'] );
	}

	/**
	 * Check that the server is reachable and accepts the secret (settings page).
	 *
	 * @param array $s Services settings.
	 * @return array{ok:bool,message:string}
	 */
	public static function test( array $s ) {
		if ( ! self::configured( $s ) ) {
			return array(
				'ok'      => false,
				'message' => __( 'Enter the server address, site key and secret key first.', 'nemvo-analytics' ),
			);
		}
		$response = wp_remote_post(
			trailingslashit( $s['cap_url'] ) . rawurlencode( $s['cap_site_key'] ) . '/siteverify',
			array(
				'timeout' => 10,
				'headers' => array( 'Content-Type' => 'application/json' ),
				'body'    => wp_json_encode(
					array(
						'secret'   => $s['cap_secret'],
						'response' => 'connection-test',
					)
				),
			)
		);
		if ( is_wp_error( $response ) ) {
			return array(
				'ok'      => false,
				/* translators: %s: error message */
				'message' => sprintf( __( 'Cap is not reachable from WordPress: %s', 'nemvo-analytics' ), $response->get_error_message() ),
			);
		}
		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $data ) || ! array_key_exists( 'success', $data ) ) {
			return array(
				'ok'      => false,
				/* translators: %d: HTTP status */
				'message' => sprintf( __( 'The server answered with HTTP %d but not like Cap. Check the address and the site key.', 'nemvo-analytics' ), (int) wp_remote_retrieve_response_code( $response ) ),
			);
		}
		if ( ! empty( $data['error'] ) && false !== stripos( (string) $data['error'], 'secret' ) ) {
			return array(
				'ok'      => false,
				'message' => __( 'Cap rejected the site key or the secret key.', 'nemvo-analytics' ),
			);
		}
		return array(
			'ok'      => true,
			'message' => __( 'Cap is reachable and accepts the keys.', 'nemvo-analytics' ),
		);
	}
}
