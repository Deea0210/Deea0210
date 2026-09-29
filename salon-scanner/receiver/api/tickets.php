<?php
/**
 * Ticket Scanner receiver.
 *
 *   POST  JSON ticket from the phone      → 200 {"ok":true,"id":123}
 *         (the same scanId sent again     → 200 {"ok":true,"id":123,"duplicate":true})
 *   GET   ?ping=1                          → 200 {"ok":true}   (the phone's "Test connection")
 *
 * Every request needs:  Authorization: Bearer <SCANNER_API_KEY>
 */
declare(strict_types=1);

$config = require dirname(__DIR__) . '/config.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

// ---------------------------------------------------------------- helpers

function respond(int $status, array $body): never
{
    http_response_code($status);
    echo json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

function fail(int $status, string $message): never
{
    respond($status, ['ok' => false, 'error' => $message]);
}

function str_field(array $data, string $key, int $max, bool $required = false): string
{
    $value = $data[$key] ?? '';
    if (!is_string($value)) {
        fail(400, "\"{$key}\" must be text");
    }
    $value = trim($value);
    if ($required && $value === '') {
        fail(400, "\"{$key}\" is required");
    }
    if (mb_strlen($value) > $max) {
        fail(400, "\"{$key}\" is too long");
    }
    return $value;
}

/** Decodes a data:image/jpeg;base64,... URL and returns raw JPEG/PNG bytes (or null). */
function image_bytes(mixed $dataUrl, int $maxBytes): ?string
{
    if (!is_string($dataUrl) || $dataUrl === '') {
        return null;
    }
    if (!preg_match('#^data:image/(jpeg|png);base64,([A-Za-z0-9+/=]+)$#', $dataUrl, $m)) {
        fail(400, 'Images must be base64 JPEG or PNG data URLs');
    }
    $bytes = base64_decode($m[2], true);
    if ($bytes === false || strlen($bytes) > $maxBytes) {
        fail(400, 'Image is invalid or too large');
    }
    $info = @getimagesizefromstring($bytes);
    if ($info === false || !in_array($info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG], true)) {
        fail(400, 'Image is not a valid JPEG or PNG');
    }
    return $bytes;
}

// ------------------------------------------------------------------- CORS
// Only needed when the scanner page is served from a different domain.

$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if ($origin !== '' && in_array($origin, $config['allowed_origins'], true)) {
    header('Access-Control-Allow-Origin: ' . $origin);
    header('Vary: Origin');
    header('Access-Control-Allow-Headers: Authorization, Content-Type');
    header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
    header('Access-Control-Max-Age: 600');
}
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($method === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// ------------------------------------------------------------------- auth

if (strlen($config['api_key']) < 32) {
    error_log('[ticket-scanner] SCANNER_API_KEY is missing or shorter than 32 characters');
    fail(500, 'Receiver is not configured');
}
$auth = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
if ($auth === '' && function_exists('getallheaders')) {
    foreach (getallheaders() as $name => $value) {
        if (strcasecmp($name, 'Authorization') === 0) {
            $auth = $value;
        }
    }
}
$key = preg_match('/^Bearer\s+(\S+)$/i', $auth, $m) ? $m[1] : '';
if ($key === '' || !hash_equals($config['api_key'], $key)) {
    fail(401, 'Unauthorized');
}

if ($method === 'GET' && isset($_GET['ping'])) {
    respond(200, ['ok' => true, 'service' => 'ticket-scanner-receiver']);
}
if ($method !== 'POST') {
    fail(405, 'Use POST');
}

// ------------------------------------------------------------------- body

$raw = file_get_contents('php://input', false, null, 0, $config['max_body_bytes'] + 1);
if ($raw === false || $raw === '') {
    fail(400, 'Empty request');
}
if (strlen($raw) > $config['max_body_bytes']) {
    fail(413, 'Request too large');
}
try {
    $data = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
} catch (JsonException) {
    fail(400, 'Invalid JSON');
}
if (!is_array($data)) {
    fail(400, 'Invalid JSON');
}

$scanId = str_field($data, 'scanId', 36, true);
if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $scanId)) {
    fail(400, '"scanId" must be a UUID');
}
$scanId = strtolower($scanId);
$template = str_field($data, 'template', 60, true);
$device = str_field($data, 'device', 100);

try {
    $scannedAt = new DateTimeImmutable(str_field($data, 'scannedAt', 40, true));
} catch (Exception) {
    fail(400, '"scannedAt" must be an ISO 8601 date');
}
$localTz = new DateTimeZone($config['timezone']);
$scannedLocal = $scannedAt->setTimezone($localTz)->format('Y-m-d H:i:s');
$scannedUtc = $scannedAt->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');

$services = $data['services'] ?? null;
if (!is_array($services) || count($services) > 200) {
    fail(400, '"services" must be a list');
}
$ticked = [];
foreach ($services as $i => $s) {
    if (!is_array($s)) {
        fail(400, "services[{$i}] must be an object");
    }
    $code = str_field($s, 'code', 40, true);
    if (!preg_match('/^[a-z0-9-]+$/', $code)) {
        fail(400, "services[{$i}].code is invalid");
    }
    if (!is_bool($s['ticked'] ?? null)) {
        fail(400, "services[{$i}].ticked must be true or false");
    }
    if (!$s['ticked']) {
        continue;
    }
    $price = $s['price'] ?? null;
    if ($price !== null && ((!is_int($price) && !is_float($price)) || $price < 0 || $price > 100000)) {
        fail(400, "services[{$i}].price is invalid");
    }
    $confidence = $s['confidence'] ?? 1;
    $ticked[] = [
        'code'       => $code,
        'name'       => str_field($s, 'name', 120, true),
        'section'    => str_field($s, 'section', 60),
        'price'      => $price === null ? null : round((float) $price, 2),
        'confidence' => is_int($confidence) || is_float($confidence) ? max(0, min(1, (float) $confidence)) : 1.0,
        'changed'    => !empty($s['changedByStaff']),
    ];
}

$fields = is_array($data['fields'] ?? null) ? $data['fields'] : [];
$fieldC = str_field($fields, 'c', 100);
$treatments = str_field($fields, 'treatments', 20);
$tipsText = str_replace(['£', ',', ' '], ['', '.', ''], str_field($fields, 'tips', 20));
if ($tipsText !== '' && !preg_match('/^\d{1,5}(\.\d{1,2})?$/', $tipsText)) {
    fail(400, '"fields.tips" must be an amount like 5 or 5.50');
}
$tips = $tipsText === '' ? null : (float) $tipsText;

$photo = image_bytes($data['photo'] ?? null, 6 * 1024 * 1024);
$fieldImages = [];
foreach ((is_array($data['fieldImages'] ?? null) ? $data['fieldImages'] : []) as $name => $url) {
    if (is_string($name) && preg_match('/^[a-z0-9_]{1,20}$/', $name)) {
        $fieldImages[$name] = image_bytes($url, 2 * 1024 * 1024);
    }
}

// ----------------------------------------------------------------- store

try {
    $pdo = new PDO($config['db_dsn'], $config['db_user'], $config['db_pass'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
} catch (PDOException $e) {
    error_log('[ticket-scanner] database connection failed: ' . $e->getMessage());
    fail(503, 'Database unavailable, the phone will retry');
}

$existing = $pdo->prepare('SELECT id FROM scanned_tickets WHERE scan_id = ?');
$existing->execute([$scanId]);
if ($id = $existing->fetchColumn()) {
    respond(200, ['ok' => true, 'id' => (int) $id, 'duplicate' => true]);
}

// Photos: <photo_dir>/<YYYY-MM>/<scanId>.jpg (+ _c.jpg, _tips.jpg …)
$photoPath = null;
if ($photo !== null || $fieldImages) {
    $folder = rtrim($config['photo_dir'], '/') . '/' . $scannedAt->format('Y-m');
    if (!is_dir($folder) && !mkdir($folder, 0750, true) && !is_dir($folder)) {
        error_log('[ticket-scanner] cannot create photo folder ' . $folder);
        fail(500, 'Could not store the photo');
    }
    if ($photo !== null) {
        $photoPath = $folder . '/' . $scanId . '.jpg';
        file_put_contents($photoPath, $photo, LOCK_EX);
    }
    foreach ($fieldImages as $name => $bytes) {
        if ($bytes !== null) {
            file_put_contents($folder . '/' . $scanId . '_' . $name . '.jpg', $bytes, LOCK_EX);
        }
    }
}

$audit = $data;
unset($audit['photo'], $audit['fieldImages']);
$total = array_sum(array_map(static fn(array $s): float => $s['price'] ?? 0.0, $ticked));
$serviceCount = count(array_filter($ticked, static fn(array $s): bool => $s['price'] !== null));

try {
    $pdo->beginTransaction();
    $insert = $pdo->prepare(
        'INSERT INTO scanned_tickets
            (scan_id, template, scanned_at, scanned_at_utc, device, field_c, treatments, tips,
             services_count, services_total, corrected_by_staff, photo_path, raw_payload)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $insert->execute([
        $scanId, $template, $scannedLocal, $scannedUtc, $device, $fieldC, $treatments, $tips,
        $serviceCount, round($total, 2), !empty($data['correctedByStaff']) ? 1 : 0, $photoPath,
        json_encode($audit, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
    ]);
    $ticketId = (int) $pdo->lastInsertId();

    $line = $pdo->prepare(
        'INSERT INTO scanned_ticket_services (ticket_id, code, name, section, price, confidence, changed_by_staff)
         VALUES (?, ?, ?, ?, ?, ?, ?)'
    );
    foreach ($ticked as $s) {
        $line->execute([$ticketId, $s['code'], $s['name'], $s['section'], $s['price'], round($s['confidence'], 3), $s['changed'] ? 1 : 0]);
    }
    $pdo->commit();
} catch (PDOException $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    // Two sends of the same scan at the same moment: the unique key wins, report the stored one.
    if ($e->getCode() === '23000') {
        $existing->execute([$scanId]);
        respond(200, ['ok' => true, 'id' => (int) $existing->fetchColumn(), 'duplicate' => true]);
    }
    error_log('[ticket-scanner] saving ticket failed: ' . $e->getMessage());
    fail(500, 'Could not save the ticket, the phone will retry');
}

respond(200, ['ok' => true, 'id' => $ticketId]);
