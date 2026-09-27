<?php
/**
 * Comments.
 *
 * @package Deea
 */

if ( post_password_required() ) {
	return;
}
?>
<section id="comments" class="container container--narrow comments">
	<?php if ( have_comments() ) : ?>
		<h2 class="comments__title">
			<?php
			/* translators: %s: number of comments */
			echo esc_html( sprintf( _n( '%s comment', '%s comments', get_comments_number(), 'deea' ), number_format_i18n( get_comments_number() ) ) );
			?>
		</h2>
		<ol class="comment-list">
			<?php
			wp_list_comments(
				array(
					'style'       => 'ol',
					'short_ping'  => true,
					'avatar_size' => 44,
				)
			);
			?>
		</ol>
		<?php the_comments_navigation(); ?>
	<?php endif; ?>

	<?php if ( ! comments_open() && get_comments_number() ) : ?>
		<p class="muted"><?php esc_html_e( 'Comments are closed.', 'deea' ); ?></p>
	<?php endif; ?>

	<?php comment_form(); ?>
</section>
