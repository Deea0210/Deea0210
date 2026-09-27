<?php
declare(strict_types=1);
require dirname(__DIR__) . '/includes/bootstrap.php';
require_admin();

$dayNames = [1 => 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];

if (is_post()) {
    verify_csrf();
    $action = post('action');

    if ($action === 'hours') {
        $rows = (array) ($_POST['hours'] ?? []);
        $valid = static fn($t): bool => is_string($t) && preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $t) === 1;
        foreach ($dayNames as $day => $name) {
            $row = (array) ($rows[$day] ?? []);
            $open = $row['open'] ?? '09:00';
            $close = $row['close'] ?? '17:00';
            $isOpen = ($row['is_open'] ?? '') === '1';
            if (!$valid($open) || !$valid($close) || ($isOpen && $open >= $close)) {
                flash('error', "{$name}: closing time must be after opening time.");
                redirect('admin/availability.php');
            }
            db_run(
                'INSERT INTO working_hours (day_of_week, is_open, open_time, close_time) VALUES (?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE is_open = VALUES(is_open), open_time = VALUES(open_time), close_time = VALUES(close_time)',
                [$day, $isOpen ? 1 : 0, $open . ':00', $close . ':00']
            );
        }
        flash('success', 'Opening hours saved.');
        redirect('admin/availability.php');
    }

    if ($action === 'block') {
        $from = DateTimeImmutable::createFromFormat('!Y-m-d', post('from'));
        $to = post('to') !== '' ? DateTimeImmutable::createFromFormat('!Y-m-d', post('to')) : $from;
        if (!$from || !$to || $to < $from || $from->diff($to)->days > 366) {
            flash('error', 'Please choose a valid date range (up to one year).');
            redirect('admin/availability.php');
        }
        $reason = mb_substr(post('reason'), 0, 160);
        for ($d = $from; $d <= $to; $d = $d->modify('+1 day')) {
            db_run('INSERT IGNORE INTO blocked_dates (blocked_on, reason) VALUES (?, ?)', [$d->format('Y-m-d'), $reason]);
        }
        flash('success', 'Time off added. Clients can no longer book those days.');
        redirect('admin/availability.php');
    }

    if ($action === 'unblock') {
        db_run('DELETE FROM blocked_dates WHERE id = ?', [(int) post('id')]);
        flash('success', 'Day re-opened for bookings.');
        redirect('admin/availability.php');
    }
}

$hours = working_hours();
$blocked = db_all('SELECT * FROM blocked_dates WHERE blocked_on >= CURDATE() ORDER BY blocked_on');
$rules = config('booking');

admin_header('Availability', 'availability');
?>
<header class="admin-head">
    <div>
        <h1>Availability</h1>
        <p class="muted">When clients can book you. Existing bookings are never removed by changes here.</p>
    </div>
</header>

<div class="admin-grid">
    <section class="card">
        <h2 class="card__title">Weekly opening hours</h2>
        <form method="post" class="stack">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="hours">
            <div class="hours">
                <?php foreach ($dayNames as $day => $name):
                    $row = $hours[$day] ?? ['is_open' => 0, 'open_time' => '09:00:00', 'close_time' => '17:00:00']; ?>
                    <div class="hours__row">
                        <label class="checkbox"><input type="checkbox" name="hours[<?= $day ?>][is_open]" value="1" <?= (int) $row['is_open'] ? 'checked' : '' ?>><span><?= e($name) ?></span></label>
                        <label class="sr-only" for="open-<?= $day ?>"><?= e($name) ?> opens</label>
                        <input id="open-<?= $day ?>" type="time" step="900" name="hours[<?= $day ?>][open]" value="<?= e(substr($row['open_time'], 0, 5)) ?>">
                        <span aria-hidden="true">–</span>
                        <label class="sr-only" for="close-<?= $day ?>"><?= e($name) ?> closes</label>
                        <input id="close-<?= $day ?>" type="time" step="900" name="hours[<?= $day ?>][close]" value="<?= e(substr($row['close_time'], 0, 5)) ?>">
                    </div>
                <?php endforeach; ?>
            </div>
            <button class="btn btn--primary" type="submit">Save hours</button>
        </form>
        <p class="muted small">
            Booking rules (edit in <code>config.php</code>): start times every <?= (int) $rules['slot_interval'] ?> min,
            at least <?= (int) $rules['min_notice_hours'] ?> h notice, up to <?= (int) $rules['max_days_ahead'] ?> days ahead,
            <?= (int) $rules['buffer_minutes'] ?> min break between appointments. Timezone: <?= e(config('app.timezone')) ?>.
        </p>
    </section>

    <div class="stack">
        <section class="card">
            <h2 class="card__title">Add time off</h2>
            <form method="post" class="form-grid">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="block">
                <div class="field"><label for="from">From</label><input id="from" type="date" name="from" required min="<?= e(date('Y-m-d')) ?>"></div>
                <div class="field"><label for="to">To <small>(optional)</small></label><input id="to" type="date" name="to" min="<?= e(date('Y-m-d')) ?>"></div>
                <div class="field field--full"><label for="reason">Note <small>(only you see this)</small></label><input id="reason" name="reason" maxlength="160" placeholder="Holiday, shoot day, conference…"></div>
                <div class="field field--full"><button class="btn btn--dark" type="submit">Block these days</button></div>
            </form>
        </section>

        <section class="card">
            <h2 class="card__title">Upcoming time off</h2>
            <?php if (!$blocked): ?>
                <p class="muted">No days blocked.</p>
            <?php else: ?>
                <ul class="list">
                    <?php foreach ($blocked as $day): ?>
                        <li>
                            <span><strong><?= e(format_date($day['blocked_on'], 'D j M Y')) ?></strong> <span class="muted"><?= e($day['reason']) ?></span></span>
                            <form method="post">
                                <?= csrf_field() ?><input type="hidden" name="action" value="unblock"><input type="hidden" name="id" value="<?= (int) $day['id'] ?>">
                                <button class="btn btn--ghost btn--sm" type="submit">Re-open</button>
                            </form>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </section>
    </div>
</div>
<?php admin_footer(); ?>
