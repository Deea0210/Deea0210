<?php
declare(strict_types=1);
require dirname(__DIR__) . '/includes/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$service = find_active_service_by_slug((string) ($_GET['service'] ?? ''));
$month = (string) ($_GET['month'] ?? date('Y-m'));

if (!$service || !preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month)) {
    http_response_code(400);
    echo json_encode(['error' => 'Unknown service or month.']);
    exit;
}

$first = new DateTimeImmutable($month . '-01');
$days = available_slots_between($first->format('Y-m-d'), $first->format('Y-m-t'), (int) $service['duration_minutes']);

echo json_encode([
    'service'  => $service['slug'],
    'month'    => $month,
    'duration' => (int) $service['duration_minutes'],
    'days'     => (object) $days,
]);
