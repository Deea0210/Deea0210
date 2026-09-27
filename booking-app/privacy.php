<?php
declare(strict_types=1);
require __DIR__ . '/includes/bootstrap.php';

$brand = (string) config('app.brand_name');
$email = (string) config('app.email');

site_header([
    'title'       => 'Privacy notice — ' . $brand,
    'description' => 'How ' . $brand . ' uses the personal details you share when booking a consultation.',
    'canonical'   => absolute_url('privacy.php'),
]);
?>
<section class="page-hero">
    <div class="container container--narrow">
        <p class="eyebrow">Privacy</p>
        <h1 class="page-hero__title">Privacy notice</h1>
        <p class="page-hero__lead">Short and in plain English. Last updated <?= e(date('F Y', (int) filemtime(__FILE__))) ?>.</p>
    </div>
</section>
<section class="section section--flush">
    <div class="container container--narrow prose">
        <h2>Who is responsible for your data</h2>
        <p><?= e(config('app.owner_name')) ?> (“<?= e($brand) ?>”), contactable at <a href="mailto:<?= e($email) ?>"><?= e($email) ?></a>.</p>

        <h2>What I collect and why</h2>
        <p>When you book, I collect your name, email, and optionally your phone number, business name, website, budget and project description. I use them only to arrange and hold our meeting, prepare a proposal and reply to you. The legal basis is taking steps at your request before entering into a contract, and your consent.</p>

        <h2>How long I keep it</h2>
        <p>Booking details are kept for up to 24 months after our last contact, or longer only if we work together and the law requires it (for example, for invoicing).</p>

        <h2>Who I share it with</h2>
        <p>Nobody, except the hosting and email providers needed to run this website. I never sell your data or use it for advertising.</p>

        <h2>Cookies</h2>
        <p>This site uses a single, strictly necessary session cookie to keep the booking form secure. There are no tracking or advertising cookies.</p>

        <h2>Your rights</h2>
        <p>You can ask to see, correct, export or delete your data at any time by emailing <a href="mailto:<?= e($email) ?>"><?= e($email) ?></a>. You also have the right to complain to your local data protection authority.</p>
    </div>
</section>
<?php site_footer(); ?>
