<?php
declare(strict_types=1);
require __DIR__ . '/includes/bootstrap.php';

$ref = (string) ($_GET['ref'] ?? $_POST['ref'] ?? '');
$token = (string) ($_GET['token'] ?? $_POST['token'] ?? '');
$booking = find_booking_by_token($ref, $token);

if (!$booking) {
    http_response_code(404);
    site_header(['title' => 'Booking not found', 'noindex' => true]);
    ?>
    <section class="page-hero"><div class="container container--narrow">
        <h1 class="page-hero__title">We couldn't find that booking.</h1>
        <p class="page-hero__lead">Please use the link from your confirmation email, or <a href="mailto:<?= e(config('app.email')) ?>">get in touch</a>.</p>
        <p><a class="btn btn--primary" href="<?= e(url('book.php')) ?>">Make a new booking</a></p>
    </div></section>
    <?php
    site_footer();
    exit;
}

$selfUrl = 'manage.php?ref=' . rawurlencode($booking['reference']) . '&token=' . rawurlencode($token);

if (is_post() && post('action') === 'cancel') {
    verify_csrf();
    if (booking_is_upcoming($booking)) {
        db_run("UPDATE bookings SET status = 'cancelled' WHERE id = ?", [$booking['id']]);
        $booking['status'] = 'cancelled';
        notify_client_cancelled($booking);
        flash('success', 'Your booking has been cancelled.');
    }
    redirect($selfUrl);
}

$isNew = isset($_GET['new']);
$upcoming = booking_is_upcoming($booking);

header('Cache-Control: no-store');
site_header(['title' => 'Your booking ' . format_reference($booking['reference']), 'noindex' => true, 'body_class' => 'page-manage']);
?>

<section class="page-hero">
    <div class="container container--narrow">
        <?php render_flashes(); ?>
        <?php if ($isNew): ?>
            <div class="success-mark" aria-hidden="true"><?= icon('check') ?></div>
            <p class="eyebrow">Request received</p>
            <h1 class="page-hero__title">Thank you, <?= e(explode(' ', trim($booking['client_name']))[0]) ?>!</h1>
            <p class="page-hero__lead">Your booking request is in. I'll review it and confirm by email at <strong><?= e($booking['client_email']) ?></strong>. Keep this page's link: it's how you can view or cancel your booking.</p>
        <?php else: ?>
            <p class="eyebrow">Your booking</p>
            <h1 class="page-hero__title"><?= e($booking['service_name']) ?></h1>
        <?php endif; ?>
    </div>
</section>

<section class="section section--flush">
    <div class="container container--narrow">
        <div class="ticket">
            <div class="ticket__head">
                <span class="ticket__icon"><?= icon($booking['service_icon']) ?></span>
                <div>
                    <strong><?= e($booking['service_name']) ?></strong>
                    <span>Ref. <?= e(format_reference($booking['reference'])) ?></span>
                </div>
                <?= status_badge($booking['status']) ?>
            </div>
            <dl class="ticket__grid">
                <div><dt>Date</dt><dd><?= e(format_date($booking['start_at'])) ?></dd></div>
                <div><dt>Time</dt><dd><?= e(format_time($booking['start_at'])) ?> – <?= e(format_time($booking['end_at'])) ?> <small>(<?= e(str_replace('_', ' ', (string) config('app.timezone'))) ?>)</small></dd></div>
                <div><dt>Meeting</dt><dd><?= e(meeting_type_label($booking['meeting_type'])) ?></dd></div>
                <div><dt>Name</dt><dd><?= e($booking['client_name']) ?></dd></div>
            </dl>
            <?php if ($upcoming): ?>
                <div class="ticket__actions">
                    <a class="btn btn--dark" href="<?= e(url('ics.php?ref=' . rawurlencode($booking['reference']) . '&token=' . rawurlencode($token))) ?>"><?= icon('download') ?> Add to calendar</a>
                    <form method="post" action="<?= e(url($selfUrl)) ?>" data-confirm="Cancel this booking?">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="cancel">
                        <button class="btn btn--ghost" type="submit">Cancel booking</button>
                    </form>
                </div>
            <?php endif; ?>
        </div>

        <div class="next-steps">
            <h2>What happens next?</h2>
            <ol>
                <li>I'll review your details and confirm the time by email.</li>
                <li>You'll receive <?= $booking['meeting_type'] === 'online' ? 'a video call link' : ($booking['meeting_type'] === 'phone' ? 'a call at the number you provide' : 'the meeting location details') ?> before we meet.</li>
                <li>After our chat, you'll get a clear proposal with price and timeline.</li>
            </ol>
            <p>Questions? Email <a href="mailto:<?= e(config('app.email')) ?>"><?= e(config('app.email')) ?></a>.</p>
        </div>
    </div>
</section>

<?php site_footer(); ?>
