<?php
/**
 * Blog, archives and search results.
 *
 * @package Deea
 */

get_header();
?>
<section class="page-hero">
	<div class="container">
		<?php if ( is_search() ) : ?>
			<p class="eyebrow"><?php esc_html_e( 'Search', 'deea' ); ?></p>
			<h1 class="page-hero__title">
				<?php
				/* translators: %s: search query */
				echo esc_html( sprintf( __( 'Results for “%s”', 'deea' ), get_search_query() ) );
				?>
			</h1>
		<?php elseif ( is_archive() ) : ?>
			<p class="eyebrow"><?php esc_html_e( 'Archive', 'deea' ); ?></p>
			<h1 class="page-hero__title"><?php echo esc_html( wp_strip_all_tags( get_the_archive_title() ) ); ?></h1>
			<?php the_archive_description( '<div class="page-hero__lead">', '</div>' ); ?>
		<?php else : ?>
			<p class="eyebrow"><?php esc_html_e( 'Blog', 'deea' ); ?></p>
			<h1 class="page-hero__title"><?php echo esc_html( is_home() && get_option( 'page_for_posts' ) ? get_the_title( get_option( 'page_for_posts' ) ) : __( 'Work & insights', 'deea' ) ); ?></h1>
			<p class="page-hero__lead"><?php esc_html_e( 'Tips on websites, SEO, content and visuals for small businesses, plus behind-the-scenes from recent projects.', 'deea' ); ?></p>
		<?php endif; ?>
	</div>
</section>

<section class="section section--flush">
	<div class="container">
		<?php if ( have_posts() ) : ?>
			<div class="post-grid">
				<?php
				while ( have_posts() ) :
					the_post();
					get_template_part( 'template-parts/post-card' );
				endwhile;
				?>
			</div>
			<?php
			the_posts_pagination(
				array(
					'mid_size'  => 1,
					'prev_text' => __( 'Previous', 'deea' ),
					'next_text' => __( 'Next', 'deea' ),
				)
			);
			?>
		<?php else : ?>
			<div class="empty-state">
				<p><?php esc_html_e( 'Nothing here yet.', 'deea' ); ?></p>
				<?php get_search_form(); ?>
			</div>
		<?php endif; ?>
	</div>
</section>
<?php
get_footer();
