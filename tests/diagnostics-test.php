<?php
/**
 * Tests of forwarded visitor addresses and Check tracking. Run in the dev container:
 *
 *   docker compose -f .devcontainer/docker-compose.yml exec -T wordpress wp eval-file /workspace/tests/diagnostics-test.php
 *
 * @package NemvoAnalytics
 */

use NemvoAnalytics\Collector;
use NemvoAnalytics\Diagnostics;
use NemvoAnalytics\Privacy;
use NemvoAnalytics\Settings;

defined( 'ABSPATH' ) || exit;

$GLOBALS['nva_fail'] = 0;
$GLOBALS['nva_pass'] = 0;

function nva_ok( $cond, $msg ) {
	$GLOBALS[ $cond ? 'nva_pass' : 'nva_fail' ]++;
	WP_CLI::log( ( $cond ? 'ok   ' : 'FAIL ' ) . $msg );
}

function nva_with_settings( array $patch ) {
	update_option( Settings::OPTION, array_merge( (array) get_option( Settings::OPTION, array() ), $patch ) );
	Settings::flush();
}

$saved = get_option( Settings::OPTION );

WP_CLI::log( '== Forwarded visitor addresses' );
$server = $_SERVER;
$cases  = array(
	// mode, REMOTE_ADDR, headers, expected.
	array( 'auto', '81.228.62.90', array( 'HTTP_X_FORWARDED_FOR' => '1.2.3.4' ), '81.228.62.90', 'auto: a public peer is the visitor, forwarded headers are ignored' ),
	array( 'auto', '172.18.0.2', array( 'HTTP_X_FORWARDED_FOR' => '84.32.41.136', 'HTTP_X_REAL_IP' => '84.32.41.136' ), '84.32.41.136', 'auto: behind a private reverse proxy (NPM), the forwarded address is used' ),
	array( 'auto', '172.18.0.2', array( 'HTTP_X_FORWARDED_FOR' => '9.9.9.9, 84.32.41.136' ), '84.32.41.136', 'auto: a faked X-Forwarded-For entry is skipped (right-most public address wins)' ),
	array( 'auto', '172.18.0.2', array( 'HTTP_X_FORWARDED_FOR' => '84.32.41.136, 10.0.0.5' ), '84.32.41.136', 'auto: private hops in the chain are skipped' ),
	array( 'auto', '172.18.0.2', array(), '172.18.0.2', 'auto: without forwarded headers the peer address stays' ),
	array( 'never', '172.18.0.2', array( 'HTTP_X_REAL_IP' => '84.32.41.136' ), '172.18.0.2', 'never: forwarded headers are ignored' ),
	array( 'always', '104.16.1.1', array( 'HTTP_CF_CONNECTING_IP' => '84.32.41.136' ), '84.32.41.136', 'always: the CDN header is used even from a public peer' ),
	array( '1', '104.16.1.1', array( 'HTTP_X_FORWARDED_FOR' => '84.32.41.136' ), '84.32.41.136', 'the old "trust proxy headers" on means always' ),
);
foreach ( $cases as $case ) {
	$_SERVER = array_merge( $server, array( 'REMOTE_ADDR' => $case[1] ), $case[2] );
	foreach ( array( 'HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'HTTP_CF_CONNECTING_IP' ) as $h ) {
		if ( ! isset( $case[2][ $h ] ) ) {
			unset( $_SERVER[ $h ] );
		}
	}
	nva_with_settings( array( 'trust_proxy' => $case[0] ) );
	$ip = Privacy::client_ip();
	nva_ok( $ip === $case[3], $case[4] . " ($ip)" );
}
$_SERVER = $server;
nva_with_settings( array( 'trust_proxy' => 'auto' ) );
$out = Settings::sanitize( array( 'trust_proxy' => 'nonsense' ) );
nva_ok( 'auto' === $out['trust_proxy'], 'an unknown mode is saved as auto' );

WP_CLI::log( '== Check tracking: server-side checks' );
Diagnostics::toggle( true );
$checks = Diagnostics::checks();
$titles = wp_list_pluck( $checks, 1 );
foreach ( $checks as $c ) {
	WP_CLI::log( '     ' . $c[0] . ': ' . $c[1] );
}
nva_ok( in_array( 'The tracker is on the home page', $titles, true ), 'finds the tracker on the home page' );
nva_ok( in_array( 'Hits are received and stored', $titles, true ), 'a test hit is stored' );

WP_CLI::log( '== Check tracking: live log' );
update_option( Diagnostics::LOG_OPTION, array(), false );
$hit = static function ( array $payload, array $headers = array() ) {
	$_SERVER['REMOTE_ADDR']     = '84.32.41.136';
	$_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36';
	unset( $_SERVER['HTTP_SEC_GPC'], $_SERVER['HTTP_DNT'] );
	foreach ( $headers as $k => $v ) {
		$_SERVER[ $k ] = $v;
	}
	$request = new WP_REST_Request( 'POST', '/nemvo/v1/e' );
	$request->set_body( wp_json_encode( $payload ) );
	$request->set_header( 'accept-language', 'sv' );
	$request->set_header( 'sec-fetch-site', 'same-origin' );
	rest_do_request( $request );
	Collector::flush();
};
$pv = array(
	't'  => 'pv',
	'id' => 'abcdef0123456789',
	'u'  => home_url( '/contact/' ),
	'r'  => '',
	'b'  => 0,
	'c'  => array( 'type' => 'page' ),
);
$hit( $pv, array( 'HTTP_SEC_GPC' => '1' ) );
$hit( array_merge( $pv, array( 'u' => 'https://another-site.example/' ) ) );
$hit( array( 't' => 'x', 'r' => 'dnt', 'm' => '/contact/' ) );
$hit( $pv );
$log = Diagnostics::entries();
$why = wp_list_pluck( $log, 'reason' );
nva_ok( in_array( 'gpc', $why, true ), 'a Global Privacy Control request is logged with its reason' );
nva_ok( in_array( 'host', $why, true ) && in_array( 'another-site.example', wp_list_pluck( $log, 'host' ), true ), 'a hit for another host is logged with that host' );
nva_ok( in_array( 'client_dnt', $why, true ), 'a browser that stopped because of Do Not Track says so' );
$stored = array_values( array_filter( $log, static function ( $e ) {
	return 'stored' === $e['outcome'] && 'pv' === $e['type'];
} ) );
nva_ok( $stored && '/contact/' === $stored[0]['path'], 'a normal pageview is logged as stored (' . ( $stored ? $stored[0]['network'] . ', score ' . $stored[0]['score'] . ', ' . $stored[0]['signals'] : '' ) . ')' );
Diagnostics::toggle( false );
$hit( array( 't' => 'x', 'r' => 'gpc' ) );
nva_ok( count( Diagnostics::entries() ) === count( $log ), 'nothing is logged after the check stops' );

// Remove the test visit.
$db = NemvoAnalytics\DB::conn();
$db->query( "DELETE s, p FROM `" . NemvoAnalytics\DB::table( 'sessions' ) . "` s JOIN `" . NemvoAnalytics\DB::table( 'pageviews' ) . "` p ON p.session_id = s.id WHERE p.pv = 'abcdef0123456789'" ); // phpcs:ignore
update_option( Settings::OPTION, $saved );
delete_option( Diagnostics::LOG_OPTION );

WP_CLI::log( sprintf( '%d passed, %d failed', $GLOBALS['nva_pass'], $GLOBALS['nva_fail'] ) );
if ( $GLOBALS['nva_fail'] ) {
	WP_CLI::halt( 1 );
}
