# Deea: Web Developer & Digital Creative

Everything needed to promote and book **website, web app, SEO, content, graphic design, photography and video**
services for small businesses:

| Folder | What's inside |
| --- | --- |
| [`booking-app/`](booking-app/) | **Landing page + online booking web app** (PHP & MySQL, runs on XAMPP). Clients pick a service like "Make me a website", choose a free time in a live calendar and get a confirmation email with a private manage/cancel link. You manage bookings, services, prices and opening hours in the admin panel. |
| [`wordpress-theme/`](wordpress-theme/) | **WordPress theme** with the same design: landing page, services editable in WP Admin, blog, and "Book" buttons that link to the booking app. Upload `deea.zip` in WordPress. |
| [`blade-boost/`](blade-boost/) | **BladeBoost**, a Windows app for the Razer Blade 15 (Late 2020) that replaces Razer Synapse: CPU/GPU temperatures, performance modes, manual fan speed, and a Game Mode that pauses background services and apps with one-click **Restore**. Download `BladeBoost.exe` from the Releases page. |
| [`marketing/`](marketing/) | **Business card** (print-ready PDF, EU and US sizes), **social media ads** (feed, story, link ad), an **A5 flyer** for small businesses, and ready-to-paste **ad copy** for Meta, Google Ads and Google Business Profile. |

## Quick start with XAMPP

1. Copy `booking-app` to `C:\xampp\htdocs\booking-app` and start **Apache** + **MySQL** in the XAMPP Control Panel.
2. Open <http://localhost/booking-app/install.php> and create your admin login.
3. Visit <http://localhost/booking-app/> (landing page) and <http://localhost/booking-app/admin/> (admin).
4. *(Optional)* Install WordPress in `htdocs/wordpress`, upload `wordpress-theme/deea.zip` and activate it.
   Its "Book" buttons already point to `/booking-app/book.php`.

Full guides: [booking app](booking-app/README.md) · [WordPress theme](wordpress-theme/README.md) · [marketing kit](marketing/README.md)

## Before going live: replace the placeholders

- **Contact details:** `booking-app/config.php` (or `config.local.php`), WordPress **Customize → Deea: Business details**, and `marketing/brand.js`.
  They currently use `hello@yourdomain.com`, `yourdomain.com` and `+40 7XX XXX XXX`.
- **Prices:** the starter "from" prices are examples. Set yours in the booking app's **Admin → Services** and WordPress **Services**.
- **Texts:** hero, about and FAQ copy is written in a friendly first-person voice. Adjust it to sound like you.
- **Timezone:** `Europe/Bucharest` by default (`app.timezone` in `booking-app/config.php`).
- Re-export the business card and ads with `node marketing/render.mjs` so the QR codes point at your real booking page.
