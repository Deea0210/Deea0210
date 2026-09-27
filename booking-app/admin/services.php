<?php
declare(strict_types=1);
require dirname(__DIR__) . '/includes/bootstrap.php';
require_admin();

$meetingTypes = (array) config('booking.meeting_types', []);
$blank = [
    'id' => 0, 'slug' => '', 'name' => '', 'tagline' => '', 'description' => '', 'icon' => 'spark',
    'price_from' => '', 'duration_minutes' => 30, 'meeting_types' => implode(',', array_keys($meetingTypes)),
    'is_active' => 1, 'sort_order' => ((int) db_value('SELECT COALESCE(MAX(sort_order), 0) FROM services')) + 1,
];
$errors = [];
$editing = null;

if (is_post()) {
    verify_csrf();
    $action = post('action');
    $id = (int) post('id');

    if ($action === 'toggle' && $id) {
        db_run('UPDATE services SET is_active = 1 - is_active WHERE id = ?', [$id]);
        flash('success', 'Service visibility updated.');
        redirect('admin/services.php');
    }

    if ($action === 'delete' && $id) {
        if ((int) db_value('SELECT COUNT(*) FROM bookings WHERE service_id = ?', [$id]) > 0) {
            flash('error', 'This service has bookings, so it cannot be deleted. Hide it instead.');
        } else {
            db_run('DELETE FROM services WHERE id = ?', [$id]);
            flash('success', 'Service deleted.');
        }
        redirect('admin/services.php');
    }

    if ($action === 'save') {
        $editing = [
            'id'               => $id,
            'name'             => post('name'),
            'slug'             => slugify(post('slug') ?: post('name')),
            'tagline'          => post('tagline'),
            'description'      => post('description'),
            'icon'             => in_array(post('icon'), service_icons(), true) ? post('icon') : 'spark',
            'price_from'       => post('price_from'),
            'duration_minutes' => (int) post('duration_minutes'),
            'meeting_types'    => implode(',', array_intersect(array_keys($meetingTypes), (array) ($_POST['meeting_types'] ?? []))),
            'is_active'        => post('is_active') === '1' ? 1 : 0,
            'sort_order'       => (int) post('sort_order'),
        ];
        if ($editing['name'] === '' || mb_strlen($editing['name']) > 120) {
            $errors[] = 'Name is required (max 120 characters).';
        }
        if (mb_strlen($editing['tagline']) > 160) {
            $errors[] = 'Tagline must be 160 characters or fewer.';
        }
        if ($editing['description'] === '') {
            $errors[] = 'Description is required.';
        }
        if ($editing['price_from'] !== '' && (!is_numeric($editing['price_from']) || (float) $editing['price_from'] < 0)) {
            $errors[] = 'Price must be a number (or leave it empty for “custom quote”).';
        }
        if ($editing['duration_minutes'] < 15 || $editing['duration_minutes'] > 480) {
            $errors[] = 'Duration must be between 15 and 480 minutes.';
        }
        if ($editing['meeting_types'] === '') {
            $errors[] = 'Choose at least one meeting type.';
        }
        if (db_value('SELECT 1 FROM services WHERE slug = ? AND id <> ?', [$editing['slug'], $id])) {
            $errors[] = 'Another service already uses the URL name “' . $editing['slug'] . '”.';
        }

        if (!$errors) {
            $values = [
                $editing['slug'], $editing['name'], $editing['tagline'], $editing['description'], $editing['icon'],
                $editing['price_from'] === '' ? null : round((float) $editing['price_from'], 2),
                $editing['duration_minutes'], $editing['meeting_types'], $editing['is_active'], $editing['sort_order'],
            ];
            if ($id) {
                db_run(
                    'UPDATE services SET slug = ?, name = ?, tagline = ?, description = ?, icon = ?, price_from = ?,
                        duration_minutes = ?, meeting_types = ?, is_active = ?, sort_order = ? WHERE id = ?',
                    [...$values, $id]
                );
                flash('success', 'Service “' . $editing['name'] . '” saved.');
            } else {
                db_run(
                    'INSERT INTO services (slug, name, tagline, description, icon, price_from, duration_minutes, meeting_types, is_active, sort_order)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                    $values
                );
                flash('success', 'Service “' . $editing['name'] . '” added.');
            }
            redirect('admin/services.php');
        }
    }
}

if ($editing === null && isset($_GET['edit'])) {
    $editing = $_GET['edit'] === 'new' ? $blank : find_service((int) $_GET['edit']);
}
$services = db_all('SELECT s.*, (SELECT COUNT(*) FROM bookings b WHERE b.service_id = s.id) AS bookings FROM services s ORDER BY sort_order, name');

admin_header('Services', 'services');
?>
<header class="admin-head">
    <div>
        <h1>Services</h1>
        <p class="muted">What clients can book, how long it takes and the “from” price on your website.</p>
    </div>
    <a class="btn btn--primary" href="?edit=new">+ Add service</a>
</header>

<?php if ($editing): ?>
    <section class="card">
        <h2 class="card__title"><?= $editing['id'] ? 'Edit “' . e($editing['name']) . '”' : 'New service' ?></h2>
        <?php foreach ($errors as $message): ?><div class="alert alert--error" role="alert"><?= e($message) ?></div><?php endforeach; ?>
        <form method="post" class="form-grid">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="save">
            <input type="hidden" name="id" value="<?= (int) $editing['id'] ?>">
            <div class="field">
                <label for="name">Name</label>
                <input id="name" name="name" required maxlength="120" value="<?= e($editing['name']) ?>">
            </div>
            <div class="field">
                <label for="tagline">Client-style tagline <small>(e.g. “Make me a website”)</small></label>
                <input id="tagline" name="tagline" maxlength="160" value="<?= e($editing['tagline']) ?>">
            </div>
            <div class="field field--full">
                <label for="description">Description</label>
                <textarea id="description" name="description" rows="3" required><?= e($editing['description']) ?></textarea>
            </div>
            <div class="field">
                <label for="price_from">Price from <small>(empty = custom quote)</small></label>
                <input id="price_from" name="price_from" inputmode="decimal" value="<?= e($editing['price_from'] ?? '') ?>">
            </div>
            <div class="field">
                <label for="duration_minutes">Appointment length (minutes)</label>
                <input id="duration_minutes" name="duration_minutes" type="number" min="15" max="480" step="5" required value="<?= (int) $editing['duration_minutes'] ?>">
            </div>
            <div class="field">
                <label for="icon">Icon</label>
                <select id="icon" name="icon"><?= options(array_combine(service_icons(), service_icons()), (string) $editing['icon']) ?></select>
            </div>
            <div class="field">
                <label for="slug">URL name <small>(auto from name if empty)</small></label>
                <input id="slug" name="slug" maxlength="80" value="<?= e($editing['slug']) ?>" pattern="[a-z0-9-]*">
            </div>
            <fieldset class="field field--full">
                <legend>Meeting types</legend>
                <div class="inline-options">
                    <?php $allowed = explode(',', (string) $editing['meeting_types']); ?>
                    <?php foreach ($meetingTypes as $key => $label): ?>
                        <label class="checkbox"><input type="checkbox" name="meeting_types[]" value="<?= e($key) ?>" <?= in_array($key, $allowed, true) ? 'checked' : '' ?>><span><?= e($label) ?></span></label>
                    <?php endforeach; ?>
                </div>
            </fieldset>
            <div class="field">
                <label for="sort_order">Order</label>
                <input id="sort_order" name="sort_order" type="number" value="<?= (int) $editing['sort_order'] ?>">
            </div>
            <div class="field">
                <span class="field__label">Visibility</span>
                <label class="checkbox"><input type="checkbox" name="is_active" value="1" <?= (int) $editing['is_active'] ? 'checked' : '' ?>><span>Show on website &amp; booking page</span></label>
            </div>
            <div class="field field--full form-actions">
                <button class="btn btn--primary" type="submit">Save service</button>
                <a class="btn btn--ghost" href="<?= e(url('admin/services.php')) ?>">Cancel</a>
            </div>
        </form>
    </section>
<?php endif; ?>

<div class="table-wrap">
    <table class="table">
        <thead><tr><th>Service</th><th>From</th><th>Length</th><th>Bookings</th><th>Visible</th><th><span class="sr-only">Actions</span></th></tr></thead>
        <tbody>
        <?php foreach ($services as $s): ?>
            <tr class="<?= (int) $s['is_active'] ? '' : 'is-muted' ?>">
                <td data-label="Service">
                    <span class="with-icon"><?= icon($s['icon']) ?><span><strong><?= e($s['name']) ?></strong><span class="muted"><?= e($s['tagline']) ?></span></span></span>
                </td>
                <td data-label="From"><?= $s['price_from'] !== null ? e(money($s['price_from'])) : '<span class="muted">Quote</span>' ?></td>
                <td data-label="Length"><?= e(format_duration((int) $s['duration_minutes'])) ?></td>
                <td data-label="Bookings"><?= (int) $s['bookings'] ?></td>
                <td data-label="Visible">
                    <form method="post">
                        <?= csrf_field() ?><input type="hidden" name="action" value="toggle"><input type="hidden" name="id" value="<?= (int) $s['id'] ?>">
                        <button class="switch" type="submit" role="switch" aria-checked="<?= (int) $s['is_active'] ? 'true' : 'false' ?>" aria-label="Show <?= e($s['name']) ?> on website"><span></span></button>
                    </form>
                </td>
                <td class="table__action">
                    <a class="btn btn--ghost btn--sm" href="?edit=<?= (int) $s['id'] ?>">Edit</a>
                    <?php if (!(int) $s['bookings']): ?>
                        <form method="post" data-confirm="Delete “<?= e($s['name']) ?>”?">
                            <?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int) $s['id'] ?>">
                            <button class="btn btn--ghost btn--sm" type="submit">Delete</button>
                        </form>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php admin_footer(); ?>
