<?php
/**
 * "Services" post type: edit services, prices and icons in WP Admin → Services.
 *
 * @package Deea
 */

defined( 'ABSPATH' ) || exit;

/**
 * Register the post type.
 */
function deea_register_services() {
	register_post_type(
		'deea_service',
		array(
			'labels'        => array(
				'name'          => __( 'Services', 'deea' ),
				'singular_name' => __( 'Service', 'deea' ),
				'add_new_item'  => __( 'Add service', 'deea' ),
				'edit_item'     => __( 'Edit service', 'deea' ),
				'all_items'     => __( 'All services', 'deea' ),
			),
			'public'        => false,
			'show_ui'       => true,
			'show_in_rest'  => false,
			'menu_icon'     => 'dashicons-portfolio',
			'menu_position' => 20,
			'supports'      => array( 'title', 'excerpt', 'page-attributes' ),
		)
	);
}
add_action( 'init', 'deea_register_services' );

/**
 * Meta fields: key => label.
 *
 * @return array
 */
function deea_service_fields() {
	return array(
		'tagline'      => __( 'Client-style tagline (e.g. "Make me a website")', 'deea' ),
		'price_from'   => __( 'Price from (number, empty = custom quote)', 'deea' ),
		'icon'         => __( 'Icon', 'deea' ),
		'booking_slug' => __( 'Booking app service slug (pre-selects it on the booking page)', 'deea' ),
	);
}

/**
 * Meta box.
 */
function deea_service_meta_box() {
	add_meta_box( 'deea_service_details', __( 'Service details', 'deea' ), 'deea_render_service_meta_box', 'deea_service', 'normal', 'high' );
}
add_action( 'add_meta_boxes', 'deea_service_meta_box' );

/**
 * Render the meta box.
 *
 * @param WP_Post $post Post.
 */
function deea_render_service_meta_box( $post ) {
	wp_nonce_field( 'deea_service_save', 'deea_service_nonce' );
	echo '<p>' . esc_html__( 'The description comes from the "Excerpt" box below. Order services with "Order" in Page Attributes.', 'deea' ) . '</p>';
	echo '<table class="form-table" role="presentation">';
	foreach ( deea_service_fields() as $key => $label ) {
		$value = get_post_meta( $post->ID, '_deea_' . $key, true );
		echo '<tr><th scope="row"><label for="deea_' . esc_attr( $key ) . '">' . esc_html( $label ) . '</label></th><td>';
		if ( 'icon' === $key ) {
			echo '<select id="deea_icon" name="deea_icon">';
			foreach ( deea_service_icons() as $icon ) {
				printf( '<option value="%1$s"%2$s>%1$s</option>', esc_attr( $icon ), selected( $value, $icon, false ) );
			}
			echo '</select>';
		} else {
			printf( '<input class="regular-text" type="text" id="deea_%1$s" name="deea_%1$s" value="%2$s">', esc_attr( $key ), esc_attr( $value ) );
		}
		echo '</td></tr>';
	}
	echo '</table>';
}

/**
 * Save meta.
 *
 * @param int $post_id Post ID.
 */
function deea_save_service( $post_id ) {
	if ( ! isset( $_POST['deea_service_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['deea_service_nonce'] ) ), 'deea_service_save' ) ) {
		return;
	}
	if ( ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) || ! current_user_can( 'edit_post', $post_id ) ) {
		return;
	}
	foreach ( array_keys( deea_service_fields() ) as $key ) {
		$raw = isset( $_POST[ 'deea_' . $key ] ) ? sanitize_text_field( wp_unslash( $_POST[ 'deea_' . $key ] ) ) : '';
		if ( 'price_from' === $key ) {
			$raw = is_numeric( $raw ) ? (string) round( (float) $raw, 2 ) : '';
		} elseif ( 'icon' === $key && ! in_array( $raw, deea_service_icons(), true ) ) {
			$raw = 'spark';
		} elseif ( 'booking_slug' === $key ) {
			$raw = sanitize_title( $raw );
		}
		update_post_meta( $post_id, '_deea_' . $key, $raw );
	}
}
add_action( 'save_post_deea_service', 'deea_save_service' );

/**
 * Price column in the services list.
 *
 * @param array $columns Columns.
 * @return array
 */
function deea_service_columns( $columns ) {
	$columns['deea_price'] = __( 'From', 'deea' );
	$columns['menu_order'] = __( 'Order', 'deea' );
	return $columns;
}
add_filter( 'manage_deea_service_posts_columns', 'deea_service_columns' );

/**
 * Render custom columns.
 *
 * @param string $column  Column.
 * @param int    $post_id Post ID.
 */
function deea_service_column_content( $column, $post_id ) {
	if ( 'deea_price' === $column ) {
		$price = get_post_meta( $post_id, '_deea_price_from', true );
		echo esc_html( '' === $price ? __( 'Quote', 'deea' ) : deea_money( $price ) );
	} elseif ( 'menu_order' === $column ) {
		echo (int) get_post_field( 'menu_order', $post_id );
	}
}
add_action( 'manage_deea_service_posts_custom_column', 'deea_service_column_content', 10, 2 );

/**
 * Published services, ordered, as plain arrays.
 *
 * @return array
 */
function deea_get_services() {
	$posts = get_posts(
		array(
			'post_type'      => 'deea_service',
			'post_status'    => 'publish',
			'posts_per_page' => 50,
			'orderby'        => array(
				'menu_order' => 'ASC',
				'title'      => 'ASC',
			),
		)
	);
	return array_map(
		static function ( $post ) {
			return array(
				'id'           => $post->ID,
				'name'         => get_the_title( $post ),
				'description'  => $post->post_excerpt,
				'tagline'      => (string) get_post_meta( $post->ID, '_deea_tagline', true ),
				'price_from'   => (string) get_post_meta( $post->ID, '_deea_price_from', true ),
				'icon'         => get_post_meta( $post->ID, '_deea_icon', true ) ? get_post_meta( $post->ID, '_deea_icon', true ) : 'spark',
				'booking_slug' => (string) get_post_meta( $post->ID, '_deea_booking_slug', true ),
			);
		},
		$posts
	);
}

/**
 * Starter services, the same as the booking app's.
 *
 * @return array
 */
function deea_default_services() {
	return array(
		array( 'website', __( 'Website Design & Build', 'deea' ), __( 'Make me a website', 'deea' ), __( 'A fast, mobile-first website that looks like your brand and turns visitors into customers. WordPress or custom-built, with contact forms, maps, bookings and everything set up for Google.', 'deea' ), 'code', '450' ),
		array( 'web-app', __( 'Web App Development', 'deea' ), __( 'Develop me a web app', 'deea' ), __( 'Booking systems, client portals, dashboards and online tools built around the way your business works: secure, easy to use and ready to grow with you.', 'deea' ), 'app', '1500' ),
		array( 'seo', __( 'SEO & Google Visibility', 'deea' ), __( 'Get me found on Google', 'deea' ), __( 'Technical SEO audit, keyword research, on-page optimization and a Google Business Profile that brings local customers to your door.', 'deea' ), 'search', '250' ),
		array( 'content', __( 'Content Creation', 'deea' ), __( 'Fill my social media', 'deea' ), __( 'Posts, reels, blog articles and website copy that sound like you and keep your audience engaged, planned in a simple monthly content calendar.', 'deea' ), 'megaphone', '300' ),
		array( 'graphic-design', __( 'Graphic Design & Branding', 'deea' ), __( 'Design my brand', 'deea' ), __( 'Logos, brand kits, business cards, flyers, menus and social media templates that make your business instantly recognizable.', 'deea' ), 'pen-tool', '200' ),
		array( 'photography', __( 'Photography', 'deea' ), __( 'Photograph my business', 'deea' ), __( 'Product, team, food and interior photography that shows your business at its best, edited and delivered ready for web, social and print.', 'deea' ), 'camera', '180' ),
		array( 'videography', __( 'Videography', 'deea' ), __( 'Film my story', 'deea' ), __( 'Promo videos, reels and short-form content: planned, shot, edited and optimized for Instagram, TikTok, YouTube and your website.', 'deea' ), 'video', '350' ),
		array( 'launch-pack', __( 'Small Business Launch Pack', 'deea' ), __( 'Get my business online', 'deea' ), __( 'Everything you need to start strong: a 5-page website, Google Business Profile setup, a logo refresh and a photo session. One plan, one partner, one price.', 'deea' ), 'star', '1200' ),
	);
}

/**
 * Add the starter services the first time the theme is activated.
 */
function deea_seed_services() {
	deea_register_services();
	$existing = get_posts(
		array(
			'post_type'      => 'deea_service',
			'post_status'    => 'any',
			'posts_per_page' => 1,
			'fields'         => 'ids',
		)
	);
	if ( $existing || get_option( 'deea_services_seeded' ) ) {
		return;
	}
	foreach ( deea_default_services() as $order => list( $slug, $name, $tagline, $description, $icon, $price ) ) {
		$post_id = wp_insert_post(
			array(
				'post_type'    => 'deea_service',
				'post_status'  => 'publish',
				'post_title'   => $name,
				'post_excerpt' => $description,
				'menu_order'   => $order + 1,
			)
		);
		if ( $post_id && ! is_wp_error( $post_id ) ) {
			update_post_meta( $post_id, '_deea_tagline', $tagline );
			update_post_meta( $post_id, '_deea_price_from', $price );
			update_post_meta( $post_id, '_deea_icon', $icon );
			update_post_meta( $post_id, '_deea_booking_slug', $slug );
		}
	}
	update_option( 'deea_services_seeded', 1 );
}
add_action( 'after_switch_theme', 'deea_seed_services' );
