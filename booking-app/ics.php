<?php
declare(strict_types=1);
require __DIR__ . '/includes/bootstrap.php';

$token = (string) ($_GET['token'] ?? '');
$booking = find_booking_by_token((string) ($_GET['ref'] ?? ''), $token);
if (!$booking) {
    http_response_code(404);
    exit('Booking not found.');
}

header('Content-Type: text/calendar; charset=utf-8');
header('Content-Disposition: attachment; filename="booking-' . $booking['reference'] . '.ics"');
header('Cache-Control: no-store');
echo booking_ics($booking, manage_url($booking['reference'], $token));
