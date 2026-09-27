<?php
declare(strict_types=1);
require __DIR__ . '/includes/bootstrap.php';

$services = active_services();
$launchPack = null;
foreach ($services as $i => $service) {
    if ($service['slug'] === 'launch-pack') {
        $launchPack = $service;
        unset($services[$i]);
    }
}
$brand = (string) config('app.brand_name');
$owner = (string) config('app.owner_name');
$portfolio = (array) config('app.portfolio', []);

// Structured data so Google understands the business and its services.
$schema = [
    '@context'    => 'https://schema.org',
    '@type'       => 'ProfessionalService',
    'name'        => $brand,
    'description' => config('app.role') . ' — ' . config('app.tagline'),
    'url'         => absolute_url(),
    'image'       => absolute_url('assets/img/og-image.png'),
    'email'       => config('app.email'),
    'founder'     => ['@type' => 'Person', 'name' => $owner, 'jobTitle' => config('app.role')],
    'sameAs'      => array_values(array_filter((array) config('app.social', []))),
    'potentialAction' => ['@type' => 'ReserveAction', 'target' => absolute_url('book.php')],
    'hasOfferCatalog' => [
        '@type' => 'OfferCatalog',
        'name'  => 'Services',
        'itemListElement' => array_values(array_map(static fn(array $s): array => array_filter([
            '@type'       => 'Offer',
            'itemOffered' => ['@type' => 'Service', 'name' => $s['name'], 'description' => $s['description']],
            'priceSpecification' => $s['price_from'] !== null ? [
                '@type' => 'PriceSpecification', 'minPrice' => (float) $s['price_from'],
            ] : null,
        ]), active_services())),
    ],
];
if (config('app.phone')) {
    $schema['telephone'] = config('app.phone');
}

site_header([
    'title'      => $brand . ' — Websites, Web Apps, SEO, Content, Design, Photo & Video',
    'canonical'  => absolute_url(),
    'body_class' => 'home',
    'head'       => '<script type="application/ld+json">' . json_encode($schema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) . '</script>',
]);
?>

<section class="hero">
    <div class="container hero__grid">
        <div class="hero__copy">
            <p class="eyebrow"><span class="pulse" aria-hidden="true"></span> Taking new projects · <?= e(config('app.role')) ?></p>
            <h1 class="hero__title">Your small business, <em>online</em> and <em>impossible to miss.</em></h1>
            <p class="hero__lead">
                I'm <?= e($owner) ?> — one creative partner for your website, web app, SEO, content,
                branding, photos and video. Tell me what you need, pick a time, and let's make it happen.
            </p>
            <div class="hero__actions">
                <a class="btn btn--primary btn--lg" href="<?= e(url('book.php')) ?>"><?= icon('calendar') ?> Book a free consultation</a>
                <a class="btn btn--ghost btn--lg" href="#services">See services &amp; prices</a>
            </div>
            <ul class="hero__ticks">
                <li><?= icon('check') ?> Free discovery call</li>
                <li><?= icon('check') ?> Mobile-first &amp; SEO-ready</li>
                <li><?= icon('check') ?> You own everything</li>
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
                <div class="mock__searchbar"><?= icon('search') ?><span>best bakery near me</span></div>
                <div class="mock__result">
                    <span class="mock__rank">#1</span>
                    <div><strong>Your Business</strong><small>yourbusiness.com · Open now · ★★★★★</small></div>
                </div>
            </div>
            <div class="mock mock--photo">
                <div class="mock__viewfinder"><span></span><span></span><span></span><span></span></div>
                <div class="mock__rec"><i></i> REC 00:14</div>
            </div>
            <span class="chip chip--a"><?= icon('trending') ?> Found on Google</span>
            <span class="chip chip--b"><?= icon('pen-tool') ?> On-brand design</span>
            <span class="chip chip--c"><?= icon('camera') ?> Scroll-stopping content</span>
        </div>
    </div>

    <div class="marquee" aria-hidden="true">
        <div class="marquee__track">
            <?php for ($i = 0; $i < 2; $i++): ?>
                <span>Websites</span><span>Web apps</span><span>SEO</span><span>Content</span><span>Branding</span><span>Graphic design</span><span>Photography</span><span>Videography</span><span>Social media</span>
            <?php endfor; ?>
        </div>
    </div>
</section>

<section class="section" id="services" aria-labelledby="services-title">
    <div class="container">
        <header class="section__head">
            <p class="eyebrow">Services</p>
            <h2 id="services-title" class="section__title">Just tell me what you need.</h2>
            <p class="section__lead">Every project starts with a free consultation. Prices are starting points: you'll get a clear quote before any work begins.</p>
        </header>
        <div class="services-grid">
            <?php foreach ($services as $service): ?>
                <article class="service-card">
                    <div class="service-card__icon"><?= icon($service['icon']) ?></div>
                    <p class="service-card__ask">“<?= e($service['tagline']) ?>”</p>
                    <h3 class="service-card__title"><?= e($service['name']) ?></h3>
                    <p class="service-card__text"><?= e($service['description']) ?></p>
                    <div class="service-card__foot">
                        <span class="service-card__price">
                            <?php if ($service['price_from'] !== null): ?>from <strong><?= e(money($service['price_from'])) ?></strong><?php else: ?>Custom quote<?php endif; ?>
                        </span>
                        <a class="link-arrow" href="<?= e(url('book.php?service=' . rawurlencode($service['slug']))) ?>">Book <span class="sr-only"><?= e($service['name']) ?></span><?= icon('arrow-right') ?></a>
                    </div>
                </article>
            <?php endforeach; ?>
        </div>
    </div>
</section>

<?php if ($launchPack): ?>
<section class="promo" aria-labelledby="promo-title">
    <div class="container promo__inner">
        <div>
            <p class="eyebrow eyebrow--light"><?= icon('star') ?> For small businesses</p>
            <h2 id="promo-title" class="promo__title"><?= e($launchPack['name']) ?></h2>
            <p class="promo__text"><?= e($launchPack['description']) ?></p>
            <ul class="promo__list">
                <li><?= icon('check') ?> 5-page mobile-friendly website</li>
                <li><?= icon('check') ?> Google Business Profile &amp; basic SEO</li>
                <li><?= icon('check') ?> Logo refresh &amp; brand colors</li>
                <li><?= icon('check') ?> Photo session for your website &amp; socials</li>
            </ul>
        </div>
        <div class="promo__card">
            <?php if ($launchPack['price_from'] !== null): ?>
                <p class="promo__from">All of it, from</p>
                <p class="promo__price"><?= e(money($launchPack['price_from'])) ?></p>
            <?php endif; ?>
            <a class="btn btn--primary btn--lg btn--block" href="<?= e(url('book.php?service=' . rawurlencode($launchPack['slug']))) ?>">Let's launch my business</a>
            <p class="promo__note">Starts with a free <?= e(format_duration((int) $launchPack['duration_minutes'])) ?> planning call.</p>
        </div>
    </div>
</section>
<?php endif; ?>

<section class="section section--tint" id="process" aria-labelledby="process-title">
    <div class="container">
        <header class="section__head">
            <p class="eyebrow">How it works</p>
            <h2 id="process-title" class="section__title">From “I need…” to launch day in four steps.</h2>
        </header>
        <ol class="steps">
            <li class="step">
                <span class="step__num">01</span>
                <h3>Book a call</h3>
                <p>Pick a service and a time that suits you. It takes about a minute.</p>
            </li>
            <li class="step">
                <span class="step__num">02</span>
                <h3>Plan together</h3>
                <p>We talk about your goals, customers and budget. You get a clear proposal and timeline.</p>
            </li>
            <li class="step">
                <span class="step__num">03</span>
                <h3>Create</h3>
                <p>I design, build, write and shoot, sharing progress so you're never left guessing.</p>
            </li>
            <li class="step">
                <span class="step__num">04</span>
                <h3>Launch &amp; grow</h3>
                <p>We go live, measure results and keep improving your visibility and content.</p>
            </li>
        </ol>
    </div>
</section>

<?php if ($portfolio): ?>
<section class="section" id="work" aria-labelledby="work-title">
    <div class="container">
        <header class="section__head">
            <p class="eyebrow">Selected work</p>
            <h2 id="work-title" class="section__title">Recent projects.</h2>
        </header>
        <div class="work-grid">
            <?php foreach ($portfolio as $item): ?>
                <a class="work-card" href="<?= e($item['url'] ?? '#') ?>" <?= !empty($item['url']) ? 'target="_blank" rel="noopener"' : '' ?>>
                    <?php if (!empty($item['image'])): ?><img src="<?= e(url($item['image'])) ?>" alt="" loading="lazy"><?php endif; ?>
                    <span class="work-card__meta"><?= e($item['category'] ?? '') ?></span>
                    <strong class="work-card__title"><?= e($item['title'] ?? '') ?></strong>
                </a>
            <?php endforeach; ?>
        </div>
    </div>
</section>
<?php endif; ?>

<section class="section" id="about" aria-labelledby="about-title">
    <div class="container about">
        <div class="about__portrait" aria-hidden="true">
            <span><?= e(mb_substr($owner, 0, 1)) ?></span>
        </div>
        <div>
            <p class="eyebrow">About</p>
            <h2 id="about-title" class="section__title">Hi, I'm <?= e($owner) ?>.</h2>
            <p class="section__lead">
                I'm a web developer and digital creative who helps small businesses show up online the right way.
                Instead of juggling a developer, an SEO agency, a designer and a photographer, you get one person who
                understands your whole brand and makes every piece work together.
            </p>
            <ul class="about__points">
                <li><?= icon('users') ?><div><strong>One point of contact</strong><span>No hand-offs, no agency overheads, no mixed messages.</span></div></li>
                <li><?= icon('layers') ?><div><strong>Consistent everywhere</strong><span>Your website, socials and print all look and sound like you.</span></div></li>
                <li><?= icon('search') ?><div><strong>Built to be found</strong><span>SEO is baked in from day one, not bolted on later.</span></div></li>
                <li><?= icon('key') ?><div><strong>You own it all</strong><span>Your domain, your code, your photos. No lock-in.</span></div></li>
            </ul>
        </div>
    </div>
</section>

<section class="section section--tint" id="faq" aria-labelledby="faq-title">
    <div class="container container--narrow">
        <header class="section__head">
            <p class="eyebrow">FAQ</p>
            <h2 id="faq-title" class="section__title">Good questions.</h2>
        </header>
        <div class="faq">
            <details>
                <summary>What happens in the free consultation?</summary>
                <p>We spend about 30 minutes on your business, your goals and what's working today. You leave with honest advice and, if we're a good fit, a clear proposal with price and timeline.</p>
            </details>
            <details>
                <summary>How much does a website cost?</summary>
                <p>Simple business websites start from the price shown above. The final quote depends on pages, features (like bookings or a shop) and content. You'll always know the price before any work starts.</p>
            </details>
            <details>
                <summary>How long does a project take?</summary>
                <p>A small business website usually takes 2–4 weeks from the moment content is ready. Web apps are planned in milestones so you see progress every week.</p>
            </details>
            <details>
                <summary>Can I update my website myself?</summary>
                <p>Yes. I build on tools you can manage (like WordPress) and give you a short walkthrough so you can edit text, photos and prices without calling anyone.</p>
            </details>
            <details>
                <summary>Do you work with businesses outside my area?</summary>
                <p>Absolutely. Websites, web apps, SEO, design and content can all be done remotely. Photo and video shoots happen on location, and travel can be arranged.</p>
            </details>
            <details>
                <summary>Can I change or cancel my booking?</summary>
                <p>Of course. Your confirmation email has a private link where you can add the meeting to your calendar or cancel it. Then simply book a new time.</p>
            </details>
        </div>
    </div>
</section>

<section class="cta">
    <div class="container cta__inner">
        <h2 class="cta__title">Ready to grow your business online?</h2>
        <p class="cta__text">Pick a time that works for you. The first conversation is free and there's no obligation.</p>
        <a class="btn btn--primary btn--lg" href="<?= e(url('book.php')) ?>"><?= icon('calendar') ?> Book your free consultation</a>
    </div>
</section>

<?php site_footer(); ?>
