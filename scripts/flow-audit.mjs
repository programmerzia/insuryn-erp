#!/usr/bin/env node
/**
 * Flow audit (docs/flow-audit.md): walks market cross-check Part A steps 1–14 ("a week in a non-life insurer") in a browser, as the role each step belongs
 * to, on the Part A demo company, and MEASURES the work a user does (UX brief §1 throughput): the starting screen, the screens reached, the drawers and
 * dialogs opened, clicks and keystrokes, fields without a sensible default, required fields that cannot be known yet, next steps not offered on
 * completion, and every point where the user must leave the flow to create or look up something.
 *
 * Counting rules — the script behaves like a user who knows the app:
 * - Each step starts on the screen a user would be on (Home, or where the previous step ended) and moves by clicking (sidebar, links, buttons).
 * - A click is a pointer press on a control; choosing from a select counts 2 (open + choose); a lookup pick counts 1 click after typing.
 * - Keystrokes are the characters typed plus keys pressed (Enter, Tab, shortcuts). Money is typed as digits without separators.
 * - A field that already holds the right value is not touched: that is a sensible default, and costs nothing. A field that is empty or wrong costs
 *   the keystrokes/clicks and is listed as "no default".
 * - Screens = distinct pages (Inertia page components, e.g. `quotations/Workbench`) visited during the step, including the starting one: a tab or a record saved
 *   in place is the same screen. Drawers and dialogs are counted when they open.
 *
 *   composer db:fresh && php artisan erp:demo      # fresh story; step 13 locks September
 *   npm run build && php artisan serve --port=8765 &
 *   composer worker &                                # restart it after code changes (php artisan queue:restart)
 *   node scripts/flow-audit.mjs [--base http://nonlife.localhost:8765]
 *
 * Writes storage/flow-audit/results.json, storage/flow-audit/table.md and storage/flow-audit/step-NN.png.
 */
import { mkdirSync, writeFileSync } from 'node:fs';
import { chromium } from 'playwright-core';

const args = process.argv.slice(2);
const option = (name, fallback) => (args.includes(`--${name}`) ? args[args.indexOf(`--${name}`) + 1] : fallback);
const base = option('base', 'http://nonlife.localhost:8765');
const password = process.env.ERP_ADMIN_PASSWORD ?? 'ChangeMe123!';
const out = 'storage/flow-audit';
mkdirSync(out, { recursive: true });

const browser = await chromium.launch({ executablePath: process.env.CHROME ?? '/usr/bin/google-chrome' });
const results = [];
const sessions = new Map();
const state = { stamp: Date.now().toString().slice(-4) };

const screenOf = (url) => {
    const u = new URL(url);
    return u.pathname.replace(/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}/g, '{id}') + (u.searchParams.get('tab') ? `?tab=${u.searchParams.get('tab')}` : '');
};

/** The Inertia page component on screen (what the user perceives as one screen). */
const componentOf = async (page) => page.evaluate(() => {
    if (window.history.state?.page?.component) return window.history.state.page.component;
    try {
        return JSON.parse(document.querySelector('script[data-page]')?.textContent ?? '{}').component ?? location.pathname;
    } catch {
        return location.pathname;
    }
}).catch(() => screenOf(page.url()));

/** The meter of the step running now. */
let meter = null;

class Meter {
    constructor(page) {
        this.page = page;
        this.start = screenOf(page.url());
        this.screens = new Set([this.start]);
        this.clicks = 0;
        this.keys = 0;
        this.dialogs = 0;
        this.leaves = [];
        this.noDefaults = [];
        this.unknowable = [];
        this.nextSteps = [];
        this.notes = [];
    }

    async restart() {
        this.start = await componentOf(this.page);
        this.screens = new Set([this.start]);
    }

    async track() {
        this.screens.add(await componentOf(this.page));
    }
}

/** A signed-in page for a demo role user, one browser context per user; navigation and dialogs feed the current meter. */
async function as(role) {
    if (sessions.has(role)) return sessions.get(role);
    const context = await browser.newContext({ viewport: { width: 1440, height: 900 }, reducedMotion: 'reduce' });
    const page = await context.newPage();
    page.setDefaultTimeout(10000);
    await page.exposeFunction('__erpAuditDialog', (n) => {
        if (meter?.page === page) meter.dialogs += n;
    });
    await context.addInitScript(() => {
        const count = (node) => {
            if (!(node instanceof Element)) return;
            const matches = [node, ...node.querySelectorAll('[role="dialog"],[role="alertdialog"]')].filter((el) => /^(dialog|alertdialog)$/.test(el.getAttribute('role') ?? ''));
            if (matches.length) window.__erpAuditDialog?.(matches.length);
        };
        new MutationObserver((mutations) => mutations.forEach((m) => m.addedNodes.forEach(count))).observe(document, { childList: true, subtree: true });
    });
    page.on('framenavigated', (frame) => {
        if (frame === page.mainFrame() && meter?.page === page) void meter.track();
    });
    await page.goto(`${base}/login`);
    await page.fill('input[type=email]', `${role}@nonlife.local`);
    await page.fill('input[type=password]', password);
    await Promise.all([page.waitForURL((url) => !url.pathname.startsWith('/login')), page.click('button[type=submit]')]);
    sessions.set(role, page);
    return page;
}

async function settle(page) {
    await page.waitForLoadState('networkidle').catch(() => undefined);
    await page.waitForTimeout(300);
    if (meter?.page === page) await meter.track();
}

// ── Measured user actions ─────────────────────────────────────────────────────────────────────────────────
async function click(locator) {
    await locator.click();
    meter.clicks += 1;
    await settle(meter.page);
}
async function press(key) {
    await meter.page.keyboard.press(key);
    meter.keys += 1;
    await settle(meter.page);
}
/** Types into a field the user must fill (no default check). */
async function type(locator, text) {
    await locator.click();
    meter.clicks += 1;
    await locator.fill(text);
    meter.keys += text.length;
}
/** Fills a field only when it does not already hold what the user wants; otherwise it was a sensible default. */
async function ensure(locator, wanted, label, { select = false, typed = wanted, accept = (v) => v === wanted } = {}) {
    const current = await locator.inputValue().catch(() => '');
    if (accept(current)) return;
    meter.noDefaults.push(`${label}${current ? ` (was “${current}”)` : ''}`);
    if (select) {
        await locator.selectOption(wanted);
        meter.clicks += 2;
    } else {
        await type(locator, typed);
    }
}
/** Lookup: click, type the query, pick the option. */
async function lookup(input, text, pick = text) {
    await type(input, text);
    const option = meter.page.getByRole('option').filter({ hasText: pick }).first();
    await option.waitFor();
    await option.dispatchEvent('mousedown');
    meter.clicks += 1;
}
/** Confirms the journal preview dialog (the deliberate stop before money moves), returns its lines. */
async function confirmJournal() {
    const page = meter.page;
    const dialog = page.getByRole('dialog').filter({ hasText: 'Back to the form' });
    await dialog.waitFor();
    const lines = (await dialog.locator('tbody tr').allInnerTexts()).map((t) => t.replace(/\s+/g, ' ').trim());
    await dialog.getByRole('button').last().click();
    meter.clicks += 1;
    await dialog.waitFor({ state: 'detached' }).catch(() => undefined);
    await settle(page);
    return lines;
}
/** Starts a task from a Home start action when Home offers it, else through the sidebar list and its "new" button. */
async function start(label, list, newName) {
    const fromHome = meter.page.locator('main').getByRole('link', { name: label, exact: true });
    if (/\/home$/.test(new URL(meter.page.url()).pathname) && (await fromHome.count())) return click(fromHome.first());
    await nav(list);
    await click(meter.page.getByRole('link', { name: newName }).or(meter.page.getByRole('button', { name: newName })).first());
}
async function nav(label) {
    // Sidebar items by address (their names carry badge counts, "Bank ● 5").
    const hrefs = { Quotes: '/quotations', Receipts: '/receipts', Suspense: '/suspense', Claims: '/claims', Bank: '/bank', Journals: '/accounting/journals', Close: '/close',
        'Trial balance': '/accounting/trial-balance', Reports: '/reports', Producers: '/distribution/producers', Policies: '/policies' };
    await click(meter.page.locator(`nav[aria-label="Main"] a[href="${hrefs[label] ?? label}"]`).first());
}
/** Records whether the page now offers the natural next step (a button or link matching). */
async function offers(pattern, what) {
    const found = (await meter.page.getByRole('button', { name: pattern }).count()) + (await meter.page.getByRole('link', { name: pattern }).count());
    if (!found) meter.nextSteps.push(what);
    return found > 0;
}
const tab = (page, name) => page.getByRole('tab', { name }).or(page.getByRole('link', { name, exact: true })).or(page.getByRole('button', { name, exact: true })).first();

async function step(no, title, role, run) {
    let page;
    let status = 'done';
    try {
        page = await as(role);
        meter = new Meter(page);
        await meter.restart();
        await run(page, meter);
    } catch (error) {
        status = 'stopped';
        meter?.notes.push(`Stopped: ${String(error.message ?? error).split('\n')[0]}`);
    }
    if (page) await page.screenshot({ path: `${out}/step-${String(no).padStart(2, '0')}.png` }).catch(() => undefined);
    const m = meter;
    const row = { step: no, title, role, status, start: m.start, screens: [...m.screens], screenCount: m.screens.size, dialogs: m.dialogs, clicks: m.clicks, keys: m.keys,
        leaves: m.leaves, noDefaults: m.noDefaults, unknowable: m.unknowable, nextSteps: m.nextSteps, notes: m.notes };
    results.push(row);
    console.log(`${String(no).padStart(2)} ${status.padEnd(7)} screens ${row.screenCount} dialogs ${row.dialogs} clicks ${row.clicks} keys ${row.keys} — ${title}`);
    for (const [label, list] of [['leave', m.leaves], ['no default', m.noDefaults], ['unknowable', m.unknowable], ['next step not offered', m.nextSteps], ['note', m.notes]]) {
        for (const item of list) console.log(`     ${label}: ${item}`);
    }
    console.log(`     screens: ${row.screens.join(' → ')}`);
}

// ── Day 1: branch officer ────────────────────────────────────────────────────────────────────────────────
await step(1, 'New motor policy: product, customer (new), vehicle, sum insured, premium, VAT and stamp duty', 'branch.officer', async (page, m) => {
    await page.goto(`${base}/home`);
    await settle(page);
    await m.restart();
    await start('New quote', 'Quotes', /New quote/);
    await ensure(page.locator('#branch_id'), await page.locator('#branch_id option').filter({ hasText: 'Head Office' }).first().getAttribute('value'), 'branch', { select: true });
    const motor = await page.locator('#product_id option').filter({ hasText: 'Motor' }).first().getAttribute('value');
    await ensure(page.locator('#product_id'), motor, 'product', { select: true });
    await ensure(page.locator('#inception input, input#inception').first(), '', 'cover start', { accept: (v) => v !== '', typed: 't' });

    // A new customer: created inline from the lookup (Part A "enters the customer (or creates one)").
    const name = `Shafiq Rahman ${state.stamp}`;
    await type(page.locator('#customer_party_id'), name);
    const create = page.getByRole('button', { name: /New customer/ });
    if ((await create.count()) === 0) {
        m.leaves.push('customer: no inline create — Parties → New party, then back');
    } else {
        await create.dispatchEvent('mousedown');
        m.clicks += 1;
        await page.getByRole('button', { name: 'Create customer' }).waitFor();
        await click(page.getByRole('button', { name: 'Create customer' }));
    }
    // The producer exists here; a producer that does not cannot be created from the quote.
    // What the open lookup offers for a producer that is not there yet (read before picking; the list closes on pick).
    await type(page.locator('#producer_id'), 'AG-001');
    await page.getByRole('option').filter({ hasText: 'AG-001' }).first().waitFor();
    const askForProducer = await page.getByText(/Ask your branch manager to add the producer/).count();
    const newProducer = await page.getByRole('button', { name: /New producer/ }).count();
    await page.getByRole('option').filter({ hasText: 'AG-001' }).first().dispatchEvent('mousedown');
    m.clicks += 1;
    if (askForProducer) m.leaves.push('producer (when new): a branch officer asks the branch manager, who creates it inline from the quote (agent.manage)');
    else if (!newProducer) m.leaves.push('producer (when new): no inline create — Distribution → Producers and a licence, then back');

    await ensure(page.locator('#risk_vehicle_type'), 'private', 'vehicle type', { select: true });
    for (const [field, value] of [['registration_no', `DHA-METRO-GA-19-${state.stamp}`], ['chassis_no', `AUDIT-${state.stamp}`], ['engine_cc', '1500'], ['seats', '5'],
        ['year_of_manufacture', '2020'], ['driver_age', '40'], ['sum_insured', '450000']]) {
        const input = page.locator(`#risk_${field}`);
        if ((await input.count()) === 0) continue;
        const label = await page.locator(`label[for="risk_${field}"]`).innerText().catch(() => '');
        if (field === 'chassis_no' && /optional/i.test(label)) continue; // not needed to price: entered on the proposal
        if ((await input.inputValue()) === '') await type(input, value);
    }
    const chassisLabel = await page.locator('label[for="risk_chassis_no"]').innerText().catch(() => '');
    if (chassisLabel && !/optional/i.test(chassisLabel)) m.unknowable.push('chassis number required to quote (a customer asking for a price rarely has it)');
    await page.getByText(/Gross premium/).first().waitFor();
    await click(page.getByRole('button', { name: 'Issue quotation' }));
    await click(page.getByRole('button', { name: /make proposal/ }));
    await click(page.getByRole('button', { name: 'Make proposal', exact: true }));
    await page.waitForURL(/\/proposals\//);
    await click(page.getByRole('button', { name: 'Verify identity' }));
    await type(page.locator('#id_number'), '1990123456789');
    await click(page.getByRole('button', { name: 'Record verification' }));
    await click(page.getByRole('button', { name: 'Submit to underwriting' }));
    const chassis = page.locator('#detail_chassis_no');
    if (await chassis.count()) {
        m.notes.push('Chassis number entered on the proposal (risk details drawer), when the customer brings the registration papers.');
        await type(chassis, `AUDIT-${state.stamp}`);
        await click(page.getByRole('button', { name: 'Save details' }));
        await click(page.getByRole('button', { name: 'Submit to underwriting' }));
    }
    await click(page.getByRole('button', { name: 'Submit proposal', exact: true }));
    state.proposalUrl = page.url();
    m.notes.push((await page.getByText('Approved automatically').count()) ? 'Proposal approved automatically.' : 'Proposal referred.');
});

await step(2, 'Issue: policy number allocated, accounting written behind the scenes', 'branch.officer', async (page, m) => {
    await click(page.getByRole('button', { name: 'Issue policy' }));
    await ensure(page.getByLabel('Issue date'), '', 'issue date', { accept: (v) => v !== '', typed: 't' });
    await click(page.getByRole('button', { name: /^Review and issue/ }));
    const lines = await confirmJournal();
    m.notes.push(`Journal: ${lines.join(' | ')}`);
    await page.waitForURL(/\/policies\//);
    await settle(page);
    state.policyUrl = page.url().split('?')[0];
    state.policyNumber = (await page.locator('h1').first().innerText()).trim();
    state.gross = (await page.locator('dt', { hasText: /Gross premium/ }).locator('xpath=following-sibling::dd').first().innerText()).trim();
    m.notes.push(`Issued ${state.policyNumber}, gross ${state.gross}.`);
    await offers(/record (a )?receipt|take (the )?payment/i, 'after issue → “Record receipt?”');
});

await step(3, 'Receive the premium by bank transfer, allocate to the installment, receipt for the customer', 'branch.manager', async (page, m) => {
    m.notes.push('Branch manager: a branch officer may record but not allocate receipts (SoD).');
    await page.goto(state.policyUrl);
    await settle(page);
    await m.restart();
    const fromPolicy = page.getByRole('link', { name: /record (a )?receipt|take (the )?payment/i }).or(page.getByRole('button', { name: /record (a )?receipt|take (the )?payment/i }));
    if (await fromPolicy.count()) {
        await click(fromPolicy.first());
    } else {
        m.leaves.push('receipt is started from Receipts → Record a receipt, not from the policy');
        await nav('Receipts');
        await click(page.getByRole('link', { name: /Record a receipt/ }).or(page.getByRole('button', { name: /Record a receipt/ })).first());
    }
    const plain = state.gross.replace(/,/g, '').replace(/\.00$/, '');
    const same = (v) => v.replace(/,/g, '') === state.gross.replace(/,/g, '');
    await ensure(page.locator('#amount'), '', 'amount', { accept: same, typed: plain });
    await ensure(page.locator('#value_date input, input#value_date').first(), '', 'value date', { accept: (v) => v !== '', typed: 't' });
    await ensure(page.locator('#channel'), 'bank_transfer', 'channel', { select: true });
    await type(page.locator('#reference'), `TRF ${state.stamp}`);
    await ensure(page.locator('#branch_id'), await page.locator('#branch_id option').filter({ hasText: 'Head Office' }).first().getAttribute('value'), 'branch', { select: true });
    if ((await page.locator('#allocation-0').count()) === 0) await click(page.getByRole('button', { name: 'Add an installment' }));
    const allocated = await page.locator('#allocation-0').inputValue();
    if (!allocated.includes(state.policyNumber)) await lookup(page.locator('#allocation-0'), state.policyNumber, state.policyNumber);
    await ensure(page.locator('#allocation-amount-0'), '', 'allocation amount', { accept: same, typed: plain });
    await click(page.getByRole('button', { name: /^Review and post/ }));
    m.notes.push(`Journal: ${(await confirmJournal()).join(' | ')}`);
    await page.waitForURL(/\/receipts\/[0-9a-f-]{36}/);
    state.receiptUrl = page.url().split('?')[0];
    if (!(await offers(/generate receipt|print receipt/i, 'after receipt → “Print receipt” (it is only on the Documents tab)'))) await click(tab(page, 'Documents'));
    await click(page.getByRole('button', { name: /Generate receipt|Print receipt/ }).first());
    await page.getByText('Nothing printed yet').waitFor({ state: 'detached', timeout: 60000 }).catch(() => m.notes.push('Receipt PDF did not finish within a minute.'));
});

await step(4, 'Commission accrued on the receipt for an agent on a commission scheme', 'finance.manager', async (page, m) => {
    await page.goto(state.policyUrl);
    await settle(page);
    await m.restart();
    await click(page.getByRole('button', { name: /View accounting/ }));
    await page.waitForTimeout(1000);
    m.notes.push(/Commission Expense/i.test(await page.locator('body').innerText()) ? 'Commission expense and payable on the policy; no user action (it accrues on allocation).' : 'No commission journal on the policy.');
});

// ── Day 2: claims ────────────────────────────────────────────────────────────────────────────────────────
await step(5, 'Register the accident: policy, date of loss, description, documents', 'claims.officer', async (page, m) => {
    await page.goto(`${base}/home`);
    await settle(page);
    await m.restart();
    await start('Register a claim', 'Claims', /Register a claim/);
    await lookup(page.locator('#policy_id'), state.policyNumber, state.policyNumber);
    await click(page.getByRole('button', { name: /^Continue/ }));
    await type(page.getByLabel('Date of loss'), 't'); // only the customer knows it: an input, not a missing default
    await ensure(page.getByLabel('Reported on'), '', 'reported on', { accept: (v) => v !== '', typed: 't' });
    await type(page.getByLabel('What happened'), 'Rear collision at Farmgate signal');
    await click(page.getByRole('button', { name: /^Continue/ }));
    await click(page.getByRole('button', { name: /^Register claim/ }));
    await page.waitForURL(/\/claims\/[0-9a-f-]{36}/);
    state.claimUrl = page.url().split('?')[0];
    state.claimNumber = (await page.locator('h1').first().innerText()).trim();
    if ((await page.locator('input[type=file]').count()) === 0) {
        m.notes.push('Documents are attached after registering, on the claim page Documents tab.');
        await click(tab(page, 'Documents'));
    }
    const file = page.locator('input[type=file]').first();
    if ((await file.count()) === 0) {
        m.leaves.push('documents cannot be attached to the claim');
    } else {
        await file.setInputFiles({ name: 'survey-report.pdf', mimeType: 'application/pdf', buffer: Buffer.from('%PDF-1.4\n% flow audit\n') });
        m.clicks += 2; // choose the file in the picker
        await click(page.locator('form button[type=submit]').last());
    }
    await offers(/set reserve/i, 'after registering → “Set reserve”');
});

await step(6, 'Surveyor estimates 200,000: set the reserve', 'claims.officer', async (page, m) => {
    if ((await page.getByRole('button', { name: 'Set reserve' }).count()) === 0) {
        await page.goto(state.claimUrl);
        await settle(page);
    }
    await click(page.getByRole('button', { name: 'Set reserve' }));
    await type(page.getByLabel(/New total reserve/), '200000');
    await type(page.getByLabel('Reason', { exact: true }), 'Surveyor estimate');
    await ensure(page.getByLabel('Date', { exact: true }), '', 'reserve date', { accept: (v) => v !== '', typed: 't' });
    await click(page.getByRole('button', { name: /^Review and post/ }));
    m.notes.push(`Journal: ${(await confirmJournal()).join(' | ')}`);
    if (await page.getByText(/approves the settlement/i).count()) m.notes.push(`Hand-off: ${(await page.getByText(/approves the settlement/i).first().innerText()).trim()}`);
    else await offers(/approve payment|send for approval|ask .*to approve/i, 'after reserve → “Approve” (the claims officer cannot approve their own reserve; no hand-off offered)');
});

await step(7, 'Settle at 180,000: approve within limit, finance releases, close releases the rest', 'claims.manager', async (page, m) => {
    await page.goto(`${base}/home`);
    await settle(page);
    await m.restart();
    const fromHome = page.locator(`main a[href="${new URL(state.claimUrl).pathname}"]`).first();
    if (await fromHome.count()) await click(fromHome);
    else {
        m.leaves.push('the claim waiting for approval is not on the claims manager\'s Home');
        await nav('Claims');
        await click(page.locator('tbody a[href*="/claims/"]').first());
    }
    if (!page.url().startsWith(state.claimUrl)) {
        m.notes.push('Home opened a different claim; went to the audit claim.');
        await page.goto(state.claimUrl);
        await settle(page);
    }
    await click(page.getByRole('button', { name: 'Approve payment' }));
    const amount = page.getByLabel(/^Amount/);
    if ((await amount.inputValue()) === '') m.noDefaults.push('approval amount (reserve not proposed)');
    await type(amount, '180000');
    const payee = page.locator('#payee_party_id');
    if ((await payee.evaluate((el) => el.tagName)) === 'SELECT') {
        if (!(await payee.inputValue())) {
            m.noDefaults.push('payee (policyholder not preselected)');
            await payee.selectOption({ index: 1 });
            m.clicks += 2;
        }
        m.leaves.push('payee other than a party (e.g. a garage): create in Parties first — no inline create');
    } else {
        if (!(await payee.inputValue())) m.noDefaults.push('payee (policyholder not preselected)');
        if (!(await page.getByText(/Ctrl\+N adds|New payee/).count())) m.leaves.push('payee other than a party (e.g. a garage): create in Parties first — no inline create');
    }
    await ensure(page.getByLabel('Approval date'), '', 'approval date', { accept: (v) => v !== '', typed: 't' });
    await click(page.getByRole('button', { name: /^Review and approve/ }));
    m.notes.push(`Approval journal: ${(await confirmJournal()).join(' | ') || 'none (routed)'}`);
    await offers(/request release/i, 'after approval → “Request release”');
    await click(page.getByRole('button', { name: 'Request release' }));

    const manager = page;
    const finance = await as('finance.manager');
    m.page = finance;
    m.notes.push('Finance manager releases the payment (a different person).');
    await finance.goto(`${base}/home`);
    await settle(finance);
    await m.track();
    const release = finance.locator(`main a[href="${new URL(state.claimUrl).pathname}"]`).first();
    if (await release.count()) await click(release);
    else m.leaves.push('the payment to release is not on the finance manager\'s Home');
    if (!finance.url().startsWith(state.claimUrl)) {
        await finance.goto(state.claimUrl);
        await settle(finance);
    }
    await click(finance.getByRole('button', { name: 'Pay', exact: true }));
    await ensure(finance.getByLabel('Paid on'), '', 'paid on', { accept: (v) => v !== '', typed: 't' });
    await click(finance.getByRole('button', { name: /^Review and pay/ }));
    m.notes.push(`Payment journal: ${(await confirmJournal()).join(' | ')}`);

    m.page = manager;
    await manager.goto(state.claimUrl);
    await settle(manager);
    await click(manager.getByRole('button', { name: 'Close claim' }));
    await type(manager.getByLabel('Reason', { exact: true }), 'Settled at 180,000');
    await ensure(manager.getByLabel('Date', { exact: true }), '', 'close date', { accept: (v) => v !== '', typed: 't' });
    await click(manager.getByRole('button', { name: /^Review and close/ }));
    m.notes.push(`Close journal: ${(await confirmJournal()).join(' | ')}`);
});

// ── Day 3: accountant ────────────────────────────────────────────────────────────────────────────────────
await step(8, 'Home queue; allocate money in suspense to a policy', 'accountant', async (page, m) => {
    await page.goto(`${base}/home`);
    await settle(page);
    await m.restart();
    m.notes.push(`Home queues: ${(await page.locator('section h2').allInnerTexts()).join(', ')}.`);
    const item = page.locator('section').filter({ hasText: 'Unallocated receipts' }).locator('tbody a').first();
    if (await item.count()) await click(item);
    else {
        await nav('Suspense');
        await click(page.locator('tbody tr').first());
    }
    if (!/\/allocate/.test(page.url())) {
        const workbench = page.getByRole('link', { name: /allocation workbench|^Allocate/i }).first();
        if (await workbench.count()) await click(workbench);
    }
    await page.locator('section[aria-label="Candidate installments"] tbody tr').first().waitFor();
    await click(page.locator('section[aria-label="Candidate installments"] tbody tr').filter({ hasText: 'Rahima' }).first());
    await press('Enter');
    await ensure(page.getByLabel('Allocation date'), '', 'allocation date', { accept: (v) => v !== '', typed: 't' });
    await click(page.getByRole('button', { name: /^Allocate/ }));
    m.notes.push(`Journal: ${(await confirmJournal()).join(' | ')}`);
});

await step(9, 'Import the bank statement CSV, accept suggested matches, exceptions left', 'accountant', async (page, m) => {
    await nav('Bank');
    await click(page.locator('tbody tr').filter({ hasText: 'City Bank' }).first());
    // Gap fixes W7: only the page's own links (the sidebar's "Commission statements" matches /statement/ too).
    const open = page.locator('main').getByRole('link', { name: /Open|statement|matching/i }).first();
    if (await open.count()) await click(open);
    else await press('Enter');
    await page.waitForURL(/\/bank\/[0-9a-f-]{36}/);
    const amount = state.gross.replace(/,/g, '');
    const csv = `date,description,reference,amount\n${new Date().toISOString().slice(0, 10)},Transfer,TRF ${state.stamp},${amount}\n`;
    await page.locator('input[type=file]').first().setInputFiles({ name: 'city-bank-today.csv', mimeType: 'text/csv', buffer: Buffer.from(csv) });
    m.clicks += 3; // Import statement, choose the file, open
    await settle(page);
    const statement = page.locator('section[aria-label="Statement lines"] tbody tr');
    for (let i = 0; i < 8; i++) {
        const suggested = statement.filter({ hasText: /Strong match|Possible match/ }).first();
        if ((await suggested.count()) === 0) break;
        await click(suggested);
        await press('Enter');
    }
    const left = (await statement.allInnerTexts()).map((t) => t.replace(/\s+/g, ' ').trim()).filter((t) => !/Every statement line|No rows/.test(t));
    m.notes.push(`Exceptions left: ${left.join(' | ') || 'none'}.`);
    for (const text of ['Bank charges', 'TT 9921']) {
        const row = statement.filter({ hasText: text }).first();
        if ((await row.count()) === 0) continue;
        await click(row);
        await type(page.getByLabel(/Explanation/), text === 'Bank charges' ? 'Bank charges' : 'Unknown transfer, investigating');
        await click(page.getByRole('button', { name: 'Explain' }));
    }
});

await step(10, 'Record office expenses (vendor bills and salaries are not built)', 'accountant', async (page, m) => {
    await nav('Journals');
    await click(page.getByRole('link', { name: /New manual journal|New journal/ }).or(page.getByRole('button', { name: /New manual journal|New journal/ })).first());
    await ensure(page.getByLabel('Date', { exact: true }), '', 'journal date', { accept: (v) => v !== '', typed: 't' });
    await type(page.getByLabel('Description'), 'Office rent for September');
    await type(page.getByLabel('Reason'), 'Rent invoice 9/26');
    // Gap fixes W7: journal accounts are a lookup since GA-31 — type the code or name and pick it.
    const account = (index) => page.locator(`input#line-account-${index}`);
    await type(account(0), 'Rent');
    await page.waitForTimeout(800); // the lookup asks the server as the user types
    const rent = page.getByRole('option').filter({ hasText: /Rent/ });
    if ((await rent.count()) > 0) {
        await rent.first().dispatchEvent('mousedown');
        m.clicks += 1;
    } else {
        m.leaves.push((await page.getByRole('button', { name: 'New account' }).count())
            ? 'no rent account in this chart: created inline (New account)'
            : 'no rent/office expense account in this chart: the accountant cannot add accounts — ask the Finance Manager, then come back');
        await lookup(account(0), '5300', '5300');
    }
    await lookup(account(1), '1010', '1010');
    await ensure(page.getByLabel('Side, line 2'), 'credit', 'side of line 2', { select: true });
    await type(page.getByLabel('Amount, line 1'), '85000');
    if ((await page.getByLabel('Amount, line 2').inputValue()).replace(/,/g, '') !== '85000.00') await type(page.getByLabel('Amount, line 2'), '85000');
    if (!(await page.getByRole('button', { name: 'New account' }).count()) && !(await page.getByText(/added by the Finance Manager/).count())) {
        m.leaves.push('an account that is not in the chart: no inline create — Accounting → Imports (CSV)');
    }
    m.leaves.push('vendor bill (AP) and salaries: no module, only a manual journal (G6)');
    await click(page.getByRole('button', { name: /^Save and submit/ }));
});

// ── Month end: finance manager ───────────────────────────────────────────────────────────────────────────
await step(11, 'Close September: run the checklist', 'finance.manager', async (page, m) => {
    await page.goto(`${base}/home`);
    await settle(page);
    await m.restart();
    await nav('Close');
    await click(page.locator('tbody tr').filter({ hasText: /Sep(tember)? 2026/ }).first());
    await click(page.getByRole('button', { name: /Start close|Open the checklist/ }).first());
    await page.waitForURL(/\/close\//);
    // Gap fixes W7: as many rounds as the checklist has tasks (W4 added reconciliations and the year-end close); a task that posts (premium earning,
    // year-end close) shows its journal first and is confirmed; a task that is refused or blocked is passed over, not retried.
    const taskCount = await page.locator('ol[aria-label="Close tasks"] > li').count();
    let passedOver = 0;
    for (let i = 0; i < taskCount; i++) {
        const open = page.getByRole('button', { name: /^Work on/ });
        const before = await open.count();
        if (before <= passedOver) break;
        await click(open.nth(passedOver));
        const run = page.locator('li div.bg-surface-2 button').first();
        if (await run.isDisabled()) {
            passedOver += 1;
            await click(open.nth(passedOver - 1)); // close it again
            continue;
        }
        const posts = /Review and run/.test(await run.innerText());
        await click(run);
        if (posts) {
            const lines = await confirmJournal().catch(() => null);
            if (lines === null) m.notes.push('A posting task showed no journal preview.');
        }
        if ((await page.getByRole('button', { name: /^Work on/ }).count()) >= before) passedOver += 1;
    }
    const tasks = (await page.locator('ol[aria-label="Close tasks"] > li').allInnerTexts()).map((t) => t.split('\n').filter(Boolean).slice(0, 4).join(' · '));
    m.notes.push(`Tasks not done: ${tasks.filter((t) => !/· Done$/.test(t)).join(' | ') || 'none'}.`);
});

await step(12, 'Trial balance, P&L, balance sheet; click a figure down to the policy', 'finance.manager', async (page, m) => {
    await nav('Trial balance');
    await click(page.locator('tbody tr').filter({ hasText: 'Premium Receivable' }).locator('a').last());
    const direct = page.locator('main tbody a[href^="/policies/"]').first();
    if (await direct.count()) {
        await click(direct);
    } else {
        const journals = await page.locator('main tbody a[href*="/accounting/journals/"]').evaluateAll((links) => links.map((a) => a.getAttribute('href')));
        for (const href of journals.slice(0, 8)) {
            await page.goto(`${base}${href}`);
            await settle(page);
            const source = page.locator('main a[href^="/policies/"], main a[href^="/claims/"], main a[href^="/receipts/"]').first();
            if (await source.count()) {
                m.clicks += 1; // the journal row the user opens
                await click(source);
                break;
            }
        }
    }
    // The statements: linked from each other since X11, else through the reports index each time.
    const statement = async (name) => {
        const link = page.locator('main').getByRole('link', { name, exact: true });
        if (await link.count()) return click(link.first());
        await nav('Reports');
        await click(page.getByRole('link', { name }).first());
    };
    await nav('Trial balance');
    await statement('Profit and loss');
    await statement('Balance sheet');
});

await step(13, 'Lock the period', 'finance.manager', async (page, m) => {
    await nav('Close');
    await click(page.locator('tbody tr').filter({ hasText: /Sep(tember)? 2026/ }).first());
    await click(page.getByRole('button', { name: /Open the checklist/ }).first());
    const lock = page.getByRole('button', { name: 'Lock the period' });
    if ((await lock.count()) === 0 || (await lock.isDisabled())) {
        // Gap fixes W7 (CQ-C5, D-56): the soft lock opens on the month's last day and the lock after month end; a CFO's early lock also needs the month
        // soft-locked first, so before 30 September nobody locks it. The audit runs on 14 September: it records what the lock section says.
        m.notes.push(`Lock not available: ${(await page.locator('section[aria-label="Lock the period"]').innerText()).replace(/\s+/g, ' ')}`);
        m.notes.push('Soft lock opens on 30 Sep, hard lock opens on 1 Oct (CQ-C5); the CFO\'s early lock with a reason also needs the soft lock, so no role locks September on 14 Sep.');
        return;
    }
    await click(lock);
    await click(page.getByRole('alertdialog').getByRole('button').last());
    m.notes.push('September locked.');
});

// ── Quarter / year end ───────────────────────────────────────────────────────────────────────────────────
await step(14, 'Regulatory exports: premium register by class, outstanding claims, UPR, agency register', 'finance.manager', async (page, m) => {
    await page.goto(`${base}/home`);
    await settle(page);
    await m.restart();
    await nav('Reports');
    for (const report of ['Premium register', 'Outstanding claims', 'Unearned premium']) {
        const fromIndex = page.getByRole('link', { name: new RegExp(`^Export ${report}.* as CSV`, 'i') });
        if (await fromIndex.count()) {
            await Promise.all([page.waitForEvent('download').catch(() => undefined), fromIndex.first().click()]);
            meter.clicks += 1;
            continue;
        }
        if (!(await page.getByRole('link', { name: report }).count())) await nav('Reports');
        await click(page.getByRole('link', { name: report }).first());
        const exportButton = page.getByRole('button', { name: /Export/ }).first();
        if (await exportButton.count()) await click(exportButton);
        else m.notes.push(`${report}: no export button.`);
    }
    await nav('Producers');
    const xlsx = page.getByRole('link', { name: 'XLSX' });
    if (await xlsx.count()) await click(xlsx.first());
    else m.leaves.push('agency register: no export on the producers queue');
    m.leaves.push('IDRA return forms: not built (G5)');
    m.notes.push('Run as the finance manager, who files the returns in Part A and holds reports.regulatory (fix G3).');
});

// ── Output ───────────────────────────────────────────────────────────────────────────────────────────────
writeFileSync(`${out}/results.json`, `${JSON.stringify({ base, ranAt: new Date().toISOString(), results }, null, 2)}\n`);
const cell = (list) => (list.length ? list.join('; ') : '—');
const table = ['| # | Part A step | Role | Start | Screens | Drawers/dialogs | Clicks | Keys | Leaves the flow | No default | Required but unknowable | Next step not offered |',
    '|---|---|---|---|---|---|---|---|---|---|---|---|',
    ...results.map((r) => `| ${r.step} | ${r.title} | ${r.role} | \`${r.start}\` | ${r.screenCount} | ${r.dialogs} | ${r.clicks} | ${r.keys} | ${cell(r.leaves)} | ${cell(r.noDefaults)} | ${cell(r.unknowable)} | ${cell(r.nextSteps)} |`)];
writeFileSync(`${out}/table.md`, `${table.join('\n')}\n`);
await browser.close();
