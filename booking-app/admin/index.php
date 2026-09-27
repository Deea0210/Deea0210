<?php
declare(strict_types=1);
require dirname(__DIR__) . '/includes/bootstrap.php';
require_admin();

$views = [
    'upcoming'  => 'Upcoming',
    'pending'   => 'Needs confirmation',
    'past'      => 'Past',
    'cancelled' => 'Cancelled',
    'all'       => 'All',
];
$view = array_key_exists($_GET['view'] ?? '', $views) ? $_GET['view'] : 'upcoming';
$q = trim((string) ($_GET['q'] ?? ''));

$where = [];
$params = [];
switch ($view) {
    case 'upcoming':
        $where[] = "b.status IN ('pending','confirmed') AND b.end_at >= NOW()";
        break;
    case 'pending':
        $where[] = "b.status = 'pending'";
        break;
    case 'past':
        $where[] = "b.status IN ('confirmed','completed') AND b.end_at < NOW()";
        break;
    case 'cancelled':
        $where[] = "b.status = 'cancelled'";
        break;
}
if ($q !== '') {
    $where[] = '(b.client_name LIKE ? OR b.client_email LIKE ? OR b.business_name LIKE ? OR b.reference = ?)';
    $like = '%' . addcslashes($q, '%_\\') . '%';
    array_push($params, $like, $like, $like, strtoupper(str_replace('-', '', $q)));
}
$order = in_array($view, ['upcoming', 'pending'], true) ? 'b.start_at ASC' : 'b.start_at DESC';
$bookings = db_all(
    BOOKING_SELECT . ($where ? ' WHERE ' . implode(' AND ', $where) : '') . " ORDER BY {$order} LIMIT 200",
    $params
);

$stats = db_one(
    "SELECT
        SUM(status = 'pending') AS pending,
        SUM(status IN ('pending','confirmed') AND start_at >= NOW() AND DATE(start_at) <= CURDATE() + INTERVAL 7 DAY) AS this_week,
        SUM(status <> 'cancelled' AND YEAR(start_at) = YEAR(CURDATE()) AND MONTH(start_at) = MONTH(CURDATE())) AS this_month,
        COUNT(*) AS total
     FROM bookings"
);

admin_header('Bookings', 'bookings');
?>
<header class="admin-head">
    <div>
        <h1>Bookings</h1>
        <p class="muted">Confirm requests, add notes and keep track of your clients.</p>
    </div>
    <a class="btn btn--ghost" href="<?= e(url('admin/export.php')) ?>"><?= icon('download') ?> Export CSV</a>
</header>

<div class="stats">
    <a class="stat stat--accent" href="?view=pending"><span class="stat__num"><?= (int) $stats['pending'] ?></span><span class="stat__label">Waiting for you</span></a>
    <div class="stat"><span class="stat__num"><?= (int) $stats['this_week'] ?></span><span class="stat__label">Next 7 days</span></div>
    <div class="stat"><span class="stat__num"><?= (int) $stats['this_month'] ?></span><span class="stat__label">This month</span></div>
    <div class="stat"><span class="stat__num"><?= (int) $stats['total'] ?></span><span class="stat__label">All time</span></div>
</div>

<div class="toolbar">
    <nav class="tabs" aria-label="Filter bookings">
        <?php foreach ($views as $key => $label): ?>
            <a href="?view=<?= e($key) ?>" <?= $key === $view ? 'aria-current="page"' : '' ?>><?= e($label) ?></a>
        <?php endforeach; ?>
    </nav>
    <form class="search" method="get" role="search">
        <input type="hidden" name="view" value="<?= e($view) ?>">
        <label class="sr-only" for="q">Search bookings</label>
        <input id="q" name="q" type="search" placeholder="Name, email, business or ref…" value="<?= e($q) ?>">
        <button class="btn btn--dark btn--sm" type="submit"><?= icon('search') ?><span class="sr-only">Search</span></button>
    </form>
</div>

<?php if (!$bookings): ?>
    <div class="empty">
        <?= icon('calendar') ?>
        <p>No bookings here yet.</p>
        <a class="btn btn--primary btn--sm" href="<?= e(url('book.php')) ?>" target="_blank">Open the booking page</a>
    </div>
<?php else: ?>
    <div class="table-wrap">
        <table class="table">
            <thead>
                <tr><th>When</th><th>Client</th><th>Service</th><th>Meeting</th><th>Status</th><th><span class="sr-only">Open</span></th></tr>
            </thead>
            <tbody>
            <?php foreach ($bookings as $b): ?>
                <tr>
                    <td data-label="When">
                        <strong><?= e(format_date($b['start_at'], 'D j M Y')) ?></strong>
                        <span class="muted"><?= e(format_time($b['start_at'])) ?>–<?= e(format_time($b['end_at'])) ?></span>
                    </td>
                    <td data-label="Client">
                        <strong><?= e($b['client_name']) ?></strong>
                        <span class="muted"><?= e($b['business_name'] ?: $b['client_email']) ?></span>
                    </td>
                    <td data-label="Service"><?= e($b['service_name']) ?></td>
                    <td data-label="Meeting"><?= e(meeting_type_label($b['meeting_type'])) ?></td>
                    <td data-label="Status"><?= status_badge($b['status']) ?></td>
                    <td class="table__action"><a class="btn btn--ghost btn--sm" href="<?= e(url('admin/booking.php?id=' . $b['id'])) ?>">Open <span class="sr-only">booking <?= e(format_reference($b['reference'])) ?></span></a></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>
<?php admin_footer(); ?>
