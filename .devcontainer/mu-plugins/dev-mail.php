<?php
/**
 * Plugin Name: Dev mail to Mailpit
 * Description: Development only. Delivers all WordPress mail to the Mailpit container.
 */

defined( 'ABSPATH' ) || exit;

add_action(
	'phpmailer_init',
	static function ( $phpmailer ) {
		$phpmailer->isSMTP();
		$phpmailer->Host        = 'mailpit';
		$phpmailer->Port        = 1025;
		$phpmailer->SMTPAuth    = false;
		$phpmailer->SMTPAutoTLS = false;
	}
);

// The default sender, wordpress@localhost, is not a valid address.
add_filter(
	'wp_mail_from',
	static function () {
		return 'wordpress@nemvo.test';
	}
);
