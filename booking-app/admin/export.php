<?php
declare(strict_types=1);
require dirname(__DIR__) . '/includes/bootstrap.php';
require_admin();

$rows = db_all(BOOKING_SELECT . ' ORDER BY b.start_at DESC');

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="bookings-' . date('Y-m-d') . '.csv"');

$out = fopen('php://output', 'w');
fwrite($out, "\xEF\xBB\xBF"); // BOM so Excel opens UTF-8 correctly
fputcsv($out, ['Reference', 'Status', 'Service', 'Date', 'Start', 'End', 'Meeting', 'Name', 'Email', 'Phone', 'Business', 'Website', 'Budget', 'Message', 'Notes', 'Booked at'], ',', '"', '');

// Prevent spreadsheet formula injection from client-entered text.
$safe = static fn(?string $v): string => preg_match('/^[=+\-@\t\r]/', (string) $v) ? "'" . $v : (string) $v;

foreach ($rows as $b) {
    fputcsv($out, array_map($safe, [
        format_reference($b['reference']),
        $b['status'],
        $b['service_name'],
        format_date($b['start_at'], 'Y-m-d'),
        format_time($b['start_at']),
        format_time($b['end_at']),
        meeting_type_label($b['meeting_type']),
        $b['client_name'],
        $b['client_email'],
        $b['client_phone'],
        $b['business_name'],
        $b['website'],
        $b['budget'],
        $b['message'],
        $b['admin_notes'],
        $b['created_at'],
    ]), ',', '"', '');
}
fclose($out);
