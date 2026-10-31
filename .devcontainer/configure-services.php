<?php
/**
 * Points the plugin at the dev container's Valkey and Cap, and creates a Cap
 * site key through Cap's admin API (run with `wp eval-file`). The queue and
 * Cap stay off until you switch them on under Analytics > Settings.
 *
 * @package NemvoAnalytics
 */

use NemvoAnalytics\Settings;

defined( 'ABSPATH' ) || exit;

$services = Settings::services();
$services = array_merge(
	$services,
	array(
		'queue_host' => 'valkey',
		'queue_port' => 6379,
		'queue_db'   => 0,
		'cap_url'    => 'http://cap:3000',
	)
);

if ( '' === $services['cap_site_key'] ) {
	$admin_key = (string) getenv( 'CAP_ADMIN_KEY' );
	$login     = null;
	for ( $i = 0; $i < 20 && ! $login; $i++ ) {
		$response = wp_remote_post(
			'http://cap:3000/auth/login',
			array(
				'headers' => array( 'Content-Type' => 'application/json' ),
				'body'    => wp_json_encode( array( 'admin_key' => $admin_key ) ),
			)
		);
		$data     = is_wp_error( $response ) ? null : json_decode( wp_remote_retrieve_body( $response ), true );
		$login    = ! empty( $data['success'] ) ? $data : null;
		if ( ! $login ) {
			sleep( 2 );
		}
	}
	if ( $login ) {
		$response = wp_remote_post(
			'http://cap:3000/server/keys',
			array(
				'headers' => array(
					'Content-Type'  => 'application/json',
					'Authorization' => 'Bearer ' . base64_encode( wp_json_encode( array( 'token' => $login['session_token'], 'hash' => $login['hashed_token'] ) ) ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
				),
				'body'    => wp_json_encode( array( 'name' => 'WordPress (dev)' ) ),
			)
		);
		$key      = is_wp_error( $response ) ? null : json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! empty( $key['siteKey'] ) ) {
			$services['cap_site_key'] = $key['siteKey'];
			$services['cap_secret']   = $key['secretKey'];
			WP_CLI::log( 'Created Cap site key ' . $key['siteKey'] . '.' );
		}
	} else {
		WP_CLI::warning( 'Cap did not answer; set it up later under Analytics > Settings.' );
	}
}

update_option( Settings::SERVICES_OPTION, $services, true );
WP_CLI::success( 'Valkey and Cap are configured (both off until enabled in the settings).' );
