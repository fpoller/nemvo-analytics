<?php
/**
 * Turns hits into sessions, pageviews and events.
 *
 * @package NemvoAnalytics
 */

namespace NemvoAnalytics;

defined( 'ABSPATH' ) || exit;

/**
 * Processes a batch of enriched hits (one hit when written directly, up to
 * hundreds from the queue) with a fixed number of queries: one preload of
 * the pageviews and sessions involved, multi-row inserts for new rows and
 * single-row updates by primary key, all in one transaction.
 *
 * Hits (built by Collector):
 *  pv: t, at, pv, visitor, path, title, post_id, type, status, src[], agent[], geo[], net[], lang, screen, flags, search, ref404
 *  e:  t, at, pv, ms, scroll, flags   (engagement ping)
 *  ev: t, at, pv, name, label         (event)
 *  v:  t, at, pv, flags               (passed a proof of work or Cap)
 */
class Processor {

	/**
	 * Session flags after the last batch, by pageview id (for the collector's reply).
	 *
	 * @var int[]
	 */
	public static $flags = array();

	/**
	 * Pageview ids given a new id in the last batch (a cached page seen by
	 * another visitor): original id => new id.
	 *
	 * @var string[]
	 */
	public static $renamed = array();

	/**
	 * Write a batch.
	 *
	 * @param array[]       $hits   Hits.
	 * @param callable|null $enrich Called with a pv hit that starts a session; returns the hit with network details filled in.
	 * @return bool False when the database could not be written (nothing was).
	 */
	public static function process( array $hits, $enrich = null ) {
		$db = DB::conn();
		if ( ! $db || ! DB::maybe_install() || ! $hits ) {
			return (bool) $db && ! $hits;
		}
		$t_s     = DB::table( 'sessions' );
		$t_p     = DB::table( 'pageviews' );
		$t_e     = DB::table( 'events' );
		$timeout = 60 * (int) Settings::get( 'session_timeout' );

		// Stable sort by time (usort is not stable before PHP 8).
		foreach ( $hits as $i => &$hit ) {
			$hit['_i'] = $i;
		}
		unset( $hit );
		usort(
			$hits,
			static function ( $a, $b ) {
				return strcmp( (string) $a['at'], (string) $b['at'] ) ?: $a['_i'] - $b['_i'];
			}
		);

		// Preload what the batch refers to.
		$views    = array();
		$sessions = array();
		$open     = array();
		$visitors = array();
		$min_at   = null;
		foreach ( $hits as $hit ) {
			if ( 'pv' === $hit['t'] ) {
				$visitors[ $hit['visitor'] ] = true;
				$min_at                      = null === $min_at ? $hit['at'] : min( $min_at, $hit['at'] );
			}
		}
		// Every pageview the batch mentions, including pageviews the server recorded that tracker hits now confirm.
		$need = array();
		foreach ( $hits as $hit ) {
			$need[ $hit['pv'] ] = true;
		}
		if ( $need ) {
			$rows = $db->get_results( "SELECT id, session_id, pv, visitor, path, engaged_ms, scroll, is_exit FROM `$t_p` WHERE pv IN (" . self::in( $db, array_keys( $need ) ) . ')', ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
			foreach ( (array) $rows as $row ) {
				$row['new']          = false;
				$row['dirty']        = false;
				$views[ $row['pv'] ] = $row;
			}
		}
		$cols = 'id, visitor, started_at, last_seen, duration, pageviews, events, is_bounce, exit_path, exit_pv, bot_flags, bot_score, screen, language, country, region, city, asn, org, network, company';
		$ids  = array_unique( array_map( 'intval', array_column( $views, 'session_id' ) ) );
		if ( $ids ) {
			foreach ( (array) $db->get_results( "SELECT $cols FROM `$t_s` WHERE id IN (" . implode( ',', $ids ) . ')', ARRAY_A ) as $row ) { // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
				$sessions[ (int) $row['id'] ] = self::session_row( $row );
			}
		}
		if ( $visitors ) {
			$cutoff = gmdate( 'Y-m-d H:i:s', strtotime( $min_at ) - $timeout );
			$rows   = $db->get_results( "SELECT $cols FROM `$t_s` WHERE visitor IN (" . self::in( $db, array_keys( $visitors ) ) . ') AND ' . $db->prepare( 'last_seen >= %s', $cutoff ) . ' ORDER BY id', ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
			foreach ( (array) $rows as $row ) {
				$row                          = self::session_row( $row );
				$sessions[ $row['id'] ]       = $row;
				$open[ $row['visitor'] ]      = $row['id'];
			}
		}

		$db->query( 'START TRANSACTION' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$new_events    = array();
		$clear_exit    = array();
		$track_404     = (bool) Settings::get( 'track_404' );
		self::$renamed = array();

		foreach ( $hits as $hit ) {
			$at = (string) $hit['at'];
			switch ( $hit['t'] ) {
				case 'pv':
					if ( isset( $views[ $hit['pv'] ] ) ) {
						$known = $views[ $hit['pv'] ];
						$ksid  = (int) $known['session_id'];
						if ( $known['visitor'] === $hit['visitor'] && isset( $sessions[ $ksid ] ) ) {
							// The tracker confirms the pageview the server recorded: no new pageview, more detail.
							if ( $hit['flags'] & Bot::JS ) {
								self::confirm( $sessions[ $ksid ], $hit, $enrich );
							}
							break;
						}
						// The same id from someone else (a cached copy of the page): a pageview of its own.
						$original                   = $hit['pv'];
						$hit['pv']                  = bin2hex( random_bytes( 8 ) );
						self::$renamed[ $original ] = $hit['pv'];
					}
					$sid = $open[ $hit['visitor'] ] ?? 0;
					if ( $sid && strtotime( $sessions[ $sid ]['last_seen'] ) < strtotime( $at ) - $timeout ) {
						$sid = 0;
					}

					if ( ! $sid ) {
						if ( $enrich ) {
							$hit = call_user_func( $enrich, $hit );
						}
						$row = self::new_session( $hit );
						if ( false === $db->insert( $t_s, $row ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery
							$db->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
							return false;
						}
						$sid                       = (int) $db->insert_id;
						$row['id']                 = $sid;
						$row['dirty']              = false;
						$sessions[ $sid ]          = $row;
						$open[ $hit['visitor'] ]   = $sid;
						$prev_path                 = '';
						$is_entry                  = 1;
					} else {
						$s         = &$sessions[ $sid ];
						$prev_path = $s['exit_path'];
						$is_entry  = 0;
						if ( isset( $views[ $s['exit_pv'] ] ) ) {
							$views[ $s['exit_pv'] ]['is_exit'] = 0;
							$views[ $s['exit_pv'] ]['dirty']   = true;
						} elseif ( '' !== $s['exit_pv'] ) {
							$clear_exit[] = $s['exit_pv'];
						}
						$s['pageviews']++;
						$s['last_seen'] = max( $s['last_seen'], $at );
						$s['exit_path'] = $hit['path'];
						$s['exit_pv']   = $hit['pv'];
						$s['is_bounce'] = 0;
						$s['bot_flags'] = $s['bot_flags'] | (int) $hit['flags'];
						$elapsed        = strtotime( $at ) - strtotime( $s['started_at'] );
						if ( $s['pageviews'] > 300 || ( $s['pageviews'] >= 15 && $elapsed < 3 * $s['pageviews'] ) ) {
							$s['bot_flags'] |= Bot::RATE;
						}
						$s['dirty'] = true;
						unset( $s );
					}

					$views[ $hit['pv'] ] = array(
						'new'          => true,
						'dirty'        => false,
						'session_id'   => $sid,
						'pv'           => $hit['pv'],
						'visitor'      => $hit['visitor'],
						'created_at'   => $at,
						'path'         => $hit['path'],
						'title'        => $hit['title'],
						'post_id'      => (int) $hit['post_id'],
						'content_type' => $hit['type'],
						'prev_path'    => $prev_path,
						'engaged_ms'   => 0,
						'scroll'       => 0,
						'is_entry'     => $is_entry,
						'is_exit'      => 1,
						'status'       => (int) $hit['status'],
					);
					if ( 404 === (int) $hit['status'] && $track_404 ) {
						$new_events[] = array( $sid, $hit['pv'], $hit['visitor'], $at, '404', $hit['path'], (string) $hit['ref404'], $hit['path'] );
					}
					if ( '' !== (string) ( $hit['search'] ?? '' ) ) {
						$new_events[] = array( $sid, $hit['pv'], $hit['visitor'], $at, 'Site search', (string) $hit['search'], '', $hit['path'] );
					}
					break;

				case 'e':
					if ( ! isset( $views[ $hit['pv'] ], $sessions[ (int) $views[ $hit['pv'] ]['session_id'] ] ) ) {
						break;
					}
					$v   = &$views[ $hit['pv'] ];
					$s   = &$sessions[ (int) $v['session_id'] ];
					$ms  = min( 6 * HOUR_IN_SECONDS * 1000, absint( $hit['ms'] ) );
					$pct = min( 100, absint( $hit['scroll'] ) );
					if ( $ms > $v['engaged_ms'] || $pct > $v['scroll'] ) {
						$before          = intdiv( (int) $v['engaged_ms'], 1000 );
						$v['engaged_ms'] = max( (int) $v['engaged_ms'], $ms );
						$v['scroll']     = max( (int) $v['scroll'], $pct );
						$v['dirty']      = true;
						$s['duration']  += intdiv( (int) $v['engaged_ms'], 1000 ) - $before;
						// Ten seconds of attention turns a single-page visit into an engaged one.
						if ( $s['duration'] >= 10 ) {
							$s['is_bounce'] = 0;
						}
					}
					$s['bot_flags'] = $s['bot_flags'] | (int) $hit['flags'];
					$s['last_seen'] = max( $s['last_seen'], $at );
					$s['dirty']     = true;
					unset( $v, $s );
					break;

				case 'ev':
					if ( ! isset( $views[ $hit['pv'] ], $sessions[ (int) $views[ $hit['pv'] ]['session_id'] ] ) ) {
						break;
					}
					$v = $views[ $hit['pv'] ];
					$s = &$sessions[ (int) $v['session_id'] ];
					if ( $s['events'] < 500 ) {
						$new_events[]   = array( (int) $v['session_id'], $hit['pv'], $s['visitor'], $at, (string) $hit['name'], (string) $hit['label'], '', $v['path'] );
						$s['events']++;
						$s['is_bounce'] = 0;
						$s['last_seen'] = max( $s['last_seen'], $at );
						$s['dirty']     = true;
					}
					unset( $s );
					break;

				case 'v':
					if ( isset( $views[ $hit['pv'] ], $sessions[ (int) $views[ $hit['pv'] ]['session_id'] ] ) ) {
						$s              = &$sessions[ (int) $views[ $hit['pv'] ]['session_id'] ];
						$s['bot_flags'] = $s['bot_flags'] | (int) $hit['flags'];
						$s['dirty']     = true;
						unset( $s );
					}
					break;
			}
		}

		$ok = true;

		// New pageviews.
		$rows = array();
		foreach ( $views as $v ) {
			if ( $v['new'] ) {
				$rows[] = array( (int) $v['session_id'], $v['pv'], $v['visitor'], $v['created_at'], $v['path'], $v['title'], $v['post_id'], $v['content_type'], $v['prev_path'], (int) $v['engaged_ms'], (int) $v['scroll'], (int) $v['is_entry'], (int) $v['is_exit'], (int) $v['status'] );
			}
		}
		$ok = $ok && self::insert_rows( $db, $t_p, array( 'session_id', 'pv', 'visitor', 'created_at', 'path', 'title', 'post_id', 'content_type', 'prev_path', 'engaged_ms', 'scroll', 'is_entry', 'is_exit', 'status' ), $rows, true );

		// Changed pageviews from earlier batches.
		foreach ( $views as $v ) {
			if ( $ok && ! $v['new'] && $v['dirty'] ) {
				$ok = false !== $db->query( $db->prepare( "UPDATE `$t_p` SET engaged_ms = %d, scroll = %d, is_exit = %d WHERE id = %d", $v['engaged_ms'], $v['scroll'], $v['is_exit'], $v['id'] ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
			}
		}
		if ( $ok && $clear_exit ) {
			$ok = false !== $db->query( "UPDATE `$t_p` SET is_exit = 0 WHERE pv IN (" . self::in( $db, $clear_exit ) . ')' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
		}

		$ok = $ok && self::insert_rows( $db, $t_e, array( 'session_id', 'pv', 'visitor', 'created_at', 'name', 'label', 'meta', 'path' ), $new_events, false );

		self::$flags = array();
		foreach ( $sessions as $sid => $s ) {
			$score = Bot::score( $s['bot_flags'] );
			if ( $ok && ( $s['dirty'] || $score !== $s['bot_score'] ) ) {
				$ok = false !== $db->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
					$db->prepare(
						"UPDATE `$t_s` SET last_seen = %s, duration = %d, pageviews = %d, events = %d, is_bounce = %d, exit_path = %s, exit_pv = %s, bot_flags = %d, bot_score = %d, screen = %s, language = %s, country = %s, region = %s, city = %s, asn = %d, org = %s, network = %s, company = %s WHERE id = %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
						$s['last_seen'],
						$s['duration'],
						$s['pageviews'],
						$s['events'],
						$s['is_bounce'],
						$s['exit_path'],
						$s['exit_pv'],
						$s['bot_flags'],
						$score,
						(string) $s['screen'],
						(string) $s['language'],
						(string) $s['country'],
						(string) $s['region'],
						(string) $s['city'],
						(int) $s['asn'],
						(string) $s['org'],
						(string) $s['network'],
						(string) $s['company'],
						$sid
					)
				);
			}
		}
		foreach ( $views as $pv => $v ) {
			self::$flags[ $pv ] = isset( $sessions[ (int) $v['session_id'] ] ) ? $sessions[ (int) $v['session_id'] ]['bot_flags'] : 0;
		}
		foreach ( self::$renamed as $original => $pv ) {
			self::$flags[ $original ] = self::$flags[ $pv ] ?? 0;
		}

		$db->query( $ok ? 'COMMIT' : 'ROLLBACK' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return $ok;
	}

	/**
	 * Complete a session with what only the tracker knows.
	 *
	 * @param array         $s      Session row (changed in place).
	 * @param array         $hit    Tracker pv hit.
	 * @param callable|null $enrich Network lookup with reverse DNS.
	 */
	private static function confirm( array &$s, array $hit, $enrich ) {
		$s['bot_flags'] = $s['bot_flags'] | (int) $hit['flags'];
		if ( '' === (string) $s['screen'] ) {
			$s['screen'] = (string) $hit['screen'];
		}
		if ( '' === (string) $s['country'] && '' !== (string) ( $hit['geo']['country'] ?? '' ) ) {
			$s['country'] = (string) $hit['geo']['country'];
			$s['region']  = (string) ( $hit['geo']['region'] ?? '' );
			$s['city']    = (string) ( $hit['geo']['city'] ?? '' );
		}
		// The server skipped the reverse DNS lookup to serve the page fast; do it now.
		if ( $enrich && '' === (string) $s['company'] && in_array( (string) $s['network'], array( 'isp', '' ), true ) ) {
			$enriched = call_user_func( $enrich, $hit );
			if ( ! empty( $enriched['net']['network'] ) ) {
				$s['asn']     = (int) $enriched['net']['asn'];
				$s['org']     = (string) $enriched['net']['provider'];
				$s['network'] = (string) $enriched['net']['network'];
				$s['company'] = (string) $enriched['net']['company'];
				if ( 'hosting' === $s['network'] ) {
					$s['bot_flags'] |= Bot::DATACENTER;
				}
			}
		}
		$s['last_seen'] = max( $s['last_seen'], (string) $hit['at'] );
		$s['dirty']     = true;
	}

	/**
	 * Mark ended visits without any interaction (hourly).
	 *
	 * @return int Visits updated.
	 */
	public static function mark_passive() {
		$db = DB::conn();
		if ( ! $db ) {
			return 0;
		}
		$t_s   = DB::table( 'sessions' );
		$now   = current_time( 'timestamp' ); // phpcs:ignore WordPress.DateTime.CurrentTimeTimestamp.Requested
		$ended = gmdate( 'Y-m-d H:i:s', $now - 60 * (int) Settings::get( 'session_timeout' ) );
		$since = gmdate( 'Y-m-d H:i:s', $now - 2 * DAY_IN_SECONDS );
		$rows  = $db->get_results( $db->prepare( "SELECT id, bot_flags FROM `$t_s` WHERE last_seen BETWEEN %s AND %s AND bot_flags & %d = 0 LIMIT 5000", $since, $ended, Bot::INTERACTION | Bot::PASSIVE ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		$by    = array();
		foreach ( (array) $rows as $row ) {
			$flags                                  = (int) $row->bot_flags | Bot::PASSIVE;
			$by[ $flags . ':' . Bot::score( $flags ) ][] = (int) $row->id;
		}
		foreach ( $by as $key => $ids ) {
			list( $flags, $score ) = array_map( 'intval', explode( ':', $key ) );
			$db->query( $db->prepare( "UPDATE `$t_s` SET bot_flags = %d, bot_score = %d WHERE id IN (" . implode( ',', $ids ) . ')', $flags, $score ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		}
		return count( (array) $rows );
	}

	/**
	 * Initial session row for a pv hit.
	 *
	 * @param array $hit Hit.
	 * @return array
	 */
	private static function new_session( array $hit ) {
		$flags = (int) $hit['flags'];
		if ( 'hosting' === ( $hit['net']['network'] ?? '' ) ) {
			$flags |= Bot::DATACENTER;
		}
		return array_merge(
			array_intersect_key( (array) $hit['src'], array_flip( array( 'channel', 'source', 'referrer_host', 'referrer_url', 'utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content' ) ) ),
			array(
				'visitor'    => $hit['visitor'],
				'started_at' => $hit['at'],
				'last_seen'  => $hit['at'],
				'duration'   => 0,
				'pageviews'  => 1,
				'events'     => 0,
				'is_bounce'  => 1,
				'entry_path' => $hit['path'],
				'exit_path'  => $hit['path'],
				'exit_pv'    => $hit['pv'],
				'country'    => (string) ( $hit['geo']['country'] ?? '' ),
				'region'     => (string) ( $hit['geo']['region'] ?? '' ),
				'city'       => (string) ( $hit['geo']['city'] ?? '' ),
				'language'   => (string) $hit['lang'],
				'device'     => (string) ( $hit['agent']['device'] ?? '' ),
				'os'         => (string) ( $hit['agent']['os'] ?? '' ),
				'browser'    => (string) ( $hit['agent']['browser'] ?? '' ),
				'screen'     => (string) $hit['screen'],
				'asn'        => (int) ( $hit['net']['asn'] ?? 0 ),
				'org'        => (string) ( $hit['net']['provider'] ?? '' ),
				'network'    => (string) ( $hit['net']['network'] ?? '' ),
				'company'    => (string) ( $hit['net']['company'] ?? '' ),
				'bot_flags'  => $flags,
				'bot_score'  => Bot::score( $flags ),
			)
		);
	}

	/**
	 * Normalise a loaded session row.
	 *
	 * @param array $row Row.
	 * @return array
	 */
	private static function session_row( array $row ) {
		foreach ( array( 'id', 'duration', 'pageviews', 'events', 'is_bounce', 'bot_flags', 'bot_score', 'asn' ) as $int ) {
			$row[ $int ] = (int) $row[ $int ];
		}
		$row['dirty'] = false;
		return $row;
	}

	/**
	 * Quoted IN list.
	 *
	 * @param \wpdb    $db     Connection.
	 * @param string[] $values Values.
	 * @return string
	 */
	private static function in( $db, array $values ) {
		return implode( ',', array_map( static function ( $v ) use ( $db ) {
			return $db->prepare( '%s', $v );
		}, $values ) );
	}

	/**
	 * Multi-row insert in chunks.
	 *
	 * @param \wpdb   $db     Connection.
	 * @param string  $table  Table.
	 * @param array   $cols   Columns.
	 * @param array[] $rows   Rows (values in column order).
	 * @param bool    $ignore INSERT IGNORE (duplicate pageview ids).
	 * @return bool
	 */
	public static function insert_rows( $db, $table, array $cols, array $rows, $ignore ) {
		foreach ( array_chunk( $rows, 250 ) as $chunk ) {
			$values = array();
			foreach ( $chunk as $row ) {
				$formats  = implode(
					',',
					array_map(
						static function ( $v ) {
							return is_int( $v ) ? '%d' : '%s';
						},
						$row
					)
				);
				$values[] = $db->prepare( "($formats)", $row ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			}
			$sql = 'INSERT ' . ( $ignore ? 'IGNORE ' : '' ) . "INTO `$table` (" . implode( ',', $cols ) . ') VALUES ' . implode( ',', $values );
			if ( false === $db->query( $sql ) ) { // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
				return false;
			}
		}
		return true;
	}
}
