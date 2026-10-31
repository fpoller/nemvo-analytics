<?php
/**
 * Statistics storage: where it is, connections and schema.
 *
 * @package NemvoAnalytics
 */

namespace NemvoAnalytics;

defined( 'ABSPATH' ) || exit;

/**
 * Statistics are stored in one of two places ("targets"):
 *  - local:    the WordPress database, tables {wp_prefix}nemvo_*  (default);
 *  - external: a separate MySQL/MariaDB database, configured with
 *              NEMVO_ANALYTICS_DB_* constants, environment variables or the
 *              settings page.
 * The active target is stored in STORAGE_OPTION and changed by Migration.
 */
class DB {

	const SCHEMA_VERSION = 1;
	const SCHEMA_OPTION  = 'nemvo_analytics_schema';
	const STORAGE_OPTION = 'nemvo_analytics_storage';
	const TABLES         = array( 'sessions', 'pageviews', 'events' );

	/**
	 * Open connections per target (false after a failed attempt).
	 *
	 * @var array
	 */
	private static $conns = array();

	/**
	 * Connection errors per target.
	 *
	 * @var array
	 */
	private static $errors = array();

	/**
	 * Cached active target.
	 *
	 * @var string|null
	 */
	private static $active = null;

	/**
	 * The external database configuration, or null when none is set.
	 *
	 * @return array{source:string,host:string,name:string,user:string,password:string,prefix:string}|null
	 */
	public static function external_config() {
		if ( '' !== Settings::env( 'NEMVO_ANALYTICS_DB_NAME' ) ) {
			$cfg = array(
				'source'   => defined( 'NEMVO_ANALYTICS_DB_NAME' ) ? 'constant' : 'env',
				'host'     => Settings::env( 'NEMVO_ANALYTICS_DB_HOST' ),
				'name'     => Settings::env( 'NEMVO_ANALYTICS_DB_NAME' ),
				'user'     => Settings::env( 'NEMVO_ANALYTICS_DB_USER' ),
				'password' => Settings::env( 'NEMVO_ANALYTICS_DB_PASSWORD' ),
				'prefix'   => Settings::env( 'NEMVO_ANALYTICS_DB_PREFIX' ),
			);
		} else {
			$cfg = Settings::db_settings();
			if ( '' === $cfg['name'] ) {
				return null;
			}
			$cfg['source'] = 'settings';
		}
		$cfg['host']   = '' === $cfg['host'] ? 'localhost' : $cfg['host'];
		$cfg['prefix'] = (string) preg_replace( '/[^A-Za-z0-9_]/', '', (string) $cfg['prefix'] );
		$cfg['prefix'] = '' === $cfg['prefix'] ? 'nemvo_' : $cfg['prefix'];
		return $cfg;
	}

	/**
	 * The active target: what the admin chose through a migration, else
	 * NEMVO_ANALYTICS_STORAGE, else external when wp-config.php or the
	 * environment defines an external database, else local.
	 *
	 * @return string local|external
	 */
	public static function active() {
		if ( null !== self::$active ) {
			return self::$active;
		}
		$external = self::external_config();
		$mode     = get_option( self::STORAGE_OPTION, '' );
		if ( ! in_array( $mode, array( 'local', 'external' ), true ) ) {
			$env  = Settings::env( 'NEMVO_ANALYTICS_STORAGE' );
			$mode = in_array( $env, array( 'local', 'external' ), true ) ? $env : ( $external && 'settings' !== $external['source'] ? 'external' : 'local' );
		}
		self::$active = 'external' === $mode && ! $external ? 'local' : $mode;
		return self::$active;
	}

	/**
	 * Switch the active target (after a migration).
	 *
	 * @param string $target local|external.
	 */
	public static function set_active( $target ) {
		update_option( self::STORAGE_OPTION, $target, true );
		self::$active = $target;
	}

	/**
	 * Whether statistics live outside the WordPress database.
	 *
	 * @return bool
	 */
	public static function is_external() {
		return 'external' === self::active();
	}

	/**
	 * Table name prefix of a target.
	 *
	 * @param string|null $target Target (default: active).
	 * @return string
	 */
	public static function prefix( $target = null ) {
		global $wpdb;
		$target = $target ? $target : self::active();
		if ( 'external' === $target ) {
			$cfg = self::external_config();
			return $cfg ? $cfg['prefix'] : 'nemvo_';
		}
		return $wpdb->prefix . 'nemvo_';
	}

	/**
	 * Fully qualified table name.
	 *
	 * @param string      $name   sessions|pageviews|events|meta.
	 * @param string|null $target Target (default: active).
	 * @return string
	 */
	public static function table( $name, $target = null ) {
		return self::prefix( $target ) . $name;
	}

	/**
	 * The connection of a target, or null when it is unreachable.
	 *
	 * @param string|null $target Target (default: active).
	 * @return \wpdb|null
	 */
	public static function conn( $target = null ) {
		global $wpdb;
		$target = $target ? $target : self::active();
		if ( 'local' === $target ) {
			return $wpdb;
		}
		if ( isset( self::$conns[ $target ] ) ) {
			return self::$conns[ $target ] ? self::$conns[ $target ] : null;
		}
		$cfg = self::external_config();
		if ( ! $cfg ) {
			self::$errors[ $target ] = __( 'No external database is configured.', 'nemvo-analytics' );
			self::$conns[ $target ]  = false;
			return null;
		}
		$conn = new Connection( $cfg['user'], $cfg['password'], $cfg['name'], $cfg['host'] );
		if ( ! $conn->open() ) {
			self::$errors[ $target ] = $conn->connect_error;
			self::$conns[ $target ]  = false;
			return null;
		}
		self::$conns[ $target ] = $conn;
		return $conn;
	}

	/**
	 * Last connection error of a target.
	 *
	 * @param string|null $target Target (default: active).
	 * @return string
	 */
	public static function error( $target = null ) {
		$target = $target ? $target : self::active();
		return self::$errors[ $target ] ?? '';
	}

	/**
	 * Identifies a database so the schema check follows a switch.
	 *
	 * @param string $target Target.
	 * @return string
	 */
	private static function signature( $target ) {
		if ( 'local' === $target ) {
			return 'local|' . self::prefix( 'local' );
		}
		$cfg = self::external_config();
		return md5( ( $cfg ? $cfg['host'] . '|' . $cfg['name'] : '' ) . '|' . self::prefix( 'external' ) );
	}

	/**
	 * Whether a target has an up-to-date schema (cheap: an autoloaded option).
	 *
	 * @param string|null $target Target (default: active).
	 * @return bool
	 */
	public static function schema_ok( $target = null ) {
		$target = $target ? $target : self::active();
		$known  = get_option( self::SCHEMA_OPTION, array() );
		$sig    = self::signature( $target );
		return is_array( $known ) && isset( $known[ $sig ] ) && (int) $known[ $sig ] >= self::SCHEMA_VERSION;
	}

	/**
	 * Install or upgrade the active target when needed.
	 *
	 * @return bool
	 */
	public static function maybe_install() {
		return self::schema_ok() || self::install();
	}

	/**
	 * Create the tables in a target.
	 *
	 * @param string|null $target Target (default: active).
	 * @return bool
	 */
	public static function install( $target = null ) {
		$target = $target ? $target : self::active();
		$db     = self::conn( $target );
		if ( ! $db ) {
			return false;
		}

		$opts = 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
		$s    = self::table( 'sessions', $target );
		$p    = self::table( 'pageviews', $target );
		$e    = self::table( 'events', $target );
		$m    = self::table( 'meta', $target );

		$sql = array(
			"CREATE TABLE IF NOT EXISTS `$s` (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				visitor CHAR(16) NOT NULL,
				started_at DATETIME NOT NULL,
				last_seen DATETIME NOT NULL,
				duration INT UNSIGNED NOT NULL DEFAULT 0,
				pageviews SMALLINT UNSIGNED NOT NULL DEFAULT 0,
				events SMALLINT UNSIGNED NOT NULL DEFAULT 0,
				is_bounce TINYINT(1) NOT NULL DEFAULT 1,
				entry_path VARCHAR(255) NOT NULL DEFAULT '',
				exit_path VARCHAR(255) NOT NULL DEFAULT '',
				exit_pv CHAR(16) NOT NULL DEFAULT '',
				channel VARCHAR(32) NOT NULL DEFAULT '',
				source VARCHAR(100) NOT NULL DEFAULT '',
				referrer_host VARCHAR(191) NOT NULL DEFAULT '',
				referrer_url VARCHAR(255) NOT NULL DEFAULT '',
				utm_source VARCHAR(100) NOT NULL DEFAULT '',
				utm_medium VARCHAR(100) NOT NULL DEFAULT '',
				utm_campaign VARCHAR(150) NOT NULL DEFAULT '',
				utm_term VARCHAR(150) NOT NULL DEFAULT '',
				utm_content VARCHAR(150) NOT NULL DEFAULT '',
				country CHAR(2) NOT NULL DEFAULT '',
				region VARCHAR(100) NOT NULL DEFAULT '',
				city VARCHAR(100) NOT NULL DEFAULT '',
				language VARCHAR(16) NOT NULL DEFAULT '',
				device VARCHAR(16) NOT NULL DEFAULT '',
				os VARCHAR(32) NOT NULL DEFAULT '',
				browser VARCHAR(40) NOT NULL DEFAULT '',
				screen VARCHAR(8) NOT NULL DEFAULT '',
				asn INT UNSIGNED NOT NULL DEFAULT 0,
				org VARCHAR(100) NOT NULL DEFAULT '',
				network VARCHAR(16) NOT NULL DEFAULT '',
				company VARCHAR(100) NOT NULL DEFAULT '',
				bot_score TINYINT UNSIGNED NOT NULL DEFAULT 0,
				bot_flags INT UNSIGNED NOT NULL DEFAULT 0,
				PRIMARY KEY  (id),
				KEY visitor_seen (visitor, last_seen),
				KEY started_at (started_at),
				KEY last_seen (last_seen)
			) $opts",
			"CREATE TABLE IF NOT EXISTS `$p` (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				session_id BIGINT UNSIGNED NOT NULL,
				pv CHAR(16) NOT NULL,
				visitor CHAR(16) NOT NULL,
				created_at DATETIME NOT NULL,
				path VARCHAR(255) NOT NULL DEFAULT '',
				title VARCHAR(255) NOT NULL DEFAULT '',
				post_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
				content_type VARCHAR(32) NOT NULL DEFAULT '',
				prev_path VARCHAR(255) NOT NULL DEFAULT '',
				engaged_ms INT UNSIGNED NOT NULL DEFAULT 0,
				scroll TINYINT UNSIGNED NOT NULL DEFAULT 0,
				is_entry TINYINT(1) NOT NULL DEFAULT 0,
				is_exit TINYINT(1) NOT NULL DEFAULT 1,
				status SMALLINT UNSIGNED NOT NULL DEFAULT 200,
				PRIMARY KEY  (id),
				UNIQUE KEY pv (pv),
				KEY session_id (session_id),
				KEY created_at (created_at),
				KEY path_created (path(191), created_at),
				KEY prev_path (prev_path(191)),
				KEY post_created (post_id, created_at)
			) $opts",
			"CREATE TABLE IF NOT EXISTS `$e` (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				session_id BIGINT UNSIGNED NOT NULL,
				pv CHAR(16) NOT NULL DEFAULT '',
				visitor CHAR(16) NOT NULL,
				created_at DATETIME NOT NULL,
				name VARCHAR(64) NOT NULL,
				label VARCHAR(255) NOT NULL DEFAULT '',
				meta VARCHAR(255) NOT NULL DEFAULT '',
				path VARCHAR(255) NOT NULL DEFAULT '',
				PRIMARY KEY  (id),
				KEY session_id (session_id),
				KEY name_created (name, created_at),
				KEY created_at (created_at)
			) $opts",
			"CREATE TABLE IF NOT EXISTS `$m` (
				meta_key VARCHAR(64) NOT NULL,
				meta_value LONGTEXT NOT NULL,
				PRIMARY KEY  (meta_key)
			) $opts",
		);

		foreach ( $sql as $statement ) {
			if ( false === $db->query( $statement ) ) { // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery -- the plugin's own tables.
				self::$errors[ $target ] = $db->last_error;
				return false;
			}
		}

		$db->replace(
			$m,
			array(
				'meta_key'   => 'schema_version', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value' => (string) self::SCHEMA_VERSION, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
			)
		);

		$known                             = get_option( self::SCHEMA_OPTION, array() );
		$known                             = is_array( $known ) ? $known : array();
		$known[ self::signature( $target ) ] = self::SCHEMA_VERSION;
		update_option( self::SCHEMA_OPTION, $known, true );
		return true;
	}

	/**
	 * Approximate row counts per table, or null when unreachable.
	 *
	 * @param string|null $target Target (default: active).
	 * @param bool        $exact  COUNT(*) instead of table statistics.
	 * @return array|null
	 */
	public static function counts( $target = null, $exact = false ) {
		$target = $target ? $target : self::active();
		$db     = self::conn( $target );
		if ( ! $db ) {
			return null;
		}
		$out = array();
		foreach ( self::TABLES as $name ) {
			$table = self::table( $name, $target );
			if ( $exact ) {
				$n = $db->get_var( "SELECT COUNT(*) FROM `$table`" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
			} else {
				$n = $db->get_var( $db->prepare( 'SELECT TABLE_ROWS FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s', $table ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			}
			$out[ $name ] = null === $n ? 0 : (int) $n;
		}
		return $out;
	}

	/**
	 * Everything the settings page shows about a target.
	 *
	 * @param string|null $target Target (default: active).
	 * @return array
	 */
	public static function status( $target = null ) {
		global $wpdb;
		$target = $target ? $target : self::active();
		$cfg    = 'external' === $target ? self::external_config() : null;
		$status = array(
			'target'    => $target,
			'active'    => self::active() === $target,
			'source'    => $cfg ? $cfg['source'] : ( 'local' === $target ? 'wordpress' : '' ),
			'host'      => $cfg ? $cfg['host'] : DB_HOST,
			'name'      => $cfg ? $cfg['name'] : DB_NAME,
			'user'      => $cfg ? $cfg['user'] : DB_USER,
			'prefix'    => self::prefix( $target ),
			'configured' => 'local' === $target || null !== $cfg,
			'connected' => false,
			'error'     => '',
			'server'    => '',
			'schema'    => self::schema_ok( $target ),
			'tables'    => array(),
			'bytes'     => 0,
			'oldest'    => null,
		);
		if ( ! $status['configured'] ) {
			return $status;
		}

		$db = self::conn( $target );
		if ( ! $db ) {
			$status['error'] = self::error( $target );
			return $status;
		}
		$status['connected'] = true;
		$status['server']    = (string) $db->get_var( 'SELECT VERSION()' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

		$rows = $db->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$db->prepare(
				'SELECT TABLE_NAME AS t, TABLE_ROWS AS r, DATA_LENGTH + INDEX_LENGTH AS b FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN (%s, %s, %s)',
				self::table( 'sessions', $target ),
				self::table( 'pageviews', $target ),
				self::table( 'events', $target )
			)
		);
		foreach ( (array) $rows as $row ) {
			$status['tables'][ $row->t ] = array(
				'rows'  => (int) $row->r,
				'bytes' => (int) $row->b,
			);
			$status['bytes']            += (int) $row->b;
		}
		if ( $status['tables'] ) {
			$status['oldest'] = $db->get_var( 'SELECT MIN(started_at) FROM `' . self::table( 'sessions', $target ) . '`' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
		}
		return $status;
	}

	/**
	 * Try a set of credentials without changing anything.
	 *
	 * @param array $cfg host/name/user/password.
	 * @return array{ok:bool,message:string}
	 */
	public static function test( array $cfg ) {
		$conn = new Connection( $cfg['user'], $cfg['password'], $cfg['name'], $cfg['host'] ? $cfg['host'] : 'localhost' );
		if ( ! $conn->open() ) {
			return array(
				'ok'      => false,
				'message' => $conn->connect_error,
			);
		}
		$version = $conn->get_var( 'SELECT VERSION()' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$grants  = (array) $conn->get_col( 'SHOW GRANTS FOR CURRENT_USER()' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$create  = (bool) preg_match( '/ALL PRIVILEGES|CREATE/i', implode( ' ', $grants ) );
		$conn->close();
		return array(
			'ok'      => true,
			/* translators: %s: database server version */
			'message' => sprintf( __( 'Connected to %s.', 'nemvo-analytics' ), $version ) . ( $create ? '' : ' ' . __( 'Warning: the user may lack the CREATE privilege needed to create tables.', 'nemvo-analytics' ) ),
		);
	}

	/**
	 * Delete everything older than a timestamp, in small batches to keep
	 * locks short.
	 *
	 * @param string $before Local MySQL datetime.
	 * @return int Rows deleted.
	 */
	public static function purge_before( $before ) {
		$db = self::conn();
		if ( ! $db ) {
			return 0;
		}
		$deleted = 0;
		$jobs    = array(
			self::table( 'events' )    => 'created_at',
			self::table( 'pageviews' ) => 'created_at',
			self::table( 'sessions' )  => 'last_seen',
		);
		foreach ( $jobs as $table => $column ) {
			for ( $i = 0; $i < 200; $i++ ) {
				$n        = (int) $db->query( $db->prepare( "DELETE FROM `$table` WHERE `$column` < %s LIMIT 5000", $before ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
				$deleted += $n;
				if ( $n < 5000 ) {
					break;
				}
			}
		}
		return $deleted;
	}

	/**
	 * Remove all statistics from a target (keeps the tables).
	 *
	 * @param string|null $target Target (default: active).
	 * @return bool
	 */
	public static function truncate( $target = null ) {
		$db = self::conn( $target );
		if ( ! $db ) {
			return false;
		}
		foreach ( self::TABLES as $t ) {
			$db->query( 'TRUNCATE TABLE `' . self::table( $t, $target ) . '`' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
		}
		return true;
	}

	/**
	 * Drop the tables of a target (uninstall).
	 *
	 * @param string $target Target.
	 */
	public static function drop( $target ) {
		$db = self::conn( $target );
		if ( ! $db ) {
			return;
		}
		foreach ( array( 'events', 'pageviews', 'sessions', 'meta' ) as $t ) {
			$db->query( 'DROP TABLE IF EXISTS `' . self::table( $t, $target ) . '`' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
		}
	}
}
