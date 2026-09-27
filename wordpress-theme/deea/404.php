<?php
/**
 * Not found.
 *
 * @package Deea
 */

get_header();
?>
<section class="page-hero">
	<div class="container container--narrow">
		<p class="eyebrow">404</p>
		<h1 class="page-hero__title"><?php esc_html_e( 'This page went off-script.', 'deea' ); ?></h1>
		<p class="page-hero__lead"><?php esc_html_e( 'The page you were looking for doesn’t exist. Try the homepage, or book a call if you were looking for help with your project.', 'deea' ); ?></p>
		<p class="hero__actions">
			<a class="btn btn--primary" href="<?php echo esc_url( home_url( '/' ) ); ?>"><?php esc_html_e( 'Back to homepage', 'deea' ); ?></a>
			<a class="btn btn--ghost" href="<?php echo esc_url( deea_booking_url() ); ?>"><?php esc_html_e( 'Book a call', 'deea' ); ?></a>
		</p>
	</div>
</section>
<?php
get_footer();
