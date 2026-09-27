<?php
/**
 * Landing page (used for the site's front page).
 *
 * @package Deea
 */

get_header();

$deea_services    = deea_get_services();
$deea_launch_pack = null;
foreach ( $deea_services as $deea_i => $deea_service ) {
	if ( 'launch-pack' === $deea_service['booking_slug'] ) {
		$deea_launch_pack = $deea_service;
		unset( $deea_services[ $deea_i ] );
	}
}
$deea_owner = deea_opt( 'owner_name' );
?>

<section class="hero">
	<div class="container hero__grid">
		<div class="hero__copy">
			<p class="eyebrow"><span class="pulse" aria-hidden="true"></span> <?php esc_html_e( 'Taking new projects', 'deea' ); ?> · <?php echo esc_html( deea_opt( 'role' ) ); ?></p>
			<h1 class="hero__title"><?php echo esc_html( deea_opt( 'hero_title' ) ); ?> <em><?php echo esc_html( deea_opt( 'hero_highlight' ) ); ?></em></h1>
			<p class="hero__lead"><?php echo esc_html( deea_opt( 'hero_lead' ) ); ?></p>
			<div class="hero__actions">
				<a class="btn btn--primary btn--lg" href="<?php echo esc_url( deea_booking_url() ); ?>"><?php deea_the_icon( 'calendar' ); ?> <?php esc_html_e( 'Book a free consultation', 'deea' ); ?></a>
				<a class="btn btn--ghost btn--lg" href="#services"><?php esc_html_e( 'See services & prices', 'deea' ); ?></a>
			</div>
			<ul class="hero__ticks">
				<li><?php deea_the_icon( 'check' ); ?> <?php esc_html_e( 'Free discovery call', 'deea' ); ?></li>
				<li><?php deea_the_icon( 'check' ); ?> <?php esc_html_e( 'Mobile-first & SEO-ready', 'deea' ); ?></li>
				<li><?php deea_the_icon( 'check' ); ?> <?php esc_html_e( 'You own everything', 'deea' ); ?></li>
			</ul>
		</div>

		<div class="hero__art" aria-hidden="true">
			<div class="mock mock--browser">
				<div class="mock__bar"><i></i><i></i><i></i><span>yourbusiness.com</span></div>
				<div class="mock__body">
					<div class="mock__hero"></div>
					<div class="mock__row"><b></b><b></b><b></b></div>
					<div class="mock__lines"><s></s><s></s><s class="short"></s></div>
				</div>
			</div>
			<div class="mock mock--search">
				<div class="mock__searchbar"><?php deea_the_icon( 'search' ); ?><span>best bakery near me</span></div>
				<div class="mock__result">
					<span class="mock__rank">#1</span>
					<div><strong>Your Business</strong><small>yourbusiness.com · Open now · ★★★★★</small></div>
				</div>
			</div>
			<div class="mock mock--photo">
				<div class="mock__viewfinder"><span></span><span></span><span></span><span></span></div>
				<div class="mock__rec"><i></i> REC 00:14</div>
			</div>
			<span class="chip chip--a"><?php deea_the_icon( 'trending' ); ?> <?php esc_html_e( 'Found on Google', 'deea' ); ?></span>
			<span class="chip chip--b"><?php deea_the_icon( 'pen-tool' ); ?> <?php esc_html_e( 'On-brand design', 'deea' ); ?></span>
			<span class="chip chip--c"><?php deea_the_icon( 'camera' ); ?> <?php esc_html_e( 'Scroll-stopping content', 'deea' ); ?></span>
		</div>
	</div>

	<div class="marquee" aria-hidden="true">
		<div class="marquee__track">
			<?php
			$deea_words = array( __( 'Websites', 'deea' ), __( 'Web apps', 'deea' ), __( 'SEO', 'deea' ), __( 'Content', 'deea' ), __( 'Branding', 'deea' ), __( 'Graphic design', 'deea' ), __( 'Photography', 'deea' ), __( 'Videography', 'deea' ), __( 'Social media', 'deea' ) );
			for ( $deea_n = 0; $deea_n < 2; $deea_n++ ) {
				foreach ( $deea_words as $deea_word ) {
					echo '<span>' . esc_html( $deea_word ) . '</span>';
				}
			}
			?>
		</div>
	</div>
</section>

<section class="section" id="services" aria-labelledby="services-title">
	<div class="container">
		<header class="section__head">
			<p class="eyebrow"><?php esc_html_e( 'Services', 'deea' ); ?></p>
			<h2 id="services-title" class="section__title"><?php esc_html_e( 'Just tell me what you need.', 'deea' ); ?></h2>
			<p class="section__lead"><?php esc_html_e( "Every project starts with a free consultation. Prices are starting points: you'll get a clear quote before any work begins.", 'deea' ); ?></p>
		</header>
		<div class="services-grid">
			<?php foreach ( $deea_services as $deea_service ) : ?>
				<article class="service-card">
					<div class="service-card__icon"><?php deea_the_icon( $deea_service['icon'] ); ?></div>
					<?php if ( $deea_service['tagline'] ) : ?>
						<p class="service-card__ask">&ldquo;<?php echo esc_html( $deea_service['tagline'] ); ?>&rdquo;</p>
					<?php endif; ?>
					<h3 class="service-card__title"><?php echo esc_html( $deea_service['name'] ); ?></h3>
					<p class="service-card__text"><?php echo esc_html( $deea_service['description'] ); ?></p>
					<div class="service-card__foot">
						<span class="service-card__price">
							<?php if ( '' !== $deea_service['price_from'] ) : ?>
								<?php esc_html_e( 'from', 'deea' ); ?> <strong><?php echo esc_html( deea_money( $deea_service['price_from'] ) ); ?></strong>
							<?php else : ?>
								<?php esc_html_e( 'Custom quote', 'deea' ); ?>
							<?php endif; ?>
						</span>
						<a class="link-arrow" href="<?php echo esc_url( deea_booking_url( $deea_service['booking_slug'] ) ); ?>"><?php esc_html_e( 'Book', 'deea' ); ?> <span class="sr-only"><?php echo esc_html( $deea_service['name'] ); ?></span><?php deea_the_icon( 'arrow-right' ); ?></a>
					</div>
				</article>
			<?php endforeach; ?>
		</div>
	</div>
</section>

<?php if ( $deea_launch_pack ) : ?>
<section class="promo" aria-labelledby="promo-title">
	<div class="container promo__inner">
		<div>
			<p class="eyebrow eyebrow--light"><?php deea_the_icon( 'star' ); ?> <?php esc_html_e( 'For small businesses', 'deea' ); ?></p>
			<h2 id="promo-title" class="promo__title"><?php echo esc_html( $deea_launch_pack['name'] ); ?></h2>
			<p class="promo__text"><?php echo esc_html( $deea_launch_pack['description'] ); ?></p>
			<ul class="promo__list">
				<li><?php deea_the_icon( 'check' ); ?> <?php esc_html_e( '5-page mobile-friendly website', 'deea' ); ?></li>
				<li><?php deea_the_icon( 'check' ); ?> <?php esc_html_e( 'Google Business Profile & basic SEO', 'deea' ); ?></li>
				<li><?php deea_the_icon( 'check' ); ?> <?php esc_html_e( 'Logo refresh & brand colors', 'deea' ); ?></li>
				<li><?php deea_the_icon( 'check' ); ?> <?php esc_html_e( 'Photo session for your website & socials', 'deea' ); ?></li>
			</ul>
		</div>
		<div class="promo__card">
			<?php if ( '' !== $deea_launch_pack['price_from'] ) : ?>
				<p class="promo__from"><?php esc_html_e( 'All of it, from', 'deea' ); ?></p>
				<p class="promo__price"><?php echo esc_html( deea_money( $deea_launch_pack['price_from'] ) ); ?></p>
			<?php endif; ?>
			<a class="btn btn--primary btn--lg btn--block" href="<?php echo esc_url( deea_booking_url( $deea_launch_pack['booking_slug'] ) ); ?>"><?php esc_html_e( "Let's launch my business", 'deea' ); ?></a>
			<p class="promo__note"><?php esc_html_e( 'Starts with a free planning call.', 'deea' ); ?></p>
		</div>
	</div>
</section>
<?php endif; ?>

<section class="section section--tint" id="process" aria-labelledby="process-title">
	<div class="container">
		<header class="section__head">
			<p class="eyebrow"><?php esc_html_e( 'How it works', 'deea' ); ?></p>
			<h2 id="process-title" class="section__title"><?php esc_html_e( 'From “I need…” to launch day in four steps.', 'deea' ); ?></h2>
		</header>
		<ol class="steps">
			<?php
			$deea_steps = array(
				array( __( 'Book a call', 'deea' ), __( 'Pick a service and a time that suits you. It takes about a minute.', 'deea' ) ),
				array( __( 'Plan together', 'deea' ), __( 'We talk about your goals, customers and budget. You get a clear proposal and timeline.', 'deea' ) ),
				array( __( 'Create', 'deea' ), __( "I design, build, write and shoot, sharing progress so you're never left guessing.", 'deea' ) ),
				array( __( 'Launch & grow', 'deea' ), __( 'We go live, measure results and keep improving your visibility and content.', 'deea' ) ),
			);
			foreach ( $deea_steps as $deea_index => list( $deea_title, $deea_text ) ) :
				?>
				<li class="step">
					<span class="step__num"><?php echo esc_html( sprintf( '%02d', $deea_index + 1 ) ); ?></span>
					<h3><?php echo esc_html( $deea_title ); ?></h3>
					<p><?php echo esc_html( $deea_text ); ?></p>
				</li>
			<?php endforeach; ?>
		</ol>
	</div>
</section>

<?php
// Latest blog posts double as a "work & insights" section once you publish some.
$deea_posts = get_posts( array( 'posts_per_page' => 3 ) );
if ( $deea_posts ) :
	?>
<section class="section" id="work" aria-labelledby="work-title">
	<div class="container">
		<header class="section__head">
			<p class="eyebrow"><?php esc_html_e( 'Work & insights', 'deea' ); ?></p>
			<h2 id="work-title" class="section__title"><?php esc_html_e( 'Latest from the studio.', 'deea' ); ?></h2>
		</header>
		<div class="post-grid">
			<?php
			foreach ( $deea_posts as $post ) : // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
				setup_postdata( $post );
				get_template_part( 'template-parts/post-card' );
			endforeach;
			wp_reset_postdata();
			?>
		</div>
	</div>
</section>
<?php endif; ?>

<section class="section" id="about" aria-labelledby="about-title">
	<div class="container about">
		<div class="about__portrait" aria-hidden="true">
			<span><?php echo esc_html( mb_substr( $deea_owner, 0, 1 ) ); ?></span>
		</div>
		<div>
			<p class="eyebrow"><?php esc_html_e( 'About', 'deea' ); ?></p>
			<h2 id="about-title" class="section__title">
				<?php
				/* translators: %s: owner name */
				echo esc_html( sprintf( __( "Hi, I'm %s.", 'deea' ), $deea_owner ) );
				?>
			</h2>
			<p class="section__lead"><?php echo esc_html( deea_opt( 'about_text' ) ); ?></p>
			<ul class="about__points">
				<li><?php deea_the_icon( 'users' ); ?><div><strong><?php esc_html_e( 'One point of contact', 'deea' ); ?></strong><span><?php esc_html_e( 'No hand-offs, no agency overheads, no mixed messages.', 'deea' ); ?></span></div></li>
				<li><?php deea_the_icon( 'layers' ); ?><div><strong><?php esc_html_e( 'Consistent everywhere', 'deea' ); ?></strong><span><?php esc_html_e( 'Your website, socials and print all look and sound like you.', 'deea' ); ?></span></div></li>
				<li><?php deea_the_icon( 'search' ); ?><div><strong><?php esc_html_e( 'Built to be found', 'deea' ); ?></strong><span><?php esc_html_e( 'SEO is baked in from day one, not bolted on later.', 'deea' ); ?></span></div></li>
				<li><?php deea_the_icon( 'key' ); ?><div><strong><?php esc_html_e( 'You own it all', 'deea' ); ?></strong><span><?php esc_html_e( 'Your domain, your code, your photos. No lock-in.', 'deea' ); ?></span></div></li>
			</ul>
		</div>
	</div>
</section>

<section class="section section--tint" id="faq" aria-labelledby="faq-title">
	<div class="container container--narrow">
		<header class="section__head">
			<p class="eyebrow"><?php esc_html_e( 'FAQ', 'deea' ); ?></p>
			<h2 id="faq-title" class="section__title"><?php esc_html_e( 'Good questions.', 'deea' ); ?></h2>
		</header>
		<div class="faq">
			<?php
			$deea_faq = array(
				__( 'What happens in the free consultation?', 'deea' ) => __( "We spend about 30 minutes on your business, your goals and what's working today. You leave with honest advice and, if we're a good fit, a clear proposal with price and timeline.", 'deea' ),
				__( 'How much does a website cost?', 'deea' )          => __( "Simple business websites start from the price shown above. The final quote depends on pages, features (like bookings or a shop) and content. You'll always know the price before any work starts.", 'deea' ),
				__( 'How long does a project take?', 'deea' )          => __( 'A small business website usually takes 2–4 weeks from the moment content is ready. Web apps are planned in milestones so you see progress every week.', 'deea' ),
				__( 'Can I update my website myself?', 'deea' )        => __( 'Yes. I build on tools you can manage (like WordPress) and give you a short walkthrough so you can edit text, photos and prices without calling anyone.', 'deea' ),
				__( 'Do you work with businesses outside my area?', 'deea' ) => __( 'Absolutely. Websites, web apps, SEO, design and content can all be done remotely. Photo and video shoots happen on location, and travel can be arranged.', 'deea' ),
			);
			foreach ( $deea_faq as $deea_q => $deea_a ) :
				?>
				<details>
					<summary><?php echo esc_html( $deea_q ); ?></summary>
					<p><?php echo esc_html( $deea_a ); ?></p>
				</details>
			<?php endforeach; ?>
		</div>
	</div>
</section>

<?php
// Anything written in the editor for a static front page appears here.
while ( is_page() && have_posts() ) :
	the_post();
	if ( '' !== trim( get_the_content() ) ) :
		?>
		<section class="section">
			<div class="container container--narrow entry-content"><?php the_content(); ?></div>
		</section>
		<?php
	endif;
endwhile;
?>

<section class="cta">
	<div class="container cta__inner">
		<h2 class="cta__title"><?php esc_html_e( 'Ready to grow your business online?', 'deea' ); ?></h2>
		<p class="cta__text"><?php esc_html_e( "Pick a time that works for you. The first conversation is free and there's no obligation.", 'deea' ); ?></p>
		<a class="btn btn--primary btn--lg" href="<?php echo esc_url( deea_booking_url() ); ?>"><?php deea_the_icon( 'calendar' ); ?> <?php esc_html_e( 'Book your free consultation', 'deea' ); ?></a>
	</div>
</section>

<?php
get_footer();
