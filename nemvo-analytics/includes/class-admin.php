<?php
/**
 * Admin screens.
 *
 * @package NemvoAnalytics
 */

namespace NemvoAnalytics;

defined( 'ABSPATH' ) || exit;

/**
 * Menus, assets, the WP dashboard widget, the posts list column, the
 * privacy policy text and the raw CSV export.
 */
class Admin {

	const SLUG          = 'nemvo-analytics';
	const SLUG_REALTIME = 'nemvo-analytics-realtime';
	const SLUG_SETTINGS = 'nemvo-analytics-settings';

	/**
	 * Hook suffixes of our pages.
	 *
	 * @var string[]
	 */
	private static $hooks = array();

	/**
	 * Post views preloaded for the list table.
	 *
	 * @var array|null
	 */
	private static $post_views = null;

	/**
	 * Hook in.
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_init', array( __CLASS__, 'register_settings' ) );
		add_action( 'admin_init', array( __CLASS__, 'privacy_policy' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_action( 'wp_dashboard_setup', array( __CLASS__, 'dashboard_widget' ) );
		add_action( 'admin_post_nemvo_analytics_export', array( __CLASS__, 'export' ) );
		add_action( 'admin_post_nemvo_analytics_test_report', array( __CLASS__, 'test_report' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( NEMVO_ANALYTICS_FILE ), array( __CLASS__, 'action_links' ) );
		add_filter( 'admin_body_class', array( __CLASS__, 'body_class' ) );
		add_action( 'admin_notices', array( __CLASS__, 'activation_notice' ) );

		foreach ( array( 'post', 'page' ) as $type ) {
			add_filter( "manage_{$type}_posts_columns", array( __CLASS__, 'add_column' ) );
			add_action( "manage_{$type}_posts_custom_column", array( __CLASS__, 'render_column' ), 10, 2 );
		}
	}

	/**
	 * Admin menu.
	 */
	public static function menu() {
		self::$hooks[] = add_menu_page(
			__( 'Analytics', 'nemvo-analytics' ),
			__( 'Analytics', 'nemvo-analytics' ),
			Plugin::CAP_VIEW,
			self::SLUG,
			array( __CLASS__, 'render_dashboard' ),
			'dashicons-chart-area',
			3
		);
		self::$hooks[] = add_submenu_page( self::SLUG, __( 'Analytics dashboard', 'nemvo-analytics' ), __( 'Dashboard', 'nemvo-analytics' ), Plugin::CAP_VIEW, self::SLUG, array( __CLASS__, 'render_dashboard' ) );
		self::$hooks[] = add_submenu_page( self::SLUG, __( 'Realtime', 'nemvo-analytics' ), __( 'Realtime', 'nemvo-analytics' ), Plugin::CAP_VIEW, self::SLUG_REALTIME, array( __CLASS__, 'render_dashboard' ) );
		self::$hooks[] = add_submenu_page( self::SLUG, __( 'Analytics settings', 'nemvo-analytics' ), __( 'Settings', 'nemvo-analytics' ), 'manage_options', self::SLUG_SETTINGS, array( __CLASS__, 'render_settings' ) );
	}

	/**
	 * Register options.
	 */
	public static function register_settings() {
		register_setting(
			'nemvo_analytics',
			Settings::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( Settings::class, 'sanitize' ),
			)
		);
		register_setting(
			'nemvo_analytics',
			Settings::DB_OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( Settings::class, 'sanitize_db' ),
			)
		);
		register_setting(
			'nemvo_analytics',
			Settings::SERVICES_OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( Settings::class, 'sanitize_services' ),
			)
		);
	}

	/**
	 * Suggested privacy policy text.
	 */
	public static function privacy_policy() {
		if ( function_exists( 'wp_add_privacy_policy_content' ) ) {
			wp_add_privacy_policy_content( 'Nemvo Analytics', Privacy::policy_text() );
		}
	}

	/**
	 * Settings link on the plugins screen.
	 *
	 * @param array $links Links.
	 * @return array
	 */
	public static function action_links( $links ) {
		array_unshift( $links, sprintf( '<a href="%s">%s</a>', esc_url( admin_url( 'admin.php?page=' . self::SLUG_SETTINGS ) ), esc_html__( 'Settings', 'nemvo-analytics' ) ) );
		array_unshift( $links, sprintf( '<a href="%s">%s</a>', esc_url( admin_url( 'admin.php?page=' . self::SLUG ) ), esc_html__( 'Dashboard', 'nemvo-analytics' ) ) );
		return $links;
	}

	/**
	 * Mark our screens so the stylesheet can take over the canvas.
	 *
	 * @param string $classes Classes.
	 * @return string
	 */
	public static function body_class( $classes ) {
		$screen = get_current_screen();
		if ( $screen && in_array( $screen->id, self::$hooks, true ) ) {
			$classes .= ' nva-screen';
		}
		return $classes;
	}

	/**
	 * Enqueue assets on our screens.
	 *
	 * @param string $hook Current screen hook.
	 */
	public static function assets( $hook ) {
		if ( ! in_array( $hook, self::$hooks, true ) ) {
			return;
		}
		// WordPress replaces emoji with images from its CDN; these screens load nothing from elsewhere.
		remove_action( 'admin_print_scripts', 'print_emoji_detection_script' );
		remove_action( 'admin_print_styles', 'print_emoji_styles' );

		$url = NEMVO_ANALYTICS_URL . 'assets/';
		$ver = NEMVO_ANALYTICS_VERSION;
		wp_enqueue_style( 'nva-admin', $url . 'css/admin.css', array(), $ver );

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$page = isset( $_GET['page'] ) ? sanitize_key( $_GET['page'] ) : '';

		if ( self::SLUG_SETTINGS === $page ) {
			wp_enqueue_script( 'nva-settings', $url . 'js/settings.js', array( 'wp-i18n' ), $ver, true );
			wp_localize_script(
				'nva-settings',
				'nvaSettings',
				array(
					'root'      => esc_url_raw( rest_url( Collector::NS ) ),
					'nonce'     => wp_create_nonce( 'wp_rest' ),
					'migration' => Migration::state() ? Migration::progress( Migration::state() ) : null,
				)
			);
			return;
		}

		wp_enqueue_script( 'nva-chart', $url . 'vendor/chart/chart.umd.min.js', array(), '4.5.1', true );
		wp_enqueue_script( 'nva-chart-geo', $url . 'vendor/chart/chartjs-chart-geo.umd.min.js', array( 'nva-chart' ), '4.3.6', true );
		wp_enqueue_script( 'nva-chart-matrix', $url . 'vendor/chart/chartjs-chart-matrix.min.js', array( 'nva-chart' ), '3.1.0', true );
		wp_enqueue_script( 'nva-dashboard', $url . 'js/dashboard.js', array( 'nva-chart', 'nva-chart-geo', 'nva-chart-matrix', 'wp-i18n' ), $ver, true );
		wp_set_script_translations( 'nva-dashboard', 'nemvo-analytics', NEMVO_ANALYTICS_DIR . 'languages' );

		$goals = array();
		foreach ( array_values( (array) Settings::get( 'goals' ) ) as $i => $goal ) {
			$goals[] = array(
				'id'   => $i,
				'name' => $goal['name'],
			);
		}
		$db = DB::conn();

		wp_localize_script(
			'nva-dashboard',
			'nvaDashboard',
			array(
				'root'        => esc_url_raw( rest_url( Collector::NS ) ),
				'nonce'       => wp_create_nonce( 'wp_rest' ),
				'view'        => self::SLUG_REALTIME === $page ? 'realtime' : 'dashboard',
				'dashboardUrl' => admin_url( 'admin.php?page=' . self::SLUG ),
				'realtimeUrl' => admin_url( 'admin.php?page=' . self::SLUG_REALTIME ),
				'settingsUrl' => admin_url( 'admin.php?page=' . self::SLUG_SETTINGS ),
				'exportUrl'   => wp_nonce_url( admin_url( 'admin-post.php?action=nemvo_analytics_export' ), 'nemvo_analytics_export' ),
				'home'        => home_url( '/' ),
				'siteName'    => get_bloginfo( 'name' ),
				'mapUrl'      => $url . 'vendor/countries.json?ver=' . $ver,
				'countries'   => Geo::countries(),
				'goals'       => $goals,
				'canManage'   => current_user_can( 'manage_options' ),
				'today'       => current_datetime()->format( 'Y-m-d' ),
				'startOfWeek' => (int) get_option( 'start_of_week', 1 ),
				'locale'      => str_replace( '_', '-', get_user_locale() ),
				'tracking'    => (bool) Settings::get( 'tracking_enabled' ),
				'db'          => array(
					'ok'       => (bool) $db,
					'error'    => DB::error(),
					'external' => DB::is_external(),
				),
				'geoNotice'   => false !== strpos( Geo::mmdb_path( 'geo' ) . Geo::mmdb_path( 'asn' ), 'dbip' ),
				'botThreshold' => Bot::threshold(),
				'botSignals'  => self::signals(),
				'networks'    => Network::types(),
				'asn'         => (bool) Geo::database_info( 'asn' ),
				'warnings'    => array_values(
					array_filter(
						Health::checks(),
						static function ( $check ) {
							return 'good' !== $check[0];
						}
					)
				),
			)
		);
	}

	/**
	 * Bot signals for the dashboard: flag => [label, weight].
	 *
	 * @return array
	 */
	private static function signals() {
		$labels = Bot::labels();
		$out    = array();
		foreach ( Bot::weights() as $flag => $weight ) {
			$out[ $flag ] = array( $labels[ $flag ], $weight );
		}
		return $out;
	}

	/**
	 * After activation: where to go next (shown once).
	 */
	public static function activation_notice() {
		if ( ! current_user_can( 'manage_options' ) || ! get_transient( 'nemvo_analytics_activated' ) ) {
			return;
		}
		delete_transient( 'nemvo_analytics_activated' );
		printf(
			'<div class="notice notice-success is-dismissible"><p><strong>%1$s</strong> %2$s</p><p><a class="button button-primary" href="%3$s">%4$s</a> <a class="button" href="%5$s">%6$s</a></p></div>',
			esc_html__( 'Nemvo Analytics is collecting statistics.', 'nemvo-analytics' ),
			esc_html__( 'Visits show up on the dashboard within seconds. Logged-in administrators and editors are not counted; use a private window to see your own visit. The setup checklist on the settings page shows what else is worth turning on.', 'nemvo-analytics' ),
			esc_url( admin_url( 'admin.php?page=' . self::SLUG_SETTINGS ) ),
			esc_html__( 'Setup checklist', 'nemvo-analytics' ),
			esc_url( admin_url( 'admin.php?page=' . self::SLUG ) ),
			esc_html__( 'Open the dashboard', 'nemvo-analytics' )
		);
	}

	/**
	 * Dashboard and realtime screens (one app, two views).
	 */
	public static function render_dashboard() {
		require NEMVO_ANALYTICS_DIR . 'templates/dashboard.php';
	}

	/**
	 * Settings screen.
	 */
	public static function render_settings() {
		require NEMVO_ANALYTICS_DIR . 'templates/settings.php';
	}

	/**
	 * Widget on the WordPress dashboard.
	 */
	public static function dashboard_widget() {
		if ( current_user_can( Plugin::CAP_VIEW ) ) {
			wp_add_dashboard_widget( 'nemvo_analytics_widget', __( 'Analytics – last 7 days', 'nemvo-analytics' ), array( __CLASS__, 'render_widget' ) );
		}
	}

	/**
	 * Widget body: KPIs, a sparkline and the top pages.
	 */
	public static function render_widget() {
		$db = DB::conn();
		if ( ! $db ) {
			echo '<p>' . esc_html__( 'The statistics database is not reachable.', 'nemvo-analytics' ) . '</p>';
			return;
		}
		DB::maybe_install();
		$to     = current_datetime()->format( 'Y-m-d' );
		$from   = current_datetime()->modify( '-6 days' )->format( 'Y-m-d' );
		$stats  = new Stats( $db, $from, $to );
		$kpi    = $stats->overview();
		$series = $stats->timeseries( 'day' );
		$pages  = $stats->breakdown( 'page', array( 'limit' => 5 ) )['rows'];

		$values = wp_list_pluck( $series, 'visitors' );
		$max    = max( 1, max( $values ) );
		$points = array();
		foreach ( $values as $i => $v ) {
			$points[] = round( $i * ( 300 / max( 1, count( $values ) - 1 ) ), 1 ) . ',' . round( 46 - $v / $max * 42, 1 );
		}

		echo '<div class="nva-widget">';
		echo '<div class="nva-widget__kpis">';
		$items = array(
			__( 'Visitors', 'nemvo-analytics' )    => number_format_i18n( $kpi['visitors'] ),
			__( 'Pageviews', 'nemvo-analytics' )   => number_format_i18n( $kpi['pageviews'] ),
			__( 'Bounce rate', 'nemvo-analytics' ) => number_format_i18n( $kpi['bounce_rate'] * 100, 0 ) . '%',
			__( 'Conversions', 'nemvo-analytics' ) => number_format_i18n( $kpi['conversions'] ),
		);
		foreach ( $items as $label => $value ) {
			printf( '<div><span>%s</span><strong>%s</strong></div>', esc_html( $label ), esc_html( $value ) );
		}
		echo '</div>';
		printf(
			'<svg class="nva-widget__spark" viewBox="0 0 300 48" preserveAspectRatio="none" role="img" aria-label="%s"><polyline points="%s" fill="none" stroke="#2a78d6" stroke-width="2" stroke-linejoin="round" stroke-linecap="round" vector-effect="non-scaling-stroke"/></svg>',
			esc_attr__( 'Visitors per day', 'nemvo-analytics' ),
			esc_attr( implode( ' ', $points ) )
		);
		if ( $pages ) {
			echo '<table class="nva-widget__table"><thead><tr><th>' . esc_html__( 'Top pages', 'nemvo-analytics' ) . '</th><th>' . esc_html__( 'Views', 'nemvo-analytics' ) . '</th></tr></thead><tbody>';
			foreach ( $pages as $page ) {
				printf( '<tr><td title="%s">%s</td><td>%s</td></tr>', esc_attr( $page['label'] ), esc_html( $page['title'] ? $page['title'] : $page['label'] ), esc_html( number_format_i18n( $page['pageviews'] ) ) );
			}
			echo '</tbody></table>';
		}
		printf( '<p><a class="button" href="%s">%s</a></p>', esc_url( admin_url( 'admin.php?page=' . self::SLUG ) ), esc_html__( 'Open analytics', 'nemvo-analytics' ) );
		echo '</div>';
		echo '<style>.nva-widget__kpis{display:grid;grid-template-columns:repeat(4,1fr);gap:8px}.nva-widget__kpis span{display:block;color:#52514e;font-size:12px}.nva-widget__kpis strong{font-size:18px;font-weight:600}.nva-widget__spark{width:100%;height:48px;margin:12px 0}.nva-widget__table{width:100%;border-collapse:collapse}.nva-widget__table th{text-align:left;color:#52514e;font-weight:400;font-size:12px}.nva-widget__table td,.nva-widget__table th{padding:4px 0;border-bottom:1px solid #e1e0d9}.nva-widget__table td:last-child,.nva-widget__table th:last-child{text-align:right;font-variant-numeric:tabular-nums}.nva-widget__table td:first-child{max-width:0;width:100%;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}</style>';
	}

	/**
	 * Add the views column.
	 *
	 * @param array $columns Columns.
	 * @return array
	 */
	public static function add_column( $columns ) {
		if ( current_user_can( Plugin::CAP_VIEW ) ) {
			$columns['nva_views'] = __( 'Views (30d)', 'nemvo-analytics' );
		}
		return $columns;
	}

	/**
	 * Render the views column, loading all visible posts in one query.
	 *
	 * @param string $column  Column.
	 * @param int    $post_id Post id.
	 */
	public static function render_column( $column, $post_id ) {
		if ( 'nva_views' !== $column ) {
			return;
		}
		if ( null === self::$post_views ) {
			global $wp_query;
			$db               = DB::conn();
			$ids              = $wp_query && $wp_query->posts ? wp_list_pluck( $wp_query->posts, 'ID' ) : array( $post_id );
			self::$post_views = $db ? Stats::post_views( $db, $ids, 30 ) : array();
		}
		$views = self::$post_views[ $post_id ] ?? 0;
		$path  = (string) wp_parse_url( get_permalink( $post_id ), PHP_URL_PATH );
		$hash  = 'range=30d&f=' . rawurlencode( wp_json_encode( array( array( 'd' => 'page', 'o' => 'is', 'v' => $path ) ) ) );
		printf( '<a href="%s">%s</a>', esc_url( admin_url( 'admin.php?page=' . self::SLUG ) . '#' . $hash ), esc_html( number_format_i18n( $views ) ) );
	}

	/**
	 * Stream raw pageviews (with session attributes) as CSV.
	 */
	public static function export() {
		if ( ! current_user_can( Plugin::CAP_VIEW ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to export statistics.', 'nemvo-analytics' ), 403 );
		}
		check_admin_referer( 'nemvo_analytics_export' );
		$db = DB::conn();
		if ( ! $db ) {
			wp_die( esc_html( DB::error() ) );
		}

		$re   = '/^\d{4}-\d{2}-\d{2}$/';
		$from = isset( $_GET['from'] ) && preg_match( $re, sanitize_text_field( wp_unslash( $_GET['from'] ) ) ) ? sanitize_text_field( wp_unslash( $_GET['from'] ) ) : current_datetime()->modify( '-29 days' )->format( 'Y-m-d' );
		$to   = isset( $_GET['to'] ) && preg_match( $re, sanitize_text_field( wp_unslash( $_GET['to'] ) ) ) ? sanitize_text_field( wp_unslash( $_GET['to'] ) ) : current_datetime()->format( 'Y-m-d' );

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="analytics-' . $from . '-' . $to . '.csv"' );

		$out  = fopen( 'php://output', 'w' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		$cols = array( 'created_at', 'path', 'title', 'content_type', 'engaged_seconds', 'scroll_percent', 'is_entry', 'is_exit', 'status', 'channel', 'source', 'referrer', 'utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content', 'country', 'region', 'city', 'language', 'device', 'os', 'browser', 'screen', 'asn', 'network_owner', 'network_type', 'company', 'bot_score', 'session_id' );
		fputcsv( $out, $cols );

		$p    = DB::table( 'pageviews' );
		$s    = DB::table( 'sessions' );
		$last = 0;
		do {
			$rows = $db->get_results(
				$db->prepare(
					"SELECT p.id, p.created_at, p.path, p.title, p.content_type, ROUND(p.engaged_ms / 1000) AS engaged_seconds, p.scroll AS scroll_percent, p.is_entry, p.is_exit, p.status, s.channel, s.source, s.referrer_url AS referrer, s.utm_source, s.utm_medium, s.utm_campaign, s.utm_term, s.utm_content, s.country, s.region, s.city, s.language, s.device, s.os, s.browser, s.screen, s.asn, s.org AS network_owner, s.network AS network_type, s.company, s.bot_score, p.session_id FROM `$p` p JOIN `$s` s ON s.id = p.session_id WHERE p.created_at BETWEEN %s AND %s AND p.id > %d ORDER BY p.id LIMIT 5000", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					$from . ' 00:00:00',
					$to . ' 23:59:59',
					$last
				),
				ARRAY_A
			);
			foreach ( $rows as $row ) {
				$last = (int) $row['id'];
				unset( $row['id'] );
				fputcsv( $out, $row );
			}
		} while ( count( $rows ) === 5000 );
		fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		exit;
	}

	/**
	 * Send the weekly report now (settings page button).
	 */
	public static function test_report() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to do that.', 'nemvo-analytics' ), 403 );
		}
		check_admin_referer( 'nemvo_analytics_test_report' );
		$sent = Report::send( wp_get_current_user()->user_email );
		wp_safe_redirect( admin_url( 'admin.php?page=' . self::SLUG_SETTINGS . '&nva_report=' . ( $sent ? 'sent' : 'failed' ) . '#reports' ) );
		exit;
	}
}
