<?php
/**
 * Single blog post.
 *
 * @package Deea
 */

get_header();

while ( have_posts() ) :
	the_post();
	?>
	<article id="post-<?php the_ID(); ?>" <?php post_class(); ?>>
		<header class="page-hero">
			<div class="container container--narrow">
				<p class="eyebrow">
					<time datetime="<?php echo esc_attr( get_the_date( 'c' ) ); ?>"><?php echo esc_html( get_the_date() ); ?></time>
					<?php
					$deea_categories = get_the_category();
					if ( $deea_categories ) {
						echo ' · ' . esc_html( $deea_categories[0]->name );
					}
					?>
				</p>
				<h1 class="page-hero__title"><?php the_title(); ?></h1>
				<?php if ( has_excerpt() ) : ?>
					<p class="page-hero__lead"><?php echo esc_html( get_the_excerpt() ); ?></p>
				<?php endif; ?>
			</div>
		</header>

		<?php if ( has_post_thumbnail() ) : ?>
			<div class="container entry-hero-image"><?php the_post_thumbnail( 'large' ); ?></div>
		<?php endif; ?>

		<div class="container container--narrow entry-content">
			<?php
			the_content();
			wp_link_pages(
				array(
					'before' => '<nav class="page-links">' . esc_html__( 'Pages:', 'deea' ),
					'after'  => '</nav>',
				)
			);
			?>
		</div>

		<footer class="container container--narrow entry-footer">
			<?php the_tags( '<p class="entry-tags">', ' ', '</p>' ); ?>
			<div class="entry-cta">
				<p><strong><?php esc_html_e( 'Want this for your business?', 'deea' ); ?></strong> <?php esc_html_e( 'Book a free consultation and let’s talk.', 'deea' ); ?></p>
				<a class="btn btn--primary" href="<?php echo esc_url( deea_booking_url() ); ?>"><?php esc_html_e( 'Book a call', 'deea' ); ?></a>
			</div>
			<?php
			the_post_navigation(
				array(
					'prev_text' => '<span>' . esc_html__( 'Previous', 'deea' ) . '</span> %title',
					'next_text' => '<span>' . esc_html__( 'Next', 'deea' ) . '</span> %title',
				)
			);
			?>
		</footer>
	</article>

	<?php
	if ( comments_open() || get_comments_number() ) :
		comments_template();
	endif;
endwhile;

get_footer();
