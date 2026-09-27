<?php
declare(strict_types=1);
defined('APP_ROOT') || exit;

final class SlotUnavailable extends RuntimeException {}

const BOOKING_STATUSES = [
    'pending'   => 'Pending',
    'confirmed' => 'Confirmed',
    'completed' => 'Completed',
    'cancelled' => 'Cancelled',
];

/* ---------- Services ---------- */

function active_services(): array
{
    return db_all('SELECT * FROM services WHERE is_active = 1 ORDER BY sort_order, name');
}

function find_service(int $id): ?array
{
    return db_one('SELECT * FROM services WHERE id = ?', [$id]);
}

function find_active_service_by_slug(string $slug): ?array
{
    return db_one('SELECT * FROM services WHERE slug = ? AND is_active = 1', [$slug]);
}

/** Meeting types allowed for a service, as key => label. */
function service_meeting_types(array $service): array
{
    $all = config('booking.meeting_types', []);
    $allowed = array_filter(array_map('trim', explode(',', (string) $service['meeting_types'])));
    $types = array_intersect_key($all, array_flip($allowed));
    return $types ?: $all;
}

/* ---------- Availability ---------- */

function working_hours(): array
{
    static $hours = null;
    if ($hours === null) {
        $hours = [];
        foreach (db_all('SELECT * FROM working_hours ORDER BY day_of_week') as $row) {
            $hours[(int) $row['day_of_week']] = $row;
        }
    }
    return $hours;
}

function blocked_dates_between(string $from, string $to): array
{
    $rows = db_all('SELECT blocked_on FROM blocked_dates WHERE blocked_on BETWEEN ? AND ?', [$from, $to]);
    return array_flip(array_column($rows, 'blocked_on'));
}

/** Pending and confirmed bookings overlapping a period, as [start, end] pairs. */
function busy_periods(DateTimeImmutable $from, DateTimeImmutable $to, ?int $ignoreBookingId = null): array
{
    $rows = db_all(
        "SELECT id, start_at, end_at FROM bookings
         WHERE status IN ('pending','confirmed') AND start_at < ? AND end_at > ?",
        [$to->format('Y-m-d H:i:s'), $from->format('Y-m-d H:i:s')]
    );
    $periods = [];
    foreach ($rows as $row) {
        if ($ignoreBookingId !== null && (int) $row['id'] === $ignoreBookingId) {
            continue;
        }
        $periods[] = [new DateTimeImmutable($row['start_at']), new DateTimeImmutable($row['end_at'])];
    }
    return $periods;
}

/** Last date a client may book (inclusive). */
function booking_horizon(): DateTimeImmutable
{
    return (new DateTimeImmutable('today'))->modify('+' . (int) config('booking.max_days_ahead', 60) . ' days');
}

/**
 * Free start times ("H:i") for every bookable day in a range.
 *
 * @return array<string, string[]>  date => slots
 */
function available_slots_between(string $fromDate, string $toDate, int $duration): array
{
    $from = DateTimeImmutable::createFromFormat('!Y-m-d', $fromDate);
    $to = DateTimeImmutable::createFromFormat('!Y-m-d', $toDate);
    if (!$from || !$to || $duration < 5) {
        return [];
    }
    $today = new DateTimeImmutable('today');
    $from = max($from, $today);
    $to = min($to, booking_horizon());
    if ($from > $to) {
        return [];
    }

    $interval = max(5, (int) config('booking.slot_interval', 30));
    $buffer = max(0, (int) config('booking.buffer_minutes', 0));
    $earliest = new DateTimeImmutable('+' . (int) config('booking.min_notice_hours', 24) . ' hours');
    $hours = working_hours();
    $blocked = blocked_dates_between($from->format('Y-m-d'), $to->format('Y-m-d'));
    $busy = busy_periods($from->modify("-{$buffer} minutes"), $to->modify('+1 day')->modify("+{$buffer} minutes"));

    $result = [];
    for ($day = $from; $day <= $to; $day = $day->modify('+1 day')) {
        $date = $day->format('Y-m-d');
        $wh = $hours[(int) $day->format('N')] ?? null;
        if (!$wh || !(int) $wh['is_open'] || isset($blocked[$date])) {
            continue;
        }
        $open = new DateTimeImmutable($date . ' ' . $wh['open_time']);
        $close = new DateTimeImmutable($date . ' ' . $wh['close_time']);
        $slots = [];
        for ($start = $open; ; $start = $start->modify("+{$interval} minutes")) {
            $end = $start->modify("+{$duration} minutes");
            if ($end > $close) {
                break;
            }
            if ($start < $earliest) {
                continue;
            }
            foreach ($busy as [$busyStart, $busyEnd]) {
                if ($start < $busyEnd->modify("+{$buffer} minutes") && $end > $busyStart->modify("-{$buffer} minutes")) {
                    continue 2;
                }
            }
            $slots[] = $start->format('H:i');
        }
        if ($slots) {
            $result[$date] = $slots;
        }
    }
    return $result;
}

function available_slots(string $date, int $duration): array
{
    return available_slots_between($date, $date, $duration)[$date] ?? [];
}

/* ---------- Bookings ---------- */

function generate_reference(): string
{
    $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    do {
        $ref = '';
        for ($i = 0; $i < 8; $i++) {
            $ref .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }
    } while (db_value('SELECT 1 FROM bookings WHERE reference = ?', [$ref]));
    return $ref;
}

function format_reference(string $ref): string
{
    return substr($ref, 0, 4) . '-' . substr($ref, 4);
}

/**
 * Create a booking after re-checking the slot inside a lock, so two people
 * can never grab the same time.
 *
 * @return array{id:int, reference:string, token:string}
 */
function create_booking(array $service, DateTimeImmutable $start, array $data): array
{
    $pdo = db();
    if ((int) $pdo->query("SELECT GET_LOCK('deea_booking_slots', 10)")->fetchColumn() !== 1) {
        throw new RuntimeException('Could not lock the calendar, please try again.');
    }
    try {
        $duration = (int) $service['duration_minutes'];
        if (!in_array($start->format('H:i'), available_slots($start->format('Y-m-d'), $duration), true)) {
            throw new SlotUnavailable('That time is no longer available.');
        }
        $reference = generate_reference();
        $token = bin2hex(random_bytes(24));
        db_run(
            'INSERT INTO bookings (reference, token_hash, service_id, start_at, end_at, meeting_type, client_name,
                client_email, client_phone, business_name, website, budget, message, consent_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())',
            [
                $reference,
                hash('sha256', $token),
                $service['id'],
                $start->format('Y-m-d H:i:s'),
                $start->modify("+{$duration} minutes")->format('Y-m-d H:i:s'),
                $data['meeting_type'],
                $data['client_name'],
                $data['client_email'],
                $data['client_phone'],
                $data['business_name'],
                $data['website'],
                $data['budget'],
                $data['message'],
            ]
        );
        return ['id' => (int) $pdo->lastInsertId(), 'reference' => $reference, 'token' => $token];
    } finally {
        $pdo->query("SELECT RELEASE_LOCK('deea_booking_slots')");
    }
}

const BOOKING_SELECT = 'SELECT b.*, s.name AS service_name, s.slug AS service_slug, s.icon AS service_icon,
        s.duration_minutes, s.price_from
    FROM bookings b JOIN services s ON s.id = b.service_id';

function find_booking(int $id): ?array
{
    return db_one(BOOKING_SELECT . ' WHERE b.id = ?', [$id]);
}

/** Look up a booking from the client's private link. */
function find_booking_by_token(string $reference, string $token): ?array
{
    $reference = strtoupper(str_replace('-', '', $reference));
    if (!preg_match('/^[A-Z0-9]{8}$/', $reference) || !preg_match('/^[a-f0-9]{48}$/', $token)) {
        return null;
    }
    $booking = db_one(BOOKING_SELECT . ' WHERE b.reference = ?', [$reference]);
    if (!$booking || !hash_equals($booking['token_hash'], hash('sha256', $token))) {
        return null;
    }
    return $booking;
}

function manage_url(string $reference, string $token): string
{
    return absolute_url('manage.php?ref=' . rawurlencode($reference) . '&token=' . rawurlencode($token));
}

function booking_is_upcoming(array $booking): bool
{
    return in_array($booking['status'], ['pending', 'confirmed'], true)
        && new DateTimeImmutable($booking['start_at']) > new DateTimeImmutable();
}

function meeting_type_label(string $type): string
{
    return config('booking.meeting_types', [])[$type] ?? ucfirst(str_replace('_', ' ', $type));
}

function status_badge(string $status): string
{
    return '<span class="badge badge--' . e($status) . '">' . e(BOOKING_STATUSES[$status] ?? $status) . '</span>';
}

/** iCalendar (.ics) file for a booking. */
function booking_ics(array $booking, string $manageUrl = ''): string
{
    $utc = new DateTimeZone('UTC');
    $fmt = static fn(string $dt): string => (new DateTimeImmutable($dt))->setTimezone($utc)->format('Ymd\THis\Z');
    $esc = static fn(string $s): string => addcslashes(str_replace(["\r\n", "\n"], '\n', $s), ',;\\');
    $brand = (string) config('app.brand_name');
    $host = parse_url(absolute_url(), PHP_URL_HOST) ?: 'localhost';
    $description = 'Booking ' . format_reference($booking['reference']) . ' — ' . meeting_type_label($booking['meeting_type']);
    if ($manageUrl !== '') {
        $description .= "\nManage your booking: " . $manageUrl;
    }
    $lines = [
        'BEGIN:VCALENDAR',
        'VERSION:2.0',
        'PRODID:-//' . $brand . '//Booking//EN',
        'CALSCALE:GREGORIAN',
        'METHOD:PUBLISH',
        'BEGIN:VEVENT',
        'UID:' . $booking['reference'] . '@' . $host,
        'DTSTAMP:' . gmdate('Ymd\THis\Z'),
        'DTSTART:' . $fmt($booking['start_at']),
        'DTEND:' . $fmt($booking['end_at']),
        'SUMMARY:' . $esc($booking['service_name'] . ' with ' . $brand),
        'DESCRIPTION:' . $esc($description),
        'STATUS:' . ($booking['status'] === 'cancelled' ? 'CANCELLED' : ($booking['status'] === 'confirmed' ? 'CONFIRMED' : 'TENTATIVE')),
        'END:VEVENT',
        'END:VCALENDAR',
    ];
    return implode("\r\n", $lines) . "\r\n";
}
