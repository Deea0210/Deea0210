<?php
declare(strict_types=1);
require dirname(__DIR__) . '/includes/bootstrap.php';

if (current_admin()) {
    redirect('admin/index.php');
}

$error = '';
if (is_post()) {
    verify_csrf();
    if (login_locked(client_ip())) {
        $error = 'Too many attempts. Please wait ' . LOGIN_WINDOW_MINUTES . ' minutes and try again.';
    } elseif (attempt_login(post('username'), (string) ($_POST['password'] ?? ''))) {
        redirect('admin/index.php');
    } else {
        $error = 'Wrong username or password.';
    }
}

header('Cache-Control: no-store');
admin_header('Log in', '', 'admin--auth');
?>
<div class="auth-card">
    <a class="logo" href="<?= e(url('')) ?>">
        <span class="logo__mark" aria-hidden="true"><?= e(mb_substr((string) config('app.brand_name'), 0, 1)) ?></span>
        <span class="logo__text">admin<span class="logo__dot">.</span></span>
    </a>
    <h1>Welcome back</h1>
    <?php if ($error): ?><div class="alert alert--error" role="alert"><?= e($error) ?></div><?php endif; ?>
    <form method="post" class="stack">
        <?= csrf_field() ?>
        <div class="field">
            <label for="username">Username</label>
            <input id="username" name="username" required autocomplete="username" autofocus value="<?= e(post('username')) ?>">
        </div>
        <div class="field">
            <label for="password">Password</label>
            <input id="password" name="password" type="password" required autocomplete="current-password">
        </div>
        <button class="btn btn--primary btn--block" type="submit">Log in</button>
    </form>
</div>
<?php admin_footer(); ?>
