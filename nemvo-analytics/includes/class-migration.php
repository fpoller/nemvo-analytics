<?php
/**
 * Moving statistics between the WordPress database and an external one.
 *
 * @package NemvoAnalytics
 */

namespace NemvoAnalytics;

defined( 'ABSPATH' ) || exit;

/**
 * Copies all rows to the other storage in resumable steps (each a few
 * seconds, so it works through the settings page as well as WP-CLI), then
 * copies what changed meanwhile and switches the active storage. Tracking
 * continues during the copy. The old copy is kept until you delete it.
 */
class Migration {

	const OPTION = 'nemvo_analytics_migration';
	const BATCH  = 2000;

	/**
	 * Current state, or null when no migration runs.
	 *
	 * @return array|null
	 */
	public static function state() {
		$state = get_option( self::OPTION );
		return is_array( $state ) ? $state : null;
	}

	/**
	 * Start a migration to a target.
	 *
	 * @param string $to      local|external.
	 * @param bool   $replace Empty the target first when it already holds statistics.
	 * @return array|\WP_Error Progress.
	 */
	public static function start( $to, $replace ) {
		$from = DB::active();
		if ( ! in_array( $to, array( 'local', 'external' ), true ) || $to === $from ) {
			return new \WP_Error( 'nemvo_migrate_target', __( 'Statistics are already stored there.', 'nemvo-analytics' ) );
		}
		if ( ! DB::conn( $from ) ) {
			/* translators: %s: error message */
			return new \WP_Error( 'nemvo_migrate_source', sprintf( __( 'The current database is not reachable: %s', 'nemvo-analytics' ), DB::error( $from ) ) );
		}
		if ( ! DB::conn( $to ) ) {
			/* translators: %s: error message */
			return new \WP_Error( 'nemvo_migrate_conn', sprintf( __( 'The target database is not reachable: %s', 'nemvo-analytics' ), DB::error( $to ) ) );
		}
		if ( ! DB::install( $to ) ) {
			/* translators: %s: error message */
			return new \WP_Error( 'nemvo_migrate_install', sprintf( __( 'Could not create the tables in the target database: %s', 'nemvo-analytics' ), DB::error( $to ) ) );
		}
		$existing = DB::counts( $to, true );
		if ( array_sum( (array) $existing ) > 0 ) {
			if ( ! $replace ) {
				return new \WP_Error(
					'nemvo_migrate_not_empty',
					/* translators: %s: number of visits */
					sprintf( __( 'The target database already holds %s visits. Confirm that they may be replaced.', 'nemvo-analytics' ), number_format_i18n( $existing['sessions'] ) ),
					array( 'counts' => $existing )
				);
			}
			DB::truncate( $to );
		}

		$src   = DB::conn( $from );
		$high  = array();
		$total = array();
		foreach ( DB::TABLES as $table ) {
			$high[ $table ]  = (int) $src->get_var( 'SELECT COALESCE(MAX(id), 0) FROM `' . DB::table( $table, $from ) . '`' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
			$total[ $table ] = (int) $src->get_var( 'SELECT COUNT(*) FROM `' . DB::table( $table, $from ) . '`' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
		}
		$state = array(
			'from'    => $from,
			'to'      => $to,
			'phase'   => 'copy',
			'table'   => 0,
			'last'    => 0,
			'started' => current_time( 'mysql' ),
			'high'    => $high,
			'total'   => $total,
			'copied'  => array_fill_keys( DB::TABLES, 0 ),
		);
		update_option( self::OPTION, $state, false );
		return self::progress( $state );
	}

	/**
	 * Run the migration for up to $seconds.
	 *
	 * @param float $seconds Time budget.
	 * @return array|\WP_Error Progress.
	 */
	public static function step( $seconds = 5.0 ) {
		$state = self::state();
		if ( ! $state ) {
			return new \WP_Error( 'nemvo_migrate_none', __( 'No migration is running.', 'nemvo-analytics' ) );
		}
		$src = DB::conn( $state['from'] );
		$dst = DB::conn( $state['to'] );
		if ( ! $src || ! $dst ) {
			return new \WP_Error( 'nemvo_migrate_conn', __( 'A database became unreachable. Run the migration again to continue where it stopped.', 'nemvo-analytics' ) );
		}
		$start = microtime( true );

		while ( 'copy' === $state['phase'] && microtime( true ) - $start < $seconds ) {
			$table = DB::TABLES[ $state['table'] ];
			$rows  = $src->get_results( $src->prepare( 'SELECT * FROM `' . DB::table( $table, $state['from'] ) . '` WHERE id > %d ORDER BY id LIMIT %d', $state['last'], self::BATCH ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
			if ( $rows && ! self::write( $dst, DB::table( $table, $state['to'] ), $rows ) ) {
				/* translators: %s: error message */
				return new \WP_Error( 'nemvo_migrate_write', sprintf( __( 'Writing to the target database failed: %s', 'nemvo-analytics' ), $dst->last_error ) );
			}
			$state['copied'][ $table ] += count( $rows );
			$state['last']              = $rows ? (int) end( $rows )['id'] : $state['last'];
			if ( count( $rows ) < self::BATCH ) {
				$state['table']++;
				$state['last'] = 0;
				if ( $state['table'] >= count( DB::TABLES ) ) {
					$state['phase'] = 'delta';
				}
			}
			update_option( self::OPTION, $state, false );
		}

		if ( 'delta' === $state['phase'] ) {
			// Rows written or changed since the copy started: open visits keep changing.
			$since = gmdate( 'Y-m-d H:i:s', strtotime( $state['started'] ) - 60 * ( (int) Settings::get( 'session_timeout' ) + 60 ) );
			$jobs  = array(
				'sessions'  => $src->prepare( 'last_seen >= %s OR id > %d', $since, $state['high']['sessions'] ),
				'pageviews' => $src->prepare( 'created_at >= %s OR id > %d', $since, $state['high']['pageviews'] ),
				'events'    => $src->prepare( 'id > %d', $state['high']['events'] ),
			);
			foreach ( $jobs as $table => $where ) {
				$last = 0;
				do {
					$rows = $src->get_results( 'SELECT * FROM `' . DB::table( $table, $state['from'] ) . "` WHERE ($where) AND id > " . (int) $last . ' ORDER BY id LIMIT ' . self::BATCH, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
					if ( $rows && ! self::write( $dst, DB::table( $table, $state['to'] ), $rows ) ) {
						/* translators: %s: error message */
						return new \WP_Error( 'nemvo_migrate_write', sprintf( __( 'Writing to the target database failed: %s', 'nemvo-analytics' ), $dst->last_error ) );
					}
					$last = $rows ? (int) end( $rows )['id'] : $last;
				} while ( count( $rows ) === self::BATCH );
			}
			DB::set_active( $state['to'] );
			$state['phase']    = 'done';
			$state['finished'] = current_time( 'mysql' );
			delete_option( self::OPTION );
			update_option( 'nemvo_analytics_migrated', array_intersect_key( $state, array_flip( array( 'from', 'to', 'finished', 'copied' ) ) ), false );
		}
		return self::progress( $state );
	}

	/**
	 * Stop a running migration (the active storage stays as it was).
	 */
	public static function cancel() {
		delete_option( self::OPTION );
	}

	/**
	 * Progress summary.
	 *
	 * @param array $state State.
	 * @return array
	 */
	public static function progress( array $state ) {
		$total  = max( 1, array_sum( $state['total'] ) );
		$copied = array_sum( $state['copied'] );
		$done   = 'done' === $state['phase'];
		return array(
			'phase'   => $state['phase'],
			'from'    => $state['from'],
			'to'      => $state['to'],
			'percent' => $done ? 100 : min( 99, (int) floor( $copied / $total * 100 ) ),
			'copied'  => $copied,
			'total'   => array_sum( $state['total'] ),
			'done'    => $done,
			'message' => $done
				/* translators: %s: number of rows */
				? sprintf( __( 'Done: %s rows copied. Statistics are now stored in the new location.', 'nemvo-analytics' ), number_format_i18n( $copied ) )
				/* translators: 1: rows copied, 2: rows in total */
				: sprintf( __( 'Copying… %1$s of %2$s rows', 'nemvo-analytics' ), number_format_i18n( $copied ), number_format_i18n( array_sum( $state['total'] ) ) ),
		);
	}

	/**
	 * Delete the statistics left in the storage that is no longer active.
	 *
	 * @return bool
	 */
	public static function purge_inactive() {
		$inactive = DB::is_external() ? 'local' : 'external';
		if ( self::state() || ! DB::conn( $inactive ) ) {
			return false;
		}
		return DB::truncate( $inactive );
	}

	/**
	 * REPLACE rows into a table.
	 *
	 * @param \wpdb   $db    Target connection.
	 * @param string  $table Table.
	 * @param array[] $rows  Rows (associative).
	 * @return bool
	 */
	private static function write( $db, $table, array $rows ) {
		$cols = array_keys( $rows[0] );
		foreach ( array_chunk( $rows, 500 ) as $chunk ) {
			$values = array();
			foreach ( $chunk as $row ) {
				$values[] = $db->prepare( '(' . implode( ',', array_fill( 0, count( $cols ), '%s' ) ) . ')', array_values( $row ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			}
			if ( false === $db->query( "REPLACE INTO `$table` (`" . implode( '`,`', $cols ) . '`) VALUES ' . implode( ',', $values ) ) ) { // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
				return false;
			}
		}
		return true;
	}
}
