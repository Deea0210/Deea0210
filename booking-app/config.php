<?php
/**
 * Default configuration — works out of the box on XAMPP.
 *
 * Do NOT put production passwords in this file. For a live server, create
 * `config.local.php` next to this file (it is git-ignored) and return only the
 * keys you want to override, e.g.:
 *
 *   <?php return [
 *       'app' => ['base_url' => 'https://book.yourdomain.com', 'email' => 'you@yourdomain.com'],
 *       'db'  => ['name' => 'live_db', 'user' => 'live_user', 'pass' => 'secret'],
 *       'mail'=> ['enabled' => true],
 *   ];
 */
return [
    'app' => [
        'brand_name'  => 'Deea',
        'owner_name'  => 'Deea',
        'role'        => 'Web Developer & Digital Creative',
        'tagline'     => 'Websites · Web apps · SEO · Content · Design · Photo & Video',
        'email'       => 'hello@yourdomain.com',
        'phone'       => '',                 // e.g. '+40 712 345 678' — hidden when empty
        'location'    => 'Available worldwide · online & on location',
        'base_url'    => '',                 // e.g. 'https://book.yourdomain.com' (recommended in production)
        'timezone'    => 'Europe/Bucharest', // https://www.php.net/manual/en/timezones.php
        'currency'    => '€%s',              // how prices are shown, e.g. '%s lei', '$%s', '£%s'
        'social'      => [
            'instagram' => '',
            'facebook'  => '',
            'linkedin'  => '',
            'tiktok'    => '',
            'youtube'   => '',
            'github'    => 'https://github.com/Deea0210',
        ],
        // Shown in a "Selected work" section on the landing page (hidden while empty).
        // Example: ['title' => 'Bakery website', 'category' => 'Website + SEO', 'image' => 'assets/img/work/bakery.jpg', 'url' => 'https://…'],
        'portfolio'   => [],
    ],

    'db' => [
        'host'    => '127.0.0.1',
        'port'    => 3306,
        'name'    => 'deea_booking',
        'user'    => 'root',   // XAMPP default
        'pass'    => '',       // XAMPP default (empty)
        'charset' => 'utf8mb4',
    ],

    'booking' => [
        'slot_interval'    => 30,  // minutes between possible start times
        'min_notice_hours' => 24,  // earliest a client can book, from now
        'max_days_ahead'   => 60,  // how far ahead the calendar opens
        'buffer_minutes'   => 15,  // free time kept between appointments
        'max_open_per_email' => 3, // pending/confirmed future bookings allowed per email
        'meeting_types' => [
            'online'    => 'Video call (Google Meet / Zoom)',
            'phone'     => 'Phone call',
            'in_person' => 'In person / on location',
        ],
        'budgets' => [
            'Under €500',
            '€500 – €1,500',
            '€1,500 – €5,000',
            '€5,000+',
            'Not sure yet',
        ],
    ],

    'mail' => [
        // XAMPP has no mail server by default, so emails are written to
        // storage/mail-log.php (viewable in Admin → Email log) until you enable this.
        'enabled'   => false,
        'from'      => 'no-reply@yourdomain.com',
        'from_name' => 'Deea',
        'admin_to'  => 'hello@yourdomain.com', // where new-booking alerts go
    ],
];
