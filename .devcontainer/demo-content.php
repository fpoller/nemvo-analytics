<?php
/**
 * Creates the demo site's pages and posts (run with `wp eval-file`).
 *
 * Paths and titles come from the demo traffic generator, so seeded and real
 * statistics point at the same URLs. Existing content is left alone.
 *
 * @package NemvoAnalytics
 */

use NemvoAnalytics\Seeder;

defined( 'ABSPATH' ) || exit;

$sentences = array(
	'We help Nordic organisations modernise their infrastructure without disrupting the business that runs on it.',
	'Every engagement starts with a short discovery phase, so the plan is based on how your systems are actually used.',
	'Our consultants have run production platforms themselves, which is why we care about operations as much as architecture.',
	'Security is designed in from the first sprint, not bolted on before the audit.',
	'We document as we go and pair with your team, so the knowledge stays in-house when we leave.',
	'Fixed-scope pilots let you see results within weeks instead of committing to a year-long programme up front.',
	'Costs are tracked from day one, with dashboards that show where every krona of cloud spend goes.',
	'We work with Azure, AWS and on-premises platforms, and recommend whatever fits your constraints best.',
	'Compliance requirements such as NIS2, GDPR and ISO 27001 are mapped to concrete controls and owners.',
	'Automation removes the repetitive work, from provisioning to patching, so your team can focus on what matters.',
);

$paragraphs = static function ( $count, $offset ) use ( $sentences ) {
	$html = '';
	for ( $i = 0; $i < $count; $i++ ) {
		$text  = $sentences[ ( $offset + $i ) % count( $sentences ) ] . ' ' . $sentences[ ( $offset + $i + 3 ) % count( $sentences ) ] . ' ' . $sentences[ ( $offset + $i + 7 ) % count( $sentences ) ];
		$html .= "<!-- wp:paragraph -->\n<p>" . esc_html( $text ) . "</p>\n<!-- /wp:paragraph -->\n\n";
	}
	return $html;
};

$heading = static function ( $text ) {
	return "<!-- wp:heading -->\n<h2 class=\"wp-block-heading\">" . esc_html( $text ) . "</h2>\n<!-- /wp:heading -->\n\n";
};

$html = static function ( $markup ) {
	return "<!-- wp:html -->\n" . $markup . "\n<!-- /wp:html -->\n\n";
};

// A small PDF so the download links work.
$uploads = wp_upload_dir();
$pdf_dir = trailingslashit( $uploads['basedir'] ) . '2026/09';
wp_mkdir_p( $pdf_dir );
$pdf_file = $pdf_dir . '/nis2-readiness-whitepaper.pdf';
if ( ! file_exists( $pdf_file ) ) {
	file_put_contents( $pdf_file, "%PDF-1.4\n1 0 obj<</Type/Catalog/Pages 2 0 R>>endobj 2 0 obj<</Type/Pages/Kids[3 0 R]/Count 1>>endobj 3 0 obj<</Type/Page/MediaBox[0 0 595 842]/Parent 2 0 R>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions
}
$pdf_url = trailingslashit( $uploads['baseurl'] ) . '2026/09/nis2-readiness-whitepaper.pdf';

$extra = array(
	'/'                           => $html( '<p><a class="wp-element-button" href="/book-a-meeting/" data-nva-event="CTA click" data-nva-label="Hero: Book a meeting">Book a meeting</a></p>' ),
	'/contact/'                   => $html(
		'<form action="/contact/thank-you/" method="post" data-nva-label="Contact form">'
		. '<p><label>Name<br><input name="your-name" required></label></p>'
		. '<p><label>Email<br><input type="email" name="your-email" required></label></p>'
		. '<p><label>How can we help?<br><textarea name="your-message" rows="4"></textarea></label></p>'
		. '<p><button type="submit" class="wp-element-button">Send</button></p></form>'
		. '<p>Or email <a href="mailto:hello@nemvo.se">hello@nemvo.se</a> or call <a href="tel:+46852027800">+46 8 520 278 00</a>.</p>'
	),
	'/resources/nis2-whitepaper/' => $html( '<p><a class="wp-element-button" href="' . esc_url( $pdf_url ) . '">Download the whitepaper (PDF)</a></p>' ),
	'/book-a-meeting/'            => $html( '<p><a class="wp-element-button" href="https://example.com/nemvo/intro-call">Pick a time</a></p>' ),
	'/contact/thank-you/'         => $html( '<p>Thanks! We will get back to you within one working day.</p>' ),
);

$ids   = array();
$index = 0;
foreach ( Seeder::pages() as $path => $page ) {
	$index++;
	list( $title, $type ) = $page;
	$content = ( $extra[ $path ] ?? '' ) . $paragraphs( 2, $index ) . $heading( 'How we work' ) . $paragraphs( 3, $index + 2 ) . $heading( 'Why it matters' ) . $paragraphs( 3, $index + 5 );

	if ( 'post' === $type ) {
		$slug     = basename( $path );
		$existing = get_page_by_path( $slug, OBJECT, 'post' );
		if ( ! $existing ) {
			wp_insert_post(
				array(
					'post_type'    => 'post',
					'post_status'  => 'publish',
					'post_title'   => $title,
					'post_name'    => $slug,
					'post_content' => $content,
				)
			);
		}
		continue;
	}

	$slug = '/' === $path ? 'home' : trim( $path, '/' );
	$id   = get_page_by_path( $slug, OBJECT, 'page' );
	if ( $id ) {
		$ids[ $path ] = $id->ID;
		continue;
	}

	// Create missing parents (e.g. /resources/) as plain pages.
	$parent_id = 0;
	$parent    = trim( dirname( $slug ), '/.' );
	if ( '' !== $parent && '/' !== $path ) {
		$parent_page = get_page_by_path( $parent, OBJECT, 'page' );
		$parent_id   = $parent_page ? $parent_page->ID : wp_insert_post(
			array(
				'post_type'   => 'page',
				'post_status' => 'publish',
				'post_title'  => ucfirst( basename( $parent ) ),
				'post_name'   => basename( $parent ),
			)
		);
	}

	$ids[ $path ] = wp_insert_post(
		array(
			'post_type'    => 'page',
			'post_status'  => 'publish',
			'post_title'   => '/' === $path ? 'Home' : $title,
			'post_name'    => basename( $slug ),
			'post_parent'  => $parent_id,
			'post_content' => $content,
			'menu_order'   => $index,
		)
	);
}

update_option( 'show_on_front', 'page' );
update_option( 'page_on_front', $ids['/'] );
update_option( 'page_for_posts', $ids['/blog/'] );
update_option( 'blogdescription', 'IT consulting for the Nordics' );

WP_CLI::success( 'Demo content is in place.' );
