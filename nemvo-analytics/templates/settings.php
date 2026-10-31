<?php
/**
 * Settings screen.
 *
 * Included from Admin::render_settings(), so the variables below are local
 * to that method, not globals.
 *
 * @package NemvoAnalytics
 */

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- local variables of Admin::render_settings().

namespace NemvoAnalytics;

defined( 'ABSPATH' ) || exit;

$settings = Settings::get();
$services = Settings::services();
$db_form  = Settings::db_settings();
$roles    = wp_roles()->get_names();
$opt      = Settings::OPTION;
$db_opt   = Settings::DB_OPTION;
$svc_opt  = Settings::SERVICES_OPTION;
$active   = DB::active();
$local    = DB::status( 'local' );
$external = DB::status( 'external' );
$ext_cfg  = DB::external_config();
$db_lock  = $ext_cfg && 'settings' !== $ext_cfg['source'];
$queue    = Queue::config();
$q_lock   = $queue && 'settings' !== $queue['source'];
$q_len    = $queue ? Queue::length() : null;
$q_state  = get_option( Queue::STATE_OPTION, array() );
$cap_lock = '' !== Settings::env( 'NEMVO_ANALYTICS_CAP_URL' );
$geo      = Geo::database_info( 'geo' );
$asn      = Geo::database_info( 'asn' );
$checks   = Health::checks();
$migrated = get_option( 'nemvo_analytics_migrated' );
$inactive = 'local' === $active ? $external : $local;

$sources = array(
	'constant'  => __( 'constants in wp-config.php', 'nemvo-analytics' ),
	'env'       => __( 'environment variables (e.g. Docker Compose)', 'nemvo-analytics' ),
	'settings'  => __( 'the credentials saved on this page', 'nemvo-analytics' ),
	'wordpress' => __( 'WordPress itself', 'nemvo-analytics' ),
);

// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display only.
$report_notice = isset( $_GET['nva_report'] ) ? sanitize_key( $_GET['nva_report'] ) : '';

$checkbox = static function ( $key, $label, $help = '', $option = null, $values = null ) use ( $settings, $opt ) {
	$option = $option ? $option : $opt;
	$values = null === $values ? $settings : $values;
	printf(
		'<label class="nva-check"><input type="checkbox" name="%1$s[%2$s]" value="1" %3$s> <span>%4$s%5$s</span></label>',
		esc_attr( $option ),
		esc_attr( $key ),
		checked( ! empty( $values[ $key ] ), true, false ),
		esc_html( $label ),
		$help ? '<small>' . esc_html( $help ) . '</small>' : ''
	);
};

$role_boxes = static function ( $key ) use ( $settings, $opt, $roles ) {
	echo '<div class="nva-checks nva-checks--inline">';
	foreach ( $roles as $role => $name ) {
		printf(
			'<label class="nva-check"><input type="checkbox" name="%1$s[%2$s][]" value="%3$s" %4$s> <span>%5$s</span></label>',
			esc_attr( $opt ),
			esc_attr( $key ),
			esc_attr( $role ),
			checked( in_array( $role, (array) $settings[ $key ], true ), true, false ),
			esc_html( translate_user_role( $name ) )
		);
	}
	echo '</div>';
};

$code = static function ( $id, $text, $title = '' ) {
	printf(
		'<div class="nva-code">%4$s<button type="button" class="nva-code__copy" data-nva-copy="%1$s">%2$s</button><pre id="%1$s"><code>%3$s</code></pre></div>',
		esc_attr( $id ),
		esc_html__( 'Copy', 'nemvo-analytics' ),
		esc_html( $text ),
		$title ? '<div class="nva-code__title">' . esc_html( $title ) . '</div>' : ''
	);
};

$badge = static function ( $state, $text ) {
	$icons = array(
		'good'     => '<path d="M3.5 8.5l3 3 6-7" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>',
		'warning'  => '<path d="M8 4.5v4.5M8 11.5v.01" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>',
		'critical' => '<path d="M4.5 4.5l7 7M11.5 4.5l-7 7" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>',
		'neutral'  => '<circle cx="8" cy="8" r="2.5" fill="currentColor"/>',
	);
	printf(
		'<span class="nva-badge is-%1$s"><svg viewBox="0 0 16 16" width="14" height="14" aria-hidden="true">%2$s</svg>%3$s</span>',
		esc_attr( $state ),
		$icons[ $state ], // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG above.
		esc_html( $text )
	);
};

$field = static function ( $option, $key, $label, $value, $args = array() ) {
	$args = array_merge(
		array(
			'type'        => 'text',
			'placeholder' => '',
			'help'        => '',
			'attrs'       => '',
		),
		$args
	);
	printf(
		'<label><span>%1$s</span><input type="%2$s" name="%3$s[%4$s]" value="%5$s" placeholder="%6$s" autocomplete="off" data-field="%4$s"%7$s>%8$s</label>',
		esc_html( $label ),
		esc_attr( $args['type'] ),
		esc_attr( $option ),
		esc_attr( $key ),
		esc_attr( $value ),
		esc_attr( $args['placeholder'] ),
		$args['attrs'] ? ' ' . $args['attrs'] : '', // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static attributes from this file.
		$args['help'] ? '<small>' . esc_html( $args['help'] ) . '</small>' : ''
	);
};

$compose_db = <<<'YAML'
services:
  wordpress:
    environment:
      # ... your existing WordPress settings ...
      NEMVO_ANALYTICS_DB_HOST: statsdb
      NEMVO_ANALYTICS_DB_NAME: analytics
      NEMVO_ANALYTICS_DB_USER: analytics
      NEMVO_ANALYTICS_DB_PASSWORD_FILE: /run/secrets/analytics_db_password
      # Optional: start a new site directly in the external database.
      # NEMVO_ANALYTICS_STORAGE: external
    secrets: [analytics_db_password]
    depends_on:
      statsdb: { condition: service_healthy }

  statsdb:
    image: mariadb:11.4
    restart: unless-stopped
    environment:
      MARIADB_DATABASE: analytics
      MARIADB_USER: analytics
      MARIADB_PASSWORD_FILE: /run/secrets/analytics_db_password
      MARIADB_RANDOM_ROOT_PASSWORD: "1"
    secrets: [analytics_db_password]
    volumes: [statsdb:/var/lib/mysql]
    healthcheck:
      test: ["CMD", "healthcheck.sh", "--connect", "--innodb_initialized"]
      interval: 10s
      retries: 10

secrets:
  analytics_db_password:
    file: ./secrets/analytics_db_password.txt   # openssl rand -base64 32 > secrets/analytics_db_password.txt

volumes:
  statsdb:
YAML;

$wpconfig = <<<'PHP'
// Nemvo Analytics: external statistics database.
define( 'NEMVO_ANALYTICS_DB_HOST', 'db.example.com:3306' );
define( 'NEMVO_ANALYTICS_DB_NAME', 'analytics' );
define( 'NEMVO_ANALYTICS_DB_USER', 'analytics' );
define( 'NEMVO_ANALYTICS_DB_PASSWORD', 'a-long-random-password' );
// Optional, default "nemvo_":
// define( 'NEMVO_ANALYTICS_DB_PREFIX', 'nemvo_' );
PHP;

$sql = <<<'SQL'
CREATE DATABASE analytics CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'analytics'@'%' IDENTIFIED BY 'a-long-random-password';
GRANT SELECT, INSERT, UPDATE, DELETE, CREATE, ALTER, INDEX, DROP
  ON analytics.* TO 'analytics'@'%';
SQL;

$compose_queue = <<<'YAML'
x-wordpress: &wordpress
  image: wordpress:php8.3-apache            # the image you already use
  environment: &wordpress-env
    # ... your existing WordPress settings ...
    NEMVO_ANALYTICS_QUEUE_URL: redis://valkey:6379/0
  volumes: &wordpress-volumes
    - wordpress:/var/www/html

services:
  wordpress:
    <<: *wordpress
    depends_on:
      valkey: { condition: service_healthy }

  # Writes queued hits to the database in batches.
  analytics-worker:
    image: wordpress:cli-php8.3
    restart: unless-stopped
    user: "33:33"
    environment: *wordpress-env
    volumes: *wordpress-volumes
    command: wp nemvo-analytics queue work --loop

  valkey:
    image: valkey/valkey:9-alpine
    restart: unless-stopped
    # No persistence: hits only wait for seconds, and never include IP addresses.
    command: valkey-server --save "" --appendonly no --maxmemory 128mb --maxmemory-policy noeviction
    healthcheck:
      test: ["CMD", "valkey-cli", "ping"]
      interval: 5s
YAML;

$cron_queue = <<<'SH'
# Without Docker: run the worker as a service ...
wp --path=/var/www/html nemvo-analytics queue work --loop

# ... or from cron every minute (each run works for up to 30 seconds):
* * * * * www-data wp --path=/var/www/html nemvo-analytics queue work
SH;

$compose_cap = <<<'YAML'
services:
  cap:
    image: tiago2/cap:latest
    restart: unless-stopped
    environment:
      ADMIN_KEY: ${CAP_ADMIN_KEY}               # openssl rand -hex 24, kept in .env
      REDIS_URL: redis://valkey:6379/1
    ports:
      - "127.0.0.1:3000:3000"                  # dashboard, reachable from the server only
    depends_on:
      valkey: { condition: service_healthy }

  valkey:                                      # shared with the queue, if you use it
    image: valkey/valkey:9-alpine
    restart: unless-stopped
    command: valkey-server --save 60 1 --loglevel warning
    healthcheck:
      test: ["CMD", "valkey-cli", "ping"]
      interval: 5s

  wordpress:
    environment:
      # Optional: configure Cap here instead of on this page.
      NEMVO_ANALYTICS_CAP_URL: http://cap:3000
      NEMVO_ANALYTICS_CAP_SITE_KEY: your-site-key
      NEMVO_ANALYTICS_CAP_SECRET_FILE: /run/secrets/cap_secret
YAML;

$cli = <<<'SH'
wp nemvo-analytics status                     # storage, queue, Cap, IP databases, warnings
wp nemvo-analytics install                    # create or repair tables
wp nemvo-analytics migrate --to=external      # move statistics (and --to=local to move back)
wp nemvo-analytics queue work --loop          # the queue worker
wp nemvo-analytics lookup 203.0.113.7         # what would be recorded for an IP
wp nemvo-analytics geoip-update --edition=asn # country | city | asn
wp nemvo-analytics purge --before=2025-01-01
wp nemvo-analytics seed --days=90             # demo traffic, for test sites only
wp nemvo-analytics report --to=you@example.com
SH;

$js_api = <<<'HTML'
<!-- Any element: a click records the event -->
<a href="/book-a-meeting/" data-nva-event="CTA click" data-nva-label="Hero">Book a meeting</a>

<!-- Forms are recorded automatically; name them for nicer reports -->
<form data-nva-label="Newsletter"> ... </form>

<script>
  // From your own code:
  window.nvaTrack && nvaTrack('Booked a call', 'Pricing page');
</script>

<!-- On your privacy policy page: let visitors opt out in their browser -->
[nemvo_analytics_optout]
HTML;

$filters = <<<'PHP'
// Do not track a section of the site.
add_filter( 'nemvo_analytics_should_track', function ( $track ) {
	return $track && ! is_page( 'intranet' );
} );

// Accept hits for an extra domain (e.g. a staging alias).
add_filter( 'nemvo_analytics_allowed_hosts', function ( $hosts ) {
	$hosts[] = 'staging.example.com';
	return $hosts;
} );

// Add referrer spam domains.
add_filter( 'nemvo_analytics_spam_referrers', function ( $domains ) {
	$domains[] = 'spam.example';
	return $domains;
} );
PHP;

$where = static function ( $status ) use ( $sources ) {
	if ( 'local' === $status['target'] ) {
		return sprintf( '%s · %s', $status['name'], $status['prefix'] . '*' );
	}
	/* translators: 1: user@host/database, 2: where the settings come from */
	return sprintf( __( '%1$s, set by %2$s', 'nemvo-analytics' ), $status['user'] . '@' . $status['host'] . '/' . $status['name'], $sources[ $status['source'] ] ?? '' );
};
?>
<div class="wrap nva-wrap nva-settings">
	<header class="nva-top">
		<div class="nva-top__title">
			<span class="nva-top__site"><?php echo esc_html( get_bloginfo( 'name' ) ); ?></span>
			<h1><?php esc_html_e( 'Analytics settings', 'nemvo-analytics' ); ?></h1>
		</div>
		<nav class="nva-top__nav" aria-label="<?php esc_attr_e( 'Analytics', 'nemvo-analytics' ); ?>">
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . Admin::SLUG ) ); ?>"><?php esc_html_e( 'Dashboard', 'nemvo-analytics' ); ?></a>
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . Admin::SLUG_REALTIME ) ); ?>"><?php esc_html_e( 'Realtime', 'nemvo-analytics' ); ?></a>
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . Admin::SLUG_SETTINGS ) ); ?>" aria-current="page"><?php esc_html_e( 'Settings', 'nemvo-analytics' ); ?></a>
		</nav>
	</header>

	<?php settings_errors(); ?>
	<?php if ( 'sent' === $report_notice ) : ?>
		<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'The report was sent to your email address.', 'nemvo-analytics' ); ?></p></div>
	<?php elseif ( 'failed' === $report_notice ) : ?>
		<div class="notice notice-error is-dismissible"><p><?php esc_html_e( 'The report could not be sent. Check that WordPress can send email and that the statistics database is reachable.', 'nemvo-analytics' ); ?></p></div>
	<?php endif; ?>

	<div class="nva-settings__layout">
		<nav class="nva-settings__nav" aria-label="<?php esc_attr_e( 'Settings sections', 'nemvo-analytics' ); ?>">
			<a href="#setup"><?php esc_html_e( 'Setup', 'nemvo-analytics' ); ?></a>
			<a href="#tracking"><?php esc_html_e( 'Tracking', 'nemvo-analytics' ); ?></a>
			<a href="#goals"><?php esc_html_e( 'Goals', 'nemvo-analytics' ); ?></a>
			<a href="#networks"><?php esc_html_e( 'Companies & networks', 'nemvo-analytics' ); ?></a>
			<a href="#geo"><?php esc_html_e( 'Geolocation', 'nemvo-analytics' ); ?></a>
			<a href="#bots"><?php esc_html_e( 'Automated traffic', 'nemvo-analytics' ); ?></a>
			<a href="#database"><?php esc_html_e( 'Database', 'nemvo-analytics' ); ?></a>
			<a href="#queue"><?php esc_html_e( 'Queue', 'nemvo-analytics' ); ?></a>
			<a href="#privacy"><?php esc_html_e( 'Privacy & retention', 'nemvo-analytics' ); ?></a>
			<a href="#access"><?php esc_html_e( 'Access', 'nemvo-analytics' ); ?></a>
			<a href="#reports"><?php esc_html_e( 'Email reports', 'nemvo-analytics' ); ?></a>
			<a href="#data"><?php esc_html_e( 'Data', 'nemvo-analytics' ); ?></a>
			<a href="#developers"><?php esc_html_e( 'Developers', 'nemvo-analytics' ); ?></a>
		</nav>

		<form method="post" action="options.php" class="nva-settings__form">
			<?php settings_fields( 'nemvo_analytics' ); ?>

			<section id="setup" class="nva-card nva-section">
				<h2><?php esc_html_e( 'Setup checklist', 'nemvo-analytics' ); ?></h2>
				<p class="nva-lead"><?php esc_html_e( 'Nemvo Analytics works as soon as it is activated. These checks show what is worth a look on this site; the same checks appear under Tools › Site Health.', 'nemvo-analytics' ); ?></p>

				<div class="nva-diag" id="nva-diag">
					<h3><?php esc_html_e( 'Check tracking', 'nemvo-analytics' ); ?></h3>
					<p><?php esc_html_e( 'No visits showing up? This loads your home page and sends a test hit from the server, then shows every hit the site receives for 30 minutes, with what happened to it. Browse your site meanwhile, logged out or in a private window.', 'nemvo-analytics' ); ?></p>
					<p class="nva-actions">
						<button type="button" class="button button-primary" data-nva-action="diag-start"><?php esc_html_e( 'Check tracking', 'nemvo-analytics' ); ?></button>
						<button type="button" class="button" data-nva-action="diag-stop" hidden><?php esc_html_e( 'Stop', 'nemvo-analytics' ); ?></button>
						<span class="nva-result" role="status"></span>
					</p>
					<ul class="nva-checklist nva-diag__checks" hidden></ul>
					<div class="nva-diag__live" hidden>
						<p class="nva-muted nva-diag__status"></p>
						<div class="nva-table-wrap">
							<table class="nva-table nva-diag__log">
								<thead>
									<tr>
										<th scope="col"><?php esc_html_e( 'When', 'nemvo-analytics' ); ?></th>
										<th scope="col"><?php esc_html_e( 'Hit', 'nemvo-analytics' ); ?></th>
										<th scope="col"><?php esc_html_e( 'Result', 'nemvo-analytics' ); ?></th>
										<th scope="col"><?php esc_html_e( 'Details', 'nemvo-analytics' ); ?></th>
									</tr>
								</thead>
								<tbody></tbody>
							</table>
						</div>
					</div>
				</div>

				<ul class="nva-checklist">
					<?php foreach ( $checks as $check ) : ?>
						<li class="is-<?php echo esc_attr( $check[0] ); ?>">
							<?php $badge( $check[0], '' ); ?>
							<div>
								<strong><?php echo esc_html( $check[1] ); ?></strong>
								<p><?php echo esc_html( $check[2] ); ?></p>
							</div>
							<a href="#<?php echo esc_attr( $check[3] ); ?>"><?php esc_html_e( 'Settings', 'nemvo-analytics' ); ?></a>
						</li>
					<?php endforeach; ?>
				</ul>
			</section>

			<section id="tracking" class="nva-card nva-section">
				<h2><?php esc_html_e( 'Tracking', 'nemvo-analytics' ); ?></h2>
				<div class="nva-checks">
					<?php $checkbox( 'tracking_enabled', __( 'Collect statistics', 'nemvo-analytics' ), __( 'Turn off to pause collection without losing data.', 'nemvo-analytics' ) ); ?>
					<?php $checkbox( 'server_hits', __( 'Record every page request on the server', 'nemvo-analytics' ), __( 'Counts each page view as it is served, also when the browser never runs the tracker (most bots, blocked scripts). The tracker then confirms the same page view and adds time on page, scroll depth, screen size and human signals; it is never counted twice.', 'nemvo-analytics' ) ); ?>
					<?php $checkbox( 'respect_dnt', __( 'Respect Do Not Track and Global Privacy Control', 'nemvo-analytics' ), __( 'Browsers that send these signals are not counted at all. Brave sends Global Privacy Control by default, and Firefox does in private windows. The statistics are anonymous and cookieless, so you may turn this off.', 'nemvo-analytics' ) ); ?>
					<?php $checkbox( 'inline_script', __( 'Print the tracker inline (6 KB)', 'nemvo-analytics' ), __( 'Saves a request and is not blocked by URL-based filter lists.', 'nemvo-analytics' ) ); ?>
				</div>

				<h3><?php esc_html_e( 'Do not track these logged-in roles', 'nemvo-analytics' ); ?></h3>
				<?php $role_boxes( 'exclude_roles' ); ?>
				<p class="nva-muted">
					<?php
					/* translators: 1: #nva-ignore, 2: #nva-track */
					printf( esc_html__( 'To exclude your own browser without logging in, open any page of the site with %1$s at the end of the address. %2$s undoes it.', 'nemvo-analytics' ), '<code>#nva-ignore</code>', '<code>#nva-track</code>' );
					?>
				</p>

				<div class="nva-fields nva-fields--2">
					<label><span><?php esc_html_e( 'Excluded IP addresses', 'nemvo-analytics' ); ?></span><textarea name="<?php echo esc_attr( $opt ); ?>[exclude_ips]" rows="4" placeholder="203.0.113.10&#10;10.0.0.0/8&#10;2001:db8::/32"><?php echo esc_textarea( $settings['exclude_ips'] ); ?></textarea><small><?php esc_html_e( 'One address or CIDR range per line, e.g. your office.', 'nemvo-analytics' ); ?></small></label>
					<label><span><?php esc_html_e( 'Excluded paths', 'nemvo-analytics' ); ?></span><textarea name="<?php echo esc_attr( $opt ); ?>[exclude_paths]" rows="4" placeholder="/intranet/*&#10;/checkout/thank-you/"><?php echo esc_textarea( $settings['exclude_paths'] ); ?></textarea><small><?php esc_html_e( 'One per line; * matches anything.', 'nemvo-analytics' ); ?></small></label>
					<label><span><?php esc_html_e( 'Session timeout (minutes)', 'nemvo-analytics' ); ?></span><input type="number" min="5" max="240" name="<?php echo esc_attr( $opt ); ?>[session_timeout]" value="<?php echo esc_attr( $settings['session_timeout'] ); ?>"><small><?php esc_html_e( 'A visit ends after this long without activity. 30 is the industry standard.', 'nemvo-analytics' ); ?></small></label>
				</div>

				<h3><?php esc_html_e( 'Automatic events', 'nemvo-analytics' ); ?></h3>
				<div class="nva-checks nva-checks--grid">
					<?php $checkbox( 'track_outbound', __( 'Outbound link clicks', 'nemvo-analytics' ) ); ?>
					<?php $checkbox( 'track_downloads', __( 'File downloads', 'nemvo-analytics' ) ); ?>
					<?php $checkbox( 'track_forms', __( 'Form submissions', 'nemvo-analytics' ), __( 'Including Contact Form 7, WPForms and Gravity Forms.', 'nemvo-analytics' ) ); ?>
					<?php $checkbox( 'track_contact_links', __( 'Email and phone link clicks', 'nemvo-analytics' ) ); ?>
					<?php $checkbox( 'track_404', __( 'Broken links (404 pages)', 'nemvo-analytics' ) ); ?>
					<?php $checkbox( 'track_search', __( 'Site search terms', 'nemvo-analytics' ) ); ?>
					<?php $checkbox( 'track_scroll', __( 'Scroll depth', 'nemvo-analytics' ) ); ?>
				</div>
				<div class="nva-fields">
					<label><span><?php esc_html_e( 'Download file extensions', 'nemvo-analytics' ); ?></span><input type="text" class="large-text" name="<?php echo esc_attr( $opt ); ?>[download_extensions]" value="<?php echo esc_attr( $settings['download_extensions'] ); ?>"></label>
				</div>
				<p class="nva-note"><?php esc_html_e( 'Made for sites without page caching or a CDN in front. If you use a caching or optimization plugin, exclude the script with the id "nva-tracker" from "delay JavaScript", combine and minify features; the tracker already carries the usual opt-out attributes for WP Rocket, LiteSpeed, Autoptimize and Cloudflare Rocket Loader.', 'nemvo-analytics' ); ?></p>
			</section>

			<section id="goals" class="nva-card nva-section">
				<h2><?php esc_html_e( 'Goals', 'nemvo-analytics' ); ?></h2>
				<p class="nva-lead"><?php esc_html_e( 'A goal is a visit that reached a page or triggered an event. Goals drive the conversion numbers on the dashboard and can be used as filters.', 'nemvo-analytics' ); ?></p>
				<table class="nva-goals">
					<thead>
						<tr>
							<th scope="col"><?php esc_html_e( 'Name', 'nemvo-analytics' ); ?></th>
							<th scope="col"><?php esc_html_e( 'When a visit…', 'nemvo-analytics' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Match', 'nemvo-analytics' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Page path or event name', 'nemvo-analytics' ); ?></th>
							<th scope="col"><span class="screen-reader-text"><?php esc_html_e( 'Remove', 'nemvo-analytics' ); ?></span></th>
						</tr>
					</thead>
					<tbody id="nva-goal-rows">
						<?php
						$goal_row = static function ( $i, $goal ) use ( $opt ) {
							$name = $opt . '[goals][' . $i . ']';
							?>
							<tr>
								<td><input type="text" name="<?php echo esc_attr( $name ); ?>[name]" value="<?php echo esc_attr( $goal['name'] ); ?>" aria-label="<?php esc_attr_e( 'Name', 'nemvo-analytics' ); ?>" placeholder="<?php esc_attr_e( 'Requested a quote', 'nemvo-analytics' ); ?>"></td>
								<td>
									<select name="<?php echo esc_attr( $name ); ?>[type]" aria-label="<?php esc_attr_e( 'Type', 'nemvo-analytics' ); ?>">
										<option value="path" <?php selected( $goal['type'], 'path' ); ?>><?php esc_html_e( 'visited a page', 'nemvo-analytics' ); ?></option>
										<option value="event" <?php selected( $goal['type'], 'event' ); ?>><?php esc_html_e( 'triggered an event', 'nemvo-analytics' ); ?></option>
									</select>
								</td>
								<td>
									<select name="<?php echo esc_attr( $name ); ?>[match]" aria-label="<?php esc_attr_e( 'Match', 'nemvo-analytics' ); ?>">
										<option value="exact" <?php selected( $goal['match'], 'exact' ); ?>><?php esc_html_e( 'is', 'nemvo-analytics' ); ?></option>
										<option value="prefix" <?php selected( $goal['match'], 'prefix' ); ?>><?php esc_html_e( 'starts with', 'nemvo-analytics' ); ?></option>
										<option value="contains" <?php selected( $goal['match'], 'contains' ); ?>><?php esc_html_e( 'contains', 'nemvo-analytics' ); ?></option>
									</select>
								</td>
								<td><input type="text" name="<?php echo esc_attr( $name ); ?>[value]" value="<?php echo esc_attr( $goal['value'] ); ?>" aria-label="<?php esc_attr_e( 'Page path or event name', 'nemvo-analytics' ); ?>" placeholder="/contact/thank-you/"></td>
								<td><button type="button" class="button-link nva-goal-remove" aria-label="<?php esc_attr_e( 'Remove goal', 'nemvo-analytics' ); ?>">&times;</button></td>
							</tr>
							<?php
						};
						foreach ( array_values( (array) $settings['goals'] ) as $i => $goal ) {
							$goal_row( $i, $goal );
						}
						?>
					</tbody>
				</table>
				<template id="nva-goal-template">
					<?php
					$goal_row(
						'__i__',
						array(
							'name'  => '',
							'type'  => 'path',
							'match' => 'exact',
							'value' => '',
						)
					);
					?>
				</template>
				<p><button type="button" class="button" id="nva-goal-add"><?php esc_html_e( 'Add goal', 'nemvo-analytics' ); ?></button></p>
				<p class="nva-muted"><?php esc_html_e( 'Built-in event names: Form submit, File download, Outbound link, Email click, Phone click, Comment, 404 and Site search, plus any name you send yourself.', 'nemvo-analytics' ); ?></p>
			</section>

			<section id="networks" class="nva-card nva-section">
				<h2><?php esc_html_e( 'Companies & networks', 'nemvo-analytics' ); ?></h2>
				<p class="nva-lead"><?php esc_html_e( 'Shows which organisations visit: a prospect\'s office, a home connection, a phone on a mobile network, a university or a datacenter. Every network on the internet belongs to an autonomous system (ASN). Companies with their own ASN are named directly. For connections from an ISP, the reverse DNS name of the address separates home lines (e.g. c-83-233-1-2.bbcust.telenor.se) from business lines (e.g. fw.acme.se, which names the company).', 'nemvo-analytics' ); ?></p>
				<ul class="nva-ticks">
					<li><?php esc_html_e( 'Looked up in memory when a visit starts. The IP address and the hostname are not stored; only the network owner, its type and a company name or domain are.', 'nemvo-analytics' ); ?></li>
					<li><?php esc_html_e( 'Hostnames are forward-confirmed (the name must point back to the same address), so nobody can pass themselves off as another company.', 'nemvo-analytics' ); ?></li>
					<li><?php esc_html_e( 'Reverse DNS uses your server\'s own resolver, with a hard time limit. No other service is asked.', 'nemvo-analytics' ); ?></li>
				</ul>
				<div class="nva-status <?php echo $asn ? 'is-ok' : 'is-neutral'; ?>">
					<div class="nva-status__head">
						<?php $badge( $asn ? 'good' : 'warning', $asn ? __( 'Network database installed', 'nemvo-analytics' ) : __( 'No network database yet', 'nemvo-analytics' ) ); ?>
						<span class="nva-status__source">
							<?php
							if ( $asn ) {
								/* translators: 1: database type, 2: build date, 3: size */
								echo esc_html( sprintf( __( '%1$s, built %2$s, %3$s', 'nemvo-analytics' ), $asn['type'], $asn['built'], size_format( $asn['size'], 1 ) ) );
							} else {
								esc_html_e( 'Download the free DB-IP ASN database (10 MB) to identify networks and companies.', 'nemvo-analytics' );
							}
							?>
						</span>
					</div>
					<p class="nva-actions">
						<button type="button" class="button<?php echo $asn ? '' : ' button-primary'; ?>" data-nva-action="geo" data-edition="asn"><?php echo $asn ? esc_html__( 'Update the ASN database', 'nemvo-analytics' ) : esc_html__( 'Download the ASN database', 'nemvo-analytics' ); ?></button>
						<span class="nva-result" role="status"></span>
					</p>
				</div>
				<div class="nva-checks">
					<?php $checkbox( 'network_lookup', __( 'Identify networks and companies', 'nemvo-analytics' ) ); ?>
					<?php $checkbox( 'rdns_lookup', __( 'Use reverse DNS to tell offices from homes on ISP networks', 'nemvo-analytics' ), __( 'One DNS lookup per visit, only for ISP addresses, limited to a fraction of a second.', 'nemvo-analytics' ) ); ?>
				</div>
				<div class="nva-fields nva-fields--2">
					<label><span><?php esc_html_e( 'Your own ASN .mmdb database (optional)', 'nemvo-analytics' ); ?></span><input type="text" name="<?php echo esc_attr( $opt ); ?>[asn_mmdb_path]" value="<?php echo esc_attr( $settings['asn_mmdb_path'] ); ?>" placeholder="/var/lib/GeoIP/GeoLite2-ASN.mmdb"><small><?php esc_html_e( 'For example MaxMind GeoLite2 ASN, kept fresh by geoipupdate.', 'nemvo-analytics' ); ?></small></label>
				</div>
				<h3><?php esc_html_e( 'Try it', 'nemvo-analytics' ); ?></h3>
				<div class="nva-fields nva-fields--inline">
					<label><span><?php esc_html_e( 'IP address', 'nemvo-analytics' ); ?></span><input type="text" id="nva-lookup-ip" placeholder="<?php esc_attr_e( 'Leave empty for your own', 'nemvo-analytics' ); ?>"></label>
					<button type="button" class="button" data-nva-action="lookup"><?php esc_html_e( 'Look up', 'nemvo-analytics' ); ?></button>
					<span class="nva-result" role="status"></span>
				</div>
				<dl class="nva-facts nva-lookup" id="nva-lookup-result" hidden></dl>
			</section>

			<section id="geo" class="nva-card nva-section">
				<h2><?php esc_html_e( 'Geolocation', 'nemvo-analytics' ); ?></h2>
				<p class="nva-lead"><?php esc_html_e( 'Countries are looked up in memory while a hit is processed. The IP address is never written anywhere.', 'nemvo-analytics' ); ?></p>
				<div class="nva-status <?php echo $geo ? 'is-ok' : 'is-neutral'; ?>">
					<div class="nva-status__head">
						<?php $badge( $geo ? 'good' : 'warning', $geo ? __( 'Location database installed', 'nemvo-analytics' ) : __( 'No location database', 'nemvo-analytics' ) ); ?>
						<span class="nva-status__source">
							<?php
							if ( $geo ) {
								/* translators: 1: database type, 2: build date, 3: size */
								echo esc_html( sprintf( __( '%1$s, built %2$s, %3$s', 'nemvo-analytics' ), $geo['type'], $geo['built'], size_format( $geo['size'], 1 ) ) );
							} else {
								esc_html_e( 'Using CDN headers and the browser time zone.', 'nemvo-analytics' );
							}
							?>
						</span>
					</div>
					<p class="nva-actions">
						<button type="button" class="button<?php echo $geo ? '' : ' button-primary'; ?>" data-nva-action="geo" data-edition="country"><?php esc_html_e( 'Countries (4 MB)', 'nemvo-analytics' ); ?></button>
						<button type="button" class="button" data-nva-action="geo" data-edition="city"><?php esc_html_e( 'Countries and cities (130 MB)', 'nemvo-analytics' ); ?></button>
						<span class="nva-result" role="status"></span>
					</p>
					<p class="nva-muted"><?php esc_html_e( 'Free DB-IP Lite databases (CC BY 4.0), downloaded only when you click. One location database is kept at a time.', 'nemvo-analytics' ); ?></p>
				</div>
				<div class="nva-checks">
					<?php $checkbox( 'geo_headers', __( 'Use country headers from a CDN or proxy', 'nemvo-analytics' ), __( 'Cloudflare, CloudFront, Vercel and others.', 'nemvo-analytics' ) ); ?>
					<?php $checkbox( 'geo_timezone', __( 'Fall back to the browser time zone', 'nemvo-analytics' ), __( 'Works without any IP database. Accurate for most countries, but cannot tell apart countries that share a zone.', 'nemvo-analytics' ) ); ?>
					<?php $checkbox( 'geo_auto_update', __( 'Update the downloaded IP databases monthly', 'nemvo-analytics' ) ); ?>
				</div>
				<div class="nva-fields nva-fields--2">
					<label><span><?php esc_html_e( 'Your own location .mmdb database (optional)', 'nemvo-analytics' ); ?></span><input type="text" name="<?php echo esc_attr( $opt ); ?>[geo_mmdb_path]" value="<?php echo esc_attr( $settings['geo_mmdb_path'] ); ?>" placeholder="/var/lib/GeoIP/GeoLite2-City.mmdb"></label>
					<label><span><?php esc_html_e( 'Forwarded visitor addresses', 'nemvo-analytics' ); ?></span>
						<select name="<?php echo esc_attr( $opt ); ?>[trust_proxy]">
							<option value="auto" <?php selected( Privacy::proxy_mode(), 'auto' ); ?>><?php esc_html_e( 'Automatic: trust them from a reverse proxy on a private network (recommended)', 'nemvo-analytics' ); ?></option>
							<option value="always" <?php selected( Privacy::proxy_mode(), 'always' ); ?>><?php esc_html_e( 'Always trust them (behind a CDN such as Cloudflare)', 'nemvo-analytics' ); ?></option>
							<option value="never" <?php selected( Privacy::proxy_mode(), 'never' ); ?>><?php esc_html_e( 'Never: use the connecting address', 'nemvo-analytics' ); ?></option>
						</select>
						<small><?php esc_html_e( 'X-Forwarded-For, X-Real-IP, CF-Connecting-IP and similar. Automatic covers Nginx Proxy Manager, Traefik, Caddy and Docker setups: forwarded addresses are used only when the request comes from a private address, so visitors cannot fake theirs. If your web server already restores visitor addresses (like the official WordPress Docker image does), nothing changes.', 'nemvo-analytics' ); ?></small>
					</label>
					<label><span><?php esc_html_e( 'Precision stored', 'nemvo-analytics' ); ?></span>
						<select name="<?php echo esc_attr( $opt ); ?>[geo_precision]">
							<option value="country" <?php selected( $settings['geo_precision'], 'country' ); ?>><?php esc_html_e( 'Country only', 'nemvo-analytics' ); ?></option>
							<option value="region" <?php selected( $settings['geo_precision'], 'region' ); ?>><?php esc_html_e( 'Country and region', 'nemvo-analytics' ); ?></option>
							<option value="city" <?php selected( $settings['geo_precision'], 'city' ); ?>><?php esc_html_e( 'Country, region and city', 'nemvo-analytics' ); ?></option>
						</select>
					</label>
				</div>
			</section>

			<section id="bots" class="nva-card nva-section">
				<h2><?php esc_html_e( 'Automated traffic', 'nemvo-analytics' ); ?></h2>
				<p class="nva-lead"><?php esc_html_e( 'Every visit is recorded, and gets a bot score from 0 to 100. The dashboard shows people by default; switch it to "Automated" to see crawlers, scrapers, spam and fake hits, and why each was flagged.', 'nemvo-analytics' ); ?></p>
				<table class="nva-signals">
					<thead><tr><th scope="col"><?php esc_html_e( 'Signal', 'nemvo-analytics' ); ?></th><th scope="col" class="nva-num"><?php esc_html_e( 'Points', 'nemvo-analytics' ); ?></th></tr></thead>
					<tbody>
						<?php
						$labels = Bot::labels();
						foreach ( array_filter( Bot::weights() ) as $flag => $weight ) {
							printf( '<tr><td>%1$s</td><td class="nva-num">%2$s</td></tr>', esc_html( $labels[ $flag ] ), esc_html( ( $weight > 0 ? '+' : '' ) . $weight ) );
						}
						?>
					</tbody>
				</table>
				<div class="nva-fields nva-fields--2">
					<label><span><?php esc_html_e( 'Count a visit as automated from this score', 'nemvo-analytics' ); ?></span><input type="number" min="10" max="95" name="<?php echo esc_attr( $opt ); ?>[bot_threshold]" value="<?php echo esc_attr( $settings['bot_threshold'] ); ?>"><small><?php esc_html_e( 'Default 50. A datacenter address alone (40) stays a person, e.g. someone on a VPN.', 'nemvo-analytics' ); ?></small></label>
				</div>
				<div class="nva-checks">
					<?php $checkbox( 'pow_enabled', __( 'Built-in proof of work', 'nemvo-analytics' ), __( 'Each new visit solves a small SHA-256 puzzle in the background (a few dozen milliseconds of idle time, only on HTTPS). Scripts that fake hits can\'t.', 'nemvo-analytics' ) ); ?>
				</div>

				<h3><?php esc_html_e( 'Cap (optional, stronger proof)', 'nemvo-analytics' ); ?></h3>
				<p><?php esc_html_e( 'Cap is an open-source proof-of-work CAPTCHA you host yourself. With Cap on, each new visit solves an invisible Cap challenge instead of the built-in one, and the result is verified by your Cap server. Visitors only talk to your site: WordPress relays the challenge to Cap, so Cap can stay on an internal network. The Cap widget comes with this plugin and is loaded only when a visit needs it.', 'nemvo-analytics' ); ?></p>
				<details class="nva-details">
					<summary><?php esc_html_e( 'Install Cap with Docker Compose', 'nemvo-analytics' ); ?></summary>
					<ol class="nva-steps">
						<li><?php esc_html_e( 'Add the services below to the docker-compose.yml that runs WordPress (so WordPress can reach Cap as http://cap:3000). Put a long random admin key in .env, e.g. CAP_ADMIN_KEY= followed by the output of openssl rand -hex 24. It is the password of Cap\'s dashboard.', 'nemvo-analytics' ); ?></li>
						<li><?php esc_html_e( 'Start it with docker compose up -d.', 'nemvo-analytics' ); ?></li>
						<li><?php esc_html_e( 'Open the dashboard at http://localhost:3000 on the server (e.g. through an SSH tunnel: ssh -L 3000:localhost:3000 your-server), log in with the admin key and create a site key. Copy the site key and the secret key; the secret is shown once.', 'nemvo-analytics' ); ?></li>
						<li><?php esc_html_e( 'Enter http://cap:3000, the site key and the secret below, test, tick "Use Cap" and save.', 'nemvo-analytics' ); ?></li>
					</ol>
					<?php $code( 'nva-compose-cap', $compose_cap, 'docker-compose.yml' ); ?>
				</details>
				<?php if ( $cap_lock ) : ?>
					<p class="nva-note"><?php esc_html_e( 'Cap is configured through NEMVO_ANALYTICS_CAP_* constants or environment variables.', 'nemvo-analytics' ); ?></p>
					<p class="nva-actions"><button type="button" class="button" data-nva-action="cap-test"><?php esc_html_e( 'Test Cap', 'nemvo-analytics' ); ?></button><span class="nva-result" role="status"></span></p>
				<?php else : ?>
					<div class="nva-checks"><?php $checkbox( 'cap_enabled', __( 'Use Cap', 'nemvo-analytics' ), '', $svc_opt, $services ); ?></div>
					<div class="nva-fields nva-fields--2" data-group="cap">
						<?php $field( $svc_opt, 'cap_url', __( 'Cap server, as WordPress reaches it', 'nemvo-analytics' ), $services['cap_url'], array( 'placeholder' => 'http://cap:3000' ) ); ?>
						<?php $field( $svc_opt, 'cap_public_url', __( 'Public Cap address (optional)', 'nemvo-analytics' ), $services['cap_public_url'], array( 'placeholder' => 'https://cap.example.com', 'help' => __( 'Only if browsers should talk to Cap directly instead of through WordPress.', 'nemvo-analytics' ) ) ); ?>
						<?php $field( $svc_opt, 'cap_site_key', __( 'Site key', 'nemvo-analytics' ), $services['cap_site_key'] ); ?>
						<?php $field( $svc_opt, 'cap_secret', __( 'Secret key', 'nemvo-analytics' ), '', array( 'type' => 'password', 'placeholder' => $services['cap_secret'] ? __( 'Unchanged', 'nemvo-analytics' ) : '', 'attrs' => 'autocomplete="new-password"' ) ); ?>
					</div>
					<p class="nva-actions"><button type="button" class="button" data-nva-action="cap-test"><?php esc_html_e( 'Test Cap', 'nemvo-analytics' ); ?></button><span class="nva-result" role="status"></span></p>
				<?php endif; ?>
			</section>

			<section id="database" class="nva-card nva-section">
				<h2><?php esc_html_e( 'Database', 'nemvo-analytics' ); ?></h2>
				<p class="nva-lead"><?php esc_html_e( 'Statistics are stored in the WordPress database by default, in their own tables. That is right for most sites. A separate MySQL or MariaDB database is worth it for busy sites: it keeps the WordPress database and its backups small, and the load of tracking away from your content. You can move statistics there and back at any time.', 'nemvo-analytics' ); ?></p>

				<div class="nva-storage">
					<?php foreach ( array( $local, $external ) as $st ) : ?>
						<div class="nva-storage__item<?php echo $st['active'] ? ' is-active' : ''; ?>">
							<div class="nva-storage__head">
								<strong><?php echo 'local' === $st['target'] ? esc_html__( 'WordPress database', 'nemvo-analytics' ) : esc_html__( 'External database', 'nemvo-analytics' ); ?></strong>
								<?php
								if ( $st['active'] ) {
									$badge( $st['connected'] ? 'good' : 'critical', __( 'In use', 'nemvo-analytics' ) );
								} elseif ( ! $st['configured'] ) {
									$badge( 'neutral', __( 'Not set up', 'nemvo-analytics' ) );
								} else {
									$badge( $st['connected'] ? 'neutral' : 'critical', $st['connected'] ? __( 'Ready', 'nemvo-analytics' ) : __( 'Not reachable', 'nemvo-analytics' ) );
								}
								?>
							</div>
							<?php if ( $st['configured'] ) : ?>
								<p class="nva-muted"><?php echo esc_html( $where( $st ) ); ?></p>
								<?php if ( $st['connected'] ) : ?>
									<p class="nva-muted">
										<?php
										$rows = array_sum( wp_list_pluck( $st['tables'], 'rows' ) );
										/* translators: 1: server version, 2: rows, 3: size */
										echo esc_html( sprintf( __( '%1$s · ~%2$s rows · %3$s', 'nemvo-analytics' ), $st['server'], number_format_i18n( $rows ), size_format( $st['bytes'], 1 ) ? size_format( $st['bytes'], 1 ) : '0 B' ) );
										?>
									</p>
								<?php else : ?>
									<p class="nva-status__error"><?php echo esc_html( $st['error'] ); ?></p>
								<?php endif; ?>
							<?php else : ?>
								<p class="nva-muted"><?php esc_html_e( 'Set it up with Docker Compose, wp-config.php or the form below.', 'nemvo-analytics' ); ?></p>
							<?php endif; ?>
						</div>
					<?php endforeach; ?>
				</div>

				<div class="nva-migrate" id="nva-migrate" data-active="<?php echo esc_attr( $active ); ?>">
					<?php if ( $external['configured'] && $external['connected'] && $local['connected'] ) : ?>
						<p class="nva-actions">
							<button type="button" class="button button-primary" data-nva-action="migrate" data-to="<?php echo 'local' === $active ? 'external' : 'local'; ?>">
								<?php echo 'local' === $active ? esc_html__( 'Move statistics to the external database', 'nemvo-analytics' ) : esc_html__( 'Move statistics back to the WordPress database', 'nemvo-analytics' ); ?>
							</button>
							<span class="nva-result" role="status"></span>
						</p>
						<div class="nva-progress" hidden><div class="nva-progress__bar"><span></span></div><p class="nva-muted"></p></div>
						<p class="nva-muted"><?php esc_html_e( 'All visits are copied in the background while tracking continues, then the plugin switches over. The old copy is kept until you delete it.', 'nemvo-analytics' ); ?></p>
					<?php endif; ?>
					<?php if ( $inactive['connected'] && $inactive['tables'] && array_sum( wp_list_pluck( $inactive['tables'], 'rows' ) ) > 0 && $migrated ) : ?>
						<p class="nva-actions">
							<button type="button" class="button" data-nva-action="purge-inactive"><?php echo 'local' === $active ? esc_html__( 'Delete the old copy in the external database', 'nemvo-analytics' ) : esc_html__( 'Delete the old copy in the WordPress database', 'nemvo-analytics' ); ?></button>
							<span class="nva-result" role="status"></span>
						</p>
					<?php endif; ?>
					<p class="nva-actions"><button type="button" class="button" data-nva-action="install"><?php esc_html_e( 'Create or repair tables', 'nemvo-analytics' ); ?></button><span class="nva-result" role="status"></span></p>
				</div>

				<h3><?php esc_html_e( 'Set up an external database', 'nemvo-analytics' ); ?></h3>
				<details class="nva-details">
					<summary><?php esc_html_e( 'With Docker Compose (recommended)', 'nemvo-analytics' ); ?></summary>
					<ol class="nva-steps">
						<li><?php esc_html_e( 'Add a database service next to WordPress and give WordPress the NEMVO_ANALYTICS_DB_* variables. The _FILE variants read Docker secrets.', 'nemvo-analytics' ); ?></li>
						<li><?php esc_html_e( 'Put a long random password in secrets/analytics_db_password.txt.', 'nemvo-analytics' ); ?></li>
						<li><?php esc_html_e( 'Run docker compose up -d, reload this page and click "Move statistics to the external database".', 'nemvo-analytics' ); ?></li>
					</ol>
					<?php $code( 'nva-compose-db', $compose_db, 'docker-compose.yml' ); ?>
					<p class="nva-muted"><?php esc_html_e( 'Using an existing MySQL or MariaDB server instead? Point NEMVO_ANALYTICS_DB_HOST at it (host:port works) and create the database and user like this:', 'nemvo-analytics' ); ?></p>
					<?php $code( 'nva-sql', $sql ); ?>
				</details>
				<details class="nva-details">
					<summary><?php esc_html_e( 'With wp-config.php', 'nemvo-analytics' ); ?></summary>
					<p><?php esc_html_e( 'Add these constants above the "That\'s all, stop editing!" line. Constants win over environment variables and over this page.', 'nemvo-analytics' ); ?></p>
					<?php $code( 'nva-wpconfig', $wpconfig, 'wp-config.php' ); ?>
				</details>
				<details class="nva-details"<?php echo ! $db_lock && $db_form['name'] ? ' open' : ''; ?>>
					<summary><?php esc_html_e( 'On this page', 'nemvo-analytics' ); ?></summary>
					<?php if ( $db_lock ) : ?>
						<p class="nva-note"><?php echo esc_html( sprintf( /* translators: %s: configuration source */ __( 'The external database is configured by %s, so these fields are not used.', 'nemvo-analytics' ), $sources[ $ext_cfg['source'] ] ) ); ?></p>
					<?php else : ?>
						<p><?php esc_html_e( 'Handy on managed hosting. The password is stored in the WordPress options table and is not shown again.', 'nemvo-analytics' ); ?></p>
						<div class="nva-fields nva-fields--2" data-group="db">
							<?php $field( $db_opt, 'host', __( 'Host', 'nemvo-analytics' ), $db_form['host'], array( 'placeholder' => 'localhost:3306' ) ); ?>
							<?php $field( $db_opt, 'name', __( 'Database name', 'nemvo-analytics' ), $db_form['name'], array( 'placeholder' => 'analytics' ) ); ?>
							<?php $field( $db_opt, 'user', __( 'User', 'nemvo-analytics' ), $db_form['user'] ); ?>
							<?php $field( $db_opt, 'password', __( 'Password', 'nemvo-analytics' ), '', array( 'type' => 'password', 'placeholder' => $db_form['password'] ? __( 'Unchanged', 'nemvo-analytics' ) : '', 'attrs' => 'autocomplete="new-password"' ) ); ?>
							<?php $field( $db_opt, 'prefix', __( 'Table prefix', 'nemvo-analytics' ), $db_form['prefix'] ); ?>
						</div>
						<p class="nva-actions"><button type="button" class="button" data-nva-action="db-test"><?php esc_html_e( 'Test connection', 'nemvo-analytics' ); ?></button><span class="nva-result" role="status"></span></p>
						<p class="nva-muted"><?php esc_html_e( 'Save first, then move the statistics with the button above.', 'nemvo-analytics' ); ?></p>
					<?php endif; ?>
				</details>
			</section>

			<section id="queue" class="nva-card nva-section">
				<h2><?php esc_html_e( 'Queue', 'nemvo-analytics' ); ?></h2>
				<p class="nva-lead"><?php esc_html_e( 'For traffic peaks: with the queue on, a hit only touches Valkey (or Redis) and is answered at once; a worker writes hits to the database in batches of hundreds, one transaction each. Without it, every hit writes to the database straight away, which is fine up to many thousands of visits a day.', 'nemvo-analytics' ); ?></p>
				<p class="nva-muted"><?php esc_html_e( 'Why Valkey and not RabbitMQ: a queue of hits needs only a fast list. Valkey is a single small container, the plugin talks to it directly with no PHP extension or library, and it can serve Cap as well. If Valkey cannot be reached, hits are written directly, so nothing is lost.', 'nemvo-analytics' ); ?></p>
				<?php if ( $queue ) : ?>
					<div class="nva-status <?php echo null === $q_len ? 'is-error' : 'is-ok'; ?>">
						<div class="nva-status__head">
							<?php $badge( null === $q_len ? 'critical' : 'good', null === $q_len ? __( 'Not reachable', 'nemvo-analytics' ) : __( 'Queue on', 'nemvo-analytics' ) ); ?>
							<span class="nva-status__source">
								<?php
								if ( null === $q_len ) {
									echo esc_html( Queue::error() );
								} else {
									/* translators: 1: host:port, 2: number of waiting hits, 3: time of the last worker run */
									echo esc_html( sprintf( __( '%1$s · %2$s waiting · worker last ran %3$s', 'nemvo-analytics' ), $queue['host'] . ':' . $queue['port'], number_format_i18n( $q_len ), empty( $q_state['last_run'] ) ? __( 'never', 'nemvo-analytics' ) : sprintf( /* translators: %s: time difference */ __( '%s ago', 'nemvo-analytics' ), human_time_diff( (int) $q_state['last_run'] ) ) ) );
								}
								?>
							</span>
						</div>
						<p class="nva-actions"><button type="button" class="button" data-nva-action="queue-work"><?php esc_html_e( 'Write queued hits now', 'nemvo-analytics' ); ?></button><span class="nva-result" role="status"></span></p>
					</div>
				<?php endif; ?>
				<?php if ( $q_lock ) : ?>
					<p class="nva-note"><?php esc_html_e( 'The queue is configured by NEMVO_ANALYTICS_QUEUE_URL.', 'nemvo-analytics' ); ?></p>
				<?php else : ?>
					<div class="nva-checks"><?php $checkbox( 'queue_enabled', __( 'Send hits through the queue', 'nemvo-analytics' ), __( 'Start the worker first (see below). Without it, WP-Cron writes queued hits once a minute.', 'nemvo-analytics' ), $svc_opt, $services ); ?></div>
					<div class="nva-fields nva-fields--2" data-group="queue">
						<?php $field( $svc_opt, 'queue_host', __( 'Valkey host', 'nemvo-analytics' ), $services['queue_host'], array( 'placeholder' => 'valkey' ) ); ?>
						<?php $field( $svc_opt, 'queue_port', __( 'Port', 'nemvo-analytics' ), $services['queue_port'], array( 'type' => 'number' ) ); ?>
						<?php $field( $svc_opt, 'queue_password', __( 'Password (optional)', 'nemvo-analytics' ), '', array( 'type' => 'password', 'placeholder' => $services['queue_password'] ? __( 'Unchanged', 'nemvo-analytics' ) : '', 'attrs' => 'autocomplete="new-password"' ) ); ?>
						<?php $field( $svc_opt, 'queue_db', __( 'Database number', 'nemvo-analytics' ), $services['queue_db'], array( 'type' => 'number' ) ); ?>
						<?php $field( $svc_opt, 'queue_prefix', __( 'Key prefix', 'nemvo-analytics' ), $services['queue_prefix'] ); ?>
					</div>
					<p class="nva-actions"><button type="button" class="button" data-nva-action="queue-test"><?php esc_html_e( 'Test connection', 'nemvo-analytics' ); ?></button><span class="nva-result" role="status"></span></p>
				<?php endif; ?>
				<details class="nva-details">
					<summary><?php esc_html_e( 'Install Valkey and the worker with Docker Compose', 'nemvo-analytics' ); ?></summary>
					<p><?php esc_html_e( 'The worker runs WP-CLI in a second container with the same files and settings as WordPress. Setting NEMVO_ANALYTICS_QUEUE_URL turns the queue on.', 'nemvo-analytics' ); ?></p>
					<?php $code( 'nva-compose-queue', $compose_queue, 'docker-compose.yml' ); ?>
					<?php $code( 'nva-cron-queue', $cron_queue, __( 'Without Docker', 'nemvo-analytics' ) ); ?>
				</details>
			</section>

			<section id="privacy" class="nva-card nva-section">
				<h2><?php esc_html_e( 'Privacy & retention', 'nemvo-analytics' ); ?></h2>
				<ul class="nva-ticks">
					<li><?php esc_html_e( 'No cookies, local storage or fingerprinting, so no consent banner is needed for analytics in most EU interpretations.', 'nemvo-analytics' ); ?></li>
					<li><?php esc_html_e( 'Visitors are counted with a hash of IP address and browser, salted with a random secret that is replaced on a schedule. Old secrets are deleted, so hashes cannot be traced back or linked over time.', 'nemvo-analytics' ); ?></li>
					<li><?php esc_html_e( 'IP addresses and hostnames are never stored. Screen sizes are bucketed and browser versions are dropped.', 'nemvo-analytics' ); ?></li>
					<li><?php esc_html_e( 'Everything stays on your own servers. Nothing is sent to third parties, and the dashboard loads nothing from other sites.', 'nemvo-analytics' ); ?></li>
				</ul>
				<div class="nva-fields nva-fields--2">
					<label><span><?php esc_html_e( 'Replace the visitor salt', 'nemvo-analytics' ); ?></span>
						<select name="<?php echo esc_attr( $opt ); ?>[salt_rotation]">
							<option value="daily" <?php selected( $settings['salt_rotation'], 'daily' ); ?>><?php esc_html_e( 'Daily (most private)', 'nemvo-analytics' ); ?></option>
							<option value="weekly" <?php selected( $settings['salt_rotation'], 'weekly' ); ?>><?php esc_html_e( 'Weekly', 'nemvo-analytics' ); ?></option>
							<option value="monthly" <?php selected( $settings['salt_rotation'], 'monthly' ); ?>><?php esc_html_e( 'Monthly (better unique visitor counts over longer ranges)', 'nemvo-analytics' ); ?></option>
						</select>
						<small><?php esc_html_e( 'A returning visitor is recognised only within one salt period.', 'nemvo-analytics' ); ?></small>
					</label>
					<label><span><?php esc_html_e( 'Keep data for (days)', 'nemvo-analytics' ); ?></span><input type="number" min="0" max="3650" name="<?php echo esc_attr( $opt ); ?>[retention_days]" value="<?php echo esc_attr( $settings['retention_days'] ); ?>"><small><?php esc_html_e( 'Older data is deleted every night. 395 days allows a full year-over-year comparison. 0 keeps everything.', 'nemvo-analytics' ); ?></small></label>
				</div>
				<p class="nva-muted">
					<?php
					/* translators: 1: link to the privacy policy guide, 2: shortcode */
					printf( esc_html__( 'Suggested wording for your privacy policy is in the %1$s. The %2$s shortcode adds an opt-out checkbox to any page.', 'nemvo-analytics' ), '<a href="' . esc_url( admin_url( 'options-privacy.php?tab=policyguide' ) ) . '">' . esc_html__( 'privacy policy guide', 'nemvo-analytics' ) . '</a>', '<code>[nemvo_analytics_optout]</code>' );
					?>
				</p>
			</section>

			<section id="access" class="nva-card nva-section">
				<h2><?php esc_html_e( 'Access', 'nemvo-analytics' ); ?></h2>
				<p><?php esc_html_e( 'Roles that can see the dashboard. Administrators always can; only administrators can change settings.', 'nemvo-analytics' ); ?></p>
				<?php $role_boxes( 'view_roles' ); ?>
			</section>

			<section id="reports" class="nva-card nva-section">
				<h2><?php esc_html_e( 'Email reports', 'nemvo-analytics' ); ?></h2>
				<div class="nva-checks">
					<?php $checkbox( 'report_enabled', __( 'Send a weekly summary', 'nemvo-analytics' ), __( 'Sent on the first day of the week (see Settings › General) at 07:00, comparing the last 7 days with the 7 before.', 'nemvo-analytics' ) ); ?>
				</div>
				<div class="nva-fields">
					<label><span><?php esc_html_e( 'Recipients', 'nemvo-analytics' ); ?></span><input type="text" class="large-text" name="<?php echo esc_attr( $opt ); ?>[report_recipients]" value="<?php echo esc_attr( $settings['report_recipients'] ); ?>"><small><?php esc_html_e( 'Separate addresses with commas.', 'nemvo-analytics' ); ?></small></label>
				</div>
				<p><a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=nemvo_analytics_test_report' ), 'nemvo_analytics_test_report' ) ); ?>"><?php esc_html_e( 'Send me the report now', 'nemvo-analytics' ); ?></a></p>
			</section>

			<section id="data" class="nva-card nva-section">
				<h2><?php esc_html_e( 'Data', 'nemvo-analytics' ); ?></h2>
				<p><?php esc_html_e( 'Raw pageviews can be exported as CSV from the dashboard for any date range.', 'nemvo-analytics' ); ?></p>
				<div class="nva-fields nva-fields--inline">
					<label><span><?php esc_html_e( 'Delete statistics before', 'nemvo-analytics' ); ?></span><input type="date" id="nva-purge-before"></label>
					<button type="button" class="button" data-nva-action="purge"><?php esc_html_e( 'Delete', 'nemvo-analytics' ); ?></button>
					<button type="button" class="button nva-button-danger" data-nva-action="purge-all"><?php esc_html_e( 'Delete all statistics', 'nemvo-analytics' ); ?></button>
					<span class="nva-result" role="status"></span>
				</div>
				<div class="nva-checks">
					<?php $checkbox( 'drop_on_uninstall', __( 'Delete the statistics tables when the plugin is uninstalled', 'nemvo-analytics' ), __( 'Otherwise the data is kept, so a reinstall picks up where it left off.', 'nemvo-analytics' ) ); ?>
				</div>
			</section>

			<section id="developers" class="nva-card nva-section">
				<h2><?php esc_html_e( 'For developers', 'nemvo-analytics' ); ?></h2>
				<h3><?php esc_html_e( 'Custom events', 'nemvo-analytics' ); ?></h3>
				<?php $code( 'nva-js', $js_api ); ?>
				<h3><?php esc_html_e( 'PHP filters', 'nemvo-analytics' ); ?></h3>
				<?php $code( 'nva-filters', $filters ); ?>
				<h3><?php esc_html_e( 'WP-CLI', 'nemvo-analytics' ); ?></h3>
				<?php $code( 'nva-cli', $cli ); ?>
			</section>

			<div class="nva-savebar">
				<?php submit_button( __( 'Save changes', 'nemvo-analytics' ), 'primary', 'submit', false ); ?>
			</div>
		</form>
	</div>
</div>
