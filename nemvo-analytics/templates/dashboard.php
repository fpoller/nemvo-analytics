<?php
/**
 * Dashboard and realtime screens. The markup is built by assets/js/dashboard.js.
 *
 * @package NemvoAnalytics
 */

namespace NemvoAnalytics;

defined( 'ABSPATH' ) || exit;
?>
<div class="wrap nva-wrap">
	<h1 class="screen-reader-text"><?php esc_html_e( 'Analytics', 'nemvo-analytics' ); ?></h1>
	<div id="nva-app" class="nva-app" aria-busy="true"></div>
	<noscript><p><?php esc_html_e( 'The analytics dashboard needs JavaScript.', 'nemvo-analytics' ); ?></p></noscript>
</div>
