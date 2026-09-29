# Ticket Scanner

Point the salon phone (e.g. the Huawei P40) at a filled-in **barber ticket**. The app reads which services are
ticked, lets reception check them, and sends them to the salon software. No app store needed: it's a web app
that opens in the phone's browser and can be added to the home screen.

```
 phone (browser)                                     your AWS server
┌──────────────────────────┐   HTTPS POST (JSON)   ┌──────────────────────────────┐
│ scanner/  camera → ticks │ ────────────────────▶ │ receiver/api/tickets.php     │
│ review & fix → Send      │   Bearer scanner key  │  → MySQL: scanned_tickets    │
│ offline outbox (retries) │ ◀──────────────────── │  → photos on disk            │
└──────────────────────────┘      {"ok":true}      └──────────────────────────────┘
                                                      your salon software reads the
                                                      new rows (status = 'new')
```

## What it reads on the ticket

- **All 41 tick boxes** (Hair, Colour, Grooming, Perm, Brazilian/Botox, plus Senior (60+), NHS/Student and
  6th Free Cut). Box positions, service names and prices are in [`scanner/template.js`](scanner/template.js).
- **The handwritten boxes** (C, How many treatments, Tips) are cut out and shown next to a text field so
  reception can type the value. *How many treatments* is pre-filled with the number of ticked services.
- It lines the ticket up using the red boxes themselves, so the photo can be at an angle, upside down or taken
  with the phone held either way. The camera captures automatically once the ticket is held still.
- Faint ticks, or ticks drawn mostly outside a box, are highlighted **"Please check"** on the review screen.

**Tested accuracy** (the real recognizer running in Chromium on generated phone photos with random ticks in blue,
black or red pen, perspective, rotation, shadows, colour casts, blur and JPEG noise):

| Set | Photos | Ticket found | Wrong ticks | Missed ticks |
| --- | --- | --- | --- | --- |
| Normal photos | 120 | 120 | 0 | 0 |
| Hard photos (small, blurry, strong colour casts) | 120 | 117* | 0 | 0 (1 flagged to check) |

\* The other 3 had part of the ticket outside the frame; the app refuses those and asks for a retake rather than guessing.

## Set up on your AWS server

The camera only works on **HTTPS** pages, which your salon software already uses.

1. **Scanner page:** upload the `scanner/` folder so it opens at e.g. `https://your-salon-software/scanner/`.
   In `scanner/config.js`, set `apiUrl` to where the receiver will be (default `/scanner-api/tickets.php`).
2. **Receiver:** upload `receiver/api/` so it answers at `https://your-salon-software/scanner-api/tickets.php`,
   and put `receiver/config.php` in the folder **above** it (it holds no secrets, but doesn't need to be public).
3. **Database:** run [`receiver/schema.sql`](receiver/schema.sql) on your MySQL/MariaDB (e.g. RDS).
4. **Settings** (environment variables for PHP, see `receiver/config.php`):

   | Variable | Example |
   | --- | --- |
   | `SCANNER_API_KEY` | output of `php -r "echo bin2hex(random_bytes(32)), PHP_EOL;"` |
   | `SCANNER_DB_DSN` | `mysql:host=your-rds-endpoint;dbname=salon;charset=utf8mb4` |
   | `SCANNER_DB_USER`, `SCANNER_DB_PASS` | your database user |
   | `SCANNER_PHOTO_DIR` | `/var/data/ticket-photos` (**outside** the public web folder) |
   | `SCANNER_TIMEZONE` | `Europe/London` |
   | `SCANNER_ALLOWED_ORIGINS` | only if the scanner page is on a *different* domain |

5. Check it: `curl -H "Authorization: Bearer YOUR_KEY" "https://your-salon-software/scanner-api/tickets.php?ping=1"`
   should answer `{"ok":true,...}`.

## Set up the phone (once)

1. Open `https://your-salon-software/scanner/` in the phone's browser (Huawei Browser works; so do Firefox and Chrome).
2. The Settings screen opens: the address is pre-filled. Paste the **scanner key**, name the phone
   (e.g. *Reception P40*), tap **Test connection**, then **Save**.
3. Allow camera access, then use the browser menu → **Add to home screen**. It now opens like an app.

The key is stored only in that phone's browser. If a phone is lost, change `SCANNER_API_KEY` on the server
and enter the new key on the other phones.

## Using it

1. Put the ticket on the desk (a plain, darker surface helps) and point the phone at it so the **whole ticket** is visible.
2. Hold still for a moment: the boxes light up green and the photo is taken automatically (or tap the round button).
3. Check the list: switch services on or off, use **+ Add or remove a service** for anything missed, and type the
   C number and tips from the handwriting shown on screen.
4. Tap **Send to reception**. With no connection, the ticket stays on the phone and sends automatically later
   (the badge at the top shows how many are waiting).

## What your salon software receives

One `POST` per ticket (JSON). Services include **every** box with `ticked` true or false; `code` never changes,
so use it to map to your own service IDs.

```json
{
  "scanId": "3f1c2c9e-8b1a-4d5e-9c7a-2a1b3c4d5e6f",
  "template": "barber-a6-v1",
  "scannedAt": "2026-09-29T15:40:12+01:00",
  "device": "Reception P40",
  "services": [
    { "code": "skin-fade", "name": "Skin Fade", "section": "Hair", "price": 15.75,
      "ticked": true, "confidence": 0.97, "changedByStaff": false },
    { "code": "senior", "name": "Senior (60+)", "section": "Discounts", "price": null,
      "ticked": true, "confidence": 1, "changedByStaff": false }
  ],
  "fields": { "c": "123", "treatments": "1", "tips": "5.50" },
  "fieldImages": { "c": "data:image/jpeg;base64,…", "treatments": "…", "tips": "…" },
  "correctedByStaff": false,
  "totals": { "services": 1, "amount": 15.75, "currency": "GBP" },
  "photo": "data:image/jpeg;base64,…"
}
```

`receiver/api/tickets.php` checks the key, validates everything, ignores duplicates (same `scanId`), saves the
straightened ticket photo plus the handwriting crops, and writes:

- `scanned_tickets`: one row per ticket (C, treatments, tips, totals, photo path, `status = 'new'`).
- `scanned_ticket_services`: one row per **ticked** box (discounts have `price = NULL`).

Reception's view in your software can start from:

```sql
SELECT t.id, t.scanned_at, t.field_c, t.tips, t.services_total, GROUP_CONCAT(s.name SEPARATOR ', ') AS services
FROM scanned_tickets t JOIN scanned_ticket_services s ON s.ticket_id = t.id
WHERE t.status = 'new' GROUP BY t.id ORDER BY t.scanned_at;
-- when handled:  UPDATE scanned_tickets SET status = 'processed' WHERE id = ?;
```

If you'd rather receive the JSON in your own code, keep the same request format and reply `{"ok": true}`
(any non-2xx reply makes the phone keep the ticket and retry).

## Changing prices or the ticket design

- **Prices or names:** edit them in `scanner/template.js` (the `code` values must stay the same).
- **New ticket artwork:** export it as a 300-dpi JPEG and run `python3 tools/make-template.py ticket.jpg`
  (needs `opencv-python-headless`). Update the service list at the top of that script if services changed.
- **Re-test accuracy:** `python3 tools/make-test-photos.py ticket.jpg tests/photos 120`, serve this folder
  (`python3 -m http.server 8099`) and run `node tests/run-accuracy.mjs`.

## Files

```
scanner/            the phone app (static files: HTML, CSS, JS, no build step)
  recognizer.js     finds the ticket and reads ticks (no libraries)
  template.js       box positions, services and prices for this ticket
  app.js            camera, review screen, sending, offline outbox
  config.js         receiver address for this deployment
receiver/           PHP endpoint + MySQL schema for the salon software
tools/              template builder and test-photo generator
tests/              accuracy test harness
```
