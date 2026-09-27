<?php
/**
 * Regular page.
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
				<h1 class="page-hero__title"><?php the_title(); ?></h1>
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
	</article>
	<?php
	if ( comments_open() || get_comments_number() ) :
		comments_template();
	endif;
endwhile;

get_footer();
