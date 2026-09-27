<?php
declare(strict_types=1);
defined('APP_ROOT') || exit;

/**
 * Public page header.
 *
 * @param array{title?:string, description?:string, canonical?:string, body_class?:string, head?:string, noindex?:bool} $page
 */
function site_header(array $page = []): void
{
    $brand = (string) config('app.brand_name');
    $title = $page['title'] ?? $brand . ' — ' . config('app.role');
    $description = $page['description'] ?? 'Websites, web apps, SEO, content, graphic design, photography and video for small businesses. Book a free consultation online.';
    $canonical = $page['canonical'] ?? null;
    ?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($title) ?></title>
    <meta name="description" content="<?= e($description) ?>">
    <?php if (!empty($page['noindex'])): ?><meta name="robots" content="noindex, nofollow"><?php endif; ?>
    <?php if ($canonical): ?><link rel="canonical" href="<?= e($canonical) ?>"><?php endif; ?>
    <meta property="og:type" content="website">
    <meta property="og:title" content="<?= e($title) ?>">
    <meta property="og:description" content="<?= e($description) ?>">
    <meta property="og:image" content="<?= e(absolute_url('assets/img/og-image.png')) ?>">
    <meta property="og:site_name" content="<?= e($brand) ?>">
    <meta name="twitter:card" content="summary_large_image">
    <meta name="theme-color" content="#16131f">
    <link rel="icon" href="<?= e(url('assets/img/favicon.svg')) ?>" type="image/svg+xml">
    <link rel="preload" href="<?= e(url('assets/fonts/bricolage-grotesque-latin-wght-normal.woff2')) ?>" as="font" type="font/woff2" crossorigin>
    <link rel="preload" href="<?= e(url('assets/fonts/inter-latin-wght-normal.woff2')) ?>" as="font" type="font/woff2" crossorigin>
    <link rel="stylesheet" href="<?= e(asset('css/app.css')) ?>">
    <?= $page['head'] ?? '' ?>
</head>
<body class="<?= e($page['body_class'] ?? '') ?>">
<a class="skip-link" href="#main">Skip to content</a>
<header class="site-header" data-header>
    <div class="container site-header__inner">
        <a class="logo" href="<?= e(url('')) ?>" aria-label="<?= e($brand) ?> — home">
            <span class="logo__mark" aria-hidden="true"><?= e(mb_substr($brand, 0, 1)) ?></span>
            <span class="logo__text"><?= e(strtolower($brand)) ?><span class="logo__dot">.</span></span>
        </a>
        <button class="nav-toggle" type="button" aria-expanded="false" aria-controls="site-nav" data-nav-toggle>
            <span class="sr-only">Menu</span><?= icon('menu') ?>
        </button>
        <nav class="site-nav" id="site-nav" aria-label="Main">
            <a href="<?= e(url('#services')) ?>">Services</a>
            <a href="<?= e(url('#process')) ?>">How it works</a>
            <a href="<?= e(url('#about')) ?>">About</a>
            <a href="<?= e(url('#faq')) ?>">FAQ</a>
            <a class="btn btn--primary btn--sm" href="<?= e(url('book.php')) ?>">Book a call <?= icon('arrow-right') ?></a>
        </nav>
    </div>
</header>
<main id="main">
    <?php
}

function site_footer(): void
{
    $brand = (string) config('app.brand_name');
    $social = array_filter((array) config('app.social', []));
    ?>
</main>
<footer class="site-footer">
    <div class="container site-footer__grid">
        <div>
            <a class="logo logo--light" href="<?= e(url('')) ?>">
                <span class="logo__mark" aria-hidden="true"><?= e(mb_substr($brand, 0, 1)) ?></span>
                <span class="logo__text"><?= e(strtolower($brand)) ?><span class="logo__dot">.</span></span>
            </a>
            <p class="site-footer__tagline"><?= e(config('app.tagline')) ?></p>
        </div>
        <div>
            <h2 class="site-footer__heading">Contact</h2>
            <ul class="site-footer__list">
                <li><?= icon('mail') ?><a href="mailto:<?= e(config('app.email')) ?>"><?= e(config('app.email')) ?></a></li>
                <?php if (config('app.phone')): ?>
                    <li><?= icon('phone') ?><a href="tel:<?= e(preg_replace('/[^0-9+]/', '', (string) config('app.phone'))) ?>"><?= e(config('app.phone')) ?></a></li>
                <?php endif; ?>
                <li><?= icon('map-pin') ?><span><?= e(config('app.location')) ?></span></li>
            </ul>
        </div>
        <div>
            <h2 class="site-footer__heading">Explore</h2>
            <ul class="site-footer__list">
                <li><a href="<?= e(url('book.php')) ?>">Book a consultation</a></li>
                <li><a href="<?= e(url('#services')) ?>">Services &amp; prices</a></li>
                <li><a href="<?= e(url('privacy.php')) ?>">Privacy notice</a></li>
                <?php foreach ($social as $network => $link): ?>
                    <li><a href="<?= e($link) ?>" rel="me noopener" target="_blank"><?= e(ucfirst($network)) ?></a></li>
                <?php endforeach; ?>
            </ul>
        </div>
    </div>
    <div class="container site-footer__bottom">
        <p>© <?= date('Y') ?> <?= e($brand) ?>. All rights reserved.</p>
    </div>
</footer>
<script src="<?= e(asset('js/site.js')) ?>" defer></script>
</body>
</html>
    <?php
}

function render_flashes(): void
{
    foreach (take_flashes() as $flash) {
        echo '<div class="alert alert--' . e($flash['type']) . '" role="status">' . e($flash['message']) . '</div>';
    }
}

/* ---------- Admin layout ---------- */

function admin_header(string $title, string $active = '', string $bodyClass = ''): void
{
    $admin = current_admin();
    $nav = [
        'bookings'     => ['admin/index.php', 'Bookings', 'calendar'],
        'services'     => ['admin/services.php', 'Services', 'grid'],
        'availability' => ['admin/availability.php', 'Availability', 'clock'],
        'mail'         => ['admin/mail-log.php', 'Email log', 'mail'],
        'account'      => ['admin/account.php', 'Account', 'key'],
    ];
    ?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title><?= e($title) ?> · <?= e(config('app.brand_name')) ?> Admin</title>
    <link rel="icon" href="<?= e(url('assets/img/favicon.svg')) ?>" type="image/svg+xml">
    <link rel="stylesheet" href="<?= e(asset('css/app.css')) ?>">
    <link rel="stylesheet" href="<?= e(asset('css/admin.css')) ?>">
</head>
<body class="admin <?= e($bodyClass) ?>">
<a class="skip-link" href="#main">Skip to content</a>
<?php if ($admin): ?>
<aside class="admin-sidebar">
    <a class="logo logo--light" href="<?= e(url('admin/index.php')) ?>">
        <span class="logo__mark" aria-hidden="true"><?= e(mb_substr((string) config('app.brand_name'), 0, 1)) ?></span>
        <span class="logo__text">admin<span class="logo__dot">.</span></span>
    </a>
    <nav class="admin-nav" aria-label="Admin">
        <?php foreach ($nav as $key => [$href, $label, $ico]): ?>
            <a href="<?= e(url($href)) ?>" <?= $key === $active ? 'aria-current="page"' : '' ?>><?= icon($ico) ?><span><?= e($label) ?></span></a>
        <?php endforeach; ?>
    </nav>
    <div class="admin-sidebar__foot">
        <a href="<?= e(url('')) ?>" target="_blank" rel="noopener"><?= icon('external') ?><span>View site</span></a>
        <form method="post" action="<?= e(url('admin/logout.php')) ?>">
            <?= csrf_field() ?>
            <button type="submit"><?= icon('logout') ?><span>Log out <?= e($admin['username']) ?></span></button>
        </form>
    </div>
</aside>
<?php endif; ?>
<main id="main" class="admin-main">
    <?php render_flashes(); ?>
    <?php
}

function admin_footer(): void
{
    ?>
</main>
<script src="<?= e(asset('js/site.js')) ?>" defer></script>
</body>
</html>
    <?php
}
