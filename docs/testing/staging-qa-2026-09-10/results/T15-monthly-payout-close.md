# T15 — `compensation:monthly-payout-close` + `payout:monthly-run` on staging (closed month 2026-08)

Verdict: **PASS-with-notes** — every check ran; the gate, the idempotency and the exit codes behave
exactly as the code states, and **no money moved** (wallet fingerprint byte-identical). Four defects,
none of them an arithmetic error: the August payout close is a permanent silent no-op because a
pre-deploy batch already occupies its date; the monthly batch has **no earning window at all**, so the
gate and the batch are not talking about the same money; `payout:monthly-run` has **no gate and no
closed-period guard** and defaults to the *current* month, which walks straight past the 1st→8th
buffer; and a deliberate refusal is recorded as a `failed` run with no error text.

Scope: SSH + MySQL + code reading on deployed `6f114500`. No browser. Two commands run, both against
`--month=2026-08` only. No `--force`, no `--in-flight`, no recompute, no batch approved or dispatched,
no September command issued by hand. September wallet rows untouched (T20 was reading them).

---

## Checks

| # | Check | Result | Evidence |
|---|-------|--------|----------|
| 1 | What the monthly batch sweeps, batch-date convention, thresholds, deductions, holds, cap | **PASS** (source) | §1 |
| 2 | `compensation:monthly-payout-close --month=2026-08` — output, exit code, runs, audit, batches, wallet | **PASS** | §2 — exit 0, **no batch created**, wallet unchanged |
| 3 | Second run → no-op; batch creation idempotent | **PASS** | §3 — identical output, unique key `uniq_payout_batch_date_type` |
| 4 | Gate names the engine + the re-run command; F40 (failed close invisible) | **PASS** (both branches) | §4 |
| 5 | Batch 2 (2026-09-01): August's batch, September's, or an orphan? Oct-8 collision? | **PASS** (answered) | §5 — it *is* August's batch by the code's convention, built from September income; **no** collision on 8 Oct |
| 6 | Dry-run maths for the September crediting month (batch dated 2026-10-01) | **PASS** (computed, not run) | §6 — ₹30,736.56 net to 4 distributors, 1 held |
| 7 | Does `payout:monthly-run` refuse standalone? | **FAIL → D3** | §7 — no gate, no OpenMonthGuard, defaults to the current month |

---

## 1. What the code says (check 1)

**Two different `--month`s.** `compensation:monthly-payout-close --month` is the **crediting** month;
`payout:monthly-run --month` is the **batch** month (when the money moves).
`MonthlyPayoutCloseCommand.php:60,81`:

```php
$batchMonth = $month->copy()->addMonthNoOverflow()->startOfMonth();
…
$exitCode = Artisan::call('payout:monthly-run', ['--month' => $batchMonth->format('Y-m')]);
```

Verified live: `2026-07 → 2026-08-01`, `2026-08 → 2026-09-01`, `2026-09 → 2026-10-01`.

**Batch date = the 1st of the batch month**, i.e. the 1st of the month *after* the crediting month —
**not** the 8th. The 8th is only when the scheduler fires (`routes/console.php:102`,
`monthlyOn(8, '04:00')`, `--month = now()->subMonthNoOverflow()`). `PayoutService.php:378`:
`$dateStr = $month->copy()->startOfMonth()->toDateString();`

**What it sweeps.** `runMonthlyBatch()` (`PayoutService.php:376`) unions the three groups
(`CompensationPlanSettingsService.php:35-39`):

| group | wallet types |
|---|---|
| B | `gbb_credit`, `rank_credit`, `fortune_credit` |
| C | `awards_credit` |
| D | `adc_credit` |

Selection is **`swept_by_payout_batch_id IS NULL` + `amount_paise > 0` + `notReversed()` and nothing
else** — no `bonus_month` filter, no `earned_on` filter, no date window of any kind (`:414-419`,
`:485-491`). Alongside them it sweeps the credit-time repurchase debits whose `reference_type` is in
`MONTHLY_REPURCHASE_REF_TYPES = ['gbb_monthly_result','rank_bonus_result','fortune_bonus_result']`
(`:52`) — Awards and ADC carry no repurchase deduction. **`earnings_through` is never written for a
monthly batch**: the only assignment in the whole service (`PayoutService.php:105`) is on the weekly
path. See **D2**.

**Deduction order** (`:544-585`), matching the client's rule and the weekly batch:
`gross → −repurchase (already taken at credit time) → −admin charge → payable → −TDS → net`.

* Admin charge **3 %** (`comp.admin_charge.rate_bp = 300`, read from staging), computed **per group**
  with an **independent ₹25,000 cap each** — `adminB` (GBB+Rank+Fortune), `adminC` (Awards),
  `adminD` (ADC). `comp.admin_charge.monthly_cap_paise` is **absent from `settings`**, so the registry
  default `2_500_000` applies (`CompensationPlanSettingsService.php:55`). All seven
  `comp.admin_charge.applies_to_*` are `true` on staging. Formula: `(int) min((int) round(base × 300 / 10000), 2_500_000)` (`:1192-1202`).
  Clamped to `effectiveGross` and apportioned across the three groups if it would exceed it (`:568-571`).
* **TDS 5 %** (`comp.tds.rate_bp = 500`) on **payable minus Group C**: Lifetime Award cash reaches the
  wallet already net of both charges, so it is removed from the TDS base or it is taxed twice
  (`:579-582`). `min(payable, …)` — never tax past payable.
* **₹100 minimum** (`payout.min_threshold_paise = 10000`): a net below it writes a `below_minimum`
  line and sweeps nothing (`:584-598`).

**Holds**, in this order, each writing a line item and sweeping nothing (`holdLineItem()`, `:686-715`):

| order | gate | status |
|---|---|---|
| 1 | personal BV < `payout.neft_min_bv_paise` (300,000 paise = 3,000 BV) | `web_only` |
| 2 | `users.status !== 'active'` | `kyc_pending` |
| 3 | no `distributors.bank_account_enc` | `no_bank_account` |
| 4 | bank ciphertext will not decrypt | `bank_decrypt_failed` |

**Income cap ₹50,00,000** (`comp.monthly_income_cap_paise` absent → registry default `500_000_000`),
**Group B only** — Awards and ADC are outside it. `allocateAgainstIncomeCap()` groups the Group B
entries by **`bonus_month`** (falling back to the batch month when it is NULL) and fills the room
Fortune → GBB → Rank, so Rank is forfeited first. Room = cap − `monthToDateCappedGrossPaise()`, which
counts **swept** rows only. Everything above the ceiling becomes an `income_cap_forfeit` line.

**Preconditions on the close** before it delegates: `WorkerFreshness::staleReason()` (refuses a worker
running pre-deploy code), then `MonthlyEngineCompletionGate::blockingFailure($month)`. `--force`
downgrades the gate to a warning. `ResolvesMonthOption` + `OpenMonthGuard` refuse an in-flight month
(`compensation:monthly-payout-close` **is** in `FREEZING_COMMANDS`, `OpenMonthGuard.php:41`).

---

## 2. The run (check 2)

```
$ php artisan compensation:monthly-payout-close --month=2026-08
Monthly payout close — crediting month August 2026, batch September 2026
Monthly payout (Groups B/C/D) — September 2026
Batch #2 pending — 0 distributors, net ₹0.00
EXIT=0
```

**No batch was created, no batch was refused, and nothing was skipped** — the run found the
*existing* batch dated 2026-09-01 and returned it untouched. `runMonthlyBatch()` short-circuits
before it does anything at all (`PayoutService.php:406-409`):

```php
if (in_array($batch->status, self::CLOSED_BATCH_STATUSES, true)
    || ($batch->status === PayoutBatch::STATUS_PENDING && $batch->processed_at !== null)) {
    return $batch;
}
```

Batch 2 is `pending` with `processed_at = 2026-09-05 14:03:29`, so it returns *before*
`STATUS_PROCESSING`, before the distributor query and before any sweep. `MonthlyPayoutCommand:66-68`
then maps `pending + processed_at !== null` to **SUCCESS**. So the answer to "empty batch, skipped or
refused?" is **none of the three: a silent success that touches nothing** — see **D1**.

### Before → after

| thing | before | after |
|---|---|---|
| `wallet_ledger_entries` fingerprint (40 rows) | `6e5c95e1ced1d014ba861c1538de75b3` | **identical** |
| `payout_batches` 1/2/3 incl. `updated_at` | 2026-09-05 14:03:29 / … | **identical** |
| `payout_line_items` | 7 rows, max id 7 | **identical** |
| `audit_log` | max 3184 | **max 3184 — no new row** |
| `engine_runs` | max 44 | 48 (+4, below) |

```
| id | engine_key                        | period_start | status    | trigger | summary | error | started_at          | duration_ms |
| 45 | compensation.monthly-payout-close | 2026-08-01   | succeeded | console | NULL    | NULL  | 2026-09-10 17:05:08 |          32 |
| 46 | payout.monthly                    | 2026-09-01   | succeeded | console | NULL    | NULL  | 2026-09-10 17:05:09 |           4 |
| 47 | compensation.monthly-payout-close | 2026-08-01   | succeeded | console | NULL    | NULL  | 2026-09-10 17:05:15 |          39 |
| 48 | payout.monthly                    | 2026-09-01   | succeeded | console | NULL    | NULL  | 2026-09-10 17:05:15 |           4 |
```

**Both keys are recorded**, and note the periods differ by design: the orchestrator against the
crediting month `2026-08-01`, the nested batch against the batch month `2026-09-01`. `summary` is
NULL on both even on the success path (D3 of T13, unchanged).

`laravel.log` for 17:0x contains no ERROR/CRITICAL and no `monthly_payout_close` line (the success
path logs nothing). `failed_jobs` unchanged (max id 238, 2026-09-05).

---

## 3. Second run and idempotence (check 3)

Byte-identical output and exit 0 (`run2.log`), wallet fingerprint unchanged, batch `updated_at`
unchanged. It is a **no-op, not a refusal** — the command has no "already paid" message; it prints
the same success line either way.

**Batch creation is idempotent at the database, not only in PHP:**

```
payout_batches  Non_unique 0  uniq_payout_batch_date_type  (batch_date, batch_type)
```

so the `first()`-then-`create()` in `runMonthlyBatch()` (`:401-411`) cannot be raced into two batches
for one month; a loser would hit the unique key. The crash-resume guard inside the loop
(`PayoutLineItem::where(batch, distributor)->exists()`, `:431-435`) prevents a duplicate line item if
a run dies part-way.

---

## 4. The gate (check 4)

Exercised read-only through `php artisan tinker` (no rows written; the FAILED branch uses **unsaved**
model instances through a reflection call on the private method).

**Never-succeeded branch — live, on a real month with no runs:**

```php
MonthlyEngineCompletionGate::blockingFailure(Carbon::parse('2026-07-01'));
// rank.check / never_succeeded
```
```
Monthly payout refused for July 2026 — the month's crediting is incomplete.
Rank Qualification Check has no succeeded run for July 2026.
Fix it, then re-run the payout close:
  php artisan rank:check-qualifications --month=2026-07
  php artisan compensation:monthly-payout-close --month=2026-07
```

**Failed-and-unresolved branch** — staging has **no** FAILED run among the seven crediting engines
(`SELECT … WHERE engine_key IN (ENGINE_KEYS) AND status='failed'` → 0 rows), so it was driven with
synthetic unsaved runs:

```
unresolvedFailure([failed#999])            => 999
unresolvedFailure([failed#999, later ok])  => null
```
```
Monthly payout refused for August 2026 — the month's crediting is incomplete.
Fortune Bonus Payout FAILED for Aug 2026 (run #999, 08 Sep 2026 04:00) and has not succeeded since.
Fix it, then re-run the payout close:
  php artisan fortune:monthly-run --month=2026-08
  php artisan compensation:monthly-payout-close --month=2026-08
```

The refusal **names the engine, the exact re-run command with its period option, and the close to
re-run afterwards** — as required. The command prints this string verbatim
(`MonthlyPayoutCloseCommand:74 → refuse() → $this->error($message)`), logs
`compensation.monthly_payout_close.refused`, writes an `audit_log` row with the reason, and returns
FAILURE. That path was **not exercised on staging** (no allowed month refuses) — see **D4**.

All seven crediting engines are flag-**on** on staging, so the flag-off carve-out
(`featureFlagIsOff()` → `continue`) is inert here and cannot be waving August through.

**F40 confirmed, verbatim.** The gate's only query is (`MonthlyEngineCompletionGate.php:160-171`):

```php
EngineRun::query()
    ->whereIn('engine_key', self::ENGINE_KEYS)          // the SEVEN crediting engines
    ->whereBetween('period_start', [$monthStart->toDateString(),
        $monthStart->copy()->endOfMonth()->endOfDay()->toDateTimeString()])
    ->orderBy('started_at')->orderBy('id')->get();
```

`ENGINE_KEYS` (`:46-54`) is `rank.check, rank.bonus, gbb.monthly, fortune.enroll, adc.bonus,
fortune.payout, offers.monthly`. **`compensation.monthly-close` is not in it**, so the FAILED,
unresolved close for August (`engine_runs` **37**, no later succeeded close for 2026-08-01) is
invisible: `blockingFailure(2026-08)` returns **`null`** and the month reads "ready to pay". Verified
live, after the run.

**And the same call for September already returns `null`.** `blockingFailure(Carbon::parse('2026-09-01'))
=> NULL` today, on 10 September, because the pre-deploy Sept-dated runs satisfy it — F05's face at the
gate. Combined with **D2** below, the 8 Oct payout close will pass the gate and sweep the entire
wallet unless the F10 cleanup happens first.

---

## 5. Batch 2 — August's batch, holding September's money (check 5)

```
| id | type    | batch_date | earnings_through | status  | dist | gross     | net | processed_at        |
|  2 | monthly | 2026-09-01 | NULL             | pending |    0 | 1,050,000 |   0 | 2026-09-05 14:03:29 |
| line 4 | batch 2 | distributor 33 | wallet 1,050,000 | gross 1,050,000 | net 0 | status web_only |
```

**By the deployed code's convention batch 2 *is* the August payment batch** — crediting month
2026-08 → `addMonthNoOverflow()` → batch_date `2026-09-01`. It is not an orphan and not September's.

But its **content is September's**: the only line is distributor 33's `adc_credit` of ₹10,500 written
`2026-09-05 14:03:29` (`wallet_ledger_entries` id 23, `bonus_month` **NULL** — F09). It was built
pre-deploy by `payout.monthly` run **15** (2026-09-05 14:03:29) and re-run by **31** (2026-09-09
10:30). Held `web_only` because distributor 33 has **0 personal BV** (`bv_ledger_entries` → no rows),
so nothing was swept and no money is at risk.

**No collision on 8 Oct.** The September close is scheduled with `--month = 2026-09` →
batch_date **2026-10-01**, a different row under `uniq_payout_batch_date_type`. Confirmed live
(`2026-09 -> batch 2026-10-01`). Distributor 33 will get a *fresh* `web_only` line in that batch
while the same ₹10,500 keeps its line in batch 2 — F46's double-counting, on the monthly side.

The cost is elsewhere: because batch 2 carries `processed_at`, **the August payout close can never do
anything again** (§2). If August had earned money after 5 Sept, that money would sit unpaid with the
close reporting success. → **D1**.

---

## 6. Dry-run: what the September crediting month pays on 8 Oct (check 6, NOT run)

Batch dated **2026-10-01**. Inputs read from staging (unswept, positive, not reversed):

| entry | dist | type | paise | bonus_month |
|---|---|---|---|---|
| 33 | 1 | `rank_credit` | 1,216,384 | 2026-09-01 |
| 24 | 2 | `rank_credit` | 1,001,000 | 2026-09-01 |
| 27 | 3 | `rank_credit` | 1,001,000 | 2026-09-01 |
| 30 | 4 | `rank_credit` | 500,500 | 2026-09-01 |
| 23 | 33 | `adc_credit` | 1,050,000 | **NULL** |

Matching `repurchase_transfer` debits with `reference_type = rank_bonus_result` (the monthly ref set;
the `gsb_cutoff_result` ones belong to the weekly batch and are **not** touched here):
d1 −121,638 · d2 −100,100 · d3 −100,100 · d4 −50,050.

Gates (all read from staging): d1–d4 `users.status = active`, `bank_account_enc` present, personal BV
280,600 / 14,400 / 14,400 / 500,000 BV ≫ 3,000 BV → **all pass**. d33: personal BV **0** → `web_only`.
Income-cap room is the full ₹50,00,000 each (`monthToDateCappedGrossPaise` counts swept rows only, and
0 rows are swept), so nothing is forfeited; the NULL `bonus_month` on the ADC row is harmless because
Group D is outside the cap.

| dist | gross | −repurchase | wallet_balance | admin 3 % (≤₹25k/group) | payable | TDS 5 % | **net** | status |
|---|---|---|---|---|---|---|---|---|
| 1 | 1,216,384 | 121,638 | 1,094,746 | 36,492 | 1,058,254 | 52,913 | **1,005,341** (₹10,053.41) | `pending` |
| 2 | 1,001,000 | 100,100 | 900,900 | 30,030 | 870,870 | 43,544 | **827,326** (₹8,273.26) | `pending` |
| 3 | 1,001,000 | 100,100 | 900,900 | 30,030 | 870,870 | 43,544 | **827,326** (₹8,273.26) | `pending` |
| 4 | 500,500 | 50,050 | 450,450 | 15,015 | 435,435 | 21,772 | **413,663** (₹4,136.63) | `pending` |
| 33 | 1,050,000 | 0 | 1,050,000 | 0 | — | 0 | **0** | **`web_only`** (0 personal BV) |
| **batch** | **4,768,884** (incl. held) | 371,888 | | **111,567** | | **161,773** | **3,073,656 = ₹30,736.56** | `distributor_count` **4**, deductions 645,228 |

Cross-check: `net + admin + TDS = 3,346,996 = gross − repurchase` to the paise. TDS on d2/d3 is
`round(870,870 × 5 %) = round(43,543.5) = 43,544` — PHP rounds half away from zero, and the code uses
`(int) round(...)` clamped by `min(payable, …)`. Admin on d1 is `round(1,216,384 × 3 %) = round(36,491.52)
= 36,492`; no group is near the ₹25,000 cap. `total_gross_paise` includes d33's held ₹10,500;
`distributor_count` counts only `pending` lines (`finalizeBatchTotals()`, `:995-1019`).

**Nothing above is a target for the 8th unless the F10 cleanup runs first**: the four `rank_credit`
rows are exactly the premature September credits T14 flagged.

---

## 7. `payout:monthly-run` standalone (check 7)

**There is no guard.** `MonthlyPayoutCommand::handle()` is 40 lines and contains, in full: a feature-flag
check, the month parse, the service call, an exception handler. No `MonthlyEngineCompletionGate`, no
`ResolvesMonthOption`, no `OpenMonthGuard`, no closed-period check, no Tuesday-style refusal
(`MonthlyPayoutCommand.php:29-69`). And the default is the **current** month:

```php
$month = $this->option('month')
    ? Carbon::parse((string) $this->option('month').'-01')
    : Carbon::today()->startOfMonth();     //  ← the month in flight
```

`OpenMonthGuard::FREEZING_COMMANDS` (`OpenMonthGuard.php:33-42`) lists eight signatures and
`payout:monthly-run` is **not** one of them, so `--in-flight` is not even needed. `manuallyTriggerable:
false` in the registry (`EngineRegistry.php:304`) closes the admin console only — it has no effect on
the CLI, which is what the scheduler, the runbooks and operators use.

So the gate exists **only** in the orchestrator, and a direct CLI run bypasses the entire 1st→8th
buffer. → **D3**.

---

## Defects

### D1 — **Medium/High (design + data).** A pre-existing processed batch turns the month's payout close into a silent, permanent success that pays nothing
*Repro:* `php artisan compensation:monthly-payout-close --month=2026-08` on staging, twice.
*Expected:* for a month whose crediting produced ₹0, either an empty batch dated 2026-09-01 or an
explicit "nothing to pay" / "already paid on <date>".
*Actual:* exit 0, `Batch #2 pending — 0 distributors, net ₹0.00`, no batch created, no line item, no
audit row — because batch 2 (built pre-deploy on 2026-09-05 out of *September* income) already owns
`batch_date 2026-09-01` and carries `processed_at`, so `runMonthlyBatch()` returns it before doing
anything (`PayoutService.php:406-409`). The output is indistinguishable from a genuine ₹0 month.
*Why it matters:* the operator-visible signal for "this month was paid" and "a foreign batch occupies
this month's date, so this month will never be paid" is the same line and the same exit code. Any
month with unswept credits sitting behind an already-processed batch row is silently never paid, and
the health digest sees a `succeeded` run. On staging it is harmless (August is a ₹0 month; the F10
cleanup deletes batch 2), but the shape is generic.
*Fix direction:* print (and record in `summary`) which batch was returned, whether it was created or
found, and how many unswept credits remain unpaid at exit.

### D2 — **High (design).** The monthly batch has no earning window, so the gate and the batch are not talking about the same month
`runMonthlyBatch()` selects on `swept_by_payout_batch_id IS NULL` alone — **no `bonus_month`, no
`earned_on`, no window of any kind** (`PayoutService.php:414-419`, `:485-491`) — and the monthly path
never writes `earnings_through` (the sole assignment, `:105`, is the weekly path), so the batch page
cannot even display a window. `--month` is only the batch's date, its idempotency key, and the
income-cap fallback month.
Consequence: `MonthlyEngineCompletionGate` certifies that **month M**'s seven crediting engines
succeeded, and the batch then pays **everything unswept in the wallet**, including credits earned
outside M and credits for a month whose engines have not run yet. That is not a theoretical gap —
batch 2 *is* August's payment batch and it holds a September ADC credit. Compare the weekly batch,
which is strictly windowed by `PayoutBatch::weeklyEarningWindow()` and refuses to split a credit from
its deduction.
Live face of it: `blockingFailure(2026-09)` already returns `null` on staging, so on 8 Oct the
September close will pass the gate and sweep every wallet row present at that moment — the premature
September credits included (F05 + F10).
*Fix direction:* window the monthly sweep by `bonus_month <= $creditingMonth` (with an explicit rule
for the NULL `bonus_month` rows F09 leaves behind) and stamp `earnings_through` on the monthly batch
too, so the gate's certificate and the batch's contents describe the same money.

### D3 — **High (code).** `payout:monthly-run` bypasses the gate and defaults to the month in flight
`php artisan payout:monthly-run`, typed with no options on any day, creates a batch dated the 1st of
the **current** month and sweeps every unswept Group B/C/D credit — marking them paid out — with no
completion gate, no closed-period guard (`OpenMonthGuard::FREEZING_COMMANDS` omits it) and no
maker-checker (`manuallyTriggerable: false` binds the admin console only). The entire justification
for splitting crediting (1st) from payment (8th) — "the batch is idempotent per month: once it has
swept the wallet there is nowhere for a late credit to go" (`MonthlyPayoutCloseCommand:20-26`) — is
defeated by the more obvious of the two commands, which is also the one an operator debugging a
missing payout would reach for first.
On staging today it is a no-op only by the accident that batch 2 already occupies 2026-09-01.
*Expected:* the batch command refuses unless the gate passes for its crediting month, or it is not
runnable by hand at all.
*Fix direction:* move the gate check into `MonthlyPayoutCommand` (with `--force` for the deliberate
case), add `payout:monthly-run` to `FREEZING_COMMANDS`, and drop the current-month default in favour
of the previous month.

### D4 — **Medium (observability).** A deliberate refusal is recorded as `failed` with no error text
`refuse()` returns `self::FAILURE`, and `RecordEngineRun::finished()` maps a non-zero exit to
`status = failed` while leaving `error` NULL (`RecordEngineRun.php:143-155`) — so an intentional "the
month is not ready" refusal is written to `engine_runs` exactly like a crashed payout, and the Engine
Runs page and the health digest, which read the run row, show a failure with no cause. Partially
mitigated here (unlike F43): `refuse()` does write `audit_log
compensation.monthly_payout_close.refused` carrying the reason — but neither surface reads the audit
log. Unlike the weekly non-Tuesday case the failure *is* resolvable (re-run the engine, re-run the
close), so this is the milder member of the family.
**Not exercised on staging** — no month inside this task's remit refuses. Source-derived; the audit
row and the run row for a refusal remain unverified.

*Not defects, recorded:* F09 re-confirmed live (`wallet_ledger_entries` 23, `adc_credit`,
`bonus_month NULL`) — harmless in the monthly batch today because Group D is outside the income cap,
but it is exactly the row D2's proposed window filter would have to make a decision about.
F40 confirmed with the query quoted (§4). `summary` NULL on a succeeded run (T13 D3) unchanged.

---

## Mutations made on staging

| Table | Change | Before → after |
|---|---|---|
| `engine_runs` | +4 rows: **45**, **47** (`compensation.monthly-payout-close`, `2026-08-01`, `succeeded`), **46**, **48** (`payout.monthly`, `2026-09-01`, `succeeded`) | 44 rows → 48 |
| `wallet_ledger_entries` | **none** — fingerprint `6e5c95e1ced1d014ba861c1538de75b3`, 40 rows, before and after | — |
| `payout_batches` | **none** — all three rows incl. `updated_at` identical | — |
| `payout_line_items` | **none** — 7 rows, max id 7 | — |
| `audit_log` | **none** — max id 3184 before and after | — |

Commands run on staging: `compensation:monthly-payout-close --month=2026-08` ×2 (both exit 0), and
two read-only `php artisan tinker` calls (gate queries + a reflection call on unsaved model
instances). No `--force`, no `--in-flight`, no `payout:monthly-run` invoked directly, no September
period passed to any command by hand, no recompute, no batch approved or dispatched, no row deleted.
Nothing was written to the server filesystem.

---

## Notes for the orchestrator (≤ 10 lines)

1. **D3 is the one to fix before launch**: `payout:monthly-run` has no gate, no closed-period guard,
   and defaults to the *current* month — one hand-typed command pays an unfinished month.
2. **D2 next**: the monthly batch has no earning window at all, so the gate certifies month M while
   the batch pays the whole wallet. It is why batch 2 (August's batch) holds September income.
3. `blockingFailure(2026-09)` **already returns null**. On 8 Oct the September close passes the gate
   and, per D2, sweeps everything present — do the F10 cleanup before the 1st, not before the 8th.
4. F40 confirmed with the query quoted; add "`ENGINE_KEYS` excludes `compensation.monthly-close`" to
   the register entry.
5. Batch 2 is **not** an orphan — it is August's payment batch by the code's convention, and it makes
   the August close a permanent no-op (D1). It collides with nothing on 8 Oct (batch 2026-10-01).
6. Acceptance target for T32 / the October batch: **₹30,736.56 net to 4 distributors** (d1 ₹10,053.41,
   d2 ₹8,273.26, d3 ₹8,273.26, d4 ₹4,136.63), d33 held `web_only` on 0 personal BV — assuming the
   September credits survive the cleanup decision.
7. Checks 2/3/5 are fully verified; check 4's *refusal-as-a-command* path (audit row + failed run) is
   source-derived only — re-task with a refusing month (e.g. `--month=2026-07`) if you want it live,
   accepting one unresolvable `failed` run in the digest.
