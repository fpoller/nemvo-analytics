<?php
/**
 * End-to-end tests of collection, the queue, bot signals, verification,
 * migration and the reports. Run in the dev container:
 *
 *   docker compose -f .devcontainer/docker-compose.yml exec -T wordpress wp eval-file /workspace/tests/pipeline-test.php
 *
 * Uses a separate table prefix in the WordPress database and restores all
 * settings afterwards, so the dev site's demo data is left alone.
 *
 * @package NemvoAnalytics
 */

use NemvoAnalytics\Bot;
use NemvoAnalytics\Cap;
use NemvoAnalytics\Collector;
use NemvoAnalytics\DB;
use NemvoAnalytics\Health;
use NemvoAnalytics\Migration;
use NemvoAnalytics\Pow;
use NemvoAnalytics\Queue;
use NemvoAnalytics\Settings;

defined( 'ABSPATH' ) || exit;

$GLOBALS['nva_fail'] = 0;
$GLOBALS['nva_pass'] = 0;

function nva_ok( $cond, $msg ) {
	if ( $cond ) {
		$GLOBALS['nva_pass']++;
		WP_CLI::log( "ok   $msg" );
	} else {
		$GLOBALS['nva_fail']++;
		WP_CLI::log( "FAIL $msg" );
	}
}

/** Send a hit to the collector like a browser would. */
function nva_hit( array $payload, $ip = '81.236.10.20', $ua = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36', array $headers = array() ) {
	$_SERVER['REMOTE_ADDR']     = $ip;
	$_SERVER['HTTP_USER_AGENT'] = $ua;
	$request                    = new WP_REST_Request( 'POST', '/nemvo/v1/e' );
	$request->set_body( wp_json_encode( $payload ) );
	$headers += array(
		'accept-language' => 'sv-SE,sv;q=0.9,en;q=0.8',
		'sec-fetch-site'  => 'same-origin',
	);
	foreach ( $headers as $name => $value ) {
		if ( null !== $value ) {
			$request->set_header( $name, $value );
		}
	}
	$response = rest_do_request( $request );
	Collector::flush();
	return $response;
}

function nva_pv( $id, $extra = array() ) {
	return array_merge(
		array(
			't'  => 'pv',
			'id' => $id,
			'u'  => home_url( '/services/cloud-migration/' ),
			'r'  => 'https://www.google.se/',
			'ti' => 'Cloud migration',
			'w'  => 1920,
			'tz' => 'Europe/Stockholm',
			'l'  => 'sv-SE',
			'tp' => 0,
			'b'  => 0,
			'c'  => array( 'type' => 'page', 'id' => 0 ),
		),
		$extra
	);
}

function nva_session( $pv ) {
	$db = DB::conn();
	return $db->get_row( $db->prepare( 'SELECT s.* FROM `' . DB::table( 'sessions' ) . '` s JOIN `' . DB::table( 'pageviews' ) . '` p ON p.session_id = s.id WHERE p.pv = %s', $pv ), ARRAY_A ); // phpcs:ignore
}

function nva_id() {
	return bin2hex( random_bytes( 8 ) );
}

/** Solve a proof-of-work challenge like the tracker does. */
function nva_solve( $challenge, $bits ) {
	for ( $i = 0; $i < 5000000; $i++ ) {
		$nonce = base_convert( (string) $i, 10, 36 );
		$hash  = hash( 'sha256', $challenge . $nonce, true );
		$ok    = true;
		for ( $b = 0; $b < $bits; $b++ ) {
			if ( ord( $hash[ intdiv( $b, 8 ) ] ) & ( 0x80 >> ( $b % 8 ) ) ) {
				$ok = false;
				break;
			}
		}
		if ( $ok ) {
			return $nonce;
		}
	}
	return '';
}

// Isolate: test tables with their own prefix, settings restored at the end.
global $wpdb;
$saved = array(
	'settings' => get_option( Settings::OPTION ),
	'services' => get_option( Settings::SERVICES_OPTION ),
	'storage'  => get_option( DB::STORAGE_OPTION ),
	'prefix'   => $wpdb->prefix,
);
$wpdb->prefix = 'nvatest_';
$reset        = static function () {
	foreach ( array( 'local', 'external' ) as $target ) {
		DB::install( $target );
		DB::truncate( $target );
	}
};
update_option( DB::STORAGE_OPTION, 'local' );
$reset_static = new ReflectionProperty( DB::class, 'active' );
$reset_static->setAccessible( true );
$reset_static->setValue( null, null );
$reset();

$settings                  = Settings::get();
$settings['exclude_roles'] = array();
update_option( Settings::OPTION, $settings );
Settings::flush();
wp_set_current_user( 0 );

WP_CLI::log( '== Direct collection' );
$pv1  = nva_id();
$resp = nva_hit( nva_pv( $pv1 ) );
$data = $resp->get_data();
nva_ok( 200 === $resp->get_status() && 'pow' === ( $data['v'] ?? '' ) && ! empty( $data['c'] ), 'pageview answers with a proof-of-work challenge' );
$s = nva_session( $pv1 );
nva_ok( $s && 1 === (int) $s['pageviews'] && '/services/cloud-migration/' === $s['entry_path'], 'session created with entry page' );
nva_ok( $s && 'Organic Search' === $s['channel'] && 'Google' === $s['source'], 'source attributed (Google, organic search)' );
nva_ok( $s && 'SE' === $s['country'], 'country from the IP database (' . ( $s['country'] ?? '' ) . ')' );
nva_ok( $s && (int) $s['asn'] > 0 && '' !== $s['network'], 'network looked up: AS' . ( $s['asn'] ?? 0 ) . ' ' . ( $s['org'] ?? '' ) . ' / ' . ( $s['network'] ?? '' ) );
nva_ok( $s && 0 === (int) $s['bot_score'], 'a normal browser scores 0' );

nva_hit( array( 't' => 'e', 'id' => $pv1, 'd' => 23000, 's' => 64, 'i' => 1 ) );
$s = nva_session( $pv1 );
nva_ok( 23 === (int) $s['duration'] && 0 === (int) $s['is_bounce'], 'engagement adds duration and ends the bounce' );
nva_ok( (bool) ( (int) $s['bot_flags'] & Bot::INTERACTION ), 'interaction flag recorded' );

nva_hit( array( 't' => 'ev', 'id' => $pv1, 'n' => 'Form submit', 'l' => 'Contact form' ) );
$s = nva_session( $pv1 );
nva_ok( 1 === (int) $s['events'], 'event counted on the session' );

$nonce = nva_solve( $data['c'], (int) $data['b'] );
nva_ok( Pow::verify( $data['c'], $nonce, $pv1 ), 'proof of work verifies' );
nva_ok( ! Pow::verify( $data['c'], $nonce, nva_id() ), 'proof of work is bound to its pageview' );
nva_hit( array( 't' => 'v', 'id' => $pv1, 'c' => $data['c'], 'n' => $nonce ) );
$s = nva_session( $pv1 );
nva_ok( (bool) ( (int) $s['bot_flags'] & Bot::POW ), 'solved proof of work recorded' );

$pv2  = nva_id();
$resp = nva_hit( nva_pv( $pv2, array( 'u' => home_url( '/contact/' ), 'r' => home_url( '/services/cloud-migration/' ) ) ) );
nva_ok( 204 === $resp->get_status(), 'a verified visit is not asked again' );
$s2 = nva_session( $pv2 );
nva_ok( $s2 && $s2['id'] === $s['id'] && 2 === (int) $s2['pageviews'] && '/contact/' === $s2['exit_path'], 'second page continues the session' );
$exits = $wpdb->get_col( $wpdb->prepare( 'SELECT is_exit FROM `' . DB::table( 'pageviews' ) . '` WHERE session_id = %d ORDER BY id', $s['id'] ) ); // phpcs:ignore
nva_ok( array( '0', '1' ) === $exits, 'only the last page is the exit' );

WP_CLI::log( '== Bot signals' );
$pv = nva_id();
nva_hit( nva_pv( $pv ), '66.249.66.1', 'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)' );
$s = nva_session( $pv );
nva_ok( $s && (int) $s['bot_score'] >= 50 && 'Googlebot' === $s['browser'], 'crawler recorded as automated: ' . ( $s['browser'] ?? '' ) . ', score ' . ( $s['bot_score'] ?? '' ) );

$pv = nva_id();
nva_hit( nva_pv( $pv, array( 'b' => 1 ) ), '81.236.10.21' );
$s = nva_session( $pv );
nva_ok( $s && (int) $s['bot_flags'] & Bot::HEADLESS, 'navigator.webdriver marks an automated browser' );

$pv      = nva_id();
$payload = nva_pv( $pv, array( 'r' => 'https://semalt.com/' ) );
unset( $payload['b'] );
nva_hit( $payload, '81.236.10.22', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36', array( 'accept-language' => null, 'sec-fetch-site' => null ) );
$s = nva_session( $pv );
nva_ok( $s && (int) $s['bot_flags'] & Bot::REF_SPAM && (int) $s['bot_flags'] & Bot::NO_CLIENT && (int) $s['bot_flags'] & Bot::FETCH_META, 'forged referrer spam: spam, no tracker, no fetch metadata (score ' . ( $s['bot_score'] ?? '' ) . ')' );

$pv = nva_id();
nva_hit( nva_pv( $pv ), '8.8.4.4' );
$s = nva_session( $pv );
nva_ok( $s && 'hosting' === $s['network'] && (int) $s['bot_flags'] & Bot::DATACENTER, 'datacenter network flagged (' . ( $s['org'] ?? '' ) . ')' );

$burst = nva_id();
nva_hit( nva_pv( $burst ), '81.236.10.23' );
for ( $i = 0; $i < 16; $i++ ) {
	nva_hit( nva_pv( nva_id(), array( 'u' => home_url( '/blog/p' . $i . '/' ) ) ), '81.236.10.23' );
}
$s = nva_session( $burst );
nva_ok( $s && (int) $s['bot_flags'] & Bot::RATE, 'too many pages too fast flagged' );

WP_CLI::log( '== Queue' );
$services                  = Settings::services();
$services['queue_enabled'] = 1;
update_option( Settings::SERVICES_OPTION, $services );
Queue::reset();
$test = Queue::test( Queue::from_settings( $services ) );
nva_ok( $test['ok'], 'Valkey reachable: ' . $test['message'] );
Queue::reset();
nva_ok( Queue::enabled(), 'queue on' );
while ( Queue::length() ) {
	Queue::work( 5 );
}
$pvq  = nva_id();
$resp = nva_hit( nva_pv( $pvq ), '81.236.10.40' );
nva_ok( 1 === Queue::length() && ! nva_session( $pvq ), 'pageview waits in the queue, not yet in the database' );
nva_ok( 'pow' === ( $resp->get_data()['v'] ?? '' ), 'queued pageview still gets a challenge' );
nva_hit( array( 't' => 'e', 'id' => $pvq, 'd' => 12000, 's' => 40, 'i' => 1 ), '81.236.10.40' );
nva_hit( array( 't' => 'ev', 'id' => $pvq, 'n' => 'File download', 'l' => '/x.pdf' ), '81.236.10.40' );
nva_ok( 3 === Queue::length(), 'engagement and event queued behind it' );
$n = Queue::work( 5 );
$s = nva_session( $pvq );
nva_ok( 3 === $n && $s && 12 === (int) $s['duration'] && 1 === (int) $s['events'], 'worker wrote the batch in order (duration 12 s, 1 event)' );
$batch_pvs = array();
for ( $i = 0; $i < 300; $i++ ) {
	$batch_pvs[] = nva_id();
	nva_hit( nva_pv( end( $batch_pvs ) ), '81.236.11.' . ( $i % 50 ) );
}
$t0 = microtime( true );
$n  = Queue::work( 10 );
nva_ok( 300 === $n, sprintf( '300 queued pageviews from 50 visitors written in %.0f ms', ( microtime( true ) - $t0 ) * 1000 ) );
$visits = (int) $wpdb->get_var( 'SELECT COUNT(DISTINCT session_id) FROM `' . DB::table( 'pageviews' ) . '` WHERE pv IN (\'' . implode( "','", $batch_pvs ) . '\')' ); // phpcs:ignore
nva_ok( 50 === $visits, "grouped into 50 visits ($visits)" );
$services['queue_enabled'] = 0;
update_option( Settings::SERVICES_OPTION, $services );
Queue::reset();

WP_CLI::log( '== Migration local -> external -> local' );
$db_local = DB::conn( 'local' );
$sum      = static function ( $target ) {
	$db = DB::conn( $target );
	return $db->get_var( 'SELECT CONCAT(COUNT(*), "/", SUM(pageviews), "/", SUM(duration), "/", SUM(bot_flags)) FROM `' . DB::table( 'sessions', $target ) . '`' ) . ' ' . $db->get_var( 'SELECT CONCAT(COUNT(*), "/", SUM(engaged_ms), "/", SUM(is_exit)) FROM `' . DB::table( 'pageviews', $target ) . '`' ); // phpcs:ignore
};
$local_sum = $sum( 'local' );
$start     = Migration::start( 'external', true );
nva_ok( ! is_wp_error( $start ), 'migration to external starts' );
do {
	$step = Migration::step( 5 );
} while ( ! is_wp_error( $step ) && ! $step['done'] );
nva_ok( ! is_wp_error( $step ) && 'external' === DB::active(), 'statistics now live in the external database' );
nva_ok( $sum( 'external' ) === $local_sum, 'external copy identical (' . $local_sum . ')' );
$pv = nva_id();
nva_hit( nva_pv( $pv ), '81.236.10.30' );
nva_ok( (bool) DB::conn( 'external' )->get_var( DB::conn( 'external' )->prepare( 'SELECT 1 FROM `' . DB::table( 'pageviews', 'external' ) . '` WHERE pv = %s', $pv ) ), 'new hits go to the external database' ); // phpcs:ignore
$ext_sum = $sum( 'external' );
$err     = Migration::start( 'local', false );
nva_ok( is_wp_error( $err ) && 'nemvo_migrate_not_empty' === $err->get_error_code(), 'moving back refuses to overwrite without confirmation' );
Migration::start( 'local', true );
do {
	$step = Migration::step( 5 );
} while ( ! is_wp_error( $step ) && ! $step['done'] );
nva_ok( 'local' === DB::active() && $sum( 'local' ) === $ext_sum, 'moved back to the WordPress database, identical (' . $ext_sum . ')' );
nva_ok( Migration::purge_inactive() && 0 === (int) DB::counts( 'external', true )['sessions'], 'old external copy deleted on request' );

WP_CLI::log( '== Reports' );
wp_set_current_user( 1 );
$today = current_datetime()->format( 'Y-m-d' );
$batch = new WP_REST_Request( 'POST', '/nemvo/v1/stats/batch' );
$reqs  = array();
foreach ( array( 'human', 'bot', 'all' ) as $traffic ) {
	$reqs[] = array( 'route' => 'overview', 'params' => array( 'from' => $today, 'to' => $today, 'traffic' => $traffic, 'compare' => 'previous' ) );
}
foreach ( array( 'channel', 'source', 'page', 'exits', 'country', 'city', 'device', 'browser', 'network', 'org', 'company', 'weekday', 'hour', 'goal', 'event', 'utm_campaign' ) as $dim ) {
	$reqs[] = array( 'route' => 'breakdown', 'params' => array( 'from' => $today, 'to' => $today, 'dim' => $dim, 'traffic' => 'all' ) );
}
$reqs[] = array( 'route' => 'sessions', 'params' => array( 'from' => $today, 'to' => $today, 'traffic' => 'all' ) );
$reqs[] = array( 'route' => 'bots', 'params' => array( 'from' => $today, 'to' => $today ) );
$reqs[] = array( 'route' => 'heatmap', 'params' => array( 'from' => $today, 'to' => $today ) );
$reqs[] = array( 'route' => 'realtime', 'params' => array() );
$reqs[] = array( 'route' => 'overview', 'params' => array( 'from' => $today, 'to' => $today, 'traffic' => 'all', 'filters' => wp_json_encode( array( array( 'd' => 'bot_flag', 'o' => 'is', 'v' => (string) Bot::UA_BOT ) ) ) ) );
$batch->set_param( 'requests', $reqs );
$out = rest_do_request( $batch )->get_data();
$all = true;
foreach ( $out as $i => $item ) {
	if ( 200 !== $item['status'] ) {
		$all = false;
		WP_CLI::log( '     ' . $reqs[ $i ]['route'] . ' ' . ( $reqs[ $i ]['params']['dim'] ?? '' ) . ': ' . $item['error'] );
	}
}
nva_ok( $all && count( $out ) === count( $reqs ), 'batch of ' . count( $reqs ) . ' reports answers' );
$human = $out[0]['data']['current']['visits'];
$bots  = $out[1]['data']['current']['visits'];
$every = $out[2]['data']['current']['visits'];
nva_ok( $human + $bots === $every && $bots >= 4, "people ($human) + automated ($bots) = all ($every)" );
$bot_report = $out[ count( $reqs ) - 4 ]['data'];
nva_ok( $bot_report['bots'] === $bots && count( $bot_report['reasons'] ) >= 4, 'bot summary lists ' . count( $bot_report['reasons'] ) . ' reasons' );
nva_ok( 1 === $out[ count( $reqs ) - 1 ]['data']['current']['visits'], 'filter by bot signal (crawler user agent) finds the crawler' );
$sessions = $out[ count( $reqs ) - 5 ]['data'];
$detail   = rest_do_request( new WP_REST_Request( 'GET', '/nemvo/v1/stats/session' ) );
$req      = new WP_REST_Request( 'GET', '/nemvo/v1/stats/session' );
$req->set_param( 'id', $sessions['rows'][0]['id'] );
$detail = rest_do_request( $req )->get_data();
nva_ok( ! empty( $detail['pages'] ) && isset( $detail['signals'] ), 'visit detail with pages and signals' );

WP_CLI::log( '== Cap relay' );
$services                = Settings::services();
$services['cap_enabled'] = 1;
$test                    = Cap::test( $services );
nva_ok( $test['ok'], 'Cap reachable with the dev site key: ' . $test['message'] );
update_option( Settings::SERVICES_OPTION, $services );
Cap::reset();
wp_set_current_user( 0 );
$pvc  = nva_id();
$resp = nva_hit( nva_pv( $pvc ), '81.236.12.1' );
nva_ok( 'cap' === ( $resp->get_data()['v'] ?? '' ), 'with Cap on, a new visit is asked to solve Cap' );
$relay = rest_do_request( new WP_REST_Request( 'POST', '/nemvo/v1/cap/challenge' ) );
$cdata = $relay->get_data();
nva_ok( 200 === $relay->get_status() && ( isset( $cdata['challenge'] ) || isset( $cdata['token'] ) ), 'WordPress relays a Cap challenge (' . implode( ',', array_keys( (array) $cdata ) ) . ')' );
nva_ok( ! Cap::verify( 'not-a-real-token' ), 'an invalid Cap token is rejected' );
$services['cap_enabled'] = 0;
update_option( Settings::SERVICES_OPTION, $services );
Cap::reset();

WP_CLI::log( '== Health' );
$checks = Health::checks();
nva_ok( count( $checks ) >= 3, 'setup checklist has ' . count( $checks ) . ' items' );

// Clean up.
foreach ( array( 'local', 'external' ) as $target ) {
	DB::drop( $target );
}
$wpdb->prefix = $saved['prefix'];
update_option( Settings::OPTION, $saved['settings'] );
update_option( Settings::SERVICES_OPTION, $saved['services'] );
if ( false === $saved['storage'] ) {
	delete_option( DB::STORAGE_OPTION );
} else {
	update_option( DB::STORAGE_OPTION, $saved['storage'] );
}
$known = get_option( DB::SCHEMA_OPTION );
foreach ( (array) $known as $sig => $v ) {
	if ( false !== strpos( (string) $sig, 'nvatest_' ) ) {
		unset( $known[ $sig ] );
	}
}
update_option( DB::SCHEMA_OPTION, $known );

WP_CLI::log( sprintf( '%d passed, %d failed', $GLOBALS['nva_pass'], $GLOBALS['nva_fail'] ) );
if ( $GLOBALS['nva_fail'] ) {
	WP_CLI::halt( 1 );
}
