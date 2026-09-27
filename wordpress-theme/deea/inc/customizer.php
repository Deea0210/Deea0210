<?php
/**
 * Customizer: business details, hero text, booking link and social profiles.
 * Appearance → Customize → "Deea: Business details".
 *
 * @package Deea
 */

defined( 'ABSPATH' ) || exit;

/**
 * Default values.
 *
 * @return array
 */
function deea_defaults() {
	return array(
		'owner_name'       => 'Deea',
		'role'             => __( 'Web Developer & Digital Creative', 'deea' ),
		'tagline'          => __( 'Websites · Web apps · SEO · Content · Design · Photo & Video', 'deea' ),
		'email'            => 'hello@yourdomain.com',
		'phone'            => '',
		'location'         => __( 'Available worldwide · online & on location', 'deea' ),
		'booking_url'      => '/booking-app/book.php',
		'currency'         => '€%s',
		'hero_title'       => __( 'Your small business, online and', 'deea' ),
		'hero_highlight'   => __( 'impossible to miss.', 'deea' ),
		'hero_lead'        => __( "I'm Deea — one creative partner for your website, web app, SEO, content, branding, photos and video. Tell me what you need, pick a time, and let's make it happen.", 'deea' ),
		'about_text'       => __( 'I\'m a web developer and digital creative who helps small businesses show up online the right way. Instead of juggling a developer, an SEO agency, a designer and a photographer, you get one person who understands your whole brand and makes every piece work together.', 'deea' ),
		'social_instagram' => '',
		'social_facebook'  => '',
		'social_linkedin'  => '',
		'social_tiktok'    => '',
		'social_youtube'   => '',
		'social_github'    => 'https://github.com/Deea0210',
	);
}

/**
 * Read a theme option.
 *
 * @param string $key Option key without the deea_ prefix.
 * @return string
 */
function deea_opt( $key ) {
	// Defaults are applied here, not via get_theme_mod(), which would run
	// sprintf() on them and break patterns such as "€%s".
	$value = get_theme_mod( 'deea_' . $key, null );
	if ( null === $value ) {
		$defaults = deea_defaults();
		$value    = isset( $defaults[ $key ] ) ? $defaults[ $key ] : '';
	}
	return (string) $value;
}

/**
 * Register Customizer settings.
 *
 * @param WP_Customize_Manager $wp_customize Customizer.
 */
function deea_customize_register( $wp_customize ) {
	$defaults = deea_defaults();

	$wp_customize->add_panel(
		'deea',
		array(
			'title'    => __( 'Deea: Business details', 'deea' ),
			'priority' => 30,
		)
	);

	$sections = array(
		'deea_business' => __( 'You & contact', 'deea' ),
		'deea_hero'     => __( 'Landing page text', 'deea' ),
		'deea_booking'  => __( 'Booking & prices', 'deea' ),
		'deea_social'   => __( 'Social profiles', 'deea' ),
	);
	foreach ( $sections as $id => $title ) {
		$wp_customize->add_section( $id, array( 'title' => $title, 'panel' => 'deea' ) );
	}

	$fields = array(
		'owner_name'       => array( 'deea_business', __( 'Your name', 'deea' ), 'text' ),
		'role'             => array( 'deea_business', __( 'Your title', 'deea' ), 'text' ),
		'tagline'          => array( 'deea_business', __( 'Services line (footer)', 'deea' ), 'text' ),
		'email'            => array( 'deea_business', __( 'Email', 'deea' ), 'email' ),
		'phone'            => array( 'deea_business', __( 'Phone (optional)', 'deea' ), 'text' ),
		'location'         => array( 'deea_business', __( 'Location', 'deea' ), 'text' ),
		'hero_title'       => array( 'deea_hero', __( 'Hero headline', 'deea' ), 'text' ),
		'hero_highlight'   => array( 'deea_hero', __( 'Hero headline — highlighted end', 'deea' ), 'text' ),
		'hero_lead'        => array( 'deea_hero', __( 'Hero introduction', 'deea' ), 'textarea' ),
		'about_text'       => array( 'deea_hero', __( 'About text', 'deea' ), 'textarea' ),
		'booking_url'      => array( 'deea_booking', __( 'Booking page URL (e.g. https://book.yourdomain.com/book.php)', 'deea' ), 'url' ),
		'currency'         => array( 'deea_booking', __( 'Price format (%s = amount), e.g. €%s or %s lei', 'deea' ), 'text' ),
		'social_instagram' => array( 'deea_social', 'Instagram', 'url' ),
		'social_facebook'  => array( 'deea_social', 'Facebook', 'url' ),
		'social_linkedin'  => array( 'deea_social', 'LinkedIn', 'url' ),
		'social_tiktok'    => array( 'deea_social', 'TikTok', 'url' ),
		'social_youtube'   => array( 'deea_social', 'YouTube', 'url' ),
		'social_github'    => array( 'deea_social', 'GitHub', 'url' ),
	);

	foreach ( $fields as $key => list( $section, $label, $type ) ) {
		$sanitize = 'sanitize_text_field';
		if ( 'email' === $type ) {
			$sanitize = 'sanitize_email';
		} elseif ( 'url' === $type ) {
			$sanitize = 'esc_url_raw';
		} elseif ( 'textarea' === $type ) {
			$sanitize = 'sanitize_textarea_field';
		}
		$wp_customize->add_setting(
			'deea_' . $key,
			array(
				'default'           => $defaults[ $key ],
				'sanitize_callback' => $sanitize,
			)
		);
		$wp_customize->add_control(
			'deea_' . $key,
			array(
				'label'   => $label,
				'section' => $section,
				'type'    => 'url' === $type ? 'text' : $type,
			)
		);
	}
}
add_action( 'customize_register', 'deea_customize_register' );
