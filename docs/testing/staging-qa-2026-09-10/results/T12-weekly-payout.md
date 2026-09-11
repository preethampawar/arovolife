# T12 — `gsb:weekly-payout` (weekly payout batch) on staging

Verdict: **PASS-with-notes** — the Tuesday rule, the Wed→Tue window, the deduction order and the
idempotency guard all behave exactly as the code says, and the arithmetic for the batch that will pay
the current week reconciles to the paise. Four defects, none of them a money error on staging: the
three pre-deploy batches are frozen with line items that contradict the window the deployed code
now says they pay, a mistaken CLI invocation leaves an unresolvable failed run in the health digest
with no error text, the NEFT export cannot actually be sent to a bank, and the weekly batch and the
monthly payout close share the income cap with no mutex between them.

Scope: SSH + MySQL + code reading on deployed `6f114500`. No browser. Two commands run, both
non-mutating on the money tables (`gsb:weekly-payout` with no options → refusal;
`gsb:weekly-payout --date=2026-09-08` → idempotent no-op). No `--force`, no batch approved,
dispatched or marked paid, no monthly engine, no recompute.

---

## 1. The rule, as the code states it

**Earning week = Wednesday→Tuesday, paid the Tuesday one week after it closes.**
`PayoutBatch::weeklyEarningWindow()` (`PayoutBatch.php:114-122`) is the single source:

```php
$end = $batchDate->copy()->startOfDay()->subDays(7);
return ['start' => $end->copy()->subDays(6), 'end' => $end];
```

So the batch dated Tuesday **T** pays **[T−13, T−7]**. Batch 2026-09-08 pays 2026-08-26 → 2026-09-01;
the week Wed 2026-09-02 → Tue 2026-09-08 is paid by the batch dated **Tue 2026-09-15**. Client rule
quoted in the source: income earned 5–11 Aug is deposited 18 Aug (`PayoutService.php:63-79`).

**`earnings_through`** = that window's `end`, stamped **only at batch creation**
(`PayoutService.php:99-105`) and never recomputed — `weeklyEarningThrough()` is documented "Read, never
derived… Recomputing the window from `batch_date` would invent an earning week for a batch that never
had one" (`PayoutBatch.php:124-137`). Pre-column batches keep NULL and the reports print "—".

**Tuesday-only (24216f0f).** `GsbWeeklyPayoutCommand::handle()` refuses a non-Tuesday `--date` (or
today) unless `--force`: *"A weekly payout batch is dated a Tuesday; %s is a %s."* → `FAILURE`. There
is **no dry-run option**; the signature is only `--date=` and `--force`.

**"Catch-up" is not a CLI feature.** It lives in `compensation:recompute-all`
(`EngineReplayService::latestFiringAtOrBefore()`, 14178efa): a Date engine's *period in flight* is the
latest date its cadence fires on at or before the replay horizon — the horizon for a daily engine, the
**most recent Tuesday** for the weekly batch. Before that fix the replay handed the weekly batch the
horizon date and aborted, after the wipe, on six days in seven. Running the command by hand on a
Thursday therefore does **not** catch up to Tuesday; it refuses.

**What the batch sweeps.** `GROUP_A_TYPES = ['gsb_credit','mb_credit']`
(`CompensationPlanSettingsService.php:33`), positive amount, `swept_by_payout_batch_id IS NULL`,
`notReversed()`, `earnedOnOrBefore($earnedThrough)` — plus, for the paying branch, the matching
credit-time `repurchase_transfer` debits whose `reference_type` is in
`WEEKLY_REPURCHASE_REF_TYPES = ['gsb_cutoff_result']` and that fall in the **same** window
(`unsweptRepurchaseTransfers()`, `PayoutService.php:969-977`) — so a credit and its deduction can never
land in different batches. `earnedOnOrBefore` also passes rows with `earned_on IS NULL`
(`WalletLedgerEntry.php:123-128`); `WalletService::credit()` now refuses a Group A credit without
`earnedOn` (`WalletService.php:162-168`), so only pre-migration rows can be NULL and staging's backfill
count is 0.

**Holds** (each writes a line item, never sweeps, never debits the wallet — `holdLineItem()`,
`PayoutService.php:686-715`), in order:

| order | gate | source | status written |
|---|---|---|---|
| 1 | personal BV < `payout.neft_min_bv_paise` (300,000 paise = 3,000 BV) | `bvLedger->totalPersonalBvPaise()` | `web_only` |
| 2 | KYC — `users.status !== 'active'` | `isKycVerified()` | `kyc_pending` |
| 3 | no `distributors.bank_account_enc` | `hasBankAccountOnFile()` | `no_bank_account` |
| 4 | bank ciphertext will not decrypt | `BankDecryptionException` | `bank_decrypt_failed` |
| 5 | net < `payout.min_threshold_paise` (10,000 paise = ₹100) | after all deductions | `below_minimum` |

Deduction order (`PayoutService.php:245-278`): **gross → −repurchase (already taken at credit time) →
−admin charge (3 %, capped) → payable → −TDS (5 % of payable) → net**.

---

## 2. Running it today (Thursday 2026-09-10)

```
$ php artisan gsb:weekly-payout
A weekly payout batch is dated a Tuesday; 2026-09-10 is a Thursday. Pass --force only to run a deliberately off-cycle batch.
EXIT=1
```

**Refusal, not a catch-up** — and that is what the deployed code prescribes: the catch-up added by
14178efa lives in the recompute replay, not the command. Nothing was written to `payout_batches`,
`payout_line_items` or `wallet_ledger_entries` (MD5 fingerprints below unchanged), and `audit_log`
gained no row (max id 3183 before and after).

`engine_runs` **did** gain a row:

```
id  engine_key         period_start  status  trigger  actor_id  summary  error  started_at           duration_ms
43  gsb.weekly-payout  2026-09-10    failed  console  NULL      NULL     NULL   2026-09-10 12:52:33  10
```

`RecordEngineRun::finished()` sets only `status` from the exit code — it never populates `error`
(`RecordEngineRun.php:143-155`). See defect **D2**: this row can never be resolved and has no text.

---

## 3. Are batches 1–3 legitimate under the new rule?

| id | type | batch_date | weekday | earnings_through | status | processed_at | gross | net | lines |
|---|---|---|---|---|---|---|---|---|---|
| 1 | weekly | 2026-09-05 | **Saturday** | NULL | pending | 2026-09-05 14:03:29 | 79,63,400 p | 0 | 3 held (`no_bank_account`, d1/d2/d3) |
| 2 | monthly | 2026-09-01 | — | NULL | pending | 2026-09-05 14:03:29 | 10,50,000 p | 0 | 1 held (`web_only`, d33) |
| 3 | weekly | 2026-09-08 | Tuesday | NULL | pending | 2026-09-08 09:00:04 | 79,89,000 p | 0 | 3 held (`no_bank_account`, d1/d2/d3) |

- **Batch 1 is illegitimate under the current rule**: a weekly batch dated a **Saturday**. It is the
  exact artefact 14178efa was written to stop — a recompute replay computing the in-flight Date period
  as the horizon. `engine_runs` id 5 (`gsb.weekly-payout / 2026-09-05 / succeeded / console`) is that
  replay. The new code can no longer produce it (`--force` aside).
- **Batch 3's date is legitimate; its contents are not.** Under the deployed rule a batch dated
  2026-09-08 pays 2026-08-26 → 2026-09-01, and staging has **zero** Group A credits earned on or before
  2026-09-01 — the correct batch 3 is completely empty. The real batch 3 carries three held lines for
  income earned 2026-09-04/05/06, which belongs to the 2026-09-15 batch. It was built pre-deploy by the
  old sweep-everything code (`engine_runs` id 25, 2026-09-08 09:00). The command itself now prints the
  contradiction:
  `Weekly payout (Group A) — batch 2026-09-08 pays earnings 2026-08-26 → 2026-09-01` while the batch
  holds ₹79,890 of 09-04..09-06 income.
- **They were not, and cannot be, re-dated or re-lined.** No migration touches `batch_date` or the line
  items; `2026_09_07_100004_add_earnings_through_to_payout_batches` adds the column nullable and
  deliberately backfills nothing ("Those rows keep NULL by construction"). The only backfill migration
  in play, `2026_09_05_190000_backfill_held_payout_lines_and_batch_totals`, recomputes
  `repurchase_deduction_paise` / `wallet_balance_paise` on held lines and the batch totals — it never
  re-derives a window. **The new code will never fill `earnings_through` on batches 1–3**: it is written
  only in the `PayoutBatch::create()` branch, and the re-run in §5 left batch 3's value NULL.
- **They do not block the next batch and they strand no money.** The unique key is
  `uniq_payout_batch_date_type (batch_date, batch_type)`, so only the 2026-09-05 and 2026-09-08 weekly
  slots are taken; 2026-09-15 is free. All 40 `wallet_ledger_entries` still have
  `swept_by_payout_batch_id IS NULL` — held lines never sweep — so every rupee is still available to the
  2026-09-15 batch. What they *do* block is a **corrected** batch for 2026-09-08: `processed_at` is set,
  so `runWeeklyBatch()` returns them untouched forever.
- **Cross-ref F10.** The windowed recompute from 2026-09-01 deletes payout batches 1–3 with the rest of
  the September derived rows; a replay after 14178efa would recreate only the Tuesday batches. That is
  the clean fix and it is still awaiting the user's go-ahead. Until then batches 1 and 3 stay as
  misleading history.

---

## 4. What the batch for Wed 2026-09-02 → Tue 2026-09-08 must contain

That week is paid by the batch dated **2026-09-15** (not 09-08 — see §1). I did **not** run it: the date
is in the future, and a real batch would sweep the wallet and be a money mutation outside this task's
remit. The figures below are derived from the ledger and the live plan parameters, and are the
acceptance target for T32 / the 15th.

Live parameters read from staging `settings`: `comp.admin_charge.rate_bp = 300`,
`comp.tds.rate_bp = 500`, `payout.min_threshold_paise = 10000`, `payout.neft_min_bv_paise = 300000`,
all seven `comp.admin_charge.applies_to_* = true`. `comp.admin_charge.weekly_cap_paise` is **absent**
from `settings`, so the registry default **2,500,000 paise (₹25,000)** applies
(`CompensationPlanSettingsService.php:54`). `comp.monthly_income_cap_paise` is likewise absent →
₹50,00,000; nobody on staging is within two orders of magnitude of it, and there are no
`income_cap_forfeit` rows.

Ledger sums for the window (`earned_on <= 2026-09-08`, unswept, positive):

| dist | gsb_credit | mb_credit | gross | repurchase already taken (`repurchase_transfer`, ref `gsb_cutoff_result`) | wallet after credit-time deduction |
|---|---|---|---|---|---|
| 1 | 12,25,600 | 53,63,400 | **65,89,000** | 1,22,560 | 64,66,440 |
| 2 | 8,00,000 | 0 | **8,00,000** | 80,000 | 7,20,000 |
| 3 | 6,00,000 | 0 | **6,00,000** | 60,000 | 5,40,000 |
| 4–7 | 0 | 0 | 0 | — | — |

(all paise). Distributors 4–7 have **no Group A credit at all**, so `$distributorIds` never contains
them and no line item is written for them — their rank/ADC income is Group B/D and belongs to the
monthly batch.

**Repurchase is not taken a second time.** `$repurchase` is the sum of the *existing* `repurchase_transfer`
**debits**; `$effectiveGross = $gross − $repurchase` merely excludes money that left the main wallet at
credit time (three-entry ledger, T11 §4). The admin charge is levied on the **gross**
(`adminChargeFor([[Gsb,$gsbEffective],[Mentorship,$mbEffective]], …)`, `PayoutService.php:269-273`) but
clamped to `$effectiveGross`, so admin + TDS + net always equal what is actually debited.

Expected line items for batch 2026-09-15:

| dist | gross | −repurchase | wallet_balance | admin 3 % (cap ₹25k) | payable | TDS 5 % | **net** | status |
|---|---|---|---|---|---|---|---|---|
| 1 | 65,89,000 | 1,22,560 | 64,66,440 | 1,97,670 | 62,68,770 | 3,13,439 | **59,55,331** (₹59,553.31) | `pending` |
| 2 | 8,00,000 | 80,000 | 7,20,000 | 24,000 | 6,96,000 | 34,800 | **6,61,200** (₹6,612.00) | `pending` |
| 3 | 6,00,000 | 60,000 | 5,40,000 | 18,000 | 5,22,000 | 26,100 | **4,95,900** (₹4,959.00) | `pending` |
| **batch** | **79,89,000** | | | **2,39,670** | | **3,74,339** | **71,12,431** (₹71,124.31) | 3 distributors, deductions 8,76,569 |

TDS on d1 is `round(6268770 × 5 %) = round(313438.5) = 313439` — PHP rounds half away from zero, and the
code uses `(int) round(...)`, `min()`-clamped to payable.

**Gates for all three: passed.** `users.status = active` (KYC), `bank_account_enc` non-NULL and
decryptable (T01 wrote it, last4 0001/0002/0003), personal BV 2,80,600 / 14,400 / 14,400 ≫ 3,000. Every
net is ≫ ₹100, so no `below_minimum` line. Nobody is `web_only` — d33's `web_only` line in batch 2 is
the monthly ADC stream, out of scope here.

**No `--force` re-run against batch 3 was attempted for these numbers**, and none would produce them: a
re-run of 2026-09-08 is a no-op (§5), and `--force --date=2026-09-10` would create a *new* junk batch
dated a Thursday whose window (08-28 → 09-03) also excludes every credit — an empty batch row on a day
the engine never fires. Not run.

---

## 5. Idempotence

```
$ php artisan gsb:weekly-payout --date=2026-09-08
Weekly payout (Group A) — batch 2026-09-08 pays earnings 2026-08-26 → 2026-09-01
Batch #3 pending — 0 distributors, net ₹0.00
EXIT=0
```

No second batch, no line item, no sweep, no audit row. `runWeeklyBatch()` finds the existing batch by
`whereDate('batch_date') + batch_type` and returns before the `processing` update because
`status === pending && processed_at !== null` (`PayoutService.php:110-113`); the DB unique key
`uniq_payout_batch_date_type` is the second line of defence.

| fingerprint | before both runs | after both runs |
|---|---|---|
| `payout_batches` (3 rows, all columns) | `56abe6f550f3fa959e4948b266ef6bd2` | **identical** |
| `payout_line_items` (7 rows) | `50a820d62b7556e3dc609831e3f7bc2f` | **identical** |
| `wallet_ledger_entries` (40 rows, incl. `swept_by_payout_batch_id`) | `08310d25ca01131a7464d1f575fd596e` | **identical** |
| `audit_log` max id | 3183 | 3183 |

`engine_runs` id 44 (`gsb.weekly-payout / 2026-09-08 / succeeded / console / 15 ms`) is the only new row.
Note the no-op **returns exit 0 and records a `succeeded` run** — correct for the cron alerting the
command documents, but it means "batch already processed" and "batch built" are indistinguishable in
`engine_runs`, which is how batch 3 came to look healthy while holding the wrong week.

---

## 6. Repurchase-wallet gate on payout — there is none

`PayoutService` never calls `WalletService::repurchaseWalletBalancePaise()` (grep over the file: zero
hits; the only callers are checkout, the dashboard, the income page and two admin controllers). The
"repurchase wallet must be 0" gates live in the *earning* engines (FB / GBB / RB re-qual / AO-GO), not in
the payout. So **d1 (₹272.98), d2 (₹1,801), d3 (₹1,601) and d4 (₹500.50) are paid their GSB/MB in full**
— the deduction has already been taken at credit time and is sitting in the repurchase wallet, and the
payout only sweeps what remains:

> "Repurchase was deducted at credit time: each gsb_credit has a matching repurchase_transfer debit
> already in the main wallet. Sweep those entries alongside the bonus credits so the balance closes to
> zero; the payout_debit uses effectiveGross (post-repurchase), not the full gross"
> — `PayoutService.php:238-243`

Consistent with the R-60 rule: a repurchase-wallet balance never leaves as cash, and it never blocks the
cash that is left.

---

## 7. Income cap — one ledger, no double count, but no mutex

Both batches measure the same thing the same way: `allocateAgainstIncomeCap()` groups the entries being
swept by `bonus_month` and gives each group only the room its own month has left, where consumption is
`monthToDateCappedGrossPaise()` = the sum of **already-swept**, non-reversed `MONTHLY_CAP_TYPES` credits
carrying that `bonus_month` (`PayoutService.php:1086-1111`). Because only *swept* rows count as consumed
and the sweep happens inside the same `DB::transaction` as the allocation, whichever batch runs first
records its consumption before the other reads it — no double count.

The serialisation is by clock alone. `routes/console.php:63-67` schedules the weekly batch
`weeklyOn(2, '03:00')` and `:96-110` the monthly payout close `monthlyOn(8, '04:00')`, both
`runInBackground()`, both `withoutOverlapping()` — which is **per command**, not across commands. The
comment is explicit that 04:00 was chosen "to keep it clear of the weekly GSB batch at Tuesday 03:00,
which consults the same monthly income cap". On an 8th that falls on a Tuesday (2026-09-08 was one) a
weekly batch overrunning 60 minutes would have both processes reading the same swept set and each
allocating the full remaining room. No staging exposure (largest distributor is at ₹90,569 against a
₹50,00,000 ceiling, and there are no `income_cap_forfeit` rows at all), but the guard is a comment, not
a lock. See **D4**.

---

## 8. Beneficiary name on the NEFT export (F28)

`AdminWeeklyPayoutController::exportNeft()` (`:208-243`) writes columns
`Line#, ADN, Full Name, Bank Last 4, Net Amount (₹), UTR, Status`, and the payee name is:

```php
Csv::safe($line->distributor->user?->full_name ?? ''),
```

i.e. **`users.full_name`**, with no fallback and no holder-name column anywhere (F28 confirmed). The
Razorpay path does the same with a fallback: `contactName()` returns
`trim($distributor->user?->full_name) ?: (string) $distributor->adn`, truncated to 50 chars
(`RazorpayPayoutGateway.php:646-650`) — the manual-NEFT export has **no** such fallback and would emit an
empty name cell.

Two consequences on staging, both real: all seven earning accounts have `users.full_name =
"Arovolife Private Limited"` on seven different bank accounts, so the file carries one payee name for
seven beneficiaries; and the export contains **only the last four digits** of the account with **no
account number and no IFSC**, so the CSV as it stands cannot be uploaded to a bank as a payment
instruction at all. See **D3**.

---

## Checks

| # | Check | Result | Evidence |
|---|---|---|---|
| 1 | Rule stated from the code: Wed→Tue week, paid T+7; `earnings_through`; catch-up; sweep set; holds | **PASS** | §1 — `PayoutBatch.php:114-122`, `PayoutService.php:63-79/99-105/686-715/969-977`, `WalletLedgerEntry.php:123-128`, `EngineReplayService::latestFiringAtOrBefore()` (14178efa) |
| 2 | Run today (Thursday), no options | **PASS — refusal** | §2. Exit 1, exact message, zero rows written, `engine_runs` id 43 `failed`, no audit row |
| 2b | The refusal is a refusal, not a silent catch-up | **PASS** | Catch-up exists only in the recompute replay; the command has no such path |
| 3 | Batches 1–3 legitimate under the new rule? | **FAIL — see D1** | §3. Batch 1 dated a Saturday; batch 3's lines are a week the batch no longer pays; `earnings_through` will never be filled; frozen by `processed_at` |
| 3b | Do they block the next batch / strand money? | **PASS** | Unique key is (batch_date, batch_type); 2026-09-15 free; all 40 wallet rows still unswept |
| 4 | Hand maths for the week Wed 09-02 → Tue 09-08 | **PASS** | §4. Ledger sums reconcile to batch-3's held-line grosses to the paise; expected net ₹71,124.31 to 3 distributors in the batch dated **2026-09-15** |
| 4b | Repurchase not deducted twice | **PASS** | `$effectiveGross = gross − existing repurchase_transfer debits`; the debit sum is read, never recomputed (`PayoutService.php:238-247`) |
| 4c | Admin charge = 3 % in 4 groups, each capped ₹25k | **PASS** | Group A `gsb+mb` weekly (cap `weekly_cap_paise`), Groups B `gbb+rank+fortune` / C `awards` / D `adc` monthly, each `adminChargeFor(..., $adminCapPaise)` separately (`:552-561`); caps absent from `settings` → registry default 2,500,000 |
| 4d | ₹100 minimum + hold reasons | **PASS** | `min_threshold_paise=10000`, `neft_min_bv_paise=300000`; gate order web_only → kyc_pending → no_bank_account → bank_decrypt_failed → below_minimum (`:146-195`) |
| 4e | `--force` re-run against batch 3 | **NOT RUN — correctly** | A re-run of 09-08 is a no-op (§5); `--force --date=2026-09-10` would create a junk Thursday batch with an empty window. Neither produces the §4 figures |
| 5 | Repurchase-wallet gate on the weekly payout | **PASS — none exists, by design** | §6. `PayoutService` never reads the repurchase wallet; d1–d4 are paid in full |
| 6 | Income cap: same ledger, no double count | **PASS-with-note** | §7. Consumption = swept rows only, inside the sweeping transaction. Clock-only serialisation → **D4** |
| 7 | NEFT beneficiary name | **PASS-with-defect** | §8. `users.full_name`, no fallback, last-4 only → **D3** |
| 8 | Idempotence — same Tuesday twice | **PASS** | §5. Three MD5 fingerprints identical, audit max id unchanged, `Batch #3 pending — 0 distributors` |

---

## Defects

| # | Sev | Finding |
|---|---|---|
| **D1** | **Medium (data)** | **Payout batches 1 and 3 are pre-deploy artefacts that the new code can neither correct nor reproduce.** Batch 1 is dated **Saturday 2026-09-05** — a weekly batch on a day the engine can no longer be dated (created by the pre-14178efa replay, `engine_runs` id 5). Batch 3 is dated a legitimate Tuesday but carries three held lines for income earned 2026-09-04/05/06, which under the deployed rule belongs to the 2026-09-15 batch; a correct batch 3 would be empty. Both have `earnings_through = NULL` and `processed_at` set, so `runWeeklyBatch()` returns them unchanged forever and no migration re-dates or re-lines them. Repro: `php artisan gsb:weekly-payout --date=2026-09-08` prints *"pays earnings 2026-08-26 → 2026-09-01"* against a batch holding ₹79,890 of 09-04..09-06 income. Expected: a weekly batch's line items lie inside the window the batch states. Actual: they do not, and the batch says "—" for its window in the UI. No money impact — held lines sweep nothing, all 40 ledger rows are still unswept. Fix = the F10 windowed recompute from 2026-09-01 (deletes batches 1–3; the replay then recreates only Tuesday batches). Until then treat batches 1–3 as history, not as evidence. |
| **D2** | **Medium (observability)** | **A refused run is indistinguishable from a failed payout and can never be resolved.** `php artisan gsb:weekly-payout` on a non-Tuesday exits 1, and `RecordEngineRun::finished()` writes `status = failed` with **`error = NULL`** (it only maps the exit code — `RecordEngineRun.php:143-155`). `EngineStatusService::unresolvedFailureQuery()` clears a failure only when a **succeeded** run exists for the same `engine_key` **and the same `period_start`** — and `period_start` here is a Thursday, a date the engine refuses by construction. Result: engine_runs id 43 (`gsb.weekly-payout / 2026-09-10 / failed`) will be reported by the daily health digest for 30 days, with no error text to explain it, and nothing short of a DB edit or `--force` (which would create a junk batch) can close it. Expected: an operator-error refusal is either not recorded as a failure or is recorded with its message. Also true for the other Tuesday-only refusal paths. |
| **D3** | **Medium (ops)** | **The manual-NEFT export cannot be sent to a bank, and its payee name has no fallback.** `exportNeft()` emits `Bank Last 4` and no account number or IFSC, so the CSV is a reconciliation sheet, not a payment instruction — while `payout.gateway = manual_neft` is the live setting and the admin copy tells finance to "download the NEFT CSV, upload it to the bank". The payee name is `users.full_name` with **no fallback** (`?? ''`), unlike the Razorpay path which falls back to the ADN (`RazorpayPayoutGateway.php:646-650`); a distributor with a blank name exports a blank beneficiary. On staging all seven earning accounts carry the same name ("Arovolife Private Limited") over seven different accounts. Closes F28's open question: the export sends `users.full_name`, and there is still no holder-name column. |
| **D4** | **Low (design)** | **The weekly batch and the monthly payout close share the income cap with no mutex.** Both are `runInBackground()` with per-command `withoutOverlapping()`; the only separation on an 8th that is a Tuesday is 03:00 vs 04:00, which `routes/console.php:101-102` acknowledges in a comment. `monthToDateCappedGrossPaise()` counts only swept rows, so two concurrent processes would each read the same consumption and each allocate the full remaining ₹50L room. No staging exposure (largest distributor ₹90,569; zero `income_cap_forfeit` rows). A shared lock, or the cap read taken under the same lock as the sweep, would make the comment enforceable. |

Not a defect, but worth knowing for finance reports: `payout_batches.total_gross_paise` is **not additive
across batches**. A held distributor gets a fresh held line in every weekly batch until their bank
details arrive, so the same ₹79,890 already appears in batch 1 and batch 3 and will appear again in the
batch that finally pays it. Documented behaviour (`finalizeBatchTotals()` docblock: "that is the income
the batch looked at"), but a naïve SUM over batches triple-counts it.

---

## Mutations made on staging

| Table | Rows | Before → After |
|---|---|---|
| `engine_runs` | **id 43** (new) | — → `gsb.weekly-payout / 2026-09-10 / failed / console / 10 ms`, `error` NULL. The Thursday refusal. Cannot be self-resolved (D2) — leave for the F10 cleanup or ask the developer to delete it before the next digest. |
| `engine_runs` | **id 44** (new) | — → `gsb.weekly-payout / 2026-09-08 / succeeded / console / 15 ms`. The idempotent no-op. |

Nothing else. `payout_batches`, `payout_line_items` and `wallet_ledger_entries` are byte-identical to
their pre-task fingerprints (§5); `audit_log` max id is still 3183; no batch was approved, dispatched or
marked paid; `laravel.log` recorded nothing from either run (only the pre-existing
`LOG_SLACK_WEBHOOK_URL is empty` warnings).

---

## Notes for the orchestrator

1. The command behaves exactly as specified: Tuesday-only, Wed→Tue window one week in arrears, idempotent.
   No arithmetic defect found; §4 is the acceptance target for the 2026-09-15 batch — ₹71,124.31 net to 3
   distributors (d1 ₹59,553.31 · d2 ₹6,612.00 · d3 ₹4,959.00).
2. **The week Wed 09-02 → Tue 09-08 is paid by the batch dated 09-15, not 09-08.** Any task expecting
   batch 3 to pay September income is working from the pre-deploy rule.
3. T32 will find batches 1–3 unapprovable-in-substance: 0 paying lines each, so `approve()` would confirm
   "₹0 to 0 distributors". If T32 needs a real batch to approve, it needs the F10 cleanup first (or a
   deliberate `--force` batch, which I did not create).
4. engine_runs id 43 is my mutation and will show in tomorrow's health digest as an unresolved failure.
   Mention it to the user before they read the digest.
5. D1 and F10 are the same cleanup. D2, D3 and D4 are code fixes independent of staging data.
6. F28 is answered: NEFT payee = `users.full_name`, no fallback, and the export carries no account
   number or IFSC (D3).
