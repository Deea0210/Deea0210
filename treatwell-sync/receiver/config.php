<?php
/**
 * Bookings receiver settings, from environment variables (no secrets in code).
 *
 *   TREATWELL_SYNC_KEY   at least 32 random characters, e.g. from:
 *                        php -r "echo bin2hex(random_bytes(32)), PHP_EOL;"
 *   TREATWELL_DB_DSN     e.g. mysql:host=your-rds-endpoint;dbname=salon;charset=utf8mb4
 *   TREATWELL_DB_USER / TREATWELL_DB_PASS
 */
declare(strict_types=1);

$env = static fn(string $name, string $default = ''): string => (string) (getenv($name) !== false ? getenv($name) : $default);

return [
    'api_key'        => $env('TREATWELL_SYNC_KEY'),
    'db_dsn'         => $env('TREATWELL_DB_DSN', 'mysql:host=127.0.0.1;dbname=salon;charset=utf8mb4'),
    'db_user'        => $env('TREATWELL_DB_USER', 'root'),
    'db_pass'        => $env('TREATWELL_DB_PASS'),
    'max_body_bytes' => 2 * 1024 * 1024,
];
