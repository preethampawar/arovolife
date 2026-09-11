# T14 — In-flight-month (September 2026) engine-results audit

Verdict: **FAIL**

Scope: READ-ONLY. No engine was run, no row was written, no artisan command was executed on staging.
All evidence is `SELECT`-only over SSH plus the local repo at `6f114500`.

---

## Headline

Every one of the seven `compensation:monthly-close` steps already has a **SUCCEEDED**
`engine_runs` row for `period_start = 2026-09-01`. `MonthlyCloseCommand` resumes past any step
with such a row, so at **01 Oct 2026 00:20 IST the scheduled close for September will skip all
seven steps and exit SUCCESS without recomputing anything** — outcome **(a) silent skip**.

September is priced on **17.89 lakh BV** (frozen 05 Sep 14:03) against **20.39 lakh BV** actually
accrued — **12.3 % short**, and short by more with every further September sale. Nothing in the
new code recomputes it, and the 8 Oct payout close will not refuse: it also reads
"succeeded for 2026-09" and will pay the September rank credits out of the October batch.

The September rows were **not** produced by the schedule. They were produced by
`compensation:recompute-all` queued from the admin console by the developer account
(`audit_log` 3140/3151, actor 242, 2026-09-05 14:03:27→31), plus one later manual
`rank:monthly-run` (engine run 17, 14:49:52).

---

## Checks

| # | Check | Result | Evidence |
|---|-------|--------|----------|
| 1 | Which September result rows exist, per engine | See "Row census" below | single UNION query over 15 tables |
| 2 | Are September credits already swept into a payout batch? | **No** — every `wallet_ledger_entries.swept_by_payout_batch_id` is NULL | query below |
| 3 | Is anything actually paid? | **No** — all 7 `payout_line_items` are `no_bank_account`/`web_only`, `net_transferred_paise = 0`; all 3 batches `pending` | query below |
| 4 | 01 Oct `compensation:monthly-close --month=2026-09` | **(a) SKIPS all 7 steps** | `MonthlyCloseCommand:139` |
| 4b | …and with `--restart`? | **Aborts at step 2** (Rank Bonus throws) | `RankBonusService::refuseUnfrozenPaidMonth()` |
| 5 | 08 Oct `compensation:monthly-payout-close --month=2026-09` | **Does NOT refuse**; runs `payout:monthly-run --month=2026-10`, sweeps September's ₹37,188.84 rank credits into a new October batch | `MonthlyPayoutCloseCommand:70`, `PayoutService:413` |
| 6 | The 3 existing pending batches | Permanently closed to re-processing (`pending` + `processed_at`); their held credits are re-offered to the next batch | `PayoutService:111-114`, `:406-409` |
| 7 | Next Tuesday (15 Sep) `gsb:weekly-payout` | Creates a **new** batch dated 2026-09-15; batches 1 and 3 untouched; the Sep 4–6 GSB/MB credits are re-offered because they were never swept | `PayoutService:93-124` |
| 8 | `rank_monthly_pools` for a month with 4 credited `rank_bonus_results` | **0 rows** — the "unfrozen paid month" state the code explicitly refuses | `SELECT COUNT(*) = 0`; table created by a migration dated **after** the rows |
| 9 | `purchase_offer_grants` recoverable by a recompute? | **No** — the table is absent from `DerivedTables` | `grep purchase_offer_grants DerivedTables.php` → no match |

---

## Row census (staging, 2026-09-10)

```sql
SELECT "rank_qualifications" t, month_start p, status s, COUNT(*) n, NULL g, NULL nt FROM rank_qualifications GROUP BY 2,3
UNION ALL SELECT "rank_bonus_results", month_start, status, COUNT(*), SUM(gross_paise), SUM(net_paise) FROM rank_bonus_results GROUP BY 2,3
UNION ALL SELECT "rank_monthly_pools", month_start, CONCAT("rank",rank_number), COUNT(*), SUM(pool_paise), SUM(payout_paise) FROM rank_monthly_pools GROUP BY 2,3
UNION ALL SELECT "rank_aogo_grants", month_start, status, COUNT(*), SUM(income_paise), NULL FROM rank_aogo_grants GROUP BY 2,3
UNION ALL SELECT "gbb_monthly_results", `year_month`, status, COUNT(*), SUM(gbb_gross_paise), SUM(gbb_net_paise) FROM gbb_monthly_results GROUP BY 2,3
UNION ALL SELECT "gbb_monthly_pools", month_start, "-", COUNT(*), SUM(pool_paise), SUM(payout_paise) FROM gbb_monthly_pools GROUP BY 2,3
UNION ALL SELECT "adc_bonus_results", month_start, status, COUNT(*), SUM(gross_paise), SUM(net_paise) FROM adc_bonus_results GROUP BY 2,3
UNION ALL SELECT "fortune_bonus_participants", month_start, "-", COUNT(*), NULL, NULL FROM fortune_bonus_participants GROUP BY 2,3
UNION ALL SELECT "fortune_bonus_results", month_start, status, COUNT(*), SUM(gross_paise), SUM(net_paise) FROM fortune_bonus_results GROUP BY 2,3
UNION ALL SELECT "fortune_monthly_pools", month_start, "-", COUNT(*), SUM(pool_paise), SUM(payout_paise) FROM fortune_monthly_pools GROUP BY 2,3
UNION ALL SELECT "purchase_offer_grants", month_start, status, COUNT(*), NULL, NULL FROM purchase_offer_grants GROUP BY 2,3
UNION ALL SELECT "mentorship_bonus_results", cutoff_date, status, COUNT(*), SUM(mb_gross_paise), NULL FROM mentorship_bonus_results GROUP BY 2,3
UNION ALL SELECT "gsb_cutoff_results", cutoff_date, status, COUNT(*), SUM(gross_gsb_paise), SUM(net_gsb_paise) FROM gsb_cutoff_results GROUP BY 2,3
UNION ALL SELECT "gsb_daily_pools", cutoff_date, "-", COUNT(*), SUM(pool_paise), SUM(fixed_payout_paise+variable_payout_paise) FROM gsb_daily_pools GROUP BY 2,3
UNION ALL SELECT "msb_daily_pools", cutoff_date, "-", COUNT(*), SUM(pool_paise), SUM(payout_paise) FROM msb_daily_pools GROUP BY 2,3
ORDER BY 1,2,3;
```

```
+--------------------------+------------+-------------+-----+----------+---------+
| t                        | p          | s           | n   | g(paise) | net     |
+--------------------------+------------+-------------+-----+----------+---------+
| adc_bonus_results        | 2026-09-01 | credited    |   1 |  1050000 | 1050000 |
| fortune_monthly_pools    | 2026-08-01 | -           |   1 |        0 |       0 |
| fortune_monthly_pools    | 2026-09-01 | -           |   1 |  8944000 |       0 |
| gbb_monthly_pools        | 2026-09-01 | -           |   1 |  8944000 |       0 |
| gsb_cutoff_results       | 2026-09-04 | below_600bv | 310 |        0 |       0 |
| gsb_cutoff_results       | 2026-09-04 | credited    |   3 |  1600000 | 1440000 |
| gsb_cutoff_results       | 2026-09-04 | no_match    |   4 |        0 |       0 |
| gsb_cutoff_results       | 2026-09-05 | below_600bv | 310 |        0 |       0 |
| gsb_cutoff_results       | 2026-09-05 | credited    |   3 |  1000000 |  900000 |
| gsb_cutoff_results       | 2026-09-05 | no_match    |   4 |        0 |       0 |
| gsb_cutoff_results       | 2026-09-06 | below_600bv | 310 |        0 |       0 |
| gsb_cutoff_results       | 2026-09-06 | credited    |   1 |    25600 |   23040 |
| gsb_cutoff_results       | 2026-09-06 | no_match    |   6 |        0 |       0 |
| gsb_cutoff_results       | 2026-09-07 | below_600bv | 310 |        0 |       0 |
| gsb_cutoff_results       | 2026-09-07 | no_match    |   7 |        0 |       0 |
| gsb_cutoff_results       | 2026-09-08 | below_600bv | 310 |        0 |       0 |
| gsb_cutoff_results       | 2026-09-08 | no_match    |   7 |        0 |       0 |
| gsb_cutoff_results       | 2026-09-09 | below_600bv | 310 |        0 |       0 |
| gsb_cutoff_results       | 2026-09-09 | no_match    |   7 |        0 |       0 |
| gsb_daily_pools          | 2026-09-04 | -           |   1 | 55323000 | 1600000 |
| gsb_daily_pools          | 2026-09-05 | -           |   1 | 25173000 | 1000000 |
| gsb_daily_pools          | 2026-09-06 | -           |   1 |    27000 |   25600 |
| gsb_daily_pools          | 2026-09-07..09 | -       |   3 |        0 |       0 |
| mentorship_bonus_results | 2026-09-04 | credited    |   2 |  3686400 |    NULL |
| mentorship_bonus_results | 2026-09-05 | credited    |   2 |  1677000 |    NULL |
| msb_daily_pools          | 2026-09-04 | -           |   1 |  3688200 | 3686400 |
| msb_daily_pools          | 2026-09-05 | -           |   1 |  1678200 | 1677000 |
| msb_daily_pools          | 2026-09-06 | -           |   1 |     1800 |       0 |
| msb_daily_pools          | 2026-09-07..09 | -       |   3 |        0 |       0 |
| purchase_offer_grants    | 2026-09-01 | granted     |  63 |     NULL |    NULL |
| rank_aogo_grants         | 2026-09-01 | credited    |   1 |   500500 |    NULL |
| rank_bonus_results       | 2026-09-01 | credited    |   4 |  3718884 | 3346996 |
| rank_qualifications      | 2026-08-01 | qualified   |   1 |     NULL |    NULL |
| rank_qualifications      | 2026-09-01 | qualified   |   4 |     NULL |    NULL |
+--------------------------+------------+-------------+-----+----------+---------+
```

Empty tables (0 rows, whole table): **`rank_monthly_pools`**, **`gbb_monthly_results`**,
**`fortune_bonus_participants`**, **`fortune_bonus_results`**.

September BV actually accrued vs frozen:

```sql
SELECT DATE(created_at) d, SUM(bv_paise) bv, COUNT(*) n FROM bv_ledger_entries
WHERE created_at >= "2026-09-01" GROUP BY 1 WITH ROLLUP;
-- 09-04 122,940,000 | 09-05 80,940,000 | 09-06 60,000 | TOTAL 203,940,000
```

`gbb_monthly_pools` / `fortune_monthly_pools` for September froze
`company_bv_paise = 178,880,000`. Missing **25,060,000 paise (2.51 lakh BV, 12.3 %)** — and
every September sale from now on widens the gap, because nothing will re-freeze it.

---

## 2. Trace of every September row to the wallet and to payouts

```sql
SELECT type, bonus_month, earned_on, engine_run_id, swept_by_payout_batch_id AS swept,
       COUNT(*) n, SUM(amount_paise) amt
FROM wallet_ledger_entries GROUP BY 1,2,3,4,5 ORDER BY 2,1,3;
```

```
| type                   | bonus_month | earned_on  | run | swept | n | amt      |
| adc_credit             | NULL        | NULL       |  12 | NULL  | 1 |  1050000 |
| repurchase_wallet_used | NULL        | NULL       | NULL| NULL  | 2 |  -216900 |
| gsb_credit             | 2026-09-01  | 2026-09-04 |   1 | NULL  | 3 |  1600000 |
| gsb_credit             | 2026-09-01  | 2026-09-05 |   3 | NULL  | 3 |  1000000 |
| gsb_credit             | 2026-09-01  | 2026-09-06 |  20 | NULL  | 1 |    25600 |
| mb_credit              | 2026-09-01  | 2026-09-04 |   1 | NULL  | 2 |  3686400 |
| mb_credit              | 2026-09-01  | 2026-09-05 |   3 | NULL  | 2 |  1677000 |
| rank_credit            | 2026-09-01  | NULL       |  17 | NULL  | 4 |  3718884 |
| repurchase_deduction   | 2026-09-01  | NULL       |  17 | NULL  | 4 |   371888 |
| repurchase_deduction   | 2026-09-01  | 2026-09-04 |   1 | NULL  | 3 |   160000 |
| repurchase_deduction   | 2026-09-01  | 2026-09-05 |   3 | NULL  | 3 |   100000 |
| repurchase_deduction   | 2026-09-01  | 2026-09-06 |  20 | NULL  | 1 |     2560 |
| repurchase_transfer    | 2026-09-01  | NULL       |  17 | NULL  | 4 |  -371888 |
| repurchase_transfer    | 2026-09-01  | 2026-09-04 |   1 | NULL  | 3 |  -160000 |
| repurchase_transfer    | 2026-09-01  | 2026-09-05 |   3 | NULL  | 3 |  -100000 |
| repurchase_transfer    | 2026-09-01  | 2026-09-06 |  20 | NULL  | 1 |    -2560 |
```

**Nothing is swept** (`swept_by_payout_batch_id` NULL on every row) and **nothing is paid**:

```
payout_batches:
| 1 | weekly  | 2026-09-05 | earnings_through NULL | pending | gross 7963400 | net 0 | dist 0 | processed 2026-09-05 14:03:29 |
| 2 | monthly | 2026-09-01 | earnings_through NULL | pending | gross 1050000 | net 0 | dist 0 | processed 2026-09-05 14:03:29 |
| 3 | weekly  | 2026-09-08 | earnings_through NULL | pending | gross 7989000 | net 0 | dist 0 | processed 2026-09-08 09:00:04 |

payout_line_items: 7 rows, all net_transferred_paise = 0
| 1 | b1 | d1 | 6563400 | no_bank_account |   | 4 | b2 | d33 | 1050000 | web_only |
| 2 | b1 | d2 |  800000 | no_bank_account |   | 5 | b3 | d1  | 6589000 | no_bank_account |
| 3 | b1 | d3 |  600000 | no_bank_account |   | 6 | b3 | d2  |  800000 | no_bank_account |
                                              | 7 | b3 | d3  |  600000 | no_bank_account |
```

The ADC credit (d33, ₹10,500, `bonus_month` NULL) is the only September monthly credit that
reached a batch — held `web_only`, wallet never debited. The 4 rank credits (₹37,188.84) were
written at 14:49:52, i.e. **after** monthly batch 2 was processed at 14:03:29, so they sit in the
wallet with no batch at all. `earnings_through = NULL` on all three batches is benign: the column
was added by today's deploy and is only stamped at batch creation.

---

## 3. What runs on 01 Oct 2026 — answer: **(a) silent skip**

Scheduler (`routes/console.php:88-94`):

```php
Schedule::command(MonthlyCloseCommand::class, [
    '--month' => now('Asia/Kolkata')->subMonthNoOverflow()->format('Y-m'),   // 2026-09
])->monthlyOn(1, '00:20')->timezone('Asia/Kolkata')…
```

`MonthlyCloseCommand::handle()`, lines 133-146:

```php
foreach (self::STEPS as $index => $key) {
    $definition = EngineRegistry::get($key);
    $period = MonthlyEngineCompletionGate::periodFor($definition, $month);   // 2026-09-01
    …
    if (! $restart && $this->status->hasSucceededRun($key, $definition->periodStart($period))) {
        $this->line("{$label}: already succeeded for … — resuming past it.");
        continue;                                    // ← every September step lands here
    }
```

`EngineStatusService::hasSucceededRun()` (lines 46-53) matches on **engine key + period date +
status only** — it has no notion of *when* the run happened or of whether the month was in flight:

```php
return EngineRun::query()
    ->where('engine_key', $key)
    ->whereDate('period_start', $period->toDateString())
    ->where('status', EngineRun::STATUS_SUCCEEDED)
    ->exists();
```

Every one of `MonthlyEngineCompletionGate::ENGINE_KEYS` has such a row for `2026-09-01`:

```
 7 offers.monthly  2026-09-01 succeeded  2026-09-05 14:03:29
 9 gbb.monthly     2026-09-01 succeeded  2026-09-05 14:03:29
10 rank.check      2026-09-01 succeeded  2026-09-05 14:03:29
11 rank.bonus      2026-09-01 succeeded  2026-09-05 14:03:29
12 adc.bonus       2026-09-01 succeeded  2026-09-05 14:03:29
13 fortune.enroll  2026-09-01 succeeded  2026-09-05 14:03:29
14 fortune.payout  2026-09-01 succeeded  2026-09-05 14:03:29
17 rank.bonus      2026-09-01 succeeded  2026-09-05 14:49:52
```

Every feature flag is ON (QA baseline), so no step is excused as flag-off either. The close prints
seven "already succeeded — resuming past it" lines and returns SUCCESS. **September is never
repriced.** The under-payment is silent and permanent.

### Why the individual engines' self-heal never fires

The engines *do* carry a premature-freeze self-heal, and it would work here — but only if they were
invoked, which they will not be:

* GBB — `GrowthBoosterBonusService::replacePrematureFreeze()` (line 354-360) tests
  `created_at < month-close` and would delete the September pool, because **zero**
  `gbb_monthly_results` rows exist, so the `POOL_FUNDED_STATUSES` check at line 372 does not block.
  Same for Fortune (`FortuneBonusService:753`, zero credited results — and deleting the pool is the
  only thing that **re-opens Fortune enrolment**, which the premature pool has closed for September
  for good).
* Rank — **cannot** self-heal. `RankBonusService::runForMonth()` (lines 131-141):

  ```php
  $pools = $this->frozenPools($monthStart);                    // EMPTY on staging
  if ($pools->isNotEmpty() && $this->replacePrematureFreeze(...)) { $pools = collect(); }
  if ($pools->isEmpty()) {
      $this->refuseUnfrozenPaidMonth($monthStart);             // ← throws
  ```

  and `refuseUnfrozenPaidMonth()` (lines 452-479) throws a `RuntimeException` the moment the month
  has ≥ 1 `credited`/`reversed` result and no pool row — exactly staging's state (4 credited,
  0 pools). So **`--restart` does not rescue September either: the close aborts at step 2/7** and
  steps 3–7 never run.

  Root cause of the missing pool rows: `rank_monthly_pools` is created by
  `app/Modules/Compensation/Database/Migrations/2026_09_07_100000_create_rank_monthly_pools_table.php`,
  which **first ran on staging at today's deploy (10:40 IST)**. The 4 September results were written
  on 05 Sep by the pre-freeze engine. The migration performs **no backfill** (`up()` is a bare
  `Schema::create`), so every pre-existing month is left in the "unfrozen paid month" state the new
  code refuses to touch.

---

## 4. What runs on 08 Oct, and next Tuesday

**08 Oct 04:00 — `compensation:monthly-payout-close --month=2026-09`**

`MonthlyEngineCompletionGate::blockingFailure(2026-09)` looks for an *unresolved FAILED run* or an
*absent SUCCEEDED run* per engine. September has a SUCCEEDED run for all seven → **returns null →
the gate does not refuse.** It then calls (`MonthlyPayoutCloseCommand:79`):

```php
$batchMonth = $month->copy()->addMonthNoOverflow()->startOfMonth();   // 2026-10-01
Artisan::call('payout:monthly-run', ['--month' => $batchMonth->format('Y-m')]);
```

`PayoutService::runMonthlyBatch()` selects unswept Group B/C/D credits with **no month filter**
(lines 413-418):

```php
$distributorIds = WalletLedgerEntry::whereIn('type', $allMonthlyTypes)
    ->whereNull('swept_by_payout_batch_id')
    ->where('amount_paise', '>', 0)
    ->notReversed()->distinct()->pluck('distributor_id');
```

⇒ the four September `rank_credit` rows (₹37,188.84) **will be swept into the new October batch**
and paid (subject to the KYC/bank gates), on economics frozen against a 12 %-short September.
Money is not lost — it is paid on the wrong number.

The **existing** monthly batch 2 (2026-09-01) is inert: `pending` **with** `processed_at` set is a
terminal state for the runner (lines 406-409 `return $batch;`), so it will never re-sweep. It would
only be re-processed if a batch dated 2026-09-01 were requested again, which nothing does.

**Next Tuesday (15 Sep) 03:00 — `gsb:weekly-payout`** creates a *new* batch dated 2026-09-15
(`weeklyEarningWindow` end = 2026-09-08) and sweeps the unswept Group A credits earned 04–06 Sep.
Batches 1 (2026-09-05, a **Saturday** — created off-cycle by the recompute) and 3 (2026-09-08)
are skipped by the same `pending + processed_at` guard (lines 111-114). Their held line items moved
no money and their wallet entries were never marked swept, so nothing is stranded — the held
distributors are simply re-offered to whichever batch first finds them with bank + KYC on file.

---

## Defects

### D1 — **Critical.** A month closed early keeps its premature pricing for ever; the new close silently skips it
*Repro:* any monthly engine run for month M while M is in flight (recompute-all with a horizon
inside M, an admin manual trigger, the pre-deploy schedule) writes a SUCCEEDED `engine_runs` row
for `period_start = M-01`. On the 1st of M+1 `compensation:monthly-close --month=M` skips every
step that has such a row.
*Expected:* the close recomputes M on the full month's BV, or refuses loudly.
*Actual:* it prints "already succeeded … resuming past it" seven times and exits 0. September 2026
stays priced on 17.89 lakh BV against 20.39 lakh actual (−12.3 %), and the 8 Oct payout close does
not refuse either, because `MonthlyEngineCompletionGate` asks the same question.
*Fix direction:* `hasSucceededRun()` is not a sufficient resume key. The resume check must also
require that the run happened **after the period closed** — the same rule
`replacePrematureFreeze()` already applies to pool rows (`created_at >= monthEnd + 1 day`), and the
same rule `EngineStatusService::hasSucceededRunAfterDay()` already implements for the daily
cut-off. A run stamped inside its own period should be treated as *not* a completed period.

### D2 — **High.** `rank_monthly_pools` migration has no backfill, leaving every pre-existing month unrepairable
`2026_09_07_100000_create_rank_monthly_pools_table.php::up()` only creates the table. Staging now
has 4 `credited` `rank_bonus_results` for 2026-09-01 and 0 pool rows — the exact state
`RankBonusService::refuseUnfrozenPaidMonth()` throws on. Any later Rank Bonus run for that month
(including `compensation:monthly-close --restart`) aborts with a `RuntimeException`, taking steps
3–7 of the close with it. Production will hit this on the first month that was credited before this
migration ships. Either backfill a pool row from the credited results, or make the refusal
non-fatal for a month whose results predate the table.

### D3 — **High.** `purchase_offer_grants` is missing from `DerivedTables`, so no recompute can fix September's offers
`grep purchase_offer_grants app/Modules/Compensation/Support/DerivedTables.php` → no match. The 63
September grants (`granted`, `qualifying_bv_paise` recorded from partial-month BV) survive both a
full and a windowed recompute, and `PurchaseOfferService::grantHalfPrice()` refuses to re-grant
(`alreadyGranted()`, line 294). Consequence: a distributor who was below `qualifying_bv_paise` on
05 Sep but crosses it by 30 Sep is permanently denied their September offer, and no tool in the repo
can repair it. This is precisely the drift the `DerivedTables` docblock warns about
("rank_aogo_grants, gsb_personal_bv_topups and engine_runs were all added … and silently survived").

### D4 — **Medium.** Fortune enrolment for September is closed by a premature pool
`fortune_monthly_pools` id 1 (`month_start 2026-09-01`, frozen 05 Sep 14:03) exists with
`total_points = 0` and **zero** `fortune_bonus_participants`. `FortuneBonusService::enrollEligible()`
refuses the moment a pool row exists, so nobody can enrol for September. Self-healing requires the
Fortune engine to be *invoked* — which D1 guarantees it will not be.

### D5 — **Low.** `adc_credit` wallet entries carry `bonus_month = NULL`
`AreteDevelopmentCenterBonusService.php:127-135` calls `WalletService::credit()` without
`bonusMonth`, although the parameter exists (`WalletService.php:144`) and every other monthly
engine passes it. Entry id 23 (₹10,500) therefore cannot be attributed to an earned month by any
report that groups on `bonus_month`. ADC is Group D and outside the ₹50 L Group-B cap allocation,
so no money is mis-paid today — but it is an inconsistency across the five bonus engines.

---

## Recommended cleanup — DO NOT RUN WITHOUT THE USER'S EXPLICIT CONSENT

**Admin → Compensation → Engine Runs → "Recompute", with:**

| Field | Value |
|---|---|
| From | `2026-09-01` |
| To | *(blank — defaults to today)* |
| Engines | *(leave every box clear = all engines)* |
| **Windowed** | **ticked** |

Route: `POST /admin/compensation/engine-runs/recompute-all`
(`routes/web.php:622`, permission `finance.record`), which dispatches
`RecomputeAllJob(from: '2026-09-01', to: null, onlyEngineKeys: null, windowed: true)` onto the
`compensation` queue (`AdminEngineRunsController:214`). There is **no artisan equivalent left in the
repo** — `compensation:recompute-all` exists only as prose in a comment; the console command has
been removed and the admin button is the only entry point.

**What it deletes/rewrites** (`WindowedStateWiper` + `DerivedTables::DATE_COLUMNS`), scoped to rows
dated on/after 2026-09-01 (monthly tables from the month's first day):
`group_bv_credits`, `group_bv_reversals`, `group_bv_debts` (rewound arithmetically),
`bv_propagation_log`, `group_bv_daily`, `payout_line_items` + `payout_batches` (batches **1, 2, 3**),
`wallet_ledger_entries` whose source row is being rebuilt (`repurchase_wallet_used` is preserved),
`gsb_cutoff_results`, `gsb_carryforward` (rewound from each row's `*_before` columns),
`gsb_personal_bv_topups`, `gsb_daily_pools`, `msb_daily_pools`, `mentorship_bonus_results`,
`gbb_monthly_results` + `gbb_monthly_pools`, `rank_bonus_results` + `rank_monthly_pools` +
`rank_aogo_grants` + `rank_qualifications`, `lifetime_award_milestones`, `fortune_bonus_results` +
`fortune_bonus_participants` + `fortune_monthly_pools` (+ levels via FK cascade),
`adc_bonus_results`, `repurchase_cycles` (verdicts reset), and **`engine_runs`** — the last is the
one that actually clears D1's skip, because
`EngineStatusService::isPeriodComputed()` reads a surviving succeeded row as "already done".
It then re-derives group BV from paid orders and replays every engine day by day to today, with the
in-flight month recomputed by the catch-up pass (`EngineReplayService:207`, `OpenMonthGuard::overrideFor`).

Notes and caveats the orchestrator must weigh before approving:

1. This is a **destructive** operation on staging data (three payout batches, 23 wallet entries,
   ~2,000 result rows). Global rule "Destructive DB actions — always warn first" applies; the QA
   plan's Rule 1 also bans `recompute-all` outright, so the user has to lift that for the windowed
   variant explicitly.
2. It will **not** fix D3: the 63 `purchase_offer_grants` survive and are never re-evaluated.
   If September's offers must be correct, those 63 rows have to be deleted by hand first
   (`DELETE FROM purchase_offer_grants WHERE month_start = '2026-09-01'` — 0 are `consumed`, so
   nothing is orphaned) — again, only with explicit consent.
3. September is still an **in-flight** month, so the replay's September freeze is provisional by
   construction and will itself be premature the moment the next order lands. That is fine for QA
   as long as **T13/T15 test closed months only**, but it means the cleanup must be re-run (or
   accepted as provisional) at the end of the QA window.
4. Run it **before** T10–T15 and T30–T32. Every one of those tasks reads
   `engine_runs` / pools / batches, and the current September rows will make their results
   uninterpretable (e.g. T30's "in-flight refusal" check and T15's monthly-batch gate).
5. Do it while nothing else is driving the app — the job takes the `compensation:recompute:all`
   cache lock for up to 2 h and runs on the single `compensation` worker.

**Alternative if the user refuses a recompute:** deleting only the September rows from
`engine_runs` would un-stick D1's skip, but the close would then abort at step 2 on D2's
`refuseUnfrozenPaidMonth()` throw. There is no safe surgical fix short of the windowed recompute.

---

## Mutations made on staging

**None.** Every statement issued was a `SELECT` / `SHOW`. No artisan command, no engine, no write.

---

## Notes for the orchestrator (≤ 10 lines)

1. D1 is a production bug, not a staging-data artefact — it will bite the first real month that is
   ever touched mid-flight. Route it to the compensation owner, not to the QA cleanup.
2. D2 will fire on the **first production deploy** of the `rank_monthly_pools` migration if any
   month was credited before it. Needs a backfill migration.
3. D3 needs `purchase_offer_grants` (and check `redeem_point_entries` / `lifetime_award_rewards`
   too) added to `DerivedTables::TABLES` + `DATE_COLUMNS`.
4. Ask the user to lift plan Rule 1 for the **windowed** recompute above before any other engine
   task runs; T10–T15 and T30–T32 are unreliable until it has.
5. T13 must use `--month=2026-08` (a genuinely closed month) as planned — do not let it target
   September.
6. Fortune September enrolment is closed (D4); any T22/T31 Fortune check will read empty until the
   cleanup runs.
