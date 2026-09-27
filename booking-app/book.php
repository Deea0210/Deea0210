<?php
declare(strict_types=1);
require __DIR__ . '/includes/bootstrap.php';

$services = active_services();
$bySlug = array_column($services, null, 'slug');
$budgets = (array) config('booking.budgets', []);

$old = [
    'service'       => (string) ($_GET['service'] ?? ''),
    'date'          => '',
    'time'          => '',
    'meeting_type'  => '',
    'client_name'   => '',
    'client_email'  => '',
    'client_phone'  => '',
    'business_name' => '',
    'website'       => '',
    'budget'        => '',
    'message'       => '',
    'consent'       => '',
];
$errors = [];

if (is_post()) {
    verify_csrf();

    // Honeypot: real people never see or fill this field.
    if (post('fax_number') !== '') {
        redirect('');
    }

    foreach (array_keys($old) as $key) {
        $old[$key] = post($key);
    }
    // Names and similar fields must stay on one line (they end up in email subjects).
    foreach (['client_name', 'client_phone', 'business_name', 'website'] as $key) {
        $old[$key] = trim((string) preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $old[$key]));
    }

    $service = $bySlug[$old['service']] ?? null;
    if (!$service) {
        $errors['service'] = 'Please choose a service.';
    }

    $start = DateTimeImmutable::createFromFormat('!Y-m-d H:i', $old['date'] . ' ' . $old['time']);
    if (!$start || $start->format('Y-m-d H:i') !== $old['date'] . ' ' . $old['time']) {
        $errors['time'] = 'Please pick a date and time in the calendar.';
        $start = null;
    }

    if ($service && !array_key_exists($old['meeting_type'], service_meeting_types($service))) {
        $errors['meeting_type'] = 'Please choose how you would like to meet.';
    }

    $nameLength = mb_strlen($old['client_name']);
    if ($nameLength < 2 || $nameLength > 120) {
        $errors['client_name'] = 'Please enter your name.';
    }
    if (!filter_var($old['client_email'], FILTER_VALIDATE_EMAIL) || mb_strlen($old['client_email']) > 190) {
        $errors['client_email'] = 'Please enter a valid email address.';
    }
    if ($old['client_phone'] !== '' && !preg_match('/^[0-9+()\/.\s-]{6,40}$/', $old['client_phone'])) {
        $errors['client_phone'] = 'Please enter a valid phone number (or leave it empty).';
    }
    if (mb_strlen($old['business_name']) > 160) {
        $errors['business_name'] = 'That business name is a little long.';
    }
    if ($old['website'] !== '') {
        $site = preg_match('~^https?://~i', $old['website']) ? $old['website'] : 'https://' . $old['website'];
        if (!filter_var($site, FILTER_VALIDATE_URL) || mb_strlen($site) > 255) {
            $errors['website'] = 'Please enter a valid web address (or leave it empty).';
        } else {
            $old['website'] = $site;
        }
    }
    if ($old['budget'] !== '' && !in_array($old['budget'], $budgets, true)) {
        $errors['budget'] = 'Please choose a budget range.';
    }
    $messageLength = mb_strlen($old['message']);
    if ($messageLength < 10 || $messageLength > 5000) {
        $errors['message'] = 'Tell me a little about your project (at least 10 characters).';
    }
    if ($old['consent'] !== '1') {
        $errors['consent'] = 'Please agree so I can use your details to handle your booking.';
    }

    if (!$errors) {
        $open = (int) db_value(
            "SELECT COUNT(*) FROM bookings WHERE client_email = ? AND status IN ('pending','confirmed') AND start_at > NOW()",
            [$old['client_email']]
        );
        if ($open >= (int) config('booking.max_open_per_email', 3)) {
            $errors['form'] = 'You already have several upcoming bookings. Please manage them from your confirmation emails, or get in touch directly.';
        }
    }

    if (!$errors && $service && $start) {
        try {
            $created = create_booking($service, $start, $old);
            $booking = find_booking($created['id']);
            notify_booking_created($booking, $created['token']);
            redirect('manage.php?ref=' . rawurlencode($created['reference']) . '&token=' . rawurlencode($created['token']) . '&new=1');
        } catch (SlotUnavailable) {
            $errors['time'] = 'Sorry, that time was just booked by someone else. Please choose another one.';
            $old['time'] = '';
        }
    }
}

$selected = $bySlug[$old['service']] ?? null;

site_header([
    'title'       => 'Book a consultation — ' . config('app.brand_name'),
    'description' => 'Book a free consultation for a website, web app, SEO, content, graphic design, photography or video project.',
    'canonical'   => absolute_url('book.php'),
    'body_class'  => 'page-book',
]);

$field = static function (string $name) use ($errors): string {
    return isset($errors[$name])
        ? ' aria-invalid="true" aria-describedby="' . e($name) . '-error"'
        : '';
};
$error = static function (string $name) use ($errors): string {
    return isset($errors[$name]) ? '<p class="field__error" id="' . e($name) . '-error">' . e($errors[$name]) . '</p>' : '';
};
?>

<section class="page-hero">
    <div class="container">
        <p class="eyebrow">Book online</p>
        <h1 class="page-hero__title">Let's talk about your project.</h1>
        <p class="page-hero__lead">Choose a service, pick a time that suits you and tell me a little about what you need. You'll get a confirmation by email.</p>
    </div>
</section>

<section class="section section--flush">
    <div class="container">
        <?php if ($errors): ?>
            <div class="alert alert--error" role="alert" tabindex="-1" data-focus>
                <strong>Please check the form.</strong>
                <?= isset($errors['form']) ? e($errors['form']) : 'Some details need your attention below.' ?>
            </div>
        <?php endif; ?>

        <noscript><div class="alert alert--error">The booking calendar needs JavaScript. Please enable it, or email <a href="mailto:<?= e(config('app.email')) ?>"><?= e(config('app.email')) ?></a>.</div></noscript>

        <form class="booking" method="post" action="<?= e(url('book.php')) ?>" novalidate data-booking
              data-api="<?= e(url('api/availability.php')) ?>"
              data-horizon="<?= e(booking_horizon()->format('Y-m-d')) ?>"
              data-today="<?= e(date('Y-m-d')) ?>">
            <?= csrf_field() ?>
            <div class="booking__main">

                <fieldset class="panel">
                    <legend class="panel__title"><span class="panel__num">1</span> What can I help you with?</legend>
                    <?= $error('service') ?>
                    <div class="service-options">
                        <?php foreach ($services as $service): ?>
                            <label class="service-option">
                                <input type="radio" name="service" value="<?= e($service['slug']) ?>"
                                       data-name="<?= e($service['name']) ?>"
                                       data-duration="<?= (int) $service['duration_minutes'] ?>"
                                       data-duration-label="<?= e(format_duration((int) $service['duration_minutes'])) ?>"
                                       data-price="<?= $service['price_from'] !== null ? e('from ' . money($service['price_from'])) : 'Custom quote' ?>"
                                       data-meetings="<?= e(json_encode(service_meeting_types($service))) ?>"
                                       <?= $old['service'] === $service['slug'] ? 'checked' : '' ?> required>
                                <span class="service-option__box">
                                    <span class="service-option__icon"><?= icon($service['icon']) ?></span>
                                    <span class="service-option__text">
                                        <strong><?= e($service['tagline'] ?: $service['name']) ?></strong>
                                        <small><?= e($service['name']) ?> · <?= e(format_duration((int) $service['duration_minutes'])) ?></small>
                                    </span>
                                </span>
                            </label>
                        <?php endforeach; ?>
                    </div>
                </fieldset>

                <fieldset class="panel" data-step="time">
                    <legend class="panel__title"><span class="panel__num">2</span> Pick a date &amp; time</legend>
                    <p class="panel__hint">Times are shown in <?= e(str_replace('_', ' ', (string) config('app.timezone'))) ?> time.</p>
                    <?= $error('time') ?>
                    <input type="hidden" name="date" value="<?= e($old['date']) ?>" data-date-input>
                    <input type="hidden" name="time" value="<?= e($old['time']) ?>" data-time-input>
                    <div class="scheduler" data-scheduler>
                        <div class="calendar" data-calendar>
                            <div class="calendar__head">
                                <button type="button" class="icon-btn" data-cal-prev aria-label="Previous month"><?= icon('chevron-left') ?></button>
                                <strong class="calendar__month" data-cal-label aria-live="polite"></strong>
                                <button type="button" class="icon-btn" data-cal-next aria-label="Next month"><?= icon('chevron-right') ?></button>
                            </div>
                            <div class="calendar__grid calendar__grid--dow" aria-hidden="true">
                                <span>Mo</span><span>Tu</span><span>We</span><span>Th</span><span>Fr</span><span>Sa</span><span>Su</span>
                            </div>
                            <div class="calendar__grid" data-cal-days role="group" aria-label="Available days"></div>
                        </div>
                        <div class="times">
                            <p class="times__title" data-times-title>Choose a service to see available times.</p>
                            <div class="times__list" data-times role="group" aria-label="Available times"></div>
                        </div>
                    </div>
                </fieldset>

                <fieldset class="panel">
                    <legend class="panel__title"><span class="panel__num">3</span> Your details</legend>
                    <div class="form-grid">
                        <div class="field">
                            <label for="client_name">Full name <span aria-hidden="true">*</span></label>
                            <input id="client_name" name="client_name" type="text" autocomplete="name" maxlength="120" required value="<?= e($old['client_name']) ?>"<?= $field('client_name') ?>>
                            <?= $error('client_name') ?>
                        </div>
                        <div class="field">
                            <label for="client_email">Email <span aria-hidden="true">*</span></label>
                            <input id="client_email" name="client_email" type="email" autocomplete="email" maxlength="190" required value="<?= e($old['client_email']) ?>"<?= $field('client_email') ?>>
                            <?= $error('client_email') ?>
                        </div>
                        <div class="field">
                            <label for="client_phone">Phone <small>(optional)</small></label>
                            <input id="client_phone" name="client_phone" type="tel" autocomplete="tel" maxlength="40" value="<?= e($old['client_phone']) ?>"<?= $field('client_phone') ?>>
                            <?= $error('client_phone') ?>
                        </div>
                        <div class="field">
                            <label for="business_name">Business name <small>(optional)</small></label>
                            <input id="business_name" name="business_name" type="text" autocomplete="organization" maxlength="160" value="<?= e($old['business_name']) ?>"<?= $field('business_name') ?>>
                            <?= $error('business_name') ?>
                        </div>
                        <div class="field">
                            <label for="website">Current website <small>(optional)</small></label>
                            <input id="website" name="website" type="text" inputmode="url" placeholder="yourbusiness.com" maxlength="255" value="<?= e($old['website']) ?>"<?= $field('website') ?>>
                            <?= $error('website') ?>
                        </div>
                        <div class="field">
                            <label for="budget">Budget <small>(optional)</small></label>
                            <select id="budget" name="budget"<?= $field('budget') ?>>
                                <option value="">Choose a range…</option>
                                <?= options($budgets, $old['budget'], false) ?>
                            </select>
                            <?= $error('budget') ?>
                        </div>
                        <div class="field field--full">
                            <label for="meeting_type">How should we meet? <span aria-hidden="true">*</span></label>
                            <select id="meeting_type" name="meeting_type" required data-meeting-select data-selected="<?= e($old['meeting_type']) ?>"<?= $field('meeting_type') ?>>
                                <?php foreach ($selected ? service_meeting_types($selected) : config('booking.meeting_types') as $key => $label): ?>
                                    <option value="<?= e($key) ?>" <?= $key === $old['meeting_type'] ? 'selected' : '' ?>><?= e($label) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <?= $error('meeting_type') ?>
                        </div>
                        <div class="field field--full">
                            <label for="message">Tell me about your project <span aria-hidden="true">*</span></label>
                            <textarea id="message" name="message" rows="5" maxlength="5000" required placeholder="What does your business do, what would you like to achieve, and is there a deadline?"<?= $field('message') ?>><?= e($old['message']) ?></textarea>
                            <?= $error('message') ?>
                        </div>
                        <div class="field field--hp" aria-hidden="true">
                            <label for="fax_number">Leave this empty</label>
                            <input id="fax_number" name="fax_number" type="text" tabindex="-1" autocomplete="off">
                        </div>
                        <div class="field field--full">
                            <label class="checkbox">
                                <input type="checkbox" name="consent" value="1" required <?= $old['consent'] === '1' ? 'checked' : '' ?><?= $field('consent') ?>>
                                <span>I agree that my details are used to handle this booking, as described in the <a href="<?= e(url('privacy.php')) ?>" target="_blank">privacy notice</a>.</span>
                            </label>
                            <?= $error('consent') ?>
                        </div>
                    </div>
                </fieldset>
            </div>

            <aside class="booking__aside">
                <div class="summary" data-summary>
                    <h2 class="summary__title">Your booking</h2>
                    <dl class="summary__list">
                        <div><dt><?= icon('layers') ?> Service</dt><dd data-sum-service><?= $selected ? e($selected['name']) : '—' ?></dd></div>
                        <div><dt><?= icon('calendar') ?> Date</dt><dd data-sum-date>—</dd></div>
                        <div><dt><?= icon('clock') ?> Time</dt><dd data-sum-time>—</dd></div>
                        <div><dt><?= icon('star') ?> Price</dt><dd data-sum-price><?= $selected ? e($selected['price_from'] !== null ? 'from ' . money($selected['price_from']) : 'Custom quote') : '—' ?></dd></div>
                    </dl>
                    <button class="btn btn--primary btn--lg btn--block" type="submit" data-submit>Request booking <?= icon('arrow-right') ?></button>
                    <p class="summary__note">No payment now. I'll confirm your booking by email, usually within one working day.</p>
                </div>
            </aside>
        </form>
    </div>
</section>

<script src="<?= e(asset('js/book.js')) ?>" defer></script>
<?php site_footer(); ?>
