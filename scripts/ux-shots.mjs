#!/usr/bin/env node
/**
 * UX slice screenshots (docs/ux-design-brief.md review loop): every page at 1366×768 and 1920×1080, light and dark (system preference),
 * signed in as a demo user. Needs `php artisan serve` and a local database seeded with DemoBusinessSeeder.
 *
 *   node scripts/ux-shots.mjs <slice> [--user admin@demo.local] [--base http://127.0.0.1:8765] name=/path [name=/path …]
 *
 * Writes storage/ux-screenshots/<slice>/<name>-<width>-<light|dark>.png and prints console errors per page.
 */
import { mkdirSync } from 'node:fs';
import { chromium } from 'playwright-core';

const args = process.argv.slice(2);
const option = (name, fallback) => {
    const index = args.indexOf(`--${name}`);
    return index === -1 ? fallback : args.splice(index, 2)[1];
};
const user = option('user', 'admin@demo.local');
const base = option('base', 'http://127.0.0.1:8765');
const password = option('password', process.env.ERP_ADMIN_PASSWORD ?? 'ChangeMe123!');
const [slice, ...pages] = args;
if (!slice || pages.length === 0) {
    console.error('usage: ux-shots.mjs <slice> name=/path …');
    process.exit(1);
}
const out = `storage/ux-screenshots/${slice}`;
mkdirSync(out, { recursive: true });

const browser = await chromium.launch({ executablePath: process.env.CHROME ?? '/usr/bin/google-chrome' });
for (const scheme of ['light', 'dark']) {
    for (const [width, height] of [[1366, 768], [1920, 1080]]) {
        const context = await browser.newContext({ viewport: { width, height }, colorScheme: scheme, reducedMotion: 'reduce' });
        const page = await context.newPage();
        const errors = [];
        page.on('console', (message) => message.type() === 'error' && errors.push(message.text()));
        page.on('pageerror', (error) => errors.push(error.message));
        await page.goto(`${base}/login`);
        await page.fill('input[type=email]', user);
        await page.fill('input[type=password]', password);
        await Promise.all([page.waitForURL((url) => !url.pathname.startsWith('/login')), page.click('button[type=submit]')]);
        for (const entry of pages) {
            const [name, path] = entry.split('=');
            const response = await page.goto(`${base}${path}`, { waitUntil: 'networkidle' });
            await page.evaluate(() => document.fonts.ready);
            const file = `${out}/${name}-${width}-${scheme}.png`;
            await page.screenshot({ path: file });
            console.log(`${file} ${response?.status()} ${errors.length ? `errors: ${errors.join(' | ')}` : ''}`);
            errors.length = 0;
        }
        await context.close();
    }
}
await browser.close();
