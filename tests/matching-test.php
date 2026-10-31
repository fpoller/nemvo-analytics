<?php
/**
 * Tests of server-side page requests and how tracker hits are matched to them. Run in the dev container:
 *
 *   docker compose -f .devcontainer/docker-compose.yml exec -T wordpress wp eval-file /workspace/tests/matching-test.php
 *
 * Uses its own table prefix, so the demo data is left alone.
 *
 * @package NemvoAnalytics
 */

use NemvoAnalytics\Bot;
use NemvoAnalytics\Collector;
use NemvoAnalytics\DB;
use NemvoAnalytics\Settings;

defined( 'ABSPATH' ) || exit;

$GLOBALS['nva_fail'] = 0;
$GLOBALS['nva_pass'] = 0;

function nva_ok( $cond, $msg ) {
	$GLOBALS[ $cond ? 'nva_pass' : 'nva_fail' ]++;
	WP_CLI::log( ( $cond ? 'ok   ' : 'FAIL ' ) . $msg );
}

const NVA_CHROME = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36';

/** A page request as the web server sees it; returns the pageview id given to the tracker. */
function nva_request( $path, $ip, $ua = NVA_CHROME, array $extra = array() ) {
	$_SERVER = array_merge(
		$_SERVER,
		array(
			'REQUEST_METHOD'       => 'GET',
			'REQUEST_URI'          => $path,
			'REMOTE_ADDR'          => $ip,
			'HTTP_USER_AGENT'      => $ua,
			'HTTP_ACCEPT_LANGUAGE' => 'sv-SE,sv;q=0.9',
			'HTTP_SEC_FETCH_MODE'  => 'navigate',
			'HTTP_REFERER'         => 'https://www.google.se/',
		),
		$extra
	);
	return Collector::server_hit();
}

/** A tracker hit from the browser. */
function nva_beacon( array $payload, $ip, $ua = NVA_CHROME ) {
	$_SERVER['REMOTE_ADDR']     = $ip;
	$_SERVER['HTTP_USER_AGENT'] = $ua;
	$request                    = new WP_REST_Request( 'POST', '/nemvo/v1/e' );
	$request->set_body( wp_json_encode( $payload ) );
	$request->set_header( 'accept-language', 'sv-SE' );
	$request->set_header( 'sec-fetch-site', 'same-origin' );
	$response = rest_do_request( $request );
	Collector::flush();
	return $response;
}

function nva_tracker_pv( $id, $path ) {
	return array(
		't'  => 'pv',
		'id' => $id,
		'u'  => home_url( $path ),
		'r'  => 'https://www.google.se/',
		'ti' => 'Page',
		'w'  => 1440,
		'tz' => 'Europe/Stockholm',
		'l'  => 'sv-SE',
		'b'  => 0,
		'c'  => array( 'type' => 'page' ),
	);
}

function nva_session_of( $pv ) {
	$db = DB::conn();
	return $db->get_row( $db->prepare( 'SELECT s.* FROM `' . DB::table( 'sessions' ) . '` s JOIN `' . DB::table( 'pageviews' ) . '` p ON p.session_id = s.id WHERE p.pv = %s', $pv ), ARRAY_A ); // phpcs:ignore
}

global $wpdb;
$saved_prefix = $wpdb->prefix;
$saved        = get_option( Settings::OPTION );
$wpdb->prefix = 'nvamatch_';
$active       = new ReflectionProperty( DB::class, 'active' );
$active->setAccessible( true );
$active->setValue( null, 'local' );
DB::install( 'local' );
DB::truncate( 'local' );
update_option( Settings::OPTION, array_merge( (array) $saved, array( 'exclude_roles' => array(), 'respect_dnt' => 1, 'server_hits' => 1 ) ) );
Settings::flush();
wp_set_current_user( 0 );

WP_CLI::log( '== A person: page request + tracker' );
$id = nva_request( '/services/', '81.236.20.1' );
nva_ok( (bool) preg_match( '/^[a-f0-9]{16}$/', $id ), "the server records the request and hands the tracker its id ($id)" );
$s = nva_session_of( $id );
nva_ok( $s && 1 === (int) $s['pageviews'] && (int) $s['bot_flags'] & Bot::NO_JS, 'stored at once, marked as not (yet) running JavaScript (score ' . ( $s['bot_score'] ?? '' ) . ')' );
nva_beacon( nva_tracker_pv( $id, '/services/' ), '81.236.20.1' );
$s     = nva_session_of( $id );
$count = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM `' . DB::table( 'pageviews' ) . '`' ); // phpcs:ignore
nva_ok( 1 === $count && 1 === (int) $s['pageviews'], 'the tracker hit confirms the same pageview: counted once' );
nva_ok( (int) $s['bot_flags'] & Bot::JS && (int) $s['bot_score'] < 50, 'and turns it into a person (score ' . $s['bot_score'] . ')' );
nva_ok( 'xl' === $s['screen'], 'the tracker adds the screen size (' . $s['screen'] . ')' );
nva_beacon( array( 't' => 'e', 'id' => $id, 'd' => 20000, 's' => 70, 'i' => 1 ), '81.236.20.1' );
nva_ok( 20 === (int) nva_session_of( $id )['duration'], 'engagement lands on the matched pageview' );

$id2 = nva_request( '/contact/', '81.236.20.1', NVA_CHROME, array( 'HTTP_REFERER' => home_url( '/services/' ) ) );
nva_beacon( nva_tracker_pv( $id2, '/contact/' ), '81.236.20.1' );
$s = nva_session_of( $id2 );
nva_ok( 2 === (int) $s['pageviews'] && '/contact/' === $s['exit_path'], 'the next page continues the visit, again once' );

WP_CLI::log( '== No JavaScript' );
$id = nva_request( '/blog/', '81.236.20.2', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36' );
$s  = nva_session_of( $id );
nva_ok( $s && (int) $s['bot_score'] >= 50, 'a request that never runs the tracker counts as automated (score ' . ( $s['bot_score'] ?? '' ) . ')' );
$id = nva_request( '/', '66.249.66.1', 'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)' );
$s  = nva_session_of( $id );
nva_ok( $s && 'Googlebot' === $s['browser'] && 100 === (int) $s['bot_score'], 'a crawler that never runs JavaScript is seen too: ' . ( $s['browser'] ?? '' ) );
$id = nva_request( '/', '81.236.20.3', 'curl/8.5.0', array( 'HTTP_SEC_FETCH_MODE' => '', 'HTTP_ACCEPT_LANGUAGE' => '' ) );
$s  = nva_session_of( $id );
nva_ok( $s && 'curl' === $s['browser'] && 100 === (int) $s['bot_score'], 'curl is seen and flagged' );

WP_CLI::log( '== A cached copy of a page' );
$id    = nva_request( '/about/', '81.236.20.4' );
$reply = nva_beacon( nva_tracker_pv( $id, '/about/' ), '81.236.20.5' );
$new   = $reply->get_data()['id'] ?? '';
nva_ok( '' !== $new && $new !== $id, 'someone else using the same id gets a pageview of their own, with a new id' );
nva_ok( nva_session_of( $new )['visitor'] !== nva_session_of( $id )['visitor'], 'and their own visit' );
nva_beacon( array( 't' => 'e', 'id' => $new, 'd' => 9000, 's' => 30, 'i' => 1 ), '81.236.20.5' );
nva_ok( 9 === (int) nva_session_of( $new )['duration'] && 0 === (int) nva_session_of( $id )['duration'], 'their engagement follows the new id' );

WP_CLI::log( '== Privacy signals and settings' );
$id = nva_request( '/', '81.236.20.6', NVA_CHROME, array( 'HTTP_SEC_GPC' => '1' ) );
nva_ok( '' === $id, 'a Global Privacy Control request is not recorded while respected' );
unset( $_SERVER['HTTP_SEC_GPC'] );
$id = nva_request( '/', '81.236.20.7', NVA_CHROME, array( 'HTTP_SEC_PURPOSE' => 'prefetch' ) );
nva_ok( '' === $id, 'a prefetch is not recorded' );
unset( $_SERVER['HTTP_SEC_PURPOSE'] );
update_option( Settings::OPTION, array_merge( (array) get_option( Settings::OPTION ), array( 'respect_dnt' => 0 ) ) );
Settings::flush();
$id = nva_request( '/', '81.236.20.8', NVA_CHROME, array( 'HTTP_SEC_GPC' => '1' ) );
nva_ok( '' !== $id, 'with "Respect DNT/GPC" off, a Brave visit is recorded' );

// Clean up.
DB::drop( 'local' );
$known = get_option( DB::SCHEMA_OPTION );
foreach ( (array) $known as $sig => $v ) {
	if ( false !== strpos( (string) $sig, 'nvamatch_' ) ) {
		unset( $known[ $sig ] );
	}
}
update_option( DB::SCHEMA_OPTION, $known );
$wpdb->prefix = $saved_prefix;
update_option( Settings::OPTION, $saved );

WP_CLI::log( sprintf( '%d passed, %d failed', $GLOBALS['nva_pass'], $GLOBALS['nva_fail'] ) );
if ( $GLOBALS['nva_fail'] ) {
	WP_CLI::halt( 1 );
}
