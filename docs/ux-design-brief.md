# UX / UI Design Brief — Insurance ERP

Audience: accountants, branch staff, claims officers, finance managers working 6–8 hours a day in this product, mostly on laptops, often on slow connections in Bangladesh. The product's job is throughput with zero financial mistakes. It must feel like a native desktop application, not a website.

---

## 1. Principles (in priority order)

1. **Work queues, not dashboards.** Every role's home is a prioritised list of things needing action (unallocated receipts, claims over limit, unmatched bank lines, installments overdue, close tasks). Charts live one level down.
2. **Exceptions first.** Show what is wrong or waiting; hide what is fine. "12 unmatched lines" beats a table of 4,000 matched ones.
3. **Calm density.** Dense tables are fine; decoration is not. Type discipline, alignment and tabular numerals do the work. No card grids, no gradients, no illustrations.
4. **Desktop-app interaction model.** Keyboard-first, command palette, split panes, resizable persistent columns, tabs, right-click menus, instant navigation, optimistic updates, state remembered per user.
5. **Accounting invisible by default, one click away.** Users act on policies, claims, receipts; every object has "View accounting" that opens the journal in a side panel.
6. **Deliberate friction only where money moves.** Posting, paying, locking a period get a confirmation with the exact amounts and accounts. Everything else is instant.
7. **Numbers are sacred.** Right-aligned, tabular figures, thousands separators, negatives in parentheses, currency once per table not per cell, minor units never shown.

---

## 2. Visual system (tokens)

**Colour — one accent, cool neutrals, semantic only where semantic.**
| Token | Hex | Use |
|---|---|---|
| `surface` | #FFFFFF | page and panel background |
| `surface-2` | #F6F7F9 | table header, sidebar, inset areas |
| `line` | #E3E6EA | hairline borders, dividers |
| `ink` | #1B1F24 | primary text |
| `ink-2` | #5B6470 | secondary text, labels |
| `accent` | #1F5F8B | primary action, selection, links (deep steel blue — dependable, not fintech-purple) |
| `accent-soft` | #E8F0F7 | selected row, focus ring base |
| `ok` | #1E7F4F | posted, matched, reconciled |
| `warn` | #B7791F | pending, ageing, soft-locked |
| `danger` | #B42318 | failed, unbalanced, locked-period violations only |

Dark mode: same structure, `surface` #14171B, `surface-2` #1C2025, `line` #2A2F36, `ink` #E7EAEE. Ship both; default to system.

**Typography**
- UI and body: **IBM Plex Sans** (with `font-variant-numeric: tabular-nums` on all numeric cells). Bengali: **Noto Sans Bengali** in the same stack.
- No second display face. No monospace except code/IDs in tooltips.
- Scale: 12 (dense table), 13 (default UI), 14 (form inputs, body), 16 (section title), 20 (page title). Weights 400/500/600 only. Sentence case everywhere. No all-caps labels, no eyebrow labels, no icon-per-heading.

**Spacing & shape**
- 4px grid. Row height 32px (dense) / 40px (comfortable), user-switchable.
- Radius: 4px controls, 6px panels, 0 on tables. One shadow level, used only for floating layers (menus, palette, dialogs).
- Borders convey structure; shadows do not.

**Iconography**: Lucide, 16px, stroke 1.5, always paired with a label except in toolbars with tooltips.

---

## 3. Application shell (desktop-app model)

```
┌──────────────────────────────────────────────────────────────────────────┐
│ [≡] Demo Insurance ▾   Search or run a command  (Ctrl+K)      ⚙  👤 Zia ▾ │  ← 44px top bar
├────────────┬─────────────────────────────────────────────────────────────┤
│ Home       │ Receipts ▸ Unallocated                       [Filters] [⋮] │
│ Policies   │ ┌─────────────────────────────┬───────────────────────────┐ │
│ Receipts ●3│ │  dense table (virtualised)  │  detail / inspector panel │ │
│ Claims  ●1 │ │  sticky header, col resize  │  (Enter opens, Esc closes)│ │
│ Bank    ●12│ │  row selection, checkboxes  │  tabs: Details · Accounting│ │
│ Commission │ │                             │        · History · Files  │ │
│ Accounting │ │                             │                           │ │
│ Close      │ └─────────────────────────────┴───────────────────────────┘ │
│ Reports    │ 1,284 rows · 3 selected · Σ 1,245,300.00    ◀ 1 / 26 ▶     │  ← status bar
│ ──────     │                                                             │
│ Admin      │                                                             │
└────────────┴─────────────────────────────────────────────────────────────┘
```

- **Sidebar**: collapsible to icons (Ctrl+B), badge counts = items needing action for *this user*. Order = frequency of use, not org chart.
- **Top bar**: entity/branch switcher, global search + command palette in one field, notifications, user.
- **Main**: list + inspector split (draggable divider, remembered). Inspector opens on row select; full page only for create/edit flows that need space.
- **Status bar**: row count, selection count, sum of selected amounts, pagination. Always visible.
- **Tabs**: opening an object from a queue can pin it as a tab (Ctrl+click), like a desktop app; max 8, persisted.

---

## 4. Interaction rules

**Keyboard**
- `Ctrl+K` palette: navigate ("go to claims"), act ("new receipt", "lock period Sep 2026"), find ("POL-1042", "cheque 88231"). Fuzzy, recent-first.
- Tables: ↑↓ move, Enter open, Space select, Shift+↑↓ range, `/` focus filter, `Esc` close inspector, `Ctrl+Enter` = primary action of the inspector (approve, allocate, post).
- Forms: Tab order = reading order, `Ctrl+S` save draft, `Ctrl+Enter` submit, `Esc` cancel with unsaved-changes guard.
- Every action in a menu shows its shortcut.

**Tables (TanStack Table + virtual scrolling)**
- Sticky header, column resize/reorder/hide, saved views per user ("My overdue > 30d"), inline filter row, multi-sort.
- Amount columns right-aligned tabular; date columns `12 Sep 2026`; status as a small dot + word, not a coloured pill.
- Footer totals for numeric columns; selection sum in status bar.
- Bulk actions appear in a toolbar when ≥1 row selected (allocate, approve, export).

**Forms**
- Single column, 560px max, labels above, helper text below, errors inline and specific ("Amount exceeds installment balance by 1,200.00").
- Money inputs: right-aligned, auto-format on blur, arrow keys ±1,000.
- Lookups (policy, customer, agent): typeahead with number/name/phone, recent items, `Ctrl+N` to create inline in a drawer.
- Dates: keyboard-typable, `t` = today, `+3` = today+3.
- Long flows (claim registration, policy issue) are steppers with a persistent summary rail on the right; each step savable as draft.
- Confirmation dialogs for money movements show a mini journal preview (DR/CR lines) before posting.

**Navigation & state**
- Inertia partial reloads; no full page loads after login. Prefetch on hover for list → detail.
- Optimistic updates for allocate/match/approve with rollback toast on failure.
- Back/forward restore scroll, filters, selection. Filters live in the URL.
- Every list, inspector width, column set, density and theme persists per user.

**Feedback**
- Toasts bottom-left, 4s, with undo where the action is reversible (unmatch, unallocate).
- Never a spinner over the whole screen; skeleton rows in place.
- Errors say what happened and what to do; never "Something went wrong".

**Empty states**: one sentence + one primary action ("No unallocated receipts. Import a bank statement.").

---

## 5. Role home screens (work queues)

| Role | Queue blocks (top to bottom) |
|---|---|
| Branch officer | Installments due this week · Lapsing policies · Receipts to record · Quotes to follow up |
| Accountant | Unallocated receipts (aged) · Unmatched bank lines · Journals awaiting my approval · Failed accounting events |
| Claims officer/manager | Claims awaiting reserve · Awaiting my approval (over my limit flagged) · Payments to release · SLA breaches |
| Finance manager / CFO | Close progress (task list with owners) · Reconciliation variances · Approvals over threshold · Cash position (single number + 30-day bars) |
| Auditor | Recent reversals/adjustments · Period reopen events · Control-account manual postings |

Each block: title, count, top 5 rows, "Open queue" link. No KPI card grid.

---

## 6. Key screens

1. **Queue list** (all lists share it): toolbar (view selector, filter, density, columns, export) → table → status bar; inspector on the right.
2. **Object page** (policy, claim, receipt): header strip (number, status, key amounts, primary action), tabs: Overview · Transactions/Timeline · Accounting · Documents · Audit. Timeline is the object's story in plain sentences ("Reserve increased to 250,000 by Rafiq, 3 Sep").
3. **Allocation workbench**: receipt on the left, candidate installments on the right, keyboard-driven allocate, running remaining balance, one commit.
4. **Bank matching**: statement lines vs ledger lines side by side, suggested matches with confidence, accept with Enter, split/merge.
5. **Month-end close**: task checklist with dependencies, owners, blockers, progress; each task opens its exception queue; lock button disabled with the reason until clean.
6. **Trial balance / statements**: collapsible account tree, period comparison, click any figure → ledger → journal → event → source document (breadcrumb drill).
7. **Journal viewer**: lines table with account codes, dimensions as chips, reversal/correction links, "reverse" behind approval.

---

## 7. Performance targets (Bangladesh connections)
- First paint < 1.5s on 3G-fast after login; subsequent navigations < 300ms (Inertia partial + cached props).
- Tables virtualised above 200 rows; server pagination above 5,000.
- Total JS < 350KB gzipped; fonts subset and self-hosted.
- Works fully offline-tolerant for reads (stale-while-revalidate); writes queue with a clear "pending" state — LATER.

## 8. Localisation
English and Bangla switchable per user; numbers and dates in the user's locale; Bengali digits optional (config).

## 9. What to avoid (explicitly)
Card-grid dashboards, gradient headers, coloured status pills everywhere, icons on every heading, all-caps labels, marketing illustrations, full-screen spinners, modals for everything, purple/violet accents, monospace body text, per-cell currency symbols, 100% width tables with text wrapping in numbers.
