#!/usr/bin/env node
/**
 * Gap audit GA-16: the screens used on a phone — Home, a policy, a receipt (new and recorded) and a claim (new and registered) — fit a 400 px wide
 * screen without scrolling sideways (document scrollWidth ≤ innerWidth), the sidebar is hidden behind the ☰ button there and opens over the page,
 * and a desktop keeps its sidebar. Wide tables scroll inside their own container, which does not widen the page.
 *
 *   node tests/e2e/phone-width.mjs [--base http://nonlife.localhost:8771]   # after scripts/e2e.sh seeded the demo (it runs this after the happy path)
 *
 * Screenshots land in storage/e2e/phone/ (gitignored).
 */
import { mkdirSync } from 'node:fs';
import { chromium } from 'playwright-core';

const args = process.argv.slice(2);
const option = (name, fallback) => (args.includes(`--${name}`) ? args[args.indexOf(`--${name}`) + 1] : fallback);
const base = option('base', process.env.E2E_BASE_URL ?? 'http://nonlife.localhost:8771');
const domain = `${new URL(base).hostname.split('.')[0]}.local`;
const out = 'storage/e2e/phone';
mkdirSync(out, { recursive: true });
const failures = [];
const check = (condition, message) => {
    if (!condition) failures.push(message);
    console.log(`${condition ? 'ok  ' : 'FAIL'} ${message}`);
};

const browser = await chromium.launch({ executablePath: process.env.CHROME ?? '/usr/bin/google-chrome', args: ['--no-sandbox'] });
try {
    const phone = await browser.newContext({ viewport: { width: 400, height: 860 } });
    const page = await phone.newPage();
    await page.goto(`${base}/login`);
    await page.fill('input[type=email]', `branch.manager@${domain}`);
    await page.fill('input[type=password]', process.env.ERP_ADMIN_PASSWORD ?? 'ChangeMe123!');
    await Promise.all([page.waitForURL(/\/home/), page.click('button[type=submit]')]);

    const find = async (q, kind) => {
        const response = await page.request.get(`${base}/search?q=${encodeURIComponent(q)}`, { headers: { Accept: 'application/json' } });
        return ((await response.json()).results ?? []).find((r) => r.kind === kind)?.href;
    };
    const screens = { home: '/home', policy: await find('POL', 'policy'), 'receipt-create': '/receipts/create', receipt: await find('RCT', 'receipt'),
        'claim-create': '/claims/create', claim: await find('CLM', 'claim') };

    for (const [name, path] of Object.entries(screens)) {
        check(Boolean(path), `${name}: the demo has a record to open`);
        if (!path) continue;
        await page.goto(`${base}${path}`);
        await page.waitForLoadState('networkidle').catch(() => undefined);
        await page.waitForTimeout(300);
        const size = await page.evaluate(() => ({ scroll: document.documentElement.scrollWidth, inner: window.innerWidth, mainScroll: (() => {
            const main = document.getElementById('main');
            return main ? main.scrollWidth - main.clientWidth : 0;
        })() }));
        check(size.scroll <= size.inner && size.mainScroll <= 0, `${name} (${path}) fits 400 px: page ${size.scroll} px, main scrolls sideways by ${size.mainScroll} px`);
        check(!(await page.locator('#main-navigation').isVisible()), `${name}: the sidebar is hidden`);
        await page.screenshot({ path: `${out}/${name}-400.png` });
    }

    await page.goto(`${base}/home`);
    await page.getByTestId('phone-menu').click();
    check(await page.locator('#main-navigation').isVisible(), 'the ☰ button opens the sidebar over the page');
    await page.screenshot({ path: `${out}/menu-open-400.png` });
    await page.locator('#main-navigation').getByRole('link', { name: 'Policies' }).click();
    await page.waitForURL(/\/policies/);
    await page.waitForTimeout(300);
    check(!(await page.locator('#main-navigation').isVisible()), 'following a sidebar link closes it');

    const desktop = await browser.newContext({ viewport: { width: 1366, height: 768 }, storageState: await phone.storageState() });
    const wide = await desktop.newPage();
    await wide.goto(`${base}/home`);
    await wide.waitForLoadState('networkidle').catch(() => undefined);
    const nav = await wide.locator('#main-navigation').boundingBox();
    check(nav !== null && nav.x === 0 && nav.width >= 48, `a desktop keeps the sidebar (${nav ? `${Math.round(nav.width)} px` : 'hidden'})`);
    check(!(await wide.getByTestId('phone-menu').isVisible()), 'a desktop has no ☰ button');
} finally {
    await browser.close();
}
if (failures.length) {
    console.error(`phone width: ${failures.length} check(s) failed`);
    process.exit(1);
}
console.log('phone width: all checks passed');
