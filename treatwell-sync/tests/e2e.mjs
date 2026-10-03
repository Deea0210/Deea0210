/*
 * End-to-end test: Chromium with the extension installed, the pretend Connect calendar
 * (tests/mock-connect/server.py, same data layout as the real Connect) and the PHP receiver with MySQL.
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
const LUNCHTIME = new Date(`${TODAY}T12:10:00`); // the popup's clock, for "Still to come"

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
const viewTabs = (page) => page.$$eval('.view', els => els.map(e => e.textContent.trim()));
async function pickView(page, label) {
    await page.click(`.view:has-text("${label}")`);
    await page.waitForTimeout(100);
    return rowsOf(page);
}

// 1) Reception opens the Connect calendar as usual
const connect = await context.newPage();
connect.on('pageerror', e => errors.push('connect page: ' + e.message));
await connect.goto('https://connect.treatwell.co.uk/calendar');
await connect.waitForSelector('#list li');
check('Connect calendar still works with the extension installed', (await connect.textContent('#list')).includes('Skin Fade'));
await sleep(1200);

// 2) The popup lists the bookings happening today
const popup = await context.newPage();
popup.on('pageerror', e => errors.push('popup: ' + e.message));
await popup.clock.setFixedTime(LUNCHTIME);
await popup.setViewportSize({ width: 420, height: 720 });
await popup.goto(`chrome-extension://${extId}/popup.html`);
await popup.waitForSelector('.booking', { timeout: 10000 });
let rows = await pickView(popup, 'Happening');
check('Happening: 6 bookings in time order (the no-show is left out)',
    rows.length === 6 && rows[0].startsWith('09:00') && !rows.some(r => r.includes('Nora West')), rows);
check('stylist, client, phone, service and price are read', rows.some(r => r.includes('John Smith') && r.includes('+44 7700 900123')
    && r.includes('Skin Fade') && r.includes('S.Renato') && r.includes('£15.75')), rows);
check('a completed booking is marked Done', rows.some(r => r.includes('Paul Kim') && /Done/i.test(r)), rows);
check('a package (two services) shows once per service', rows.filter(r => r.includes('Valentina')).length === 2, rows);
check('activity feed, waiting list and lunch blocks are not bookings', !rows.some(r => /Booked Today|Waiting Client|Lunch/.test(r)), rows);
check('tomorrow\'s booking is not in today\'s list', !rows.some(r => r.includes('Sam Lee')), rows);
check('tabs show the counts', JSON.stringify(await viewTabs(popup)) === JSON.stringify(['Happening 6', 'Still to come 3', 'No-shows 1']), await viewTabs(popup));
check('status line says it is up to date', /Updated \d\d:\d\d/.test(await popup.textContent('#status')), await popup.textContent('#status'));
await shot(popup, '1-happening.png');

rows = await pickView(popup, 'Still to come');
check('Still to come at 12:10: the one in progress and the later ones, not finished or done ones',
    JSON.stringify(rows.map(r => r.slice(0, 5))) === JSON.stringify(['12:00', '12:35', '14:00']), rows);
await shot(popup, '2-still-to-come.png');
rows = await pickView(popup, 'No-shows');
check('No-shows tab lists the no-show', rows.length === 1 && rows[0].includes('Nora West') && /No-show/i.test(rows[0]), rows);
await pickView(popup, 'Happening');

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
await options.close();
await sleep(1500);
let stored = db(`SELECT treatwell_id, status, no_show, removed FROM treatwell_bookings WHERE booking_date='${TODAY}' ORDER BY treatwell_id`);
check('all 7 bookings saved with their status', stored === [
    '501\tConfirmed\t0\t0', '502\tConfirmed\t0\t0', '503\tConfirmed\t0\t0', '505\tNo-show\t1\t0',
    '506\tCompleted\t0\t0', '507\tConfirmed\t0\t0', '508\tConfirmed\t0\t0'].join('\n'), stored);

// 4) Things change in Treatwell; reception has moved the calendar to tomorrow
await ctl('/status?id=502&code=CC');   // cancelled: Connect's calendar no longer lists it
await ctl('/delete?id=503');
await ctl('/status?id=501&code=NS');   // marked as no-show
await ctl('/add');                     // new online booking, not confirmed yet
await connect.click('#next');
await connect.waitForFunction(() => document.getElementById('list').textContent.includes('Wash'));
await sleep(800);
await worker.evaluate(() => refreshNow()); // what the 5-minute timer does
await sleep(1500);
rows = await rowsOf(popup);
check('refresh picks up the new booking, marked Unconfirmed', rows.some(r => r.includes('Lucy Hall') && /Unconfirmed/i.test(r)), rows);
check('cancelled and removed bookings disappear', !rows.some(r => /Ali K|Emma Jones/.test(r)), rows);
check('a booking marked no-show leaves Happening', !rows.some(r => r.includes('John Smith')), rows);
check('still only today\'s bookings although the calendar shows tomorrow', !rows.some(r => r.includes('Sam Lee')), rows);
rows = await pickView(popup, 'No-shows');
check('No-shows tab now has both no-shows', rows.length === 2, rows);
await pickView(popup, 'Happening');
stored = db(`SELECT treatwell_id, status, no_show, removed FROM treatwell_bookings WHERE booking_date='${TODAY}' ORDER BY treatwell_id`);
check('database: 501 no-show, 502 and 503 removed, 504 added', stored === [
    '501\tNo-show\t1\t0', '502\tConfirmed\t0\t1', '503\tConfirmed\t0\t1', '504\tUnconfirmed\t0\t0', '505\tNo-show\t1\t0',
    '506\tCompleted\t0\t0', '507\tConfirmed\t0\t0', '508\tConfirmed\t0\t0'].join('\n'), stored);
check('toolbar badge counts the bookings happening today', (await worker.evaluate(() => chrome.action.getBadgeText({}))) === '4');
await shot(popup, '3-after-changes.png');

const log = await ctl('/log');
const replays = log.filter(r => r.path.includes('calendar.json') && r.path.includes(`date-from=${TODAY}`));
check('automatic refresh asked Treatwell for today, with the login cookie and the page\'s headers',
    replays.length >= 2 && replays.every(r => r.header && r.cookie), replays.slice(-2));
check('the extension never sent anything but reads to Treatwell', log.every(r => r.method === 'GET'), log.filter(r => r.method !== 'GET'));

// 5) CSV = what's on screen
const [csvFile] = await Promise.all([popup.waitForEvent('download'), popup.click('#csv')]);
const csv = readFileSync(await csvFile.path(), 'utf8');
check('CSV has the happening bookings only', csv.split('\r\n').length === 5 && !/Nora West|John Smith/.test(csv) && csv.includes('Unconfirmed'),
    csv.split('\r\n').map(l => l.slice(0, 60)));

// 5b) Leave out a stylist's bookings
const settingsPage = await context.newPage();
settingsPage.on('pageerror', e => errors.push('options: ' + e.message));
await settingsPage.goto(`chrome-extension://${extId}/options.html`);
await settingsPage.waitForSelector('#staff-seen .chip');
const offered = await settingsPage.$$eval('#staff-seen .chip', els => els.map(e => e.textContent));
check('Settings offers the staff names seen in Treatwell', ['Mohammad A', 'S.Renato', 'Sofia', 'Zane'].every(n => offered.includes(n)), offered);
await settingsPage.click('#staff-seen .chip:has-text("Zane")');
check('clicking a name adds it to the box', (await settingsPage.inputValue('textarea[name=excludeStaff]')) === 'Zane');
await settingsPage.fill('textarea[name=excludeStaff]', 'zane\nrenato');
await settingsPage.dispatchEvent('textarea[name=excludeStaff]', 'input');
check('a name that matches nobody gets a hint', /"renato" isn't a staff name in Treatwell\. Did you mean S\.Renato\?/.test(await settingsPage.textContent('#staff-check')),
    await settingsPage.textContent('#staff-check'));
await settingsPage.fill('textarea[name=excludeStaff]', 'zane');
await settingsPage.click('button[type=submit]');
await settingsPage.waitForFunction(() => /Saved/.test(document.getElementById('status').textContent));
await shot(settingsPage, '5-settings-staff.png');
await sleep(2000);
rows = await rowsOf(popup);
check('their bookings are left out of the list', rows.length === 2 && !rows.some(r => r.includes('Valentina')), rows);
check('the popup says who is left out', /Leaving out bookings for zane/.test(await popup.textContent('#left-out')), await popup.textContent('#left-out'));
check('and out of the badge count', (await worker.evaluate(() => chrome.action.getBadgeText({}))) === '2');
stored = db(`SELECT treatwell_id, removed FROM treatwell_bookings WHERE treatwell_id IN (507, 508) ORDER BY treatwell_id`);
check('and out of the salon software (marked removed)', stored === '507\t1\n508\t1', stored);
await shot(popup, '6-staff-left-out.png');
await settingsPage.fill('textarea[name=excludeStaff]', '');
await settingsPage.click('button[type=submit]');
await settingsPage.waitForFunction(() => /Saved/.test(document.getElementById('status').textContent));
await settingsPage.close();
await sleep(2500);
rows = await rowsOf(popup);
check('emptying the box brings them back', rows.filter(r => r.includes('Valentina')).length === 2, rows);
stored = db(`SELECT treatwell_id, removed FROM treatwell_bookings WHERE treatwell_id IN (507, 508) ORDER BY treatwell_id`);
check('also in the salon software', stored === '507\t0\n508\t0', stored);

// 6) Logged out of Connect
await ctl('/logout');
await popup.click('#refresh');
await popup.waitForFunction(() => /logged out/.test(document.getElementById('status').textContent), null, { timeout: 25000 });
check('logged-out Connect is reported', true);
check('toolbar badge shows "!"', (await worker.evaluate(() => chrome.action.getBadgeText({}))) === '!');
await ctl('/login');

// 7) Setup file without personal details
const [download] = await Promise.all([popup.waitForEvent('download'), popup.click('#sample')]);
const sample = readFileSync(await download.path(), 'utf8');
check('setup file has the shape of the data', sample.includes('"bookingsSource"') && sample.includes('/api/venue/42/calendar.json'));
check('setup file has no names, phones or emails', !/John|Smith|Lucy|Hall|7700|7123|example\.com|Valentina/.test(sample),
    (sample.match(/John|Smith|Lucy|Hall|7700|7123|example\.com|Valentina/g) || []));

// 8) Connect closed
await connect.close();
await worker.evaluate(() => refreshNow());
await popup.reload();
await popup.waitForSelector('#status');
check('closed Connect tab is reported', /Open Treatwell Connect/.test(await popup.textContent('#status')), await popup.textContent('#status'));
check('the list stays available while Connect is closed', (await rowsOf(popup)).length === 4);

const page = await context.newPage();
await page.clock.setFixedTime(LUNCHTIME);
await page.goto(`chrome-extension://${extId}/popup.html?page=1`);
await page.waitForSelector('.booking');
await shot(page, '4-page-view.png');
check('alarm set for automatic refresh', ((await worker.evaluate(() => chrome.alarms.get('refresh'))) || {}).periodInMinutes === 5);

check('no script errors', errors.length === 0, errors);
await context.close();
console.log(failures ? `\n${failures} check(s) failed` : '\nAll checks passed');
process.exit(failures ? 1 : 0);
