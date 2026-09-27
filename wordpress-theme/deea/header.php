<?php
/**
 * Site header.
 *
 * @package Deea
 */

?>
<!doctype html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<?php wp_head(); ?>
</head>
<body <?php body_class(); ?>>
<?php wp_body_open(); ?>
<a class="skip-link" href="#main"><?php esc_html_e( 'Skip to content', 'deea' ); ?></a>
<header class="site-header" data-header>
	<div class="container site-header__inner">
		<?php if ( has_custom_logo() ) : ?>
			<?php the_custom_logo(); ?>
		<?php else : ?>
			<a class="logo" href="<?php echo esc_url( home_url( '/' ) ); ?>" rel="home">
				<span class="logo__mark" aria-hidden="true"><?php echo esc_html( mb_substr( get_bloginfo( 'name' ), 0, 1 ) ); ?></span>
				<span class="logo__text"><?php echo esc_html( mb_strtolower( get_bloginfo( 'name' ) ) ); ?><span class="logo__dot">.</span></span>
			</a>
		<?php endif; ?>
		<button class="nav-toggle" type="button" aria-expanded="false" aria-controls="site-nav" data-nav-toggle>
			<span class="sr-only"><?php esc_html_e( 'Menu', 'deea' ); ?></span><?php deea_the_icon( 'menu' ); ?>
		</button>
		<nav class="site-nav" id="site-nav" aria-label="<?php esc_attr_e( 'Main', 'deea' ); ?>">
			<?php deea_menu_links( 'primary' ); ?>
			<a class="btn btn--primary btn--sm" href="<?php echo esc_url( deea_booking_url() ); ?>"><?php esc_html_e( 'Book a call', 'deea' ); ?> <?php deea_the_icon( 'arrow-right' ); ?></a>
		</nav>
	</div>
</header>
<main id="main">
