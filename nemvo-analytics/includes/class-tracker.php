<?php
/**
 * Front-end tracker output.
 *
 * @package NemvoAnalytics
 */

namespace NemvoAnalytics;

defined( 'ABSPATH' ) || exit;

/**
 * Prints the tracking script with page context (post id, 404, search).
 */
class Tracker {

	/**
	 * Hook in.
	 */
	public static function init() {
		add_action( 'wp_footer', array( __CLASS__, 'output' ), 99 );
		add_shortcode( 'nemvo_analytics_optout', array( __CLASS__, 'optout' ) );
	}

	/**
	 * Attributes that keep caching and optimization plugins from delaying,
	 * combining or rewriting the tracker.
	 *
	 * @return array
	 */
	private static function script_attributes() {
		return array(
			'id'                => 'nva-tracker',
			'data-cfasync'      => 'false',
			'data-no-optimize'  => '1',
			'data-no-defer'     => '1',
			'data-no-minify'    => '1',
			'data-noptimize'    => '1',
			'nowprocket'        => true,
			'data-pagespeed-no-defer' => true,
		);
	}

	/**
	 * [nemvo_analytics_optout]: a checkbox visitors can untick to stop being
	 * counted in this browser (for your privacy policy page).
	 *
	 * @param array $atts Shortcode attributes: label.
	 * @return string
	 */
	public static function optout( $atts ) {
		$atts = shortcode_atts(
			array(
				'label' => __( 'Include my visits in this site\'s anonymous statistics.', 'nemvo-analytics' ),
			),
			$atts,
			'nemvo_analytics_optout'
		);
		$js   = '(function(){var b=document.querySelectorAll("[data-nva-optout]");b.forEach(function(c){try{c.checked=localStorage.getItem("nva_ignore")!=="1";}catch(e){c.disabled=true;}c.addEventListener("change",function(){try{if(c.checked){localStorage.removeItem("nva_ignore");}else{localStorage.setItem("nva_ignore","1");}}catch(e){}});});})();';
		return '<label class="nva-optout"><input type="checkbox" data-nva-optout checked> ' . esc_html( $atts['label'] ) . '</label>' . wp_get_inline_script_tag( $js );
	}

	/**
	 * Whether the current visitor should be tracked at all.
	 *
	 * @return bool
	 */
	public static function should_track() {
		if ( ! Settings::get( 'tracking_enabled' ) || is_admin() || is_preview() || is_customize_preview() || wp_doing_ajax() ) {
			return false;
		}
		if ( is_user_logged_in() ) {
			$excluded = (array) Settings::get( 'exclude_roles' );
			if ( array_intersect( $excluded, (array) wp_get_current_user()->roles ) ) {
				return false;
			}
		}
		/**
		 * Filters whether the current request is tracked.
		 *
		 * @param bool $track Whether to print the tracker.
		 */
		return (bool) apply_filters( 'nemvo_analytics_should_track', true );
	}

	/**
	 * Page context the browser cannot know.
	 *
	 * @return array
	 */
	private static function context() {
		$ctx = array(
			'type' => 'other',
			'id'   => 0,
		);
		if ( is_404() ) {
			$ctx['type'] = '404';
			$ctx['nf']   = 1;
		} elseif ( is_search() ) {
			$ctx['type'] = 'search';
			if ( Settings::get( 'track_search' ) ) {
				$ctx['sq'] = mb_substr( get_search_query( false ), 0, 100 );
			}
		} elseif ( is_front_page() || is_home() ) {
			$ctx['type'] = 'home';
		} elseif ( is_singular() ) {
			$ctx['type'] = get_post_type();
			$ctx['id']   = get_queried_object_id();
		} elseif ( is_category() || is_tag() || is_tax() ) {
			$ctx['type'] = 'taxonomy';
		} elseif ( is_author() ) {
			$ctx['type'] = 'author';
		} elseif ( is_archive() ) {
			$ctx['type'] = 'archive';
		}
		return $ctx;
	}

	/**
	 * Print the tracker in the footer.
	 */
	public static function output() {
		if ( ! self::should_track() ) {
			return;
		}

		$config = array(
			'endpoint'  => esc_url_raw( rest_url( Collector::NS . '/e' ) ),
			'dnt'       => (bool) Settings::get( 'respect_dnt' ),
			'outbound'  => (bool) Settings::get( 'track_outbound' ),
			'downloads' => Settings::get( 'track_downloads' ) ? explode( ',', Settings::get( 'download_extensions' ) ) : array(),
			'forms'     => (bool) Settings::get( 'track_forms' ),
			'contact'   => (bool) Settings::get( 'track_contact_links' ),
			'scroll'    => (bool) Settings::get( 'track_scroll' ),
			'ctx'       => self::context(),
			'cap'       => Cap::enabled() ? Cap::client_config() : null,
		);

		$json = wp_json_encode( $config );
		$file = NEMVO_ANALYTICS_DIR . 'assets/js/tracker.min.js';
		if ( ! is_readable( $file ) ) {
			$file = NEMVO_ANALYTICS_DIR . 'assets/js/tracker.js';
		}

		if ( Settings::get( 'inline_script' ) && is_readable( $file ) ) {
			// Inline: no extra request, nothing for URL-based blockers to match.
			$js = file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			wp_print_inline_script_tag( 'window.nvaConfig=' . $json . ";\n" . $js, self::script_attributes() );
			return;
		}

		wp_print_inline_script_tag( 'window.nvaConfig=' . $json . ';', array( 'id' => 'nva-config' ) + self::script_attributes() );
		wp_print_script_tag(
			array(
				'src'   => NEMVO_ANALYTICS_URL . 'assets/js/tracker.js?ver=' . NEMVO_ANALYTICS_VERSION,
				'defer' => true,
			) + self::script_attributes()
		);
	}
}
