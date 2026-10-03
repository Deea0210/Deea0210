/*
 * End-to-end test: Chromium with the extension installed, the pretend Connect calendar
 * (tests/mock-connect/server.py) and the PHP receiver with MySQL.
 *
 *   sudo python3 tests/mock-connect/server.py &      (https on 127.0.0.1:443, controls on :8444)
 *   TREATWELL_SYNC_KEY=… TREATWELL_DB_DSN=… php -S 127.0.0.1:8301 -t receiver/api &
 *   KEY=… node tests/e2e.mjs                          (from the treatwell-sync folder)
 */
import { chromium } from 'playwright';
import { cpSync, mkdtempSync, readFileSync, writeFileSync, mkdirSync } from 'node:fs';
import { execFileSync } from 'node:child_process';
import { tmpdir } from 'node:os';
import path from 'node:path';

const KEY = process.env.KEY;
const RECEIVER = 'http://127.0.0.1:8301/bookings.php';
const CONTROL = 'http://127.0.0.1:8444';
const SHOTS = process.env.SHOTS || '';
const sleep = (ms) => new Promise(r => setTimeout(r, ms));
const ctl = (p) => fetch(CONTROL + p).then(r => r.json());
const db = (sql) => execFileSync('mysql', ['-uroot', 'salon_test', '-N', '-e', sql]).toString().trim();
const pad = (n) => String(n).padStart(2, '0');
const d = new Date();
const TODAY = `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`;

let failures = 0;
function check(name, ok, detail) {
    console.log(`${ok ? '✓' : '✗'} ${name}${ok || detail === undefined ? '' : '  → ' + JSON.stringify(detail)}`);
    if (!ok) failures++;
}

// A copy of the extension that may also talk to the local receiver without Chrome's "Allow" prompt.
const work = mkdtempSync(path.join(tmpdir(), 'tw-ext-'));
cpSync(path.resolve('extension'), path.join(work, 'ext'), { recursive: true });
const manifestPath = path.join(work, 'ext', 'manifest.json');
const manifest = JSON.parse(readFileSync(manifestPath, 'utf8'));
manifest.host_permissions.push('http://127.0.0.1/*');
writeFileSync(manifestPath, JSON.stringify(manifest, null, 2));

const context = await chromium.launchPersistentContext(path.join(work, 'profile'), {
    channel: 'chromium',
    headless: true,
    viewport: { width: 1000, height: 760 },
    acceptDownloads: true,
    args: [
        `--disable-extensions-except=${path.join(work, 'ext')}`,
        `--load-extension=${path.join(work, 'ext')}`,
        '--host-resolver-rules=MAP connect.treatwell.co.uk 127.0.0.1',
        '--ignore-certificate-errors',
        '--no-proxy-server',
    ],
});
const errors = [];
let [worker] = context.serviceWorkers();
if (!worker) worker = await context.waitForEvent('serviceworker');
const extId = worker.url().split('/')[2];

async function shot(page, name) {
    if (!SHOTS) return;
    mkdirSync(SHOTS, { recursive: true });
    await page.screenshot({ path: path.join(SHOTS, name), fullPage: true });
}
const rowsOf = (page) => page.$$eval('.booking', els => els.map(e => e.innerText.replace(/\s+/g, ' ').trim()));

// 1) Reception opens the Connect calendar as usual
const connect = await context.newPage();
connect.on('pageerror', e => errors.push('connect page: ' + e.message));
await connect.goto('https://connect.treatwell.co.uk/calendar');
await connect.waitForSelector('#list li');
check('Connect calendar still works with the extension installed', (await connect.textContent('#list')).includes('Skin Fade'));
await sleep(1200);

// 2) The popup lists today's bookings
const popup = await context.newPage();
popup.on('pageerror', e => errors.push('popup: ' + e.message));
await popup.setViewportSize({ width: 420, height: 640 });
await popup.goto(`chrome-extension://${extId}/popup.html`);
await popup.waitForSelector('.booking', { timeout: 10000 });
let rows = await rowsOf(popup);
check('3 bookings for today, in time order', rows.length === 3 && rows[0].startsWith('09:00') && rows[2].startsWith('14:00'), rows);
check('stylist names come from the employees list', rows.some(r => r.includes('Renato')) && rows.some(r => r.includes('Mohammad A')), rows);
check('client, service and price are read', rows.some(r => r.includes('John Smith') && r.includes('Skin Fade') && r.includes('£15.75')), rows);
check('reviews, lunch breaks and working hours are not bookings', !rows.some(r => /Happy Client|Lunch|18:00/.test(r)), rows);
check('tomorrow\'s booking is not in today\'s list', !rows.some(r => r.includes('Sam Lee')), rows);
check('status line says it is up to date', /Updated \d\d:\d\d/.test(await popup.textContent('#status')), await popup.textContent('#status'));
await shot(popup, '1-popup.png');

// 3) Settings: send to the salon software
const options = await context.newPage();
options.on('pageerror', e => errors.push('options: ' + e.message));
await options.goto(`chrome-extension://${extId}/options.html`);
await options.fill('input[name=apiUrl]', RECEIVER);
await options.fill('input[name=apiKey]', KEY);
await options.click('#test');
await options.waitForFunction(() => !/Testing/.test(document.getElementById('status').textContent));
check('Test connection reaches the receiver', (await options.textContent('#status')).startsWith('Connected'), await options.textContent('#status'));
await options.click('button[type=submit]');
await options.waitForFunction(() => /Saved/.test(document.getElementById('status').textContent));
await shot(options, '3-settings.png');
await options.close();
await sleep(1500);
let stored = db(`SELECT treatwell_id, TIME_FORMAT(start_time,'%H:%i'), staff_name, customer_name, cancelled, removed FROM treatwell_bookings WHERE booking_date='${TODAY}' ORDER BY start_time`);
check('the 3 bookings are saved in the salon database', stored.split('\n').length === 3 && stored.includes('John Smith'), stored);

// 4) Things change in Treatwell; reception has moved the calendar to tomorrow
await ctl('/cancel?id=502');
await ctl('/delete?id=503');
await ctl('/add');
await connect.click('#next');
await connect.waitForFunction(() => document.getElementById('list').textContent.includes('Wash'));
await sleep(800);
await worker.evaluate(() => refreshNow()); // what the 5-minute timer does
await sleep(1500);
rows = await rowsOf(popup);
check('refresh picks up a new booking', rows.some(r => r.includes('Lucy Hall') && r.includes('16:30')), rows);
check('a cancelled booking is marked cancelled', rows.some(r => r.includes('Ali K') && /Cancelled/i.test(r)), rows);
check('a booking removed in Treatwell disappears', !rows.some(r => r.includes('Emma Jones')), rows);
check('still only today\'s bookings although the calendar shows tomorrow', !rows.some(r => r.includes('Sam Lee')), rows);
stored = db(`SELECT treatwell_id, cancelled, removed FROM treatwell_bookings WHERE booking_date='${TODAY}' ORDER BY treatwell_id`);
check('database: 502 cancelled, 503 removed, 504 added', stored === '501\t0\t0\n502\t1\t0\n503\t0\t1\n504\t0\t0', stored);
await shot(popup, '2-popup-after-changes.png');

const log = await ctl('/log');
const replays = log.filter(r => r.path.includes(`date-from=${TODAY}`));
check('automatic refresh asked Treatwell for today, with the login token and cookie',
    replays.length >= 2 && replays.every(r => r.auth && r.cookie), replays.slice(-2));
check('the extension never sent anything but reads to Treatwell', log.every(r => r.method === 'GET'), log.filter(r => r.method !== 'GET'));

// 5) Logged out of Connect
await ctl('/logout');
await popup.click('#refresh');
await popup.waitForFunction(() => /logged out/.test(document.getElementById('status').textContent), null, { timeout: 25000 });
check('logged-out Connect is reported', true);
check('toolbar badge shows "!"', (await worker.evaluate(() => chrome.action.getBadgeText({}))) === '!');
await ctl('/login');

// 6) Setup file without personal details
const [download] = await Promise.all([popup.waitForEvent('download'), popup.click('#sample')]);
const sample = readFileSync(await download.path(), 'utf8');
check('setup file has the shape of the data', sample.includes('"bookingsSource"') && sample.includes('/api/v1/venue/42/calendar'));
check('setup file has no names, phones or token', !/John|Smith|Lucy|Hall|07700|07123|Bearer|tok-/.test(sample),
    (sample.match(/John|Smith|Lucy|Hall|07700|07123|Bearer|tok-/g) || []));

// 7) Connect closed
await connect.close();
await worker.evaluate(() => refreshNow());
await popup.reload();
await popup.waitForSelector('#status');
check('closed Connect tab is reported', /Open Treatwell Connect/.test(await popup.textContent('#status')), await popup.textContent('#status'));
check('the list stays available while Connect is closed', (await rowsOf(popup)).length === 3);

// Full-page view (for a reception screen)
const page = await context.newPage();
await page.goto(`chrome-extension://${extId}/popup.html?page=1`);
await page.waitForSelector('.booking');
await shot(page, '4-page-view.png');
check('alarm set for automatic refresh', ((await worker.evaluate(() => chrome.alarms.get('refresh'))) || {}).periodInMinutes === 5);

check('no script errors', errors.length === 0, errors);
await context.close();
console.log(failures ? `\n${failures} check(s) failed` : '\nAll checks passed');
process.exit(failures ? 1 : 0);
