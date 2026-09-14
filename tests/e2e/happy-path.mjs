#!/usr/bin/env node
/**
 * Slice 2.0c: the Phase 1 E2E happy path (design §9.1, CI-blocking), in a real browser on the Part A demo tenant, as the role each step belongs to:
 *
 *   quote → proposal → issue policy (journal preview) → record and allocate the premium receipt (branch manager) → register a claim → reserve →
 *   approve (claims manager) → release and pay (finance manager) → close the claim
 *
 * then checks the outcomes on the screens: the policy is in force with its journal posted, the receipt is allocated, the claim is closed with its
 * journals posted, the trial balance is balanced and the accountant's "Failed accounting events" queue is empty. Every journal preview must balance
 * and carry the expected lines. Any failed assertion, uncaught page error or 5xx response exits non-zero with evidence in storage/e2e/.
 *
 *   scripts/e2e.sh                                                   # fresh database, demo, server, worker, this test
 *   node tests/e2e/happy-path.mjs [--base http://nonlife.localhost:8771]   # or npm run test:e2e:run, against a server you started
 *
 * CHROME: the Chrome binary (default /usr/bin/google-chrome). ERP_ADMIN_PASSWORD: the demo users' password (default ChangeMe123!).
 */
import { assert, assertEqual, click, confirmJournal, createRun, eventually, fillIfEmpty, hasLine, lookup, minor, recordStatus, settle } from './lib.mjs';

const args = process.argv.slice(2);
const option = (name, fallback) => (args.includes(`--${name}`) ? args[args.indexOf(`--${name}`) + 1] : fallback);
const base = option('base', process.env.E2E_BASE_URL ?? 'http://nonlife.localhost:8771');
const tenant = new URL(base).hostname.split('.')[0];
const stamp = Date.now().toString().slice(-6);
const run = await createRun({ base, domain: `${tenant}.local` });
const state = {};

/** Reloads a record page until its "View accounting" panel lists journals that are all posted and satisfy `accept`. */
async function postedJournals(page, url, accept, what) {
    return eventually(async () => {
        await page.getByRole('button', { name: 'View accounting' }).click();
        const drawer = page.getByRole('dialog').filter({ hasText: 'Accounting for' });
        await drawer.waitFor();
        await drawer.locator('section, p').first().waitFor();
        await page.waitForTimeout(300);
        const journals = await drawer.locator('section').evaluateAll((sections) => sections.map((s) => ({
            status: s.querySelector('header')?.lastElementChild?.textContent?.trim() ?? '',
            text: s.textContent?.replace(/\s+/g, ' ') ?? '',
        })));
        await page.keyboard.press('Escape');
        return journals;
    }, (journals) => journals.length > 0 && journals.every((j) => j.status === 'Posted') && accept(journals), `${what}: every journal behind the record is posted`, {
        timeout: 60000,
        interval: 1000,
        between: async () => {
            await page.goto(url);
            await settle(page);
        },
    });
}

let failure = null;
try {
    // ASSUMPTION A-148: the run acts on today's date (the forms' own defaults), inside the demo story's open fiscal year. The Part A demo fixes its
    // story in August–September 2026 and seeds the periods July 2026 – June 2027 (August locked); outside that window the flow cannot post.
    const today = new Date().toISOString().slice(0, 10);
    assert(today >= '2026-09-01' && today <= '2027-06-30', `today (${today}) is outside the demo's open fiscal periods 2026-09-01 … 2027-06-30: move the Part A demo story forward`);

    await run.step('branch officer: quote with a new customer, proposal, KYC, submit', async () => {
        const { page } = await run.as('branch.officer');
        await page.goto(`${base}/home`);
        await settle(page);
        await click(page, page.locator('main').getByRole('link', { name: 'New quote', exact: true }).first());
        await page.waitForURL(/\/quotations\/create/);

        const headOffice = await page.locator('#branch_id option').filter({ hasText: 'Head Office' }).first().getAttribute('value');
        if ((await page.locator('#branch_id').inputValue()) !== headOffice) await page.locator('#branch_id').selectOption(headOffice);
        const motor = await page.locator('#product_id option').filter({ hasText: 'Motor' }).first().getAttribute('value');
        if ((await page.locator('#product_id').inputValue()) !== motor) await page.locator('#product_id').selectOption(motor);
        await settle(page);
        await fillIfEmpty(page.locator('#inception input, input#inception').first(), 't');

        const customer = `E2E Customer ${stamp}`;
        await page.locator('#customer_party_id').click();
        await page.locator('#customer_party_id').fill(customer);
        const create = page.getByRole('button', { name: /New customer/ });
        await create.waitFor();
        await create.dispatchEvent('mousedown');
        await click(page, page.getByRole('button', { name: 'Create customer' }));
        await lookup(page, page.locator('#producer_id'), 'AG-001');

        if ((await page.locator('#risk_vehicle_type').inputValue()) !== 'private') await page.locator('#risk_vehicle_type').selectOption('private');
        for (const [field, value] of [['registration_no', `DHA-E2E-${stamp}`], ['engine_cc', '1500'], ['seats', '5'], ['year_of_manufacture', '2020'], ['driver_age', '40'], ['sum_insured', '450000']]) {
            const input = page.locator(`#risk_${field}`);
            if ((await input.count()) && (await input.inputValue()) === '') await input.fill(value);
        }
        await page.getByText(/Gross premium/).first().waitFor();
        await click(page, page.getByRole('button', { name: 'Issue quotation' }));
        await click(page, page.getByRole('button', { name: /make proposal/ }));
        await click(page, page.getByRole('button', { name: 'Make proposal', exact: true }));
        await page.waitForURL(/\/proposals\//);

        await click(page, page.getByRole('button', { name: 'Verify identity' }));
        await page.locator('#id_number').fill(`19901${stamp}78`);
        await click(page, page.getByRole('button', { name: 'Record verification' }));
        await click(page, page.getByRole('button', { name: 'Submit to underwriting' }));
        const chassis = page.locator('#detail_chassis_no');
        if (await chassis.count()) {
            await chassis.fill(`E2E-${stamp}`);
            await click(page, page.getByRole('button', { name: 'Save details' }));
            await click(page, page.getByRole('button', { name: 'Submit to underwriting' }));
        }
        await click(page, page.getByRole('button', { name: 'Submit proposal', exact: true }));
        await eventually(() => page.getByText('Approved automatically').count(), (n) => n > 0, 'the proposal is approved automatically');
    });

    await run.step('branch officer: issue the policy through the journal preview', async () => {
        const { page } = await run.as('branch.officer');
        await click(page, page.getByRole('button', { name: 'Issue policy' }));
        await fillIfEmpty(page.getByLabel('Issue date'), 't');
        await click(page, page.getByRole('button', { name: /^Review and issue/ }));
        const journals = await confirmJournal(page);
        await page.waitForURL(/\/policies\/[0-9a-f-]{36}/);
        await settle(page);
        state.policyUrl = page.url().split('?')[0];
        state.policyNumber = (await page.locator('h1').first().innerText()).trim();
        assert(/^POL-/.test(state.policyNumber), `a policy number is allocated (got "${state.policyNumber}")`);
        state.gross = minor(await page.locator('dt', { hasText: /Gross premium/ }).locator('xpath=following-sibling::dd').first().innerText());
        assert(state.gross > 0n, 'the policy shows its gross premium');
        assert(hasLine(journals, 'Premium Receivable', 'debit', state.gross), `issue preview debits Premium Receivable with the gross premium ${state.gross}`);
        assert(hasLine(journals, 'Unearned Premium Reserve', 'credit'), 'issue preview credits the Unearned Premium Reserve');
        assert(/^(Issued|Active)$/.test(await recordStatus(page)), `the new policy is issued or active (got "${await recordStatus(page)}")`);
    });

    await run.step('branch manager: record the premium receipt from the policy and allocate it', async () => {
        const { page } = await run.as('branch.manager');
        await page.goto(state.policyUrl);
        await settle(page);
        await click(page, page.getByRole('link', { name: /record (a |the premium )?receipt/i }).or(page.getByRole('button', { name: /record (a |the premium )?receipt/i })).first());
        await page.waitForURL(/\/receipts\/create/);
        assertEqual(minor(await page.locator('#amount').inputValue()), state.gross, 'the receipt amount is prefilled with the outstanding premium');
        await fillIfEmpty(page.locator('#value_date input, input#value_date').first(), 't');
        if ((await page.locator('#channel').inputValue()) !== 'bank_transfer') await page.locator('#channel').selectOption('bank_transfer');
        await page.locator('#reference').fill(`E2E TRF ${stamp}`);
        if ((await page.locator('#allocation-0').count()) === 0) await click(page, page.getByRole('button', { name: 'Add an installment' }));
        if (!(await page.locator('#allocation-0').inputValue()).includes(state.policyNumber)) await lookup(page, page.locator('#allocation-0'), state.policyNumber);
        if (minor(await page.locator('#allocation-amount-0').inputValue()) !== state.gross) await page.locator('#allocation-amount-0').fill(String(Number(state.gross) / 100));
        await click(page, page.getByRole('button', { name: /^Review and post/ }));
        const journals = await confirmJournal(page);
        assert(hasLine(journals, 'Bank', 'debit', state.gross), 'receipt preview debits the bank with the premium');
        assert(hasLine(journals, 'Premium Receivable', 'credit', state.gross), 'receipt preview credits Premium Receivable with the premium');
        await page.waitForURL(/\/receipts\/[0-9a-f-]{36}/);
        await settle(page);
        state.receiptUrl = page.url().split('?')[0];
        assertEqual(await recordStatus(page), 'Allocated', 'the receipt status');
    });

    await run.step('claims officer: register a claim on the policy', async () => {
        const { page } = await run.as('claims.officer');
        await page.goto(`${base}/home`);
        await settle(page);
        await click(page, page.locator('main').getByRole('link', { name: 'Register a claim', exact: true }).first());
        await lookup(page, page.locator('#policy_id'), state.policyNumber);
        await click(page, page.getByRole('button', { name: /^Continue/ }));
        await page.getByLabel('Date of loss').fill('t');
        await fillIfEmpty(page.getByLabel('Reported on'), 't');
        await page.getByLabel('What happened').fill(`E2E rear collision ${stamp}`);
        await click(page, page.getByRole('button', { name: /^Continue/ }));
        await click(page, page.getByRole('button', { name: /^Register claim/ }));
        await page.waitForURL(/\/claims\/[0-9a-f-]{36}/);
        await settle(page);
        state.claimUrl = page.url().split('?')[0];
        assertEqual(await recordStatus(page), 'Registered', 'the new claim status');
    });

    await run.step('claims officer: set the reserve at 200,000', async () => {
        const { page } = await run.as('claims.officer');
        await click(page, page.getByRole('button', { name: 'Set reserve' }));
        await page.getByLabel(/New total reserve/).fill('200000');
        await page.getByLabel('Reason', { exact: true }).fill('Surveyor estimate');
        await fillIfEmpty(page.getByLabel('Date', { exact: true }), 't');
        await click(page, page.getByRole('button', { name: /^Review and post/ }));
        const journals = await confirmJournal(page);
        assert(hasLine(journals, 'Claims Incurred', 'debit', 200_000_00n), 'reserve preview debits Claims Incurred 200,000');
        assert(hasLine(journals, 'Outstanding Claims Reserve', 'credit', 200_000_00n), 'reserve preview credits the Outstanding Claims Reserve 200,000');
        await eventually(() => recordStatus(page), (s) => s === 'Reserved', 'the claim is reserved');
    });

    await run.step('claims manager: approve the payment at 180,000 and request release', async () => {
        const { page } = await run.as('claims.manager');
        await page.goto(state.claimUrl);
        await settle(page);
        await click(page, page.getByRole('button', { name: 'Approve payment' }));
        await page.getByLabel(/^Amount/).fill('180000');
        const payee = page.locator('#payee_party_id');
        if ((await payee.evaluate((el) => el.tagName)) === 'SELECT' && !(await payee.inputValue())) await payee.selectOption({ index: 1 });
        assert((await payee.inputValue()) !== '', 'the approval proposes a payee');
        await fillIfEmpty(page.getByLabel('Approval date'), 't');
        await click(page, page.getByRole('button', { name: /^Review and approve/ }));
        const journals = await confirmJournal(page);
        assert(hasLine(journals, 'Outstanding Claims Reserve', 'debit', 180_000_00n), 'approval preview moves 180,000 out of the reserve');
        assert(hasLine(journals, 'Claims Payable', 'credit', 180_000_00n), 'approval preview credits Claims Payable 180,000');
        await eventually(() => recordStatus(page), (s) => s === 'Approved', 'the claim is approved');
        await click(page, page.getByRole('button', { name: 'Request release' }));
        await eventually(() => page.getByText(/Release requested/i).count(), (n) => n > 0, 'the payment shows release requested');
    });

    await run.step('finance manager: release and pay the claim payment', async () => {
        const { page } = await run.as('finance.manager');
        await page.goto(state.claimUrl);
        await settle(page);
        await click(page, page.getByRole('button', { name: 'Pay', exact: true }));
        await fillIfEmpty(page.getByLabel('Paid on'), 't');
        await click(page, page.getByRole('button', { name: /^Review and pay/ }));
        const journals = await confirmJournal(page);
        assert(hasLine(journals, 'Claims Payable', 'debit', 180_000_00n), 'payment preview debits Claims Payable 180,000');
        assert(hasLine(journals, 'Bank', 'credit', 180_000_00n), 'payment preview credits the bank 180,000');
        await eventually(() => recordStatus(page), (s) => s === 'Paid', 'the claim is paid');
    });

    await run.step('claims manager: close the claim, releasing the rest of the reserve', async () => {
        const { page } = await run.as('claims.manager');
        await page.goto(state.claimUrl);
        await settle(page);
        await click(page, page.getByRole('button', { name: 'Close claim' }));
        await page.getByLabel('Reason', { exact: true }).fill('Settled at 180,000');
        await fillIfEmpty(page.getByLabel('Date', { exact: true }), 't');
        await click(page, page.getByRole('button', { name: /^Review and close/ }));
        const journals = await confirmJournal(page);
        assert(hasLine(journals, 'Outstanding Claims Reserve', 'debit', 20_000_00n), 'close preview releases the remaining 20,000 of the reserve');
        await eventually(() => recordStatus(page), (s) => s === 'Closed', 'the claim is closed');
    });

    await run.step('outcomes: policy in force, receipt allocated, claim closed, journals posted', async () => {
        const { page } = await run.as('finance.manager');
        await page.goto(state.policyUrl);
        await settle(page);
        const status = await recordStatus(page);
        assert(/^(Issued|Active)$/.test(status), `the policy stays in force after the claim (got "${status}")`);
        await postedJournals(page, state.policyUrl, (js) => js.some((j) => j.text.includes('Premium Receivable')), 'policy');

        await page.goto(state.receiptUrl);
        await settle(page);
        assertEqual(await recordStatus(page), 'Allocated', 'the receipt status after the run');
        await postedJournals(page, state.receiptUrl, (js) => js.some((j) => j.text.includes('Premium Receivable')), 'receipt');

        await page.goto(state.claimUrl);
        await settle(page);
        assertEqual(await recordStatus(page), 'Closed', 'the claim status after the run');
        await postedJournals(page, state.claimUrl, (js) => ['Claims Incurred', 'Claims Payable'].every((a) => js.some((j) => j.text.includes(a))) && js.length >= 4, 'claim');
    });

    await run.step('outcomes: trial balance balanced, no failed accounting events', async () => {
        const { page } = await run.as('finance.manager');
        await page.goto(`${base}/accounting/trial-balance`);
        await settle(page);
        const balance = await page.locator('main [role=status]').filter({ hasText: /Balanced|Out of balance/ }).first().innerText();
        assertEqual(balance.trim(), 'Balanced', 'the trial balance');
        const totals = page.locator('table[aria-label="Trial balance"] tfoot td');
        const [debit, credit] = [minor(await totals.nth(1).innerText()), minor(await totals.nth(2).innerText())];
        assert(debit > 0n && debit === credit, `trial balance totals are equal and non-zero (debit ${debit}, credit ${credit})`);

        const accountant = (await run.as('accountant')).page;
        await accountant.goto(`${base}/home`);
        await settle(accountant);
        const failed = accountant.locator('section').filter({ has: accountant.getByRole('heading', { name: 'Failed accounting events' }) });
        await failed.waitFor();
        assert((await failed.getByText('Every accounting event posted.').count()) === 1, `the accountant's failed accounting events queue is empty (shows: ${(await failed.innerText()).replace(/\s+/g, ' ')})`);
    });
} catch (error) {
    failure = error;
}

const code = await run.finish(failure);
console.log(code === 0 ? 'E2E happy path: passed' : 'E2E happy path: FAILED');
process.exit(code);
