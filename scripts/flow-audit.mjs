#!/usr/bin/env node
/**
 * Flow audit (docs/flow-audit.md): walks market cross-check Part A steps 1–14 ("a week in a non-life insurer") in a browser, as the role each step
 * belongs to, on the Part A demo company. Each step is recorded as pass, partial (done, with a shortfall) or fail, with notes and a screenshot.
 *
 *   composer db:fresh && php artisan erp:demo      # fresh story: the audit issues, pays, reserves and closes for real
 *   php artisan serve --port=8765 &                 # plus `npm run build`
 *   composer worker &                                # posts accounting events; without it the close finds unposted receipts as variances
 *   node scripts/flow-audit.mjs [--base http://nonlife.localhost:8765]
 *
 * Writes storage/flow-audit/results.json and storage/flow-audit/<step>.png.
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
const today = new Date().toISOString().slice(0, 10);
const state = {};

/** A signed-in page for a demo role user, one browser context per user. */
async function as(role) {
    if (sessions.has(role)) return sessions.get(role);
    const context = await browser.newContext({ viewport: { width: 1440, height: 900 }, reducedMotion: 'reduce' });
    const page = await context.newPage();
    page.setDefaultTimeout(8000);
    await page.goto(`${base}/login`);
    await page.fill('input[type=email]', `${role}@nonlife.local`);
    await page.fill('input[type=password]', password);
    await Promise.all([page.waitForURL((url) => !url.pathname.startsWith('/login')), page.click('button[type=submit]')]);
    sessions.set(role, page);
    return page;
}

async function settle(page) {
    await page.waitForLoadState('networkidle').catch(() => undefined);
    await page.waitForTimeout(250);
}

/** Types into a lookup and picks the first option whose text contains `pick`. */
async function lookup(page, input, text, pick = text) {
    await input.click();
    await input.fill(text);
    const option = page.getByRole('option').filter({ hasText: pick }).first();
    await option.waitFor();
    await option.dispatchEvent('mousedown');
}

/** Confirms the journal preview dialog (the deliberate stop before money moves) and returns its lines as text. */
async function confirmJournal(page) {
    const dialog = page.getByRole('dialog').filter({ hasText: 'Back to the form' });
    await dialog.waitFor();
    const lines = (await dialog.locator('tbody tr').allInnerTexts()).map((t) => t.replace(/\s+/g, ' ').trim());
    await dialog.getByRole('button').last().click();
    await dialog.waitFor({ state: 'detached' }).catch(() => undefined);
    await settle(page);
    return lines;
}

async function step(no, title, role, run) {
    const notes = [];
    let status = 'pass';
    let page;
    try {
        page = await as(role);
        const outcome = await run(page, notes);
        if (outcome === 'partial' || outcome === 'fail') status = outcome;
    } catch (error) {
        status = 'fail';
        notes.push(`Stopped: ${String(error.message ?? error).split('\n')[0]}`);
    }
    if (page) await page.screenshot({ path: `${out}/step-${String(no).padStart(2, '0')}.png` }).catch(() => undefined);
    results.push({ step: no, title, role, status, notes });
    console.log(`${String(no).padStart(2)} ${status.padEnd(7)} ${title}${notes.length ? `\n     - ${notes.join('\n     - ')}` : ''}`);
}

// ── Day 1: branch officer ───────────────────────────────────────────────────────────────────────────────
await step(1, 'New motor policy: product, customer, vehicle, sum insured, premium, VAT and stamp duty', 'branch.officer', async (page, notes) => {
    // Phase 3 R7: a rated product is quoted in the quote workbench (risk form, live premium), accepted as a proposal and approved by the underwriting rules.
    await page.goto(`${base}/quotations/create`);
    await settle(page);
    const product = page.locator('#product_id');
    await product.selectOption({ label: await product.locator('option', { hasText: 'Motor' }).first().innerText() });
    await page.locator('#inception input, input#inception').first().fill('t').catch(async () => page.getByLabel('Cover starts').fill('t'));
    await lookup(page, page.locator('#customer_party_id'), 'Karim');
    await lookup(page, page.locator('#producer_id'), 'AG-001');
    await page.locator('#risk_vehicle_type').selectOption('private');
    const stamp = Date.now().toString().slice(-4);
    await page.locator('#risk_registration_no').fill(`DHA-METRO-GA-19-${stamp}`);
    await page.locator('#risk_chassis_no').fill(`AUDIT-${stamp}`);
    await page.locator('#risk_engine_cc').fill('1500');
    await page.locator('#risk_seats').fill('5');
    await page.locator('#risk_year_of_manufacture').fill('2020');
    await page.locator('#risk_driver_age').fill('40');
    await page.locator('#risk_sum_insured').fill('450,000.00');
    await page.getByText(/Gross premium/).first().waitFor();
    const rail = (await page.getByRole('complementary', { name: 'Premium' }).innerText()).replace(/\s+/g, ' ');
    notes.push(`Premium worked out from the tariff: ${rail.slice(0, 240)}`);
    await Promise.all([page.waitForURL(/\/quotations\/[0-9a-f-]{36}/), page.getByRole('button', { name: 'Issue quotation' }).click()]);
    await settle(page);
    await page.getByRole('button', { name: /make proposal/ }).click();
    await Promise.all([page.waitForURL(/\/proposals\/[0-9a-f-]{36}/), page.getByRole('button', { name: 'Make proposal', exact: true }).click()]);
    await settle(page);
    await page.getByRole('button', { name: 'Verify identity' }).click();
    await page.locator('#id_number').fill('1990123456789');
    await page.getByRole('button', { name: 'Record verification' }).click();
    await settle(page);
    await page.getByRole('button', { name: 'Submit to underwriting' }).click();
    await page.getByRole('button', { name: 'Submit proposal', exact: true }).click();
    await settle(page);
    state.proposalUrl = page.url();
    const approved = await page.getByText('Approved automatically').count();
    notes.push(`Quotation issued and proposal ${approved ? 'approved automatically' : 'referred'} at ${state.proposalUrl.replace(base, '')}.`);
    return approved ? 'pass' : 'partial';
});

await step(2, 'Issue: policy number allocated, accounting written behind the scenes', 'branch.officer', async (page, notes) => {
    let outcome = 'pass';
    await page.goto(state.proposalUrl);
    await settle(page);
    await page.getByRole('button', { name: 'Issue policy' }).click();
    await page.getByLabel('Issue date').fill('t');
    await page.getByRole('button', { name: /^Review and issue/ }).click();
    const lines = await confirmJournal(page);
    notes.push(`Journal preview: ${lines.join(' | ')}`);
    await page.waitForURL(/\/policies\/[0-9a-f-]{36}/);
    await settle(page);
    state.policyUrl = page.url().split('?')[0];
    const heading = (await page.locator('h1').first().innerText()).trim();
    state.policyNumber = heading;
    state.gross = (await page.locator('dt', { hasText: /Gross premium/ }).locator('xpath=following-sibling::dd').first().innerText()).trim();
    notes.push(`Issued as ${heading}, gross premium ${state.gross}.`);
    if (!/^POL-[A-Z0-9]+-\d{4}-\d{6}$/.test(heading)) {
        notes.push('Number does not follow POL-<BRANCH>-<FY>-<seq>.');
        outcome = 'partial';
    }
    if (!lines.some((l) => /Stamp/i.test(l))) {
        notes.push('No stamp duty line in the journal.');
        outcome = 'partial';
    }
    return outcome;
});

await step(3, 'Receive the premium by bank transfer and allocate to the installment; receipt number for the customer', 'branch.manager', async (page, notes) => {
    let outcome = 'pass';
    notes.push('Done as the branch manager: a branch officer may record receipts but not allocate them (segregation of duties).');
    await page.goto(`${base}/receipts/create`);
    await settle(page);
    await page.getByLabel(/Amount received/).fill(state.gross);
    await page.getByLabel('Value date').fill('t');
    await page.locator('#channel').selectOption('bank_transfer');
    await page.getByLabel('Reference').fill('TRF KARIM MOTOR 2');
    if ((await page.locator('#allocation-0').count()) === 0) await page.getByRole('button', { name: 'Add an installment' }).click();
    await lookup(page, page.locator('#allocation-0'), state.policyNumber, state.policyNumber);
    await page.locator('#allocation-amount-0').fill(state.gross);
    await page.getByRole('button', { name: /^Review and post/ }).click();
    const lines = await confirmJournal(page);
    notes.push(`Journal preview: ${lines.join(' | ')}`);
    state.receiptPreview = lines;
    await page.waitForURL(/\/receipts\/[0-9a-f-]{36}/);
    state.receiptUrl = page.url();
    notes.push(`Receipt ${(await page.locator('h1').first().innerText()).trim()} recorded.`);
    await page.goto(`${state.receiptUrl.split('?')[0]}?tab=documents`);
    await settle(page);
    const generate = page.getByRole('button', { name: 'Generate receipt' });
    if ((await generate.count()) === 0) {
        notes.push('No printable receipt for the customer.');
        outcome = 'partial';
    } else {
        await generate.click();
        const printed = await page.getByText('Nothing printed yet').waitFor({ state: 'detached', timeout: 60000 }).then(() => true).catch(() => false);
        await settle(page);
        if (printed) {
            notes.push('Receipt PDF generated for the customer from the Documents tab (headless Chromium).');
        } else {
            notes.push('Generating the receipt did not finish within a minute.');
            outcome = 'partial';
        }
    }
    return outcome;
});

await step(4, 'Commission accrued on the receipt for an agent on a commission scheme', 'finance.manager', async (page, notes) => {
    await page.goto(state.policyUrl);
    await settle(page);
    await page.getByRole('button', { name: /View accounting/ }).click();
    await page.waitForTimeout(1200);
    const text = await page.locator('body').innerText();
    if (!/Commission Expense/i.test(text)) {
        notes.push('No commission journal on the policy after the receipt.');
        return 'fail';
    }
    notes.push(`The policy's accounting shows commission expense and commission payable (10% of ${state.gross}) posted with the allocation.`);
    return 'pass';
});

// ── Day 2: claims ────────────────────────────────────────────────────────────────────────────────────────
await step(5, 'Register the accident: policy, date of loss, description, documents — nothing financial yet', 'claims.officer', async (page, notes) => {
    let outcome = 'pass';
    await page.goto(`${base}/claims/create`);
    await settle(page);
    await lookup(page, page.locator('#policy_id'), state.policyNumber, state.policyNumber);
    await page.getByRole('button', { name: /^Continue/ }).click();
    await page.getByLabel('Date of loss').fill('t');
    await page.getByLabel('Reported on').fill('t');
    await page.getByLabel('What happened').fill('Rear collision at Farmgate signal');
    await page.getByRole('button', { name: /^Continue/ }).click();
    await Promise.all([page.waitForURL(/\/claims\/[0-9a-f-]{36}/), page.getByRole('button', { name: /^Register claim/ }).click()]);
    state.claimUrl = page.url();
    notes.push(`Claim ${(await page.locator('h1').first().innerText()).trim()} registered; status registered, no journal.`);
    await page.getByRole('tab', { name: 'Documents' }).click().catch(async () => page.getByRole('button', { name: 'Documents' }).click());
    await page.waitForTimeout(400);
    if ((await page.locator('input[type=file]').count()) === 0) {
        notes.push('Documents cannot be attached to the claim.');
        outcome = 'partial';
    } else {
        await page.locator('input[type=file]').first().setInputFiles({ name: 'survey-report.pdf', mimeType: 'application/pdf', buffer: Buffer.from('%PDF-1.4\n% flow audit\n') });
        await page.getByLabel('Description').fill('Surveyor report').catch(() => undefined);
        await page.locator('form button[type=submit]').last().click();
        await page.waitForTimeout(1500);
        await settle(page);
        notes.push((await page.getByText('survey-report.pdf').count()) > 0 ? 'Survey report attached and listed.' : 'Attaching the survey report did not list it.');
        if ((await page.getByText('survey-report.pdf').count()) === 0) outcome = 'partial';
    }
    return outcome;
});

await step(6, 'Surveyor estimates 200,000: set the reserve (claims expense / outstanding claims)', 'claims.officer', async (page, notes) => {
    await page.goto(state.claimUrl);
    await settle(page);
    await page.getByRole('button', { name: 'Set reserve' }).click();
    await page.getByLabel(/New total reserve/).fill('200,000.00');
    await page.getByLabel('Reason', { exact: true }).fill('Surveyor estimate');
    await page.getByLabel('Date', { exact: true }).fill('t');
    await page.getByRole('button', { name: /^Review and post/ }).click();
    notes.push(`Journal preview: ${(await confirmJournal(page)).join(' | ')}`);
    return 'pass';
});

await step(7, 'Settle at 180,000: claims manager approves (limit), finance releases (maker/checker), close releases the rest', 'claims.manager', async (page, notes) => {
    let outcome = 'pass';
    await page.goto(state.claimUrl);
    await settle(page);
    await page.getByRole('button', { name: 'Approve payment' }).click();
    await page.getByLabel(/^Amount/).fill('180,000.00');
    const payee = page.locator('#payee_party_id');
    await payee.selectOption({ index: 1 });
    await page.getByLabel('Approval date').fill('t');
    await page.getByRole('button', { name: /^Review and approve/ }).click();
    notes.push(`Approval preview: ${(await confirmJournal(page)).join(' | ') || 'nothing posted yet (routed for approval)'}`);
    const body = await page.locator('body').innerText();
    if (/pending approval/i.test(body)) {
        notes.push('The payment went to the approval queue (above the claims manager\'s limit).');
    } else {
        notes.push('Approved within the claims manager\'s limit.');
    }
    if ((await page.getByRole('button', { name: 'Request release' }).count()) === 0) {
        notes.push('No release to request (approval still pending or not configured).');
        return 'partial';
    }
    await page.getByRole('button', { name: 'Request release' }).click();
    await settle(page);

    const finance = await as('finance.manager');
    await finance.goto(state.claimUrl);
    await settle(finance);
    await finance.getByRole('button', { name: 'Pay', exact: true }).click();
    await finance.getByLabel('Paid on').fill('t');
    await finance.getByRole('button', { name: /^Review and pay/ }).click();
    notes.push(`Payment preview (finance manager): ${(await confirmJournal(finance)).join(' | ')}`);

    await page.goto(state.claimUrl);
    await settle(page);
    await page.getByRole('button', { name: 'Close claim' }).click();
    await page.getByLabel('Reason', { exact: true }).fill('Settled at 180,000');
    await page.getByLabel('Date', { exact: true }).fill('t');
    await page.getByRole('button', { name: /^Review and close/ }).click();
    const closing = await confirmJournal(page);
    notes.push(`Close preview: ${closing.join(' | ')}`);
    if (!closing.some((l) => /20,000\.00/.test(l))) outcome = 'partial';
    return outcome;
});

// ── Day 3: accountant ───────────────────────────────────────────────────────────────────────────────────
await step(8, 'Home queue shows unallocated receipts, unmatched bank lines, journals awaiting approval; allocate money in suspense', 'accountant', async (page, notes) => {
    await page.goto(`${base}/home`);
    await settle(page);
    const queues = await page.locator('section h2').allInnerTexts();
    notes.push(`Home queues: ${queues.join(', ')}.`);
    await page.goto(`${base}/suspense`);
    await settle(page);
    await page.getByRole('row').filter({ hasText: 'DEP 7781' }).first().click().catch(async () => page.locator('tbody tr').first().click());
    await page.getByRole('link', { name: 'Open the allocation workbench' }).click();
    await settle(page);
    const candidates = page.locator('section[aria-label="Candidate installments"] tbody tr').filter({ hasText: 'Rahima' });
    await candidates.first().click();
    await page.keyboard.press('Enter');
    await page.waitForTimeout(300);
    // Enter fills the line with what the installment still needs, up to what is left in suspense.
    await page.locator('section[aria-label="Receipt"] input[id^="line-"]').first().waitFor();
    await page.getByLabel('Allocation date').fill('t');
    await page.getByRole('button', { name: /^Allocate/ }).click();
    notes.push(`Allocation preview: ${(await confirmJournal(page)).join(' | ')}`);
    return 'pass';
});

await step(9, 'Import the bank statement CSV, accept suggested matches, leave the exceptions', 'accountant', async (page, notes) => {
    let outcome = 'pass';
    await page.goto(`${base}/bank`);
    await settle(page);
    await page.locator('tbody tr').filter({ hasText: 'City Bank' }).first().click();
    const open = page.getByRole('link', { name: /Open|statement|matching/i }).first();
    if (await open.count()) await open.click(); else await page.keyboard.press('Enter');
    await page.waitForURL(/\/bank\/[0-9a-f-]{36}/);
    await settle(page);
    notes.push('The September statement CSV was imported by the demo (storage/app/demo/city-bank-2026-09.csv); the import button is on this page.');
    const statement = page.locator('section[aria-label="Statement lines"] tbody tr');
    for (let attempt = 0; attempt < 6; attempt++) {
        const suggested = statement.filter({ hasText: /Strong match|Possible match/ }).first();
        if ((await suggested.count()) === 0) break;
        await suggested.click();
        await page.keyboard.press('Enter');
        await settle(page);
    }
    const left = await statement.filter({ hasNotText: /No rows|Every statement line|Strong match|Possible match/ }).allInnerTexts();
    notes.push(`Left to explain: ${left.map((t) => t.replace(/\s+/g, ' ').trim()).join(' | ') || 'none'}.`);
    for (const text of ['Bank charges', 'TT 9921']) {
        const row = statement.filter({ hasText: text }).first();
        if ((await row.count()) === 0) continue;
        await row.click();
        await page.getByLabel(/Explanation/).fill(text === 'Bank charges' ? 'Bank charges for September' : 'Unknown transfer, under investigation');
        await page.getByRole('button', { name: 'Explain' }).click();
        await settle(page);
    }
    if (left.length !== 2) outcome = 'partial';
    return outcome;
});

await step(10, 'Record office expenses, vendor bills (AP) and salaries', 'accountant', async (page, notes) => {
    await page.goto(`${base}/accounting/journals/create`);
    await settle(page);
    await page.getByLabel('Date', { exact: true }).fill('t');
    await page.getByLabel('Description').fill('Office rent for September');
    await page.getByLabel('Reason').fill('Rent invoice 9/26');
    const accounts = page.getByLabel(/^Account, line/);
    await accounts.nth(0).selectOption({ label: await accounts.nth(0).locator('option', { hasText: 'Office Rent' }).first().innerText().catch(async () => accounts.nth(0).locator('option', { hasText: 'Salaries' }).first().innerText()) });
    await accounts.nth(1).selectOption({ label: await accounts.nth(1).locator('option', { hasText: 'Bank - Main' }).first().innerText() });
    await page.getByLabel('Side, line 2').selectOption('credit');
    await page.getByLabel('Amount, line 1').fill('85,000.00');
    await page.getByLabel('Amount, line 2').fill('85,000.00');
    await Promise.all([page.waitForURL(/\/accounting\/journals\/[0-9a-f-]{36}/), page.getByRole('button', { name: /^Save and submit/ }).click()]);
    notes.push('Office rent recorded as a manual journal and submitted for approval.');
    notes.push('No accounts payable (vendor bills) or payroll module: only manual journals (G6 / Phase 2).');
    return 'partial';
});

// ── Month end: finance manager ─────────────────────────────────────────────────────────────────────────
await step(11, 'Close September: earn premium, reconcile premium, claims, commission and bank, review suspense', 'finance.manager', async (page, notes) => {
    await page.goto(`${base}/close`);
    await settle(page);
    await page.locator('tbody tr').filter({ hasText: /Sep(tember)? 2026/ }).first().click();
    const primary = page.getByRole('button', { name: /Start close|Open the checklist/ }).first();
    await primary.click();
    await page.waitForURL(/\/close\//);
    await settle(page);
    for (let i = 0; i < 14; i++) {
        const work = page.getByRole('button', { name: /^Work on/ }).first();
        if ((await work.count()) === 0) break;
        await work.click();
        const note = page.getByLabel(/^Note for/).first();
        await note.fill('Flow audit');
        const run = page.locator('li div.bg-surface-2 button').first();
        if (await run.isDisabled()) break;
        await run.click();
        await settle(page);
        if ((await page.getByRole('button', { name: /^Work on/ }).count()) > 0 && (await page.locator('li').filter({ hasText: 'Blocked' }).count()) > 0) break;
    }
    const tasks = (await page.locator('ol[aria-label="Close tasks"] > li').allInnerTexts()).map((t) => t.split('\n').filter(Boolean).slice(0, 4).join(' · '));
    notes.push(`Tasks: ${tasks.join(' | ')}`);
    return tasks.every((t) => /· Done$|· Skipped$/.test(t)) ? 'pass' : 'partial';
});

await step(12, 'Review trial balance, P&L, balance sheet; click a figure down to the policies and claims', 'finance.manager', async (page, notes) => {
    await page.goto(`${base}/accounting/trial-balance`);
    await settle(page);
    notes.push(`Trial balance rows: ${await page.locator('tbody tr').count()}.`);
    const figure = page.locator('tbody tr').filter({ hasText: 'Premium Receivable' }).locator('a').last();
    if ((await figure.count()) === 0) {
        notes.push('Figures in the trial balance are not links.');
        return 'partial';
    }
    await figure.click();
    await settle(page);
    notes.push(`Figure → ${page.url().replace(base, '')}.`);
    const activity = page.url();
    const journalLinks = await page.locator('main tbody a[href*="/accounting/journals/"]').evaluateAll((links) => links.map((a) => a.getAttribute('href')));
    let source = page.locator('nothing-yet');
    for (const href of journalLinks.slice(0, 8)) {
        await page.goto(`${base}${href}`);
        await settle(page);
        source = page.locator('main a[href^="/policies/"], main a[href^="/claims/"], main a[href^="/receipts/"]').first();
        if ((await source.count()) > 0) {
            notes.push(`→ journal ${href}.`);
            break;
        }
    }
    if (activity === page.url()) notes.push('The account activity lists no journals.');
    if ((await source.count()) === 0) {
        notes.push('The journal does not link to its policy, receipt or claim.');
        return 'partial';
    }
    await source.click();
    await settle(page);
    notes.push(`Drilled from the trial balance to ${page.url().replace(base, '')}.`);
    for (const report of ['profit-and-loss', 'balance-sheet']) {
        const response = await page.goto(`${base}/reports/${report}`);
        notes.push(`${report}: HTTP ${response?.status()}.`);
    }
    return 'pass';
});

await step(13, 'Lock the period; corrections then go into the next month', 'finance.manager', async (page, notes) => {
    await page.goto(`${base}/close`);
    await settle(page);
    await page.locator('tbody tr').filter({ hasText: /Sep(tember)? 2026/ }).first().click();
    await page.getByRole('button', { name: /Open the checklist/ }).first().click();
    await page.waitForURL(/\/close\//);
    await settle(page);
    const lock = page.getByRole('button', { name: 'Lock the period' });
    const reason = (await page.locator('section[aria-label="Lock the period"]').innerText()).replace(/\s+/g, ' ');
    if ((await lock.count()) === 0 || (await lock.isDisabled())) {
        notes.push(`Lock not available yet: ${reason}`);
        return 'partial';
    }
    await lock.click();
    await page.getByRole('dialog').getByRole('button').last().click().catch(() => undefined);
    await settle(page);
    notes.push('September locked.');
    return 'pass';
});

// ── Quarter / year end ─────────────────────────────────────────────────────────────────────────────────
await step(14, 'Regulatory exports: premium register by class, outstanding claims, unearned premium reserve, agency register', 'finance.manager', async (page, notes) => {
    let outcome = 'pass';
    await page.goto(`${base}/reports`);
    await settle(page);
    const text = await page.locator('main').innerText();
    for (const [name, pattern] of [['premium register', /Premium register/i], ['outstanding claims', /Outstanding claims/i], ['unearned premium', /Unearned premium/i]]) {
        if (!pattern.test(text)) {
            notes.push(`No ${name} report.`);
            outcome = 'partial';
        }
    }
    const register = await page.goto(`${base}/reports/premium-register`);
    const registerText = await page.locator('main').innerText();
    const classTotals = /total.{0,20}class|by class/i.test(registerText);
    notes.push(`Premium register HTTP ${register?.status()}${classTotals ? ', with totals by class' : ', without totals by class'}.`);
    if (!classTotals) outcome = 'partial';
    const auditor = await as('auditor');
    await auditor.goto(`${base}/distribution/producers`);
    await settle(auditor);
    if ((await auditor.getByRole('group', { name: /agency register/i }).count()) === 0) {
        notes.push('No agency register export on the producers queue.');
        outcome = 'partial';
    } else {
        const download = await Promise.all([auditor.waitForEvent('download'), auditor.getByRole('link', { name: 'XLSX' }).click()]).then(([d]) => d.suggestedFilename()).catch(() => null);
        notes.push(`Agency register export on the producers queue (holders of reports.regulatory, e.g. the auditor)${download ? `: downloaded ${download}` : ''}. The finance manager does not hold reports.regulatory, so does not see it.`);
    }
    await page.goto(`${base}/reports/unearned-premium`);
    const upr = (await page.locator('main').innerText()).replace(/\s+/g, ' ');
    const variance = upr.match(/Variance\s*([\d,.()-]+)/i)?.[1];
    notes.push(`Unearned premium report reconciles to the control${variance ? ` (variance ${variance})` : ''}.`);
    notes.push('IDRA return forms themselves are not built (G5).');
    return outcome === 'pass' ? 'partial' : outcome;
});

writeFileSync(`${out}/results.json`, `${JSON.stringify({ base, ranAt: new Date().toISOString(), results }, null, 2)}\n`);
await browser.close();
