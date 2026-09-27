<?php
declare(strict_types=1);
require dirname(__DIR__) . '/includes/bootstrap.php';
require_admin();

$id = (int) ($_GET['id'] ?? 0);
$booking = find_booking($id);
if (!$booking) {
    flash('error', 'Booking not found.');
    redirect('admin/index.php');
}
$self = 'admin/booking.php?id=' . $id;

if (is_post()) {
    verify_csrf();
    $action = post('action');

    if ($action === 'status') {
        $status = post('status');
        if (!array_key_exists($status, BOOKING_STATUSES)) {
            flash('error', 'Unknown status.');
            redirect($self);
        }
        if ($status !== $booking['status'] && in_array($status, ['pending', 'confirmed'], true) && $booking['status'] === 'cancelled') {
            // Re-opening a cancelled booking: make sure the time is still free.
            $overlap = busy_periods(new DateTimeImmutable($booking['start_at']), new DateTimeImmutable($booking['end_at']), $id);
            if ($overlap) {
                flash('error', 'That time is now taken by another booking, so this one cannot be re-opened.');
                redirect($self);
            }
        }
        db_run('UPDATE bookings SET status = ? WHERE id = ?', [$status, $id]);
        if (post('notify') === '1' && $status !== $booking['status']) {
            notify_status_changed(find_booking($id), post('note'));
            flash('success', 'Status updated to ' . BOOKING_STATUSES[$status] . ' and the client was emailed.');
        } else {
            flash('success', 'Status updated to ' . BOOKING_STATUSES[$status] . '.');
        }
        redirect($self);
    }

    if ($action === 'notes') {
        db_run('UPDATE bookings SET admin_notes = ? WHERE id = ?', [mb_substr(post('admin_notes'), 0, 10000), $id]);
        flash('success', 'Notes saved.');
        redirect($self);
    }

    if ($action === 'delete') {
        db_run('DELETE FROM bookings WHERE id = ?', [$id]);
        flash('success', 'Booking ' . format_reference($booking['reference']) . ' deleted.');
        redirect('admin/index.php');
    }
}

admin_header('Booking ' . format_reference($booking['reference']), 'bookings');
$phone = preg_replace('/[^0-9+]/', '', $booking['client_phone']);
?>
<p><a class="link-back" href="<?= e(url('admin/index.php')) ?>"><?= icon('arrow-left') ?> All bookings</a></p>

<header class="admin-head">
    <div>
        <h1><?= e($booking['client_name']) ?></h1>
        <p class="muted"><?= e($booking['service_name']) ?> · Ref. <?= e(format_reference($booking['reference'])) ?> · booked <?= e(format_date($booking['created_at'], 'j M Y, H:i')) ?></p>
    </div>
    <?= status_badge($booking['status']) ?>
</header>

<div class="admin-grid">
    <div class="stack">
        <section class="card">
            <h2 class="card__title">Appointment</h2>
            <dl class="details">
                <div><dt>Date</dt><dd><?= e(format_date($booking['start_at'])) ?></dd></div>
                <div><dt>Time</dt><dd><?= e(format_time($booking['start_at'])) ?> – <?= e(format_time($booking['end_at'])) ?></dd></div>
                <div><dt>Service</dt><dd><?= e($booking['service_name']) ?></dd></div>
                <div><dt>Meeting</dt><dd><?= e(meeting_type_label($booking['meeting_type'])) ?></dd></div>
                <div><dt>Budget</dt><dd><?= e($booking['budget'] ?: '—') ?></dd></div>
            </dl>
        </section>

        <section class="card">
            <h2 class="card__title">Client</h2>
            <dl class="details">
                <div><dt>Name</dt><dd><?= e($booking['client_name']) ?></dd></div>
                <div><dt>Email</dt><dd><a href="mailto:<?= e($booking['client_email']) ?>"><?= e($booking['client_email']) ?></a></dd></div>
                <div><dt>Phone</dt><dd><?= $booking['client_phone'] ? '<a href="tel:' . e($phone) . '">' . e($booking['client_phone']) . '</a>' : '—' ?></dd></div>
                <div><dt>Business</dt><dd><?= e($booking['business_name'] ?: '—') ?></dd></div>
                <div><dt>Website</dt><dd><?= $booking['website'] ? '<a href="' . e($booking['website']) . '" target="_blank" rel="noopener noreferrer">' . e($booking['website']) . '</a>' : '—' ?></dd></div>
            </dl>
            <h3 class="card__subtitle">Project</h3>
            <p class="pre"><?= e($booking['message']) ?></p>
        </section>
    </div>

    <div class="stack">
        <section class="card">
            <h2 class="card__title">Update status</h2>
            <form method="post" class="stack">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="status">
                <div class="segmented" role="radiogroup" aria-label="Status">
                    <?php foreach (BOOKING_STATUSES as $key => $label): ?>
                        <label><input type="radio" name="status" value="<?= e($key) ?>" <?= $key === $booking['status'] ? 'checked' : '' ?>><span><?= e($label) ?></span></label>
                    <?php endforeach; ?>
                </div>
                <label class="checkbox"><input type="checkbox" name="notify" value="1" checked data-toggle-target="#note-field"><span>Email the client about the change</span></label>
                <div class="field" id="note-field">
                    <label for="note">Message to client <small>(optional: meeting link, address, what to prepare…)</small></label>
                    <textarea id="note" name="note" rows="3"></textarea>
                </div>
                <button class="btn btn--primary" type="submit">Save status</button>
            </form>
        </section>

        <section class="card">
            <h2 class="card__title">Private notes</h2>
            <form method="post" class="stack">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="notes">
                <label class="sr-only" for="admin_notes">Private notes</label>
                <textarea id="admin_notes" name="admin_notes" rows="5" placeholder="Only you can see these."><?= e($booking['admin_notes'] ?? '') ?></textarea>
                <button class="btn btn--dark" type="submit">Save notes</button>
            </form>
        </section>

        <section class="card card--danger">
            <h2 class="card__title">Delete booking</h2>
            <p class="muted">Permanently removes this booking and the client's details. Cancelling is usually better.</p>
            <form method="post" data-confirm="Delete this booking permanently? This cannot be undone.">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="delete">
                <button class="btn btn--danger btn--sm" type="submit">Delete permanently</button>
            </form>
        </section>
    </div>
</div>
<?php admin_footer(); ?>
