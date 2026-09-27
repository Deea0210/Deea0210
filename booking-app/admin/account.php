<?php
declare(strict_types=1);
require dirname(__DIR__) . '/includes/bootstrap.php';
$admin = require_admin();

$errors = [];
if (is_post()) {
    verify_csrf();
    $current = (string) ($_POST['current_password'] ?? '');
    $new = (string) ($_POST['new_password'] ?? '');
    $confirm = (string) ($_POST['confirm_password'] ?? '');
    $hash = (string) db_value('SELECT password_hash FROM admins WHERE id = ?', [$admin['id']]);

    if (!password_verify($current, $hash)) {
        $errors[] = 'Your current password is not correct.';
    }
    if (strlen($new) < 10) {
        $errors[] = 'The new password must be at least 10 characters.';
    }
    if ($new !== $confirm) {
        $errors[] = 'The new passwords do not match.';
    }
    if (!$errors) {
        db_run('UPDATE admins SET password_hash = ? WHERE id = ?', [password_hash($new, PASSWORD_DEFAULT), $admin['id']]);
        session_regenerate_id(true);
        flash('success', 'Password changed.');
        redirect('admin/account.php');
    }
}

admin_header('Account', 'account');
?>
<header class="admin-head">
    <div>
        <h1>Account</h1>
        <p class="muted">Signed in as <strong><?= e($admin['username']) ?></strong><?= $admin['last_login_at'] ? ' · last login ' . e(format_date($admin['last_login_at'], 'j M Y, H:i')) : '' ?>.</p>
    </div>
</header>
<section class="card card--narrow">
    <h2 class="card__title">Change password</h2>
    <?php foreach ($errors as $message): ?><div class="alert alert--error" role="alert"><?= e($message) ?></div><?php endforeach; ?>
    <form method="post" class="stack">
        <?= csrf_field() ?>
        <div class="field"><label for="current_password">Current password</label><input id="current_password" name="current_password" type="password" required autocomplete="current-password"></div>
        <div class="field"><label for="new_password">New password <small>(10+ characters)</small></label><input id="new_password" name="new_password" type="password" required minlength="10" autocomplete="new-password"></div>
        <div class="field"><label for="confirm_password">Confirm new password</label><input id="confirm_password" name="confirm_password" type="password" required minlength="10" autocomplete="new-password"></div>
        <button class="btn btn--primary" type="submit">Update password</button>
    </form>
</section>
<?php admin_footer(); ?>
