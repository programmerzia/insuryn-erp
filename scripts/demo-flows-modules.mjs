// Demo flow verification, part 2: sales, collections, claims, accounting and admin through the browser as the right roles
// (scripts/demo-flows.mjs covers payables, fixed assets, budgets, petty cash, people, reinsurance, regulatory and the close).
// Usage: node scripts/demo-flows-modules.mjs http://nonlife.localhost:8784 [step numbers...]
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
    const context = await browser.newContext({ acceptDownloads: true, viewport: { width: 1440, height: 900 } });
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

async function assertNoErrorPage(page) {
    const text = await page.locator('body').innerText().catch(() => '');
    if (/Whoops|Server Error|Internal Server Error|SQLSTATE|Exception|Page Expired/.test(text)) fail(`${page.role} error page at ${page.url()}: ${text.slice(0, 200).replace(/\s+/g, ' ')}`);
}

function checkErrors(page, label) {
    if (page.errorsSeen.length) {
        const list = page.errorsSeen.splice(0);
        fail(`${label}: ${list.join(' | ')}`);
    }
}

/** Type into a LookupInput (role=combobox) and pick the first matching option. */
async function pickLookup(page, input, text) {
    await input.click();
    await input.fill(text);
    const option = page.locator('[role=listbox] [role=option]').first();
    await option.waitFor({ state: 'visible', timeout: 10000 }).catch(() => fail(`lookup "${text}": no option offered`));
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
    await button.waitFor({ state: 'visible', timeout: 10000 }).catch(() => fail(`${page.role}: no button "${text}" on ${page.url()}`));
    await button.click();
    if (!opts.noWait) await page.waitForLoadState('networkidle').catch(() => undefined);
}

async function expectText(page, text, label) {
    const locator = page.getByText(text, { exact: false }).first();
    await locator.waitFor({ state: 'visible', timeout: 15000 }).catch(() => fail(`${label}: expected "${text}" on ${page.url()}`));
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
    fail(`${label}: expected /${text}/ on ${page.url()} — body: ${(await bodyText(page)).slice(0, 400)}`);
}

async function fillDate(page, selector, iso) {
    const input = page.locator(selector);
    await input.fill(iso);
    await input.press('Tab');
}

/** A drawer or dialog by the text it shows. */
function panel(page, text) {
    return page.locator('[role=dialog]').filter({ hasText: text }).first();
}

async function alertsOf(scope) {
    return (await scope.locator('[role=alert]').allInnerTexts()).map((t) => t.trim()).filter(Boolean);
}

const steps = {};
const stamp = String(Date.now()).slice(-6);
const state = {};

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
            for (const [role, page] of sessions) {
                await page.screenshot({ path: `storage/flow-audit/modules-step-${n}-${role}.png` }).catch(() => undefined);
            }
        }
    }
    await browser.close();
    console.log(problems.length ? `${problems.length} problems:\n${problems.join('\n')}` : 'all steps OK');
    process.exit(problems.length ? 1 : 0);
};

// ---------------------------------------------------------------- 1. sales: quote → proposal → cover note → policy
steps[1] = async () => {
    const bo = await login('branch.officer');
    await goto(bo, '/quotations');
    await bo.getByRole('link', { name: 'New quote' }).first().click();
    await bo.waitForURL(/\/quotations\/create/, { timeout: 15000 });
    await bo.waitForLoadState('networkidle').catch(() => undefined);
    const branch = await bo.locator('#branch_id option').filter({ hasText: 'Head Office' }).first().getAttribute('value').catch(() => null);
    if (branch) await bo.locator('#branch_id').selectOption(branch);
    const motor = await bo.locator('#product_id option').filter({ hasText: 'Motor' }).first().getAttribute('value');
    await bo.locator('#product_id').selectOption(motor);
    const inception = bo.locator('#inception input, input#inception').first();
    if ((await inception.inputValue()) === '') await fillDate(bo, '#inception input, input#inception', '2026-09-15');
    await pickLookup(bo, bo.locator('#customer_party_id'), 'Karim');
    await pickLookup(bo, bo.locator('#producer_id'), 'AG-001');
    const vehicleType = bo.locator('#risk_vehicle_type');
    if (await vehicleType.count()) await vehicleType.selectOption('private');
    for (const [field, value] of [['registration_no', `DHA-METRO-GA-${stamp}`], ['chassis_no', `CH-${stamp}`], ['engine_cc', '1500'], ['seats', '5'], ['year_of_manufacture', '2021'], ['driver_age', '38'], ['sum_insured', '400000']]) {
        const input = bo.locator(`#risk_${field}`);
        if ((await input.count()) === 0) continue;
        const label = await bo.locator(`label[for="risk_${field}"]`).innerText().catch(() => '');
        if (field === 'chassis_no' && /optional/i.test(label)) continue;
        if ((await input.inputValue()) === '') { await input.fill(value); await input.press('Tab'); }
    }
    await bo.getByText(/Gross premium/).first().waitFor({ timeout: 15000 });
    await clickButton(bo, 'Issue quotation');
    await clickButton(bo, /make proposal/i, { noWait: true });
    await bo.getByRole('button', { name: 'Make proposal', exact: true }).click();
    await bo.waitForURL(/\/proposals\//, { timeout: 20000 });
    await assertNoErrorPage(bo);
    state.proposalUrl = new URL(bo.url()).pathname;
    await clickButton(bo, 'Verify identity', { noWait: true });
    await bo.locator('#id_number').fill('1990123456789');
    await clickButton(bo, 'Record verification');
    checkErrors(bo, 'quote → proposal');

    // Submit; the proposal must be approved before a cover note or the policy is issued.
    await clickButton(bo, 'Submit to underwriting', { noWait: true });
    const chassis = bo.locator('#detail_chassis_no');
    if (await chassis.count()) {
        if ((await chassis.inputValue()) === '') await chassis.fill(`CH-${stamp}`);
        await clickButton(bo, 'Save details');
        await clickButton(bo, 'Submit to underwriting', { noWait: true });
    }
    await bo.getByRole('button', { name: 'Submit proposal', exact: true }).click();
    await bo.waitForLoadState('networkidle').catch(() => undefined);
    // The decision shows in the header once the submission has been processed.
    await bo.getByText(/Auto approved|Referred|Approved/).first().waitFor({ timeout: 15000 }).catch(() => undefined);
    const referred = !(await bo.getByRole('button', { name: 'Issue policy' }).count());
    if (referred) {
        // Above the officer's limit: the branch manager decides it in Referrals.
        const proposalNumber = (await bo.locator('h1').first().innerText()).trim();
        const bm = await login('branch.manager');
        await goto(bm, '/underwriting/referrals');
        const row = bm.locator('main tbody tr').filter({ hasText: proposalNumber }).first();
        await row.waitFor({ state: 'visible', timeout: 10000 }).catch(() => fail(`referral ${proposalNumber}: not in the referral queue`));
        await row.click();
        const approveRef = bm.locator('aside').getByRole('button', { name: /^Approve/ }).first();
        await approveRef.waitFor({ state: 'visible', timeout: 10000 }).catch(async () => fail(`referral ${proposalNumber}: no Approve in the inspector (${(await bm.locator('aside').allInnerTexts()).join(' ').replace(/\s+/g, ' ').slice(0, 200)})`));
        await approveRef.click();
        const decide = panel(bm, 'Approve');
        if (await decide.waitFor({ state: 'visible', timeout: 3000 }).then(() => true, () => false)) await decide.getByRole('button', { name: /^Approve/ }).last().click();
        await bm.waitForLoadState('networkidle').catch(() => undefined);
        checkErrors(bm, 'referral decision');
        await goto(bo, state.proposalUrl);
    }

    // Cover note from the approved proposal (cover_note.issue).
    await clickButton(bo, 'Issue cover note', { noWait: true });
    const cn = panel(bo, 'Issue a cover note');
    await cn.waitFor({ state: 'visible' });
    const received = cn.locator('#cover_premium_received');
    if (await received.count() && !(await received.isChecked())) await received.check();
    const ref = cn.locator('#cover_premium_reference');
    if (await ref.count() && await ref.isVisible()) await ref.fill(`CN-${stamp}`);
    await cn.getByRole('button', { name: 'Issue cover note' }).click();
    await cn.waitFor({ state: 'hidden', timeout: 20000 }).catch(async () => fail(`cover note: drawer stayed open: ${(await alertsOf(cn)).join(' | ')}`));
    await bo.waitForLoadState('networkidle').catch(() => undefined);
    await expectText(bo, /CVN-|Cover note/i, 'cover note listed on the proposal');
    checkErrors(bo, 'cover note');

    // Issue the policy.
    await clickButton(bo, 'Issue policy', { noWait: true });
    const issue = panel(bo, 'Issue the policy');
    await issue.waitFor({ state: 'visible' });
    const pr = issue.locator('#premium_received');
    if (await pr.count() && !(await pr.isChecked())) await pr.check();
    const prRef = issue.locator('#premium_reference');
    if (await prRef.count() && await prRef.isVisible()) await prRef.fill(`RCPT-${stamp}`);
    await issue.getByRole('button', { name: /^Review and issue/ }).click();
    await confirmPreview(bo, 'issue policy');
    await bo.waitForURL(/\/policies\/[0-9a-f-]{36}/, { timeout: 30000 });
    state.policyUrl = new URL(bo.url()).pathname;
    state.policyNumber = (await bo.locator('h1').first().innerText()).trim();
    checkErrors(bo, 'issue policy');
    console.log(`  ${state.policyNumber} issued from ${state.proposalUrl} with a cover note`);
};

// ---------------------------------------------------------------- 2. collections: receipt on the new policy; endorse; cancel; refund request and release
steps[2] = async () => {
    if (!state.policyUrl) fail('needs step 1');
    const bm = await login('branch.manager');
    await goto(bm, state.policyUrl);
    // "Record receipt" is offered while premium is outstanding; a policy issued with the premium received has its receipt already.
    const recordReceipt = bm.getByRole('link', { name: 'Record receipt' }).or(bm.getByRole('button', { name: 'Record receipt' })).first();
    if (await recordReceipt.count()) {
        await recordReceipt.click();
        await bm.waitForURL(/\/receipts\/create/, { timeout: 15000 });
        await bm.waitForLoadState('networkidle').catch(() => undefined);
        await clickButton(bm, 'Review and post', { noWait: true });
        await confirmPreview(bm, 'receipt');
        await bm.waitForURL(/\/receipts\/[0-9a-f-]{36}/, { timeout: 30000 }).catch(() => undefined);
        await assertNoErrorPage(bm);
        checkErrors(bm, 'record receipt');
        console.log(`  receipt ${new URL(bm.url()).pathname}`);
    } else {
        fail(`record receipt: not offered on ${state.policyUrl} (${(await bodyText(bm)).slice(0, 200)})`);
    }

    await goto(bm, state.policyUrl);
    await clickButton(bm, 'Endorse', { noWait: true, exact: true });
    const en = panel(bm, 'Endorse');
    await en.waitFor({ state: 'visible' });
    if (await en.locator('#premium_delta').count()) {
        // An unrated policy: the premium change is typed.
        await en.locator('#premium_delta').fill('500');
        await en.locator('#premium_delta').press('Tab');
        await en.locator('#reason').fill('Added a named driver');
    } else {
        // A rated policy: the risk is re-rated — raise the sum insured.
        await en.locator('#endorse_reason').fill('Sum insured raised');
        await en.locator('#endorse_sum_insured').fill('450000');
        await en.locator('#endorse_sum_insured').press('Tab');
    }
    await en.getByRole('button', { name: 'Review and post' }).click();
    await confirmPreview(bm, 'endorsement');
    await expectStatus(bm, 'Endorsement', 'endorsement in the transactions');
    checkErrors(bm, 'endorse');

    await goto(bm, state.policyUrl);
    await clickButton(bm, 'Cancel policy', { noWait: true });
    const ca = panel(bm, 'Cancel');
    await ca.waitFor({ state: 'visible' });
    await ca.locator('#cancel_reason').fill('Vehicle sold');
    await ca.getByRole('button', { name: 'Review cancellation' }).click();
    await confirmPreview(bm, 'cancellation');
    await expectStatus(bm, 'Cancelled', 'policy cancelled');
    checkErrors(bm, 'cancel');

    await goto(bm, '/refunds');
    await clickButton(bm, 'Request a refund', { noWait: true });
    const rf = panel(bm, 'Request a refund');
    await rf.waitFor({ state: 'visible' });
    await pickLookup(bm, rf.locator('#policy_id'), state.policyNumber);
    await bm.waitForTimeout(500);
    if ((await rf.locator('#amount').inputValue()) === '') fail('refund: no refundable amount proposed for the cancelled policy');
    await rf.locator('#reason').fill('Policy cancelled, premium unearned');
    await rf.getByRole('button', { name: 'Request refund' }).click();
    await rf.waitFor({ state: 'hidden', timeout: 20000 }).catch(async () => fail(`refund request: ${(await alertsOf(rf)).join(' | ')}`));
    await bm.waitForLoadState('networkidle').catch(() => undefined);
    checkErrors(bm, 'refund request');

    const fm = await login('finance.manager');
    await goto(fm, '/refunds');
    const row = fm.locator('main tbody tr').filter({ hasText: state.policyNumber }).first();
    await row.waitFor({ state: 'visible', timeout: 10000 });
    await row.click();
    await clickButton(fm, 'Pay refund', { noWait: true });
    await confirmPreview(fm, 'refund release');
    await expectStatus(fm, 'Paid|Released', 'refund paid');
    checkErrors(fm, 'refund release');
    console.log(`  ${state.policyNumber}: receipt, endorsement, cancellation, refund requested and paid`);
};

// ---------------------------------------------------------------- 3. collections: cheque receipt and bounce; agent cash deposit
steps[3] = async () => {
    const bo = await login('branch.officer');
    await goto(bo, '/receipts/create');
    await bo.locator('#amount').fill('5000');
    await bo.locator('#amount').press('Tab');
    await bo.locator('select#channel, #channel select').first().selectOption('cheque');
    await bo.locator('#cheque_no').fill(`CHQ${stamp}`);
    await bo.locator('#cheque_bank').fill('BRAC Bank');
    await fillDate(bo, '#cheque_date', '2026-09-14');
    await bo.locator('#reference').fill(`Cheque test ${stamp}`);
    await clickButton(bo, 'Review and post', { noWait: true });
    await confirmPreview(bo, 'cheque receipt');
    await bo.waitForURL(/\/receipts\/[0-9a-f-]{36}/, { timeout: 30000 });
    state.chequeReceipt = new URL(bo.url()).pathname;
    checkErrors(bo, 'cheque receipt');

    const bm = await login('branch.manager');
    await goto(bm, state.chequeReceipt);
    await clickButton(bm, 'Cheque bounced', { noWait: true });
    const b = panel(bm, 'Record a bounced cheque');
    await b.waitFor({ state: 'visible' });
    await b.locator('#bounce_reason').fill('Insufficient funds');
    await b.getByRole('button', { name: /^Review/ }).click();
    await confirmPreview(bm, 'cheque bounce');
    await expectStatus(bm, 'bounced', 'receipt bounced');
    checkErrors(bm, 'cheque bounce');

    // Cash collected by an agent must be allocated in full: the branch manager (receipt.allocate) records it, then deposits it.
    await goto(bm, '/receipts/create');
    await bm.locator('select#channel, #channel select').first().selectOption('cash');
    await pickLookup(bm, bm.locator('#collected_by_agent_id'), 'AG-001');
    if (!(await bm.locator('#allocation-0').count())) await clickButton(bm, 'Add an installment', { noWait: true });
    await pickLookup(bm, bm.locator('#allocation-0'), 'POL-HO-2026-000007');
    await bm.waitForTimeout(500);
    const installment = (await bm.locator('#allocation-amount-0').inputValue()).replace(/,/g, '');
    if (!installment || Number(installment) <= 0) fail(`agent cash: the installment lookup proposed no amount ("${installment}")`);
    await bm.locator('#amount').fill(installment);
    await bm.locator('#amount').press('Tab');
    await clickButton(bm, 'Review and post', { noWait: true });
    await confirmPreview(bm, 'cash receipt by agent');
    await bm.waitForURL(/\/receipts\/[0-9a-f-]{36}/, { timeout: 30000 });
    checkErrors(bm, 'agent cash receipt');

    await goto(bm, '/agent-cash');
    await clickButton(bm, 'Record a deposit', { noWait: true });
    const d = panel(bm, 'Record a deposit');
    await d.waitFor({ state: 'visible' });
    await pickLookup(bm, d.locator('#agent_id'), 'AG-001');
    await d.locator('#amount').fill(installment);
    await d.locator('#amount').press('Tab');
    await d.locator('#reference').fill(`DEP-${stamp}`);
    await d.getByRole('button', { name: 'Review and post' }).click();
    await confirmPreview(bm, 'agent deposit');
    checkErrors(bm, 'agent deposit');
    console.log(`  cheque ${state.chequeReceipt} bounced; AG-001 collected ${installment} in cash and deposited it`);
};

// ---------------------------------------------------------------- 4. collections: commission statements prepare → approve → pay
steps[4] = async () => {
    const fm = await login('finance.manager');
    await goto(fm, '/distribution/statements');
    await clickButton(fm, 'Prepare statements', { noWait: true });
    const again = fm.getByRole('button', { name: 'Prepare again' });
    if (await again.waitFor({ state: 'visible', timeout: 2000 }).then(() => true, () => false)) await again.click();
    await fm.waitForLoadState('networkidle').catch(() => undefined);
    await assertNoErrorPage(fm);
    checkErrors(fm, 'prepare statements');
    const rows = fm.locator('main tbody tr').filter({ hasText: /Draft/ });
    if ((await rows.count()) === 0) {
        const anyRows = await fm.locator('main tbody tr').allInnerTexts();
        console.log(`  no draft statement after preparing (rows: ${anyRows.map((r) => r.replace(/\s+/g, ' ').slice(0, 80)).join(' / ') || 'none'})`);
        return;
    }
    await fm.waitForTimeout(1000);
    await rows.first().click();
    const approveBtn = fm.locator('aside').getByRole('button', { name: /^Approve/ }).first();
    await approveBtn.waitFor({ state: 'visible', timeout: 10000 }).catch(async () => fail(`approve statement: no Approve button in the inspector (aside: ${(await fm.locator('aside').allInnerTexts()).join(' ').replace(/\s+/g, ' ').slice(0, 300)})`));
    await fm.waitForTimeout(500);
    await approveBtn.click();
    const preview = fm.locator('[role=dialog]').filter({ hasText: 'Back to the form' });
    if (!(await preview.waitFor({ state: 'visible', timeout: 5000 }).then(() => true, () => false))) await approveBtn.click().catch(() => undefined);
    await confirmPreview(fm, 'approve statement');
    await expectStatus(fm, 'Approved', 'statement approved');
    checkErrors(fm, 'approve statement');
    const acc = await login('accountant');
    await goto(acc, '/distribution/statements');
    const approved = acc.locator('main tbody tr').filter({ hasText: /Approved/ }).first();
    await approved.waitFor({ state: 'visible', timeout: 10000 });
    await approved.click();
    const payBtn = acc.locator('aside').getByRole('button', { name: /^Pay/ }).first();
    await payBtn.waitFor({ state: 'visible', timeout: 10000 }).catch(async () => fail(`pay statement: no Pay button in the inspector (aside: ${(await acc.locator('aside').allInnerTexts()).join(' ').replace(/\s+/g, ' ').slice(0, 300)})`));
    await payBtn.click();
    await confirmPreview(acc, 'pay statement');
    await expectStatus(acc, 'Paid', 'statement paid');
    checkErrors(acc, 'pay statement');
    console.log('  commission statement prepared, approved and paid');
};

// ---------------------------------------------------------------- 5. claims: register → reserve → approve → release/pay → recovery → close
steps[5] = async () => {
    const co = await login('claims.officer');
    await goto(co, '/claims');
    await co.getByRole('link', { name: 'Register a claim' }).first().click();
    await co.waitForURL(/\/claims\/create/, { timeout: 15000 });
    await co.waitForLoadState('networkidle').catch(() => undefined);
    await pickLookup(co, co.locator('#policy_id'), 'POL-HO-2026-000003');
    await clickButton(co, 'Continue', { noWait: true });
    await fillDate(co, '#loss_date', '2026-09-10');
    await co.locator('#description').fill(`Water damage at the insured premises (${stamp})`);
    await clickButton(co, 'Continue', { noWait: true });
    await clickButton(co, 'Register claim', { noWait: true });
    await co.waitForURL(/\/claims\/[0-9a-f-]{36}/, { timeout: 30000 }).catch(async () => fail(`register claim: ${(await alertsOf(co)).join(' | ') || co.url()}`));
    state.claimUrl = new URL(co.url()).pathname;
    checkErrors(co, 'register claim');

    await clickButton(co, 'Set reserve', { noWait: true });
    const r = panel(co, 'Set the case reserve');
    await r.waitFor({ state: 'visible' });
    await r.locator('#reserve').fill('60000');
    await r.locator('#reserve').press('Tab');
    await r.locator('#reserve_reason').fill('Surveyor estimate');
    await r.getByRole('button', { name: 'Review and post' }).click();
    await confirmPreview(co, 'reserve');
    await expectStatus(co, '60,000', 'reserve shown');
    checkErrors(co, 'reserve');

    const cm = await login('claims.manager');
    await goto(cm, state.claimUrl);
    await clickButton(cm, 'Approve payment', { noWait: true });
    const p = panel(cm, 'Approve a payment');
    await p.waitFor({ state: 'visible' });
    await p.locator('#payment_amount').fill('45000');
    await p.locator('#payment_amount').press('Tab');
    await p.getByRole('button', { name: 'Review and approve' }).click();
    await confirmPreview(cm, 'approve payment');
    checkErrors(cm, 'approve payment');
    await goto(cm, state.claimUrl);
    const request = cm.getByRole('button', { name: 'Request release' });
    if (await request.count()) {
        await request.first().click();
        await cm.waitForLoadState('networkidle').catch(() => undefined);
    } else {
        console.log(`  no "Request release" after approval (body: ${(await bodyText(cm)).slice(0, 300)})`);
    }
    checkErrors(cm, 'request release');

    const fm = await login('finance.manager');
    await goto(fm, state.claimUrl);
    const pay = fm.getByRole('button', { name: 'Pay', exact: true });
    if (!(await pay.count())) fail(`claim pay: no Pay button for the finance manager (body: ${(await bodyText(fm)).slice(0, 300)})`);
    await pay.first().click();
    const rel = panel(fm, 'Pay the claim');
    await rel.waitFor({ state: 'visible' });
    await rel.getByRole('button', { name: 'Review and pay' }).click();
    await confirmPreview(fm, 'pay claim');
    await expectStatus(fm, 'paid', 'claim payment paid');
    checkErrors(fm, 'pay claim');

    // A recovery is money received (receipt.create): the accountant records it once the claim is paid.
    const acc = await login('accountant');
    await goto(acc, state.claimUrl);
    await clickButton(acc, 'Record recovery', { noWait: true });
    const rc = panel(acc, 'Record a recovery');
    await rc.waitFor({ state: 'visible' });
    await rc.locator('#recovery_amount').fill('5000');
    await rc.locator('#recovery_amount').press('Tab');
    await rc.locator('#recovery_reference').fill(`SALV-${stamp}`).catch(() => undefined);
    await pickLookup(acc, rc.locator('#recovery_payer'), 'Motijheel');
    await rc.getByRole('button', { name: 'Review and post' }).click();
    await confirmPreview(acc, 'recovery');
    checkErrors(acc, 'recovery');

    await goto(cm, state.claimUrl);
    await clickButton(cm, 'Close claim', { noWait: true });
    const cl = panel(cm, 'Close');
    await cl.waitFor({ state: 'visible' });
    await cl.locator('#close_reason').fill('Settled in full').catch(() => undefined);
    await cl.getByRole('button', { name: 'Review and close' }).click();
    await confirmPreview(cm, 'close claim');
    await expectStatus(cm, 'Closed', 'claim closed');
    checkErrors(cm, 'close claim');
    console.log(`  claim ${state.claimUrl}: reserved 60,000, paid 45,000, recovered 5,000, closed`);
};

// ---------------------------------------------------------------- 6. accounting: manual journal → approve → reversal; chart of accounts; account roles; imports
steps[6] = async () => {
    const acc = await login('accountant');
    await goto(acc, '/accounting/journals/create');
    await acc.locator('#description').fill(`Office repairs ${stamp}`);
    await acc.locator('#reason').fill('Invoice from the landlord');
    await pickLookup(acc, acc.locator('#line-account-0'), 'Repairs');
    await acc.locator('#line-amount-0').fill('1200');
    await acc.locator('#line-amount-0').press('Tab');
    await pickLookup(acc, acc.locator('#line-account-1'), 'Bank - Main');
    await acc.locator('#line-amount-1').fill('1200');
    await acc.locator('#line-amount-1').press('Tab');
    await clickButton(acc, 'Save and submit', { noWait: true });
    await acc.waitForURL(/\/accounting\/journals\/[0-9a-f-]{36}/, { timeout: 30000 }).catch(async () => fail(`journal create: ${(await alertsOf(acc)).join(' | ')}`));
    state.journalUrl = new URL(acc.url()).pathname;
    checkErrors(acc, 'create journal');

    const fm = await login('finance.manager');
    await goto(fm, state.journalUrl);
    const approve = fm.getByRole('button', { name: /Approve and post|Review and approve/ }).first();
    await approve.waitFor({ state: 'visible', timeout: 10000 }).catch(async () => fail(`approve journal: no approve button (body: ${(await bodyText(fm)).slice(0, 300)})`));
    await approve.click();
    await confirmPreview(fm, 'approve journal');
    await expectStatus(fm, 'Posted', 'journal posted');
    checkErrors(fm, 'approve journal');

    // Reversal: the finance manager (accounting.reverse_journal) asks; someone else with the right, the CFO, approves.
    await goto(fm, state.journalUrl);
    await clickButton(fm, 'Request a reversal', { noWait: true });
    const rv = panel(fm, 'Request a reversal');
    await rv.waitFor({ state: 'visible' });
    await rv.locator('#reversal-reason').fill('Posted twice');
    await rv.getByRole('button', { name: /Request reversal/ }).click();
    await rv.waitFor({ state: 'hidden', timeout: 20000 }).catch(async () => fail(`reversal request: ${(await alertsOf(rv)).join(' | ')}`));
    await fm.waitForLoadState('networkidle').catch(() => undefined);
    checkErrors(fm, 'request reversal');
    const cfo = await login('cfo');
    await goto(cfo, state.journalUrl);
    await clickButton(cfo, /Approve reversal|Review and approve/, { noWait: true });
    await confirmPreview(cfo, 'approve reversal');
    await expectStatus(cfo, 'Reversed|reversal', 'journal reversed');
    checkErrors(cfo, 'approve reversal');

    await goto(fm, '/accounting/chart-of-accounts');
    await clickButton(fm, 'Add account', { noWait: true });
    const coa = panel(fm, 'Add account');
    await coa.waitFor({ state: 'visible' });
    await coa.locator('#coa-type').selectOption('expense');
    await coa.locator('#coa-code').fill(`59${stamp.slice(-2)}`);
    await coa.locator('#coa-name').fill(`Test expense ${stamp}`);
    const parent = coa.locator('#coa-parent');
    if (await parent.count()) {
        const heading = await parent.locator('option').filter({ hasText: /^5/ }).first().getAttribute('value').catch(() => null);
        if (heading) await parent.selectOption(heading);
    }
    await coa.getByRole('button', { name: 'Add account' }).click();
    await coa.waitFor({ state: 'hidden', timeout: 20000 }).catch(async () => fail(`add account: ${(await alertsOf(coa)).join(' | ')}`));
    await expectText(fm, `Test expense ${stamp}`, 'new account listed');
    checkErrors(fm, 'add account');

    await goto(fm, '/accounting/account-roles');
    const unmapped = fm.locator('main tbody tr').filter({ hasText: /Map to an account|—/ }).first();
    const target = (await unmapped.count()) ? unmapped : fm.locator('main tbody tr').first();
    await target.click();
    await clickButton(fm, /Map to an(other)? account/, { noWait: true });
    const map = panel(fm, 'Map');
    await map.waitFor({ state: 'visible' });
    await map.locator('#account_id').selectOption({ label: await map.locator('#account_id option').filter({ hasText: `Test expense ${stamp}` }).first().innerText() });
    // A new mapping starts after the current one: a date unique to this run.
    const mapFrom = new Date(Date.UTC(2027, 0, 1) + (Number(stamp) % 700) * 86400000).toISOString().slice(0, 10);
    await fillDate(map, '#effective_from', mapFrom);
    await map.getByRole('button', { name: 'Map role' }).click();
    await map.waitFor({ state: 'hidden', timeout: 20000 }).catch(async () => fail(`map role: ${(await alertsOf(map)).join(' | ')}`));
    checkErrors(fm, 'account roles');

    await goto(fm, '/accounting/imports');
    const csv = Buffer.from(`code,name,type,normal_side\n58${stamp.slice(-2)},Imported expense ${stamp},expense,debit\n`);
    await fm.locator('input[type=radio][value="chart-of-accounts"]').check();
    await fm.locator('#import-file').setInputFiles({ name: 'coa.csv', mimeType: 'text/csv', buffer: csv });
    await fm.locator('button[type=submit]', { hasText: 'Dry run' }).click();
    await fm.waitForLoadState('networkidle').catch(() => undefined);
    await fm.waitForTimeout(500);
    const outcome = await fm.locator('#outcome').innerText().catch(async () => (await bodyText(fm)).slice(0, 300));
    if (!/no problems|row|account|would/i.test(outcome)) fail(`import dry run: unexpected outcome "${outcome.slice(0, 200)}"`);
    checkErrors(fm, 'imports');
    console.log(`  journal ${state.journalUrl} posted and reversed; account 59${stamp.slice(-2)} added and mapped; import dry run: ${outcome.replace(/\s+/g, ' ').slice(0, 80)}`);
};

// ---------------------------------------------------------------- 7. admin: invite user, create role, approval limit add/change, underwriting limit
steps[7] = async () => {
    const admin = await login('admin');
    await goto(admin, '/admin/users');
    await clickButton(admin, 'Invite user', { noWait: true });
    const inv = panel(admin, 'Invite user');
    await inv.waitFor({ state: 'visible' });
    await inv.locator('#name').fill(`Test User ${stamp}`);
    await inv.locator('#email').fill(`test.user.${stamp}@nonlife.local`);
    await inv.locator('#role_id').selectOption({ index: 1 });
    await inv.getByRole('button', { name: 'Send invitation' }).click();
    await inv.waitFor({ state: 'hidden', timeout: 20000 }).catch(async () => fail(`invite: ${(await alertsOf(inv)).join(' | ')}`));
    await expectText(admin, `test.user.${stamp}@nonlife.local`, 'invited user listed');
    checkErrors(admin, 'invite user');

    await goto(admin, '/admin/roles');
    await clickButton(admin, 'New role', { noWait: true });
    const role = panel(admin, 'New role');
    await role.waitFor({ state: 'visible' });
    await role.locator('#name').fill(`Test Role ${stamp}`);
    await role.getByRole('button', { name: 'Create role' }).click();
    await admin.waitForLoadState('networkidle').catch(() => undefined);
    await expectText(admin, `Test Role ${stamp}`, 'new role');
    checkErrors(admin, 'create role');

    await goto(admin, '/admin/approval-limits');
    await clickButton(admin, 'Add approval limit', { noWait: true });
    const lim = panel(admin, 'Add approval limit');
    await lim.waitFor({ state: 'visible' });
    await lim.locator('#object_type').selectOption({ index: 0 });
    // Amounts no other policy covers (unique per run: a band above 90 crore).
    const band = 900_000_000 + Number(stamp.slice(-4)) * 1000;
    await lim.locator('#min_amount').fill(String(band));
    await lim.locator('#min_amount').press('Tab');
    await lim.locator('#max_amount').fill(String(band + 500));
    await lim.locator('#max_amount').press('Tab');
    await lim.locator('#role_0').selectOption({ index: 1 });
    await lim.getByRole('button', { name: 'Add limit' }).click();
    await lim.waitFor({ state: 'hidden', timeout: 20000 }).catch(async () => fail(`add limit: ${(await alertsOf(lim)).join(' | ')}`));
    await admin.waitForLoadState('networkidle').catch(() => undefined);
    const newRow = admin.locator('main tbody tr').filter({ hasText: band.toLocaleString('en-US') }).first();
    await newRow.waitFor({ state: 'visible', timeout: 10000 });
    await newRow.click();
    await clickButton(admin, 'Change', { noWait: true });
    const chg = panel(admin, 'Change:');
    await chg.waitFor({ state: 'visible' });
    await chg.locator('#max_amount').fill(String(band + 600));
    await chg.locator('#max_amount').press('Tab');
    // A change starts after the limit it replaces started: the day after it was added.
    await fillDate(chg, '#effective_from', '2026-09-16');
    await chg.getByRole('button', { name: 'Save change' }).click();
    await chg.waitFor({ state: 'hidden', timeout: 20000 }).catch(async () => fail(`change limit: ${(await alertsOf(chg)).join(' | ')}`));
    checkErrors(admin, 'approval limits');

    await goto(admin, '/admin/underwriting-limits');
    await clickButton(admin, 'Set a limit', { noWait: true });
    const uw = panel(admin, 'Set an underwriting limit');
    await uw.waitFor({ state: 'visible' });
    await uw.locator('#role_code').selectOption({ index: 1 });
    await uw.locator('#class_code').selectOption({ index: 1 });
    await uw.locator('#max_sum_insured').fill('12000000');
    await uw.locator('#max_sum_insured').press('Tab');
    await uw.getByRole('button', { name: 'Set limit' }).click();
    await uw.waitFor({ state: 'hidden', timeout: 20000 }).catch(async () => fail(`underwriting limit: ${(await alertsOf(uw)).join(' | ')}`));
    checkErrors(admin, 'underwriting limits');
    console.log(`  user test.user.${stamp} invited, role created, approval limit added and changed, underwriting limit set`);
};

// ---------------------------------------------------------------- 8. renewals: offer a renewal quote from the register, or open the one already offered
steps[8] = async () => {
    const bo = await login('branch.officer');
    await goto(bo, '/renewals');
    const offer = bo.getByRole('button', { name: 'Create renewal quote now' });
    if (await offer.count()) {
        await offer.first().click();
        await bo.waitForLoadState('networkidle').catch(() => undefined);
        await assertNoErrorPage(bo);
        checkErrors(bo, 'renewal offer');
        console.log('  renewal quote created from the register');
        return;
    }
    const withQuote = await bo.locator('main tbody tr').filter({ hasText: /QUO-/ }).count();
    if (withQuote === 0) fail('renewals: no row offers "Create renewal quote now" and none has a renewal quotation');
    await bo.locator('main tbody tr').first().click();
    const open = bo.getByRole('link', { name: 'Open renewal quotation' }).first();
    await open.waitFor({ state: 'visible', timeout: 10000 });
    await open.click();
    await bo.waitForLoadState('networkidle').catch(() => undefined);
    await assertNoErrorPage(bo);
    checkErrors(bo, 'renewal quote');
    console.log(`  every expiring policy already has its renewal quotation (the nightly run offered it); opened ${bo.url()}`);
};

await run();
