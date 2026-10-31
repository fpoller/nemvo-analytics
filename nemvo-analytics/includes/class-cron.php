<?php
/**
 * Scheduled maintenance.
 *
 * @package NemvoAnalytics
 */

namespace NemvoAnalytics;

defined( 'ABSPATH' ) || exit;

/**
 * Every minute (only with the queue on): write queued hits.
 * Hourly: mark ended visits without any interaction.
 * Daily: retention purge and IP database refresh.
 * Weekly: the email report.
 */
class Cron {

	const QUEUE  = 'nemvo_analytics_queue';
	const HOURLY = 'nemvo_analytics_hourly';
	const DAILY  = 'nemvo_analytics_daily';
	const WEEKLY = 'nemvo_analytics_weekly';

	/**
	 * Hook in.
	 */
	public static function init() {
		add_filter( 'cron_schedules', array( __CLASS__, 'schedules' ) ); // phpcs:ignore WordPress.WP.CronInterval.CronSchedulesInterval -- the queue needs a short interval.
		add_action( self::QUEUE, array( __CLASS__, 'queue' ) );
		add_action( self::HOURLY, array( Processor::class, 'mark_passive' ) );
		add_action( self::DAILY, array( __CLASS__, 'daily' ) );
		add_action( self::WEEKLY, array( __CLASS__, 'weekly' ) );
		// Self-heal if the events were lost (e.g. after a migration), and follow the queue setting.
		if ( ! wp_next_scheduled( self::DAILY ) || Queue::enabled() !== (bool) wp_next_scheduled( self::QUEUE ) ) {
			add_action( 'init', array( __CLASS__, 'schedule' ) );
		}
	}

	/**
	 * A one-minute schedule for the queue.
	 *
	 * @param array $schedules Schedules.
	 * @return array
	 */
	public static function schedules( $schedules ) {
		$schedules['nemvo_minute'] = array(
			'interval' => MINUTE_IN_SECONDS,
			'display'  => __( 'Every minute', 'nemvo-analytics' ),
		);
		return $schedules;
	}

	/**
	 * Schedule events: hourly, daily at 03:00 and weekly on the first day of
	 * the week at 07:00, in the site's time zone; every minute with the queue.
	 */
	public static function schedule() {
		$tz = wp_timezone();
		if ( ! wp_next_scheduled( self::HOURLY ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'hourly', self::HOURLY );
		}
		if ( ! wp_next_scheduled( self::DAILY ) ) {
			$next = new \DateTimeImmutable( 'tomorrow 03:00', $tz );
			wp_schedule_event( $next->getTimestamp(), 'daily', self::DAILY );
		}
		if ( ! wp_next_scheduled( self::WEEKLY ) ) {
			$days = array( 'sunday', 'monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday' );
			$day  = $days[ (int) get_option( 'start_of_week', 1 ) % 7 ];
			$next = new \DateTimeImmutable( "next $day 07:00", $tz );
			wp_schedule_event( $next->getTimestamp(), 'weekly', self::WEEKLY );
		}
		if ( Queue::enabled() && ! wp_next_scheduled( self::QUEUE ) ) {
			wp_schedule_event( time() + MINUTE_IN_SECONDS, 'nemvo_minute', self::QUEUE );
		} elseif ( ! Queue::enabled() ) {
			wp_clear_scheduled_hook( self::QUEUE );
		}
	}

	/**
	 * Remove scheduled events.
	 */
	public static function unschedule() {
		foreach ( array( self::QUEUE, self::HOURLY, self::DAILY, self::WEEKLY ) as $hook ) {
			wp_clear_scheduled_hook( $hook );
		}
	}

	/**
	 * Write queued hits (a fallback for sites without the worker).
	 */
	public static function queue() {
		if ( Queue::enabled() ) {
			Queue::work( 20 );
		}
	}

	/**
	 * Daily maintenance.
	 */
	public static function daily() {
		$days = (int) Settings::get( 'retention_days' );
		if ( $days > 0 ) {
			DB::purge_before( current_datetime()->modify( "-$days days" )->format( 'Y-m-d 00:00:00' ) );
		}
		if ( Settings::get( 'geo_auto_update' ) ) {
			Geo::auto_update();
		}
	}

	/**
	 * Weekly email report.
	 */
	public static function weekly() {
		if ( Settings::get( 'report_enabled' ) ) {
			Report::send();
		}
	}
}
