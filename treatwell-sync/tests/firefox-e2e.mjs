/*
 * The Firefox version, end to end: Firefox (headless) with build/firefox installed as a temporary
 * add-on, the pretend Connect calendar and the PHP receiver with MySQL. Same setup as e2e.mjs, plus:
 *
 *   python3 tools/build.py
 *   FIREFOX_BIN=/path/to/firefox GECKODRIVER=/path/to/geckodriver KEY=… node tests/firefox-e2e.mjs
 *   (needs: npm install selenium-webdriver; start the pretend Connect site fresh)
 */
import { Builder, By } from 'selenium-webdriver';
import firefox from 'selenium-webdriver/firefox.js';
import { cpSync, mkdtempSync, readFileSync, writeFileSync, readdirSync, existsSync } from 'node:fs';
import { execFileSync } from 'node:child_process';
import { tmpdir } from 'node:os';
import path from 'node:path';

const KEY = process.env.KEY;
const RECEIVER = 'http://127.0.0.1:8301/bookings.php';
const CONTROL = 'http://127.0.0.1:8444';
const ID = 'salon-bookings-sync@deea0210.github.io';
const UUID = '6a1b2c3d-1111-4222-8333-944455556666'; // fixed so the test knows the extension's pages' address
const BASE = `moz-extension://${UUID}`;
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

// Test copy of the Firefox build that may also reach the local receiver without the "Allow" prompt.
const work = mkdtempSync(path.join(tmpdir(), 'tw-ff-'));
cpSync(path.resolve('build/firefox'), path.join(work, 'ext'), { recursive: true });
const manifestPath = path.join(work, 'ext', 'manifest.json');
const manifest = JSON.parse(readFileSync(manifestPath, 'utf8'));
manifest.host_permissions.push('http://127.0.0.1/*');
writeFileSync(manifestPath, JSON.stringify(manifest, null, 2));
execFileSync('python3', ['-c', `import shutil; shutil.make_archive(${JSON.stringify(path.join(work, 'ext'))}, 'zip', ${JSON.stringify(path.join(work, 'ext'))})`]);
const downloads = path.join(work, 'downloads');

const options = new firefox.Options()
    .setBinary(process.env.FIREFOX_BIN)
    .addArguments('-headless')
    .setPreference('network.dns.localDomains', 'connect.treatwell.co.uk') // the pretend Connect runs here
    .setPreference('network.proxy.type', 0)
    .setPreference('extensions.webextensions.uuids', JSON.stringify({ [ID]: UUID }))
    .setPreference('browser.download.folderList', 2)
    .setPreference('browser.download.dir', downloads)
    .setPreference('browser.download.useDownloadDir', true)
    .setPreference('browser.download.always_ask_before_handling_new_types', false)
    .setPreference('browser.helperApps.neverAsk.saveToDisk', 'text/csv,application/json');
options.setAcceptInsecureCerts(true);
const driver = await new Builder().forBrowser('firefox').setFirefoxOptions(options)
    // --allow-system-access: browser-level access, used only to open the add-on's pages in tabs
    .setFirefoxService(new firefox.ServiceBuilder(process.env.GECKODRIVER).addArguments('--allow-system-access')).build();

const rows = () => driver.executeScript("return [...document.querySelectorAll('.booking')].map(e => e.innerText.replace(/\\s+/g, ' ').trim())");
const text = (css) => driver.executeScript(`const e = document.querySelector(${JSON.stringify(css)}); return e ? e.textContent : ''`);
const badge = () => driver.executeAsyncScript('const done = arguments[arguments.length - 1]; browser.action.getBadgeText({}).then(done)');
async function waitFor(fn, ms = 15000) {
    const end = Date.now() + ms;
    while (Date.now() < end) {
        if (await fn()) return true;
        await sleep(250);
    }
    return false;
}
async function loaded(page) {
    await waitFor(async () => (await driver.getCurrentUrl()).startsWith(`${BASE}/${page.split('?')[0]}`)
        && (await driver.executeScript('return document.readyState')) === 'complete');
    await sleep(300);
}
/** Opens another of the add-on's pages in the current tab (from one of its pages). */
async function openPage(page) {
    await driver.executeScript(`location.href = ${JSON.stringify(`${BASE}/${page}`)}`);
    await waitFor(async () => (await driver.getCurrentUrl()).startsWith(`${BASE}/${page.split('?')[0]}`)
        && (await driver.executeScript('return document.readyState')) === 'complete');
    await sleep(300);
}
/** Opens one of the add-on's pages in a new tab, like clicking it in the toolbar (the driver can't navigate there). */
async function newTab(page) {
    const before = await driver.getAllWindowHandles();
    await driver.setContext(firefox.Context.CHROME);
    await driver.executeScript('gBrowser.selectedTab = gBrowser.addTab(arguments[0], '
        + '{ triggeringPrincipal: Services.scriptSecurityManager.getSystemPrincipal() })', `${BASE}/${page}`);
    await driver.setContext(firefox.Context.CONTENT);
    let handle;
    await waitFor(async () => (handle = (await driver.getAllWindowHandles()).find(h => !before.includes(h))));
    await driver.switchTo().window(handle);
    await loaded(page);
    return handle;
}
async function clickRefresh() {
    await driver.findElement(By.id('refresh')).click();
    await sleep(300);
    await waitFor(() => driver.executeScript("return !document.getElementById('refresh').disabled"), 25000);
    await sleep(800);
}

try {
    await driver.installAddon(path.join(work, 'ext.zip'), true);
    check('Firefox installs the add-on', true);

    // 1) Connect calendar
    await driver.get('https://connect.treatwell.co.uk/calendar');
    await waitFor(async () => (await text('#list')).includes('Skin Fade'));
    const connectTab = await driver.getWindowHandle();
    check('Connect calendar still works with the add-on installed', (await text('#list')).includes('Skin Fade'));
    await sleep(1500);

    // 2) The list
    const popupTab = await newTab('popup.html');
    await waitFor(async () => (await rows()).length > 0);
    let r = await rows();
    check('Happening: 6 bookings, the no-show left out', r.length === 6 && !r.some(x => x.includes('Nora West')), r);
    check('client, phone, service, stylist and price are read', r.some(x => x.includes('John Smith') && x.includes('+44 7700 900123')
        && x.includes('Skin Fade') && x.includes('S.Renato') && x.includes('£15.75')), r);
    check('completed booking marked Done', r.some(x => x.includes('Paul Kim') && /Done/i.test(x)), r);
    check('status line says it is up to date', /Updated \d\d:\d\d/.test(await text('#status')), await text('#status'));
    check('toolbar badge shows 6', (await badge()) === '6', await badge());

    // 3) Settings: salon software
    const settingsTab = await newTab('options.html');
    await driver.findElement(By.name('apiUrl')).sendKeys(RECEIVER);
    await driver.findElement(By.name('apiKey')).sendKeys(KEY);
    await driver.findElement(By.id('test')).click();
    await waitFor(async () => !/Testing|^$/.test(await text('#status')));
    check('Test connection reaches the receiver', (await text('#status')).startsWith('Connected'), await text('#status'));
    await driver.findElement(By.css('button[type=submit]')).click();
    await waitFor(async () => /Saved/.test(await text('#status')));
    check('settings saved', /Saved/.test(await text('#status')), await text('#status'));
    await sleep(2000);
    let stored = db(`SELECT treatwell_id, status FROM treatwell_bookings WHERE booking_date='${TODAY}' ORDER BY treatwell_id`);
    check('bookings saved in the salon database with their status', stored.split('\n').length === 7 && stored.includes('505\tNo-show'), stored);

    // 4) Changes in Treatwell + refresh
    await ctl('/status?id=502&code=CC');
    await ctl('/add');
    await driver.switchTo().window(popupTab);
    await clickRefresh();
    r = await rows();
    check('refresh picks up a new booking and drops a cancelled one', r.some(x => x.includes('Lucy Hall') && /Unconfirmed/i.test(x))
        && !r.some(x => x.includes('Ali K')), r);
    stored = db(`SELECT removed FROM treatwell_bookings WHERE treatwell_id = 502`);
    check('cancelled booking marked removed in the database', stored === '1', stored);

    // 5) Leave out a stylist
    await driver.switchTo().window(settingsTab);
    await driver.executeScript('location.reload()');
    await sleep(500);
    await waitFor(async () => (await driver.findElements(By.css('#staff-seen .chip'))).length > 0);
    await driver.findElement(By.xpath("//div[@id='staff-seen']/button[text()='Zane']")).click();
    check('clicking a staff name adds it', (await driver.findElement(By.name('excludeStaff')).getAttribute('value')) === 'Zane');
    await driver.findElement(By.css('button[type=submit]')).click();
    await waitFor(async () => /Saved/.test(await text('#status')));
    await sleep(2500);
    await driver.switchTo().window(popupTab);
    r = await rows();
    check('their bookings are left out', r.length > 0 && !r.some(x => x.includes('Valentina')), r);
    await driver.switchTo().window(settingsTab);
    await driver.findElement(By.name('excludeStaff')).clear();
    await driver.findElement(By.css('button[type=submit]')).click();
    await waitFor(async () => /Saved/.test(await text('#status')));
    await sleep(3000);
    await driver.switchTo().window(popupTab);
    r = await rows();
    check('and come back when the box is emptied', r.filter(x => x.includes('Valentina')).length === 2, r);

    // 6) Saving files: from the popup, Firefox opens the full-page list, which saves the file
    let listTab = popupTab;
    for (const [button, pattern] of [['csv', /^treatwell-bookings-.*\.csv$/], ['sample', /^treatwell-setup-.*\.json$/]]) {
        await driver.switchTo().window(listTab);
        await driver.findElement(By.id(button)).click();
        const saved = await waitFor(() => existsSync(downloads) && readdirSync(downloads).some(f => pattern.test(f)), 15000);
        check(`${button === 'csv' ? 'Download CSV' : 'Save a setup file'} saves the file`, saved, existsSync(downloads) ? readdirSync(downloads) : []);
        check('the full-page list opened to save it', (await driver.getAllWindowHandles()).length >= 3);
        const handles = await driver.getAllWindowHandles();
        if (!handles.includes(listTab)) { // the popup closed itself, as it does in the toolbar
            listTab = handles[handles.length - 1];
            await driver.switchTo().window(listTab);
            await openPage('popup.html');
        }
    }
    const files = readdirSync(downloads);
    const csv = readFileSync(path.join(downloads, files.find(f => f.endsWith('.csv'))), 'utf8');
    check('CSV has the happening bookings', csv.includes('Lucy Hall') && !csv.includes('Nora West'), csv.slice(0, 200));
    const sample = readFileSync(path.join(downloads, files.find(f => f.endsWith('.json'))), 'utf8');
    check('setup file has no names, phones or emails', sample.includes('"bookingsSource"') && !/John|Smith|Lucy|Hall|7700|example\.com/.test(sample));

    // 7) Logged out
    await ctl('/logout');
    await driver.switchTo().window(listTab);
    await openPage('popup.html');
    await clickRefresh();
    check('logged-out Connect is reported', /logged out/.test(await text('#status')), await text('#status'));
    check('toolbar badge shows "!"', (await badge()) === '!');
    await ctl('/login');
    check('the Connect tab was left alone', (await driver.getAllWindowHandles()).includes(connectTab));
} catch (e) {
    check('unexpected error', false, String(e && e.stack || e));
} finally {
    await driver.quit();
}
console.log(failures ? `\n${failures} check(s) failed` : '\nAll checks passed');
process.exit(failures ? 1 : 0);
