#!/usr/bin/env node
/**
 * UX slice screenshots (docs/ux-design-brief.md review loop): every page at 1366×768 and 1920×1080, light and dark (system preference),
 * signed in as a demo user. Needs `php artisan serve` and a local database seeded with DemoBusinessSeeder.
 *
 *   node scripts/ux-shots.mjs <slice> [--user admin@demo.local] [--base http://127.0.0.1:8765] name=/path[|step…] …
 *
 * Steps run in order before the screenshot: click:<selector>, ctrl-click:<selector>, hover:<selector>, key:<Playwright key>, wait:<ms>,
 * type:<text>. Example: journals=/accounting/journals|click:tbody tr:nth-child(2)|key:Control+b
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
// Sign in once (the login rate limiter allows a few attempts a minute) and reuse the session in every context.
const login = await browser.newContext();
const loginPage = await login.newPage();
await loginPage.goto(`${base}/login`);
await loginPage.fill('input[type=email]', user);
await loginPage.fill('input[type=password]', password);
await Promise.all([loginPage.waitForResponse((r) => r.url().endsWith('/login') && r.request().method() === 'POST'), loginPage.click('button[type=submit]')]);
const storageState = await login.storageState();
await login.close();
for (const scheme of ['light', 'dark']) {
    for (const [width, height] of [[1366, 768], [1920, 1080]]) {
        const context = await browser.newContext({ viewport: { width, height }, colorScheme: scheme, reducedMotion: 'reduce', storageState });
        const page = await context.newPage();
        const errors = [];
        page.on('console', (message) => message.type() === 'error' && errors.push(message.text()));
        page.on('pageerror', (error) => errors.push(error.message));
        for (const entry of pages) {
            const [name, rest] = [entry.slice(0, entry.indexOf('=')), entry.slice(entry.indexOf('=') + 1)];
            const [path, ...steps] = rest.split('|');
            const response = await page.goto(`${base}${path}`, { waitUntil: 'networkidle' });
            await page.evaluate(() => document.fonts.ready);
            for (const step of steps) {
                const [action, ...argParts] = step.split(':');
                const arg = argParts.join(':');
                if (action === 'click') await page.click(arg);
                else if (action === 'ctrl-click') await page.click(arg, { modifiers: ['Control'] });
                else if (action === 'hover') await page.hover(arg);
                else if (action === 'key') await page.keyboard.press(arg);
                else if (action === 'type') await page.keyboard.type(arg);
                else if (action === 'wait') await page.waitForTimeout(Number(arg));
                await page.waitForLoadState('networkidle');
                await page.waitForTimeout(150);
            }
            const file = `${out}/${name}-${width}-${scheme}.png`;
            await page.screenshot({ path: file });
            console.log(`${file} ${response?.status()} ${errors.length ? `errors: ${errors.join(' | ')}` : ''}`);
            errors.length = 0;
        }
        await context.close();
    }
}
await browser.close();
