<?php
declare(strict_types=1);
defined('APP_ROOT') || exit;

/** Read a config value with dot notation, e.g. config('app.brand_name'). */
function config(?string $path = null, mixed $default = null): mixed
{
    $value = $GLOBALS['APP_CONFIG'];
    if ($path === null) {
        return $value;
    }
    foreach (explode('.', $path) as $segment) {
        if (!is_array($value) || !array_key_exists($segment, $value)) {
            return $default;
        }
        $value = $value[$segment];
    }
    return $value;
}

/** Escape for HTML output. */
function e(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function is_https(): bool
{
    return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https')
        || (int) ($_SERVER['SERVER_PORT'] ?? 0) === 443;
}

/**
 * URL path of the app root, e.g. "/booking-app" when installed in
 * htdocs/booking-app on XAMPP, or "" when installed at the domain root.
 */
function base_path(): string
{
    static $base = null;
    if ($base !== null) {
        return $base;
    }
    $configured = (string) config('app.base_url', '');
    if ($configured !== '') {
        return $base = rtrim((string) parse_url($configured, PHP_URL_PATH), '/');
    }
    $normalize = static fn(string $p): string => str_replace('\\', '/', $p);
    $script = $normalize((string) (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') ?: ''));
    $root = $normalize((string) realpath(APP_ROOT));
    $scriptName = $normalize($_SERVER['SCRIPT_NAME'] ?? '');
    $relative = str_starts_with(strtolower($script), strtolower($root)) ? substr($script, strlen($root)) : '';

    if ($relative !== '' && str_ends_with($scriptName, $relative)) {
        $base = substr($scriptName, 0, -strlen($relative));
    } else {
        $base = rtrim(dirname($scriptName), '/');
    }
    return $base;
}

/** Root-relative URL to a file inside the app. */
function url(string $path = ''): string
{
    return base_path() . '/' . ltrim($path, '/');
}

/** Absolute URL (used in emails, canonical tags, sitemaps). */
function absolute_url(string $path = ''): string
{
    $configured = (string) config('app.base_url', '');
    if ($configured !== '') {
        return rtrim($configured, '/') . '/' . ltrim($path, '/');
    }
    $host = preg_replace('/[^A-Za-z0-9.\-:\[\]]/', '', $_SERVER['HTTP_HOST'] ?? 'localhost');
    return (is_https() ? 'https' : 'http') . '://' . $host . url($path);
}

function asset(string $path): string
{
    $file = APP_ROOT . '/assets/' . ltrim($path, '/');
    $version = is_file($file) ? (string) filemtime($file) : '1';
    return url('assets/' . ltrim($path, '/')) . '?v=' . $version;
}

function redirect(string $path): never
{
    header('Location: ' . (preg_match('~^https?://~i', $path) ? $path : url($path)));
    exit;
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="_csrf" value="' . e(csrf_token()) . '">';
}

function verify_csrf(): void
{
    $token = $_POST['_csrf'] ?? '';
    if (!is_string($token) || !hash_equals(csrf_token(), $token)) {
        http_response_code(419);
        exit('Your session expired. Please go back, refresh the page and try again.');
    }
}

function is_post(): bool
{
    return ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
}

/** Trimmed string from $_POST (arrays are ignored). */
function post(string $key, string $default = ''): string
{
    $value = $_POST[$key] ?? $default;
    return is_string($value) ? trim($value) : $default;
}

function flash(string $type, string $message): void
{
    $_SESSION['flash'][] = ['type' => $type, 'message' => $message];
}

function take_flashes(): array
{
    $messages = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return $messages;
}

function client_ip(): string
{
    return substr((string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'), 0, 45);
}

function money(float|string|null $amount): string
{
    if ($amount === null || $amount === '') {
        return '';
    }
    $amount = (float) $amount;
    $formatted = number_format($amount, floor($amount) == $amount ? 0 : 2);
    return sprintf((string) config('app.currency', '€%s'), $formatted);
}

function to_datetime(string|DateTimeInterface $value): DateTimeImmutable
{
    return $value instanceof DateTimeInterface
        ? DateTimeImmutable::createFromInterface($value)
        : new DateTimeImmutable($value);
}

function format_date(string|DateTimeInterface $value, string $format = 'l, j F Y'): string
{
    return to_datetime($value)->format($format);
}

function format_time(string|DateTimeInterface $value): string
{
    return to_datetime($value)->format('H:i');
}

function format_duration(int $minutes): string
{
    if ($minutes < 60) {
        return $minutes . ' min';
    }
    $hours = intdiv($minutes, 60);
    $rest = $minutes % 60;
    return $hours . ' h' . ($rest ? ' ' . $rest . ' min' : '');
}

function slugify(string $text): string
{
    $text = strtolower(trim((string) iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $text)));
    $text = preg_replace('/[^a-z0-9]+/', '-', $text) ?? '';
    return trim($text, '-') ?: 'service';
}

/** Small key/value config list rendered as <option>s. */
function options(array $items, string $selected, bool $useKeys = true): string
{
    $html = '';
    foreach ($items as $key => $label) {
        $value = $useKeys ? (string) $key : (string) $label;
        $html .= '<option value="' . e($value) . '"' . ($value === $selected ? ' selected' : '') . '>' . e($label) . '</option>';
    }
    return $html;
}
