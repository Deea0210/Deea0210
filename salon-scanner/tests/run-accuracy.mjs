/*
 * Accuracy test: runs the real recognizer in Chromium on generated photos and
 * compares the result with the known ticks.
 *
 *   python3 tools/make-test-photos.py ticket.jpg tests/photos 120
 *   python3 -m http.server 8099            (from the salon-scanner folder)
 *   node tests/run-accuracy.mjs            (needs: npm install playwright)
 */
import { chromium } from 'playwright';
import { readFileSync } from 'node:fs';

const base = process.env.BASE || 'http://127.0.0.1:8099';
const truth = JSON.parse(readFileSync(new URL('./photos/truth.json', import.meta.url), 'utf8'));
const browser = await chromium.launch();
const page = await browser.newPage();
await page.goto(`${base}/tests/harness.html`);

let found = 0, total = 0, wrong = 0, missed = 0, check = 0, boxes = 0;
const problems = [];
for (const [name, t] of Object.entries(truth)) {
    total++;
    const r = await page.evaluate((u) => window.analyze(u, 1600), `photos/${name}`);
    if (!r.found) {
        problems.push(`${name}: not found (${r.reason}), the app would ask to retake`);
        continue;
    }
    found++;
    for (const tick of r.ticks) {
        boxes++;
        const truly = t.ticked.includes(tick.code);
        if (tick.uncertain) check++;
        if (truly && !tick.ticked) { missed++; problems.push(`${name}: missed ${tick.code} (${tick.ratio})${tick.uncertain ? ', flagged to check' : ''}`); }
        if (!truly && tick.ticked) { wrong++; problems.push(`${name}: wrong tick ${tick.code} (${tick.ratio})`); }
    }
}
console.log(`Ticket found in ${found}/${total} photos · ${boxes} boxes read · wrong ticks ${wrong} · missed ticks ${missed} · flagged to check ${check}`);
if (problems.length) console.log(problems.join('\n'));
await browser.close();
process.exit(wrong > 0 ? 1 : 0);
