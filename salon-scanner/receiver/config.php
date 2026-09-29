<?php
/**
 * Receiver settings. Values come from environment variables so no secrets live
 * in code (set them in Apache/Nginx, php-fpm, Elastic Beanstalk or an .env loader).
 *
 *   SCANNER_API_KEY     at least 32 random characters, e.g. from:
 *                       php -r "echo bin2hex(random_bytes(32)), PHP_EOL;"
 *   SCANNER_DB_DSN      e.g. mysql:host=your-rds-endpoint;dbname=salon;charset=utf8mb4
 *   SCANNER_DB_USER / SCANNER_DB_PASS
 *   SCANNER_PHOTO_DIR   folder for ticket photos, OUTSIDE the public web root
 *   SCANNER_ALLOWED_ORIGINS  only if the scanner page is on a different domain,
 *                       comma separated, e.g. https://scanner.yoursalon.co.uk
 *   SCANNER_TIMEZONE    the salon's timezone (default Europe/London)
 *   SCANNER_STAFF_SQL   query that lists the stylists for the phone's "C number" buttons.
 *                       It must return the columns `code` and `name`, e.g. to use your own
 *                       staff table: SELECT staff_code AS code, first_name AS name FROM staff WHERE active = 1
 */
declare(strict_types=1);

$env = static fn(string $name, string $default = ''): string => (string) (getenv($name) !== false ? getenv($name) : $default);

return [
    'api_key'         => $env('SCANNER_API_KEY'),
    'db_dsn'          => $env('SCANNER_DB_DSN', 'mysql:host=127.0.0.1;dbname=salon;charset=utf8mb4'),
    'db_user'         => $env('SCANNER_DB_USER', 'root'),
    'db_pass'         => $env('SCANNER_DB_PASS'),
    'photo_dir'       => $env('SCANNER_PHOTO_DIR', dirname(__DIR__) . '/storage/ticket-photos'),
    'allowed_origins' => array_values(array_filter(array_map('trim', explode(',', $env('SCANNER_ALLOWED_ORIGINS'))))),
    'timezone'        => $env('SCANNER_TIMEZONE', 'Europe/London'),
    'staff_query'     => $env('SCANNER_STAFF_SQL', 'SELECT code, name FROM scanner_staff WHERE active = 1 ORDER BY name'),
    'max_body_bytes'  => 12 * 1024 * 1024,
];
