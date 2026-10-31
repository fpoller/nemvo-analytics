<?php
/**
 * Demo traffic generator.
 *
 * @package NemvoAnalytics
 */

namespace NemvoAnalytics;

defined( 'ABSPATH' ) || exit;

/**
 * Writes plausible sessions, pageviews and events for a Nordic IT consulting
 * website straight into the statistics tables (`wp nemvo-analytics seed`).
 *
 * Traffic sources go through Referrer::classify() exactly like real hits, so
 * channels and sources look the same as collected data.
 */
class Seeder {

	/**
	 * Connection.
	 *
	 * @var \wpdb
	 */
	private $db;

	/**
	 * This site's host.
	 *
	 * @var string
	 */
	private $host;

	/**
	 * Next free id per table.
	 *
	 * @var int[]
	 */
	private $ids = array();

	/**
	 * Post id per path (0 when the page does not exist in WordPress).
	 *
	 * @var int[]
	 */
	private $post_ids = array();

	/**
	 * State of the random generator.
	 *
	 * @var int
	 */
	private $state = 1;

	/**
	 * Rows waiting to be written.
	 *
	 * @var array
	 */
	private $batch = array();

	/**
	 * Constructor.
	 *
	 * @param int $seed Random seed.
	 */
	public function __construct( $seed ) {
		$this->state = ( (int) $seed ^ 0x9e3779b9 ) & 0xffffffff;
		$this->state = $this->state ? $this->state : 1;
		$this->db   = DB::conn();
		$this->host = (string) wp_parse_url( home_url(), PHP_URL_HOST );
	}

	/**
	 * Site pages: path => [title, content type, tag, landing weight, average engaged seconds].
	 *
	 * @return array
	 */
	public static function pages() {
		return array(
			'/'                                          => array( 'Nemvo – IT consulting for the Nordics', 'home', 'home', 10, 40 ),
			'/services/'                                 => array( 'Services', 'page', 'svc', 6, 45 ),
			'/services/cloud-migration/'                 => array( 'Cloud migration', 'page', 'svc', 9, 95 ),
			'/services/cybersecurity/'                   => array( 'Cybersecurity & NIS2', 'page', 'svc', 9, 100 ),
			'/services/devops-platform-engineering/'     => array( 'DevOps & platform engineering', 'page', 'svc', 7, 105 ),
			'/services/managed-it/'                      => array( 'Managed IT services', 'page', 'svc', 6, 80 ),
			'/services/data-and-ai/'                     => array( 'Data & AI', 'page', 'svc', 7, 110 ),
			'/case-studies/'                             => array( 'Case studies', 'page', 'case', 5, 45 ),
			'/case-studies/fintech-kubernetes-platform/' => array( 'A Kubernetes platform for a Nordic fintech', 'page', 'case', 4, 160 ),
			'/case-studies/retail-zero-trust/'           => array( 'Zero trust for 400 retail stores', 'page', 'case', 3, 150 ),
			'/case-studies/municipality-m365/'           => array( 'Moving a municipality to Microsoft 365', 'page', 'case', 2, 140 ),
			'/blog/'                                     => array( 'Blog', 'page', 'blog', 3, 30 ),
			'/blog/nis2-checklist-for-it-leaders/'       => array( 'The NIS2 checklist for IT leaders', 'post', 'blog', 9, 230 ),
			'/blog/azure-landing-zones-explained/'       => array( 'Azure landing zones, explained', 'post', 'blog', 7, 210 ),
			'/blog/kubernetes-cost-optimization/'        => array( 'Cutting Kubernetes costs by 40%', 'post', 'blog', 5, 190 ),
			'/blog/copilot-rollout-lessons/'             => array( 'What we learned rolling out Copilot to 2,000 users', 'post', 'blog', 6, 175 ),
			'/blog/terraform-vs-bicep/'                  => array( 'Terraform vs Bicep in 2026', 'post', 'blog', 4, 200 ),
			'/about/'                                    => array( 'About us', 'page', 'about', 5, 50 ),
			'/about/team/'                               => array( 'Our team', 'page', 'about', 3, 65 ),
			'/careers/'                                  => array( 'Careers', 'page', 'career', 5, 55 ),
			'/careers/senior-devops-engineer/'           => array( 'Senior DevOps engineer', 'page', 'career', 3, 120 ),
			'/careers/cloud-architect/'                  => array( 'Cloud architect', 'page', 'career', 2, 115 ),
			'/contact/'                                  => array( 'Contact us', 'page', 'contact', 8, 70 ),
			'/contact/thank-you/'                        => array( 'Thank you', 'page', 'thanks', 0, 12 ),
			'/book-a-meeting/'                           => array( 'Book a meeting', 'page', 'book', 4, 60 ),
			'/resources/nis2-whitepaper/'                => array( 'Whitepaper: NIS2 readiness', 'page', 'wp', 4, 85 ),
			'/privacy-policy/'                           => array( 'Privacy policy', 'page', 'legal', 1, 35 ),
		);
	}

	/**
	 * Countries: code => [weight, hours ahead of the site, languages, cities (city => [region, weight])].
	 *
	 * @return array
	 */
	private static function countries() {
		return array(
			'SE' => array( 38, 0, array( 'sv-SE' => 76, 'en-US' => 18, 'en-GB' => 6 ), array( 'Stockholm' => array( 'Stockholm County', 48 ), 'Gothenburg' => array( 'Västra Götaland County', 18 ), 'Malmö' => array( 'Skåne County', 12 ), 'Uppsala' => array( 'Uppsala County', 6 ), 'Linköping' => array( 'Östergötland County', 5 ), 'Västerås' => array( 'Västmanland County', 4 ), 'Örebro' => array( 'Örebro County', 4 ), 'Umeå' => array( 'Västerbotten County', 3 ) ) ),
			'NL' => array( 10, 0, array( 'nl-NL' => 68, 'en-US' => 32 ), array( 'Amsterdam' => array( 'North Holland', 45 ), 'Rotterdam' => array( 'South Holland', 18 ), 'Utrecht' => array( 'Utrecht', 15 ), 'Eindhoven' => array( 'North Brabant', 12 ), 'The Hague' => array( 'South Holland', 10 ) ) ),
			'US' => array( 9, -7, array( 'en-US' => 100 ), array( 'New York' => array( 'New York', 30 ), 'San Francisco' => array( 'California', 22 ), 'Seattle' => array( 'Washington', 18 ), 'Austin' => array( 'Texas', 15 ), 'Chicago' => array( 'Illinois', 15 ) ) ),
			'DE' => array( 8, 0, array( 'de-DE' => 80, 'en-US' => 20 ), array( 'Berlin' => array( 'Berlin', 35 ), 'Munich' => array( 'Bavaria', 25 ), 'Hamburg' => array( 'Hamburg', 22 ), 'Frankfurt am Main' => array( 'Hesse', 18 ) ) ),
			'NO' => array( 6, 0, array( 'nb-NO' => 80, 'en-US' => 20 ), array( 'Oslo' => array( 'Oslo', 70 ), 'Bergen' => array( 'Vestland', 30 ) ) ),
			'GB' => array( 6, -1, array( 'en-GB' => 100 ), array( 'London' => array( 'England', 70 ), 'Manchester' => array( 'England', 18 ), 'Edinburgh' => array( 'Scotland', 12 ) ) ),
			'DK' => array( 5, 0, array( 'da-DK' => 78, 'en-US' => 22 ), array( 'Copenhagen' => array( 'Capital Region', 75 ), 'Aarhus' => array( 'Central Jutland', 25 ) ) ),
			'FI' => array( 4, 1, array( 'fi-FI' => 70, 'sv-FI' => 10, 'en-US' => 20 ), array( 'Helsinki' => array( 'Uusimaa', 60 ), 'Espoo' => array( 'Uusimaa', 20 ), 'Tampere' => array( 'Pirkanmaa', 20 ) ) ),
			'IN' => array( 3, 4, array( 'en-IN' => 100 ), array( 'Bengaluru' => array( 'Karnataka', 60 ), 'Pune' => array( 'Maharashtra', 40 ) ) ),
			'PL' => array( 2, 0, array( 'pl-PL' => 70, 'en-US' => 30 ), array( 'Warsaw' => array( 'Mazovia', 60 ), 'Kraków' => array( 'Lesser Poland', 40 ) ) ),
			'FR' => array( 2, 0, array( 'fr-FR' => 85, 'en-US' => 15 ), array( 'Paris' => array( 'Île-de-France', 100 ) ) ),
			'ES' => array( 1.4, 0, array( 'es-ES' => 80, 'en-US' => 20 ), array( 'Madrid' => array( 'Madrid', 55 ), 'Barcelona' => array( 'Catalonia', 45 ) ) ),
			'IE' => array( 1.2, -1, array( 'en-IE' => 100 ), array( 'Dublin' => array( 'Leinster', 100 ) ) ),
			'CA' => array( 1, -6, array( 'en-CA' => 80, 'fr-CA' => 20 ), array( 'Toronto' => array( 'Ontario', 60 ), 'Montreal' => array( 'Quebec', 40 ) ) ),
			'CH' => array( 1, 0, array( 'de-CH' => 60, 'fr-CH' => 20, 'en-US' => 20 ), array( 'Zurich' => array( 'Zurich', 100 ) ) ),
			'BE' => array( 1, 0, array( 'nl-BE' => 55, 'fr-BE' => 45 ), array( 'Brussels' => array( 'Brussels Capital', 100 ) ) ),
			'EE' => array( 0.8, 1, array( 'et-EE' => 60, 'en-US' => 40 ), array( 'Tallinn' => array( 'Harju County', 100 ) ) ),
			'LT' => array( 0.5, 1, array( 'lt-LT' => 60, 'en-US' => 40 ), array( 'Vilnius' => array( 'Vilnius County', 100 ) ) ),
			'AU' => array( 0.6, 9, array( 'en-AU' => 100 ), array( 'Sydney' => array( 'New South Wales', 100 ) ) ),
			'SG' => array( 0.5, 6, array( 'en-SG' => 100 ), array( 'Singapore' => array( '', 100 ) ) ),
			'BR' => array( 0.5, -4, array( 'pt-BR' => 100 ), array( 'São Paulo' => array( 'São Paulo', 100 ) ) ),
			'JP' => array( 0.3, 8, array( 'ja-JP' => 100 ), array( 'Tokyo' => array( 'Tokyo', 100 ) ) ),
			'ZA' => array( 0.3, 1, array( 'en-ZA' => 100 ), array( 'Cape Town' => array( 'Western Cape', 100 ) ) ),
			''   => array( 0.8, 0, array( 'en-US' => 100 ), array() ),
		);
	}

	/**
	 * Generate and store the traffic.
	 *
	 * @param int           $days    Days back from today.
	 * @param int           $per_day Average visits on a weekday.
	 * @param callable|null $tick    Called after each day.
	 * @return array{sessions:int,pageviews:int,events:int}
	 */
	public function run( $days, $per_day, $tick = null ) {
		$now    = current_time( 'timestamp' ); // phpcs:ignore WordPress.DateTime.CurrentTimeTimestamp.Requested
		$today  = strtotime( gmdate( 'Y-m-d', $now ) . ' 00:00:00 UTC' );
		$totals = array(
			'sessions'  => 0,
			'pageviews' => 0,
			'events'    => 0,
		);
		foreach ( array_keys( self::pages() ) as $path ) {
			$this->post_ids[ $path ] = (int) url_to_postid( home_url( $path ) );
		}
		foreach ( array( 'sessions', 'pageviews', 'events' ) as $table ) {
			$this->ids[ $table ] = 1 + (int) $this->db->get_var( 'SELECT COALESCE(MAX(id), 0) FROM `' . DB::table( $table ) . '`' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		}

		$weekday = array( 1 => 1.04, 2 => 1.1, 3 => 1.08, 4 => 1.0, 5 => 0.84, 6 => 0.36, 7 => 0.4 );
		for ( $d = $days - 1; $d >= 0; $d-- ) {
			$day      = $today - $d * DAY_IN_SECONDS;
			$progress = $days > 1 ? ( $days - 1 - $d ) / ( $days - 1 ) : 1;
			$mix      = array(
				'search'      => 38,
				'direct'      => 24,
				'social'      => 10,
				'referral'    => 7,
				'ai'          => 5 * ( 0.5 + $progress ),
				'email'       => 4,
				'paid_search' => 6,
				'paid_social' => 3,
				'campaign'    => 1,
			);
			$volume = $per_day * $weekday[ (int) gmdate( 'N', $day ) ] * ( 0.72 + 0.36 * $progress ) * max( 0.6, 1 + $this->gauss() * 0.08 );
			// A newsletter goes out every other Tuesday, and one LinkedIn post took off.
			if ( 2 === (int) gmdate( 'N', $day ) && 0 === intdiv( $d, 7 ) % 2 ) {
				$mix['email'] *= 7;
				$volume       *= 1.25;
			}
			if ( 23 === $d ) {
				$mix['social'] *= 6;
				$volume        *= 1.5;
			}

			$this->batch = array(
				'sessions'  => array(),
				'pageviews' => array(),
				'events'    => array(),
			);
			$pool        = array();
			$count       = (int) round( $volume );
			for ( $i = 0; $i < $count; $i++ ) {
				$this->session( $day, $now, $mix, $pool );
			}
			foreach ( $totals as $key => $n ) {
				$totals[ $key ] = $n + count( $this->batch[ $key ] );
			}
			$this->write();
			if ( $tick ) {
				call_user_func( $tick );
			}
		}
		return $totals;
	}

	/**
	 * One visit.
	 *
	 * @param int    $day  Local midnight (as a UTC timestamp).
	 * @param int    $now  Local now (as a UTC timestamp).
	 * @param array  $mix  Channel weights.
	 * @param array  $pool Visitor ids seen today (for returning visitors).
	 */
	private function session( $day, $now, array $mix, array &$pool ) {
		if ( $this->rand( 1, 1000 ) <= 80 ) {
			$this->bot_session( $day, $now );
			return;
		}
		$countries = self::countries();
		$country   = (string) $this->pick(
			array_map(
				static function ( $c ) {
					return $c[0];
				},
				$countries
			)
		);
		$info      = $countries[ $country ];

		$weekend = (int) gmdate( 'N', $day ) >= 6;
		$hour    = (int) $this->pick( $weekend ? array( 1, 0.6, 0.4, 0.3, 0.3, 0.5, 1, 1.6, 2.4, 3.2, 3.8, 4, 3.8, 3.6, 3.6, 3.4, 3.2, 3.2, 3.4, 3.8, 4, 3.6, 2.6, 1.6 ) : array( 0.4, 0.25, 0.2, 0.2, 0.25, 0.5, 1.2, 2.6, 4.6, 6, 6.2, 5.6, 4.6, 5.2, 5.8, 5.4, 4.6, 3.6, 2.8, 2.6, 2.4, 2, 1.3, 0.8 ) );
		$hour    = ( $hour - $info[1] + 24 ) % 24;
		$start   = $day + $hour * HOUR_IN_SECONDS + $this->rand( 0, HOUR_IN_SECONDS - 1 );
		if ( $start > $now ) {
			return;
		}

		$channel = (string) $this->pick( $mix );
		list( $referrer, $params ) = $this->traffic( $channel, $country );
		$source = Referrer::classify( $referrer, $params, $this->host );

		$city      = $info[3] ? (string) $this->pick(
			array_map(
				static function ( $c ) {
					return $c[1];
				},
				$info[3]
			)
		) : '';
		$region    = $city ? $info[3][ $city ][0] : '';
		$precision = Settings::get( 'geo_precision' );
		if ( 'city' !== $precision ) {
			$city = '';
		}
		if ( 'country' === $precision ) {
			$region = '';
		}

		$device = (string) $this->pick( 'social' === $channel || 'paid_social' === $channel ? array( 'desktop' => 44, 'mobile' => 50, 'tablet' => 6 ) : array( 'desktop' => 63, 'mobile' => 31, 'tablet' => 6 ) );
		$os     = (string) $this->pick(
			array(
				'desktop' => array( 'Windows' => 55, 'macOS' => 35, 'Linux' => 8, 'ChromeOS' => 2 ),
				'mobile'  => array( 'iOS' => 56, 'Android' => 44 ),
				'tablet'  => array( 'iPadOS' => 72, 'Android' => 28 ),
			)[ $device ]
		);
		$browsers = array(
			'Windows'  => array( 'Chrome' => 50, 'Edge' => 38, 'Firefox' => 10, 'Opera' => 2 ),
			'macOS'    => array( 'Chrome' => 46, 'Safari' => 40, 'Firefox' => 8, 'Edge' => 6 ),
			'Linux'    => array( 'Firefox' => 50, 'Chrome' => 45, 'Vivaldi' => 5 ),
			'ChromeOS' => array( 'Chrome' => 100 ),
			'iOS'      => array( 'Safari' => 76, 'Chrome' => 16, 'Safari (in-app)' => 8 ),
			'iPadOS'   => array( 'Safari' => 85, 'Chrome' => 15 ),
			'Android'  => array( 'Chrome' => 76, 'Samsung Internet' => 16, 'Firefox' => 5, 'Edge' => 3 ),
		)[ $os ];
		$browser = (string) $this->pick( $browsers );
		if ( 'mobile' === $device && 'LinkedIn' === $source['source'] && $this->rand( 1, 100 ) <= 55 ) {
			$browser = 'LinkedIn app';
		}
		$screen = (string) $this->pick(
			array(
				'desktop' => array( 'lg' => 34, 'xl' => 41, 'xxl' => 25 ),
				'mobile'  => array( 'xs' => 95, 'sm' => 5 ),
				'tablet'  => array( 'md' => 70, 'lg' => 30 ),
			)[ $device ]
		);

		$office = ! $weekend && ( $hour + $info[1] + 24 ) % 24 >= 8 && ( $hour + $info[1] + 24 ) % 24 < 18;
		$net    = $this->human_network( $country, $device, $office );
		if ( isset( $net['country'] ) ) {
			// A visit from a prospect's office happens where the office is.
			$country = $net['country'];
			$info    = $countries[ $country ];
			$cities  = array_keys( $info[3] );
			$city    = 'city' === $precision ? (string) reset( $cities ) : '';
			$region  = 'country' === $precision || ! $cities ? '' : $info[3][ reset( $cities ) ][0];
		}

		$visitor = $pool && $this->rand( 1, 100 ) <= 12 ? $pool[ array_rand( $pool ) ] : $this->hex();
		$pool[]  = $visitor;
		$sid     = $this->ids['sessions']++;

		// The pages of the visit.
		$pages    = self::pages();
		$bounce   = array( 'direct' => 0.42, 'search' => 0.48, 'social' => 0.62, 'referral' => 0.4, 'ai' => 0.3, 'email' => 0.45, 'paid_search' => 0.55, 'paid_social' => 0.6, 'campaign' => 0.4 )[ $channel ];
		$length   = $this->unit() < $bounce ? 1 : 2 + $this->geometric( 0.42, 9 );
		$path     = $this->rand( 1, 1000 ) <= 12 && 'direct' !== $channel ? $this->pick_404() : $this->landing( $channel );
		$t        = $start;
		$prev     = '';
		$total_ms = 0;
		$events   = 0;
		$views    = 0;
		$last_end = $start;
		$exit     = $path;
		$is_short = 1 === $length;

		for ( $step = 0; $step < $length && $t <= $now; $step++ ) {
			$is_404 = ! isset( $pages[ $path ] ) && 0 !== strpos( $path, '?' );
			$search = 0 === strpos( $path, '?' ) ? substr( $path, 1 ) : '';
			$real   = '' !== $search ? '/' : $path;
			$page   = $is_404 ? array( 'Page not found', '404', '404', 0, 8 ) : ( '' !== $search ? array( 'Search results for “' . $search . '”', 'search', 'search', 0, 20 ) : $pages[ $path ] );

			if ( $is_short && $this->rand( 1, 100 ) <= 72 ) {
				// Most single-page visits leave within seconds.
				$ms = $this->rand( 0, 9000 );
			} else {
				$ms = $this->rand( 1, 100 ) <= 8 ? 0 : (int) ( $page[4] * 1000 * exp( $this->gauss() * 0.75 - 0.28 ) );
			}
			$ms = min( $ms, 1800000 );
			$scroll = $ms ? max( 5, min( 100, (int) round( 15 + 85 * ( 1 - exp( -$ms / 1000 / $page[4] * 1.2 ) ) + $this->gauss() * 10 ) ) ) : 0;
			$pv     = $this->hex();

			$this->batch['pageviews'][] = array( $this->ids['pageviews']++, $sid, $pv, $visitor, gmdate( 'Y-m-d H:i:s', $t ), $real, $page[0], $this->post_ids[ $real ] ?? 0, $page[1], $prev, $ms, $scroll, 0 === $step ? 1 : 0, 0, $is_404 ? 404 : 200 );
			$total_ms += $ms;
			$views++;
			$last_end = $t + (int) ( $ms / 1000 );
			$exit     = $real;

			if ( $is_404 ) {
				$this->event( $sid, $pv, $visitor, $t, '404', $path, '' === $prev ? Referrer::host_of( $referrer ) : $prev, $path );
			}
			if ( '' !== $search ) {
				$this->event( $sid, $pv, $visitor, $t, 'Site search', $search, '', '/' );
			}
			$submitted = false;
			foreach ( $this->page_events( $path, $page[2] ) as $event ) {
				$this->event( $sid, $pv, $visitor, $t + (int) ( $ms / 1000 * 0.8 ), $event[0], $event[1], '', $real );
				$submitted = $submitted || 'Form submit' === $event[0];
				$events++;
			}

			$prev = $real;
			$t   += (int) ( $ms / 1000 ) + $this->rand( 2, 25 );
			if ( $submitted && '/contact/' === $path ) {
				$path   = '/contact/thank-you/';
				$length = max( $length, $step + 2 );
				continue;
			}
			$path = $this->rand( 1, 100 ) <= 3 ? '?' . $this->pick( array( 'kubernetes' => 8, 'azure' => 9, 'nis2' => 10, 'pricing' => 6, 'careers' => 4, 'gdpr' => 3, 'copilot' => 5, 'security audit' => 4, 'terraform' => 3, 'support' => 3 ) ) : $this->next_page( $path );
		}

		$duration = (int) round( $total_ms / 1000 );
		$flags    = 0;
		if ( $this->rand( 1, 100 ) <= ( $views > 1 ? 92 : 55 ) ) {
			$flags |= Bot::INTERACTION;
		} elseif ( $last_end < $now - 3600 ) {
			$flags |= Bot::PASSIVE;
		}
		if ( $this->rand( 1, 100 ) <= 86 ) {
			$flags |= Bot::POW;
		}
		if ( 'hosting' === $net['network'] ) {
			$flags |= Bot::DATACENTER;
		}
		$this->batch['sessions'][] = array(
			$sid,
			$visitor,
			gmdate( 'Y-m-d H:i:s', $start ),
			gmdate( 'Y-m-d H:i:s', min( $now, $last_end + $this->rand( 1, 5 ) ) ),
			$duration,
			$views,
			$events,
			1 === $views && 0 === $events && $duration < 10 ? 1 : 0,
			$this->batch['pageviews'][ count( $this->batch['pageviews'] ) - $views ][5],
			$exit,
			$this->batch['pageviews'][ count( $this->batch['pageviews'] ) - 1 ][2],
			$source['channel'],
			$source['source'],
			$source['referrer_host'],
			$source['referrer_url'],
			$source['utm_source'],
			$source['utm_medium'],
			$source['utm_campaign'],
			$source['utm_term'],
			$source['utm_content'],
			$country,
			$region,
			$city,
			(string) $this->pick( $info[2] ),
			$device,
			$os,
			$browser,
			$screen,
			(int) $net['asn'],
			$net['provider'],
			$net['network'],
			$net['company'],
			Bot::score( $flags ),
			$flags,
		);

		// The last pageview of the visit is its exit.
		$this->batch['pageviews'][ count( $this->batch['pageviews'] ) - 1 ][13] = 1;
	}

	/**
	 * Fictional prospects: name => [weight, country, own ASN (0 = on an ISP line), domain on the ISP line].
	 *
	 * @return array
	 */
	private static function companies() {
		return array(
			'Nordvik Logistics AB'     => array( 9, 'SE', 64601, '' ),
			'fjallstrombank.se'        => array( 7, 'SE', 0, 'fjallstrombank.se' ),
			'Kustverk Energi AB'       => array( 6, 'SE', 64602, '' ),
			'bergholm.se'              => array( 5, 'SE', 0, 'bergholm.se' ),
			'lindqvistpartners.se'     => array( 4, 'SE', 0, 'lindqvistpartners.se' ),
			'aurora-medtech.nl'        => array( 5, 'NL', 0, 'aurora-medtech.nl' ),
			'Hansa Retail Group GmbH'  => array( 4, 'DE', 64603, '' ),
			'solberg-industrier.no'    => array( 4, 'NO', 0, 'solberg-industrier.no' ),
			'vindkraftnorden.dk'       => array( 3, 'DK', 0, 'vindkraftnorden.dk' ),
			'Polaris Insurance Oy'     => array( 3, 'FI', 64604, '' ),
			'Brightline Software Inc.' => array( 3, 'US', 64605, '' ),
		);
	}

	/**
	 * Fixed and mobile providers per country: [asn, name].
	 *
	 * @param string $country Country.
	 * @param bool   $mobile  Mobile carriers.
	 * @return array
	 */
	private function providers( $country, $mobile ) {
		$fixed = array(
			'SE' => array( array( 3301, 'Telia Company AB' ), array( 39651, 'Tele2 Sverige AB' ), array( 8473, 'Bahnhof AB' ), array( 29518, 'Bredband2 AB' ), array( 43948, 'Ownit Broadband AB' ) ),
			'NL' => array( array( 1136, 'KPN B.V.' ), array( 33915, 'Vodafone Libertel B.V.' ) ),
			'US' => array( array( 7922, 'Comcast Cable Communications, LLC' ), array( 701, 'Verizon Business' ), array( 7018, 'AT&T Services, Inc.' ) ),
			'DE' => array( array( 3320, 'Deutsche Telekom AG' ), array( 3209, 'Vodafone GmbH' ) ),
			'NO' => array( array( 2119, 'Telenor Norge AS' ), array( 29695, 'Altibox AS' ) ),
			'GB' => array( array( 2856, 'British Telecommunications PLC' ), array( 5089, 'Virgin Media Limited' ) ),
			'DK' => array( array( 3292, 'TDC Holding A/S' ), array( 9158, 'Telenor A/S' ) ),
			'FI' => array( array( 719, 'Elisa Oyj' ), array( 16086, 'DNA Oyj' ) ),
			'IN' => array( array( 9498, 'Bharti Airtel Ltd.' ) ),
			'PL' => array( array( 5617, 'Orange Polska Spolka Akcyjna' ) ),
			'FR' => array( array( 3215, 'Orange S.A.' ), array( 12322, 'Free SAS' ) ),
			'ES' => array( array( 3352, 'Telefonica de Espana S.A.U.' ) ),
			'IE' => array( array( 5466, 'Eircom Limited' ) ),
			'CA' => array( array( 812, 'Rogers Communications Canada Inc.' ) ),
			'CH' => array( array( 3303, 'Swisscom (Schweiz) AG' ) ),
			'BE' => array( array( 5432, 'Proximus NV' ) ),
			'EE' => array( array( 3249, 'Telia Eesti AS' ) ),
			'LT' => array( array( 8764, 'Telia Lietuva, AB' ) ),
			'AU' => array( array( 1221, 'Telstra Limited' ) ),
			'SG' => array( array( 7473, 'Singapore Telecommunications Ltd' ) ),
			'BR' => array( array( 28573, 'Claro NXT Telecomunicacoes Ltda' ) ),
			'JP' => array( array( 4713, 'NTT Communications Corporation' ) ),
			'ZA' => array( array( 29975, 'Vodacom' ) ),
		);
		$cell  = array(
			'SE' => array( array( 3301, 'Telia Company AB' ), array( 1257, 'Tele2 Sverige AB' ), array( 44034, 'Hi3G Access AB' ), array( 8642, 'Telenor Sverige AB' ) ),
			'US' => array( array( 21928, 'T-Mobile USA, Inc.' ), array( 22394, 'Verizon Wireless' ) ),
			'NL' => array( array( 31615, 'Odido Netherlands B.V.' ) ),
			'DE' => array( array( 3320, 'Deutsche Telekom AG' ), array( 6805, 'Telefonica Germany GmbH & Co.OHG' ) ),
			'GB' => array( array( 12576, 'EE Limited' ) ),
		);
		$list = $mobile && isset( $cell[ $country ] ) ? $cell[ $country ] : ( $fixed[ $country ] ?? array() );
		return $list ? $list[ $this->rand( 0, count( $list ) - 1 ) ] : array( 0, '' );
	}

	/**
	 * Network of a person's visit.
	 *
	 * @param string $country Country.
	 * @param string $device  Device.
	 * @param bool   $office  During office hours on a weekday.
	 * @return array asn, provider, network, company (and country for company visits)
	 */
	private function human_network( $country, $device, $office ) {
		$weights = array(
			'desktop' => $office ? array( 'business' => 55, 'residential' => 33, 'education' => 4, 'government' => 4, 'hosting' => 4 ) : array( 'business' => 12, 'residential' => 80, 'hosting' => 4, 'education' => 2, 'government' => 2 ),
			'mobile'  => array( 'mobile' => 62, 'residential' => 28, 'business' => 10 ),
			'tablet'  => array( 'residential' => 75, 'mobile' => 15, 'business' => 10 ),
		)[ $device ];
		$type = (string) $this->pick( $weights );
		if ( '' === $country ) {
			return array( 'asn' => 0, 'provider' => '', 'network' => '', 'company' => '' );
		}
		switch ( $type ) {
			case 'business':
				// Most office visits come from the prospects, the rest from one-off companies on ISP lines.
				if ( $this->rand( 1, 100 ) <= 70 ) {
					$companies = self::companies();
					$name      = (string) $this->pick(
						array_map(
							static function ( $c ) {
								return $c[0];
							},
							$companies
						)
					);
					$c         = $companies[ $name ];
					list( $asn, $isp ) = $c[2] ? array( $c[2], $name ) : $this->providers( $c[1], false );
					return array( 'asn' => $asn, 'provider' => $isp, 'network' => 'business', 'company' => $c[2] ? $name : $c[3], 'country' => $c[1] );
				}
				list( $asn, $isp ) = $this->providers( $country, false );
				return array( 'asn' => $asn, 'provider' => $isp, 'network' => 'business', 'company' => '' );
			case 'hosting':
				$host = array( array( 16509, 'Amazon.com, Inc.' ), array( 24940, 'Hetzner Online GmbH' ), array( 9009, 'M247 Europe SRL' ), array( 8075, 'Microsoft Corporation' ) )[ $this->rand( 0, 3 ) ];
				return array( 'asn' => $host[0], 'provider' => $host[1], 'network' => 'hosting', 'company' => '' );
			case 'education':
				return array( 'asn' => 1653, 'provider' => 'SUNET Swedish University Network', 'network' => 'education', 'company' => '' );
			case 'government':
				return array( 'asn' => 64650, 'provider' => 'Region Mälardalen', 'network' => 'government', 'company' => '' );
		}
		list( $asn, $isp ) = $this->providers( $country, 'mobile' === $type );
		return array( 'asn' => $asn, 'provider' => $isp, 'network' => $asn ? $type : '', 'company' => '' );
	}

	/**
	 * Automated traffic: crawlers, headless scrapers, referrer spam and forged hits.
	 *
	 * @param int $day Local midnight (as a UTC timestamp).
	 * @param int $now Local now (as a UTC timestamp).
	 */
	private function bot_session( $day, $now ) {
		$start = $day + $this->rand( 0, DAY_IN_SECONDS - 1 );
		if ( $start > $now ) {
			return;
		}
		$kind     = (string) $this->pick( array( 'crawler' => 40, 'headless' => 30, 'spam' => 15, 'forged' => 15 ) );
		$hosting  = array( array( 16509, 'Amazon.com, Inc.' ), array( 24940, 'Hetzner Online GmbH' ), array( 16276, 'OVH SAS' ), array( 14061, 'DigitalOcean, LLC' ) );
		$net      = $hosting[ $this->rand( 0, 3 ) ];
		$network  = 'hosting';
		$country  = (string) $this->pick( array( 'US' => 50, 'DE' => 20, 'NL' => 10, 'FR' => 8, 'SG' => 6, 'IN' => 6 ) );
		$referrer = '';
		$browser  = 'Chrome';
		$pages    = 1;
		$gap      = array( 1, 3 );
		switch ( $kind ) {
			case 'crawler':
				$browser = (string) $this->pick( array( 'Googlebot' => 35, 'Bingbot' => 20, 'AhrefsBot' => 12, 'SemrushBot' => 10, 'GPTBot' => 8, 'ClaudeBot' => 5, 'PerplexityBot' => 5, 'Applebot' => 5 ) );
				$net     = 'Googlebot' === $browser ? array( 15169, 'Google LLC' ) : ( 'Bingbot' === $browser ? array( 8075, 'Microsoft Corporation' ) : $net );
				$flags   = Bot::UA_BOT | ( $this->rand( 0, 1 ) ? Bot::NO_LANGUAGE : 0 );
				$pages   = $this->rand( 1, 4 );
				$gap     = array( 2, 20 );
				break;
			case 'headless':
				$browser = (string) $this->pick( array( 'HeadlessChrome' => 60, 'Puppeteer' => 20, 'Playwright' => 20 ) );
				$pages   = $this->rand( 5, 40 );
				$flags   = Bot::HEADLESS | Bot::DATACENTER | Bot::ANOMALY | ( $pages >= 15 ? Bot::RATE : 0 );
				$gap     = array( 1, 2 );
				break;
			case 'spam':
				$referrer = 'https://' . (string) $this->pick( array( 'semalt.com' => 40, 'darodar.com' => 30, 'buttons-for-website.com' => 30 ) ) . '/';
				$flags    = Bot::NO_CLIENT | Bot::REF_SPAM | Bot::NO_LANGUAGE;
				break;
			default:
				// Forged hits from a botnet of hijacked home connections.
				list( $asn, $isp ) = $this->providers( $country, false );
				$net     = array( $asn, $isp );
				$network = $asn ? 'residential' : '';
				$flags   = Bot::NO_CLIENT | Bot::FETCH_META;
				$pages   = $this->rand( 1, 2 );
		}
		if ( 'hosting' === $network ) {
			$flags |= Bot::DATACENTER;
		}
		if ( $start < $now - 3600 ) {
			$flags |= Bot::PASSIVE;
		}
		$source  = Referrer::classify( $referrer, array(), $this->host );
		$sid     = $this->ids['sessions']++;
		$visitor = $this->hex();
		$t       = $start;
		$prev    = '';
		$path    = 'spam' === $kind ? '/' : $this->page_with_tag( (string) $this->pick( array( 'home' => 20, 'svc' => 30, 'blog' => 30, 'case' => 10, 'career' => 10 ) ) );
		$first   = $path;
		$pv      = '';
		$all     = self::pages();
		for ( $i = 0; $i < $pages && $t <= $now; $i++ ) {
			$pv                         = $this->hex();
			$this->batch['pageviews'][] = array( $this->ids['pageviews']++, $sid, $pv, $visitor, gmdate( 'Y-m-d H:i:s', $t ), $path, $all[ $path ][0], $this->post_ids[ $path ] ?? 0, $all[ $path ][1], $prev, 0, 0, 0 === $i ? 1 : 0, 0, 200 );
			$prev                       = $path;
			$path                       = $this->next_page( $path );
			$t                         += $this->rand( $gap[0], $gap[1] );
		}
		$this->batch['pageviews'][ count( $this->batch['pageviews'] ) - 1 ][13] = 1;
		$this->batch['sessions'][] = array( $sid, $visitor, gmdate( 'Y-m-d H:i:s', $start ), gmdate( 'Y-m-d H:i:s', min( $now, $t ) ), 0, $i, 0, $i > 1 ? 0 : 1, $first, $prev, $pv, $source['channel'], $source['source'], $source['referrer_host'], $source['referrer_url'], '', '', '', '', '', $country, '', '', 'crawler' === $kind ? '' : 'en-US', 'desktop', 'Linux', $browser, 'headless' === $kind ? 'lg' : '', $net[0], $net[1], $network, '', Bot::score( $flags ), $flags );
	}

	/**
	 * Referrer URL and landing-page query parameters for a channel.
	 *
	 * @param string $channel Channel key.
	 * @param string $country Visitor country.
	 * @return array [referrer, params]
	 */
	private function traffic( $channel, $country ) {
		$campaigns = array( 'cloud-migration-nordics' => 40, 'nis2-consulting' => 35, 'brand' => 25 );
		$terms     = array( 'cloud migration consultant' => 20, 'nis2 consulting' => 18, 'azure partner stockholm' => 14, 'kubernetes consultants' => 12, 'it consulting company' => 10, 'nemvo' => 26 );
		switch ( $channel ) {
			case 'search':
				$engine = (string) $this->pick( array( 'google' => 86, 'www.bing.com' => 8, 'duckduckgo.com' => 4, 'www.ecosia.org' => 1, 'search.brave.com' => 1 ) );
				$tld    = array( 'SE' => 'se', 'NO' => 'no', 'DK' => 'dk', 'FI' => 'fi', 'DE' => 'de', 'NL' => 'nl', 'GB' => 'co.uk', 'FR' => 'fr', 'PL' => 'pl' );
				$host   = 'google' === $engine ? 'www.google.' . ( $tld[ $country ] ?? 'com' ) : $engine;
				return array( 'https://' . $host . '/', array() );
			case 'social':
				return array(
					(string) $this->pick(
						array(
							'https://www.linkedin.com/'               => 58,
							'https://lnkd.in/'                        => 14,
							'https://www.reddit.com/r/devops/'        => 7,
							'https://news.ycombinator.com/'           => 5,
							'https://m.facebook.com/'                 => 6,
							'https://t.co/'                           => 5,
							'https://www.youtube.com/'                => 3,
							'https://bsky.app/'                       => 2,
						)
					),
					array(),
				);
			case 'referral':
				return array(
					(string) $this->pick(
						array(
							'https://clutch.co/se/it-services/stockholm'                 => 24,
							'https://github.com/nemvo/terraform-azure-landing-zone'      => 22,
							'https://computersweden.idg.se/2.2683/nis2-guide'            => 14,
							'https://www.breakit.se/artikel/nemvo-growth'                => 9,
							'https://stackoverflow.com/questions/7814/aks-node-pools'   => 7,
							'https://www.techsverige.se/medlemmar/'                      => 8,
							'https://appsource.microsoft.com/en-us/marketplace/partner-dir' => 10,
							'https://docs.google.com/'                                   => 6,
						)
					),
					array(),
				);
			case 'ai':
				$ai = (string) $this->pick( array( 'https://chatgpt.com/' => 60, 'https://www.perplexity.ai/' => 18, 'https://copilot.microsoft.com/' => 10, 'https://claude.ai/' => 8, 'https://gemini.google.com/' => 4 ) );
				return array( $ai, 'https://chatgpt.com/' === $ai && $this->rand( 1, 100 ) <= 70 ? array( 'utm_source' => 'chatgpt.com' ) : array() );
			case 'email':
				if ( $this->rand( 1, 100 ) <= 72 ) {
					return array(
						'',
						array(
							'utm_source'   => 'newsletter',
							'utm_medium'   => 'email',
							'utm_campaign' => 'monthly-newsletter',
							'utm_content'  => (string) $this->pick( array( 'hero' => 50, 'article' => 35, 'footer' => 15 ) ),
						),
					);
				}
				return array( (string) $this->pick( array( 'https://mail.google.com/' => 50, 'https://outlook.office.com/' => 50 ) ), array() );
			case 'paid_search':
				if ( $this->rand( 1, 100 ) <= 85 ) {
					return array(
						'https://www.google.com/',
						array(
							'gclid'        => $this->hex(),
							'utm_source'   => 'google',
							'utm_medium'   => 'cpc',
							'utm_campaign' => (string) $this->pick( $campaigns ),
							'utm_term'     => (string) $this->pick( $terms ),
						),
					);
				}
				return array(
					'https://www.bing.com/',
					array(
						'msclkid'      => $this->hex(),
						'utm_source'   => 'bing',
						'utm_medium'   => 'cpc',
						'utm_campaign' => (string) $this->pick( $campaigns ),
					),
				);
			case 'paid_social':
				return array(
					'https://www.linkedin.com/',
					array(
						'li_fat_id'    => $this->hex(),
						'utm_source'   => 'linkedin',
						'utm_medium'   => 'paid_social',
						'utm_campaign' => 'nis2-whitepaper-leadgen',
						'utm_content'  => (string) $this->pick( array( 'carousel' => 55, 'single-image' => 45 ) ),
					),
				);
			case 'campaign':
				return array(
					'',
					array(
						'utm_source'   => 'azure-summit',
						'utm_medium'   => 'qr',
						'utm_campaign' => 'azure-summit-2026',
					),
				);
		}
		return array( '', array() );
	}

	/**
	 * Landing page for a channel.
	 *
	 * @param string $channel Channel key.
	 * @return string
	 */
	private function landing( $channel ) {
		$tags = array(
			'direct'      => array( 'home' => 50, 'svc' => 10, 'contact' => 8, 'career' => 8, 'about' => 6, 'blog' => 6, 'case' => 4, 'book' => 3 ),
			'search'      => array( 'home' => 14, 'svc' => 30, 'blog' => 40, 'case' => 6, 'career' => 5, 'about' => 3, 'contact' => 2 ),
			'social'      => array( 'blog' => 45, 'career' => 25, 'case' => 12, 'home' => 12, 'svc' => 6 ),
			'referral'    => array( 'home' => 40, 'case' => 22, 'blog' => 14, 'career' => 8, 'about' => 8, 'svc' => 8 ),
			'ai'          => array( 'svc' => 50, 'home' => 22, 'blog' => 18, 'about' => 6, 'case' => 4 ),
			'email'       => array( 'blog' => 50, 'wp' => 30, 'home' => 10, 'case' => 10 ),
			'paid_search' => array( 'svc' => 55, 'book' => 20, 'contact' => 12, 'home' => 13 ),
			'paid_social' => array( 'wp' => 80, 'home' => 20 ),
			'campaign'    => array( 'svc' => 40, 'wp' => 30, 'home' => 30 ),
		);
		return $this->page_with_tag( (string) $this->pick( $tags[ $channel ] ) );
	}

	/**
	 * Next page in a visit.
	 *
	 * @param string $from Current path.
	 * @return string
	 */
	private function next_page( $from ) {
		$pages = self::pages();
		$tag   = $pages[ $from ][2] ?? 'home';
		$tags  = array( 'svc' => 30, 'contact' => 14, 'case' => 10, 'about' => 8, 'home' => 8, 'blog' => 10, 'career' => 5, 'book' => 6, 'wp' => 4, 'legal' => 1 );
		if ( 'blog' === $tag ) {
			$tags['blog'] = 30;
		} elseif ( 'svc' === $tag ) {
			$tags['contact'] = 24;
			$tags['case']    = 16;
		} elseif ( 'career' === $tag ) {
			$tags['career'] = 40;
		}
		for ( $i = 0; $i < 5; $i++ ) {
			$path = $this->page_with_tag( (string) $this->pick( $tags ) );
			if ( $path !== $from ) {
				return $path;
			}
		}
		return '/';
	}

	/**
	 * A random page with a tag, weighted.
	 *
	 * @param string $tag Tag.
	 * @return string
	 */
	private function page_with_tag( $tag ) {
		$weights = array();
		foreach ( self::pages() as $path => $page ) {
			if ( $tag === $page[2] && $page[3] > 0 ) {
				$weights[ $path ] = $page[3];
			}
		}
		return $weights ? (string) $this->pick( $weights ) : '/';
	}

	/**
	 * A broken link.
	 *
	 * @return string
	 */
	private function pick_404() {
		return (string) $this->pick( array( '/services/legacy-hosting/' => 30, '/blog/2019/05/gdpr-update/' => 25, '/en/contact/' => 20, '/team/' => 15, '/jobs/' => 10 ) );
	}

	/**
	 * Events a visitor triggers on a page: list of [name, label].
	 *
	 * @param string $path Path.
	 * @param string $tag  Page tag.
	 * @return array
	 */
	private function page_events( $path, $tag ) {
		$out    = array();
		$chance = function ( $percent ) {
			return $this->rand( 1, 1000 ) <= $percent * 10;
		};
		switch ( $tag ) {
			case 'contact':
				if ( $chance( 20 ) ) {
					$out[] = array( 'Form submit', 'Contact form' );
				}
				if ( $chance( 9 ) ) {
					$out[] = array( 'Email click', 'hello@nemvo.se' );
				}
				if ( $chance( 5 ) ) {
					$out[] = array( 'Phone click', '+46 8 520 278 00' );
				}
				break;
			case 'book':
				if ( $chance( 22 ) ) {
					$out[] = array( 'Outbound link', 'cal.com/nemvo/intro-call' );
				}
				break;
			case 'wp':
				if ( $chance( 35 ) ) {
					$out[] = array( 'File download', '/wp-content/uploads/2026/09/nis2-readiness-whitepaper.pdf' );
				}
				break;
			case 'case':
				if ( '/case-studies/' !== $path && $chance( 9 ) ) {
					$out[] = array( 'File download', '/wp-content/uploads/2026/06/case-study' . substr( rtrim( $path, '/' ), strrpos( rtrim( $path, '/' ), '/' ) ) . '.pdf' );
				}
				break;
			case 'blog':
				if ( '/blog/' !== $path && $chance( 8 ) ) {
					$out[] = array( 'Outbound link', (string) $this->pick( array( 'learn.microsoft.com/azure/cloud-adoption-framework' => 40, 'github.com/nemvo/terraform-azure-landing-zone' => 30, 'kubernetes.io/docs/concepts/cluster-administration' => 20, 'eur-lex.europa.eu/eli/dir/2022/2555' => 10 ) ) );
				}
				if ( $chance( 3 ) ) {
					$out[] = array( 'Newsletter signup', 'Blog sidebar' );
				}
				break;
			case 'career':
				if ( '/careers/' !== $path && $chance( 15 ) ) {
					$out[] = array( 'Outbound link', 'nemvo.teamtailor.com/jobs/apply' );
				}
				break;
			case 'home':
				if ( $chance( 4 ) ) {
					$out[] = array( 'CTA click', 'Hero: Book a meeting' );
				}
				break;
		}
		if ( $chance( 1.5 ) ) {
			$out[] = array( 'Outbound link', (string) $this->pick( array( 'www.linkedin.com/company/nemvo' => 70, 'github.com/nemvo' => 30 ) ) );
		}
		return $out;
	}

	/**
	 * Queue an event.
	 *
	 * @param int    $sid     Session id.
	 * @param string $pv      Pageview id.
	 * @param string $visitor Visitor.
	 * @param int    $t       Time.
	 * @param string $name    Name.
	 * @param string $label   Label.
	 * @param string $meta    Meta.
	 * @param string $path    Path.
	 */
	private function event( $sid, $pv, $visitor, $t, $name, $label, $meta, $path ) {
		$this->batch['events'][] = array( $this->ids['events']++, $sid, $pv, $visitor, gmdate( 'Y-m-d H:i:s', $t ), $name, $label, $meta, $path );
	}

	/**
	 * Write the queued rows with multi-row inserts.
	 */
	private function write() {
		$columns = array(
			'sessions'  => array( 'id', 'visitor', 'started_at', 'last_seen', 'duration', 'pageviews', 'events', 'is_bounce', 'entry_path', 'exit_path', 'exit_pv', 'channel', 'source', 'referrer_host', 'referrer_url', 'utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content', 'country', 'region', 'city', 'language', 'device', 'os', 'browser', 'screen', 'asn', 'org', 'network', 'company', 'bot_score', 'bot_flags' ),
			'pageviews' => array( 'id', 'session_id', 'pv', 'visitor', 'created_at', 'path', 'title', 'post_id', 'content_type', 'prev_path', 'engaged_ms', 'scroll', 'is_entry', 'is_exit', 'status' ),
			'events'    => array( 'id', 'session_id', 'pv', 'visitor', 'created_at', 'name', 'label', 'meta', 'path' ),
		);
		foreach ( $columns as $table => $cols ) {
			foreach ( array_chunk( $this->batch[ $table ], 300 ) as $chunk ) {
				$values = array();
				foreach ( $chunk as $row ) {
					$formats  = implode(
						', ',
						array_map(
							static function ( $v ) {
								return is_int( $v ) ? '%d' : '%s';
							},
							$row
						)
					);
					$values[] = $this->db->prepare( "($formats)", $row ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				}
				$this->db->query( 'INSERT INTO `' . DB::table( $table ) . '` (' . implode( ', ', $cols ) . ') VALUES ' . implode( ', ', $values ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			}
		}
	}

	/**
	 * Next number of a seeded xorshift32 generator: the same seed gives the
	 * same demo data, which wp_rand() cannot do.
	 *
	 * @return int 0 .. 2^32-1
	 */
	private function next() {
		$x           = $this->state;
		$x          ^= ( $x << 13 ) & 0xffffffff;
		$x          ^= $x >> 17;
		$x          ^= ( $x << 5 ) & 0xffffffff;
		$this->state = $x & 0xffffffff;
		return $this->state;
	}

	/**
	 * Random float in [0, 1).
	 *
	 * @return float
	 */
	private function unit() {
		return $this->next() / 4294967296;
	}

	/**
	 * Random integer between $min and $max (inclusive).
	 *
	 * @param int $min Minimum.
	 * @param int $max Maximum.
	 * @return int
	 */
	private function rand( $min, $max ) {
		return $min + (int) floor( $this->unit() * ( $max - $min + 1 ) );
	}

	/**
	 * Weighted random key.
	 *
	 * @param array $weights key => weight.
	 * @return int|string
	 */
	private function pick( array $weights ) {
		$r = $this->unit() * array_sum( $weights );
		foreach ( $weights as $key => $weight ) {
			$r -= $weight;
			if ( $r <= 0 ) {
				return $key;
			}
		}
		return array_key_last( $weights );
	}

	/**
	 * Standard normal sample (Box-Muller).
	 *
	 * @return float
	 */
	private function gauss() {
		$u = max( 1e-9, $this->unit() );
		$v = $this->unit();
		return sqrt( -2 * log( $u ) ) * cos( 2 * M_PI * $v );
	}

	/**
	 * Geometric sample: extra steps with continuation probability 1 - $p.
	 *
	 * @param float $p   Stop probability per step.
	 * @param int   $max Cap.
	 * @return int
	 */
	private function geometric( $p, $max ) {
		$n = 0;
		while ( $n < $max && $this->unit() > $p ) {
			$n++;
		}
		return $n;
	}

	/**
	 * 16 random hex characters.
	 *
	 * @return string
	 */
	private function hex() {
		return sprintf( '%08x%08x', $this->rand( 0, 0x7fffffff ), $this->rand( 0, 0x7fffffff ) );
	}
}
