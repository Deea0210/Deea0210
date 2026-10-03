# Salon Bookings Sync (Treatwell Connect)

A browser extension for the reception computer, for **Chrome** (also Edge, Brave, Opera) and **Firefox**. While
**Treatwell Connect** is open and logged in, it reads
**today's bookings** from the Connect calendar, keeps them up to date on its own, and can send them to the salon
software.

```
 Browser on the reception PC                                        your AWS server
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
from Treatwell's servers in your logged-in browser:

- It **listens** to the data the calendar page receives and picks out the bookings: time, client, phone,
  service, stylist, price, status and notes. Stylist and service names are matched from the other lists the page
  loads. Rotas, lunch breaks, blocked time, reviews and the menu are ignored.
- Every few minutes it **repeats the calendar's own request for today** (same address, same login), so the
  list stays current even if nobody touches Connect. It only ever *reads*: it never sends changes, never sees
  or stores your Treatwell password, and makes one small request per refresh.
- If Connect is logged out or closed, the list keeps the last bookings and says what to do.

> **Fitted to the real Connect.** The reading was fitted to the layout of the real Connect calendar (from a setup
> file with no personal details): `calendar.json` appointments and packages, with Treatwell's status codes
> CR (unconfirmed), CN (confirmed), CP (completed), NS (no-show) and CC (cancelled). The pretend Connect site used
> by the tests copies that layout. If Treatwell changes something, click
> **"Bookings look wrong? Save a setup file"** in the extension and send the file to your developer. It contains the
> layout of the data with **no client names, phone numbers, emails or login tokens**, which is enough to fit the
> reading exactly.

This reads your own salon's data in your own logged-in browser. Treatwell's terms for partners may limit
automated access, so if in doubt check with your Treatwell account manager. The official alternative is one of
the salon systems Treatwell integrates with (Connect → Settings → Online Bookings → Integrations).

## Install (once, on the reception computer)

There are two versions of the same extension, made from the same code:
**[salon-bookings-sync-chrome.zip](salon-bookings-sync-chrome.zip)** and
**[salon-bookings-sync-firefox.zip](salon-bookings-sync-firefox.zip)**.

### Chrome, Edge, Brave or Opera (Windows or Mac)

1. Download **salon-bookings-sync-chrome.zip** and unzip it (Windows: right-click → **Extract All…**; Mac:
   double-click) into a folder that will stay and is **not in OneDrive or iCloud Drive**, e.g. `C:\SalonBookingsSync`
   or your Mac's home folder. The browser loads it from there every time, and synced folders can turn files into
   online-only copies it can't read.
2. Open `chrome://extensions` (Edge: `edge://extensions`), switch on **Developer mode** and click **Load unpacked**.
   Choose the folder that has `manifest.json` directly inside it.
3. Click the puzzle icon in the toolbar and **pin** *Salon Bookings Sync*.
4. **Reload the Treatwell Connect tab** (F5) and open its **Calendar** once.
5. Recommended in Chrome: **Settings → Performance → Memory saver → Always keep these sites active → Add**
   `connect.treatwell.co.uk`, so Chrome never puts the Connect tab to sleep.

Chrome may show a notice about developer-mode extensions when it starts; that's normal for extensions installed
this way. To update later: replace the folder's contents and click ↻ on the extension in `chrome://extensions`.

### Firefox 140 or newer (Windows or Mac)

Firefox only keeps add-ons that Mozilla has signed. Signing your own add-on is free, private and takes minutes:

1. Download **salon-bookings-sync-firefox.zip** (leave it zipped).
2. Go to **https://addons.mozilla.org/developers/** and log in (or create a free Mozilla account).
3. Click **Submit a New Add-on**, choose **On your own** (it stays private: not listed on Mozilla's site), and
   upload the zip. Mozilla's automatic check should pass (it was run on this zip: 0 errors, 0 warnings). If asked
   whether you need to submit source code, answer **No**: the code is plain, readable JavaScript.
4. When it's signed (usually within minutes; Mozilla emails you), download the **.xpi** file from the add-on's
   page in the Developer Hub.
5. Open the `.xpi` in Firefox (drag it onto a Firefox window) and click **Add**. Firefox lists what the add-on can
   do, including that it can send booking details (clients' names, phones, emails) to your salon software; that's
   the optional sending below and only happens if you set it up.
6. Click the puzzle icon → gear next to *Salon Bookings Sync* → **Pin to Toolbar**, then reload the Treatwell
   Connect tab and open its **Calendar** once.

Just want to try it first? `about:debugging#/runtime/this-firefox` → **Load Temporary Add-on…** → choose the zip.
It stays until Firefox is closed.

To update: in the Developer Hub open *Salon Bookings Sync* → **Upload New Version**, upload the new zip, then open
the new `.xpi`. Your settings are kept. If the list stays empty in Firefox: `about:addons` → *Salon Bookings Sync* →
**Permissions** → make sure access to `connect.treatwell.co.uk` is on.

In Firefox, **Download CSV** and **Save a setup file** open the full-page list in a tab and save from there
(Firefox can lose files saved straight from the toolbar popup).

## Using it

- **Click the icon:** today's bookings in time order, with client, phone, service, stylist, price and notes.
  Three tabs:
  - **Happening**: the bookings taking place today (Treatwell's confirmed, unconfirmed and completed ones).
    No-shows and cancelled bookings are left out, also from the count and the total. Completed ones are marked
    *Done*, and online bookings not yet accepted are marked *Unconfirmed*.
  - **Still to come**: the ones in progress or later today that aren't done yet.
  - **No-shows**: only appears when there are some.

  Tap a stylist's name to see only theirs. The number on the icon is today's bookings that are happening; a red
  **!** means Connect is logged out or the salon software couldn't be reached.
- **Open as a page** (arrow icon) for a full-window list on a reception screen. It updates by itself.
- **Copy list** or **Download CSV** (opens in Excel): exactly what's on screen (tab and stylist).
- **Settings** (gear icon): how often to refresh (default 5 minutes), the salon software connection, and
  **Leave out bookings for these staff**: type names (or click them under *Staff in Treatwell*) and their bookings
  are no longer kept, listed, counted or sent to the salon software. Capitals, spaces and dots don't matter
  ("s tsegi" = "S.Tsegi"); a name that matches nobody in Treatwell gets a "did you mean" hint. Take a name off the
  list and their bookings come back at the next refresh (straight away when you click Save).

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
      "status": "Confirmed", "statusCode": "CN", "cancelled": false, "noShow": false, "completed": false,
      "notes": "", "channel": "SUPPLIER" }
  ]
}
```

The list sent has every booking of the day with its `status` (Confirmed, Unconfirmed, Completed, No-show,
Cancelled), so the salon software can choose. `bookings.php` saves each booking in `treatwell_bookings` (one row per
Treatwell booking, updated in place, with `no_show` and `cancelled` flags).
When `complete` is true the list is the whole day as the Connect calendar shows it, so bookings of that day that
are no longer in it are marked `removed = 1`.

```sql
SELECT TIME_FORMAT(start_time, '%H:%i') AS time, customer_name, service, staff_name, price
FROM treatwell_bookings
WHERE booking_date = CURDATE() AND removed = 0 AND cancelled = 0 AND no_show = 0   -- happening today
ORDER BY start_time;
```

## Privacy

Client details stay on the reception computer (the browser's extension storage, last 7 days only) and, if set up,
your own salon database. Nothing is sent anywhere else.

## Files

```
extension/          the extension's code, shared by both versions (manifest.json here is the Chrome one)
  page-hook.js      inside the Connect page: passes on the data the calendar loads, repeats its read request
  extract.js        finds the bookings in that data, whatever its layout
  content.js        collects them for the open Connect tab
  background.js     keeps today's list, refreshes it, sends it to the salon software
  popup.*           the list;  options.*  settings
tools/build.py      makes salon-bookings-sync-chrome.zip and salon-bookings-sync-firefox.zip from extension/
receiver/           PHP endpoint + MySQL table for the salon software
tests/              extract.test.mjs (layouts), e2e.mjs (Chrome) and firefox-e2e.mjs (Firefox)
                    + mock-connect/ (pretend Connect site with the real data layout)
```

After changing anything in `extension/`, run `python3 tools/build.py` and bump `version` in
`extension/manifest.json` (Firefox needs a higher version for each signed update).
Tests: `node tests/extract.test.mjs`; for the full runs see the top of `tests/e2e.mjs` and `tests/firefox-e2e.mjs`.
