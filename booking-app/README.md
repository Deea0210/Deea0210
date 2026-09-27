# Deea Booking: landing page + online booking web app

A fast, dependency-free PHP & MySQL web app where clients can:

- read about your services on a **landing page** (SEO-ready, with structured data, sitemap and Open Graph tags),
- **book a consultation or session** for a service ("Make me a website", "Develop me a web app", "Get me found on Google"…) in a live availability calendar,
- get an email with a private link to **view, add to calendar (.ics) or cancel** their booking.

And where **you** (the admin) can:

- confirm, complete or cancel bookings and email the client in one click (with a meeting link or note),
- keep private notes per client and export everything to CSV,
- manage services, prices, appointment lengths and meeting types,
- set weekly opening hours and block holidays or shoot days.

No frameworks, no Composer, no build step: copy the folder to any PHP host and it runs.

---

## Run it locally with XAMPP

1. Copy the `booking-app` folder into XAMPP's web root:
   - Windows: `C:\xampp\htdocs\booking-app`
   - macOS: `/Applications/XAMPP/htdocs/booking-app`
   - Linux: `/opt/lampp/htdocs/booking-app`
2. Open the **XAMPP Control Panel** and start **Apache** and **MySQL**.
3. Visit <http://localhost/booking-app/install.php>, choose an admin username and password, and click **Install**.
   This creates the `deea_booking` database, the tables, 8 starter services and opening hours (Mon–Fri 9–17, Sat 10–14).
4. Done:
   - Landing page: <http://localhost/booking-app/>
   - Booking page: <http://localhost/booking-app/book.php>
   - Admin: <http://localhost/booking-app/admin/>

> XAMPP has no mail server, so emails are **logged** instead of sent. Read them in **Admin → Email log**.

`config.php` already uses XAMPP's defaults (`root`, empty password, `127.0.0.1:3306`). If you changed your MySQL password, update the `db` section.

## Make it yours

Edit **`config.php`** (or better, create `config.local.php`, see below):

| Setting | What it does |
| --- | --- |
| `app.brand_name`, `owner_name`, `role`, `tagline` | Your name and title everywhere on the site and in emails |
| `app.email`, `app.phone`, `app.location` | Contact details in the footer, emails and structured data |
| `app.social` | Links shown in the footer (leave empty to hide) |
| `app.portfolio` | Adds a "Selected work" section (hidden while empty) |
| `app.timezone`, `app.currency` | Your timezone and how prices are shown (`€%s`, `%s lei`, `$%s`…) |
| `booking.*` | Slot interval, minimum notice, how far ahead people can book, breaks between meetings, budget options |
| `mail.*` | Turn real email on, and set the sender and where new-booking alerts go |

Services, prices and opening hours are edited in the **admin panel**, not in code.
The starter prices are placeholders, so set your own in **Admin → Services**.

Text on the landing page (hero, about, FAQ) lives in `index.php`, and the privacy notice in `privacy.php`.

## Deploy to a live server

Works on any host with **PHP 8.1+** and **MySQL 5.7+ / MariaDB 10.3+** (cPanel, Hostinger, SiteGround, a VPS…).

1. Create a MySQL database and user in your hosting panel.
2. Upload the contents of `booking-app/` (to the domain root, a subdomain like `book.yourdomain.com`, or a subfolder).
3. Create `config.local.php` next to `config.php` with only your live settings:

   ```php
   <?php
   return [
       'app'  => ['base_url' => 'https://book.yourdomain.com', 'email' => 'you@yourdomain.com', 'phone' => '+40 7xx xxx xxx'],
       'db'   => ['name' => 'your_db', 'user' => 'your_user', 'pass' => 'your-strong-password'],
       'mail' => ['enabled' => true, 'from' => 'no-reply@yourdomain.com', 'admin_to' => 'you@yourdomain.com'],
   ];
   ```

4. Visit `https://your-site/install.php`, create your admin login, then **delete `install.php`**.
5. Turn on HTTPS (free Let's Encrypt in most panels). Session cookies automatically become `Secure`.
6. Submit `https://your-site/sitemap.xml` in Google Search Console.

If your host needs SMTP for email (e.g. Gmail / Google Workspace), keep `mail.enabled` on and set the host's
sendmail/SMTP relay, or swap `send_mail()` in `includes/mailer.php` for PHPMailer.

## How it works

```
booking-app/
├── index.php            Landing page (services pulled live from the database)
├── book.php             Booking form + server-side validation
├── manage.php           Client's private booking page (view / cancel)
├── ics.php              "Add to calendar" download
├── privacy.php          Privacy notice (GDPR-friendly template)
├── sitemap.php, robots.php   Served as /sitemap.xml and /robots.txt
├── install.php          One-time installer (delete after use on a live server)
├── api/availability.php JSON: free time slots per day for a service + month
├── admin/               Bookings, services, availability, email log, account, CSV export
├── includes/            Bootstrap, database, auth, booking rules, mailer, layout, icons
├── database/            schema.sql + seed.sql (also importable via phpMyAdmin)
├── assets/              CSS, JS, self-hosted fonts (no Google tracking), favicon
└── storage/             Email log (protected from the web)
```

**Availability rules:** a start time is offered when it falls within your opening hours for that day, the day isn't blocked,
it's at least `min_notice_hours` away and within `max_days_ahead`, and the appointment (plus `buffer_minutes` either side)
doesn't overlap a pending or confirmed booking. Bookings are re-checked inside a database lock when submitted, so two
people can never grab the same slot.

**Security:** prepared statements everywhere, CSRF tokens on every form, hashed passwords, login rate-limiting
(5 attempts / 15 min per IP), private booking links stored as SHA-256 hashes, a honeypot field against spam bots,
a strict Content-Security-Policy, and `.htaccess` rules that block config, SQL, include and log files.
