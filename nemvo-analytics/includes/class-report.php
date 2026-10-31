<?php
/**
 * Weekly email report.
 *
 * @package NemvoAnalytics
 */

namespace NemvoAnalytics;

defined( 'ABSPATH' ) || exit;

/**
 * A short HTML summary of the last seven days, compared with the seven
 * days before.
 */
class Report {

	/**
	 * Build and send the report.
	 *
	 * @param string|null $to Recipients (defaults to the configured list).
	 * @return bool
	 */
	public static function send( $to = null ) {
		$to = $to ? $to : (string) Settings::get( 'report_recipients' );
		$db = DB::conn();
		if ( '' === trim( $to ) || ! $db ) {
			return false;
		}
		$html = self::render( $db );
		/* translators: %s: site name */
		$subject = sprintf( __( '[%s] Weekly analytics report', 'nemvo-analytics' ), wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ) );
		return wp_mail( $to, $subject, $html, array( 'Content-Type: text/html; charset=UTF-8' ) );
	}

	/**
	 * Render the email body.
	 *
	 * @param \wpdb $db Connection.
	 * @return string
	 */
	public static function render( $db ) {
		$end   = current_datetime()->modify( '-1 day' );
		$to    = $end->format( 'Y-m-d' );
		$from  = $end->modify( '-6 days' )->format( 'Y-m-d' );
		$pto   = $end->modify( '-7 days' )->format( 'Y-m-d' );
		$pfrom = $end->modify( '-13 days' )->format( 'Y-m-d' );

		$stats = new Stats( $db, $from, $to );
		$now   = $stats->overview();
		$prev  = ( new Stats( $db, $pfrom, $pto ) )->overview();

		$kpis = array(
			'visitors'     => array( __( 'Visitors', 'nemvo-analytics' ), true, 'number' ),
			'pageviews'    => array( __( 'Pageviews', 'nemvo-analytics' ), true, 'number' ),
			'avg_duration' => array( __( 'Visit duration', 'nemvo-analytics' ), true, 'duration' ),
			'bounce_rate'  => array( __( 'Bounce rate', 'nemvo-analytics' ), false, 'percent' ),
			'conversions'  => array( __( 'Conversions', 'nemvo-analytics' ), true, 'number' ),
		);

		$ink   = '#0b0b0b';
		$muted = '#52514e';
		$line  = '#e1e0d9';
		$font  = 'font-family:system-ui,-apple-system,Segoe UI,sans-serif;';

		$cells = '';
		foreach ( $kpis as $key => $def ) {
			$value = self::format( $now[ $key ], $def[2] );
			$delta = '';
			if ( $prev[ $key ] ) {
				$change = ( $now[ $key ] - $prev[ $key ] ) / $prev[ $key ];
				$good   = ( $change >= 0 ) === $def[1];
				$delta  = sprintf(
					'<div style="font-size:12px;color:%s">%s %s%%</div>',
					$good ? '#006300' : '#d03b3b',
					$change >= 0 ? '&#9650;' : '&#9660;',
					esc_html( number_format_i18n( abs( $change ) * 100, 1 ) )
				);
			}
			$cells .= sprintf(
				'<td style="padding:12px 16px 12px 0;vertical-align:top"><div style="font-size:12px;color:%s">%s</div><div style="font-size:22px;font-weight:600;color:%s">%s</div>%s</td>',
				$muted,
				esc_html( $def[0] ),
				$ink,
				esc_html( $value ),
				$delta
			);
		}

		$lists = array(
			__( 'Top pages', 'nemvo-analytics' )   => array( $stats->breakdown( 'page', array( 'limit' => 5 ) )['rows'], 'pageviews' ),
			__( 'Top sources', 'nemvo-analytics' ) => array( $stats->breakdown( 'source', array( 'limit' => 5 ) )['rows'], 'visitors' ),
			__( 'Countries', 'nemvo-analytics' )   => array( $stats->breakdown( 'country', array( 'limit' => 5 ) )['rows'], 'visitors' ),
			__( 'Companies', 'nemvo-analytics' )   => array( $stats->breakdown( 'company', array( 'limit' => 10, 'sort' => 'visits' ) )['rows'], 'visits' ),
		);
		$countries = Geo::countries();

		$sections = '';
		foreach ( $lists as $title => $list ) {
			if ( ! $list[0] ) {
				continue;
			}
			$rows = '';
			foreach ( $list[0] as $row ) {
				$label = $row['label'];
				if ( __( 'Countries', 'nemvo-analytics' ) === $title ) {
					$label = $countries[ $label ] ?? __( 'Unknown', 'nemvo-analytics' );
				}
				$rows .= sprintf(
					'<tr><td style="padding:6px 0;border-top:1px solid %s;color:%s">%s</td><td style="padding:6px 0;border-top:1px solid %s;text-align:right;color:%s">%s</td></tr>',
					$line,
					$ink,
					esc_html( $label ),
					$line,
					$ink,
					esc_html( number_format_i18n( $row[ $list[1] ] ) )
				);
			}
			$sections .= sprintf( '<h3 style="font-size:14px;margin:24px 0 8px;color:%s">%s</h3><table width="100%%" cellspacing="0" cellpadding="0" style="font-size:13px">%s</table>', $ink, esc_html( $title ), $rows );
		}

		return sprintf(
			'<div style="%sbackground:#f9f9f7;padding:24px"><div style="max-width:640px;margin:0 auto;background:#fcfcfb;border:1px solid %s;border-radius:12px;padding:24px">'
			. '<div style="font-size:12px;color:%s">%s</div><h2 style="margin:4px 0 8px;font-size:20px;color:%s">%s</h2>'
			. '<table cellspacing="0" cellpadding="0"><tr>%s</tr></table>%s'
			. '<p style="margin-top:24px"><a href="%s" style="color:#2a78d6">%s</a></p></div></div>',
			$font,
			$line,
			$muted,
			esc_html( get_bloginfo( 'name' ) ),
			$ink,
			/* translators: 1: start date, 2: end date */
			esc_html( sprintf( __( 'Analytics for %1$s – %2$s', 'nemvo-analytics' ), date_i18n( get_option( 'date_format' ), strtotime( $from ) ), date_i18n( get_option( 'date_format' ), strtotime( $to ) ) ) ),
			$cells,
			$sections,
			esc_url( admin_url( 'admin.php?page=nemvo-analytics#range=7d' ) ),
			esc_html__( 'Open the dashboard', 'nemvo-analytics' )
		);
	}

	/**
	 * Format a metric.
	 *
	 * @param float  $value Value.
	 * @param string $type  number|percent|duration.
	 * @return string
	 */
	private static function format( $value, $type ) {
		if ( 'percent' === $type ) {
			return number_format_i18n( $value * 100, 1 ) . '%';
		}
		if ( 'duration' === $type ) {
			$value = (int) $value;
			return $value >= 60 ? sprintf( '%dm %02ds', intdiv( $value, 60 ), $value % 60 ) : $value . 's';
		}
		return number_format_i18n( $value );
	}
}
