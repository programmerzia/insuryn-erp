# Gap audit — stability, consistency and flows (14 Sep 2026)

This audit only records findings; no application code was changed. It checks the product against what a Bangladeshi non-life insurer expects day to day, the promises in `docs/ux-design-brief.md`, the market expectations in `docs/market-crosscheck-report.md`, and the open gaps in `docs/PROGRESS.md`.

**Out of scope:** other workers are already on these, so they are not reported:
- the business clock (Asia/Dhaka) and close lock rules;
- branch filtering on the remaining screens;
- readable risk validation errors;
- closing reopened claims;
- a date picker for `DateInput`;
- a chart of accounts screen and the setup wizard's chart step.

## How it was done

- **Code and data:** main at `a46a3f5`, seeded with `migrate:fresh --seed` and then `php artisan erp:demo` into database `erp_test_e`. It ran on `php artisan serve` (port 8772) with a queue worker. A blank tenant came from `php artisan erp:tenant gapaudit "Gap Audit Insurance"`.
- **Crawl** (Playwright with system Chrome):
  - Signed in as each of the nine demo roles: admin, branch officer, branch manager, claims officer, claims manager, accountant, finance manager, CFO, auditor. The demo has no underwriter role; underwriting decisions sit with the branch manager, finance manager and CFO.
  - Visited every sidebar screen and up to two object pages of each kind: 286 page loads across 51 Inertia components, plus 378 tab clicks.
  - Recorded console errors, 4xx/5xx responses, server time, tables, breadcrumbs, dates and horizontal overflow.
  - Checked every same-origin link on those pages as that role: 715 links.
  - Ran axe (WCAG 2 A/AA) in light and dark on 13 key screens, and loaded six phone-used screens at 400 px.
- **Flows by hand** (scripted clicks, screenshot at each step):
  - endorsement (re-rated) and cancellation with refund;
  - cheque receipt, then bounce;
  - agent cash receipt, then deposit;
  - commission approve, then pay;
  - suspense allocation workbench;
  - claim recovery;
  - manual journal: submit, approve in the inbox, then reversal;
  - bank matching screen;
  - close list, trial balance drill-down and all 10 on-screen reports;
  - quote → new customer → proposal → KYC → cover note → issue drawer;
  - command palette record search, help panel and guided tour;
  - user and role admin;
  - setup wizard on the fresh tenant.
- **Data checks:** SELECT-only queries on the demo ledger (section 4).
- **Evidence** (not committed): `storage/gap-audit/`, containing `crawl/shots/*.png`, `crawl/results-*.json`, `flows/*.png`, `f6.log`, `f8.log`, `f9.log` and the scripts.
- **Passed:**
  - No page took more than 1.5 s of server time: the slowest took 290 ms and 95% took under 115 ms.
  - axe found no contrast or other WCAG violations in light or dark on the 13 app screens. The only hits were on the bare 403 page (GA-07).
  - The trial balance balances per entity, currency and period, and every accounting event posted.
  - Subledgers match the GL (section 4).
  - Document numbers have no gaps.

Columns in the tables below:
- **Sev:** B = blocker, H = high, M = medium, L = low.
- **Effort:** S = hours, M = a day or two, L = a slice.
- **Cat** (category): broken, inconsistent, flow (confusing flow), affordance (missing affordance), copy, a11y, perf, data (data or accounting correctness), perm (security or permissions).

## Fix status (updated 14 Sep 2026)

| Findings | Commit | Status |
|---|---|---|
| GA-01, GA-03, GA-13, GA-08 | `0f99fa7` fix(roles) | Fixed |
| GA-07, GA-23, GA-22 | `8339270` fix(ux) | Fixed |
| GA-12, GA-20 | `9b35690` fix(claims) | Fixed. GA-20: the demo's CTG officer is branch-scoped (W3); the Head Office roles stay tenant-wide. |
| GA-02, GA-06, GA-34, GA-45 | `ab21dda` fix(reports) | Fixed |
| GA-42, GA-44 | `4c2bd00` fix(commission) | Fixed. Product owner decisions: commission on net premium, earning 1/365 (A-181, A-182, verify). |
| GA-36, GA-33, GA-41 | `c5ee2af` fix(documents) | Fixed |
| GA-32, GA-39 | `69588c1` fix(ux) | Fixed |
| GA-46, GA-47 | `a28ab17` fix(accounting) | Fixed |
| GA-11, GA-10, GA-35, GA-37 | `f397cf0` fix(distribution) | Fixed |
| GA-17, GA-38, GA-30 | `56222ad` fix(parties) | Fixed |
| GA-25, GA-28, GA-31 | `adaa9c3` fix(policies) | Fixed. GA-25: premium-free endorsement types not built yet. |
| GA-05 | `0c51620` fix(close) | Fixed |
| GA-15, GA-43, GA-04, GA-09 | `f5eb391` fix(close) | Fixed. GA-04: claim payment and reopen approvals show no lines preview yet. |
| GA-24 | `0f99fa7` | Partly: cancel date default, refund/collect next step and caption fixed; "write off small balance" not built yet. |
| GA-14, GA-18, GA-19, GA-21, GA-27 | — | In progress (W5, being ported onto main). |
| GA-16, GA-26, GA-29, GA-40 | — | Open (next wave). |

## 1. Fix first (top 15)

| # | ID | What | Sev | Effort |
|---|---|---|---|---|
| 1 | GA-01 | No role can request a refund, so money from cancelled policies is stuck in Customer Refunds Payable | B | S |
| 2 | GA-02 | Distribution → Hierarchy crashes (500) for every role when the tenant has no compensation scheme, which is true of the demo | H | S |
| 3 | GA-03 | Branch officer: "Record receipt" on a policy opens a prefilled form that fails with "You do not have permission" | H | S |
| 4 | GA-04 | Approving a manual journal in the inbox says "Nothing is posted yet", yet it posts. The approver never sees the lines. | H | M |
| 5 | GA-05 | Nightly jobs never run in dev or demo, so policies stay "Issued": Lapse and Renew never appear, reminders are empty and overdue premium is invisible | H | M |
| 6 | GA-06 | The loss ratio report prints "-1230.-77%": negative percentages are mangled | H | S |
| 7 | GA-07 | Links lead to 403 pages (Home → Bank, Agents → statement, Referrals → Quotes, journal → claim), and the 403 page is unstyled text with no way back | H | S |
| 8 | GA-08 | Failed accounting events can be seen but never reposted: no requeue page, and no role holds `accounting.requeue_event` | H | M |
| 9 | GA-09 | Claim reject and reopen, and the close-task "Execute", post journals without the journal preview | H | S |
| 10 | GA-10 | Two commission workbenches (Commission vs Statement run) and two producer lists (Agents vs Producers) in the sidebar | H | M |
| 11 | GA-11 | Two "New quote" flows: the palette and the Policies list open the legacy typed-premium form | H | S |
| 12 | GA-12 | Claims staff can't see the policy (no link, cover, sum insured or premium-paid status) or any claims report | H | M |
| 13 | GA-13 | The accountant has no trial balance, reports or close, yet does the reconciliations | H | S |
| 14 | GA-14 | A bounced cheque leaves the policy in force with nothing flagged; cheques post straight to the bank | H | M |
| 15 | GA-15 | Nobody can open the next fiscal year (the demo's periods end June 2027) and there is no year-end close | H | M |

Next in line: GA-16 (phones at 400 px), GA-17 (customers have no mobile, address or NID), GA-18 (setup wizard), GA-19 (dates with no default and approvals made without seeing the amount).

## 2. Findings

### 2.1 Broken, and permission gaps that break a flow

| ID | Area / screen (URL · component) | Role | Sev | Cat | What happens | What a BD non-life user expects | Suggested fix | Effort |
|---|---|---|---|---|---|---|---|---|
| GA-01 | `/refunds` · `refunds/Index` | all | B | perm, broken | `receipt.refund_request` is in no role template (`app/Modules/Platform/Authorization/RoleTemplates.php`), so "Request a refund" never shows. After cancelling POL-HO-2026-000001, 22,784.38 sits on 2160 Customer Refunds Payable and nobody can pay it. `numbering.void`, `accounting.post_in_soft_locked` and `accounting.manage_posting_rules` are in no template either. | Cancellation → refund voucher → finance pays the customer the same week | Give `receipt.refund_request` to the branch manager. The finance manager and CFO hold release, and the SoD rule already exists. Add a migration like `2026_09_30_000043`. After cancellation, flash "Request the refund of X" prefilled from `refundable.available`. | S |
| GA-02 | `/distribution/hierarchy` · (500) | branch manager, finance manager, CFO, auditor, admin | H | broken | `HierarchyPageController::index` runs `where('scheme_id', '')` on a uuid column when the tenant has no compensation scheme, which is the case in nonlife and demo. Postgres 22P02, so the page shows a Laravel error. | The page opens and shows the tree | Skip the levels query when `$schemeId === ''`; add a Pest case with no schemes | S |
| GA-03 | `/policies/{id}` → `/receipts/create?policy=` · `receipts/Create` | branch officer | H | perm, flow | X1 shows "Record receipt" (needs `receipt.create`) and prefills the allocation lines. Posting needs `receipt.allocate`, which the officer lacks, so the review shows only "You do not have permission for this action" (403 POST /receipts). | The officer at the counter takes the premium and prints the receipt | Either give the branch officer `receipt.allocate` for their own branch, or have `NextSteps::canRecordReceipt` prefill no allocation for users without it ("held in suspense; your branch manager allocates"). Test both. | S |
| GA-04 | `/approvals` · `approvals/Index` (inspector) | finance manager, CFO | H | data, copy | Approving a manual journal (the only step) posts JV-2026-000037. The confirmation says "Nothing is posted yet: this goes for approval or has no accounting effect on its own" (`JournalPreviewDialog.vue:32`). The inspector shows only amount, requester and a raw timestamp "2026-09-14 07:43:57+00": no lines, no link to the journal, no reversal context. The journal page itself has no Approve button once an approval policy applies. | Deliberate friction where money moves (brief §1.6): see DR/CR lines before approving | For a final approval step, preview what the approved object will post. Link the object ("Open JV draft") and show its lines in the inspector. Use formatted date and time. | M |
| GA-05 | nightly jobs (`routes/console.php` schedule); `/policies/{id}`, Home, `/dunning` | branch roles, finance | H | flow, data | Policy activation (Issued → Active), expiry, premium earning, dunning, renewal run, quotation and cover-note expiry, and licence alerts run only under `schedule:run`. The dev instructions (`composer worker`) never start it. All demo policies stay "Issued", so Lapse (Active only) and Renew never appear. `/dunning` is empty although POL-HO-2026-000002 #2 has been overdue since 5 Sep, and the officer's Home shows "Lapsing policies 0". No screen shows when the jobs last ran or offers "run now". | Status Active once cover starts; reminders and lapse happen without anyone knowing about cron | Add `schedule:work` to `composer dev`/`worker` and the How-to-resume docs. Show "Nightly jobs last ran …" on the close screen, with "Run now" for finance. Have `erp:demo` run activate, dunning and renewal once. | M |
| GA-06 | `/reports/loss-ratio` · `reports/Show` | finance manager, CFO, auditor | H | broken, data | `ReportsPageController:248` formats basis points with `intdiv` and `%` separately, so −123,077 bp prints "-1230.-77%". A recovery on a claim closed in August counts as −10,000 incurred for September. The column headers are cut off ("Earned premium (…"). | "(1,230.77)%", with recoveries shown as their own column | Format sign and absolute value. Add a "Recoveries" column and show incurred net of it. Widen the headers. | S |
| GA-07 | Home "Receipts to record" → `/bank`, `/bank/{id}`; `/agents` code → `/commission/agents/{id}`; Referrals empty state "Open quotes" → `/quotations`; journal "Source" → `/claims/{id}`; `/parties/{id}` "Add bank account" form | branch officer and manager; finance manager, CFO, admin; accountant; auditor, CFO, finance manager | H | perm, broken | The link check found these 403s. The party page shows the bank-account form to read-only roles (`parties/Show.vue`, no permission check). A full-page 403 is bare Times text "You do not have permission for this page." with no shell, title, `lang` or link back (axe: document-title and html-has-lang). | Links you see open; a refusal stays in the app | Gate each link with `can()` (the Home queue row should link to `/receipts/create?statement_line=` for branch roles). Hide the form without `party.manage`. Render 403/404 as an Inertia error page inside AppLayout with "Back" and "Ask for access". | S |
| GA-08 | Home "Failed accounting events" (`WorkQueues.php`) | accountant, finance manager | H | broken | Failed events are listed with raw `event_type` and no link, and nothing can repost them: `accounting.requeue_event` is used nowhere and held by no role. Events queued but never posted (worker down) are not listed at all. | A stuck posting can be retried by finance from the screen | Add a failed/stale events queue page with Requeue behind `accounting.requeue_event` (give it to the finance manager). Include `queued` events older than N minutes. Use `eventLabel()`. | M |
| GA-09 | `/claims/{id}` reject/reopen · `claims/Show`; `/close/runs/{id}` Execute · `close/Run` | claims manager, finance manager | H | data | Reject releases the unapproved reserve and reopen re-reserves, but both post through a plain `decision.post` with no DR/CR preview (`claims/Show.vue` decision drawer). Close tasks that post (earning) run straight from "Execute" (`close/Run.vue`). | Every posting shows its journal first (brief §1.6; the other money actions already do) | Route reject and reopen through `useMoneyForm`, and posting close tasks through `useJournalConfirm` | S |
| GA-10 | Sidebar: Commission (`/commission`), Statement run (`/distribution/statements`), Schemes, Agents (`/agents`), Producers | finance manager, accountant, branch roles | H | inconsistent, flow | Two ways to approve and pay commission write to the same `commission_statements`. The Phase 1 "Payout statements + Plans" page is used by the demo (AG-001, CST-2026-000001); the D6 statement run shows "No statements for this month yet" because the demo has no scheme. `/agents` lists producers of type agent, creates producers without licence or channel, and links to the commission statement, not the producer. The palette "Approve or pay commission" opens the Phase 1 page. | One producer register and one monthly commission run | Make Statement run the only approve/pay path. Turn `/commission` into history, or redirect it. Redirect `/agents` to `/distribution/producers?f.type=agent`. Migrate Phase 1 plans to schemes (A-20). Seed one scheme in `erp:demo`. | M |
| GA-11 | Palette "New quote" (`lib/commands.ts`), Policies list "New quote" → `/policies/create` · `policies/Create` | branch roles | H | inconsistent, flow | Home and Quotes open the rated workbench `/quotations/create`; the palette and Policies open the legacy form with typed premium, which can't be used for rated products. Home "Quotes to follow up" merges both kinds. | One quote path | Point both at `/quotations/create`. Show legacy "New policy (unrated product)" only while unrated products exist. | S |

### 2.2 Roles, permissions and controls

| ID | Area / screen | Role | Sev | Cat | What happens | What a BD non-life user expects | Suggested fix | Effort |
|---|---|---|---|---|---|---|---|---|
| GA-12 | `/claims/{id}` · `claims/Show`; sidebar | claims officer and manager | H | perm, affordance | The sidebar has only Home, Claims and Approvals. The claim page shows the policy number as plain text (no link, and `policies` is 403 for them). No sum insured, cover period, installments paid, earlier claims, or bounced cheque on the policy. No outstanding-claims or claims-paid report (`reports.financial`). Registration checks the loss date against cover but gives no warning on unpaid premium. | The claims desk checks cover and premium payment before reserving (no premium, no cover), and pulls the outstanding-claims register | Add a read-only "Policy" panel on the claim (cover, sum insured, premium paid or outstanding, bounced cheques, previous claims). Add a `reports.claims` permission (outstanding claims, claims paid, loss ratio) for claims roles. Warn at registration when premium is unpaid. | M |
| GA-13 | Sidebar: Trial balance, Close, Reports | accountant | H | perm | The accountant template has no `reports.financial`, so no trial balance, account activity, close checklist or reports, though Home gives them unmatched lines and suspense. "Journals awaiting my approval" is always empty because they can't approve (U6 Q7). | The accountant prepares the close and ties the bank and suspense to the GL | Add `reports.financial` to the accountant (read-only). Replace the approval block with "Journals I submitted". | S |
| GA-20 | Demo users (`erp:demo`) | admin | M | perm | `admin@nonlife.local` holds tenant_admin + finance_manager + claims_manager, breaking the seeded SoD rule "platform.manage_roles ✕ accounting.*". Every demo user's role is tenant-wide, so branch scoping (G2) can't be seen in the demo. | Demo shows the controls it sells | Seed the admin with tenant_admin only. Give the branch officer and manager a branch-scoped role on HO, and add a second branch (see GA-35). | S |
| GA-21 | `/claims/{id}` "Record recovery" | claims manager | M | perm, data | A claims manager posts Dr Bank / Cr 4200 directly: no finance step, no receipt number, no bank-account choice (the form field isn't rendered) and no payer. | Recovery money is receipted by finance like any inflow | Record the recovery as a receipt (number, bank, payer) created by finance, or require `receipt.create` plus a bank choice | M |
| GA-22 | Roles and users admin · `admin/roles/Show`, `admin/users/Show` | admin | L | copy, affordance | Group labels show raw ("Cover_note"), permissions have no explanation ("Post in soft locked", "Post to control"), and ticking two conflicting permissions gives no SoD warning. "Resend invitation" shows for active users. The user timeline says "Nothing has happened" although roles were assigned. The Invite drawer can't set a role (the wizard can). | Admin knows what a tick grants and what conflicts | Add labels and one-line help per permission, an inline SoD conflict notice, Resend only while invited, role picker in Invite | S |
| GA-23 | Logs (`storage/logs/laravel.log`) | ops | L | perf | Every 403 (`PermissionDenied`) is logged at ERROR with a stack trace; the audit run produced dozens | Errors mean errors | Report `PermissionDenied` at info/notice without trace, or list it in `dontReport` | S |

### 2.3 Flows that are hard to follow

| ID | Area / screen | Role | Sev | Cat | What happens | What a BD non-life user expects | Suggested fix | Effort |
|---|---|---|---|---|---|---|---|---|
| GA-14 | `/receipts/{id}` "Cheque bounced"; `/cheques` · `receipts/Cheques` | branch manager, accountant | H | flow, data | The cheque receipt debits 1010 Bank on the day it is received. On bounce, allocations reverse and installment #2 returns to "Pending", but the policy stays Issued with no flag, notice or task. There is no bank charge or penalty field. The register has only presented and bounced states: no "cleared" state and no bounce action on the row. The bounce date has no default. | No premium, no cover (Insurance Act 2010 s.18 practice): a bounce flags the policy, notifies the customer and, past grace, cancels from inception. Cheques sit in "cheques in hand / in clearing" until cleared. | Add a "Cheques in clearing" account role and a clear action (or a statement match that clears). On bounce, add a policy banner, a Home queue item "Policies with bounced premium", and dunning level 1 at once. Default the date to today. | M |
| GA-15 | Fiscal years (setup `FiscalYearSetup` only) | finance manager, CFO | H | broken, affordance | The fiscal year is opened once, in the setup wizard. There is no screen to open the next year and no year-end close to roll P&L into retained earnings. The demo's periods end 30 Jun 2027, when every posting will fail, E2E included (A-148). | Open FY 2027 from the close screen; year-end close journal | Add "Open next fiscal year" on `/close` (the same service). Add a year-end close task that posts income and expense to retained earnings. | M |
| GA-16 | Home, `/policies/{id}`, `/receipts/create`, `/receipts`, `/claims/create`, `/claims/{id}` at 400 px | branch officer, field staff | H | a11y, inconsistent | The sidebar stays 208 px and never collapses. Pages are 527–943 px wide at 400 px, so they scroll sideways. The policy number wraps one segment per line; installments are cut off. | Agents and officers check a policy and take a receipt on a phone | Below 640 px, hide the sidebar behind the ☰ button (the boot skeleton already does). Stack header facts. Show tables as cards on object pages. | M |
| GA-17 | New customer (quote lookup Ctrl+N) · `LookupInput` customer; `/parties/{id}` · `parties/Show` | branch roles | H | affordance, data | A customer is only name, kind (individual/organisation) and tax ID: no mobile, email, address, NID/BRN, date of birth or contact person. The party page is a legacy card: ISO dates "2026-08-05 – 2027-08-04", no edit, no claims or receipts, `accent-brick` utility that doesn't exist, breadcrumb missing. | Mobile number (SMS renewal and claim updates), address on the schedule, NID for KYC, everything about the customer on one page | Add contact columns (mobile required for individuals) and address to parties and the drawer. Rebuild the party page on `ObjectPage` (Overview, Policies, Claims, Receipts, Documents, Audit) with edit. | M |
| GA-18 | `/setup` (fresh tenant `erp:tenant`) · `setup/Index` (chart step excluded) | tenant admin, finance | H | flow | Fiscal year defaults to the current month (September 2026) while the hint says BD insurers start in January or July. The first product is unrated and says "Stamp duty is not calculated yet. Record it outside the system for now.", which contradicts the rated products and puts the tenant on the legacy quote form. There is no step for the bank account (receipts need one), underwriting limits (A-90: a new tenant refers everything), document templates or numbering. The Done step reads "— saved" / "— not saved yet". Home redirects to setup until it is finished. | A new insurer can issue a rated motor policy and take the premium into a bank on day one | Default the first month to January. Let the product step pick a tariff template (MOTOR/FIRE/MARINE demo plans) and set stamp duty. Add a "Bank accounts" step and an "Underwriting limits" step with the demo defaults. Fix the Done labels. | M |
| GA-19 | Money drawers without "today" or amount: commission approve (`commission/Index.vue`), refund pay, agent deposit (`agentCash/Index.vue`), cheque bounce, producer advance, endorse (unrated) and cancel (`policies/Show.vue`), `EndorseRiskDrawer` | finance, accountant, branch manager | M | affordance, flow | The dates start empty (X2 missed these). The deposit amount starts at 0.00, not the cash held. Commission "Approve a payout" asks for agent code, up-to date and approval date, then approves without showing the amount or entries. Agent pickers show the code only ("AG-001"). The refund drawer shows `available` unformatted. | Dates default to today; approving shows what is approved | Pass `today` and set drawer defaults as `claims/Show.vue` does. Prefill the deposit with `undeposited`. Preview the statement (entries and net) before approving. Use `LookupInput type=agent` showing code · name. | S |
| GA-24 | `/policies/{id}` cancel · `policies/Show` | branch manager | M | flow, copy, data | "Cancel from" is empty. After cancelling there's no next step (request refund, print cancellation endorsement). The preview captions the refunded UPR line "Cover has been provided, so this premium is no longer owed to the customer", which is wrong for the refunded part. A cancelled policy with unpaid earned premium (POL-HO-2026-000004, 496.77) stays in receivable ageing, with no write-off or collection route. | Clear refund versus amount-still-due outcome, printed cancellation | Default the date to today. Flash a `next` offer: "Request refund 22,784.38" or "Collect 496.77". Caption `unearned_premium` debit on cancellation "Premium for cover not given, returned to the customer". Add "Write off small balance" behind approval. | S |
| GA-25 | `/policies/{id}` "Endorse" (rated) · `EndorseRiskDrawer` | branch manager | M | flow, copy | The added 3,450.00 becomes installment #5 due on the effective date, with no endorsement number or label. The toast "Endorsement recorded." gives no number, "Record receipt" or print. Only risk fields can change: no endorsement for name, address, mortgagee or period extension. "The whole annual difference is charged" (A-119). The sum insured hint reads "At least 0.01". | Endorsement number, pro-rata additional premium, printed endorsement, collect before cover changes | Label the installment `<policy>/E<n>`. Flash `next` with Record receipt and Print endorsement. Add non-premium endorsement types. Make pro-rata the default for mid-term changes (verify A-119). Hide the ">0.01" hint. | M |
| GA-26 | Home queues (`app/Http/Home/WorkQueues.php`) | branch roles, finance manager | M | flow | "Receipts to record" counts every unmatched credit statement line, including lines already receipted (KARIM MOTOR has a strong match; DEP 7781 is suspense receipt RCT-6), and links to Bank. Overdue installments appear in no Home block (only "due this week"). "Approvals over threshold" lists a 350.00 manual journal (every manual journal needs approval). "Payments to release" links to all claims. The claim queue links filter the server list without showing the filter. | The queue shows real work and opens where the work is done | Exclude lines with a suggestion or open receipt. Add "Overdue premium". Rename the block "Waiting for my approval". Use `f.status=` URL filters. Add a claim-payments queue. | M |
| GA-27 | `/bank/{id}` · `bank/Show` | accountant | M | flow, data | A bounced cheque leaves its receipt (+3,550) and reversal (−3,550) as two unmatched ledger lines with no action to offset them. The commission payout ledger line has no reference (CST number missing). "Explain" on bank charges books nothing, so the GL stays short. No "Record receipt from this line" for unknown credits (TT 9921, 52,000). | Contra pairs clear, charges are booked from the line, unknown credits become suspense receipts in one step | Match a pair of ledger lines that nets to zero. Put the statement number in the payout journal memo. "Post as bank charge" (prefilled manual journal) and "Record receipt" on a statement line. | M |
| GA-28 | Proposal "Issue cover note" · `proposals/Show` | branch officer | M | data, flow | A 30-day cover note is issued as soon as the proposal is approved, with no check that premium was received (credit is allowed only on policy issue, A-117). The cover note queue links the proposal, never the later policy. | Cover note against premium received or an approved credit, like the policy | Apply the same premium-received/credit rule to cover notes. Show the superseding policy on the cover note row. | M |
| GA-29 | Command palette (`app/Http/Search/GlobalSearchQuery.php`, `lib/commands.ts`) | all | M | affordance | No results for cover notes (CVN-HO…), quotations (QUO-HO-2026-000009), proposals (PRP-HO), producers (AG-001) or vehicle registration (DHA-GA-…). No actions for endorse, cancel, renew, refund or cover note. After Esc and Ctrl+K the old query is kept. | Find any number the customer quotes; act from the palette | Add these record types and registration/chassis search. Add context actions on object pages. Clear the query on open. | M |
| GA-30 | Help panel and tour (`HelpPanel.vue`, `GuidedTour.vue`) | all | M | copy, inconsistent | Clicking বাংলা in the help panel silently switches the user's whole locale (tour, risk labels, breakdown); there is no language switch in the user menu. Bangla tour text mixes English jargon: "unallocated রসিদ, unmatched ব্যাংক লাইন", "Premium receivable". Many screens have no help (distribution, refunds, cheques, agent cash, tariffs, admin). | A deliberate EN/BN choice in the user menu; Bangla terms | Language switch in the user menu (preference `locale`); the help toggle changes only the panel. Add a BN glossary for accounting terms. Help modules for the missing screens. | S |
| GA-31 | `/accounting/journals/create` · `journals/Create` | accountant | M | affordance | The account picker is a plain select over the whole chart (not searchable). No attachment for the supporting voucher. The page title is "Draft journal" while status is "Pending approval". Numbers are assigned only on posting, so the approver can't quote a reference. | Voucher with a number and scanned support, searchable accounts | `LookupInput type=account`, document upload on manual journals (DocumentStore), a provisional reference (MJ-…) shown while pending | M |

### 2.4 Inconsistencies, layout and copy

| ID | Area / screen | Role | Sev | Cat | What happens | Suggested fix | Effort |
|---|---|---|---|---|---|---|---|
| GA-32 | `/commission/agents/{id}` · `commission/Statement` | finance manager, CFO, auditor | M | broken (layout) | At 1440 px the title "Agent AG-001" wraps one word per line under the breadcrumb, which overlaps it, and the toolbar overflows sideways | Use the `ObjectPage`/QueueView header layout (title on its own row), or link to the producer page (GA-10) | S |
| GA-33 | `/quotations/{id}` · `quotations/Workbench` | branch manager | M | broken | Console `RangeError: Invalid time value` from `producerDraft()` (`lib/producerCreate.ts:46`) on converted or issued quotes: `today` is empty, so the inline "New producer" drawer fails there | Pass `today` on every Workbench render; guard `producerDraft` | S |
| GA-34 | Reports: premium register, outstanding claims, loss ratio · `reports/Show` | finance manager, auditor | M | inconsistent | Raw codes: class "motor", branch "Ho" (code title-cased), transaction "new/cancellation", status "reserved". The premium register's money columns are pushed off-screen at 1440 px by wide text columns. Headers are truncated. No stamp duty column. Suspense ageing, agent cash, commission statements and trial balance have no export on the index. | Use labels (Motor, HO, New business, Reserved). Size the columns. Add stamp duty. Add export links for the four. | S |
| GA-35 | Demo story (`PartADemoSeeder`) | all | M | flow | One branch, no policy expiring within 60 days (renewals can't be tried), no cover note, no compensation scheme (causes GA-02 and an empty statement run). The opening bank balance is credited to Retained Earnings. The `demo` tenant is empty. The command says "8 policies" but seeds 7. | Add a CTG branch with a branch-scoped officer, a policy expiring in 20 days with a renewal quote, a cover note and a scheme. Credit the opening balance to Share capital or an opening-balance account. Fix the text. | S |
| GA-36 | Dates, money and status words across screens | all | M | inconsistent | Close list shows "Sept 2026" (en-GB) where everything else says "Sep". ISO or raw timestamps appear in the approvals inspector, audit list (`AuditList.vue:22`) and party page. Negatives show "-1,000.00" instead of "(1,000.00)" on the producer page, statement-run inspector and rating breakdown, and the statement-run inspector amounts are unformatted. Policy status "Issued" never becomes "Active" (GA-05). A closed claim's "Reserve" column shows incurred 180,000. | One `formatDate`/`formatMonth` (short month "Sep"), `formatMoney` everywhere, relabel the claims column "Incurred" or show outstanding | S |
| GA-37 | Terminology (sidebar, pages, help) | all | M | copy | The same thing has different names: Producer / Agent / BDO ("agent BDO-001" on the policy); Customer / Policyholder / Party; Quote / Quotation; Reminders / Payment reminders / Dunning; Statement run / Payout statements / Agent statement; Claims expense (help, tour) vs Claims Incurred (account); Suspense / Unallocated; Lapse (non-payment) vs lapsed (not renewed); Plans (commission) vs Tariffs (rating plans). The sidebar and page titles disagree (Schemes vs Compensation schemes, Renewals vs Expiry register, Cheques vs Cheque register). Duplicate sidebar icons. | Glossary: Producer (type agent/BDO/broker), Customer before issue and Policyholder after, Quote (document "Quotation"), Payment reminders, Commission statements, Claims incurred, "Not renewed". Align sidebar labels with page titles. | S |
| GA-38 | `/receipts/{id}` · `receipts/Show`; `/receipts/create` | branch roles, accountant | M | affordance | Allocation rows show the policy number as text (no link). The receipt form has no "Received from" field, so suspense items show Payer "—". "Collected by agent" only appears for cash (agents also collect cheques and MFS). The receipt channel defaults to the user's last channel (cash after one cash receipt). | Link the policy. Add payer (lookup; defaults to the policyholder when allocated). Show agent for every channel. Default the channel to bank transfer, or the last one per payer. | S |
| GA-39 | Toolbars at 1440 px: `/renewals` (Expiry register), `/agent-cash`, `/admin/underwriting-limits` | branch, finance, admin | L | inconsistent, copy | The title wraps onto two lines and the toolbar overflows sideways. The underwriting-limits header reads "Largest sum insured (BDT) (BDT)". | Let the toolbar wrap below the title; drop the duplicate currency suffix | S |
| GA-40 | Lists and tables (see `docs` cross-check A16–A20) | all | M | inconsistent, perf | Raw tables without sort, filter or totals: policy installments, claim reserve history, targets, producer compensation. Lists silently stop at 5,000 rows (policies, claims, receipts, parties), 500 (candidates, producers) or 100 (commission statements) with no pager. Selects load whole tables (refund policy, agent, payer). | Move them onto DataTable with `:url-sync="false"`; pass `page` to QueueView; use LookupInput | M |
| GA-41 | Documents | claims, finance | M | affordance | The claim acknowledgement and discharge voucher templates exist (`DocumentTemplateCode`) but have no data provider, so they can't be printed. Producer documents can't be attached. | Add the two providers and "Print" on the claim; DocumentList on the producer | M |

### 2.5 Data and accounting correctness (from the demo ledger)

| ID | Area | Sev | Cat | What happens (evidence) | Suggested fix | Effort |
|---|---|---|---|---|---|---|
| GA-42 | Commission base (`EarnCommissionOnAllocation`) | H (decision) | data | Commission is charged on the cash allocated, including VAT and stamp duty. POL-1: 259,250 minor on 2,592,500 instead of 225,000 on 2,250,000 net. Across the demo: 50,775 minor too much. | Record an OPEN item. Agent commission in Bangladesh is normally a percentage of net premium, without VAT and stamp duty. If confirmed, apply the rate to the net share of each allocation. | M |
| GA-43 | Close checklist (`close` catalogue) | M | data | Premium, claims and commission are reconciled. UPR, suspense, VAT payable and stamp duty payable vs GL are not close tasks (the UPR register already computes its variance). | Add UPR, suspense and duties reconciliation tasks before the trial balance task | M |
| GA-44 | Premium earning (`PremiumEarningRun`) | M (decision) | data | Monthly earning credits a calendar month only with earning months that end in it. POL-2 (27 days on cover in August) earned 0 against 88,767 minor daily. August revenue is 126,884 minor below daily pro-rata. | Confirm with the customer. 1/365 is the common method; switch the demo products to `daily_365` if agreed. | S |
| GA-45 | Journal source links (`PremiumEarningRun::earn` → `JournalSources`) | M | data | `PREMIUM_EARNED` events carry `source_type=premium_earning_ledger` with the policy id as `source_id`, so the journal viewer's source link resolves to nothing (2 of 2) | Pass the ledger row id, or map this type to a policy; add a test | S |
| GA-46 | Outbox and reconciliation runs | L | data | 25 `JournalPosted` and 2 period rows are never relayed (the relay handles only `PostAccountingEvent`). Duplicate clean reconciliation runs exist for August (close and job). | Relay or prune rows with no subscriber; reuse unchanged runs | S |
| GA-47 | Dimensions | L | data | `dimension_requirements` is empty, so nothing forces class, product or branch on premium and claim lines (all lines have them today) | Seed required dimensions for policy, premium and claim events | S |

Noted for the close-lock worker, not reported as findings:
- July 2026 is open while August is locked, so journals can post to a month before a closed one.
- `posted_at` and `locked_at` are stored to whole seconds, so ordering at lock time is ambiguous.

## 3. Cross-check against the market report, the UX brief and PROGRESS

### 3.1 Gaps you can fix now (not new features)

| Promise (source) | Status today | Finding |
|---|---|---|
| Work queues per role (brief §5) | Built. Missing for referrals, renewals due, cover notes expiring, licences expiring, refunds to release, commission to pay, agent cash not deposited, overdue premium. Claims "SLA breaches" is absent. | GA-26 |
| Journal preview wherever money moves (brief §1.6, §4) | Receipts, allocation, bounce, refund, deposit, payouts, statements, advances, policy issue/endorse/cancel and claim reserve/approve/recover/close/release have it. Claim reject/reopen, close tasks and the final approval don't. | GA-04, GA-09 |
| "Today" defaults (X2) | Claims, receipts and journals have them; commission, refund, deposit, bounce, advance, endorse and cancel don't | GA-19 |
| Record search: POL, cheque, customer (brief §4) | Policies, claims, receipts, customers and cheques work. Quotations, proposals, cover notes, producers and registration don't. | GA-29 |
| Object pages with Overview · Timeline · Accounting · Documents · Audit (brief §6.2) | Policy, claim, receipt, proposal and producer have them. Party (legacy), quotation (no timeline/audit), cover note (no page) and journal (no timeline) don't. | GA-17, GA-31 |
| Inspector tabs Accounting / History / Files (brief §3) | Slots exist; no list fills them | GA-40 |
| Bulk actions, split matching, undo for unallocate (brief §4, §6.4) | Only bank merge. Unallocate is now feasible (`RECEIPT_ALLOCATION_REVERSED` exists). | GA-27, GA-40 |
| EN/BN per user (brief §8) | Only help, tour, captions, rating labels and templates. No switch; `lang/` has English only. | GA-30 |
| Negatives in parentheses, "12 Sep 2026" (brief §1.7, §4) | Mostly. Exceptions listed. | GA-36 |
| Works on phones (task scope; brief is desktop-first) | Not responsive | GA-16 |
| Guided onboarding (market G9) | Wizard, help and tour exist. Wizard leaves the tenant unable to issue a rated policy or take money into a bank. | GA-18 |
| Role templates cover every flow (PROGRESS G3) | `policy.endorse`, `reports.regulatory` and `commission.pay` fixed. Refund request, requeue, accountant reports and claims reports are still missing. | GA-01, GA-08, GA-12, GA-13 |
| Nightly lifecycle (activate, expire, dunning, renewals) | Written, but not running in dev or demo | GA-05 |
| "Not done / gaps", Phase 3: approval engine for refunds and commission payouts; premium register stamp duty column; renewal loadings and claim-after-offer re-price | Open (no commits after R9) | GA-34, plus 3.2 |
| "Found, not changed", X/G/2.0d: receipt/claim number collision (fixed in G5); "Pay from" ignored (G1 fixed); branch-scoped 403 (G2 fixed); reopened paid claim can't close (excluded); outbox relay (open) | Per row | GA-46 |
| Flow audit observations: UTC vs Dhaka, locking early or with pending journals (excluded); finance lacks regulatory (G3 fixed); failed queue misses queued events (open) | Per row | GA-08 |

### 3.2 Real features still missing (Phase 2+ or market scope, not gaps)

| Feature (market report / spec) | What exists today |
|---|---|
| AP, supplier bills, payment runs (G6) | Nothing. Commission to AP queues an outbox message with no consumer. Office rent and bank charges only as manual journals. |
| Payroll, HR, BDO salaries (G6) | Only the `PAYROLL_POSTED` rule; `producers.employee_id` has no target |
| IDRA return forms, IBNR / technical provisions (G5) | CSV/XLSX exports: premium register, UPR, outstanding claims, agency register. No form layouts, no IBNR. |
| Reinsurance (treaty/fac, SBC compulsory cession, bordereaux) and co-insurance (G4) | Only a `reinsurer` party role |
| Fixed assets, investments register (FDRs, bonds) (G8) | Nothing |
| Customer, agent and surveyor portals; SMS/email gateway (G7) | Producer portal REST API only; notifications log-only; parties have no phone or email (GA-17 is the prerequisite) |
| Surveyor management, claim SLA timers, litigation tracking | A surveyor can only be a payee |
| Bulk policy import, bulk print/email from queues | Imports cover chart of accounts and opening balances only |
| Workflow engine (parallel steps, delegation, escalation) | Sequential approval policies only; refunds and payouts are outside it |
| Premium recognition at cover note, credit rules by producer or customer type, multi-payer on the proposal path | Refused or unchanged since R7 (A-117, A-121) |
| Year-end close with P&L roll-forward | Not built (GA-15 covers opening the next year, the part that blocks) |

## 4. Data and accounting sanity on the demo (after the audit's own postings)

| Check | Result |
|---|---|
| Trial balance per entity, currency and period | Balanced. August (locked): 15 journals, Dr = Cr 266,764,800 minor. September: balanced before and after the audit's endorsement, cancellation, receipts, bounce, deposit, payout, recovery and manual journal (UI "Balanced"). |
| Journals | 0 unbalanced, 0 without lines, 0 outside their period, 0 zero or negative lines. All 35 accounting events posted (none failed or queued), one journal each. The only journal without an event is the opening manual journal (expected). |
| Source links | Reversal links all resolve. Premium earning journals point to a missing ledger row (GA-45). |
| Premium receivable vs installments | 1,760,000 (31 Aug) and 1,994,427 minor (mid-audit) = GL, also per policy |
| UPR vs policies | 4,132,500 (31 Aug) and 6,447,500 minor = GL. No policy has negative UPR or UPR above net premium. VAT and stamp duty are excluded from UPR. |
| Claims (outstanding + payable), commission payable, suspense, VAT payable, stamp duty payable | All equal the GL. August close recorded 0 variance. |
| Bank vs statement | August fully matched. September: statement 5,165,000 minor above GL (unknown TT 52,000.00 in, bank charges 350.00 out, left unmatched by the story). |
| Numbering | POL 1–7, PRP 1–7, QUO 1–8, RCT 1–6 and CLM 1–2 (all HO, FY 2026) and JV 1–26 are contiguous. No voids or reservations. Formats mixed on purpose (`XXX-HO-2026-n` vs `JV-2026-n`). |
| Earned premium plausibility | Consistent with monthly earning; understated against daily pro-rata (GA-44). No earning outside cover. Cancellation catch-up correct (POL-4: 50,000 × 16/31). |
| Plausibility issues | Commission base includes VAT and stamp duty (GA-42). Receivable ageing still carries cancelled POL-4's 496.77 (GA-24). Loss ratio mixes a prior-period recovery into current incurred (GA-06). |
