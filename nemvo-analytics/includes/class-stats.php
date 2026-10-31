<?php
/**
 * Reporting queries.
 *
 * @package NemvoAnalytics
 */

namespace NemvoAnalytics;

defined( 'ABSPATH' ) || exit;

/**
 * Builds every report for one date range and one set of filters.
 *
 * SQL is assembled from fragments that are each passed through
 * $wpdb->prepare() on their own; the assembled statement is never prepared
 * again, so literal % signs (DATE_FORMAT, LIKE) stay intact.
 */
class Stats {

	/**
	 * Connection.
	 *
	 * @var \wpdb
	 */
	private $db;

	/**
	 * Table names.
	 *
	 * @var string
	 */
	private $s;

	/**
	 * Pageviews table.
	 *
	 * @var string
	 */
	private $p;

	/**
	 * Events table.
	 *
	 * @var string
	 */
	private $e;

	/**
	 * Range start (local datetime).
	 *
	 * @var string
	 */
	private $from;

	/**
	 * Range end (local datetime, inclusive).
	 *
	 * @var string
	 */
	private $to;

	/**
	 * Active filters: list of [d => dimension, o => is|is_not|contains, v => value].
	 *
	 * @var array
	 */
	private $filters;

	/**
	 * Configured goals.
	 *
	 * @var array
	 */
	private $goals;

	/**
	 * Which visits count: human, bot or all.
	 *
	 * @var string
	 */
	private $traffic;

	/**
	 * Constructor.
	 *
	 * @param \wpdb  $db      Connection.
	 * @param string $from    Start date Y-m-d.
	 * @param string $to      End date Y-m-d (inclusive).
	 * @param array  $filters Filters.
	 * @param string $traffic human (default), bot or all.
	 */
	public function __construct( $db, $from, $to, array $filters = array(), $traffic = 'human' ) {
		$this->db      = $db;
		$this->s       = DB::table( 'sessions' );
		$this->p       = DB::table( 'pageviews' );
		$this->e       = DB::table( 'events' );
		$this->from    = $from . ' 00:00:00';
		$this->to      = $to . ' 23:59:59';
		$this->filters = $filters;
		$this->goals   = array_values( (array) Settings::get( 'goals' ) );
		$this->traffic = in_array( $traffic, array( 'human', 'bot', 'all' ), true ) ? $traffic : 'human';
	}

	/**
	 * Condition on the bot score for the traffic scope.
	 *
	 * @param string $alias Sessions alias.
	 * @return string Empty for all traffic.
	 */
	private function traffic_sql( $alias = 's' ) {
		$threshold = Bot::threshold();
		if ( 'all' === $this->traffic ) {
			return '';
		}
		return 'bot' === $this->traffic ? "$alias.bot_score >= $threshold" : "$alias.bot_score < $threshold";
	}

	/**
	 * Dimensions: key => [level, SQL expression with {a} as table alias, skip empty values].
	 * Level "s" = session column, "p" = pageview column.
	 *
	 * @return array
	 */
	public static function dims() {
		return array(
			'channel'      => array( 's', '{a}.channel', false ),
			'source'       => array( 's', '{a}.source', false ),
			'referrer'     => array( 's', '{a}.referrer_url', true ),
			'utm_source'   => array( 's', '{a}.utm_source', true ),
			'utm_medium'   => array( 's', '{a}.utm_medium', true ),
			'utm_campaign' => array( 's', '{a}.utm_campaign', true ),
			'utm_term'     => array( 's', '{a}.utm_term', true ),
			'utm_content'  => array( 's', '{a}.utm_content', true ),
			'entry_page'   => array( 's', '{a}.entry_path', false ),
			'exit_page'    => array( 's', '{a}.exit_path', false ),
			'country'      => array( 's', '{a}.country', false ),
			'region'       => array( 's', "CONCAT({a}.country, '|', {a}.region)", true ),
			'city'         => array( 's', "CONCAT({a}.country, '|', {a}.city)", true ),
			'device'       => array( 's', '{a}.device', false ),
			'os'           => array( 's', '{a}.os', false ),
			'browser'      => array( 's', '{a}.browser', false ),
			'screen'       => array( 's', '{a}.screen', false ),
			'language'     => array( 's', "SUBSTRING_INDEX({a}.language, '-', 1)", false ),
			'network'      => array( 's', '{a}.network', true ),
			'org'          => array( 's', '{a}.org', true ),
			'company'      => array( 's', '{a}.company', true ),
			'weekday'      => array( 's', 'WEEKDAY({a}.started_at)', false ),
			'hour'         => array( 's', 'HOUR({a}.started_at)', false ),
			'visitor'      => array( 's', '{a}.visitor', false ),
			'page'         => array( 'p', '{a}.path', false ),
			'content_type' => array( 'p', '{a}.content_type', false ),
		);
	}

	/**
	 * Expression for a dimension with an alias.
	 *
	 * @param string $dim   Dimension.
	 * @param string $alias Table alias.
	 * @return string
	 */
	private function expr( $dim, $alias ) {
		return str_replace( '{a}', $alias, self::dims()[ $dim ][1] );
	}

	/**
	 * Comparison fragment.
	 *
	 * @param string $expr  SQL expression.
	 * @param string $op    is|is_not|contains.
	 * @param string $value Value.
	 * @return string
	 */
	private function compare( $expr, $op, $value ) {
		if ( 'contains' === $op ) {
			return $this->db->prepare( "$expr LIKE %s", '%' . $this->db->esc_like( $value ) . '%' ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}
		return $this->db->prepare( 'is_not' === $op ? "$expr <> %s" : "$expr = %s", $value ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Condition matching sessions that completed a goal.
	 *
	 * @param array  $goal        Goal definition.
	 * @param string $session_ref SQL reference to the session id.
	 * @return string
	 */
	private function goal_sql( array $goal, $session_ref ) {
		if ( 'event' === $goal['type'] ) {
			$table = $this->e;
			$alias = 'ge';
			$cond  = $this->goal_cond( $goal, 'ge.name' );
		} else {
			$table = $this->p;
			$alias = 'gp';
			$cond  = $this->goal_cond( $goal, 'gp.path' );
		}
		return "EXISTS (SELECT 1 FROM `$table` $alias WHERE $alias.session_id = $session_ref AND $cond)";
	}

	/**
	 * Match condition of a goal on a column.
	 *
	 * @param array  $goal Goal definition.
	 * @param string $col  Column (path or event name).
	 * @return string
	 */
	private function goal_cond( array $goal, $col ) {
		$value = (string) $goal['value'];
		switch ( $goal['match'] ) {
			case 'prefix':
				return $this->db->prepare( "$col LIKE %s", $this->db->esc_like( $value ) . '%' ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			case 'contains':
				return $this->db->prepare( "$col LIKE %s", '%' . $this->db->esc_like( $value ) . '%' ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			default:
				return $this->db->prepare( "$col = %s", $value ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}
	}

	/**
	 * LEFT JOIN of the sessions that converted on any goal (alias g), built
	 * set-wise instead of one subquery per session.
	 *
	 * @return string
	 */
	private function conversions_join() {
		if ( ! $this->goals ) {
			return '';
		}
		// Sessions that start in range may convert shortly after midnight on the last day.
		$range = $this->db->prepare( 'created_at BETWEEN %s AND %s', $this->from, gmdate( 'Y-m-d H:i:s', strtotime( $this->to ) + DAY_IN_SECONDS ) );
		$paths = array();
		$names = array();
		foreach ( $this->goals as $goal ) {
			if ( 'event' === $goal['type'] ) {
				$names[] = $this->goal_cond( $goal, 'name' );
			} else {
				$paths[] = $this->goal_cond( $goal, 'path' );
			}
		}
		$parts = array();
		if ( $paths ) {
			$parts[] = "SELECT session_id FROM `{$this->p}` WHERE $range AND (" . implode( ' OR ', $paths ) . ')';
		}
		if ( $names ) {
			$parts[] = "SELECT session_id FROM `{$this->e}` WHERE $range AND (" . implode( ' OR ', $names ) . ')';
		}
		return ' LEFT JOIN (' . implode( ' UNION ', $parts ) . ') g ON g.session_id = s.id';
	}

	/**
	 * SQL for one filter.
	 *
	 * @param array  $f           Filter.
	 * @param string $session_ref SQL reference to the session id.
	 * @param string $context     "s" for session queries, "p" for pageview queries.
	 * @return string
	 */
	private function filter_sql( array $f, $session_ref, $context ) {
		$negate = 'is_not' === $f['o'];

		if ( 'goal' === $f['d'] ) {
			$goal = $this->goals[ (int) $f['v'] ] ?? null;
			if ( ! $goal ) {
				return '1';
			}
			return ( $negate ? 'NOT ' : '' ) . $this->goal_sql( $goal, $session_ref );
		}
		if ( 'bot_flag' === $f['d'] ) {
			return $this->db->prepare( "(s.bot_flags & %d) " . ( $negate ? '=' : '<>' ) . ' 0', absint( $f['v'] ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}
		if ( 'event' === $f['d'] ) {
			$cond = $this->compare( 'fe.name', $negate ? 'is' : $f['o'], $f['v'] );
			return ( $negate ? 'NOT ' : '' ) . "EXISTS (SELECT 1 FROM `{$this->e}` fe WHERE fe.session_id = $session_ref AND $cond)";
		}

		$level = self::dims()[ $f['d'] ][0];
		if ( 's' === $level ) {
			return $this->compare( $this->expr( $f['d'], 's' ), $f['o'], $f['v'] );
		}
		if ( 'p' === $context ) {
			return $this->compare( $this->expr( $f['d'], 'p' ), $f['o'], $f['v'] );
		}
		$cond = $this->compare( $this->expr( $f['d'], 'fp' ), $negate ? 'is' : $f['o'], $f['v'] );
		return ( $negate ? 'NOT ' : '' ) . "EXISTS (SELECT 1 FROM `{$this->p}` fp WHERE fp.session_id = $session_ref AND $cond)";
	}

	/**
	 * WHERE for queries on the sessions table (alias s).
	 *
	 * @param string[] $skip Dimensions to ignore.
	 * @return string
	 */
	private function where_sessions( array $skip = array() ) {
		$where   = array( $this->db->prepare( 's.started_at BETWEEN %s AND %s', $this->from, $this->to ) );
		$traffic = $this->traffic_sql();
		if ( '' !== $traffic ) {
			$where[] = $traffic;
		}
		foreach ( $this->filters as $f ) {
			if ( ! in_array( $f['d'], $skip, true ) ) {
				$where[] = $this->filter_sql( $f, 's.id', 's' );
			}
		}
		return implode( ' AND ', $where );
	}

	/**
	 * FROM + WHERE for queries on the pageviews table (alias p).
	 *
	 * @param string[] $skip Dimensions to ignore.
	 * @return string
	 */
	private function from_pageviews( array $skip = array() ) {
		$where   = array( $this->db->prepare( 'p.created_at BETWEEN %s AND %s', $this->from, $this->to ) );
		$traffic = $this->traffic_sql();
		$join    = '' !== $traffic;
		if ( $join ) {
			$where[] = $traffic;
		}
		foreach ( $this->filters as $f ) {
			if ( in_array( $f['d'], $skip, true ) ) {
				continue;
			}
			if ( 'bot_flag' === $f['d'] || ( isset( self::dims()[ $f['d'] ] ) && 's' === self::dims()[ $f['d'] ][0] ) ) {
				$join = true;
			}
			$where[] = $this->filter_sql( $f, 'p.session_id', 'p' );
		}
		$from = "`{$this->p}` p" . ( $join ? " JOIN `{$this->s}` s ON s.id = p.session_id" : '' );
		return "$from WHERE " . implode( ' AND ', $where );
	}

	/**
	 * Session aggregate columns; use with sessions_from().
	 *
	 * @return string
	 */
	private function session_columns() {
		return 'COUNT(DISTINCT s.visitor) AS visitors, COUNT(*) AS visits, COALESCE(SUM(s.pageviews), 0) AS pageviews, COALESCE(SUM(s.is_bounce), 0) AS bounces, COALESCE(SUM(s.duration), 0) AS duration, '
			. ( $this->goals ? 'COUNT(g.session_id)' : '0' ) . ' AS conversions';
	}

	/**
	 * FROM clause for session aggregates (sessions + conversions).
	 *
	 * @return string
	 */
	private function sessions_from() {
		return "`{$this->s}` s" . $this->conversions_join();
	}

	/**
	 * Headline numbers.
	 *
	 * @return array
	 */
	public function overview() {
		$row = $this->db->get_row( 'SELECT ' . $this->session_columns() . ' FROM ' . $this->sessions_from() . ' WHERE ' . $this->where_sessions() ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$pv  = $this->db->get_row( 'SELECT COUNT(*) AS pageviews, COALESCE(AVG(NULLIF(p.engaged_ms, 0)), 0) AS avg_time, COALESCE(AVG(NULLIF(p.scroll, 0)), 0) AS scroll FROM ' . $this->from_pageviews() ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		$visits = (int) ( $row->visits ?? 0 );
		return array(
			'visitors'        => (int) ( $row->visitors ?? 0 ),
			'visits'          => $visits,
			'pageviews'       => (int) ( $pv->pageviews ?? 0 ),
			'views_per_visit' => $visits ? round( (int) $pv->pageviews / $visits, 2 ) : 0,
			'bounce_rate'     => $visits ? round( (int) $row->bounces / $visits, 4 ) : 0,
			'avg_duration'    => $visits ? (int) round( (int) $row->duration / $visits ) : 0,
			'conversions'     => (int) ( $row->conversions ?? 0 ),
			'conversion_rate' => $visits ? round( (int) $row->conversions / $visits, 4 ) : 0,
			'avg_time'        => (int) round( (float) ( $pv->avg_time ?? 0 ) / 1000 ),
			'scroll'          => (int) round( (float) ( $pv->scroll ?? 0 ) ),
		);
	}

	/**
	 * Interval that suits a range.
	 *
	 * @param string $from Y-m-d.
	 * @param string $to   Y-m-d.
	 * @return string hour|day|week|month
	 */
	public static function auto_interval( $from, $to ) {
		$days = (int) round( ( strtotime( $to ) - strtotime( $from ) ) / DAY_IN_SECONDS ) + 1;
		if ( $days <= 2 ) {
			return 'hour';
		}
		if ( $days <= 92 ) {
			return 'day';
		}
		if ( $days <= 366 ) {
			return 'week';
		}
		return 'month';
	}

	/**
	 * SQL bucket expression.
	 *
	 * @param string $col      Datetime column.
	 * @param string $interval Interval.
	 * @return string
	 */
	private static function bucket_sql( $col, $interval ) {
		switch ( $interval ) {
			case 'hour':
				return "DATE_FORMAT($col, '%Y-%m-%d %H:00')";
			case 'week':
				$start = ( (int) get_option( 'start_of_week', 1 ) + 6 ) % 7;
				return "DATE_FORMAT(DATE_SUB($col, INTERVAL (WEEKDAY($col) + 7 - $start) % 7 DAY), '%Y-%m-%d')";
			case 'month':
				return "DATE_FORMAT($col, '%Y-%m-01')";
			default:
				return "DATE_FORMAT($col, '%Y-%m-%d')";
		}
	}

	/**
	 * Every bucket key in the range, so empty buckets render as zero.
	 *
	 * @param string $interval Interval.
	 * @return string[]
	 */
	public function buckets( $interval ) {
		$tz    = wp_timezone();
		$start = new \DateTimeImmutable( $this->from, $tz );
		$end   = new \DateTimeImmutable( $this->to, $tz );
		$keys  = array();
		switch ( $interval ) {
			case 'hour':
				$step   = new \DateInterval( 'PT1H' );
				$format = 'Y-m-d H:00';
				break;
			case 'week':
				$sow    = ( (int) get_option( 'start_of_week', 1 ) + 6 ) % 7;
				$back   = ( (int) $start->format( 'N' ) - 1 + 7 - $sow ) % 7;
				$start  = $start->modify( "-$back days" );
				$step   = new \DateInterval( 'P1W' );
				$format = 'Y-m-d';
				break;
			case 'month':
				$start  = $start->modify( 'first day of this month' );
				$step   = new \DateInterval( 'P1M' );
				$format = 'Y-m-01';
				break;
			default:
				$step   = new \DateInterval( 'P1D' );
				$format = 'Y-m-d';
		}
		for ( $t = $start; $t <= $end && count( $keys ) < 2000; $t = $t->add( $step ) ) {
			$keys[] = $t->format( $format );
		}
		return $keys;
	}

	/**
	 * Metrics per time bucket.
	 *
	 * @param string $interval Interval.
	 * @return array
	 */
	public function timeseries( $interval ) {
		$b_s  = self::bucket_sql( 's.started_at', $interval );
		$b_p  = self::bucket_sql( 'p.created_at', $interval );
		$rows = $this->db->get_results( "SELECT $b_s AS b, " . $this->session_columns() . ' FROM ' . $this->sessions_from() . ' WHERE ' . $this->where_sessions() . ' GROUP BY b', OBJECT_K ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$pv   = $this->db->get_results( "SELECT $b_p AS b, COUNT(*) AS pageviews FROM " . $this->from_pageviews() . ' GROUP BY b', OBJECT_K ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		$out = array();
		foreach ( $this->buckets( $interval ) as $key ) {
			$r      = $rows[ $key ] ?? null;
			$visits = $r ? (int) $r->visits : 0;
			$out[]  = array(
				'date'            => $key,
				'visitors'        => $r ? (int) $r->visitors : 0,
				'visits'          => $visits,
				'pageviews'       => isset( $pv[ $key ] ) ? (int) $pv[ $key ]->pageviews : 0,
				'bounce_rate'     => $visits ? round( (int) $r->bounces / $visits, 4 ) : null,
				'avg_duration'    => $visits ? (int) round( (int) $r->duration / $visits ) : null,
				'conversions'     => $r ? (int) $r->conversions : 0,
				'conversion_rate' => $visits ? round( (int) $r->conversions / $visits, 4 ) : null,
			);
		}
		return $out;
	}

	/**
	 * Breakdown by a dimension.
	 *
	 * @param string $dim    Dimension (see dims(), plus exit_page, event, event_label, goal).
	 * @param array  $args   limit, offset, q (search), sort, dir, event (for event_label).
	 * @return array{rows:array,total:int}
	 */
	public function breakdown( $dim, array $args = array() ) {
		$limit  = max( 1, min( 10000, (int) ( $args['limit'] ?? 10 ) ) );
		$offset = max( 0, (int) ( $args['offset'] ?? 0 ) );
		$q      = trim( (string) ( $args['q'] ?? '' ) );
		$dir    = 'asc' === ( $args['dir'] ?? '' ) ? 'ASC' : 'DESC';
		$sort   = (string) ( $args['sort'] ?? '' );

		switch ( $dim ) {
			case 'goal':
				return $this->goals_report();
			case 'event':
			case 'event_label':
				return $this->events_report( $dim, (string) ( $args['event'] ?? '' ), $limit, $offset, $q, $sort, $dir );
			case 'page':
			case 'content_type':
			case 'exits':
				return $this->pages_report( $dim, $limit, $offset, $q, $sort, $dir );
		}
		if ( ! isset( self::dims()[ $dim ] ) ) {
			return array(
				'rows'  => array(),
				'total' => 0,
			);
		}

		$expr  = $this->expr( $dim, 's' );
		$where = $this->where_sessions();
		if ( self::dims()[ $dim ][2] ) {
			$where .= ' AND ' . str_replace( '{a}', 's', $this->non_empty( $dim ) );
		}
		if ( '' !== $q ) {
			$where .= ' AND ' . $this->compare( $expr, 'contains', $q );
		}

		$orders = array(
			'label'        => 'label',
			'visitors'     => 'visitors',
			'visits'       => 'visits',
			'pageviews'    => 'pageviews',
			'bounce_rate'  => 'bounces / visits',
			'avg_duration' => 'duration / visits',
			'conversions'  => 'conversions',
			'cr'           => 'conversions / visits',
		);
		$order = $orders[ $sort ] ?? 'visitors';

		$rows  = $this->db->get_results( "SELECT $expr AS label, " . $this->session_columns() . ' FROM ' . $this->sessions_from() . " WHERE $where GROUP BY label ORDER BY $order $dir, label ASC LIMIT $offset, $limit" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$total = (int) $this->db->get_var( "SELECT COUNT(DISTINCT $expr) FROM `{$this->s}` s WHERE $where" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		$out = array();
		foreach ( (array) $rows as $r ) {
			$visits = (int) $r->visits;
			$out[]  = array(
				'label'        => (string) $r->label,
				'visitors'     => (int) $r->visitors,
				'visits'       => $visits,
				'pageviews'    => (int) $r->pageviews,
				'bounce_rate'  => $visits ? round( (int) $r->bounces / $visits, 4 ) : 0,
				'avg_duration' => $visits ? (int) round( (int) $r->duration / $visits ) : 0,
				'conversions'  => (int) $r->conversions,
				'cr'           => $visits ? round( (int) $r->conversions / $visits, 4 ) : 0,
			);
		}
		return array(
			'rows'  => $out,
			'total' => $total,
		);
	}

	/**
	 * Non-empty condition for "skip empty" dimensions.
	 *
	 * @param string $dim Dimension.
	 * @return string
	 */
	private function non_empty( $dim ) {
		switch ( $dim ) {
			case 'region':
				return "{a}.region <> ''";
			case 'city':
				return "{a}.city <> ''";
			default:
				return self::dims()[ $dim ][1] . " <> ''";
		}
	}

	/**
	 * Pages, content types and exit pages.
	 *
	 * @param string $dim    page|content_type|exits.
	 * @param int    $limit  Limit.
	 * @param int    $offset Offset.
	 * @param string $q      Search.
	 * @param string $sort   Sort key.
	 * @param string $dir    ASC|DESC.
	 * @return array
	 */
	private function pages_report( $dim, $limit, $offset, $q, $sort, $dir ) {
		$expr = 'content_type' === $dim ? 'p.content_type' : 'p.path';
		$from = $this->from_pageviews();
		if ( '' !== $q ) {
			$from .= ' AND ' . $this->compare( $expr, 'contains', $q );
		}
		$having = 'exits' === $dim ? ' HAVING exits > 0' : '';

		$orders = array(
			'label'     => 'label',
			'visitors'  => 'visitors',
			'pageviews' => 'pageviews',
			'avg_time'  => 'avg_time',
			'scroll'    => 'scroll',
			'entries'   => 'entries',
			'exits'     => 'exits',
			'exit_rate' => 'exits / pageviews',
		);
		$order = $orders[ $sort ] ?? ( 'exits' === $dim ? 'exits' : 'visitors' );

		$rows = $this->db->get_results( "SELECT $expr AS label, COALESCE(MAX(CASE WHEN p.content_type <> 'search' THEN p.title END), MAX(p.title)) AS title, MAX(p.post_id) AS post_id, COUNT(DISTINCT p.visitor) AS visitors, COUNT(*) AS pageviews, AVG(NULLIF(p.engaged_ms, 0)) AS avg_time, AVG(NULLIF(p.scroll, 0)) AS scroll, SUM(p.is_entry) AS entries, SUM(p.is_exit) AS exits FROM $from GROUP BY label$having ORDER BY $order $dir, label ASC LIMIT $offset, $limit" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		if ( $having ) {
			$total = (int) $this->db->get_var( "SELECT COUNT(*) FROM (SELECT $expr AS label, SUM(p.is_exit) AS exits FROM $from GROUP BY label$having) t" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		} else {
			$total = (int) $this->db->get_var( "SELECT COUNT(DISTINCT $expr) FROM $from" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		}

		$out = array();
		foreach ( (array) $rows as $r ) {
			$views = (int) $r->pageviews;
			$out[] = array(
				'label'     => (string) $r->label,
				'title'     => 'content_type' === $dim ? '' : html_entity_decode( (string) $r->title, ENT_QUOTES ),
				'post_id'   => (int) $r->post_id,
				'visitors'  => (int) $r->visitors,
				'pageviews' => $views,
				'avg_time'  => (int) round( (float) $r->avg_time / 1000 ),
				'scroll'    => (int) round( (float) $r->scroll ),
				'entries'   => (int) $r->entries,
				'exits'     => (int) $r->exits,
				'exit_rate' => $views ? round( (int) $r->exits / $views, 4 ) : 0,
			);
		}
		return array(
			'rows'  => $out,
			'total' => $total,
		);
	}

	/**
	 * Event names, or labels of one event.
	 *
	 * @param string $dim    event|event_label.
	 * @param string $event  Event name for event_label.
	 * @param int    $limit  Limit.
	 * @param int    $offset Offset.
	 * @param string $q      Search.
	 * @param string $sort   Sort key.
	 * @param string $dir    ASC|DESC.
	 * @return array
	 */
	private function events_report( $dim, $event, $limit, $offset, $q, $sort, $dir ) {
		$expr  = 'event' === $dim ? 'e.name' : 'e.label';
		$where = $this->where_sessions();
		if ( 'event_label' === $dim ) {
			$where .= ' AND ' . $this->db->prepare( 'e.name = %s', $event );
		}
		if ( '' !== $q ) {
			$where .= ' AND ' . $this->compare( $expr, 'contains', $q );
		}
		$order = in_array( $sort, array( 'events', 'visitors', 'label' ), true ) ? $sort : 'events';
		$from  = "`{$this->e}` e JOIN `{$this->s}` s ON s.id = e.session_id WHERE $where";

		$rows  = $this->db->get_results( "SELECT $expr AS label, COUNT(*) AS events, COUNT(DISTINCT e.visitor) AS visitors, SUBSTRING(GROUP_CONCAT(DISTINCT NULLIF(e.meta, '') SEPARATOR ', '), 1, 255) AS meta FROM $from GROUP BY label ORDER BY $order $dir, label ASC LIMIT $offset, $limit" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$total = (int) $this->db->get_var( "SELECT COUNT(DISTINCT $expr) FROM $from" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		$out = array();
		foreach ( (array) $rows as $r ) {
			$out[] = array(
				'label'    => (string) $r->label,
				'events'   => (int) $r->events,
				'visitors' => (int) $r->visitors,
				'meta'     => (string) $r->meta,
			);
		}
		return array(
			'rows'  => $out,
			'total' => $total,
		);
	}

	/**
	 * Conversions per goal.
	 *
	 * @return array
	 */
	public function goals_report() {
		$where  = $this->where_sessions( array( 'goal' ) );
		$visits = (int) $this->db->get_var( "SELECT COUNT(*) FROM `{$this->s}` s WHERE $where" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$out    = array();
		foreach ( $this->goals as $i => $goal ) {
			$row   = $this->db->get_row( "SELECT COUNT(*) AS conversions, COUNT(DISTINCT s.visitor) AS visitors FROM `{$this->s}` s WHERE $where AND " . $this->goal_sql( $goal, 's.id' ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$out[] = array(
				'label'       => $goal['name'],
				'goal'        => $i,
				'type'        => $goal['type'],
				'conversions' => (int) $row->conversions,
				'visitors'    => (int) $row->visitors,
				'cr'          => $visits ? round( (int) $row->conversions / $visits, 4 ) : 0,
			);
		}
		usort(
			$out,
			static function ( $a, $b ) {
				return $b['conversions'] - $a['conversions'];
			}
		);
		return array(
			'rows'  => $out,
			'total' => count( $out ),
		);
	}

	/**
	 * Visits by weekday and hour.
	 *
	 * @return array 7x24 matrix, Monday first.
	 */
	public function heatmap() {
		$rows   = $this->db->get_results( "SELECT WEEKDAY(s.started_at) AS d, HOUR(s.started_at) AS h, COUNT(*) AS visits FROM `{$this->s}` s WHERE " . $this->where_sessions() . ' GROUP BY d, h' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$matrix = array_fill( 0, 7, array_fill( 0, 24, 0 ) );
		foreach ( (array) $rows as $r ) {
			$matrix[ (int) $r->d ][ (int) $r->h ] = (int) $r->visits;
		}
		return $matrix;
	}

	/**
	 * Where visitors of a page came from and went next.
	 *
	 * @param string $path Page path.
	 * @return array
	 */
	public function journey( $path ) {
		$from = $this->from_pageviews( array( 'page' ) );
		$prev = $this->db->get_results( "SELECT p.prev_path AS label, COUNT(*) AS n FROM $from AND " . $this->db->prepare( 'p.path = %s', $path ) . ' GROUP BY p.prev_path ORDER BY n DESC LIMIT 8' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$next = $this->db->get_results( "SELECT p.path AS label, COUNT(*) AS n FROM $from AND " . $this->db->prepare( 'p.prev_path = %s', $path ) . ' GROUP BY p.path ORDER BY n DESC LIMIT 8' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$page = $this->db->get_row( "SELECT COUNT(*) AS pageviews, COUNT(DISTINCT p.visitor) AS visitors, SUM(p.is_exit) AS exits, SUM(p.is_entry) AS entries, AVG(NULLIF(p.engaged_ms, 0)) AS avg_time, AVG(NULLIF(p.scroll, 0)) AS scroll, COALESCE(MAX(CASE WHEN p.content_type <> 'search' THEN p.title END), MAX(p.title)) AS title FROM $from AND " . $this->db->prepare( 'p.path = %s', $path ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		$map = static function ( $rows, $empty ) {
			$out = array();
			foreach ( (array) $rows as $r ) {
				$out[] = array(
					'label' => '' === $r->label ? $empty : (string) $r->label,
					'n'     => (int) $r->n,
				);
			}
			return $out;
		};

		$next = $map( $next, '' );
		if ( $page && (int) $page->exits > 0 ) {
			$next[] = array(
				'label' => '(exit)',
				'n'     => (int) $page->exits,
			);
			usort(
				$next,
				static function ( $a, $b ) {
					return $b['n'] - $a['n'];
				}
			);
		}

		$views = $page ? (int) $page->pageviews : 0;
		return array(
			'path'    => $path,
			'title'   => $page ? html_entity_decode( (string) $page->title, ENT_QUOTES ) : '',
			'summary' => array(
				'pageviews' => $views,
				'visitors'  => $page ? (int) $page->visitors : 0,
				'entries'   => $page ? (int) $page->entries : 0,
				'exit_rate' => $views ? round( (int) $page->exits / $views, 4 ) : 0,
				'avg_time'  => $page ? (int) round( (float) $page->avg_time / 1000 ) : 0,
				'scroll'    => $page ? (int) round( (float) $page->scroll ) : 0,
			),
			'prev'    => $map( $prev, '(entrance)' ),
			'next'    => array_slice( $next, 0, 8 ),
		);
	}

	/**
	 * Individual visits, newest first.
	 *
	 * @param int $limit  Limit.
	 * @param int $offset Offset.
	 * @return array{rows:array,total:int}
	 */
	public function sessions( $limit = 25, $offset = 0 ) {
		$limit  = max( 1, min( 200, (int) $limit ) );
		$offset = max( 0, (int) $offset );
		$where  = $this->where_sessions();
		$goal   = $this->goals ? 'g.session_id IS NOT NULL' : '0';
		$rows   = $this->db->get_results( "SELECT DISTINCT s.id, s.visitor, s.started_at, s.last_seen, s.duration, s.pageviews, s.events, s.is_bounce, s.entry_path, s.exit_path, s.channel, s.source, s.referrer_url, s.utm_campaign, s.country, s.region, s.city, s.device, s.os, s.browser, s.language, s.network, s.org, s.company, s.bot_score, s.bot_flags, $goal AS converted FROM " . $this->sessions_from() . " WHERE $where ORDER BY s.started_at DESC, s.id DESC LIMIT $offset, $limit", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
		$total  = (int) $this->db->get_var( "SELECT COUNT(*) FROM `{$this->s}` s WHERE $where" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
		foreach ( (array) $rows as &$row ) {
			foreach ( array( 'id', 'duration', 'pageviews', 'events', 'is_bounce', 'bot_score', 'bot_flags', 'converted' ) as $int ) {
				$row[ $int ] = (int) $row[ $int ];
			}
		}
		unset( $row );
		return array(
			'rows'  => (array) $rows,
			'total' => $total,
		);
	}

	/**
	 * One visit with its pages and events.
	 *
	 * @param \wpdb $db Connection.
	 * @param int   $id Session id.
	 * @return array|null
	 */
	public static function session( $db, $id ) {
		$s       = DB::table( 'sessions' );
		$p       = DB::table( 'pageviews' );
		$e       = DB::table( 'events' );
		$session = $db->get_row( $db->prepare( "SELECT * FROM `$s` WHERE id = %d", $id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		if ( ! $session ) {
			return null;
		}
		unset( $session['exit_pv'] );
		$pages  = $db->get_results( $db->prepare( "SELECT pv, created_at, path, title, content_type, engaged_ms, scroll, status FROM `$p` WHERE session_id = %d ORDER BY id LIMIT 500", $id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		$events = $db->get_results( $db->prepare( "SELECT pv, created_at, name, label, meta, path FROM `$e` WHERE session_id = %d ORDER BY id LIMIT 500", $id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		$labels = Bot::labels();
		$flags  = array();
		foreach ( Bot::weights() as $flag => $weight ) {
			if ( (int) $session['bot_flags'] & $flag ) {
				$flags[] = array(
					'flag'   => $flag,
					'label'  => $labels[ $flag ],
					'weight' => $weight,
				);
			}
		}
		// Other visits by the same visitor (within one salt period).
		$others = $db->get_results( $db->prepare( "SELECT id, started_at, pageviews, entry_path FROM `$s` WHERE visitor = %s AND id <> %d ORDER BY id DESC LIMIT 10", $session['visitor'], $id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		foreach ( (array) $pages as &$page ) {
			$page['engaged_ms'] = (int) $page['engaged_ms'];
			$page['scroll']     = (int) $page['scroll'];
			$page['status']     = (int) $page['status'];
			$page['title']      = html_entity_decode( (string) $page['title'], ENT_QUOTES );
		}
		unset( $page );
		return array(
			'session'   => $session,
			'pages'     => (array) $pages,
			'events'    => (array) $events,
			'signals'   => $flags,
			'threshold' => Bot::threshold(),
			'others'    => (array) $others,
		);
	}

	/**
	 * People versus automated traffic, and the signals behind it.
	 *
	 * @return array
	 */
	public function bots() {
		$saved         = $this->traffic;
		$this->traffic = 'all';
		$where         = $this->where_sessions();
		$this->traffic = $saved;
		$threshold     = Bot::threshold();
		$sums          = array();
		foreach ( array_keys( Bot::weights() ) as $flag ) {
			$sums[] = "SUM(s.bot_score >= $threshold AND (s.bot_flags & $flag) <> 0) AS f$flag";
		}
		$row = $this->db->get_row( "SELECT SUM(s.bot_score < $threshold) AS humans, SUM(s.bot_score >= $threshold) AS bots, " . implode( ', ', $sums ) . " FROM `{$this->s}` s WHERE $where", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
		$labels  = Bot::labels();
		$reasons = array();
		foreach ( Bot::weights() as $flag => $weight ) {
			$n = (int) ( $row[ 'f' . $flag ] ?? 0 );
			if ( $weight > 0 && $n > 0 ) {
				$reasons[] = array(
					'label'  => $labels[ $flag ],
					'flag'   => $flag,
					'visits' => $n,
				);
			}
		}
		usort(
			$reasons,
			static function ( $a, $b ) {
				return $b['visits'] - $a['visits'];
			}
		);
		$series = $this->db->get_results( "SELECT DATE_FORMAT(s.started_at, '%Y-%m-%d') AS d, SUM(s.bot_score < $threshold) AS humans, SUM(s.bot_score >= $threshold) AS bots FROM `{$this->s}` s WHERE $where GROUP BY d ORDER BY d", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
		return array(
			'humans'    => (int) ( $row['humans'] ?? 0 ),
			'bots'      => (int) ( $row['bots'] ?? 0 ),
			'threshold' => $threshold,
			'reasons'   => $reasons,
			'series'    => array_map(
				static function ( $r ) {
					return array(
						'date'   => $r['d'],
						'humans' => (int) $r['humans'],
						'bots'   => (int) $r['bots'],
					);
				},
				(array) $series
			),
		);
	}

	/**
	 * Live view: the last 30 minutes.
	 *
	 * @param \wpdb $db Connection.
	 * @return array
	 */
	public static function realtime( $db ) {
		$s    = DB::table( 'sessions' );
		$p    = DB::table( 'pageviews' );
		$e    = DB::table( 'events' );
		$now  = current_time( 'timestamp' ); // phpcs:ignore WordPress.DateTime.CurrentTimeTimestamp.Requested
		$five = gmdate( 'Y-m-d H:i:s', $now - 5 * MINUTE_IN_SECONDS );
		$half = gmdate( 'Y-m-d H:i:00', $now - 29 * MINUTE_IN_SECONDS );

		$human   = (int) Bot::threshold();
		$active  = (int) $db->get_var( $db->prepare( "SELECT COUNT(DISTINCT visitor) FROM `$s` WHERE last_seen >= %s AND bot_score < %d", $five, $human ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		$minutes = $db->get_results( $db->prepare( "SELECT DATE_FORMAT(p.created_at, %s) AS m, COUNT(*) AS pageviews, COUNT(DISTINCT p.visitor) AS visitors FROM `$p` p JOIN `$s` s ON s.id = p.session_id WHERE p.created_at >= %s AND s.bot_score < %d GROUP BY m", '%Y-%m-%d %H:%i', $half, $human ), OBJECT_K ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery

		$series = array();
		for ( $i = 29; $i >= 0; $i-- ) {
			$key      = gmdate( 'Y-m-d H:i', $now - $i * MINUTE_IN_SECONDS );
			$series[] = array(
				'minute'    => $key,
				'pageviews' => isset( $minutes[ $key ] ) ? (int) $minutes[ $key ]->pageviews : 0,
				'visitors'  => isset( $minutes[ $key ] ) ? (int) $minutes[ $key ]->visitors : 0,
			);
		}

		$top = static function ( $col ) use ( $db, $s, $five, $human ) {
			return $db->get_results( $db->prepare( "SELECT $col AS label, COUNT(DISTINCT visitor) AS visitors FROM `$s` WHERE last_seen >= %s AND bot_score < %d GROUP BY label ORDER BY visitors DESC LIMIT 8", $five, $human ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		};

		$pages = $db->get_results( $db->prepare( "SELECT exit_path AS label, COUNT(DISTINCT visitor) AS visitors FROM `$s` WHERE last_seen >= %s AND bot_score < %d GROUP BY label ORDER BY visitors DESC LIMIT 10", $five, $human ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery

		$cols = 's.id AS session_id, s.country, s.city, s.device, s.browser, s.os, s.source, s.channel, s.company, s.network, s.org';
		$feed = $db->get_results( $db->prepare( "SELECT 'pageview' AS kind, p.created_at AS at, p.path, p.title, '' AS name, '' AS label, $cols FROM `$p` p JOIN `$s` s ON s.id = p.session_id WHERE p.created_at >= %s AND s.bot_score < %d ORDER BY p.id DESC LIMIT 25", $half, $human ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		$evts = $db->get_results( $db->prepare( "SELECT 'event' AS kind, e.created_at AS at, e.path, '' AS title, e.name, e.label, $cols FROM `$e` e JOIN `$s` s ON s.id = e.session_id WHERE e.created_at >= %s AND s.bot_score < %d ORDER BY e.id DESC LIMIT 10", $half, $human ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		$feed = array_merge( (array) $feed, (array) $evts );
		usort(
			$feed,
			static function ( $a, $b ) {
				return strcmp( $b['at'], $a['at'] );
			}
		);

		return array(
			'active'    => $active,
			'now'       => gmdate( 'Y-m-d H:i:s', $now ),
			'minutes'   => $series,
			'pages'     => $pages,
			'sources'   => $top( 'source' ),
			'countries' => $top( 'country' ),
			'devices'   => $top( 'device' ),
			'companies' => $top( "IF(company <> '', company, IF(network <> '', network, ''))" ),
			'feed'      => array_slice( $feed, 0, 30 ),
		);
	}

	/**
	 * Pageviews per post over the last N days (posts list column).
	 *
	 * @param \wpdb $db   Connection.
	 * @param int[] $ids  Post ids.
	 * @param int   $days Days.
	 * @return array post_id => views
	 */
	public static function post_views( $db, array $ids, $days = 30 ) {
		$ids = array_filter( array_map( 'absint', $ids ) );
		if ( ! $ids ) {
			return array();
		}
		$p     = DB::table( 'pageviews' );
		$s     = DB::table( 'sessions' );
		$since = gmdate( 'Y-m-d 00:00:00', current_time( 'timestamp' ) - ( $days - 1 ) * DAY_IN_SECONDS ); // phpcs:ignore WordPress.DateTime.CurrentTimeTimestamp.Requested
		$rows  = $db->get_results( $db->prepare( "SELECT p.post_id, COUNT(*) AS views FROM `$p` p JOIN `$s` s ON s.id = p.session_id WHERE p.created_at >= %s AND s.bot_score < %d AND p.post_id IN (" . implode( ',', $ids ) . ') GROUP BY p.post_id', $since, Bot::threshold() ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		$out   = array();
		foreach ( (array) $rows as $r ) {
			$out[ (int) $r->post_id ] = (int) $r->views;
		}
		return $out;
	}
}
