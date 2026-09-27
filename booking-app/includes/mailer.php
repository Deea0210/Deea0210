<?php
declare(strict_types=1);
defined('APP_ROOT') || exit;

const MAIL_LOG = APP_ROOT . '/storage/mail-log.php';

/**
 * Send a plain-text email with PHP's mail(). When mail is disabled (the
 * default on XAMPP) or sending fails, the message is written to the mail log
 * instead, so nothing is lost while testing.
 */
function send_mail(string $to, string $subject, string $body, ?string $replyTo = null): bool
{
    $to = trim($to);
    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
        return false;
    }
    $sent = false;
    if (config('mail.enabled')) {
        $fromName = mb_encode_mimeheader((string) config('mail.from_name', config('app.brand_name')), 'UTF-8');
        $headers = [
            'From'                      => $fromName . ' <' . config('mail.from') . '>',
            'MIME-Version'              => '1.0',
            'Content-Type'              => 'text/plain; charset=UTF-8',
            'Content-Transfer-Encoding' => '8bit',
            'X-Mailer'                  => 'Deea Booking',
        ];
        if ($replyTo && filter_var($replyTo, FILTER_VALIDATE_EMAIL)) {
            $headers['Reply-To'] = $replyTo;
        }
        $sent = @mail($to, mb_encode_mimeheader($subject, 'UTF-8'), str_replace("\n", "\r\n", $body), $headers);
    }
    log_mail($to, $subject, $body, $sent);
    return $sent;
}

function log_mail(string $to, string $subject, string $body, bool $sent): void
{
    if (!is_file(MAIL_LOG)) {
        @file_put_contents(MAIL_LOG, "<?php http_response_code(404); exit; ?>\n");
    }
    $entry = sprintf(
        "=== %s | %s | To: %s\nSubject: %s\n\n%s\n\n",
        date('Y-m-d H:i:s'),
        $sent ? 'SENT' : (config('mail.enabled') ? 'FAILED' : 'LOGGED (mail disabled)'),
        $to,
        $subject,
        $body
    );
    @file_put_contents(MAIL_LOG, $entry, FILE_APPEND | LOCK_EX);
}

function booking_summary_text(array $booking): string
{
    $lines = [
        'Service:   ' . $booking['service_name'],
        'When:      ' . format_date($booking['start_at']) . ', ' . format_time($booking['start_at']) . '–' . format_time($booking['end_at']) . ' (' . config('app.timezone') . ')',
        'How:       ' . meeting_type_label($booking['meeting_type']),
        'Reference: ' . format_reference($booking['reference']),
    ];
    return implode("\n", $lines);
}

function mail_signature(): string
{
    $sig = "\n\n— " . config('app.owner_name') . "\n" . config('app.role');
    if (config('app.phone')) {
        $sig .= "\n" . config('app.phone');
    }
    return $sig . "\n" . config('app.email');
}

function notify_booking_created(array $booking, string $token): void
{
    $manage = manage_url($booking['reference'], $token);
    $first = explode(' ', trim($booking['client_name']))[0];

    send_mail(
        $booking['client_email'],
        'Booking request received — ' . $booking['service_name'],
        "Hi {$first},\n\nThanks for booking with " . config('app.brand_name') . "! Your request is in, and I'll confirm it shortly.\n\n"
            . booking_summary_text($booking)
            . "\n\nView, add to your calendar or cancel your booking here:\n{$manage}"
            . mail_signature(),
        (string) config('app.email')
    );

    $admin = absolute_url('admin/booking.php?id=' . $booking['id']);
    send_mail(
        (string) config('mail.admin_to'),
        'New booking: ' . $booking['client_name'] . ' — ' . $booking['service_name'],
        "New booking request\n\n" . booking_summary_text($booking)
            . "\n\nClient:   " . $booking['client_name']
            . "\nEmail:    " . $booking['client_email']
            . "\nPhone:    " . ($booking['client_phone'] ?: '—')
            . "\nBusiness: " . ($booking['business_name'] ?: '—')
            . "\nWebsite:  " . ($booking['website'] ?: '—')
            . "\nBudget:   " . ($booking['budget'] ?: '—')
            . "\n\nMessage:\n" . $booking['message']
            . "\n\nOpen in admin: {$admin}\n",
        $booking['client_email']
    );
}

function notify_status_changed(array $booking, string $note = ''): void
{
    $first = explode(' ', trim($booking['client_name']))[0];
    $intro = match ($booking['status']) {
        'confirmed' => "Great news — your booking is confirmed. See you then!",
        'cancelled' => "Your booking has been cancelled. If you'd like to pick another time, you can book again anytime at " . absolute_url('book.php') . '.',
        'completed' => "Thank you for working with me! It was a pleasure.",
        default     => 'There is an update to your booking.',
    };
    $body = "Hi {$first},\n\n{$intro}\n\n" . booking_summary_text($booking);
    if ($note !== '') {
        $body .= "\n\nNote from " . config('app.owner_name') . ":\n" . $note;
    }
    send_mail(
        $booking['client_email'],
        'Booking ' . strtolower(BOOKING_STATUSES[$booking['status']] ?? 'update') . ' — ' . $booking['service_name'],
        $body . mail_signature(),
        (string) config('app.email')
    );
}

function notify_client_cancelled(array $booking): void
{
    send_mail(
        (string) config('mail.admin_to'),
        'Cancelled by client: ' . $booking['client_name'] . ' — ' . $booking['service_name'],
        "The client cancelled this booking:\n\n" . booking_summary_text($booking)
            . "\n\nClient: " . $booking['client_name'] . ' <' . $booking['client_email'] . ">\n"
    );
}
