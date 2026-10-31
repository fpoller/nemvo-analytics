<?php
/**
 * WP-CLI commands.
 *
 * @package NemvoAnalytics
 */

namespace NemvoAnalytics;

defined( 'ABSPATH' ) || exit;

/**
 * Manage Nemvo Analytics from the command line.
 */
class Cli {

	/**
	 * Show where statistics are stored and how the setup looks.
	 *
	 * ## EXAMPLES
	 *
	 *     wp nemvo-analytics status
	 *
	 * @subcommand status
	 */
	public function status() {
		foreach ( array( 'local', 'external' ) as $target ) {
			$status = DB::status( $target );
			if ( ! $status['configured'] ) {
				\WP_CLI::line( 'External:  not configured' );
				continue;
			}
			\WP_CLI::line( sprintf( '%-10s %s%s', ucfirst( $target ) . ':', $status['active'] ? '[active] ' : '', sprintf( '%s@%s/%s (prefix %s, source %s)', $status['user'], $status['host'], $status['name'], $status['prefix'], $status['source'] ) ) );
			if ( ! $status['connected'] ) {
				\WP_CLI::line( '           not connected: ' . $status['error'] );
				continue;
			}
			\WP_CLI::line( sprintf( '           %s, schema %s', $status['server'], $status['schema'] ? 'up to date' : 'missing (run: wp nemvo-analytics install)' ) );
			foreach ( $status['tables'] as $table => $info ) {
				\WP_CLI::line( sprintf( '           %-28s ~%s rows, %s', $table, number_format( $info['rows'] ), size_format( $info['bytes'] ) ) );
			}
		}
		$migration = Migration::state();
		if ( $migration ) {
			\WP_CLI::line( sprintf( 'Migration: %s -> %s running (%s)', $migration['from'], $migration['to'], Migration::progress( $migration )['message'] ) );
		}
		$queue = Queue::config();
		\WP_CLI::line( 'Queue:     ' . ( $queue ? sprintf( '%s:%d db %d (%s), %s waiting', $queue['host'], $queue['port'], $queue['db'], $queue['source'], null === Queue::length() ? 'unreachable: ' . Queue::error() : number_format( (int) Queue::length() ) ) : 'off (hits are written directly)' ) );
		\WP_CLI::line( 'Cap:       ' . ( Cap::enabled() ? Cap::config()['url'] : ( Settings::get( 'pow_enabled' ) ? 'off (built-in proof of work on)' : 'off' ) ) );
		foreach ( array( 'geo' => 'Location', 'asn' => 'Network' ) as $kind => $label ) {
			$info = Geo::database_info( $kind );
			\WP_CLI::line( sprintf( '%-10s %s', $label . ':', $info ? $info['type'] . ' (' . $info['built'] . ') ' . $info['path'] : 'no database' ) );
		}
		foreach ( Health::checks() as $check ) {
			if ( 'good' !== $check[0] ) {
				\WP_CLI::warning( $check[1] . '. ' . $check[2] );
			}
		}
	}

	/**
	 * Create or repair the statistics tables in the active storage.
	 *
	 * @subcommand install
	 */
	public function install() {
		if ( ! DB::install() ) {
			\WP_CLI::error( DB::error() ? DB::error() : 'Could not create tables.' );
		}
		\WP_CLI::success( 'Tables are ready (' . DB::active() . ').' );
	}

	/**
	 * Move statistics to the other storage and switch to it.
	 *
	 * ## OPTIONS
	 *
	 * --to=<target>
	 * : Where to: local (the WordPress database) or external.
	 * ---
	 * options:
	 *   - local
	 *   - external
	 * ---
	 *
	 * [--replace]
	 * : Empty the target first when it already holds statistics.
	 *
	 * [--yes]
	 * : Skip the confirmation.
	 *
	 * ## EXAMPLES
	 *
	 *     wp nemvo-analytics migrate --to=external
	 *
	 * @subcommand migrate
	 * @param array $args       Positional args.
	 * @param array $assoc_args Options.
	 */
	public function migrate( $args, $assoc_args ) {
		if ( ! Migration::state() ) {
			\WP_CLI::confirm( sprintf( 'Copy all statistics from %s to %s and switch to %s?', DB::active(), $assoc_args['to'], $assoc_args['to'] ), $assoc_args );
			$progress = Migration::start( $assoc_args['to'], ! empty( $assoc_args['replace'] ) );
			if ( is_wp_error( $progress ) ) {
				\WP_CLI::error( $progress->get_error_message() . ( 'nemvo_migrate_not_empty' === $progress->get_error_code() ? ' Add --replace.' : '' ) );
			}
		}
		$bar = \WP_CLI\Utils\make_progress_bar( 'Copying', 100 );
		$at  = 0;
		do {
			$progress = Migration::step( 10.0 );
			if ( is_wp_error( $progress ) ) {
				\WP_CLI::error( $progress->get_error_message() );
			}
			$bar->tick( $progress['percent'] - $at );
			$at = $progress['percent'];
		} while ( ! $progress['done'] );
		$bar->finish();
		\WP_CLI::success( $progress['message'] );
	}

	/**
	 * Write queued hits to the database.
	 *
	 * ## OPTIONS
	 *
	 * [--loop]
	 * : Keep running (for a worker container or a systemd service).
	 *
	 * [--batch=<n>]
	 * : Hits per transaction.
	 * ---
	 * default: 500
	 * ---
	 *
	 * [--sleep=<seconds>]
	 * : Pause when the queue is empty.
	 * ---
	 * default: 1
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp nemvo-analytics queue work --loop
	 *
	 * @subcommand queue
	 * @param array $args       Positional args: work.
	 * @param array $assoc_args Options.
	 */
	public function queue( $args, $assoc_args ) {
		if ( 'work' !== ( $args[0] ?? '' ) ) {
			\WP_CLI::error( 'Usage: wp nemvo-analytics queue work [--loop]' );
		}
		$loop  = ! empty( $assoc_args['loop'] );
		$batch = max( 1, (int) $assoc_args['batch'] );
		$sleep = max( 0.1, (float) $assoc_args['sleep'] );
		$end   = time() + 3600;
		do {
			if ( ! Queue::enabled() ) {
				if ( ! $loop ) {
					\WP_CLI::error( 'The queue is off.' );
				}
				sleep( 10 );
				continue;
			}
			$n = Queue::work( 30, $batch );
			if ( $n ) {
				\WP_CLI::log( gmdate( 'H:i:s' ) . " wrote $n hits" );
			} elseif ( '' !== Queue::error() ) {
				\WP_CLI::warning( Queue::error() );
				sleep( 5 );
			} elseif ( $loop ) {
				usleep( (int) ( $sleep * 1000000 ) );
			}
			// Restart hourly so a long-running worker picks up code and setting changes.
		} while ( $loop && time() < $end );
		if ( ! $loop ) {
			\WP_CLI::success( 'Queue processed.' );
		}
	}

	/**
	 * Show what would be recorded for an IP address (location, network, company).
	 *
	 * ## OPTIONS
	 *
	 * <ip>
	 * : IPv4 or IPv6 address.
	 *
	 * ## EXAMPLES
	 *
	 *     wp nemvo-analytics lookup 8.8.8.8
	 *
	 * @subcommand lookup
	 * @param array $args Positional args.
	 */
	public function lookup( $args ) {
		if ( ! filter_var( $args[0], FILTER_VALIDATE_IP ) ) {
			\WP_CLI::error( 'Not an IP address.' );
		}
		$geo = Geo::locate( $args[0], '', '' );
		$net = Geo::network( $args[0], true );
		\WP_CLI::line( sprintf( 'Location:  %s', implode( ', ', array_filter( array( $geo['city'], $geo['region'], $geo['country'] ) ) ) ) );
		\WP_CLI::line( sprintf( 'Network:   AS%d %s', $net['asn'], $net['provider'] ) );
		\WP_CLI::line( sprintf( 'Hostname:  %s', Dns::reverse( $args[0] ) ) );
		\WP_CLI::line( sprintf( 'Type:      %s', $net['network'] ) );
		\WP_CLI::line( sprintf( 'Company:   %s', $net['company'] ) );
	}

	/**
	 * Generate realistic demo traffic for an IT consulting website.
	 *
	 * ## OPTIONS
	 *
	 * [--days=<days>]
	 * : Number of days back to generate.
	 * ---
	 * default: 120
	 * ---
	 *
	 * [--sessions=<sessions>]
	 * : Average visits per weekday.
	 * ---
	 * default: 220
	 * ---
	 *
	 * [--seed=<seed>]
	 * : Random seed, for reproducible data.
	 * ---
	 * default: 42
	 * ---
	 *
	 * [--reset]
	 * : Delete all statistics first.
	 *
	 * [--if-empty]
	 * : Do nothing when statistics already exist (for setup scripts).
	 *
	 * ## EXAMPLES
	 *
	 *     wp nemvo-analytics seed --days=90 --reset
	 *
	 * @subcommand seed
	 * @param array $args       Positional args.
	 * @param array $assoc_args Options.
	 */
	public function seed( $args, $assoc_args ) {
		if ( ! DB::conn() || ! DB::maybe_install() ) {
			\WP_CLI::error( 'Statistics database not available: ' . DB::error() );
		}
		if ( ! empty( $assoc_args['if-empty'] ) && DB::conn()->get_var( 'SELECT 1 FROM `' . DB::table( 'sessions' ) . '` LIMIT 1' ) ) { // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
			\WP_CLI::log( 'Statistics already exist, not seeding.' );
			return;
		}
		if ( ! empty( $assoc_args['reset'] ) ) {
			DB::truncate();
			\WP_CLI::log( 'Existing statistics deleted.' );
		}
		$seeder = new Seeder( (int) $assoc_args['seed'] );
		$days   = max( 1, (int) $assoc_args['days'] );
		$bar    = \WP_CLI\Utils\make_progress_bar( 'Generating traffic', $days );
		$totals = $seeder->run( $days, max( 1, (int) $assoc_args['sessions'] ), array( $bar, 'tick' ) );
		$bar->finish();
		\WP_CLI::success( sprintf( 'Created %s visits, %s pageviews and %s events.', number_format( $totals['sessions'] ), number_format( $totals['pageviews'] ), number_format( $totals['events'] ) ) );
	}

	/**
	 * Delete statistics.
	 *
	 * ## OPTIONS
	 *
	 * [--before=<date>]
	 * : Delete data older than this date (Y-m-d).
	 *
	 * [--all]
	 * : Delete everything.
	 *
	 * [--yes]
	 * : Skip the confirmation.
	 *
	 * @subcommand purge
	 * @param array $args       Positional args.
	 * @param array $assoc_args Options.
	 */
	public function purge( $args, $assoc_args ) {
		if ( ! empty( $assoc_args['all'] ) ) {
			\WP_CLI::confirm( 'Delete ALL statistics?', $assoc_args );
			DB::truncate();
			\WP_CLI::success( 'All statistics deleted.' );
			return;
		}
		if ( empty( $assoc_args['before'] ) || ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $assoc_args['before'] ) ) {
			\WP_CLI::error( 'Pass --before=<Y-m-d> or --all.' );
		}
		\WP_CLI::confirm( 'Delete statistics before ' . $assoc_args['before'] . '?', $assoc_args );
		$n = DB::purge_before( $assoc_args['before'] . ' 00:00:00' );
		\WP_CLI::success( number_format( $n ) . ' rows deleted.' );
	}

	/**
	 * Download or refresh a free DB-IP Lite database (CC BY 4.0).
	 *
	 * ## OPTIONS
	 *
	 * [--edition=<edition>]
	 * : country (4 MB), city (about 130 MB) or asn (10 MB, needed to identify companies).
	 * ---
	 * default: country
	 * options:
	 *   - country
	 *   - city
	 *   - asn
	 * ---
	 *
	 * @subcommand geoip-update
	 * @param array $args       Positional args.
	 * @param array $assoc_args Options.
	 */
	public function geoip_update( $args, $assoc_args ) {
		$result = Geo::download_dbip( $assoc_args['edition'] );
		if ( is_wp_error( $result ) ) {
			\WP_CLI::error( $result->get_error_message() );
		}
		\WP_CLI::success( 'Installed ' . $result );
	}

	/**
	 * Send the weekly email report now.
	 *
	 * ## OPTIONS
	 *
	 * [--to=<email>]
	 * : Recipient (defaults to the configured recipients).
	 *
	 * @subcommand report
	 * @param array $args       Positional args.
	 * @param array $assoc_args Options.
	 */
	public function report( $args, $assoc_args ) {
		if ( ! Report::send( $assoc_args['to'] ?? null ) ) {
			\WP_CLI::error( 'The report could not be sent.' );
		}
		\WP_CLI::success( 'Report sent.' );
	}
}
