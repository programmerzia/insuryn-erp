// Demo flow verification: drives the finance/HR modules through the browser as the right roles.
// Usage: node storage/flow-audit/demo-flows.mjs http://nonlife.localhost:8784 [step numbers...]
import { chromium } from '/home/ziaur-rahman/WorkGround/Studio/insuryn-erp/node_modules/playwright-core/index.mjs';

const base = process.argv[2] ?? 'http://nonlife.localhost:8784';
const only = process.argv.slice(3).map(Number);
const browser = await chromium.launch({ executablePath: '/usr/bin/google-chrome' });
const problems = [];
const passed = [];

function fail(msg) {
    throw new Error(msg);
}

const sessions = new Map();
async function login(role) {
    if (sessions.has(role)) {
        const existing = sessions.get(role);
        existing.errorsSeen.length = 0;
        return existing;
    }
    const page = await loginFresh(role);
    sessions.set(role, page);
    return page;
}
async function loginFresh(role) {
    const context = await browser.newContext({ acceptDownloads: true });
    const page = await context.newPage();
    page.errorsSeen = [];
    page.on('console', (m) => m.type() === 'error' && page.errorsSeen.push(`console: ${m.text().slice(0, 200)}`));
    page.on('pageerror', (e) => page.errorsSeen.push(`pageerror: ${String(e).slice(0, 200)}`));
    page.on('response', (r) => {
        if (r.status() >= 500) page.errorsSeen.push(`http ${r.status()} ${r.request().method()} ${r.url()}`);
    });
    page.role = role;
    for (let attempt = 1; ; attempt++) {
        await page.goto(`${base}/login`, { timeout: 60000 });
        await page.fill('input[type=email]', `${role}@nonlife.local`);
        await page.fill('input[type=password]', 'ChangeMe123!');
        await page.click('button[type=submit]');
        const ok = await page.waitForURL((u) => !u.pathname.startsWith('/login'), { timeout: 45000 }).then(() => true, () => false);
        if (ok) { page.errorsSeen.length = 0; return page; }
        const alerts = await page.locator('[role=alert]').allInnerTexts().catch(() => []);
        if (attempt >= 2) fail(`login ${role} failed: ${alerts.join(' | ') || page.url()}`);
    }
}

async function goto(page, url) {
    const res = await page.goto(`${base}${url}`);
    await page.waitForLoadState('networkidle').catch(() => undefined);
    if (!res || res.status() >= 400) fail(`${page.role} GET ${url} -> ${res?.status()}`);
    await assertNoErrorPage(page);
    return res;
}

/** Follows a sidebar link by address, opening the collapsible section it lives in first (a closed section keeps its links out of the DOM). */
async function nav(page, href) {
    const section = page.locator(`nav[aria-label="Main"] section[data-hrefs~="${href}"]`).first();
    const heading = section.locator('button[aria-expanded="false"]');
    if (await heading.count()) await heading.first().click();
    await section.locator(`a[href="${href}"]`).first().click();
    await page.waitForLoadState('networkidle').catch(() => undefined);
    await assertNoErrorPage(page);
}

async function assertNoErrorPage(page) {
    const text = await page.locator('body').innerText().catch(() => '');
    if (/Whoops|Server Error|Internal Server Error|SQLSTATE|Exception|Page Expired/.test(text)) fail(`${page.role} error page at ${page.url()}: ${text.slice(0, 200).replace(/\s+/g, ' ')}`);
}

function checkErrors(page, label) {
    if (page.errorsSeen.length) {
        const list = page.errorsSeen.splice(0);
        // Inertia 422 form errors are shown as 'console' in dev? no — only 5xx and page errors are recorded.
        fail(`${label}: ${list.join(' | ')}`);
    }
}

/** Type into a LookupInput (role=combobox) and pick the first matching option. */
async function pickLookup(page, input, text) {
    await input.click();
    await input.fill(text);
    const option = page.locator('[role=listbox] [role=option]').first();
    await option.waitFor({ state: 'visible', timeout: 10000 });
    const label = await option.innerText();
    await option.dispatchEvent('mousedown');
    return label;
}

/** The journal preview dialog: wait for it, ensure no failures, confirm by its last button, wait until it closes. */
async function confirmPreview(page, label = 'preview') {
    const dialog = page.locator('[role=dialog]').filter({ hasText: 'Back to the form' });
    await dialog.waitFor({ state: 'visible', timeout: 15000 }).catch(async () => {
        const alerts = await page.locator('[role=alert]').allInnerTexts();
        fail(`${label}: preview dialog did not open; alerts: ${alerts.join(' | ') || 'none'}`);
    });
    await page.waitForTimeout(200);
    const alerts = await dialog.locator('[role=alert]').allInnerTexts();
    if (alerts.length) fail(`${label}: preview reports failures: ${alerts.join(' | ')}`);
    const confirm = dialog.locator('button').last();
    const text = (await confirm.innerText()).trim();
    if (await confirm.isDisabled()) fail(`${label}: preview confirm "${text}" is disabled`);
    await confirm.click();
    await dialog.waitFor({ state: 'hidden', timeout: 30000 });
    await page.waitForLoadState('networkidle').catch(() => undefined);
    await assertNoErrorPage(page);
    return text;
}

async function clickButton(page, text, opts = {}) {
    const button = page.getByRole('button', { name: text, exact: opts.exact ?? false }).first();
    await button.waitFor({ state: 'visible', timeout: 10000 });
    await button.click();
    if (!opts.noWait) await page.waitForLoadState('networkidle').catch(() => undefined);
}

async function expectText(page, text, label) {
    const locator = page.getByText(text, { exact: false }).first();
    await locator.waitFor({ state: 'visible', timeout: 15000 }).catch(() => fail(`${label}: expected "${text}" on ${page.url()} — body: ${(page.lastBody = '')}`));
}

async function bodyText(page) {
    return (await page.locator('body').innerText()).replace(/\s+/g, ' ');
}

async function expectStatus(page, text, label) {
    await page.waitForLoadState('networkidle').catch(() => undefined);
    const deadline = Date.now() + 20000;
    while (Date.now() < deadline) {
        const body = await bodyText(page);
        if (new RegExp(text, 'i').test(body)) return;
        await page.waitForTimeout(500);
        await page.reload().catch(() => undefined);
        await page.waitForLoadState('networkidle').catch(() => undefined);
    }
    fail(`${label}: expected status /${text}/ on ${page.url()} — body: ${(await bodyText(page)).slice(0, 400)}`);
}

async function download(page, url, expectType, label) {
    const res = await page.request.get(`${base}${url}`);
    const type = res.headers()['content-type'] ?? '';
    const body = await res.body();
    if (res.status() !== 200) fail(`${label}: GET ${url} -> ${res.status()} ${body.toString().slice(0, 200)}`);
    if (!type.includes(expectType)) fail(`${label}: GET ${url} content-type ${type}, expected ${expectType}`);
    if (body.length === 0) fail(`${label}: GET ${url} is empty`);
    return body;
}

const steps = {};

// ---------------------------------------------------------------- steps are registered below

const run = async () => {
    for (const [n, step] of Object.entries(steps)) {
        if (only.length && !only.includes(Number(n))) continue;
        try {
            await step();
            passed.push(n);
            console.log(`PASS step ${n}`);
        } catch (e) {
            problems.push(`step ${n}: ${e.message}`);
            console.log(`FAIL step ${n}: ${e.message}`);
        }
    }
    await browser.close();
    console.log(problems.length ? `${problems.length} problems:\n${problems.join('\n')}` : 'all steps OK');
    process.exit(problems.length ? 1 : 0);
};

// ---------------------------------------------------------------- 1. supplier bill
steps[1] = async () => {
    const acc = await login('accountant');
    await goto(acc, '/payables/bills/create');
    const ref = `INV-${Date.now()}`;
    await pickLookup(acc, acc.locator('#supplier_id'), 'Grameenphone');
    await acc.fill('#supplier_reference', ref);
    await acc.fill('#line_0_description', 'September internet');
    await pickLookup(acc, acc.locator('#line_0_account'), '5420');
    await acc.fill('#line_0_net', '12000');
    await acc.locator('#line_0_net').blur();
    await acc.waitForTimeout(300);
    const vatHint = await acc.locator('#line_0_vat-hint').innerText().catch(() => '');
    if (!/at the category rate/.test(vatHint) || /^0\.00|^0 /.test(vatHint)) fail(`VAT not computed: "${vatHint}"`);
    await clickButton(acc, 'Save and send for approval');
    await acc.waitForURL(/\/payables\/bills\/[0-9a-f-]{36}$/, { timeout: 15000 });
    await expectStatus(acc, 'Pending approval', 'bill submitted');
    checkErrors(acc, 'accountant bill entry');
    const billUrl = new URL(acc.url()).pathname;

    const fm = await login('finance.manager');
    await goto(fm, billUrl);
    await clickButton(fm, 'Approve and post', { noWait: true });
    const label = await confirmPreview(fm, 'bill approve');
    if (!/Approve and post/.test(label)) fail(`unexpected confirm label ${label}`);
    await expectStatus(fm, 'Posted', 'bill approved');
    checkErrors(fm, 'finance.manager bill approve');
    console.log(`  bill ${billUrl} posted (${label})`);
};

// ---------------------------------------------------------------- 2. payment run
steps[2] = async () => {
    const acc = await login('accountant');
    await goto(acc, '/payables/payment-runs/create?due_by=2027-06-30');
    const boxes = acc.locator('tbody input[type=checkbox]');
    const n = await boxes.count();
    if (n === 0) fail(`no due bills listed: ${(await bodyText(acc)).slice(0, 300)}`);
    await clickButton(acc, 'Prepare and send for approval');
    await acc.waitForURL(/\/payables\/payment-runs\/[0-9a-f-]{36}$/, { timeout: 15000 });
    await expectStatus(acc, 'Pending approval', 'run submitted');
    checkErrors(acc, 'accountant run create');
    const runUrl = new URL(acc.url()).pathname;

    const fm = await login('finance.manager');
    await goto(fm, runUrl);
    await clickButton(fm, 'Approve run');
    await expectStatus(fm, 'Approved', 'run approved');
    checkErrors(fm, 'finance.manager run approve');

    const cfo = await login('cfo');
    await goto(cfo, runUrl);
    await clickButton(cfo, 'Release to bank', { noWait: true });
    await confirmPreview(cfo, 'run release');
    await expectStatus(cfo, 'Released', 'run released');
    const csv = await download(cfo, `${runUrl}/bank-file`, 'text/csv', 'bank file');
    if (!/Payee Name/.test(csv.toString())) fail(`bank file has no header: ${csv.toString().slice(0, 100)}`);
    checkErrors(cfo, 'cfo release');
    console.log(`  run ${runUrl} released, bank file ${csv.length} bytes`);
};

async function fillDate(page, selector, iso) {
    const input = page.locator(selector);
    await input.fill(iso);
    await input.press('Tab');
}

// ---------------------------------------------------------------- 3. fixed assets
steps[3] = async () => {
    const acc = await login('accountant');
    await goto(acc, '/fixed-assets');
    await clickButton(acc, 'Capitalise an asset', { noWait: true });
    const drawer = acc.locator('[role=dialog]').filter({ hasText: 'Capitalise an asset' });
    await drawer.waitFor({ state: 'visible' });
    await drawer.locator('#class_id').selectOption({ label: 'IT · Computers and IT equipment' });
    const desc = `Demo server ${Date.now()}`;
    await drawer.getByLabel('Description').fill(desc);
    await drawer.locator('#branch_id').selectOption({ label: 'HO · Head Office' });
    await drawer.getByLabel(/^Cost \(/).fill('150000');
    await drawer.getByLabel(/^Cost \(/).press('Tab');
    await drawer.getByRole('button', { name: /Review the journal/ }).click();
    await confirmPreview(acc, 'capitalise');
    await acc.waitForURL(/\/fixed-assets\/[0-9a-f-]{36}/, { timeout: 15000 });
    const assetUrl = new URL(acc.url()).pathname;
    await expectStatus(acc, 'In service', 'asset capitalised');
    checkErrors(acc, 'capitalise');
    await goto(acc, '/fixed-assets/register');
    await expectText(acc, desc, 'register shows new asset');
    checkErrors(acc, 'register');

    // dispose one HO asset (before September depreciation is posted) and move another to CTG
    await goto(acc, '/fixed-assets');
    const links = await acc.locator('main tbody a[href^="/fixed-assets/"]').evaluateAll((as) => as.map((a) => a.getAttribute('href')));
    const candidates = [...new Set(links)].filter((h) => h !== assetUrl);
    let disposed = null;
    let moved = null;
    for (const href of candidates) {
        await goto(acc, href);
        const hasDispose = await acc.getByRole('button', { name: 'Dispose', exact: true }).isVisible().catch(() => false);
        const hasMove = await acc.getByRole('button', { name: 'Move to another branch' }).isVisible().catch(() => false);
        const body = await bodyText(acc);
        if (!hasDispose || !hasMove || !/HO · Head Office|HO ·|Head Office/.test(body)) continue;
        if (!disposed) {
            await clickButton(acc, 'Dispose', { exact: true, noWait: true });
            const d = acc.locator('[role=dialog]').filter({ hasText: 'Dispose of the asset' });
            await d.waitFor({ state: 'visible' });
            await d.locator('#kind').selectOption('write_off');
            await d.getByLabel('Reason').fill('Beyond economic repair');
            await d.getByRole('button', { name: /Review the gain or loss/ }).click();
            await confirmPreview(acc, 'dispose');
            await expectStatus(acc, 'Disposed', 'asset disposed');
            checkErrors(acc, 'dispose');
            disposed = href;
            continue;
        }
        await clickButton(acc, 'Move to another branch', { noWait: true });
        const m = acc.locator('[role=dialog]').filter({ hasText: 'Move to another branch' });
        await m.waitFor({ state: 'visible' });
        await m.locator('#to_branch_id').selectOption({ label: 'CTG · Chittagong Branch' });
        await m.getByLabel('Reason').fill('Branch expansion');
        await m.getByRole('button', { name: /Review the journal/ }).click();
        await confirmPreview(acc, 'transfer');
        await acc.waitForLoadState('networkidle');
        await goto(acc, `${href}?tab=movements`);
        await expectText(acc, 'Branch expansion', 'movement recorded');
        checkErrors(acc, 'transfer');
        moved = href;
        break;
    }
    if (!disposed || !moved) fail(`could not find HO assets to dispose/move (disposed=${disposed}, moved=${moved})`);

    const fm = await login('finance.manager');
    await goto(fm, '/fixed-assets/depreciation');
    await fm.locator('select[aria-label="Month"]').selectOption({ label: 'September 2026' });
    await fm.waitForLoadState('networkidle');
    await assertNoErrorPage(fm);
    await clickButton(fm, 'Post depreciation', { noWait: true });
    const label = await confirmPreview(fm, 'depreciation');
    await expectStatus(fm, 'is posted', 'depreciation posted');
    checkErrors(fm, 'depreciation');
    console.log(`  asset ${assetUrl}; disposed ${disposed}; moved ${moved}; depreciation ${label.split('\n')[0]}`);
};

// ---------------------------------------------------------------- 4. budgets
steps[4] = async () => {
    const acc = await login('accountant');
    await goto(acc, '/budgets');
    await clickButton(acc, 'New budget', { noWait: true });
    const d = acc.locator('[role=dialog]').filter({ hasText: 'New budget' });
    await d.waitFor({ state: 'visible' });
    await d.getByRole('button', { name: /Create budget/ }).click();
    await acc.waitForURL(/\/budgets\/[0-9a-f-]{36}/, { timeout: 15000 });
    const budgetUrl = new URL(acc.url()).pathname;
    await acc.waitForLoadState('networkidle');
    await assertNoErrorPage(acc);
    let cells = acc.locator('input[inputmode=decimal][aria-label]');
    if ((await cells.count()) === 0) {
        await acc.locator('select[aria-label="Add an account"]').selectOption({ index: 1 });
        await clickButton(acc, 'Add', { exact: true, noWait: true });
        cells = acc.locator('input[inputmode=decimal][aria-label]');
        await cells.first().waitFor({ state: 'visible' });
    }
    for (let i = 0; i < Math.min(3, await cells.count()); i++) {
        await cells.nth(i).fill(String(25000 + i * 1000));
        await cells.nth(i).press('Tab');
    }
    await clickButton(acc, 'Save', { exact: true });
    await expectText(acc, 'Budget saved', 'grid saved');
    await clickButton(acc, 'Send for approval');
    await expectStatus(acc, 'Submitted', 'budget submitted');
    checkErrors(acc, 'budget prepare');

    const fm = await login('finance.manager');
    await goto(fm, budgetUrl);
    await clickButton(fm, 'Approve', { exact: true });
    await expectStatus(fm, 'Approved', 'budget approved');
    await goto(fm, '/budgets/variance');
    await expectText(fm, 'Budget variance', 'variance report');
    checkErrors(fm, 'budget approve/variance');
    console.log(`  budget ${budgetUrl} approved`);
};

// ---------------------------------------------------------------- 5. petty cash
steps[5] = async () => {
    const bm = await login('branch.manager');
    await goto(bm, '/petty-cash');
    const ho = (await bm.locator('main tbody tr').filter({ hasText: 'PC-HO' }).locator('a[href^="/petty-cash/"]').first().getAttribute('href')).split('?')[0];
    await goto(bm, ho);
    await clickButton(bm, 'Pay a voucher', { noWait: true });
    const d = bm.locator('[role=dialog]').filter({ hasText: 'Pay a voucher' });
    await d.waitFor({ state: 'visible' });
    await d.locator('#payee').fill('Tea stall');
    await d.locator('#description').fill('Office refreshments');
    await d.locator('#account_id').selectOption({ index: 1 });
    await d.locator('#amount').fill('450');
    await d.locator('#amount').press('Tab');
    await d.getByRole('button', { name: /Review the journal/ }).click();
    await confirmPreview(bm, 'voucher');
    await expectText(bm, 'Office refreshments', 'voucher listed');
    checkErrors(bm, 'voucher');

    const acc = await login('accountant');
    await goto(acc, `${ho}?tab=replenishments`);
    if (!/Pending approval/.test(await bodyText(acc))) {
        await clickButton(acc, 'Request replenishment', { noWait: true });
        const r = acc.locator('[role=dialog]').filter({ hasText: 'Request replenishment' });
        await r.waitFor({ state: 'visible' });
        await r.getByRole('button', { name: /Request replenishment/ }).click();
        await r.waitFor({ state: 'hidden', timeout: 15000 });
        await goto(acc, `${ho}?tab=replenishments`);
        await expectStatus(acc, 'Pending approval', 'replenishment requested');
    }
    checkErrors(acc, 'replenishment request');

    const fm = await login('finance.manager');
    await goto(fm, `${ho}?tab=replenishments`);
    await fm.getByRole('tab', { name: /Replenishments/ }).click().catch(() => undefined);
    await clickButton(fm, 'Approve', { exact: true, noWait: true });
    const a = fm.locator('[role=dialog]').filter({ hasText: 'Approve PCR' });
    await a.waitFor({ state: 'visible' });
    await a.getByRole('button', { name: /Review the journal/ }).click();
    await confirmPreview(fm, 'replenishment approve');
    await goto(fm, `${ho}?tab=replenishments`);
    await fm.getByRole('tab', { name: /Replenishments/ }).click().catch(() => undefined);
    await expectStatus(fm, 'Paid|Replenished', 'replenished');
    checkErrors(fm, 'replenishment approve');

    await goto(acc, ho);
    await clickButton(acc, 'Count the cash', { noWait: true });
    const c = acc.locator('[role=dialog]').filter({ hasText: 'Count the cash' });
    await c.waitFor({ state: 'visible' });
    await c.getByLabel(/^Cash counted \(/).fill('19900');
    await c.getByLabel(/^Cash counted \(/).press('Tab');
    await c.getByRole('button', { name: /^Review/ }).click();
    await confirmPreview(acc, 'cash count');
    await expectText(acc, 'Cash count recorded', 'count recorded');
    checkErrors(acc, 'cash count');
};

// ---------------------------------------------------------------- 6. people & payroll
steps[6] = async () => {
    const hr = await login('hr.manager');
    await goto(hr, '/people/employees');
    await clickButton(hr, 'Hire employee', { noWait: true });
    const d = hr.locator('[role=dialog]').filter({ hasText: 'Hire employee' });
    await d.waitFor({ state: 'visible' });
    const code = `EMP-T${String(Date.now()).slice(-5)}`;
    await d.locator('#full_name').fill('Demo Hire');
    await d.locator('#code').fill(code);
    await fillDate(d, '#joined_on', '2026-09-01');
    await d.locator('#department_id').selectOption({ index: 1 });
    await d.locator('#designation_id').selectOption({ index: 1 });
    await d.locator('#grade_id').selectOption({ index: 1 });
    await d.locator('#basic').fill('40000');
    await d.locator('#basic').press('Tab');
    await d.locator('#bank_name').fill('City Bank');
    await d.locator('#bank_branch').fill('Gulshan');
    await d.locator('#routing_no').fill('225260123');
    await d.locator('#account_no').fill('1234567890123');
    await d.getByRole('button', { name: /^Hire/ }).click();
    await hr.waitForURL(/\/people\/employees\/[0-9a-f-]{36}/, { timeout: 15000 });
    await assertNoErrorPage(hr);
    checkErrors(hr, 'hire');

    await goto(hr, '/people/payroll');
    const sepRow = hr.locator('main tbody tr').filter({ hasText: 'September 2026' }).first();
    let runUrl;
    let status = 'none';
    if (await sepRow.count()) {
        status = /Paid/.test(await sepRow.innerText()) ? 'paid' : /Posted/.test(await sepRow.innerText()) ? 'posted' : 'preview';
        await sepRow.locator('a').first().click();
        await hr.waitForURL(/\/people\/payroll\/[0-9a-f-]{36}/, { timeout: 15000 });
        runUrl = new URL(hr.url()).pathname;
        if (status === 'preview') await clickButton(hr, 'Recalculate');
    } else {
        await hr.locator('#payroll_month').selectOption({ label: 'September 2026' });
        await clickButton(hr, 'Calculate payroll');
        await hr.waitForURL(/\/people\/payroll\/[0-9a-f-]{36}/, { timeout: 15000 });
        runUrl = new URL(hr.url()).pathname;
        status = 'preview';
    }
    if (status === 'preview') {
        await expectStatus(hr, 'Preview', 'payroll preview');
        await expectText(hr, 'Demo Hire', 'new hire in payroll');
    } else {
        console.log(`  September payroll already ${status} (re-run): skipping the steps before it`);
    }
    checkErrors(hr, 'payroll calculate');

    if (status === 'preview') {
        const fm = await login('finance.manager');
        await goto(fm, runUrl);
        await clickButton(fm, 'Approve and post', { noWait: true });
        await confirmPreview(fm, 'payroll approve');
        await expectStatus(fm, 'Posted', 'payroll posted');
        checkErrors(fm, 'payroll approve');
        status = 'posted';
    }

    const acc = await login('accountant');
    await goto(acc, runUrl);
    if (status === 'posted') {
        await acc.locator('#pay_from').selectOption({ index: 0 }).catch(() => undefined);
        await clickButton(acc, 'Release salaries', { noWait: true });
        await confirmPreview(acc, 'payroll pay');
    }
    await expectStatus(acc, 'Paid', 'payroll paid');
    await goto(acc, `/people/payslips?run=${runUrl.split('/').pop()}`);
    const slip = await acc.locator('main tbody tr').first();
    await slip.click();
    const pdfLink = acc.locator('a[href*="/people/payslips/"][href$="/pdf"]').first();
    await pdfLink.waitFor({ state: 'visible', timeout: 10000 });
    const pdfUrl = await pdfLink.getAttribute('href');
    await download(acc, pdfUrl, 'application/pdf', 'payslip pdf');
    checkErrors(acc, 'payroll pay/payslip');
    console.log(`  payroll ${runUrl} paid; payslip ${pdfUrl}`);
};

// ---------------------------------------------------------------- 7. reinsurance
steps[7] = async () => {
    const fm = await login('finance.manager');
    await goto(fm, '/reinsurance/treaties/create');
    const code = `ENG-QS-${String(Date.now()).slice(-6)}`;
    await fm.fill('#code', code);
    await fm.fill('#name', 'Engineering quota share (demo)');
    const classes = await fm.locator('#class_code option').evaluateAll((os) => os.map((o) => o.value));
    const cls = classes.find((c) => /eng/i.test(c)) ?? classes[classes.length - 1];
    await fm.locator('#class_code').selectOption(cls);
    const year = 2027 + (Date.now() % 60); // one active treaty per class and underwriting year: keep re-runs distinct
    await fm.fill('#underwriting_year', String(year));
    await fillDate(fm, '#period_from', `${year}-07-01`);
    await fillDate(fm, '#period_to', `${year + 1}-06-30`);
    await fm.fill('#cession_percent', '40.00');
    await fm.locator('#participant_0').selectOption({ index: 0 });
    await fm.fill('#share_0', '100.00');
    await fm.getByRole('button', { name: /Create treaty/ }).click();
    await fm.waitForURL(/\/reinsurance\/treaties\/[0-9a-f-]{36}/, { timeout: 15000 }).catch(async () => fail(`treaty not saved; alerts: ${(await fm.locator('[role=alert]').allInnerTexts()).join(' | ')}`));
    await assertNoErrorPage(fm);
    await expectText(fm, code, 'treaty saved');
    checkErrors(fm, 'treaty');

    // facultative on a policy that still has capacity to place
    await goto(fm, '/reinsurance/cessions');
    let policyHref = await fm.locator('main a[href^="/policies/"]').first().getAttribute('href').catch(() => null);
    if (!policyHref) {
        await goto(fm, '/policies');
        policyHref = await fm.locator('main tbody a[href^="/policies/"]').first().getAttribute('href');
    }
    policyHref = policyHref.split('?')[0];
    await goto(fm, `${policyHref}?tab=reinsurance`);
    await fm.getByRole('tab', { name: 'Reinsurance' }).click().catch(() => undefined);
    await clickButton(fm, 'Place facultative', { noWait: true });
    const d = fm.locator('[role=dialog]').filter({ hasText: 'Place facultative' });
    await d.waitFor({ state: 'visible' });
    await d.locator('#fac_reinsurer').selectOption({ index: 0 });
    await d.locator('#fac_share').fill('1.00');
    await d.locator('#fac_premium').fill('25000');
    await d.locator('#fac_premium').press('Tab');
    await d.locator('#fac_slip').fill(`FAC/DEMO/${Date.now()}`);
    await d.getByRole('button', { name: /Review and place/ }).click();
    await confirmPreview(fm, 'facultative');
    await fm.waitForLoadState('networkidle');
    await goto(fm, `${policyHref}?tab=reinsurance`);
    await expectText(fm, 'FAC/DEMO/', 'facultative listed');
    checkErrors(fm, 'facultative');

    await goto(fm, '/reinsurance/statements');
    await clickButton(fm, 'Prepare statement', { noWait: true });
    const s = fm.locator('[role=dialog]').filter({ hasText: 'Prepare a reinsurer statement' });
    await s.waitFor({ state: 'visible' });
    await s.locator('#reinsurer_id').selectOption({ index: await s.locator('#reinsurer_id option').count() - 1 });
    await s.locator('#quarter').selectOption('3');
    await s.getByRole('button', { name: /Prepare statement/ }).click();
    await fm.waitForURL(/\/reinsurance\/statements\/[0-9a-f-]{36}/, { timeout: 15000 });
    await assertNoErrorPage(fm);
    checkErrors(fm, 'statement');

    await goto(fm, '/reports/ri-premium-bordereau?from=2026-07-01&to=2026-09-30');
    await download(fm, '/reports/ri-premium-bordereau/export?from=2026-07-01&to=2026-09-30', '', 'bordereau export');
    checkErrors(fm, 'bordereau');
    console.log(`  treaty ${code}; facultative on ${policyHref}`);
};

// ---------------------------------------------------------------- 8. regulatory
steps[8] = async () => {
    const cfo = await login('cfo');
    await goto(cfo, '/regulatory');
    await goto(cfo, '/regulatory/returns?period=2026-Q4');
    await clickButton(cfo, /Generate (returns|again)/);
    await expectText(cfo, /returns generated for Q4 2026|already filed/, 'Q4 generated');
    checkErrors(cfo, 'generate returns');
    // review one Q4 form
    const forms = cfo.locator('nav[aria-label="Forms"] button');
    const count = await forms.count();
    let reviewed = false;
    for (let i = 0; i < count && !reviewed; i++) {
        await forms.nth(i).click();
        await cfo.waitForLoadState('networkidle');
        const btn = cfo.getByRole('button', { name: 'Mark reviewed' });
        if (await btn.isVisible().catch(() => false)) {
            await btn.click();
            await expectText(cfo, 'Return marked reviewed', 'Q4 review');
            reviewed = true;
        }
    }
    if (!reviewed) fail('no Q4 return could be marked reviewed');
    checkErrors(cfo, 'review return');
    // filing needs a date inside the return's period and not after today, so a Q4 return cannot be filed before 1 Oct: file a Q3 one
    await goto(cfo, '/regulatory/returns?period=2026-Q3');
    const q3forms = cfo.locator('nav[aria-label="Forms"] button');
    let filed = false;
    for (let i = 0; i < (await q3forms.count()) && !filed; i++) {
        await q3forms.nth(i).click();
        await cfo.waitForURL(/[?&]form=/, { timeout: 15000 });
        await cfo.waitForLoadState('networkidle');
        const review = cfo.getByRole('button', { name: 'Mark reviewed' });
        let visible = false;
        if (await review.isVisible().catch(() => false)) {
            await review.click();
            await expectText(cfo, 'Return marked reviewed', 'Q3 review');
            visible = await cfo.locator('#filing_reference').waitFor({ state: 'visible', timeout: 15000 }).then(() => true, () => false);
        } else {
            visible = await cfo.locator('#filing_reference').isVisible().catch(() => false);
        }
        const ref = cfo.locator('#filing_reference');
        console.log(`  Q3 form ${i}: ${(await q3forms.nth(i).innerText()).replace(/\s+/g, ' ')} -> filing form ${visible ? 'shown' : 'hidden'}`);
        if (visible) {
            await ref.fill(`IDRA/NL/2026/Q3/DEMO-${String(Date.now()).slice(-4)}`);
            await clickButton(cfo, 'Mark filed');
            await expectText(cfo, 'Return marked filed', 'Q3 filed');
            filed = true;
        }
    }
    if (!filed) fail('no Q3 return could be filed');
    checkErrors(cfo, 'file return');

    const fm = await login('finance.manager');
    await goto(fm, '/regulatory/provisions?quarter=2026-Q4');
    if (!/Approve and post|Reviewed/.test(await bodyText(fm))) {
        await clickButton(fm, /Prepare run|Recalculate/);
        await expectText(fm, 'calculated as a draft', 'Q4 provisions prepared');
        await clickButton(fm, 'Mark reviewed');
        await expectText(fm, 'Run marked reviewed', 'Q4 provisions reviewed');
    }
    checkErrors(fm, 'provisions prepare');

    await goto(cfo, '/regulatory/provisions?quarter=2026-Q4');
    await clickButton(cfo, 'Approve and post', { noWait: true });
    await confirmPreview(cfo, 'provisions approve');
    await expectStatus(cfo, 'Posted', 'provisions posted');
    checkErrors(cfo, 'provisions approve');
};

// ---------------------------------------------------------------- 9. month-end close
steps[9] = async () => {
    const fm = await login('finance.manager');
    await goto(fm, '/close');
    await fm.locator('main tbody tr').filter({ hasText: 'Sep 2026' }).first().click();
    const start = fm.getByRole('button', { name: 'Start close' });
    if (await start.isVisible().catch(() => false)) {
        await start.click();
    } else {
        await clickButton(fm, 'Open the checklist');
    }
    await fm.waitForURL(/\/close\/runs\/[0-9a-f-]{36}/, { timeout: 15000 });
    const runUrl = new URL(fm.url()).pathname;
    await assertNoErrorPage(fm);
    const attempted = new Set();
    const outcomes = [];
    for (let guard = 0; guard < 40; guard++) {
        await goto(fm, runUrl);
        const rows = fm.locator('ol[aria-label="Close tasks"] > li').filter({ has: fm.getByRole('button', { name: 'Work on it' }) });
        let target = null;
        for (let i = 0; i < (await rows.count()); i++) {
            const name = (await rows.nth(i).innerText()).split('\n').map((s) => s.trim()).filter(Boolean)[1] ?? String(i);
            if (!attempted.has(name)) { target = { row: rows.nth(i), name }; break; }
        }
        if (!target) break;
        attempted.add(target.name);
        await target.row.getByRole('button', { name: 'Work on it' }).click();
        const runBtn = target.row.getByRole('button', { name: /Run the task|Review and run/ });
        await runBtn.waitFor({ state: 'visible', timeout: 5000 });
        if (await runBtn.isDisabled()) { outcomes.push(`${target.name}: waits (${await runBtn.getAttribute('title')})`); continue; }
        const label = (await runBtn.innerText()).trim();
        await runBtn.click();
        if (/Review and run/.test(label)) {
            const dialog = fm.locator('[role=dialog]').filter({ hasText: 'Back to the form' });
            await dialog.waitFor({ state: 'visible', timeout: 15000 });
            const alerts = await dialog.locator('[role=alert]').allInnerTexts();
            if (alerts.length) { outcomes.push(`${target.name}: preview refused (${alerts.join('; ').slice(0, 120)})`); await dialog.getByRole('button', { name: /Back to the form/ }).click(); continue; }
            await dialog.locator('button').last().click();
            await dialog.waitFor({ state: 'hidden', timeout: 30000 });
        }
        await fm.waitForLoadState('networkidle').catch(() => undefined);
        await assertNoErrorPage(fm);
        await fm.waitForTimeout(500);
        const body = await bodyText(fm);
        const m = body.match(/Task done|Task blocked[^.]*|Task skipped/);
        outcomes.push(`${target.name}: ${m ? m[0] : 'ran'}`);
        checkErrors(fm, `close task ${target.name}`);
    }
    console.log(`  close ${runUrl}\n   ${outcomes.join('\n   ')}`);
    checkErrors(fm, 'close');
};

//__MORE_STEPS__

await run();
