# T32 — Weekly + monthly payout pages, batch detail, NEFT screen, approval (maker-checker), holds, Payout Settings developer-only

Verdict: **PASS-with-notes**

Session mode: **no staff session existed** at the start (`/dashboard` redirected to `/login`). I signed in myself as
`admin@arovolife.test` (users.id 1, role `admin`) and signed out at the end. No impersonation used.
Staging HEAD deployed 2026-09-10 03:40 (mtime of `app/Modules/Compensation/Services/PayoutService.php` on the server).

Browser note (automation artifact, **not** an app defect): in this Chrome MCP session neither coordinate clicks nor
`ref` clicks activated any element on the app (the login button, the "Approve batch" button, the modal "Confirm"
button and an ordinary `<a>` link were all inert). A genuine DOM `.click()` on the same elements ran the app's real
handlers correctly (modal opened, form submitted). Every interaction below was therefore driven through the
element's own handler, not by bypassing it.

---

## Checks

| # | Check | Result | Evidence |
|---|---|---|---|
| 1 | Weekly list totals foot to `payout_line_items` | PASS | Page: batch 3 "08 Sep 2026 (Tue) — 0 · 3 held ₹79,890.00 ₹2,625.60 ₹0.00 Pending"; batch 1 "05 Sep 2026 (Sat) — 0 · 3 held ₹79,634.00 ₹2,600.00 ₹0.00 Pending". SQL: batch 3 lines 6,589,000+800,000+600,000 = 7,989,000 p = ₹79,890.00 and 122,560+80,000+60,000 = 262,560 p = ₹2,625.60; batch 1 lines 6,563,400+800,000+600,000 = 7,963,400 p = ₹79,634.00 and 120,000+80,000+60,000 = 260,000 p = ₹2,600.00. Both equal `payout_batches.total_gross_paise` / `total_deductions_paise`. |
| 2 | Batch 3 detail foots | PASS | Page lines: ₹65,890.00 / ₹8,000.00 / ₹6,000.00 gross; ₹1,225.60 / ₹800.00 / ₹600.00 repurchase; header "TOTAL GROSS ₹79,890.00", "DEDUCTIONS ₹2,625.60", "NET TO TRANSFER ₹0.00". Sums match the SQL above exactly. |
| 3 | Batch 1 detail foots | PASS | Page lines: ₹65,634.00 / ₹8,000.00 / ₹6,000.00 gross; ₹1,200.00 / ₹800.00 / ₹600.00 repurchase; header ₹79,634.00 / ₹2,600.00 / ₹0.00. Matches SQL. |
| 4 | Wallet-balance column matches DB | PASS (value) / see F7 (label) | Page ₹64,664.40 = `wallet_balance_paise` 6,466,440 = gross 6,589,000 − repurchase 122,560. |
| 5 | Hand-recompute of admin charge + TDS on a real line | **UNVERIFIED — no payable line exists on staging** | All 7 `payout_line_items` rows are held; `holdLineItem()` writes `admin_charge_paise = 0, tds_paise = 0` (PayoutService.php lines 709–710, 765–766), so the page's "—" for Admin charge and TDS is honest, and there is nothing to recompute. The rule itself is correctly coded and correctly documented: `effectiveGross = gross − repurchase`; `adminCharge = min(effectiveGross, 3% of gross per bonus group, capped)`; `payable = effectiveGross − adminCharge`; `tds = min(payable, 5% of payable)`; `net = payable − tds` (PayoutService.php lines 268–277) — the exact order gross→−repurchase→−admin→payable→−TDS→net. UI tooltips state the same: "3% of gross, capped at ₹25,000 per bonus group per cycle" and "5% of payable (gross − repurchase − admin charge)". |
| 6 | Hold reason shown honestly (`status`, `failure_reason`) | PASS as rendering / FAIL as fact — see F1 | All three batch-3 lines render Status "No bank account", Reason "—"; DB `status = no_bank_account`, `failure_reason = NULL`. The rendering is faithful to the row; the row itself is stale. |
| 7 | Indian lakh grouping | PASS | `/admin/compensation` renders "PENDING PAYOUTS ₹1,25,409.84" (lakh grouping, not `125,409.84`). Payout figures on the batch pages are all sub-lakh, so grouping is not exercised there. |
| 8 | F42 — batch 1 dated Saturday 2026-09-05, `earnings_through` NULL | CONFIRMED, with a correction — see F6 | Weekly list literally prints "05 Sep 2026 (Sat)" and "—" under "Earnings through". DB: batch 1 `batch_date 2026-09-05`, batch 3 `2026-09-08`, both `earnings_through NULL`. Both batches contain d1/d2/d3; batch 3's d1 gross (₹65,890) ⊇ batch 1's (₹65,634). |
| 9 | Monthly list + batch 2 detail foot | PASS | Page: "01 Sep 2026 (Tue) 0 · 1 held ₹10,500.00 ₹0.00 ₹0.00 Pending"; detail line 1 ADN 678721891 SRIRAM gross ₹10,500.00, repurchase "—", net ₹0.00, status "Web only". DB: line id 4, `gross_paise 1050000`, `repurchase_deduction_paise 0`, `status web_only`; batch 2 `total_gross_paise 1050000`. |
| 10 | F48 — monthly batch has no earning window | CONFIRMED (live code defect) | The monthly list has **no** "Earnings through" column at all (the weekly list does), and the detail page shows no window. `PayoutService.php` lines 397–402 create the monthly batch **without** `earnings_through`, whereas the weekly path (lines 97–106) stamps it. So no monthly batch can ever record which income window it swept. |
| 11 | F44 — NEFT export columns | CONFIRMED | Downloaded file `neft-batch-2026-09-08.csv` (batch 3), full content: `Line#,ADN,"Full Name","Bank Last 4","Net Amount (₹)",UTR,Status` and no data rows. **No account number, no IFSC.** Beneficiary is identified only by ADN + full name + masked bank last-4. |
| 12 | Masked last-4 used in the export | PASS (column present) | `Bank Last 4` column is populated from `bank_account_last4` (`exportNeft()`); no full account number anywhere in the file. Empty here because every line is held. |
| 13 | Export blocked when batch not approved? | **NO — by design** | `GET /{batch}/neft` (batch 3, status `pending`) returned HTTP 200 and downloaded. `exportNeft()` has no status check; the view comments "The CSV is always available: finance reconciles against it even in Razorpay mode, where it is a record rather than an instruction." Called out as F3 because the file has no "not yet approved" marking. |
| 14 | F14/F15 — NEFT import has no amount / duplicate-UTR check | CONFIRMED (code + copy, nothing submitted) | `reconcile()` validates only `['response_file' => ['required','file','mimes:csv,txt','max:5120']]`. `PayoutReconciliationService` maps exactly four columns — `adn`, `utr`, `status`, `reason` (`UTR_HEADERS`, `columnMap()`); it never reads an amount and never compares the imported UTR against UTRs already stored on other lines. On-screen help: "Needs a header row with an **ADN** column and a **Status** column; **UTR** and **Failure Reason** are used when present." and "Rows are matched on ADN; each one marks that line item transferred (with its UTR) or failed (with the bank's reason)." No import was submitted. |
| 15 | Razorpay / auto-dispatch flag OFF | CONFIRMED | No `feature_flags` table exists; the gateway lives in `settings`: `payout.gateway = manual_neft`, and `payments.gateway.razorpay.enabled = false`. `PayoutGatewaySettings::isRazorpay()` therefore returns false. |
| 16 | Approval does not enqueue a transfer job | CONFIRMED before and after | `PayoutService::approve()` dispatches `DispatchRazorpayPayoutsJob` only `if ($razorpay)` (line 1161). `jobs` table was empty after the approval. |
| 17 | Batch 3 approved once through the UI | DONE — confirm modal exercised | Modal rendered: title "Approve payout batch", body "Approve this payout batch of ₹0.00 to 0 distributor(s)?", impact "Impact: the batch is signed off for payment. No money moves until you upload the NEFT CSV to the bank and import the bank's response file here.", buttons Cancel / Confirm. After Confirm: page shows "Approved — awaiting bank", "Approved by Test Admin on 10 Sep 2026 22:57.", flash "Batch approved. Download the NEFT CSV, upload it to the bank, then import the bank's response file here." |
| 18 | `approved_by` / `approved_at` / status transition | PASS | `payout_batches` id 3: `status = approved`, `approved_by = 1`, `approved_at = 2026-09-10 22:57:32`. |
| 19 | `audit_log` row written | PASS | id **3218**, `actor_id 1`, `action payout.batch.approved`, `subject_type payout_batch`, `subject_id 3`, `details {"status":"approved","gateway":"manual_neft","batch_date":"2026-09-08","batch_type":"weekly","total_net_paise":0,"distributor_count":0}`, `ip 103.88.238.66`, `created_at 2026-09-10 22:57:32`. |
| 20 | Second approval refused (idempotent) | PASS | Re-POST to `/admin/compensation/weekly-payouts/3/approve` returned the page with the error flash "Batch cannot be approved in its current state."; DB unchanged (`approved_at` still 22:57:32); `SELECT COUNT(*) FROM audit_log WHERE id > 3218` → **0**. |
| 21 | Batches 1 and 2 untouched | PASS | `SELECT id,status,approved_by FROM payout_batches` → 1 pending NULL, 2 pending NULL, 3 approved 1. |
| 22 | Payout Settings 403 for `admin` | PASS | `GET /admin/compensation/payout-settings` as admin → HTTP **403**, page "403 / Access denied / You do not have permission to view this page." Route carries `->middleware('role:developer')`. |
| 23 | Payout Settings zero-trace in the nav | PASS | Scanned every `<a href>` on the compensation hub, the weekly list, the batch detail and the monthly detail: 56 admin links, **zero** containing `payout-settings`; `/payout-settings|Payout Settings/i.test(document.documentElement.outerHTML)` → **false** on the hub. |
| 24 | `admin-compliance` excluded from approval | PASS | `finance.record` is granted to roles **admin, admin-finance, developer** only (`role_has_permissions` join). No `finance.approve` permission exists. |
| 25 | Console errors | None | `read_console_messages` on the weekly list and both batch detail pages returned no messages (errors or otherwise). |
| 26 | Page speed | PASS — all well under 3 s | weekly list 166 ms · weekly batch 1 134 ms · monthly list 127 ms · monthly batch 2 133 ms. Slowest page: **/admin/compensation/weekly-payouts, 166 ms**. |

---

## Findings

### F1 — HIGH — Hold reasons are frozen at batch creation and are never re-evaluated, including at approval
Batch 1 (05 Sep) and batch 3 (08 Sep) both hold distributors 1, 2, 3 with `status = no_bank_account`. As of
2026-09-10 12:29 those three distributors **do** have bank details (`distributors.bank_account_enc` NOT NULL,
`bank_ifsc HDFC0000001`, added by T01) and `users.status = active`. The pages still display "No bank account".

`PayoutService::approve()` only flips the batch status, `approved_by` and `approved_at` — it never re-runs the
BV / KYC / bank gates on the lines. `retryFailedLineItems()` and `retryLineItem()` only act on
`STATUS_FAILED` and only in Razorpay mode, so a held line has **no** re-evaluation path at all.

Mitigating (verified in code and in the data, so this is not money loss): held lines do **not** mark their wallet
entries `swept_by_payout_batch_id`, so the income rolls into the next batch and is paid there once the gate clears —
that is why batch 3's d1 gross (₹65,890) contains all of batch 1's (₹65,634). The defect is that an approved batch
carries a permanently wrong, and now demonstrably false, reason on the record an auditor will read.

Expected: hold reasons re-evaluated at approval (or the reason stamped with the date it was determined).
Actual: a batch approved on 2026-09-10 states three distributors had no bank account, when they did.

### F2 — HIGH — No maker-checker separation on payout approval
`POST /{batch}/approve` is gated by `can:finance.record` — the *same* permission that gates
`POST /{batch}/reconcile` (which marks lines transferred), `retry-failed` and `line-items/{line}/retry`. There is no
`finance.approve` permission in the database at all. One `admin` or `admin-finance` user can therefore approve a
batch and then import the bank response that records it as paid, unilaterally.

`payout_batches` has no `created_by` column, so "the creator cannot approve their own batch" is not merely
unenforced — it is unrepresentable. Batches are created by the scheduler, so the only human in the loop is the
approver. This is a real gap against the CLAUDE.md "Separation of duties … RBAC enforces this in code" principle.

Positive half: `admin-compliance` and `admin-operations` are correctly excluded from `finance.record`, so compliance
cannot approve payments.

### F3 — MEDIUM — The NEFT CSV is not a usable payment instruction, and any admin role can download it
Confirmed F44: the export is `Line#, ADN, Full Name, Bank Last 4, Net Amount (₹), UTR, Status`. A bank cannot
execute an NEFT from this — there is no beneficiary account number and no IFSC. Two further points:

- **Silently empty.** Batch 3 has 3 line items and the export returned a header row and nothing else, with no
  warning on the page or in the file, because `exportNeft()` filters to `pending` / `transferred` only. An ops user
  downloads a 66-byte file and has no idea why.
- **No approval marking and no finance-only gate.** `GET /{batch}/neft` has no `can:` middleware, so every role in
  the `/admin` group (`developer|admin|admin-operations|admin-finance|admin-compliance`) can download it, and it
  returned 200 on a *pending* batch with nothing in the file to say the batch was unapproved. The "always available"
  behaviour is deliberate (view comment), but the file should carry the batch status, and export of a payment file
  arguably belongs behind `finance.record` like approve/reconcile.

### F4 — MEDIUM — NEFT import accepts a bank response with no amount check and no duplicate-UTR check (F14/F15 confirmed)
`reconcile()` validates only the file's mime type and size. `PayoutReconciliationService` reads four columns —
ADN, UTR, Status, Failure Reason — and never (a) compares any amount in the response against
`net_transferred_paise`, nor (b) checks whether the incoming UTR already exists on another line item. A bank file
with a transposed amount, or the same UTR pasted across several rows, is accepted and each matched line is marked
`transferred`. The help copy makes the same omission plain: "Rows are matched on ADN". Nothing was submitted; this
is from code and on-screen copy only.

### F5 — MEDIUM — Monthly batches record no earning window at all (F48 confirmed)
`PayoutService.php` lines 397–402 create a monthly batch with `batch_type`, `batch_date`, `status` and nothing else;
the weekly path at lines 97–106 stamps `earnings_through`. The monthly list has no "Earnings through" column and the
detail page shows no window. Nothing on screen or in the database says which month's income batch 2 swept. This is a
live code gap, not stale data.

### F6 — LOW — Correction to F42: `earnings_through NULL` on batches 1 and 3 is stale data, not a live defect
The `earnings_through` column was added by migration `2026_09_07_100004` (commit 056e8304, 2026-09-07 22:44 IST) and
the weekly path stamps it on creation — with the in-code comment "Batches written before this column existed keep
null and read '—'". Batch 1 was created 2026-09-05 and batch 3 on 2026-09-08 09:00, but `PayoutService.php` on the
server was only written at **2026-09-10 03:40**, i.e. the code that stamps the column was not deployed when either
batch ran. So the "—" on the weekly list is honest for these two rows and the next weekly batch should carry a real
date. Batch 1's Saturday `batch_date` (weekly payout is scheduled Tuesday 03:00) remains an unexplained QA/seed
artifact — worth re-checking on the first post-deploy Tuesday batch rather than treating as a code bug.
The batch-1 / batch-3 overlap of 4–6 Sept income is **by design**, not double-payment risk: held income is left
unswept deliberately (`whereNull('swept_by_payout_batch_id')` + the "no debit, no sweep" comment at the bank gate),
so it is re-offered to every subsequent batch until it can actually be paid.

### F7 — LOW — "Wallet balance" column is mislabelled
The column tooltip reads "Wallet balance at time of batch generation", but the stored value is `effectiveGross`
(gross − repurchase): PayoutService line 268 `$effectiveGross = max(0, $gross - $repurchase);` written straight into
`wallet_balance_paise`. Observed: ₹64,664.40 = ₹65,890.00 − ₹1,225.60, which is not the distributor's wallet balance.

### F8 — LOW — Approval confirm modal understates what is being approved
The modal for batch 3 read "Approve this payout batch of **₹0.00** to **0** distributor(s)?" while the page beside it
showed ₹79,890.00 gross across 3 held line items. A finance user is being asked to sign off a batch described as
empty. The modal (and the header "DISTRIBUTORS 0") should name the held lines and say that their income rolls to the
next batch. Same issue on the monthly detail page.

### F9 — LOW — Monthly batch pages post to the *weekly* routes
The monthly detail page's "NEFT CSV" button points at
`/admin/compensation/weekly-payouts/2/neft` and its approve form at the weekly approve route. It functions (the
controller is batch-type agnostic and route-model binding resolves batch id 2), but a monthly batch approved through
a URL that says "weekly-payouts" is confusing in logs and in the audit trail's URL context.

### F10 — INFO (out of T32 scope, flagged for T33/T34)
As plain `admin`, the sidebar exposes **Feature flags** (`/admin/feature-flags`) and the compensation sub-nav exposes
**Plan settings** (`/admin/compensation/plan-settings`) — both described in project memory as developer-owned
surfaces. Payout Settings is correctly hidden and 403s; these two are not hidden. Not verified further here.

---

## Mutations made on staging

| Table | Id | Before → After |
|---|---|---|
| `payout_batches` | 3 | `status` pending → **approved**; `approved_by` NULL → **1**; `approved_at` NULL → **2026-09-10 22:57:32** |
| `audit_log` | **3218** (new) | `payout.batch.approved`, actor 1, subject `payout_batch` 3, ip 103.88.238.66 |

Pre-state captured before the mutation: `MAX(audit_log.id) = 3217`; batch 3 pending / NULL / NULL.
Authorised by the task brief conditions, all three verified first: (a) batch 3 had **zero payable lines** — 3 of 3
held `no_bank_account`, `distributor_count 0`, `total_net_paise 0`; (b) `settings.payout.gateway = manual_neft` and
`payments.gateway.razorpay.enabled = false`; (c) `approve()` dispatches `DispatchRazorpayPayoutsJob` only under
Razorpay — `jobs` table empty after approval.

Nothing else was changed. No engine triggered, no setting or flag touched, no NEFT import submitted, no retry
pressed, batches 1 and 2 not approved, `payout_line_items` untouched. One file was downloaded to the local
machine (`~/Downloads/neft-batch-2026-09-08.csv`, 66 bytes) by the NEFT export check.

---

## Notes for the orchestrator

1. Batch 3 IS now approved (status `approved`, awaiting bank) — later tasks reading payout state should expect that.
2. F1 (frozen hold reasons) and F2 (no maker-checker) are the two that matter before launch; both are code-level.
3. F42's `earnings_through NULL` is stale pre-deploy data, not a live bug — see F6. Re-check on the first weekly
   batch generated after the 2026-09-10 deploy. F48 (monthly window) IS a live code gap.
4. F44 confirmed exactly as filed; F14/F15 confirmed from code + on-screen copy without submitting an import.
5. Coordinate/`ref` clicks were inert across the whole app in this Chrome MCP session — if another agent reports a
   "dead button", suspect the harness first.
