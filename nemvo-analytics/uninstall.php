<?php
/**
 * Uninstall: removes settings, scheduled events and downloaded IP databases.
 * The statistics tables are dropped only when "Delete the statistics tables
 * when the plugin is uninstalled" is ticked.
 *
 * @package NemvoAnalytics
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

require_once __DIR__ . '/nemvo-analytics.php';

$nemvo_analytics_settings = get_option( 'nemvo_analytics_settings', array() );
if ( ! empty( $nemvo_analytics_settings['drop_on_uninstall'] ) ) {
	foreach ( array( 'local', 'external' ) as $nemvo_analytics_target ) {
		if ( 'local' === $nemvo_analytics_target || NemvoAnalytics\DB::external_config() ) {
			NemvoAnalytics\DB::drop( $nemvo_analytics_target );
		}
	}
}

foreach ( array( 'nemvo_analytics_settings', 'nemvo_analytics_db', 'nemvo_analytics_services', 'nemvo_analytics_storage', 'nemvo_analytics_schema', 'nemvo_analytics_salt', 'nemvo_analytics_geo', 'nemvo_analytics_env', 'nemvo_analytics_queue_state', 'nemvo_analytics_migration', 'nemvo_analytics_migrated' ) as $nemvo_analytics_option ) {
	delete_option( $nemvo_analytics_option );
}
delete_transient( 'nemvo_analytics_activated' );

foreach ( array( 'nemvo_analytics_queue', 'nemvo_analytics_hourly', 'nemvo_analytics_daily', 'nemvo_analytics_weekly' ) as $nemvo_analytics_hook ) {
	wp_clear_scheduled_hook( $nemvo_analytics_hook );
}

// Downloaded DB-IP databases.
$nemvo_analytics_uploads = wp_upload_dir( null, false );
$nemvo_analytics_dir     = trailingslashit( $nemvo_analytics_uploads['basedir'] ) . 'nemvo-analytics';
if ( is_dir( $nemvo_analytics_dir ) ) {
	foreach ( (array) glob( $nemvo_analytics_dir . '/*' ) as $nemvo_analytics_file ) {
		wp_delete_file( $nemvo_analytics_file );
	}
	rmdir( $nemvo_analytics_dir ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- our own, now empty directory.
}
