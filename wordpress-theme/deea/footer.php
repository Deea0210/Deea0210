<?php
/**
 * Site footer.
 *
 * @package Deea
 */

$deea_name = get_bloginfo( 'name' );
?>
</main>
<footer class="site-footer">
	<div class="container site-footer__grid">
		<div>
			<a class="logo logo--light" href="<?php echo esc_url( home_url( '/' ) ); ?>">
				<span class="logo__mark" aria-hidden="true"><?php echo esc_html( mb_substr( $deea_name, 0, 1 ) ); ?></span>
				<span class="logo__text"><?php echo esc_html( mb_strtolower( $deea_name ) ); ?><span class="logo__dot">.</span></span>
			</a>
			<p class="site-footer__tagline"><?php echo esc_html( deea_opt( 'tagline' ) ); ?></p>
		</div>
		<div>
			<h2 class="site-footer__heading"><?php esc_html_e( 'Contact', 'deea' ); ?></h2>
			<ul class="site-footer__list">
				<li><?php deea_the_icon( 'mail' ); ?><a href="<?php echo esc_url( 'mailto:' . deea_opt( 'email' ) ); ?>"><?php echo esc_html( deea_opt( 'email' ) ); ?></a></li>
				<?php if ( deea_opt( 'phone' ) ) : ?>
					<li><?php deea_the_icon( 'phone' ); ?><a href="tel:<?php echo esc_attr( preg_replace( '/[^0-9+]/', '', deea_opt( 'phone' ) ) ); ?>"><?php echo esc_html( deea_opt( 'phone' ) ); ?></a></li>
				<?php endif; ?>
				<li><?php deea_the_icon( 'map-pin' ); ?><span><?php echo esc_html( deea_opt( 'location' ) ); ?></span></li>
			</ul>
		</div>
		<div>
			<h2 class="site-footer__heading"><?php esc_html_e( 'Explore', 'deea' ); ?></h2>
			<ul class="site-footer__list">
				<li><a href="<?php echo esc_url( deea_booking_url() ); ?>"><?php esc_html_e( 'Book a consultation', 'deea' ); ?></a></li>
				<?php if ( has_nav_menu( 'footer' ) ) : ?>
					<?php
					wp_nav_menu(
						array(
							'theme_location' => 'footer',
							'container'      => false,
							'items_wrap'     => '%3$s',
							'depth'          => 1,
						)
					);
					?>
				<?php endif; ?>
				<?php if ( get_privacy_policy_url() ) : ?>
					<li><a href="<?php echo esc_url( get_privacy_policy_url() ); ?>"><?php esc_html_e( 'Privacy policy', 'deea' ); ?></a></li>
				<?php endif; ?>
				<?php foreach ( deea_social_links() as $deea_network => $deea_link ) : ?>
					<li><a href="<?php echo esc_url( $deea_link ); ?>" rel="me noopener" target="_blank"><?php echo esc_html( ucfirst( $deea_network ) ); ?></a></li>
				<?php endforeach; ?>
			</ul>
		</div>
	</div>
	<?php if ( is_active_sidebar( 'footer-1' ) ) : ?>
		<div class="container site-footer__widgets"><?php dynamic_sidebar( 'footer-1' ); ?></div>
	<?php endif; ?>
	<div class="container site-footer__bottom">
		<p>&copy; <?php echo esc_html( gmdate( 'Y' ) ); ?> <?php echo esc_html( $deea_name ); ?>. <?php esc_html_e( 'All rights reserved.', 'deea' ); ?></p>
	</div>
</footer>
<?php wp_footer(); ?>
</body>
</html>
