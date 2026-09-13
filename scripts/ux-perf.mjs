#!/usr/bin/env node
/**
 * Navigation timing (UX brief §7 "subsequent navigations < 300ms"): signs in, then times Inertia visits from click to the new page being
 * rendered (inertia:start → inertia:finish) under network profiles emulated through the Chrome DevTools protocol. Prints JSON.
 *   node scripts/ux-perf.mjs [--user finance.manager@demo.local] /from=selector … (see the default scenario below)
 */
import { chromium } from 'playwright-core';

const base = process.env.BASE ?? 'http://127.0.0.1:8765';
const user = process.env.USER_EMAIL ?? 'finance.manager@demo.local';
const profiles = {
    'fast-3g': { latency: 562.5, downloadThroughput: (1440 * 1024) / 8, uploadThroughput: (675 * 1024) / 8 },
    '4g': { latency: 150, downloadThroughput: (9000 * 1024) / 8, uploadThroughput: (9000 * 1024) / 8 },
    none: null,
};
// [label, page to start on, selector to hover first (prefetch) or null, selector to click]
const steps = [
    ['Home → Receipts (sidebar, hover prefetch)', '/home', 'nav[aria-label=Main] a[href="/receipts"]', 'nav[aria-label=Main] a[href="/receipts"]'],
    ['Receipts → Policies (sidebar, no hover)', '/receipts', null, 'nav[aria-label=Main] a[href="/policies"]'],
    ['Policies → policy page (hover prefetch, then click)', '/policies', 'table[role=grid] tbody tr:nth-child(3) a', 'table[role=grid] tbody tr:nth-child(3) a'],
    ['Policy page → Accounting tab (deferred props already loaded)', null, null, 'button[role=tab]:has-text("Accounting")'],
    ['Trial balance → account activity (drill)', '/accounting/trial-balance?as_of=2026-09-30', null, 'tbody tr:nth-child(2) td:nth-child(4) a'],
];

const browser = await chromium.launch({ executablePath: process.env.CHROME ?? '/usr/bin/google-chrome' });
const login = await browser.newContext();
const lp = await login.newPage();
await lp.goto(`${base}/login`);
await lp.fill('input[type=email]', user);
await lp.fill('input[type=password]', process.env.ERP_ADMIN_PASSWORD ?? 'ChangeMe123!');
await Promise.all([lp.waitForResponse((r) => r.url().endsWith('/login') && r.request().method() === 'POST'), lp.click('button[type=submit]')]);
const storageState = await login.storageState();
await login.close();

const results = {};
for (const [profile, conditions] of Object.entries(profiles)) {
    const context = await browser.newContext({ viewport: { width: 1366, height: 768 }, storageState });
    const page = await context.newPage();
    const cdp = await context.newCDPSession(page);
    await cdp.send('Network.enable');
    if (conditions) await cdp.send('Network.emulateNetworkConditions', { offline: false, ...conditions });
    await page.addInitScript(() => {
        // From the press of the mouse to the new page component being in place (Inertia's navigate event), whether the data came from the
        // network or from the hover-prefetch cache.
        window.__clickAt = 0;
        window.__navigatedAt = 0;
        document.addEventListener('mousedown', () => { window.__clickAt = performance.now(); window.__navigatedAt = 0; }, true);
        document.addEventListener('inertia:navigate', () => { if (window.__clickAt) window.__navigatedAt = performance.now(); });
    });
    results[profile] = [];
    for (const [label, startAt, hover, click] of steps) {
        if (startAt) await page.goto(`${base}${startAt}`, { waitUntil: 'networkidle' });
        await page.waitForTimeout(conditions ? 4000 : 800); // idle page-code warm-up
        if (hover) {
            await page.hover(hover);
            await page.waitForTimeout(conditions ? 2500 : 600); // hover prefetch completes
        }
        let ms;
        if (label.includes('tab')) {
            // measured inside the page: from the click to the next painted frame with the panel shown
            ms = Math.round(await page.evaluate(async (selector) => {
                const tab = [...document.querySelectorAll('button[role=tab]')].find((b) => b.textContent?.trim() === 'Accounting');
                const t0 = performance.now();
                tab?.dispatchEvent(new MouseEvent('mousedown', { bubbles: true }));
                tab?.click();
                await new Promise((resolve) => requestAnimationFrame(() => requestAnimationFrame(resolve)));
                return performance.now() - t0;
            }, click));
        } else {
            await page.click(click);
            await page.waitForFunction(() => window.__navigatedAt > 0, null, { timeout: 60000 });
            ms = Math.round(await page.evaluate(() => window.__navigatedAt - window.__clickAt));
            await page.waitForLoadState('networkidle');
        }
        results[profile].push({ step: label, ms });
    }
    await context.close();
}
await browser.close();
console.log(JSON.stringify(results, null, 2));
