<?php
/**
 * Tests for NemvoAnalytics\Network and NemvoAnalytics\Dns.
 *
 * Run inside the dev container:
 *   wp eval-file /workspace/tests/network-test.php
 *
 * Prints one line per assertion and exits non-zero when anything fails,
 * including PHP warnings/notices raised by the code under test.
 *
 * @package NemvoAnalytics
 */

defined( 'ABSPATH' ) || exit;

$nemvo_pass     = 0;
$nemvo_fail     = 0;
$nemvo_warnings = array();

set_error_handler( // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_set_error_handler
	static function ( $errno, $errstr, $file, $line ) use ( &$nemvo_warnings ) {
		$nemvo_warnings[] = $errstr . ' (' . basename( $file ) . ':' . $line . ')';
		return true;
	}
);

/**
 * Record one assertion.
 *
 * @param string $label    Description.
 * @param mixed  $actual   Actual value.
 * @param mixed  $expected Expected value.
 */
$nemvo_check = static function ( $label, $actual, $expected ) use ( &$nemvo_pass, &$nemvo_fail, &$nemvo_warnings ) {
	$warnings       = $nemvo_warnings;
	$nemvo_warnings = array();
	if ( $actual === $expected && ! $warnings ) {
		++$nemvo_pass;
		WP_CLI::line( 'ok   ' . $label );
		return;
	}
	++$nemvo_fail;
	$detail = $warnings ? ' PHP: ' . implode( '; ', $warnings ) : '';
	WP_CLI::line( 'FAIL ' . $label . ' => got ' . wp_json_encode( $actual ) . ', expected ' . wp_json_encode( $expected ) . $detail );
};

/*
 * Network::classify(): [ asn, org, hostname, type, company, provider (null = not checked) ].
 */
$nemvo_cases = array(
	// Hosting, datacenters, CDNs and VPNs.
	array( 16509, 'Amazon.com, Inc.', '', 'hosting', '', 'Amazon.com, Inc.' ),
	array( 16509, 'AMAZON-02', '', 'hosting', '', 'AMAZON' ),
	array( 15169, 'Google LLC', '', 'hosting', '', null ),
	array( 8075, 'Microsoft Corporation', '', 'hosting', '', null ),
	array( 0, 'MICROSOFT-CORP-MSN-AS-BLOCK', '', 'hosting', '', 'MICROSOFT-CORP-MSN' ),
	array( 14061, 'DigitalOcean, LLC', '', 'hosting', '', null ),
	array( 24940, 'Hetzner Online GmbH', 'static.1.2.3.4.clients.your-server.de', 'hosting', '', null ),
	array( 0, 'Glesys AB', '', 'hosting', '', null ),
	array( 9009, 'M247 Europe SRL', '', 'hosting', '', null ),
	array( 39351, '31173 Services AB', '', 'hosting', '', null ),
	array( 0, 'Packethub S.A.', '', 'hosting', '', null ),
	array( 0, 'Nordic Webhosting Solutions AB', '', 'hosting', '', null ),
	array( 13335, 'Cloudflare, Inc.', 'one.one.one.one', 'hosting', '', null ),
	array( 0, 'AS-CHOOPA', '', 'hosting', '', 'CHOOPA' ),
	array( 16591, 'Google Fiber Inc.', '', 'isp', '', null ),
	// Education and research.
	array( 1653, 'SUNET Swedish University Network', '', 'education', '', 'SUNET Swedish University Network' ),
	array( 0, 'Lunds universitet', '', 'education', '', null ),
	array( 2603, 'NORDUnet', '', 'education', '', null ),
	array( 0, 'Kungliga Tekniska Högskolan', '', 'education', '', null ),
	array( 3301, 'Telia Company AB', 'fw.ox.ac.uk', 'education', '', null ),
	array( 0, '', 'gw.cs.stanford.edu', 'education', '', '' ),
	// Government.
	array( 0, 'Region Stockholm', '', 'government', '', null ),
	array( 0, 'Västra Götalandsregionen', '', 'government', '', null ),
	array( 0, 'Stockholms kommun', '', 'government', '', null ),
	array( 0, 'Försvarsmakten', '', 'government', '', null ),
	array( 3301, 'Telia Company AB', 'fw.dwp.gov.uk', 'government', '', null ),
	array( 0, '', 'host.army.mil', 'government', '', '' ),
	array( 0, 'Ventelo Kommunikation AB', '', 'isp', '', null ),
	// Mobile.
	array( 21928, 'T-Mobile USA, Inc.', '', 'mobile', '', null ),
	array( 0, 'Hi3G Access AB', '', 'mobile', '', null ),
	array( 0, 'Hi3G Access AB', '1-2-3-4.cust.tre.se', 'mobile', '', null ),
	array( 0, 'Hi3G Access AB', 'fw.acme.se', 'business', 'acme.se', null ),
	array( 3301, 'Telia Company AB', 'm81-232-1-2.mobile.telia.com', 'mobile', '', null ),
	array( 2119, 'Telenor Norge AS', '1-2-3-4.lte.telenor.no', 'mobile', '', 'Telenor Norge AS' ),
	// Residential on ISP networks.
	array( 3301, 'Telia Company AB', '81-232-1-2-no600.tbcn.telia.com', 'residential', '', 'Telia Company AB' ),
	array( 0, 'Telenor Sverige AB', 'c-83-233-1-2.bbcust.telenor.se', 'residential', '', null ),
	array( 8473, 'Bahnhof AB', 'h-98-128-1-2.A123.priv.bahnhof.se', 'residential', '', null ),
	array( 39651, 'Com Hem AB', 'c83-233-1-2.bredband.comhem.se', 'residential', '', null ),
	array( 1257, 'Tele2 Sverige AB', 'dynamic-1-2-3-4.tele2.se', 'residential', '', null ),
	array( 7922, 'Comcast Cable Communications, LLC', 'c-73-1-2-3.hsd1.ca.comcast.net', 'residential', '', null ),
	array( 3320, 'Deutsche Telekom AG', 'p5b0a1b2c.dip0.t-ipconnect.de', 'residential', '', null ),
	array( 0, 'Elisa Oyj', 'dsl-hkibng12-1-2.dhcp.inet.fi', 'residential', '', null ),
	array( 12552, 'IP-Only Networks AB', '2a02-aa1-1-2--3.smallisp.se', 'residential', '', null ),
	array( 12552, 'IP-Only Networks AB', 'user1-2-3-4.stadsnat.se', 'residential', '', null ),
	array( 2856, 'British Telecommunications PLC', 'host81-159-23-45.range81-159.btcentralplus.com', 'residential', '', null ),
	// Business on ISP networks.
	array( 3301, 'Telia Company AB', 'fw.acme.se', 'business', 'acme.se', null ),
	array( 3301, 'TELIANET Telia Company AB', 'gw1.volvo.com', 'business', 'volvo.com', 'Telia Company AB' ),
	array( 1257, 'Tele2 Sverige AB', 'mail.example.se', 'business', 'example.se', null ),
	array( 1257, 'Tele2 Sverige AB', 'gw.acme.co.uk', 'business', 'acme.co.uk', null ),
	array( 8473, 'Bahnhof AB', 'static-1-2-3-4.business.bahnhof.se', 'business', '', null ),
	array( 8473, 'BAHNHOF-AS', 'h-98-128-1-2.A123.corp.bahnhof.se', 'business', '', 'BAHNHOF' ),
	array( 7922, 'Comcast Cable Communications, LLC', '1-2-3-4-static.hfc.comcastbusiness.net', 'business', '', null ),
	array( 3301, 'Telia Company AB', 'office.acme.se', 'business', 'acme.se', null ),
	// ISP without a usable hostname.
	array( 3301, 'Telia Company AB', '', 'isp', '', null ),
	array( 3301, '', '', 'isp', '', '' ),
	array( 1257, 'Tele2 Sverige AB', '', 'isp', '', null ),
	array( 0, 'Ownit Broadband AB', '', 'isp', '', null ),
	array( 3301, 'Telia Company AB', 'core1.tbcn.telia.com', 'isp', '', null ),
	array( 3301, 'Telia Company AB', '81.232.1.2', 'isp', '', null ),
	// Organisations that own their ASN.
	array( 0, 'Volvo Car Corporation', '', 'business', 'Volvo Car Corporation', 'Volvo Car Corporation' ),
	array( 0, 'Ericsson AB', '', 'business', 'Ericsson AB', null ),
	array( 0, 'Telefonaktiebolaget LM Ericsson', 'gw.ericsson.com', 'business', 'Telefonaktiebolaget LM Ericsson', null ),
	array( 0, 'Axis Communications AB', 'gw.axis.com', 'business', 'Axis Communications AB', null ),
	array( 0, 'Axis Communications AB', 'fw.customer.se', 'business', 'customer.se', null ),
	array( 0, 'Skellefteå Kraft AB', 'dhcp-1-2-3-4.skekraft.net', 'residential', '', null ),
	// Tokenisation must not produce false hits.
	array( 3301, 'Telia Company AB', 'mail.resources-acme.se', 'business', 'resources-acme.se', null ),
	array( 3301, 'Telia Company AB', 'gw.abb.com', 'business', 'abb.com', null ),
	array( 3301, 'Telia Company AB', 'homeoffice.acme.se', 'business', 'acme.se', null ),
	array( 3301, 'Telia Company AB', 'vpn.client.se', 'business', 'client.se', null ),
	array( 3301, 'Telia Company AB', 'mx.cablecom-partner.se', 'business', 'cablecom-partner.se', null ),
	// Nothing or little known.
	array( 0, '', '', '', '', '' ),
	array( 0, '', 'dynamic-1-2-3-4.example.net', 'residential', '', '' ),
	array( 0, '', 'fw.acme.se', '', '', '' ),
	array( 0, '', 'not a hostname', '', '', '' ),
	array( 0, '  Telia   Company AB  ', '', 'isp', '', 'Telia Company AB' ),
);

foreach ( $nemvo_cases as $nemvo_case ) {
	list( $nemvo_asn, $nemvo_org, $nemvo_host, $nemvo_type, $nemvo_company, $nemvo_provider ) = $nemvo_case;

	$nemvo_result   = \NemvoAnalytics\Network::classify( $nemvo_asn, $nemvo_org, $nemvo_host );
	$nemvo_actual   = array( $nemvo_result['type'], $nemvo_result['company'] );
	$nemvo_expected = array( $nemvo_type, $nemvo_company );
	if ( null !== $nemvo_provider ) {
		$nemvo_actual[]   = $nemvo_result['provider'];
		$nemvo_expected[] = $nemvo_provider;
	}
	$nemvo_check( sprintf( 'classify(%d, "%s", "%s")', $nemvo_asn, $nemvo_org, $nemvo_host ), $nemvo_actual, $nemvo_expected );
}

// Result shape.
$nemvo_result = \NemvoAnalytics\Network::classify( 3301, 'Telia Company AB', 'fw.acme.se' );
$nemvo_check( 'classify() returns exactly type/company/provider', array_keys( $nemvo_result ), array( 'type', 'company', 'provider' ) );
$nemvo_result = \NemvoAnalytics\Network::classify( 0, str_repeat( 'Very Long Name ', 20 ), '' );
$nemvo_check( 'provider and company are capped at 100 characters', array( mb_strlen( $nemvo_result['provider'] ), mb_strlen( $nemvo_result['company'] ) ), array( 100, 100 ) );

/*
 * Network::needs_hostname().
 */
$nemvo_needs = array(
	array( 3301, 'Telia Company AB', true ),
	array( 0, 'Hi3G Access AB', true ),
	array( 0, '', true ),
	array( 0, 'Axis Communications AB', true ),
	array( 16509, 'Amazon.com, Inc.', false ),
	array( 16509, '', false ),
	array( 1653, 'SUNET Swedish University Network', false ),
	array( 0, 'Region Stockholm', false ),
	array( 0, 'Volvo Car Corporation', false ),
);
foreach ( $nemvo_needs as $nemvo_case ) {
	$nemvo_check( sprintf( 'needs_hostname(%d, "%s")', $nemvo_case[0], $nemvo_case[1] ), \NemvoAnalytics\Network::needs_hostname( $nemvo_case[0], $nemvo_case[1] ), $nemvo_case[2] );
}

/*
 * Network::registrable_domain().
 */
$nemvo_domains = array(
	'fw.acme.co.uk'                  => 'acme.co.uk',
	'gw.volvo.com'                   => 'volvo.com',
	'c-83-233-1-2.bbcust.telenor.se' => 'telenor.se',
	'a.b.example.com.au'             => 'example.com.au',
	'host.example.co.jp'             => 'example.co.jp',
	'x.ox.ac.uk'                     => 'ox.ac.uk',
	'www.example.com.br'             => 'example.com.br',
	'mail.example.co.nz'             => 'example.co.nz',
	'a.b.c.d.example.org'            => 'example.org',
	'FW.ACME.SE.'                    => 'acme.se',
	'acme.se'                        => 'acme.se',
	'co.uk'                          => '',
	'83.233.1.2'                     => '',
	'2001:db8::1'                    => '',
	'localhost'                      => '',
	'bad host.se'                    => '',
	'-bad.example.se'                => '',
	''                               => '',
);
foreach ( $nemvo_domains as $nemvo_host => $nemvo_domain ) {
	$nemvo_check( sprintf( 'registrable_domain("%s")', $nemvo_host ), \NemvoAnalytics\Network::registrable_domain( $nemvo_host ), $nemvo_domain );
}

$nemvo_check( 'types() lists the seven types', array_keys( \NemvoAnalytics\Network::types() ), array( 'business', 'residential', 'isp', 'mobile', 'hosting', 'education', 'government' ) );

/*
 * Dns: packet parsing (private methods, through reflection).
 */
$nemvo_parse = new ReflectionMethod( '\NemvoAnalytics\Dns', 'parse' );
$nemvo_parse->setAccessible( true );

$nemvo_encode = static function ( $name ) {
	$out = '';
	foreach ( explode( '.', $name ) as $label ) {
		$out .= chr( strlen( $label ) ) . $label;
	}
	return $out . "\0";
};

// Classless reverse delegation: PTR name -> CNAME -> PTR, with compression pointers.
$nemvo_header = pack( 'nnnnnn', 0x1234, 0x8180, 1, 2, 0, 0 );
$nemvo_q      = $nemvo_encode( '4.3.2.1.in-addr.arpa' ) . pack( 'nn', 12, 1 );
$nemvo_cname  = "\x014\x040-26\xC0\x0E"; // 4.0-26 + pointer to "3.2.1.in-addr.arpa" (offset 14).
$nemvo_a1     = "\xC0\x0C" . pack( 'nnNn', 5, 1, 300, strlen( $nemvo_cname ) ) . $nemvo_cname;
$nemvo_target = strlen( $nemvo_header . $nemvo_q ) + 12;
$nemvo_ptr    = $nemvo_encode( 'host.example.com' );
$nemvo_a2     = pack( 'n', 0xC000 | $nemvo_target ) . pack( 'nnNn', 12, 1, 300, strlen( $nemvo_ptr ) ) . $nemvo_ptr;
$nemvo_msg    = $nemvo_header . $nemvo_q . $nemvo_a1 . $nemvo_a2;
$nemvo_check( 'parse() follows a CNAME and compression pointers', $nemvo_parse->invoke( null, $nemvo_msg, 0x1234, '4.3.2.1.in-addr.arpa', 12 ), array( 'host.example.com' ) );
$nemvo_check( 'parse() rejects a wrong id', $nemvo_parse->invoke( null, $nemvo_msg, 0x4321, '4.3.2.1.in-addr.arpa', 12 ), null );
$nemvo_check( 'parse() rejects a different question', $nemvo_parse->invoke( null, $nemvo_msg, 0x1234, '5.3.2.1.in-addr.arpa', 12 ), null );
$nemvo_check( 'parse() survives every truncation of a valid packet', (static function () use ( $nemvo_parse, $nemvo_msg ) {
	for ( $i = 0; $i < strlen( $nemvo_msg ); $i++ ) {
		$nemvo_parse->invoke( null, substr( $nemvo_msg, 0, $i ), 0x1234, '4.3.2.1.in-addr.arpa', 12 );
	}
	return true;
})(), true );
$nemvo_check( 'parse() returns no records for NXDOMAIN', $nemvo_parse->invoke( null, pack( 'nnnnnn', 7, 0x8183, 1, 0, 0, 0 ) . $nemvo_q, 7, '4.3.2.1.in-addr.arpa', 12 ), array() );
$nemvo_check( 'parse() asks the next server on SERVFAIL', $nemvo_parse->invoke( null, pack( 'nnnnnn', 7, 0x8182, 1, 0, 0, 0 ) . $nemvo_q, 7, '4.3.2.1.in-addr.arpa', 12 ), null );
$nemvo_check( 'parse() stops a compression pointer loop', $nemvo_parse->invoke( null, pack( 'nnnnnn', 7, 0x8180, 1, 1, 0, 0 ) . "\xC0\x0C", 7, '4.3.2.1.in-addr.arpa', 12 ), null );
$nemvo_check( 'parse() handles empty input', $nemvo_parse->invoke( null, '', 7, 'x.example', 1 ), null );
$nemvo_check( 'parse() survives random garbage', (static function () use ( $nemvo_parse ) {
	for ( $i = 0; $i < 2000; $i++ ) {
		$garbage = pack( 'n', 9 ) . "\x81\x80\x00\x01" . random_bytes( wp_rand( 1, 120 ) );
		$result  = $nemvo_parse->invoke( null, $garbage, 9, 'x.example', 1 );
		if ( null !== $result && ! is_array( $result ) ) {
			return false;
		}
	}
	return true;
})(), true );

/*
 * Dns::reverse(): live lookups through the container's resolver.
 */
$nemvo_method = new ReflectionMethod( '\NemvoAnalytics\Dns', 'servers' );
$nemvo_method->setAccessible( true );
$nemvo_servers = $nemvo_method->invoke( null );
$nemvo_check( sprintf( 'nameservers from resolv.conf: %s', implode( ', ', $nemvo_servers ) ), count( $nemvo_servers ) >= 1 && count( $nemvo_servers ) <= 2, true );

// Worst case: 2 queries x timeout x nameservers, plus scheduling slack.
$nemvo_budget = static function ( $timeout, $servers ) {
	return 2 * $timeout * $servers + 0.1;
};

// Default timeout: whatever the state of the resolver's cache, a lookup is bounded.
foreach ( array( '8.8.8.8', '9.9.9.10', '208.67.222.222', '149.112.112.112', '2001:4860:4860::8844' ) as $nemvo_ip ) {
	$nemvo_start  = microtime( true );
	$nemvo_result = \NemvoAnalytics\Dns::reverse( $nemvo_ip );
	$nemvo_time   = microtime( true ) - $nemvo_start;
	$nemvo_check( sprintf( 'reverse("%s") = "%s" within the time budget (%.3fs of %.2fs)', $nemvo_ip, $nemvo_result, $nemvo_time, $nemvo_budget( 0.4, count( $nemvo_servers ) ) ), $nemvo_time < $nemvo_budget( 0.4, count( $nemvo_servers ) ), true );
}

// Correctness, with a timeout that tolerates a cold resolver cache (Docker's
// resolver forwards to the host and can need more than 0.4s for a new name).
$nemvo_check( 'reverse("8.8.8.8", 2.0)', \NemvoAnalytics\Dns::reverse( '8.8.8.8', 2.0 ), 'dns.google' );
$nemvo_check( 'reverse("1.1.1.1", 2.0)', \NemvoAnalytics\Dns::reverse( '1.1.1.1', 2.0 ), 'one.one.one.one' );
$nemvo_check( 'reverse("::ffff:8.8.4.4", 2.0) (IPv4-mapped)', \NemvoAnalytics\Dns::reverse( '::ffff:8.8.4.4', 2.0 ), 'dns.google' );
$nemvo_check( 'reverse("8.8.8.8") with a warm cache and the default timeout', \NemvoAnalytics\Dns::reverse( '8.8.8.8' ), 'dns.google' );
$nemvo_check( 'reverse("1.1.1.1") with a warm cache and the default timeout', \NemvoAnalytics\Dns::reverse( '1.1.1.1' ), 'one.one.one.one' );

// IPv6: the PTR leg always; the forward-confirmed lookup needs a resolver that
// returns AAAA records (Docker's embedded resolver drops them without IPv6).
$nemvo_query = new ReflectionMethod( '\NemvoAnalytics\Dns', 'query' );
$nemvo_query->setAccessible( true );
$nemvo_ptr = new ReflectionMethod( '\NemvoAnalytics\Dns', 'ptr_name' );
$nemvo_ptr->setAccessible( true );
$nemvo_name = $nemvo_ptr->invoke( null, inet_pton( '2001:4860:4860::8888' ) );
$nemvo_check( 'ip6.arpa name for 2001:4860:4860::8888', $nemvo_name, '8.8.8.8.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.6.8.4.0.6.8.4.1.0.0.2.ip6.arpa' );
$nemvo_check( 'PTR query for 2001:4860:4860::8888', $nemvo_query->invoke( null, $nemvo_servers, $nemvo_name, 12, 2.0 ), array( 'dns.google' ) );
if ( $nemvo_query->invoke( null, $nemvo_servers, 'dns.google', 28, 2.0 ) ) {
	$nemvo_check( 'reverse("2001:4860:4860::8888", 2.0)', \NemvoAnalytics\Dns::reverse( '2001:4860:4860::8888', 2.0 ), 'dns.google' );
} else {
	WP_CLI::line( 'skip reverse("2001:4860:4860::8888", 2.0): the resolver returns no AAAA records' );
}

$nemvo_check( 'reverse("192.168.1.1") (private)', \NemvoAnalytics\Dns::reverse( '192.168.1.1' ), '' );
$nemvo_check( 'reverse("100.64.1.1") (carrier-grade NAT)', \NemvoAnalytics\Dns::reverse( '100.64.1.1' ), '' );
$nemvo_check( 'reverse("::ffff:192.168.1.1") (IPv4-mapped private)', \NemvoAnalytics\Dns::reverse( '::ffff:192.168.1.1' ), '' );
$nemvo_check( 'reverse("10.0.0.1") (private)', \NemvoAnalytics\Dns::reverse( '10.0.0.1' ), '' );
$nemvo_check( 'reverse("127.0.0.1") (loopback)', \NemvoAnalytics\Dns::reverse( '127.0.0.1' ), '' );
$nemvo_check( 'reverse("fe80::1") (link-local)', \NemvoAnalytics\Dns::reverse( 'fe80::1' ), '' );
$nemvo_check( 'reverse("not-an-ip")', \NemvoAnalytics\Dns::reverse( 'not-an-ip' ), '' );

// A nameserver that never answers: the lookup gives up after one timeout per server.
$nemvo_blackhole = static function () {
	return array( '192.0.2.1' );
};
add_filter( 'nemvo_analytics_dns_servers', $nemvo_blackhole );
$nemvo_start  = microtime( true );
$nemvo_result = \NemvoAnalytics\Dns::reverse( '8.8.8.8', 0.3 );
$nemvo_time   = microtime( true ) - $nemvo_start;
remove_filter( 'nemvo_analytics_dns_servers', $nemvo_blackhole );
$nemvo_check( 'reverse() with an unresponsive nameserver returns ""', $nemvo_result, '' );
$nemvo_check( sprintf( 'reverse() with an unresponsive nameserver stops in time (%.3fs)', $nemvo_time ), $nemvo_time >= 0.25 && $nemvo_time < $nemvo_budget( 0.3, 1 ), true );

restore_error_handler();

WP_CLI::line( sprintf( '%d passed, %d failed', $nemvo_pass, $nemvo_fail ) );
if ( $nemvo_fail ) {
	WP_CLI::halt( 1 );
}
