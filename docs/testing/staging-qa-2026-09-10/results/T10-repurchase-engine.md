# T10 — repurchase engine (`repurchase:evaluate`, cycles, forfeit model) on staging

Verdict: **PASS-with-notes** (2 real defects, both in the legacy-row backfill, not in the new engine)

Scope: SSH + MySQL + code reading on `origin/main` @ `6f114500`. No browser. `repurchase:evaluate` run
3× (today, and `--date=2026-09-09` twice). No pool-freezing engine and no recompute was run.

---

## 1. The model, as the code actually implements it

`RepurchaseCycleService` (+ `RepurchaseCycle`, `IncomeEligibilityService`):

| Element | Code | Matches spec §1? |
|---|---|---|
| Day 0 | `repurchaseAnchor()` = `BvLedgerService::firstReachedBvPaiseAt(distributor, comp.gsb.min_bv_paise)` — the day personal BV first crosses 600 BV | yes |
| Due | `openCycle()`: `$due = $start->copy()->addDays($plan->repurchaseCycleDays())` — start **+ 30** (setting `comp.repurchase.cycle_days` absent on staging → registry default `30`, `repurchaseCycleDays()` returned `30` via tinker). Test `RepurchaseCycleServiceTest:108-115` pins all six client examples (7 Jul → 6 Aug …). | yes |
| Verdict | `refresh()` → `resolveAtWindowEnd()`, taken **once**, only when `asOf > due_date`, guarded by `resolved_at === null`. Both conditions at the window's last instant: (A) `selfPurchaseBvPaise(start, dueEnd) >= required_bv_paise`; (B) `walletBalanceAt(dueEnd) <= 0`. | yes |
| Freeze | `wallet_balance_paise = max(0, balance)`, `wallet_zeroed`, `resolved_at = now()` written at that moment and never rewritten (only lazily back-filled in `applyLateFulfilment()` if NULL). | yes |
| Grace | none. `comp.repurchase.grace_days` is **dead code** — `grep -rn grace_days app/` hits only a migration docblock; no `STATUS_GRACE`, no `grace_end_date` column. | yes |
| Forfeit window | `RepurchaseCycle::forfeitedWindow()` = `[due+1, fulfilled_on−1]`, null when fulfilled on time / unresolved / fulfilled on `due+1`. Single source of truth, read by `verdictAsOf()` and `forfeitedDayRanges()`. | yes |
| `fulfilled_on` | The day both conditions first hold. **On an on-time pass it is stamped = `due_date`** (`resolveAtWindowEnd()`), not the day the BV was bought. On a late pass it is the real fulfilment day (`applyLateFulfilment()` day-walk). | yes |

**So `fulfilled_on = 2026-10-03` (= due date) is the correct SHAPE for an on-time pass — but on these 7 rows
it is a leftover, and it is a leftover of a different kind than the brief assumed.** It was not written by
the old hold-and-release engine; it was written by the deploy migration
`2026_09_06_100003_backfill_verdicts_on_existing_repurchase_cycles`, which does:

```php
DB::table('repurchase_cycles')->whereNull('resolved_at')->where('status','completed')
  ->update(['fulfilled_on' => DB::raw('due_date'), 'resolved_at' => DB::raw('COALESCE(completed_at, updated_at)'), 'failure_reason' => null]);
```

Its own docblock says *"Cycles still inside their window are untouched — they resolve normally."* **It has no
such filter.** These 7 windows close on 2026-10-03, i.e. they were in flight when the migration ran on
2026-09-10, and it settled them anyway — hence `resolved_at = completed_at = 2026-09-05 14:03:29`
(the pre-deploy recompute's timestamp) on every row. → **D1** below.

**Does the new evaluate self-correct it?** No, and by design it cannot: the undo added in `8a0e9914`
covers a premature **failed** verdict only —

```php
if ($cycle->resolved_at === null)            { $cycle->status = ACTIVE; }
elseif ($cycle->status !== STATUS_COMPLETED) { /* audit + reset to ACTIVE, null the freeze */ }
```

A premature **completed** verdict falls through both arms and is left standing forever. Confirmed
empirically in check 2: three evaluate runs left `updated_at` untouched on all 7 rows, and
`SELECT COUNT(*) FROM audit_log WHERE action LIKE 'repurchase%'` = **0**.

---

## Checks

| # | Check | Result | Evidence |
|---|---|---|---|
| 1a | Model matches spec §1 (day 0, +30, one verdict on due date, no grace, freeze) | **PASS** | table above; code quoted; `RepurchaseCycleServiceTest:108,115,234` |
| 1b | `fulfilled_on = due_date` on the 7 rows = new-code semantics or leftover? | **FAIL** | leftover written by the deploy backfill migration on **in-flight** windows (`resolved_at = 2026-09-05 14:03:29`, `due_date = 2026-10-03` is in the future) → **D1** |
| 1c | New evaluate self-corrects it (`8a0e9914`) | **FAIL (by design gap)** | undo arm is `elseif ($cycle->status !== STATUS_COMPLETED)`; 3 runs, 0 mutations, 0 audit rows → **D1** |
| 1d | Legacy due dates corrected to start+30 | **FAIL** | staging rows: start 2026-09-04, due **2026-10-03** = start+29 (old `cycle_days − 1` rule). New code gives 2026-10-04 → **D2** |
| 2a | `repurchase:evaluate` (today) runs and logs an engine run | **PASS** | run 12:28:49 IST → `Repurchase evaluation — as of 2026-09-10 / Done — evaluated: 7, failed: 0`; `engine_runs` id 34, `period_start 2026-09-10`, `succeeded`, trigger `console`, 41 ms, `summary NULL` |
| 2b | `--date=2026-09-09` run | **PASS** | `engine_runs` id 35, `period_start 2026-09-09`, `succeeded`, 43 ms |
| 2c | Idempotence — same date twice, no duplicate mutation | **PASS** | id 36 (64 ms). `repurchase_cycles` after all 3 runs byte-identical to before: `updated_at` still `2026-09-07 00:30:03` (d1) / `2026-09-05 14:03:29` (d2–7); `completed_bv_paise` unchanged (28060000 / 1440000 / 1440000 / 50000000 / 60000000 / 26500000 / 36500000) |
| 2d | Log lines | **PASS-with-note** | the command logs **nothing** on success (`Log::error` only, in the per-distributor catch) and writes `summary = NULL` on the engine run — every other engine writes a summary. Observability note **D5** |
| 3 | Cycle creation trigger | **PASS** | Code: `evaluate()` returns null unless `repurchaseAnchor()` (first day personal BV ≥ `comp.gsb.min_bv_paise` = 600 BV) is non-null and ≤ asOf; `withPossibleCycle()` pre-filters to distributors with any `bv_ledger_entries` row. Registration alone creates nothing. DB: 317 active distributors, **only ids 1–7 have any BV rows**, exactly 7 cycles. Two zero-BV actives checked: **id 8 / ADN 726919720** and **id 9 / ADN 282859080** — `bv_rows = 0`, no `repurchase_cycles` row. They get one on the day their first self-consumption order pushes cumulative personal BV to 600, dated **that day** (not the day evaluate happens to notice). |
| 4 | Next-cycle chaining | **PASS (code); nothing on staging contradicts** | `nextCycleStart()`: `null` unless `status = completed && fulfilled_on !== null`; then `max(due_date + 1, fulfilled_on)` — on-time ⇒ `due + 1`, late ⇒ **the fulfilment day itself** (spec §1 "reset to a fresh 30-day cycle starting from August 27"). Pinned by `RepurchaseCycleServiceTest:234` (fulfil 27 Aug → due 2026-09-26). Staging has no second-generation cycle yet (7 first cycles, all in flight), so nothing contradicts it. For these rows the chain would open **2026-10-04**. |
| 5a | Forfeited day → `gsb_cutoff_results.status = repurchase_forfeited`, carry-forward preserved | **PASS (code)** | `GsbCutoffService:187-235`: on `! $verdict->isEligible()` it returns `OUTCOME_REPURCHASE_FORFEITED` with `newPowerCf = cfBeforePower` and `newSlab1Cf = cfBeforeSlab1` — no match attempted, the day's L/R BV never added, both stores stay where the due date left them. Re-running a forfeited date reads its own `power_cf_before_paise` / `slab1_weaker_cf_before_paise` back rather than the latest day's, and a re-run that would now count throws rather than double-count when a later cut-off exists. `GsbCutoffResult` excludes the status from the pool-funding and CF-advancing sets. No staging rows yet (statuses present: `below_600bv` 1860, `no_match` 35, `credited` 7 — and **zero legacy `repurchase_held` rows**, so deploy-checklist edge 20 is a non-issue here). |
| 5b | `hasSucceededRunAfterDay` gate: does the 00:05 run on D count as having seen D−1? | **PASS** | `EngineStatusService:88-103` — first arm `whereDate('period_start','>', $day)`. Cut-off day = D−1; the 00:05 run's `period_start` = `Carbon::today()` = **D** > D−1 ⇒ satisfied on the date arm alone; the stricter `started_at >= dayEnds` arm is only needed for a same-dated run. Deployed schedule confirmed: `php artisan schedule:list` → `5 0 * * * repurchase:evaluate` and `10 0 * * * gsb:daily-cutoff --date='2026-09-09'` (the `--date` is `now('Asia/Kolkata')->subDay()` re-evaluated at each `schedule:run`, so at 00:10 on 11 Sep it resolves to 2026-09-10 — correct). |
| 5c | Any scenario where 00:10 is blocked every day? | **PASS-with-note** | Not by the date arithmetic. But the gate is satisfied only by a `succeeded` run, and `RepurchaseEvaluateCommand` returns `FAILURE` if **any single** distributor throws. One permanently-broken distributor ⇒ `engine_runs.status = failed` every night ⇒ `gsb:daily-cutoff` refuses **platform-wide** every night until someone runs it manually or passes `--force`. Fail-closed is the right default for a permanent forfeit, but the blast radius is one row → the whole platform's daily income. **D4** |
| 6a | Repurchase wallet: ledger sum vs service | **PASS** | Hand sum of `wallet_ledger_entries` (`repurchase_deduction` − ABS(`repurchase_wallet_used`); `repurchase_transfer` is correctly **not** in `WalletService::REPURCHASE_TYPES`) vs `WalletService::repurchaseWalletBalancePaise()` via tinker — exact match on all 7: d1 244198−216900 = **27298**, d2 **180100**, d3 **160100**, d4 **50050**, d5/d6/d7 **0**. |
| 6b | `wallet_balance_paise` / `wallet_zeroed` NULL — when should they be stamped? | **FAIL (consequence of D1)** | They are stamped in `resolveAtWindowEnd()` at the window's last instant (or lazily in `applyLateFulfilment()` when a legacy row has NULL). Here the migration marked all 7 `completed` **without measuring**, so condition (B) will never be applied to this window: 4 of 7 hold a non-zero repurchase wallet today (₹272.98 / ₹1,801.00 / ₹1,601.00 / ₹500.50) and would otherwise have to clear it by the window's last day. → **D1** |
| 6c | Month-end snapshot engine for Aug/Sep | **PASS (by design — it was retired)** | `repurchase_monthly_snapshots` dropped by `2026_09_06_100002`; `SHOW TABLES LIKE '%snapshot%'` returns nothing; `php artisan list \| grep repurchase` shows only `repurchase:evaluate`. Replaced by the per-cycle freeze plus `RepurchaseWalletGateService::clearedAtMonthEnd()` (balance as of `endOfMonth 23:59:59`, `true` when the flag is off). One orphan row remains: `engine_runs` `repurchase.snapshot / 2026-09-05 / succeeded` for an engine key no longer in `EngineRegistry` → **D6**. |
| 7 | Distributor-facing reminder copy (`63fb0e41`) — dates for distributor 1 today | **PASS** | Source: `app/Modules/Compensation/Support/RepurchaseWalletStatus.php` (rendered by `resources/views/components/repurchase-wallet-status.blade.php`; callers `DashboardController:142`, `IncomeController:468`, `AdminDistributorCompController:205`). It takes `min(cycle due_date, month end)` and the due date is passed **only while the flag is on** (`DashboardController:139-147`). Verified by executing the real class on staging for d1 today: balance 27298, deadline **2026-09-30** (month end, nearer than due 2026-10-03), `daysRemaining 21`, tone `green`, detail *"Bring this to ₹0 by 30 Sep — 21 days left"*. d5–d7 (₹0) → `cleared` / *"Nothing to clear this cycle."* The missed-window branch (`overdue()` → *"Your repurchase window closed on … with a balance — your Genos BV for each day until this is ₹0 is not counted."*) is unreachable for these 7 today, as expected. No projection, no money figure other than the distributor's own balance — hard rule 3 OK. |

Supporting fact for check 6: `required_bv_paise` on the 7 rows is `60000` (600 BV, non-ranked) while
`requiredBvPaise()` now returns 110000 (d1) / 100000 (d2–d4) because those distributors have since ranked.
That is **correct** — `openCycle()` snapshots the obligation and a mid-cycle rank change applies from the
next cycle.

---

## Defects

**D1 — High (data + code). The verdict backfill settles windows that are still open, and the new evaluate
cannot undo it.**
`2026_09_06_100003_backfill_verdicts_on_existing_repurchase_cycles` filters on
`resolved_at IS NULL AND status = 'completed'` with **no `due_date < today` guard**, contradicting its own
docblock ("Cycles still inside their window are untouched"). On staging it stamped all 7 in-flight cycles
(due 2026-10-03) as `resolved_at = completed_at = 2026-09-05 14:03:29`, `fulfilled_on = due_date`,
`wallet_balance_paise = NULL`, `wallet_zeroed = NULL`.
*Expected*: those rows stay `resolved_at = NULL` and are judged on 2026-10-03 against conditions (A) **and**
(B). *Actual*: they are permanently `completed`; condition (B) is never applied — and 4 of the 7 currently
hold a non-zero repurchase wallet (₹272.98, ₹1,801.00, ₹1,601.00, ₹500.50) that would fail it.
The `8a0e9914` "real-clock evaluate undoes a premature verdict" repair only covers a premature **failed**
verdict (`elseif ($cycle->status !== STATUS_COMPLETED)`), so nothing self-heals: 3 evaluate runs today
changed nothing and wrote no `repurchase.cycle.premature_verdict_reset` audit row (audit count = 0).
*Repro*: `SELECT * FROM repurchase_cycles;` then `php artisan repurchase:evaluate` — rows unchanged.
*Fix*: add `whereDate('due_date','<', today)` to both backfill updates, and extend the undo arm to any
verdict (completed included) frozen inside a window that has not closed. Production exposure is limited
today only because the flag is OFF there (R-75), so no cycles exist to back-fill — but the migration is
already deployed and will settle whatever it finds the first time it meets an environment where the flag
has been on.

**D2 — Medium (data). The 7 live cycles keep the pre-deploy off-by-one due date.**
`cycle_start_date 2026-09-04`, `due_date 2026-10-03` = start + **29** (the old `cycle_days − 1` rule that
spec §4 item 2 says must change). Current code gives start + 30 = **2026-10-04**
(`RepurchaseCycleServiceTest:108` pins 7 Jul → 6 Aug). No migration re-dated open windows, so these
distributors are judged one day early and their next window opens one day early.
*Fix*: either re-date open windows in a follow-up migration, or accept and document it — but the QA
baseline numbers for October will be a day out either way.

**D4 — Medium (code, resilience). One failing distributor blocks the whole platform's GSB cut-off,
every night.**
`RepurchaseEvaluateCommand::handle()` catches per-distributor exceptions but returns `self::FAILURE` if
`$failed > 0`; `RecordEngineRun` then writes `status = failed`; `gsb:daily-cutoff` requires a **succeeded**
`repurchase.evaluate` run and refuses (exit 1, `Log::critical gsb.cutoff.refused_missing_evaluate`)
otherwise. A single distributor with a persistent data problem therefore stops the daily cut-off for
everyone, indefinitely and unattended (`--force` is the only escape, and it silences the guard for all).
*Suggested fix*: fail the run only when the failure rate crosses a threshold, or record per-distributor
failures and let the cut-off refuse only for those distributors.

**D5 — Low (observability). `repurchase.evaluate` writes no run summary and no success log line.**
`engine_runs.summary` is NULL on all 8 historical rows and the 3 written today; the command only logs on
exception. Every other engine's run carries a summary, so the Engine Runs page and the health digest have
nothing to show for the one engine that gates the cut-off. Suggest `"evaluated: N, opened: N, completed: N,
suspended: N"`.

**D6 — Low (housekeeping). Orphan `engine_runs` row for a retired engine.**
`repurchase.snapshot / 2026-09-05 / succeeded` survives, but `repurchase.snapshot` is no longer in
`EngineRegistry` (table and command both removed by `2026_09_06_100002`). Harmless unless the Engine Runs
page or the health digest resolves an engine definition from the key without a null guard — worth one
check by whoever owns T30/T16.

**Also noted (not a defect):** the setting row `comp.repurchase.grace_days = 7` still exists in `settings`
on staging but is no longer in the settings registry (`AdminSettingsController`) and is read by no code
(`grep -rn grace_days app/` = one migration docblock). Dead row; leave it or clean it deliberately.

---

## Mutations made on staging

| Table | Ids | Before → after |
|---|---|---|
| `engine_runs` | **34** (`repurchase.evaluate`, `period_start 2026-09-10`, succeeded, 41 ms) | row created |
| `engine_runs` | **35**, **36** (`repurchase.evaluate`, `period_start 2026-09-09`, succeeded, 43 ms / 64 ms) | rows created |
| `repurchase_cycles` | 1–7 | **unchanged** (verified `updated_at` and every column identical before and after all 3 runs) |
| anything else | — | none. No pool-freezing engine, no recompute, no `.env`, no schema change. |

Note for the orchestrator: runs 35/36 are duplicate succeeded runs for a **past** date. They change no
gate outcome (2026-09-09 was already covered for `hasSucceededRunAfterDay` by run 33, `period_start
2026-09-10`), but T14's windowed-recompute cleanup should expect 3 extra `repurchase.evaluate` rows.

## Notes for the orchestrator

1. The new forfeit engine itself is sound and matches the 2026-09-07 spec on every point I could check
   statically and at the DB: +30 window, one-time frozen verdict, no grace, `max(due+1, fulfilled_on)`
   chaining, CF-preserving forfeit branch, and the cut-off's "seen the whole day" gate.
2. The two real defects are both in the **legacy-row backfill**, not the engine: D1 (in-flight windows
   settled as completed, condition B never applied, not self-healing) and D2 (open windows keep the
   start+29 due date).
3. D1 means staging's 7 earning accounts cannot exercise the forfeit path from their **first** cycle —
   whatever they do with their repurchase wallets before 3 Oct, they pass. If a task needs a real
   forfeit, it will need a deliberately constructed second cycle (or the D1 fix) first.
4. D4 (one bad distributor blocks the platform-wide cut-off) is worth pairing with F05/R-71 in the
   findings register — same "prerequisite the scheduler did not satisfy" family.
5. Zero `repurchase_held` rows anywhere, so deploy-checklist edge 20 needs no decision on staging.
6. Tonight (2026-09-11) is the first night the new 00:05 evaluate → 00:10 cut-off order actually fires
   on staging; the previous rows were produced by the pre-deploy 00:30 schedule. Worth re-reading
   `engine_runs` tomorrow morning before trusting T11's cut-off numbers.
