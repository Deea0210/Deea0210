<?php
/**
 * One-time installer: creates the database and tables, adds starter
 * services and opening hours, and creates your admin login.
 * Delete this file after installing on a live server.
 */
declare(strict_types=1);
require __DIR__ . '/includes/bootstrap.php';

/** Split a .sql file into statements (statements end with ";" at end of line). */
function sql_statements(string $file): array
{
    $sql = preg_replace('/^\s*--.*$/m', '', (string) file_get_contents($file)) ?? '';
    return array_values(array_filter(array_map('trim', preg_split('/;\s*$/m', $sql) ?: [])));
}

$errors = [];
$done = false;
$dbName = (string) config('db.name');
$status = ['server' => false, 'database' => false, 'tables' => false, 'admin' => false];

try {
    $server = db_connect(false);
    $status['server'] = true;
    $status['database'] = (bool) $server->query('SHOW DATABASES LIKE ' . $server->quote($dbName))->fetchColumn();
    if ($status['database']) {
        $pdo = db_connect();
        $status['tables'] = (bool) $pdo->query("SHOW TABLES LIKE 'admins'")->fetchColumn();
        $status['admin'] = $status['tables'] && (int) $pdo->query('SELECT COUNT(*) FROM admins')->fetchColumn() > 0;
    }
} catch (PDOException $e) {
    $errors[] = 'Could not connect to MySQL: ' . $e->getMessage() . ' — is MySQL running in the XAMPP Control Panel, and are the db settings in config.php correct?';
}

if ($status['admin'] && !is_post()) {
    $done = true;
}

if (is_post() && $status['server'] && !$status['admin']) {
    verify_csrf();
    $username = post('username');
    $password = (string) ($_POST['password'] ?? '');
    $confirm = (string) ($_POST['password_confirm'] ?? '');

    if (!preg_match('/^[A-Za-z0-9_.-]{3,60}$/', $username)) {
        $errors[] = 'Username must be 3–60 characters: letters, numbers, dots, dashes or underscores.';
    }
    if (strlen($password) < 10) {
        $errors[] = 'Password must be at least 10 characters.';
    }
    if ($password !== $confirm) {
        $errors[] = 'Passwords do not match.';
    }

    if (!$errors) {
        try {
            if (!preg_match('/^[A-Za-z0-9_]+$/', $dbName)) {
                throw new RuntimeException('Database name in config.php may only contain letters, numbers and underscores.');
            }
            $server->exec("CREATE DATABASE IF NOT EXISTS `{$dbName}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
            $pdo = db_connect();
            foreach (sql_statements(APP_ROOT . '/database/schema.sql') as $statement) {
                $pdo->exec($statement);
            }
            if ((int) $pdo->query('SELECT COUNT(*) FROM services')->fetchColumn() === 0) {
                foreach (sql_statements(APP_ROOT . '/database/seed.sql') as $statement) {
                    $pdo->exec($statement);
                }
            }
            $stmt = $pdo->prepare('INSERT INTO admins (username, password_hash) VALUES (?, ?)');
            $stmt->execute([$username, password_hash($password, PASSWORD_DEFAULT)]);
            $done = true;
        } catch (Throwable $e) {
            $errors[] = 'Installation failed: ' . $e->getMessage();
        }
    }
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>Install · <?= e(config('app.brand_name')) ?> Booking</title>
    <link rel="stylesheet" href="<?= e(asset('css/app.css')) ?>">
    <link rel="stylesheet" href="<?= e(asset('css/admin.css')) ?>">
</head>
<body class="admin admin--auth">
<main class="auth-card" id="main">
    <a class="logo" href="<?= e(url('')) ?>">
        <span class="logo__mark" aria-hidden="true"><?= e(mb_substr((string) config('app.brand_name'), 0, 1)) ?></span>
        <span class="logo__text">install<span class="logo__dot">.</span></span>
    </a>

    <?php if ($done): ?>
        <h1>You're all set 🎉</h1>
        <p>The database is ready and your admin account exists.</p>
        <div class="alert alert--warning"><strong>Security:</strong> on a live server, delete <code>install.php</code> now.</div>
        <p class="auth-card__actions">
            <a class="btn btn--primary" href="<?= e(url('admin/login.php')) ?>">Go to admin</a>
            <a class="btn btn--ghost" href="<?= e(url('')) ?>">View website</a>
        </p>
    <?php else: ?>
        <h1>Install the booking app</h1>
        <ul class="checklist">
            <li class="<?= $status['server'] ? 'ok' : 'todo' ?>">MySQL server reachable at <code><?= e(config('db.host')) ?>:<?= e(config('db.port')) ?></code></li>
            <li class="<?= $status['database'] ? 'ok' : 'todo' ?>">Database <code><?= e($dbName) ?></code> <?= $status['database'] ? 'exists' : 'will be created' ?></li>
            <li class="<?= $status['tables'] ? 'ok' : 'todo' ?>">Tables and starter services <?= $status['tables'] ? 'exist' : 'will be created' ?></li>
            <li class="todo">Create your admin login</li>
        </ul>

        <?php foreach ($errors as $message): ?>
            <div class="alert alert--error" role="alert"><?= e($message) ?></div>
        <?php endforeach; ?>

        <?php if ($status['server']): ?>
            <form method="post" class="stack">
                <?= csrf_field() ?>
                <div class="field">
                    <label for="username">Admin username</label>
                    <input id="username" name="username" required autocomplete="username" value="<?= e(post('username', 'admin')) ?>">
                </div>
                <div class="field">
                    <label for="password">Password <small>(10+ characters)</small></label>
                    <input id="password" name="password" type="password" required minlength="10" autocomplete="new-password">
                </div>
                <div class="field">
                    <label for="password_confirm">Confirm password</label>
                    <input id="password_confirm" name="password_confirm" type="password" required minlength="10" autocomplete="new-password">
                </div>
                <button class="btn btn--primary btn--block" type="submit">Install</button>
            </form>
        <?php endif; ?>
    <?php endif; ?>
</main>
</body>
</html>
