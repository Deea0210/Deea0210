<?php
/**
 * Receives today's Treatwell bookings from the Salon Bookings Sync extension.
 *
 *   POST  {"date":"2026-10-03","complete":true,"bookings":[…]}  → 200 {"ok":true,"saved":12,"removed":1}
 *   GET   ?ping=1                                              → 200 {"ok":true}  ("Test connection")
 *
 * Every request needs:  Authorization: Bearer <TREATWELL_SYNC_KEY>
 * "complete" means the list is the whole day as Treatwell's calendar shows it, so bookings
 * of that day that are no longer in it are marked removed (cancelled or moved in Treatwell).
 */
declare(strict_types=1);

$config = require dirname(__DIR__) . '/config.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

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

/** Text value, trimmed and cut to $max characters (Treatwell's own text is never rejected for length). */
function text_value(array $data, string $key, int $max): string
{
    $value = $data[$key] ?? '';
    if (is_int($value) || is_float($value)) {
        $value = (string) $value;
    }
    if (!is_string($value)) {
        return '';
    }
    return mb_substr(trim(preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/u', '', $value) ?? ''), 0, $max);
}

// ------------------------------------------------------------------- auth

if (strlen($config['api_key']) < 32) {
    error_log('[treatwell-sync] TREATWELL_SYNC_KEY is missing or shorter than 32 characters');
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

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($method === 'GET' && isset($_GET['ping'])) {
    respond(200, ['ok' => true, 'service' => 'treatwell-bookings-receiver']);
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
    $data = json_decode($raw, true, 16, JSON_THROW_ON_ERROR);
} catch (JsonException) {
    fail(400, 'Invalid JSON');
}
if (!is_array($data)) {
    fail(400, 'Invalid JSON');
}

$date = is_string($data['date'] ?? null) ? $data['date'] : '';
$day = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
if (!$day || $day->format('Y-m-d') !== $date) {
    fail(400, '"date" must be YYYY-MM-DD');
}
$complete = ($data['complete'] ?? false) === true;
$list = $data['bookings'] ?? null;
if (!is_array($list) || !array_is_list($list) || count($list) > 1000) {
    fail(400, '"bookings" must be a list');
}

$time = static function (mixed $v): ?string {
    return is_string($v) && preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $v) ? $v . ':00' : null;
};

$rows = [];
foreach ($list as $i => $b) {
    if (!is_array($b)) {
        fail(400, "bookings[{$i}] must be an object");
    }
    $id = text_value($b, 'id', 80);
    $start = $time($b['start'] ?? null);
    if ($id === '' || $start === null) {
        fail(400, "bookings[{$i}] needs an id and a start time");
    }
    if (($b['date'] ?? $date) !== $date) {
        continue; // only the day being sent
    }
    $price = $b['price'] ?? null;
    $rows[$id] = [
        'treatwell_id'   => $id,
        'start_time'     => $start,
        'end_time'       => $time($b['end'] ?? null),
        'staff_name'     => text_value($b, 'staff', 120),
        'service'        => text_value($b, 'service', 255),
        'customer_name'  => text_value($b, 'customer', 160),
        'customer_phone' => text_value($b, 'phone', 40),
        'customer_email' => text_value($b, 'email', 160),
        'price'          => (is_int($price) || is_float($price)) && $price >= 0 && $price < 100000 ? round((float) $price, 2) : null,
        'status'         => text_value($b, 'status', 40),
        'cancelled'      => ($b['cancelled'] ?? false) === true ? 1 : 0,
        'no_show'        => ($b['noShow'] ?? false) === true ? 1 : 0,
        'notes'          => text_value($b, 'notes', 2000),
        'channel'        => text_value($b, 'channel', 60),
    ];
}

// ----------------------------------------------------------------- store

try {
    $pdo = new PDO($config['db_dsn'], $config['db_user'], $config['db_pass'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
} catch (PDOException $e) {
    error_log('[treatwell-sync] database connection failed: ' . $e->getMessage());
    fail(503, 'Database unavailable, the extension will retry');
}

try {
    $pdo->beginTransaction();
    $upsert = $pdo->prepare(
        'INSERT INTO treatwell_bookings
            (treatwell_id, booking_date, start_time, end_time, staff_name, service, customer_name, customer_phone,
             customer_email, price, status, cancelled, no_show, removed, notes, channel)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, ?, ?)
         ON DUPLICATE KEY UPDATE
            booking_date = VALUES(booking_date), start_time = VALUES(start_time), end_time = VALUES(end_time),
            staff_name = VALUES(staff_name), service = VALUES(service), customer_name = VALUES(customer_name),
            customer_phone = VALUES(customer_phone), customer_email = VALUES(customer_email), price = VALUES(price),
            status = VALUES(status), cancelled = VALUES(cancelled), no_show = VALUES(no_show), removed = 0,
            notes = VALUES(notes), channel = VALUES(channel)'
    );
    foreach ($rows as $r) {
        $upsert->execute([
            $r['treatwell_id'], $date, $r['start_time'], $r['end_time'], $r['staff_name'], $r['service'], $r['customer_name'],
            $r['customer_phone'], $r['customer_email'], $r['price'], $r['status'], $r['cancelled'], $r['no_show'],
            $r['notes'], $r['channel'],
        ]);
    }

    $removed = 0;
    if ($complete) {
        $ids = array_keys($rows);
        $sql = 'UPDATE treatwell_bookings SET removed = 1 WHERE booking_date = ? AND removed = 0';
        if ($ids) {
            $sql .= ' AND treatwell_id NOT IN (' . implode(',', array_fill(0, count($ids), '?')) . ')';
        }
        $stmt = $pdo->prepare($sql);
        $stmt->execute(array_merge([$date], array_map('strval', $ids)));
        $removed = $stmt->rowCount();
    }
    $pdo->commit();
} catch (PDOException $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('[treatwell-sync] saving bookings failed: ' . $e->getMessage());
    fail(500, 'Could not save the bookings, the extension will retry');
}

respond(200, ['ok' => true, 'date' => $date, 'saved' => count($rows), 'removed' => $removed]);
