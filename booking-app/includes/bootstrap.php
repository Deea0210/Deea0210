<?php
declare(strict_types=1);

define('APP_ROOT', dirname(__DIR__));

$config = require APP_ROOT . '/config.php';
if (is_file(APP_ROOT . '/config.local.php')) {
    $config = array_replace_recursive($config, require APP_ROOT . '/config.local.php');
}
$GLOBALS['APP_CONFIG'] = $config;
unset($config);

date_default_timezone_set($GLOBALS['APP_CONFIG']['app']['timezone'] ?? 'UTC');
mb_internal_encoding('UTF-8');

require __DIR__ . '/helpers.php';
require __DIR__ . '/icons.php';
require __DIR__ . '/db.php';
require __DIR__ . '/auth.php';
require __DIR__ . '/booking.php';
require __DIR__ . '/mailer.php';
require __DIR__ . '/layout.php';

set_exception_handler(static function (Throwable $e): void {
    // Missing tables usually means install.php has not been run yet.
    if ($e instanceof PDOException && in_array((string) $e->getCode(), ['42S02', '1049', '2002'], true)) {
        setup_required_page($e);
    }
    error_log('[deea-booking] ' . $e);
    if (!headers_sent()) {
        http_response_code(500);
    }
    echo PHP_SAPI === 'cli' ? $e . PHP_EOL : '<h1>Something went wrong</h1><p>Please try again in a moment.</p>';
});

if (PHP_SAPI !== 'cli') {
    session_name('deea_session');
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'secure'   => is_https(),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();

    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: SAMEORIGIN');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
    header("Content-Security-Policy: default-src 'self'; img-src 'self' data:; style-src 'self' 'unsafe-inline'; script-src 'self'; font-src 'self'; connect-src 'self'; frame-ancestors 'self'; base-uri 'self'; form-action 'self'");
}
