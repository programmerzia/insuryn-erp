/**
 * Slice 2.0c: the small browser toolkit the E2E happy path uses — sign-in per role, Inertia settle, lookups, the journal preview dialog, polling
 * assertions, and failure evidence (screenshots, Playwright traces, console and server-error log under storage/e2e/). Patterns follow
 * scripts/flow-audit.mjs, copied rather than imported: the audit measures, this module asserts.
 */
import { mkdirSync, rmSync, writeFileSync } from 'node:fs';
import { chromium } from 'playwright-core';

export class AssertionFailed extends Error {}

/** Fails the run with a message when the condition does not hold. */
export function assert(condition, message) {
    if (!condition) throw new AssertionFailed(message);
}

export function assertEqual(actual, expected, message) {
    if (actual !== expected) throw new AssertionFailed(`${message}: expected ${show(expected)}, got ${show(actual)}`);
}

const show = (value) => JSON.stringify(value, (_, v) => (typeof v === 'bigint' ? `${v}` : v));

/** "14,568.75" → 1456875n (minor units); empty, dash or unparsable → 0n. */
export function minor(text) {
    const cleaned = String(text ?? '').replace(/[,\s]/g, '').replace(/^\((.*)\)$/, '-$1');
    if (!/^-?\d+(\.\d{1,2})?$/.test(cleaned)) return 0n;
    const [whole, fraction = ''] = cleaned.replace('-', '').split('.');
    const value = BigInt(whole) * 100n + BigInt(fraction.padEnd(2, '0'));
    return cleaned.startsWith('-') ? -value : value;
}

/**
 * Polls `read` until `accept(value)` holds or the timeout passes, then fails with the last value seen. `between` runs before each retry
 * (e.g. a reload while the queue worker posts).
 */
export async function eventually(read, accept, message, { timeout = 15000, interval = 500, between } = {}) {
    const until = Date.now() + timeout;
    let last;
    for (;;) {
        try {
            last = await read();
            if (accept(last)) return last;
        } catch (error) {
            last = `error: ${String(error.message ?? error).split('\n')[0]}`;
        }
        if (Date.now() > until) throw new AssertionFailed(`${message} (last seen: ${show(last)})`);
        await new Promise((resolve) => setTimeout(resolve, interval));
        if (between) await between();
    }
}

export async function settle(page) {
    await page.waitForLoadState('networkidle').catch(() => undefined);
    await page.waitForTimeout(250);
}

export async function click(page, locator) {
    await locator.click();
    await settle(page);
}

/** Types into a lookup and picks the option (the lookups choose on mousedown). */
export async function lookup(page, input, text, pick = text) {
    await input.click();
    await input.fill(text);
    const option = page.getByRole('option').filter({ hasText: pick }).first();
    await option.waitFor();
    await option.dispatchEvent('mousedown');
    await settle(page);
}

/** Fills a date or text field only when it is empty ("t" is today in the date inputs). */
export async function fillIfEmpty(locator, text) {
    if ((await locator.inputValue().catch(() => '')) === '') await locator.fill(text);
}

/** The status word beside the record title on an object page (ObjectPage header). */
export async function recordStatus(page) {
    return (await page.locator('header h1').first().locator('xpath=following-sibling::span[1]').innerText()).trim();
}

/**
 * Reads the journal preview dialog (the deliberate stop before money moves), asserts each journal balances and nothing would fail to post,
 * confirms, and returns the journals: [{ title, lines: [{ account, debit, credit }], debit, credit }].
 */
export async function confirmJournal(page, { expectPosting = true } = {}) {
    const dialog = page.getByRole('dialog').filter({ hasText: 'Back to the form' });
    await dialog.waitFor();
    await settle(page);
    const failures = await dialog.getByRole('alert').allInnerTexts();
    assert(failures.length === 0, `journal preview reports failures: ${failures.join(' | ')}`);
    const journals = [];
    for (const section of await dialog.locator('section').all()) {
        const lines = [];
        for (const row of await section.locator('tbody tr').all()) {
            const cells = row.locator('td');
            lines.push({ account: (await cells.nth(0).locator('span').first().innerText()).replace(/\s+/g, ' ').trim(), debit: minor(await cells.nth(1).innerText()), credit: minor(await cells.nth(2).innerText()) });
        }
        const totals = section.locator('tfoot td');
        const journal = { title: (await section.locator('h3').innerText()).trim(), lines, debit: minor(await totals.nth(1).innerText()), credit: minor(await totals.nth(2).innerText()) };
        assert(journal.debit > 0n && journal.debit === journal.credit, `journal preview "${journal.title}" does not balance: debit ${journal.debit}, credit ${journal.credit}`);
        assertEqual(lines.reduce((sum, l) => sum + l.debit, 0n), journal.debit, `journal preview "${journal.title}" debit lines add up to the total`);
        assertEqual(lines.reduce((sum, l) => sum + l.credit, 0n), journal.credit, `journal preview "${journal.title}" credit lines add up to the total`);
        journals.push(journal);
    }
    if (expectPosting) assert(journals.length > 0, 'journal preview shows no journal lines');
    await dialog.getByRole('button').last().click();
    await dialog.waitFor({ state: 'detached', timeout: 20000 });
    await settle(page);
    return journals;
}

/** Whether a previewed journal has a line on the account (name or code) on the given side with the amount (minor units, optional). */
export function hasLine(journals, account, side, amount) {
    return journals.some((j) => j.lines.some((l) => l.account.includes(account) && l[side] > 0n && (amount === undefined || l[side] === amount)));
}

/**
 * One E2E run: a browser, one signed-in context per role (traced), every console message, page error and 5xx response recorded; `step`
 * names what is running so a failure says where, and `finish` writes the evidence on failure and returns the exit code.
 */
export async function createRun({ base, out = 'storage/e2e', chrome = process.env.CHROME ?? '/usr/bin/google-chrome', password = process.env.ERP_ADMIN_PASSWORD ?? 'ChangeMe123!', domain }) {
    rmSync(out, { recursive: true, force: true });
    mkdirSync(out, { recursive: true });
    const browser = await chromium.launch({ executablePath: chrome, args: ['--no-sandbox'] });
    const sessions = new Map();
    const log = [];
    const serverErrors = [];
    const pageErrors = [];
    let current = 'start';

    async function as(role) {
        if (sessions.has(role)) return sessions.get(role);
        const context = await browser.newContext({ viewport: { width: 1440, height: 900 }, reducedMotion: 'reduce' });
        await context.tracing.start({ screenshots: true, snapshots: true, title: role });
        const page = await context.newPage();
        page.setDefaultTimeout(15000);
        page.on('console', (m) => log.push(`[${role}] [${current}] console.${m.type()}: ${m.text()}`));
        page.on('pageerror', (e) => {
            pageErrors.push(`[${role}] [${current}] ${e.message}`);
            log.push(`[${role}] [${current}] pageerror: ${e.stack ?? e.message}`);
        });
        page.on('response', (r) => {
            if (r.status() >= 500) serverErrors.push(`[${role}] [${current}] ${r.status()} ${r.request().method()} ${r.url()}`);
        });
        page.on('requestfailed', (r) => log.push(`[${role}] [${current}] request failed: ${r.method()} ${r.url()} ${r.failure()?.errorText ?? ''}`));
        const session = { page, context };
        sessions.set(role, session); // before signing in, so a failed sign-in still leaves a screenshot and a trace
        await page.goto(`${base}/login`);
        await page.fill('input[type=email]', `${role}@${domain}`);
        await page.fill('input[type=password]', password);
        await page.click('button[type=submit]');
        await eventually(() => new URL(page.url()).pathname, (path) => !path.startsWith('/login'), `${role}@${domain} signs in`, { timeout: 20000 });
        await settle(page);
        return session;
    }

    const step = async (name, run) => {
        current = name;
        const started = Date.now();
        await run();
        // ASSUMPTION A-149: a page error or a 5xx during the step is a regression even when the screen recovered; console messages are only logged.
        assert(serverErrors.length === 0, `server errors during "${name}": ${serverErrors.join(' | ')}`);
        assert(pageErrors.length === 0, `uncaught page errors during "${name}": ${pageErrors.join(' | ')}`);
        console.log(`  ok  ${name} (${((Date.now() - started) / 1000).toFixed(1)}s)`);
    };

    const finish = async (error) => {
        if (error) {
            console.error(`  FAIL ${current}\n       ${String(error.stack ?? error).split('\n').slice(0, 6).join('\n       ')}`);
            for (const [role, { page, context }] of sessions) {
                const name = role.replace(/\W+/g, '-');
                await page.screenshot({ path: `${out}/failure-${name}.png`, fullPage: true }).catch(() => undefined);
                writeFileSync(`${out}/failure-${name}.url.txt`, `${page.url()}\n`);
                await context.tracing.stop({ path: `${out}/trace-${name}.zip` }).catch(() => undefined);
            }
            writeFileSync(`${out}/console.log`, `${[`failed in: ${current}`, String(error.stack ?? error), '', ...serverErrors, ...log].join('\n')}\n`);
            console.error(`  evidence: ${out}/ (failure-*.png, trace-*.zip — npx playwright-core show-trace, console.log)`);
        } else {
            for (const { context } of sessions.values()) await context.tracing.stop().catch(() => undefined);
        }
        await browser.close().catch(() => undefined);
        return error ? 1 : 0;
    };

    return { as, step, finish };
}
