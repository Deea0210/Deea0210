# Salon Bookings Sync (Treatwell Connect)

A Chrome extension for the reception computer. While **Treatwell Connect** is open and logged in, it reads
**today's bookings** from the Connect calendar, keeps them up to date on its own, and can send them to the salon
software.

```
 Chrome on the reception PC                                         your AWS server
┌───────────────────────────────────────────────┐   HTTPS POST    ┌───────────────────────────────┐
│ Treatwell Connect tab (logged in as usual)    │   (JSON, key)   │ receiver/api/bookings.php     │
│   └ the calendar loads its bookings  ──┐      │ ──────────────▶ │  → MySQL: treatwell_bookings  │
│ Salon Bookings Sync                    ▼      │                 └───────────────────────────────┘
│   reads them, refreshes every 5 min, list +   │
│   CSV, sends today's list when it changes     │
└───────────────────────────────────────────────┘
```

## How it reads the bookings

Treatwell doesn't give individual salons an API, so the extension uses what the Connect calendar itself loads
from Treatwell's servers in your logged-in Chrome:

- It **listens** to the data the calendar page receives and picks out the bookings: time, client, phone,
  service, stylist, price, status and notes. Stylist and service names are matched from the other lists the page
  loads. Rotas, lunch breaks, blocked time, reviews and the menu are ignored.
- Every few minutes it **repeats the calendar's own request for today** (same address, same login), so the
  list stays current even if nobody touches Connect. It only ever *reads*: it never sends changes, never sees
  or stores your Treatwell password, and makes one small request per refresh.
- If Connect is logged out or closed, the list keeps the last bookings and says what to do.

> **First-time check.** Treatwell doesn't publish its data format, so the reading was built to cope with the
> usual ways booking systems lay out their data and was tested on a pretend Connect site, not the real one.
> After installing, compare the list with the Connect calendar. If anything is missing or wrong, click
> **"Bookings look wrong? Save a setup file"** in the extension and send the file to your developer. It contains the
> layout of the data with **no client names, phone numbers, emails or login tokens**, which is enough to fit the
> reading exactly.

This reads your own salon's data in your own logged-in browser. Treatwell's terms for partners may limit
automated access, so if in doubt check with your Treatwell account manager. The official alternative is one of
the salon systems Treatwell integrates with (Connect → Settings → Online Bookings → Integrations).

## Install (once, on the reception computer)

1. Download **[salon-bookings-sync.zip](salon-bookings-sync.zip)**, right-click it → **Extract All…** and extract
   it to a folder that will stay and is **not in OneDrive**, e.g. `C:\SalonBookingsSync` (Chrome loads it from there
   every time; OneDrive can turn files into online-only copies Chrome can't read).
2. In Chrome, open `chrome://extensions`, switch on **Developer mode** (top right) and click **Load unpacked**.
   Choose the folder that has `manifest.json` directly inside it.
3. Click the puzzle icon in Chrome's toolbar and **pin** *Salon Bookings Sync*.
4. **Reload the Treatwell Connect tab** (F5) and open its **Calendar** once.
5. Recommended: Chrome **Settings → Performance → Memory saver → Always keep these sites active → Add**
   `connect.treatwell.co.uk`, so Chrome never puts the Connect tab to sleep.

Chrome may show a notice about developer-mode extensions when it starts; that's normal for extensions installed
this way. To update later: replace the folder's contents and click the ↻ button on the extension in `chrome://extensions`.

## Using it

- **Click the icon:** today's bookings in time order, with client, phone, service, stylist, price and notes.
  Cancelled ones are crossed out. Tap a stylist's name to see only theirs. The number on the icon is today's
  bookings; a red **!** means Connect is logged out or the salon software couldn't be reached.
- **Open as a page** (arrow icon) for a full-window list on a reception screen. It updates by itself.
- **Copy list** or **Download CSV** (opens in Excel).
- **Settings** (gear icon): how often to refresh (default 5 minutes) and the salon software connection.

## Send the bookings to the salon software (optional)

1. On the AWS server, upload `receiver/api/` so it answers at e.g. `https://your-salon-software/treatwell-api/bookings.php`,
   and put `receiver/config.php` in the folder above it.
2. Run [`receiver/schema.sql`](receiver/schema.sql) on the salon database.
3. Set these environment variables for PHP:

   | Variable | Example |
   | --- | --- |
   | `TREATWELL_SYNC_KEY` | output of `php -r "echo bin2hex(random_bytes(32)), PHP_EOL;"` |
   | `TREATWELL_DB_DSN` | `mysql:host=your-rds-endpoint;dbname=salon;charset=utf8mb4` |
   | `TREATWELL_DB_USER`, `TREATWELL_DB_PASS` | your database user |

4. In the extension's **Settings**, enter the address and the key, click **Test connection**, then **Save**.
   Chrome asks once to allow the extension to reach that address: choose **Allow**.

Today's list is sent whenever it changes (and retried if the server can't be reached):

```json
{
  "source": "treatwell-connect",
  "date": "2026-10-03",
  "complete": true,
  "syncedAt": "2026-10-03T15:11:02.511Z",
  "device": "Reception PC",
  "bookings": [
    { "id": "501", "date": "2026-10-03", "start": "10:30", "end": "11:15", "customer": "John Smith",
      "phone": "07700 900123", "email": "", "service": "Skin Fade", "staff": "Renato", "price": 15.75,
      "status": "CONFIRMED", "cancelled": false, "noShow": false, "notes": "", "channel": "" }
  ]
}
```

`bookings.php` saves each booking in `treatwell_bookings` (one row per Treatwell booking, updated in place).
When `complete` is true the list is the whole day as the Connect calendar shows it, so bookings of that day that
are no longer in it are marked `removed = 1`.

```sql
SELECT TIME_FORMAT(start_time, '%H:%i') AS time, customer_name, service, staff_name, price
FROM treatwell_bookings
WHERE booking_date = CURDATE() AND removed = 0 AND cancelled = 0
ORDER BY start_time;
```

## Privacy

Client details stay on the reception computer (Chrome's extension storage, last 7 days only) and, if set up,
your own salon database. Nothing is sent anywhere else.

## Files

```
extension/          the Chrome extension (no build step)
  page-hook.js      inside the Connect page: passes on the data the calendar loads, repeats its read request
  extract.js        finds the bookings in that data, whatever its layout
  content.js        collects them for the open Connect tab
  background.js     keeps today's list, refreshes it, sends it to the salon software
  popup.*           the list;  options.*  settings
receiver/           PHP endpoint + MySQL table for the salon software
tests/              extract.test.mjs (layouts), e2e.mjs + mock-connect/ (pretend Connect site)
```

Tests: `node tests/extract.test.mjs`, and for the full run see the top of `tests/e2e.mjs`.
