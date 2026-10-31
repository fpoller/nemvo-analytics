<?php
/**
 * Plugin bootstrap.
 *
 * @package NemvoAnalytics
 */

namespace NemvoAnalytics;

defined( 'ABSPATH' ) || exit;

/**
 * Wires every component into WordPress.
 */
final class Plugin {

	/**
	 * Capability required to read statistics. Granted dynamically from the
	 * "view_roles" setting, see grant_view_cap().
	 */
	const CAP_VIEW = 'view_nemvo_analytics';

	/**
	 * Singleton instance.
	 *
	 * @var Plugin|null
	 */
	private static $instance = null;

	/**
	 * Boot the plugin once.
	 *
	 * @return Plugin
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Register hooks.
	 */
	private function __construct() {
		add_filter( 'user_has_cap', array( $this, 'grant_view_cap' ), 10, 4 );
		add_action( 'rest_api_init', array( Collector::class, 'register_routes' ) );
		add_action( 'rest_api_init', array( Rest_Stats::class, 'register_routes' ) );
		add_action( 'rest_api_init', array( Cap::class, 'register_routes' ) );

		Tracker::init();
		Cron::init();
		Health::init();

		if ( is_admin() ) {
			Admin::init();
		}

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			\WP_CLI::add_command( 'nemvo-analytics', Cli::class );
		}
	}

	/**
	 * Map the virtual "view analytics" capability onto the configured roles.
	 *
	 * @param array    $allcaps All capabilities of the user.
	 * @param string[] $caps    Required primitive capabilities.
	 * @param array    $args    Requested capability and arguments.
	 * @param \WP_User $user    The user.
	 * @return array
	 */
	public function grant_view_cap( $allcaps, $caps, $args, $user ) {
		if ( ! in_array( self::CAP_VIEW, $caps, true ) ) {
			return $allcaps;
		}
		if ( ! empty( $allcaps['manage_options'] ) ) {
			$allcaps[ self::CAP_VIEW ] = true;
			return $allcaps;
		}
		$roles = (array) Settings::get( 'view_roles' );
		if ( array_intersect( $roles, (array) $user->roles ) ) {
			$allcaps[ self::CAP_VIEW ] = true;
		}
		return $allcaps;
	}

	/**
	 * Activation: store defaults, create tables, schedule jobs.
	 */
	public static function activate() {
		if ( false === get_option( Settings::OPTION ) ) {
			add_option( Settings::OPTION, Settings::defaults() );
		}
		DB::install();
		Cron::schedule();
		set_transient( 'nemvo_analytics_activated', 1, HOUR_IN_SECONDS );
	}

	/**
	 * Deactivation: stop scheduled jobs. Data is kept.
	 */
	public static function deactivate() {
		Cron::unschedule();
	}
}
