<?php
declare(strict_types=1);
defined('APP_ROOT') || exit;

function db_connect(bool $withDatabase = true): PDO
{
    $c = config('db');
    $dsn = sprintf('mysql:host=%s;port=%d;charset=%s', $c['host'], (int) $c['port'], $c['charset']);
    if ($withDatabase) {
        $dsn .= ';dbname=' . $c['name'];
    }
    $pdo = new PDO($dsn, $c['user'], $c['pass'], [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]);
    // Keep MySQL timestamps in the same timezone as PHP.
    $pdo->exec("SET time_zone = '" . (new DateTimeImmutable())->format('P') . "'");
    return $pdo;
}

/** Shared connection. Shows a friendly setup page if the database is not ready. */
function db(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        try {
            $pdo = db_connect();
        } catch (PDOException $e) {
            setup_required_page($e);
        }
    }
    return $pdo;
}

function db_all(string $sql, array $params = []): array
{
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

function db_one(string $sql, array $params = []): ?array
{
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    $row = $stmt->fetch();
    return $row === false ? null : $row;
}

function db_value(string $sql, array $params = []): mixed
{
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchColumn();
}

function db_run(string $sql, array $params = []): int
{
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    return $stmt->rowCount();
}

function setup_required_page(Throwable $e): never
{
    error_log('[deea-booking] ' . $e->getMessage());
    http_response_code(503);
    $install = e(url('install.php'));
    $local = in_array(client_ip(), ['127.0.0.1', '::1'], true);
    $detail = e($local ? $e->getMessage() : 'Hidden on public servers — check the PHP error log.');
    echo <<<HTML
<!doctype html><html lang="en"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Setup needed</title>
<body style="font:16px/1.6 system-ui,sans-serif;max-width:640px;margin:10vh auto;padding:0 20px;color:#16131f">
<h1 style="font-size:1.6rem">The booking app isn't connected to its database yet</h1>
<p>Start <strong>Apache</strong> and <strong>MySQL</strong> in the XAMPP Control Panel, check the <code>db</code> settings in <code>config.php</code>, then run the installer:</p>
<p><a href="{$install}" style="display:inline-block;background:#ff5a36;color:#fff;padding:.7em 1.2em;border-radius:999px;text-decoration:none;font-weight:600">Run install.php</a></p>
<details><summary>Technical detail</summary><pre style="white-space:pre-wrap;background:#f4efe7;padding:12px;border-radius:8px">{$detail}</pre></details>
</body></html>
HTML;
    exit;
}
