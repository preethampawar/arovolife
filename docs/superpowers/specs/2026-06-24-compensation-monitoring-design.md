# Compensation Monitoring & Reporting — Design Spec

**Date:** 2026-06-24  
**Scope:** Phase 4 Part 1 — Admin + Distributor UI for GSB, Mentorship Bonus, Group BV, Wallet, Payouts, Carry-forwards, Manual Controls  
**Source document:** Arovolife revenue sharing plan 2026-06-19  
**Decisions:** All design decisions made by engineering lead; user approved top-level structure (Option C admin, Option B distributor)

---

## 1. Decisions made

| Decision | Choice | Rationale |
|---|---|---|
| Admin structure | New "Compensation" sidebar section + deep-link from distributor profile | Mirrors BV Ledger pattern; aggregate views + per-distributor drill-down |
| Admin landing page | Tabbed section: Overview, Daily Cut-offs, Weekly Payouts, Carry-forwards, Distributor Lookup, Manual Controls | Each sub-page bookmark-able; scales as more comp features land |
| Distributor portal | Tabbed "My Income" section: Dashboard, Genos BV, GSB History, Mentorship, Wallet & Payouts | Clean separation per data type; follows existing admin tab patterns |
| Help icons | Use existing `<x-help-tip>` component on every field | Already built and consistent |
| Page notes | Blue info banner (`bg-blue-50 border-blue-200`) at top of every page explaining functionality | Consistent with existing patterns |
| Manual controls | Dedicated sub-page; every action requires ADN + reason; preview before confirm modal; audit-logged | Safety first; no silent side-effects |
| Confirm modals | Use existing `data-confirm` / confirm-modal pattern with impact detail | Consistent with platform convention |
| CSV export | Every historical table has "⬇ CSV" export | Same pattern as BV Ledger |
| Deductions shown | Admin and distributor both see gross → admin charge → TDS → net breakdown | Transparency; audit trail |

---

## 2. Admin Compensation Section

### 2.1 Route map

```
/admin/compensation                           → CompensationOverviewController
/admin/compensation/daily-cutoffs             → AdminDailyCutoffController@index
/admin/compensation/daily-cutoffs/{date}      → AdminDailyCutoffController@show
/admin/compensation/weekly-payouts            → AdminWeeklyPayoutController@index
/admin/compensation/weekly-payouts/{batch}    → AdminWeeklyPayoutController@show
/admin/compensation/carry-forwards            → AdminCarryForwardController@index
/admin/compensation/distributors/{id}         → AdminDistributorCompController@show
/admin/compensation/manual-controls           → AdminManualControlsController@index
/admin/compensation/manual-controls/retry     → AdminManualControlsController@retryCutoff (POST)
/admin/compensation/manual-controls/recalc-cf → AdminManualControlsController@recalcCarryForward (POST)
/admin/compensation/manual-controls/credit    → AdminManualControlsController@manualCredit (POST)
/admin/compensation/manual-controls/reverse   → AdminManualControlsController@reverseCredit (POST)
/admin/compensation/manual-controls/force-payout → AdminManualControlsController@forcePayout (POST)
/admin/compensation/manual-controls/freeze-gsb   → AdminManualControlsController@freezeGsb (POST)
```

**Deep-link from distributor profile:** The existing `admin.distributors.show` page gets a "Compensation →" button in its action bar that links to `/admin/compensation/distributors/{id}`.

### 2.2 Sidebar entry

Added under the existing admin sidebar, grouped with Commerce:

```
Compensation
  ├── Overview          /admin/compensation
  ├── Daily Cut-offs    /admin/compensation/daily-cutoffs
  ├── Weekly Payouts    /admin/compensation/weekly-payouts
  ├── Carry-forwards    /admin/compensation/carry-forwards
  └── Manual Controls   /admin/compensation/manual-controls
```

### 2.3 Overview page (`/admin/compensation`)

**Stat cards (top row, 4 across):**
| Card | Value | Colour | Help tip |
|---|---|---|---|
| Today's cut-off | ✓ Done / ⚠ Pending / ✗ Failed + time | Green/Amber/Red | "The 23:59 daily GSB cut-off runs automatically. If it shows Failed, use Manual Controls → Retry." |
| Failed jobs | Count needing attention | Red if >0, green if 0 | "Jobs that errored during today's cut-off or payout run. Each links to the affected distributor." |
| Pending payouts | Sum of all wallet balances ≥ ₹500 | Blue | "Total amount queued for the next Tuesday bank transfer. Does not include wallets below the ₹500 minimum." |
| GSB distributed (week) | Sum of net GSB credited this week | Purple | "Net GSB (after admin charge + TDS) credited to wallets since last Tuesday 00:00." |

**Attention feed:** Red/amber banner listing each failed or stuck item with a one-line description and an inline `Retry →` or `Recalculate →` button. Empty state: green "All systems normal" banner.

**Today's cut-off summary table:** Columns: ADN, Left BV, Right BV, Slab, Gross GSB, Net GSB, MB computed, Status (Credited / Failed / No match / Skipped-below-600BV). Sorted: Failed first, then Credited, then No match. Paginated 50/page. Filter by status. Export CSV.

**Manual trigger button:** "▶ Re-run today's cut-off" — opens confirm modal with count of distributors that will be re-processed. Only enabled if status is Failed or if admin explicitly wants a re-run (shown with warning: "Cut-off already ran today — running again will skip already-credited distributors").

### 2.4 Daily Cut-offs page (`/admin/compensation/daily-cutoffs`)

**Page note:** "Each row is one 23:59 cut-off for one distributor. The slab matched is the lower of the distributor's personal purchase title and the left/right matched BV. After the cut-off, the weaker leg resets to zero and the power leg carries forward (capped at 4,50,000 BV). Slab 1 (15,000 BV) has no time limit — the weaker leg accumulates until matched."

**Filters:** Date picker (default: today) + status filter + ADN search. Date selector shows a calendar + "Today / Yesterday / This week / Custom" shortcuts.

**Table columns:** Date | ADN | Personal title | Left BV | Right BV | Weaker side | Slab | Gross GSB | Admin charge | TDS | Net GSB | Power CF after | Slab-1 weaker CF after | MB computed | Status | Actions

**Actions column:** Credited rows → "Reverse" button (danger, with confirm). Failed rows → "Retry" button (amber). All rows → "View distributor" link.

**Export:** CSV of filtered results.

### 2.5 Weekly Payouts page (`/admin/compensation/weekly-payouts`)

**Page note:** "Payouts run every Tuesday. Each batch covers all wallets with a balance of ₹500 or more. Deductions applied per distributor: repurchase wallet (10% of prior month GSB+MB, capped ₹10,000). Minimum payout ₹500. Below-minimum balances roll over to the next Tuesday."

**Batch list:** Table of payout batches — Batch date (Tuesday) | Distributor count | Total gross | Total deductions | Total net | Status (Pending / Processing / Completed / Failed). Each row links to the batch detail.

**Batch detail:** Table per distributor — ADN | Wallet balance | Repurchase deduction | Net transferred | Bank account (last 4) | Status | UTR number (once transferred).

**Manual trigger:** "⚡ Trigger payout batch now" — confirm modal showing total amount, distributor count, and a required reason field. Only available when no batch is currently processing.

**Per-distributor force payout:** From the batch detail, each row with status Failed has a "Retry transfer" button.

### 2.6 Carry-forwards page (`/admin/compensation/carry-forwards`)

**Page note:** "This table shows the current carry-forward state for every distributor. Power-side CF is the BV on the stronger leg carried into the next day (max 4,50,000 BV; excess is flushed). Slab-1 weaker CF is the BV on the weaker leg accumulating toward the first 15,000 BV match — this has no time limit. Last updated shows when the state was last written by the cut-off engine."

**Table columns:** ADN | Personal title | Power-side CF | Power CF % of cap | Slab-1 weaker CF | Progress to 15K | Last updated | Actions

**Power CF bar:** Progress bar showing % of 4,50,000 cap. Orange if >80%, red if >95% (approaching flush threshold).

**Actions:** "Recalculate CF" button — triggers idempotent recalculation from full GSB history. Confirm modal with before/after preview.

**Filter:** ADN search + "Near cap (>80%)" filter + "Never earned GSB" filter.

### 2.7 Per-distributor compensation detail (`/admin/compensation/distributors/{id}`)

**Header card:**
- ADN + name + status badge
- 4 stat cards: Personal BV (lifetime) + title | Left Group BV today | Right Group BV today | Wallet balance
- 2 carry-forward state cards with progress bars: Power-side CF | Slab-1 weaker CF
- Action buttons: ← Distributor Profile | Tree View | ⚠ Manual Controls (links to manual controls pre-filtered to this ADN)

**Tabs (each is its own route fragment):**
1. **GSB History** — table: Date | Left BV | Right BV | Slab | Gross GSB | Admin 3% | TDS 5% | Net GSB | Status | Actions (Retry / Reverse per row). Totals row per month. Date range filter. CSV export.
2. **Mentorship Bonus** — per sponsee: Sponsee ADN | Their GSB earned | MB % applied | MB amount | Cumulative GSB from sponsee | Current slab. Total MB this period. Date filter. CSV export.
3. **Daily BV Log** — table: Date | Left Group BV | Right Group BV | Weaker side | Slab matched | Power CF after | Slab-1 weaker CF after | Result. Date filter. CSV export.
4. **Wallet Ledger** — append-only table: Timestamp | Type (GSB credit / MB credit / Payout debit / Admin charge / TDS / Repurchase / Manual credit / Reversal) | Amount | Running balance | Reference. Date filter. CSV export.
5. **Payout History** — table: Payout date (Tuesday) | Wallet balance | Admin charge | TDS | Repurchase deduction | Net transferred | Bank (last 4) | UTR | Status. CSV export.
6. **Audit Log** — table: Timestamp | Action | Performed by | Details (before → after) | Reason. Filtered to compensation actions for this distributor only. Links to full audit log.

**Help tips on every column** — see Section 4 for all help text strings.

**Page note on each tab** — see Section 5 for all page note content.

### 2.8 Manual Controls page (`/admin/compensation/manual-controls`)

**Warning banner (always visible):** "These controls affect real money and wallet balances. Every action is permanently audit-logged with your admin ID, a timestamp, the before/after state, and the reason you provide. There is no undo — use the Reverse action if a credit needs to be walked back."

**Six action cards (grid, 3×2):**
1. **Retry Daily Cut-off** — Re-run 23:59 GSB + MB for one distributor on one date. Idempotent: skips if already credited. Required fields: ADN, date, reason. Preview shows: slab, gross GSB, net GSB, CF changes.
2. **Recalculate Carry-forward** — Recompute slab-1 weaker CF and power-side CF from full history. Required fields: ADN, reason. Preview shows: current CF → new CF.
3. **Manual GSB Credit** — Credit a custom amount. Required fields: ADN, amount, reason, reference order ID. Warning: "Use only when Retry has failed. This bypasses normal slab calculation."
4. **Reverse GSB Credit** — Write a debit entry. Required fields: ADN, cut-off date being reversed, reason. Preview shows: wallet balance before → after. Danger styling.
5. **Force Weekly Payout** — Trigger immediate payout for one distributor. Required fields: ADN, reason. Preview shows: wallet balance, deductions, net transferred.
6. **Freeze GSB** — Block GSB credits without terminating account. Toggle (Freeze / Unfreeze). Required fields: ADN, reason. Shows current freeze status.

**Every action form:**
- Shows current state (read from DB) before the form
- Shows a "What will happen" preview block (computed server-side on form submit before confirm)
- Shows confirm modal with: impact list | reason | admin name + timestamp | two buttons: Cancel / Yes, execute
- On success: flash success toast + audit log row written + redirect to that distributor's compensation detail

**Recent actions feed** (bottom of page): Last 20 manual compensation actions across all distributors. Columns: Badge (action type) | ADN | Timestamp | Admin | Summary | Reason.

---

## 3. Distributor "My Income" Section

### 3.1 Route map

```
/income                    → IncomeController@dashboard      (My Income — Dashboard tab)
/income/genos-bv           → IncomeController@genosBv        (Genos BV tab)
/income/gsb-history        → IncomeController@gsbHistory     (GSB History tab)
/income/mentorship         → IncomeController@mentorship     (Mentorship Bonus tab)
/income/wallet             → IncomeController@wallet         (Wallet & Payouts tab)
/income/gsb-history/export → IncomeController@exportGsb
/income/wallet/export      → IncomeController@exportWallet
```

**Nav entry:** "My Income" added to the main distributor navigation, between "My Genos" and "My Orders".

### 3.2 Dashboard tab (`/income`)

**Payout hero card** (gradient indigo-purple, full width):
- Label: "Next Payout — Tuesday DD Mon YYYY"
- Large amount: estimated net payout (wallet balance minus projected deductions)
- Sub-line: breakdown of what's in the wallet (GSB + MB + prior balance)
- Footer note: "After 3% admin charge + 5% TDS + repurchase deduction"

**Stat cards (3 across):**
- Personal BV (lifetime) + current title + next title threshold + BV remaining
- Left Group BV — today (updates in near-real-time, note: "as of last page load")
- Right Group BV — today

**Carry-forward cards (2 across):**
- Power-side carry-forward: value + progress bar (cap 4,50,000 BV)
- Slab-1 weaker carry-forward: value + progress bar (target 15,000 BV) + "no time limit" note

**Page note:** "This dashboard shows a live snapshot of your Genos Income. Group BV updates as your Genos members make purchases throughout the day. The 23:59 daily cut-off locks the BV for that day and calculates your Genos Sales Bonus. Your wallet is credited after the cut-off and your earnings are transferred to your bank account every Tuesday. Deductions (3% admin charge, 5% TDS, and any repurchase wallet balance) are applied before transfer."

### 3.3 Genos BV tab (`/income/genos-bv`)

**Page note:** "Every day at 23:59, the platform locks your Left and Right Genos BV. The weaker side (lower of the two) is matched against the 7 slabs to determine your Genos Sales Bonus — but only up to the slab your personal purchase title allows. After the match, the weaker side resets to zero. The stronger (power) side carries forward, capped at 4,50,000 BV — any excess is flushed. For Slab 1 (15,000 BV match) only, your weaker side also carries forward indefinitely until matched — there is no time limit to earn your first ₹1,000 Genos Sales Bonus."

**Table:** Date | Left Group BV | Right Group BV | Weaker side | Slab matched | Power CF after | Slab-1 weaker CF after | Result. Date range filter. CSV export.

**No cross-distributor data is ever shown** — query always scoped to `auth()->user()->distributor`.

### 3.4 GSB History tab (`/income/gsb-history`)

**Page note:** "Your Genos Sales Bonus (GSB) is calculated at 23:59 every day based on the BV your Genos groups generated. The gross amount is reduced by a 3% admin charge (max ₹30,000), 5% TDS (Tax Deducted at Source), and a repurchase deduction before reaching your wallet. Each row below is one daily cut-off result."

**Table:** Date | Left BV matched | Right BV matched | Slab | Gross GSB | Admin charge (3%) | TDS (5%) | Repurchase deducted | Net GSB | Status. Monthly totals row. Date range filter. CSV export.

**Help tips on every column** — see Section 4.

### 3.5 Mentorship Bonus tab (`/income/mentorship`)

**Page note:** "You earn a Mentorship Bonus on the Genos Sales Bonus (GSB) earned by each distributor you directly sponsored. The rate starts at 10% of their GSB and steps down by 1% for every ₹30,000 of cumulative GSB they earn, stabilising at 1% for life. This bonus applies only to directly sponsored distributors' GSB — not to any other income type."

**Summary card:** Total MB earned this month | Total MB lifetime | Number of active direct sponsees contributing MB.

**Per-sponsee table:** Sponsee ADN (masked: first 2 chars + *** + last 2 chars for privacy) | Their GSB this period | MB % applied | MB earned from this sponsee | Their cumulative GSB (lifetime) | Current slab step. Date filter. CSV export.

**Slab progression visualiser** (per sponsee row, expandable): Shows the 10 steps (₹30K each) with the current step highlighted.

### 3.6 Wallet & Payouts tab (`/income/wallet`)

**Page note:** "Your wallet receives GSB and Mentorship Bonus credits after each 23:59 cut-off. Every Tuesday, your wallet balance (minus deductions) is transferred to your registered bank account — provided the balance is at least ₹500. Repurchase deduction: 10% of your previous month's GSB + Mentorship Bonus (max ₹10,000) is held back to fund your mandatory monthly repurchase. Balances below ₹500 roll over to the next Tuesday."

**4 stat cards:** Wallet balance | Repurchase wallet (to be deducted) | Net transfer amount | Next payout date.

**Wallet ledger table** (append-only): Timestamp | Type (GSB credit / MB credit / Payout debit / Admin charge / TDS / Repurchase / Manual credit / Reversal) | Amount (+/-) | Running balance. Date filter. CSV export.

**Payout history table:** Date | Wallet balance | Admin charge | TDS | Repurchase deduction | Net transferred | Status (Transferred / Below minimum / Pending / Failed). CSV export.

---

## 4. Help tip text for every field

### Admin and distributor shared

| Field | Help text |
|---|---|
| Personal BV (lifetime) | "The total Business Volume you have accumulated from your own personal purchases since joining. This is a lifetime running total and never resets. It determines your personal purchase title (Retailer, Dealer, Wholesaler, etc.)." |
| Personal purchase title | "Your title is determined by your lifetime personal purchase BV. Retailer: 3,000–4,999 BV. Dealer: 5,000–14,999. Wholesaler: 15,000–49,999. Distributor: 50,000–99,999. Regional Distributor: 1,00,000–1,99,999. National Distributor: 2,00,000–2,99,999. Global Distributor: 3,00,000+." |
| Left Group BV (today) | "Total Business Volume generated by all distributors placed in your Left Genos subtree today. Updates as purchases are made." |
| Right Group BV (today) | "Total Business Volume generated by all distributors placed in your Right Genos subtree today." |
| Weaker side | "The lower of your Left and Right Group BV. The Genos Sales Bonus slab is matched against the weaker side — the stronger side carries forward." |
| Slab matched | "The Genos Sales Bonus slab that applied today. Determined by whichever is lower: your personal purchase title or the matched Group BV level. Slab 1: 15,000 BV. Slab 2: 30,000 BV. Slab 3: 90,000 BV. Slab 4: 2,70,000 BV. Slab 5: 8,00,000 BV. Slab 6: 24,00,000 BV. Slab 7: 72,00,000 BV." |
| Gross GSB | "The Genos Sales Bonus before deductions. Slab 1 = ₹1,000. Slab 2 = ₹3,000. Slab 3 = ₹6,000. Slab 4 = ₹12,000. Slab 5 = ₹24,000. Slab 6 = ₹40,000. Slab 7 = ₹60,000." |
| Admin charge (3%) | "An administrative charge of 3% of gross GSB, or ₹30,000 — whichever is lower. Deducted before the amount reaches your wallet." |
| TDS (5%) | "Tax Deducted at Source at 5% per Income Tax rules. Deducted before bank transfer. Verify the current rate with your tax advisor." |
| Net GSB | "The amount credited to your wallet after the admin charge and TDS deductions." |
| Power-side carry-forward | "BV on your stronger (higher) Genos side is carried forward to the next day. Capped at 4,50,000 BV — any BV above this cap is flushed at each cut-off." |
| Slab-1 weaker carry-forward | "For the first slab only (15,000 BV match), your weaker side BV carries forward indefinitely — there is no time limit. It accumulates day by day until 15,000 BV is matched and your first ₹1,000 GSB is earned." |
| Wallet balance | "Total GSB and Mentorship Bonus credits in your wallet awaiting the next Tuesday payout." |
| Repurchase deduction | "10% of your previous month's GSB and Mentorship Bonus (capped at ₹10,000) held back to fund your mandatory monthly repurchase of at least 600 BV." |
| Net transfer amount | "Wallet balance minus the repurchase deduction. This is the amount transferred to your bank on Tuesday." |
| Mentorship Bonus % | "Starts at 10% of your direct sponsee's GSB. Steps down by 1% for every ₹30,000 of cumulative GSB they earn, stabilising at 1% for life. Each sponsee's slab is tracked independently." |
| Cumulative GSB from sponsee | "The total GSB earned by this sponsee since they joined. Used to determine your current Mentorship Bonus % rate for them." |

---

## 5. Page note content (info banners)

Every page starts with a blue info banner. Content per page:

### Admin: Overview
"The Compensation Overview shows the real-time status of today's daily GSB cut-off, any failed or stuck jobs, the total pending payout queue, and this week's GSB distributed. Items in the attention feed need action before Tuesday's payout — use the Retry or Recalculate buttons to resolve them."

### Admin: Daily Cut-offs
"Each row is one 23:59 cut-off for one distributor. The slab is determined by the lower of the distributor's personal purchase title and the matched left/right group BV. After each cut-off: weaker leg resets to zero, power leg carries forward (capped 4,50,000 BV). Slab 1 (15,000 BV) is lifetime — the weaker leg accumulates until matched. Use Manual Controls to retry failed rows or reverse incorrect credits."

### Admin: Weekly Payouts
"Payouts run automatically every Tuesday covering all wallets with a balance of ₹500 or more. Each batch shows total gross, deductions (admin charge + TDS + repurchase), and net transferred. Minimum payout is ₹500 — below-minimum wallets roll over. Use Manual Controls → Force Payout only if the automated batch failed for a specific distributor."

### Admin: Carry-forwards
"Carry-forward state persists between cut-offs. The power side (stronger leg) carries forward up to 4,50,000 BV — excess is flushed. The slab-1 weaker side carries forward indefinitely until the 15,000 BV match. Carry-forwards are updated atomically within the same DB transaction as each cut-off credit. If a BV reversal happens after a cut-off, use Recalculate Carry-forward to correct the state."

### Admin: Manual Controls
"These controls are fallbacks for edge cases — failed jobs, DB errors, BV reversals mid-cut-off. Every action requires a reason and is permanently audit-logged. Retry is safe and idempotent. Reverse creates a debit entry (no undo). Manual Credit bypasses normal slab calculation — use only when Retry has failed and the amount has been verified independently."

### Admin: Per-distributor GSB History tab
"Shows every daily cut-off result for this distributor. Gross GSB is before deductions. Failed rows have not been credited to the wallet — use Retry. Reversed rows have a debit entry in the wallet ledger. Monthly totals appear at the bottom of each calendar month's rows."

### Admin: Per-distributor Mentorship Bonus tab
"Shows Mentorship Bonus earned from each directly sponsored distributor's GSB. The % rate steps down from 10% to 1% as cumulative GSB from that sponsee passes each ₹30,000 threshold. MB applies only to GSB — not to Rank Bonus, Lifetime Rewards, or other income types."

---

## 6. Page inventory summary

| Page | Route | Who sees it |
|---|---|---|
| Compensation Overview | /admin/compensation | Admin |
| Daily Cut-offs | /admin/compensation/daily-cutoffs | Admin |
| Daily Cut-off detail (by date) | /admin/compensation/daily-cutoffs/{date} | Admin |
| Weekly Payouts | /admin/compensation/weekly-payouts | Admin |
| Weekly Payout batch detail | /admin/compensation/weekly-payouts/{batch} | Admin |
| Carry-forwards | /admin/compensation/carry-forwards | Admin |
| Distributor Comp Detail | /admin/compensation/distributors/{id} | Admin |
| Manual Controls | /admin/compensation/manual-controls | Admin only |
| My Income — Dashboard | /income | Distributor |
| My Income — Genos BV | /income/genos-bv | Distributor |
| My Income — GSB History | /income/gsb-history | Distributor |
| My Income — Mentorship | /income/mentorship | Distributor |
| My Income — Wallet & Payouts | /income/wallet | Distributor |

**Total: 13 pages (8 admin, 5 distributor)**

---

## 7. Data dependencies (what must exist before these UIs can be wired up)

These pages are designed now but cannot show real data until the Phase 4 backend engine is built:

| UI data point | Requires |
|---|---|
| Group BV (left/right today) | `group_bv_daily` table + `PropagateGroupBvJob` |
| Slab matched / Gross GSB | `gsb_cutoff_results` table (written by daily cut-off command) |
| Carry-forward state | `gsb_carryforward` table |
| Mentorship Bonus amounts | `mentorship_bonus_results` table |
| Wallet balance | `wallet_ledger_entries` table (Phase 3) |
| Payout history | `payout_batches` + `payout_line_items` tables |
| Personal BV + title | `bv_ledger_entries` (exists) + title computation (new) |
| Failed jobs | `job_failures` or `gsb_cutoff_results.status = failed` |

**Until those tables exist:** All pages render with empty states ("No data yet — GSB engine not yet active") and placeholders. The UI scaffolding can be built ahead of the engine, wired up once Phase 4 backend lands.

---

## 8. RBAC / access control

| Action | Role |
|---|---|
| View all admin comp pages | `admin`, `admin-finance` |
| Manual Controls (all actions) | `admin`, `admin-finance` |
| Freeze GSB | `admin` only |
| View distributor's own income pages | Authenticated distributor, own data only |
| Export CSV | Same as view permissions |

Distributor queries always scoped: `WHERE distributor_id = auth()->user()->distributor->id`. No cross-distributor leakage possible at the query layer.

---

## 9. Audit log actions (compensation namespace)

| Action key | Trigger |
|---|---|
| `compensation.cutoff.ran` | Automated daily cut-off completed |
| `compensation.cutoff.failed` | Automated cut-off errored |
| `compensation.cutoff.manual_retry` | Admin retried a cut-off |
| `compensation.carryforward.recalculated` | Admin recalculated CF |
| `compensation.gsb.manual_credit` | Admin manually credited GSB |
| `compensation.gsb.reversed` | Admin reversed a GSB credit |
| `compensation.payout.batch_triggered` | Automated Tuesday payout ran |
| `compensation.payout.force_triggered` | Admin force-triggered a payout |
| `compensation.gsb.frozen` | Admin froze GSB for a distributor |
| `compensation.gsb.unfrozen` | Admin unfroze GSB |

All written to existing `audit_log` table with `subject_type: 'distributor'`, `subject_id`, `details: {before, after, reason, reference}`.

---

## 10. Visual mockup references

Mockups saved in `.superpowers/brainstorm/` (session `75788-1782270543`):
- `admin-distributor-comp-detail.html` — per-distributor admin compensation view
- `distributor-my-income.html` — distributor portal (Dashboard, Genos BV, Wallet tabs)
- `admin-manual-controls.html` — manual controls page + confirm modal design

---

## 11. Out of scope for this design

- Rank Bonus UI (Phase 5)
- Fortune Bonus matrix UI (Phase 6)
- Arete Development Center Bonus UI (Phase 7)
- Growth Booster Bonus UI (Phase 4, but separate spec)
- SMS notifications for payouts (Phase 4 backlog — add 'sms' to `OrderNotificationChannels::default()`)
- Tax statements / Form 16A downloads (separate spec)
