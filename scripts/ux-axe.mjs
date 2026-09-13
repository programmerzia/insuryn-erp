#!/usr/bin/env node
/**
 * Accessibility check (UX slice U9): runs axe-core (WCAG 2.1 A/AA rules) on each page in light and dark, signed in as a demo user.
 *   node scripts/ux-axe.mjs [--user admin@demo.local] /path [/path …]
 * Prints each violation with its impact, rule and the first offending elements; exits 1 when any serious or critical violation remains.
 */
import { readFileSync } from 'node:fs';
import { chromium } from 'playwright-core';

const args = process.argv.slice(2);
const userIndex = args.indexOf('--user');
const user = userIndex === -1 ? 'admin@demo.local' : args.splice(userIndex, 2)[1];
const base = process.env.BASE ?? 'http://127.0.0.1:8765';
const axe = readFileSync(new URL('../node_modules/axe-core/axe.min.js', import.meta.url), 'utf8');

const browser = await chromium.launch({ executablePath: process.env.CHROME ?? '/usr/bin/google-chrome' });
const login = await browser.newContext();
const page0 = await login.newPage();
await page0.goto(`${base}/login`);
await page0.fill('input[type=email]', user);
await page0.fill('input[type=password]', process.env.ERP_ADMIN_PASSWORD ?? 'ChangeMe123!');
await Promise.all([page0.waitForResponse((r) => r.url().endsWith('/login') && r.request().method() === 'POST'), page0.click('button[type=submit]')]);
const storageState = await login.storageState();
await login.close();

let serious = 0;
for (const scheme of ['light', 'dark']) {
    const context = await browser.newContext({ viewport: { width: 1366, height: 768 }, colorScheme: scheme, storageState });
    const page = await context.newPage();
    for (const path of args) {
        await page.goto(`${base}${path}`, { waitUntil: 'networkidle' });
        await page.addScriptTag({ content: axe });
        const result = await page.evaluate(async () => await window.axe.run(document, { runOnly: { type: 'tag', values: ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa'] } }));
        const violations = result.violations;
        console.log(`${scheme} ${path}: ${violations.length === 0 ? 'no violations' : `${violations.length} rule(s) violated`}`);
        for (const v of violations) {
            if (v.impact === 'serious' || v.impact === 'critical') serious++;
            console.log(`  [${v.impact}] ${v.id}: ${v.help} — ${v.nodes.slice(0, 3).map((n) => n.target.join(' ')).join(' | ')}`);
        }
    }
    await context.close();
}
await browser.close();
process.exit(serious > 0 ? 1 : 0);
