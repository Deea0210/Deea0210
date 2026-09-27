<?php
/**
 * Deea Creative theme setup.
 *
 * @package Deea
 */

defined( 'ABSPATH' ) || exit;

define( 'DEEA_VERSION', '1.0.0' );

require get_template_directory() . '/inc/icons.php';
require get_template_directory() . '/inc/customizer.php';
require get_template_directory() . '/inc/services.php';

if ( ! isset( $content_width ) ) {
	$content_width = 760;
}

/**
 * Theme supports and menus.
 */
function deea_setup() {
	load_theme_textdomain( 'deea', get_template_directory() . '/languages' );

	add_theme_support( 'title-tag' );
	add_theme_support( 'automatic-feed-links' );
	add_theme_support( 'post-thumbnails' );
	add_theme_support( 'responsive-embeds' );
	add_theme_support( 'wp-block-styles' );
	add_theme_support( 'align-wide' );
	add_theme_support( 'html5', array( 'search-form', 'comment-form', 'comment-list', 'gallery', 'caption', 'style', 'script', 'navigation-widgets' ) );
	add_theme_support(
		'custom-logo',
		array(
			'height'      => 72,
			'width'       => 240,
			'flex-height' => true,
			'flex-width'  => true,
		)
	);

	register_nav_menus(
		array(
			'primary' => __( 'Main menu', 'deea' ),
			'footer'  => __( 'Footer menu', 'deea' ),
		)
	);
}
add_action( 'after_setup_theme', 'deea_setup' );

/**
 * Optional footer widget area (newsletter sign-up, badges, opening hours…).
 */
function deea_widgets_init() {
	register_sidebar(
		array(
			'name'          => __( 'Footer', 'deea' ),
			'id'            => 'footer-1',
			'description'   => __( 'Shown above the footer bottom bar. Leave empty to hide.', 'deea' ),
			'before_widget' => '<div id="%1$s" class="footer-widget %2$s">',
			'after_widget'  => '</div>',
			'before_title'  => '<h2 class="site-footer__heading">',
			'after_title'   => '</h2>',
		)
	);
}
add_action( 'widgets_init', 'deea_widgets_init' );

/**
 * Styles and scripts.
 */
function deea_assets() {
	$uri = get_template_directory_uri();
	wp_enqueue_style( 'deea-app', $uri . '/assets/css/app.css', array(), DEEA_VERSION );
	wp_enqueue_style( 'deea-theme', $uri . '/assets/css/theme.css', array( 'deea-app' ), DEEA_VERSION );
	wp_enqueue_script(
		'deea-site',
		$uri . '/assets/js/site.js',
		array(),
		DEEA_VERSION,
		array(
			'in_footer' => true,
			'strategy'  => 'defer',
		)
	);
	if ( is_singular() && comments_open() && get_option( 'thread_comments' ) ) {
		wp_enqueue_script( 'comment-reply' );
	}
}
add_action( 'wp_enqueue_scripts', 'deea_assets' );

/**
 * Preload fonts, favicon fallback and theme color.
 */
function deea_head() {
	$fonts = get_template_directory_uri() . '/assets/fonts/';
	printf( '<link rel="preload" href="%s" as="font" type="font/woff2" crossorigin>' . "\n", esc_url( $fonts . 'bricolage-grotesque-latin-wght-normal.woff2' ) );
	printf( '<link rel="preload" href="%s" as="font" type="font/woff2" crossorigin>' . "\n", esc_url( $fonts . 'inter-latin-wght-normal.woff2' ) );
	echo '<meta name="theme-color" content="#16131f">' . "\n";
	if ( ! has_site_icon() ) {
		printf( '<link rel="icon" href="%s" type="image/svg+xml">' . "\n", esc_url( get_template_directory_uri() . '/assets/img/favicon.svg' ) );
	}
}
add_action( 'wp_head', 'deea_head', 2 );

/**
 * Structured data on the front page so Google understands the business.
 */
function deea_schema() {
	if ( ! is_front_page() ) {
		return;
	}
	$offers = array();
	foreach ( deea_get_services() as $service ) {
		$offer = array(
			'@type'       => 'Offer',
			'itemOffered' => array(
				'@type'       => 'Service',
				'name'        => $service['name'],
				'description' => $service['description'],
			),
		);
		if ( '' !== $service['price_from'] ) {
			$offer['priceSpecification'] = array(
				'@type'    => 'PriceSpecification',
				'minPrice' => (float) $service['price_from'],
			);
		}
		$offers[] = $offer;
	}
	$schema = array(
		'@context'        => 'https://schema.org',
		'@type'           => 'ProfessionalService',
		'name'            => get_bloginfo( 'name' ),
		'description'     => deea_opt( 'role' ) . ' — ' . deea_opt( 'tagline' ),
		'url'             => home_url( '/' ),
		'email'           => deea_opt( 'email' ),
		'founder'         => array(
			'@type'    => 'Person',
			'name'     => deea_opt( 'owner_name' ),
			'jobTitle' => deea_opt( 'role' ),
		),
		'sameAs'          => array_values( array_filter( deea_social_links() ) ),
		'potentialAction' => array(
			'@type'  => 'ReserveAction',
			'target' => deea_booking_url(),
		),
		'hasOfferCatalog' => array(
			'@type'           => 'OfferCatalog',
			'name'            => __( 'Services', 'deea' ),
			'itemListElement' => $offers,
		),
	);
	if ( deea_opt( 'phone' ) ) {
		$schema['telephone'] = deea_opt( 'phone' );
	}
	echo '<script type="application/ld+json">' . wp_json_encode( $schema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . "</script>\n";
}
add_action( 'wp_head', 'deea_schema' );

/**
 * Booking link, optionally pre-selecting a service.
 *
 * @param string $service Service slug in the booking app.
 * @return string
 */
function deea_booking_url( $service = '' ) {
	$url = deea_opt( 'booking_url' );
	if ( $service ) {
		$url = add_query_arg( 'service', rawurlencode( $service ), $url );
	}
	return $url;
}

/**
 * Format a "from" price with the currency pattern from the Customizer.
 *
 * @param string|float $amount Price.
 * @return string
 */
function deea_money( $amount ) {
	if ( '' === $amount || null === $amount ) {
		return '';
	}
	$amount   = (float) $amount;
	$decimals = floor( $amount ) === $amount ? 0 : 2;
	$pattern  = deea_opt( 'currency' );
	$number   = number_format_i18n( $amount, $decimals );
	return false === strpos( $pattern, '%s' ) ? $pattern . ' ' . $number : str_replace( '%s', $number, $pattern );
}

/**
 * Social profile URLs keyed by network.
 *
 * @return array
 */
function deea_social_links() {
	$links = array();
	foreach ( array( 'instagram', 'facebook', 'linkedin', 'tiktok', 'youtube', 'github' ) as $network ) {
		$links[ $network ] = deea_opt( 'social_' . $network );
	}
	return array_filter( $links );
}

/**
 * Fallback main menu (anchor links to the landing page sections).
 */
function deea_fallback_menu() {
	$home  = home_url( '/' );
	$items = array(
		'#services' => __( 'Services', 'deea' ),
		'#process'  => __( 'How it works', 'deea' ),
		'#about'    => __( 'About', 'deea' ),
		'#faq'      => __( 'FAQ', 'deea' ),
	);
	foreach ( $items as $anchor => $label ) {
		printf( '<a href="%s">%s</a>', esc_url( $home . $anchor ), esc_html( $label ) );
	}
	if ( get_option( 'page_for_posts' ) ) {
		printf( '<a href="%s">%s</a>', esc_url( get_permalink( get_option( 'page_for_posts' ) ) ), esc_html__( 'Blog', 'deea' ) );
	}
}

/**
 * Menu links without the default <ul> wrapper, so they match the design.
 *
 * @param string $location Menu location.
 */
function deea_menu_links( $location ) {
	$locations = get_nav_menu_locations();
	if ( empty( $locations[ $location ] ) ) {
		if ( 'primary' === $location ) {
			deea_fallback_menu();
		}
		return;
	}
	foreach ( (array) wp_get_nav_menu_items( $locations[ $location ] ) as $item ) {
		if ( (int) $item->menu_item_parent ) {
			continue;
		}
		printf( '<a href="%s"%s>%s</a>', esc_url( $item->url ), $item->target ? ' target="' . esc_attr( $item->target ) . '" rel="noopener"' : '', esc_html( $item->title ) );
	}
}

/**
 * Shorter excerpts on blog cards.
 *
 * @return int
 */
function deea_excerpt_length() {
	return 24;
}
add_filter( 'excerpt_length', 'deea_excerpt_length' );
