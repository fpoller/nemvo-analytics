<?php
/**
 * A wpdb connection to the separate statistics database.
 *
 * @package NemvoAnalytics
 */

namespace NemvoAnalytics;

defined( 'ABSPATH' ) || exit;

/**
 * Same API as $wpdb, but it never takes the site down: the parent
 * constructor and reconnect logic call wp_die()/dead_db() when the server
 * is unreachable, which is right for the WordPress database and wrong for
 * an optional statistics database.
 */
class Connection extends \wpdb {

	/**
	 * Connection error (if any) from open().
	 *
	 * @var string
	 */
	public $connect_error = '';

	/**
	 * Store credentials without connecting.
	 *
	 * @param string $dbuser     User.
	 * @param string $dbpassword Password.
	 * @param string $dbname     Database name.
	 * @param string $dbhost     Host, optionally with :port or :/socket.
	 */
	public function __construct( $dbuser, $dbpassword, $dbname, $dbhost ) {
		// Deliberately not calling parent::__construct(), see class docblock.
		$this->dbuser      = $dbuser;
		$this->dbpassword  = $dbpassword;
		$this->dbname      = $dbname;
		$this->dbhost      = $dbhost;
		$this->show_errors = false;
		$this->init_charset();
	}

	/**
	 * Connect, failing within a few seconds when the server is down (a TCP
	 * connect to a dead host would otherwise hang for the system timeout).
	 *
	 * @return bool
	 */
	public function open() {
		$parsed = $this->parse_db_host( $this->dbhost );
		if ( $parsed && empty( $parsed[2] ) ) {
			list( $host, $port, , $is_ipv6 ) = $parsed;
			$port                            = $port ? (int) $port : 3306;
			$probe                           = @fsockopen( ( $is_ipv6 ? '[' . $host . ']' : $host ), $port, $errno, $errstr, 3 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_fsockopen -- reachability check, the error is reported.
			if ( ! $probe ) {
				/* translators: 1: host:port, 2: error message */
				$this->connect_error = sprintf( __( 'Could not reach %1$s: %2$s', 'nemvo-analytics' ), $host . ':' . $port, $errstr ? $errstr : __( 'no answer', 'nemvo-analytics' ) );
				return false;
			}
			fclose( $probe ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		}
		$ok = @$this->db_connect( false ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged

		if ( ! $ok ) {
			// wpdb discards its handle on failure; this is the only place the reason is kept.
			$error               = mysqli_connect_error(); // phpcs:ignore WordPress.DB.RestrictedFunctions.mysql_mysqli_connect_error -- the error of this wpdb instance's own connection attempt.
			$this->connect_error = $error ? $error : __( 'Could not connect to the database server.', 'nemvo-analytics' );
			return false;
		}
		if ( ! $this->ready ) {
			$this->connect_error = is_wp_error( $this->error ) ? wp_strip_all_tags( $this->error->get_error_message() ) : __( 'Connected, but could not select the database.', 'nemvo-analytics' );
			return false;
		}
		return true;
	}

	/**
	 * Never bail on a lost connection.
	 *
	 * @param bool $allow_bail Ignored.
	 * @return bool
	 */
	public function check_connection( $allow_bail = true ) {
		return parent::check_connection( false );
	}
}
