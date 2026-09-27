<?php
declare(strict_types=1);
defined('APP_ROOT') || exit;

const LOGIN_MAX_ATTEMPTS = 5;
const LOGIN_WINDOW_MINUTES = 15;
const ADMIN_IDLE_TIMEOUT = 7200; // seconds

function current_admin(): ?array
{
    static $admin = false;
    if ($admin !== false) {
        return $admin;
    }
    $id = $_SESSION['admin_id'] ?? null;
    $lastSeen = $_SESSION['admin_seen'] ?? 0;
    if (!$id || time() - $lastSeen > ADMIN_IDLE_TIMEOUT) {
        unset($_SESSION['admin_id'], $_SESSION['admin_seen']);
        return $admin = null;
    }
    $_SESSION['admin_seen'] = time();
    return $admin = db_one('SELECT id, username, last_login_at FROM admins WHERE id = ?', [$id]);
}

function require_admin(): array
{
    $admin = current_admin();
    if (!$admin) {
        redirect('admin/login.php');
    }
    header('Cache-Control: no-store');
    return $admin;
}

function login_locked(string $ip): bool
{
    $since = (new DateTimeImmutable('-' . LOGIN_WINDOW_MINUTES . ' minutes'))->format('Y-m-d H:i:s');
    $count = (int) db_value('SELECT COUNT(*) FROM login_attempts WHERE ip = ? AND attempted_at > ?', [$ip, $since]);
    return $count >= LOGIN_MAX_ATTEMPTS;
}

function attempt_login(string $username, string $password): bool
{
    $ip = client_ip();
    $row = db_one('SELECT id, password_hash FROM admins WHERE username = ?', [$username]);
    // Verify against a dummy hash when the user does not exist to keep timing similar.
    $hash = $row['password_hash'] ?? '$2y$10$usesomesillystringfore7hnbRJHxXVLeakoG8K30oukPsA.ztMG';
    if (!password_verify($password, $hash) || !$row) {
        db_run('INSERT INTO login_attempts (ip, attempted_at) VALUES (?, NOW())', [$ip]);
        return false;
    }
    if (password_needs_rehash($row['password_hash'], PASSWORD_DEFAULT)) {
        db_run('UPDATE admins SET password_hash = ? WHERE id = ?', [password_hash($password, PASSWORD_DEFAULT), $row['id']]);
    }
    db_run('DELETE FROM login_attempts WHERE ip = ?', [$ip]);
    db_run('UPDATE admins SET last_login_at = NOW() WHERE id = ?', [$row['id']]);
    session_regenerate_id(true);
    $_SESSION['admin_id'] = (int) $row['id'];
    $_SESSION['admin_seen'] = time();
    return true;
}

function logout_admin(): void
{
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 3600, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    session_destroy();
}
