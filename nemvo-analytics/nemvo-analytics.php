<?php
/**
 * Plugin Name:       Nemvo Analytics
 * Plugin URI:        https://github.com/fpoller/nemvo-analytics
 * Description:       Privacy-friendly, self-hosted web analytics: traffic sources, pages, engagement, countries, visiting companies, devices and goals, with bot detection. No cookies, no third-party services.
 * Version:           1.0.2
 * Requires at least: 6.2
 * Requires PHP:      7.4
 * Author:            Nemvo
 * Author URI:        https://nemvo.eu
 * License:           MIT
 * License URI:       https://opensource.org/licenses/MIT
 * Text Domain:       nemvo-analytics
 * Domain Path:       /languages
 *
 * @package NemvoAnalytics
 */

defined( 'ABSPATH' ) || exit;

define( 'NEMVO_ANALYTICS_VERSION', '1.0.2' );
define( 'NEMVO_ANALYTICS_FILE', __FILE__ );
define( 'NEMVO_ANALYTICS_DIR', plugin_dir_path( __FILE__ ) );
define( 'NEMVO_ANALYTICS_URL', plugin_dir_url( __FILE__ ) );

/*
 * Autoloader: NemvoAnalytics\Rest_Stats => includes/class-rest-stats.php
 */
spl_autoload_register(
	static function ( $class ) {
		$prefix = 'NemvoAnalytics\\';
		if ( 0 !== strpos( $class, $prefix ) ) {
			return;
		}
		$name = strtolower( str_replace( '_', '-', substr( $class, strlen( $prefix ) ) ) );
		$file = NEMVO_ANALYTICS_DIR . 'includes/class-' . $name . '.php';
		if ( is_readable( $file ) ) {
			require $file;
		}
	}
);

register_activation_hook( __FILE__, array( 'NemvoAnalytics\\Plugin', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'NemvoAnalytics\\Plugin', 'deactivate' ) );

add_action( 'plugins_loaded', array( 'NemvoAnalytics\\Plugin', 'instance' ) );
