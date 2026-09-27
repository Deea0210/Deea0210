#!/usr/bin/env node
/*
 * Exports the business card, ads and flyer to print-ready PDFs and PNGs.
 *
 *   npm install playwright   (once, in this folder or globally)
 *   npx playwright install chromium
 *   node marketing/render.mjs
 *
 * Output goes to marketing/export/. The website preview image is also copied to
 * booking-app/assets/img/og-image.png.
 */
import { mkdir, copyFile } from 'node:fs/promises';
import { dirname, join } from 'node:path';
import { fileURLToPath, pathToFileURL } from 'node:url';

let chromium;
try {
    ({ chromium } = await import('playwright'));
} catch {
    console.error('Playwright is not installed. Run: npm install playwright && npx playwright install chromium');
    process.exit(1);
}

const here = dirname(fileURLToPath(import.meta.url));
const out = join(here, 'export');
await mkdir(out, { recursive: true });

const browser = await chromium.launch();
const pageUrl = (file, query = '') => pathToFileURL(join(here, file)).href + query;

async function open(file, query, options = {}) {
    const page = await browser.newPage(options);
    await page.goto(pageUrl(file, query));
    await page.waitForSelector('html[data-ready]');
    await page.evaluate(() => document.fonts.ready);
    return page;
}

// Business cards: PDF with bleed (EU 85×55 mm and US 3.5×2 in) + PNG previews.
for (const [size, query] of [['eu', ''], ['us', '?size=us']]) {
    const page = await open('business-card/business-card.html', query, { deviceScaleFactor: 4 });
    await page.evaluate(() => document.documentElement.setAttribute('data-exporting', ''));
    await page.pdf({ path: join(out, `business-card-${size}.pdf`), preferCSSPageSize: true, printBackground: true });
    if (size === 'eu') {
        for (const side of ['front', 'back']) {
            await page.locator(`[data-export="${side}"]`).screenshot({ path: join(out, `business-card-${side}.png`), omitBackground: true });
        }
    }
    await page.close();
}

// Flyer: PDF with bleed + PNG preview.
{
    const page = await open('ads/flyer.html', '', { deviceScaleFactor: 2 });
    await page.pdf({ path: join(out, 'flyer-a5.pdf'), preferCSSPageSize: true, printBackground: true });
    await page.locator('[data-export="flyer-a5"]').screenshot({ path: join(out, 'flyer-a5.png') });
    await page.close();
}

// Social ads at their exact pixel sizes.
{
    const page = await open('ads/ads.html', '', { viewport: { width: 1400, height: 1000 }, deviceScaleFactor: 1 });
    await page.evaluate(() => document.documentElement.setAttribute('data-exporting', ''));
    for (const board of await page.locator('[data-export]').all()) {
        const name = await board.getAttribute('data-export');
        await board.screenshot({ path: join(out, `${name}.png`) });
    }
    await page.close();
}

await browser.close();
await copyFile(join(out, 'og-image.png'), join(here, '..', 'booking-app', 'assets', 'img', 'og-image.png'));
console.log(`Done. Files are in ${out}`);
