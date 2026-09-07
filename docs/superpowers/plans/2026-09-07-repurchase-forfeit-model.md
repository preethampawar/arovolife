# Repurchase forfeit model + weekly payout week — implementation plan (rev 3)

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace the branch's "hold and release" repurchase model with the client's confirmed "forfeit" model, and move the weekly payout to a Wednesday→Tuesday earning week paid the following Tuesday — across engines, scheduler, Engine Runs, jobs, recompute/reset tooling, reports and copy — then prove it in the browser.

**Architecture:** A failed repurchase day is a per-day exclusion of group BV. GSB writes a zero-income `repurchase_forfeited` row and leaves both carry-forward stores untouched; rank qualification subtracts failed-day `group_bv_daily`. Rank, GBB and Fortune stop consulting the cycle verdict; the 2026-09-05 month-end wallet=₹0 gate on GBB/Fortune/requalification/AO-GO is restored as one ledger-balance check. Weekly payout gains an `earned_on` date on ledger rows and sweeps only rows earned ≤ batch date − 7. The GSB cut-off refuses to run ahead of `repurchase:evaluate`.

**Tech Stack:** Laravel 13 / PHP 8.4, Pest on SQLite `:memory:` (MySQL enum widening guarded by `DB::getDriverName() === 'mysql'`), Larastan 7, Pint, claude-in-chrome for UI verification.

**Spec:** `docs/compensation/repurchase-client-examples-2026-09-07.md` (client doc + four answers; A1–A3 confirmed "option 1"; month-end wallet gate restoration confirmed by the user 2026-09-07). Executors read both.

## Context

The client's worked-examples document (2026-09-07) and follow-up answers reverse the model the **uncommitted** 2026-09-06 work on `fix/compensation-frozen-roster-and-monthly-close` implements (hold failed-day income as `repurchase_held`, keep it in pool denominators, release via four `ReleaseHeld*OnReactivation` listeners). The client's rule: "the business BVs in their left and right genos are not added to them, and therefore they lose the income related to it." Because the branch is uncommitted, the hold machinery is removed, not reversed by second migrations.

Confirmed rules (spec §1–§3):
- Cycle: day 0 = day 600 BV first reached; **`due_date = start + 30 days`**; A (self BV in `[start, due]` ≥ obligation) and B (repurchase wallet = 0 at end of due date) judged once on the due date. **No grace.** Late fulfilment cumulative from the failed cycle's start (A1); fresh cycle `start = fulfilled_on`, `due = fulfilled_on + 30`.
- Failed day `d` ∈ `[due+1, fulfilled_on−1]` (open-ended while unresolved): GSB no match, carry-forward untouched, day's BV not added; rank qualification excludes that day's group BV. Fulfilment day counts in full.
- Rank Bonus, GBB, Fortune never repurchase-held (A2). Month-end wallet = ₹0 gate on GBB, Fortune, rank requalification, AO-GO stays (forfeits the month, never holds).
- Weekly payout: earning week Wed→Tue; Tuesday-`T` batch sweeps Group A (GSB + Mentorship, A3) rows **earned** ≤ `T − 7`. Never "cooling-off" in copy.

Unchanged: 600-BV anchor, frozen one-time verdict columns, re-anchoring, date-based `verdictAsOf`, repurchase deduction at credit time, `bonus_month` cap windowing, frozen pool + roster (R-72 untouched), `IncomeReactivated` event (fires; no listeners), monthly close (1st 00:20) and monthly payout close (8th 04:00) orchestrators, `MonthlyEngineCompletionGate`, Razorpay dispatch/retry/reconcile jobs.

## Impacted areas map (every box is owned by a task below)

| Area | What changes | Task |
|---|---|---|
| Repurchase cycle service + model + events + settings registry + seeder | due = start+30; grace removed; `forfeitedWindow()` | 1 |
| Eligibility service + verdict DTO | per-day forfeit; `forfeitedDayRanges()`; no bonus-type param | 2 |
| GSB cut-off service, DTO, result model, top-up service, idle batch parity, windowed wiper status list, admin GSB pages, distributor GSB history, My Business | forfeited status; CF preserved; pool excludes forfeited | 3 |
| GSB daily cut-off **command** + `EngineStatusService` | evaluate-before-cut-off guard (`--force`) | 3 |
| Rank qualification + rank status page | counted Genos BV via one method | 4 |
| Rank Bonus service, roster DTO, result model, listener, untracked migration, admin RB pages, `CLAUDE.md` sentence | no repurchase hold | 5 |
| GBB service + roster DTO, Fortune service + result model, requalification gate, AO-GO (via `passMap`), new wallet-gate service, listeners, admin GBB/Fortune pages | no cycle hold; month-end wallet gate | 6 |
| Wallet ledger (`earned_on`), `WalletService`, GSB + Mentorship credit call sites, `PayoutService` weekly batch (3 queries + hold lines + transfers), `PayoutBatch` window helper, backfill migration | payout week | 7 |
| Scheduler (`routes/console.php`), `EngineRegistry` (descriptions, cadence pins, dependency), `EngineChainResolver` week expansion, Engine Runs page chips, `RunEngineChainJob`, `RecomputeAllJob`/`CompensationRecomputeRunner`/`EngineReplayService`, `CompensationStateWiper`, `WindowedStateWiper` (cycle verdict reset), `platform:reset-purchases`, admin manual controls (retry / recalc CF), queue workers | Task 8 |
| Reports and distributor surfaces (matrix) | Task 9 |
| Risk register, spec status, memory, deploy checklist | Task 10 |
| Browser verification | Verification §B |

**Not impacted (checked, listed so nobody re-checks):** `MonthlyCloseCommand` step order; `MonthlyPayoutCloseCommand` + `MonthlyEngineCompletionGate`; `runMonthlyBatch()` (undated by design, monthly types keep `earned_on = null`); `AutoRetryFailedPayoutsCommand`, `DispatchRazorpayPayoutsJob`, `RetryRazorpayPayoutJob`, `ProcessRazorpayPayoutWebhookJob`, `PayoutReconciliationService` (operate on payout line items, downstream of the sweep); `PropagateGroupBvJob` / `ReverseGroupBvJob` (group BV is still written for every day — exclusion happens at read time); ADC, Awards & Rewards, Mentorship pricing; `GsbDailyPoolService` (reads `POOL_FUNDED_STATUSES`, which excludes forfeited).

## Single source of truth (every task routes through these)

| Question | One place | Consumers |
|---|---|---|
| Cycle length | `CompensationPlanSettingsService::repurchaseCycleDays()` (`comp.repurchase.cycle_days` = 30) | `RepurchaseCycleService::openCycle()` |
| "Was X failed on day d?" / "which days?" | `RepurchaseCycle::forfeitedWindow(): ?array{0: Carbon, 1: ?Carbon}` = `[due+1, fulfilled_on−1]`, `[due+1, null]` while unresolved, `null` if on time / still open | `IncomeEligibilityService::verdictAsOf()` (GSB), `::forfeitedDayRanges()` (rank), admin `_tab-repurchase` |
| Genos BV that counts toward rank | `RankQualificationService::countedGenosBvForMonth(Carbon $month, ?array $ids = null)` | `checkRanks1And2()`, `RankStatusService::monthGenosBv()` |
| Repurchase wallet balance at an instant | `WalletService::repurchaseWalletBalancesAsOfPaise()` (exists) | cycle condition B, `RepurchaseWalletGateService` |
| Wallet clear at month end | `RepurchaseWalletGateService::clearedAtMonthEnd(array $ids, Carbon $month): array<int,bool>` | GBB roster, Fortune payout, `RankRequalificationGateService::walletClearedMap()` → requalification + AO-GO |
| Weekly earning window for batch `T` | `PayoutBatch::weeklyEarningWindow(Carbon $batchDate): array{start: Carbon, end: Carbon}` = `[T−13, T−7]` | `PayoutService` (3 queries + hold lines + transfers), `EngineChainResolver` week expansion, admin weekly-payouts view, distributor "next payout" copy |
| "Has evaluate run far enough for cut-off D?" | `EngineStatusService::hasSucceededRunOnOrAfter(string $key, Carbon $period): bool` | `GsbDailyCutoffCommand` guard |
| GSB statuses that moved the carry-forward | `GsbCutoffResult::advancedCarryForward()` **and** its duplicate in `WindowedStateWiper.php:229-238` (test pins them equal; forfeited in neither) | cut-off rewind, `GsbSlabProgressService`, wiper |
| GSB statuses that consumed the pool | `GsbCutoffResult::POOL_FUNDED_STATUSES` (forfeited **not** in it) | GSB I&O, `GsbDailyPoolService` |

## Global Constraints

- `RepurchaseEngineFeature` (`compensation.repurchase_engine`) gates every repurchase effect. OFF ⇒ no forfeits, no exclusion, wallet gate all-clear, cut-off guard skipped. The payout week is **not** flag-gated.
- No hardcoded plan numbers. Money in paise. `IndianNumber` for display. Lucide icons only.
- Enum widening migrations follow `2026_07_03_100000_add_repurchase_statuses_to_gsb_cutoff_results.php`.
- Copy: lowercase "arovolife", Genos/group terms, never "cooling-off" for the payout week, no income projections; help icons + confirm modals per platform convention.
- Admin help (`app/resources/help/*.md`) updated in the same task as the behaviour.
- Tests: `arovolife_test` / SQLite memory only. `vendor/bin/pint --dirty`, touched tests, and (Task 6 onward) `vendor/bin/phpstan analyse` level 7 before each commit.
- Commit trailer for money-touching commits: `Compliance-Review: compliance-officer`.
- Never run `db:seed`, `compensation:recompute-all`, `platform:reset-purchases`, or anything destructive on the dev DB without the user's explicit yes, with the 5-line warning format.

Task order: 1 → 2 → 3 → 4 → 5 → 6 → 7 → 8 → 9 → 10 → Verification A → Verification B (browser).

---

### Task 1: Cycle window = start + 30; remove grace

**Files:**
- Modify: `app/app/Modules/Compensation/Services/RepurchaseCycleService.php` (`openCycle()` ~L170-185; `applyLateFulfilment()` terminal ~L349-351; `onTransition()` L411-425; `onCompleted()` L433; class docblock)
- Modify: `Models/RepurchaseCycle.php` (drop `STATUS_GRACE`, `grace_end_date`; add `forfeitedWindow()`)
- Delete: `Events/RepurchaseGraceStarted.php`; remove `withinGrace()`/`wasForfeited()` from `Events/RepurchaseCompleted.php` (no listeners exist)
- Modify: `Services/CompensationPlanSettingsService.php` (delete `comp.repurchase.grace_days` L111 + `repurchaseGraceDays()` L296-299; fix comment L112-114)
- Modify: `app/app/Modules/Admin/Http/Controllers/AdminSettingsController.php` (delete grace entry L1051-1060; cycle_days description → "The due date is this many days after the cycle start date (start 7 Jul → due 6 Aug).")
- Modify: `app/database/seeders/SettingsSeeder.php` L86; `Support/EngineRegistry.php` L94 (`repurchase.evaluate` description: drop "grace"; say "the cut-off for day D needs an evaluate run as at D or later")
- Modify: `resources/views/admin/compensation/distributors/_tab-repurchase.blade.php` (L6 badge; developer note → forfeit wording; "Days not counted" column from `forfeitedWindow()`, "ongoing" while open); `plan-settings/index.blade.php` L30
- Create: `Database/Migrations/2026_09_07_100000_drop_grace_end_date_from_repurchase_cycles.php`
- Modify: `docs/architecture/events.md`
- Test: `tests/Modules/Compensation/RepurchaseCycleServiceTest.php`, `RepurchaseWalletStatusTest.php`

**Interfaces — Produces:**
```php
// RepurchaseCycle
public function forfeitedWindow(): ?array   // [Carbon $from, ?Carbon $to]; null = nothing forfeited
{
    if ($this->fulfilledOnTime()) return null;                       // fulfilled_on !== null && fulfilled_on <= due_date
    if ($this->resolved_at === null) return null;                    // still inside its window
    $from = $this->due_date->copy()->startOfDay()->addDay();
    $to = $this->fulfilled_on?->copy()->startOfDay()->subDay();     // null while unresolved
    return [$from, $to];
}
```
Statuses left: `active | suspended | completed`. `IncomeReactivated` fires on suspended→completed only.

- [ ] **Step 1: Failing tests.** Rewrite L96/L109/L200 to the client's dates (Jul 7→Aug 6; Jul 13→Aug 12; Jul 24→Aug 23; fulfilled Aug 9 ⇒ Aug 9→Sep 8; Aug 17→Sep 16; Aug 27→Sep 26). Add `it('forfeitedWindow is null on time, [due+1, fulfilled−1] when late, open-ended while unresolved, null inside the window')`. Remove `'grace_end_date'` fixtures (L292, L328, L360).
- [ ] **Step 2:** Run → FAIL.
- [ ] **Step 3: Implement.** `openCycle()`: `$due = $start->copy()->addDays($this->plan->repurchaseCycleDays());` no grace fields. `applyLateFulfilment()` terminal: `STATUS_SUSPENDED`. `onTransition()` drop the grace arm; `onCompleted()` gate `=== STATUS_SUSPENDED`. Migration drops `grace_end_date` (`down()` re-adds nullable). Leave the untracked `2026_09_06_100003` backfill untouched (literal `'grace'` targets legacy rows).
- [ ] **Step 4:** Run the two test files + admin settings tests → PASS. `grep -rn "grace" app/app app/resources/views | grep -i repurchase` → empty.
- [ ] **Step 5: Commit** `fix(compensation): repurchase due date is 30 days after day 0; remove grace` + trailer.

---

### Task 2: Eligibility = per-day forfeit verdict + failed-day ranges, one predicate

**Files:** `Services/IncomeEligibilityService.php`; `Services/DTOs/RepurchaseVerdict.php`; test `RepurchaseCycleServiceTest.php` (L406-436 section).

**Interfaces — Produces:**
- `ELIGIBLE = 'eligible'`, `FORFEITED = 'forfeited'`; delete `HOLD`, `BLOCKED`, `suspends()`, `statusFor()`.
- `verdictAsOf(int $distributorId, Carbon $asOf): RepurchaseVerdict` — eligible when flag off / no covering cycle / `forfeitedWindow()` null or not containing `$asOf`; else `RepurchaseVerdict::forfeited($cycle->failure_reason, $cycle->id)`.
- `forfeitedDayRanges(Carbon $from, Carbon $to): array<int, list<array{0:string,1:string}>>` — per distributor, every cycle's `forfeitedWindow()` clipped to `[$from,$to]`; `[]` when flag off.
- `RepurchaseVerdict::forfeited(?string $reason, ?int $cycleId)` replaces `held()`.

- [ ] **Step 1: Failing tests:** `forfeits Aug 24–26 for a cycle due Aug 23 fulfilled Aug 27 (RB ex 3)`; `runs an unresolved failure to the end of the asked window`; `clips a window that started before the asked range`; `returns two ranges for two consecutive failed cycles`; `verdictAsOf agrees with forfeitedDayRanges for every day of August` (property check); `nothing on time / nothing when the engine is off`.
- [ ] **Step 2:** Run → FAIL.
- [ ] **Step 3: Implement.**
```php
public function forfeitedDayRanges(Carbon $from, Carbon $to): array
{
    if (! $this->engineActive()) return [];
    $from = $from->copy()->startOfDay(); $to = $to->copy()->startOfDay();
    $cycles = RepurchaseCycle::query()
        ->whereNotNull('resolved_at')
        ->whereDate('due_date', '<', $to->toDateString())
        ->where(fn ($q) => $q->whereNull('fulfilled_on')->orWhereColumn('fulfilled_on', '>', 'due_date'))
        ->where(fn ($q) => $q->whereNull('fulfilled_on')->orWhereDate('fulfilled_on', '>', $from->toDateString()))
        ->get(['distributor_id', 'due_date', 'fulfilled_on', 'resolved_at']);
    $ranges = [];
    foreach ($cycles as $c) {
        $window = $c->forfeitedWindow(); if ($window === null) continue;
        [$start, $end] = $window;
        $start = $start->greaterThan($from) ? $start : $from->copy();
        $end = ($end === null || $end->greaterThan($to)) ? $to->copy() : $end;
        if ($start->lessThanOrEqualTo($end)) $ranges[(int) $c->distributor_id][] = [$start->toDateString(), $end->toDateString()];
    }
    return $ranges;
}
```
- [ ] **Step 4:** Run → PASS; mechanically fix the four callers' signatures so the suite compiles (three are deleted in Tasks 5–6).
- [ ] **Step 5: Commit** `refactor(compensation): per-day repurchase forfeit verdict and failed-day ranges`.

---

### Task 3: GSB — forfeited day: zero row, carry-forward untouched; cut-off guarded on evaluate

**Files:**
- Modify: `Services/GsbCutoffService.php` (`computeForDistributor()` L98-330; `settle()` L394-590; `saveResult()` volatile reset L601-611)
- Modify: `Services/DTOs/GsbCutoffComputation.php` (`OUTCOME_REPURCHASE_FORFEITED`)
- Modify: `Models/GsbCutoffResult.php` (`STATUS_REPURCHASE_FORFEITED = 'repurchase_forfeited'`; **not** in `POOL_FUNDED_STATUSES`, **not** in `advancedCarryForward()`; `REPURCHASE_HELD/SUSPENDED` stay as legacy in both)
- Modify: `Services/Recompute/WindowedStateWiper.php` L229-238 (list unchanged; add test pinning equality with the model)
- Modify: `Services/GsbPersonalBvTopupService.php` L298-305 (`cutoffIsSettled()`: add forfeited)
- Modify: `Services/EngineStatusService.php` (add `hasSucceededRunOnOrAfter()`); `Console/Commands/GsbDailyCutoffCommand.php` — **guard** before pass 1: if `IncomeEligibilityService::engineActive()` and not `--force` and `! hasSucceededRunOnOrAfter('repurchase.evaluate', $date)` ⇒ `Log::critical('gsb.cutoff.refused_missing_evaluate', [...])`, error naming `repurchase:evaluate --date=<D>`, exit 1 (an EngineRun FAILED row is recorded by the existing `RecordEngineRun` wrapper so the Engine Runs page shows it). Rationale: `evaluate --date=D` resolves cycles due ≤ D−1 and stamps fulfilments through D, which is exactly what day D's verdicts need; the 00:05 run on D+1 (as of D+1) is a superset. The replay and the admin trigger already resolve the `repurchase.evaluate` dependency for period D (`EngineRegistry.php:114`, `EngineReplayService::unscheduledPrerequisites`).
- Create: `Database/Migrations/2026_09_07_100001_add_repurchase_forfeited_status_to_gsb_cutoff_results.php`
- Modify UI: `AdminGsbCalculationController.php` L133; `AdminDailyCutoffController.php` L34, L99 + `orderByRaw` L47/L84; `admin/compensation/gsb-calculation/index.blade.php` L33-34, L92-93; `daily-cutoffs/index.blade.php` L94; `income/gsb-history.blade.php` L78-85 (label "Repurchase not met — day not counted" + help icon); `MyBusinessController.php` L76-77 (forfeited is not a "last slab match"); `AdminGsbInputOutputController.php` docblock L30-37 + per-day `forfeited_days` count on the I&O blade ("Days not counted (repurchase)")
- Delete: `Listeners/ReleaseHeldGsbOnReactivation.php`, `ReleaseHeldGbbOnReactivation.php`; `AppServiceProvider.php` L122-123 + imports; tests `ReleaseHeldGsbOnReactivationTest.php`, `ReleaseHeldGbbOnReactivationTest.php`; fix comments `AdminManualControlsController.php:71`, `CompensationRecomputeRunner.php:70`
- Modify: `help/compensation.md` L122-127
- Test: `GsbCutoffServiceTest.php`, `RepurchaseCycleServiceTest.php` (L440-540), `GsbIdleCutoffBatchTest.php`, new `GsbDailyCutoffCommandGuardTest.php`, `EngineStatusServiceTest.php`

**Interfaces — Produces:** row `status = repurchase_forfeited`, `gross = 0`, `left/right_bv_paise` = raw day BV, `*_cf_after = *_cf_before`, `slab/score = null`. `EngineStatusService::hasSucceededRunOnOrAfter(string $key, Carbon $period): bool` (SUCCEEDED run with `period_start >= $period`).

- [ ] **Step 1: Failing tests** (replace L467-540; delete the two "releases held" tests): `forfeits a failed day: zero income, no ledger row, both carry-forwards untouched, day BV not added`; `forfeits a small-BV failed day without accumulating it into the slab-1 store`; `leaves a pending personal-BV top-up pending on a forfeited day`; `resumes on the fulfilment day: day BV + preserved carry-forward matches and credits`; `a forfeited computation is not matched so it does not fund the day pool`; `re-running a forfeited day is idempotent and never rewinds the store`; `refuses to re-run a forfeited day as eligible once a later cut-off advanced the store`; `frozen wins over forfeited`. `GsbCutoffServiceTest`: `forfeited is in neither CF list nor POOL_FUNDED; the wiper list equals the model list`. `GsbIdleCutoffBatchTest`: `an idle failed-cycle distributor gets no_match with the store unchanged`. Guard test: `refuses when no evaluate run exists as at the cut-off date or later`; `runs with --force / flag off / later evaluate run`; `records a FAILED engine run when refused`.
- [ ] **Step 2:** Run → FAIL.
- [ ] **Step 3: Implement.** In `computeForDistributor()` after the CF rewind block (after L186):
```php
// Client 2026-09-07: a failed repurchase day is forfeited outright — no match,
// no carry-forward movement, today's BV simply not added. Frozen wins: its
// branch never credits and deliberately advances the store.
$verdict = $isFrozen ? RepurchaseVerdict::eligible() : $this->eligibility->verdictAsOf($distributorId, $date);
if (! $verdict->isEligible()) {
    return new GsbCutoffComputation(
        distributorId: $distributorId, date: $date, existing: $existing,
        outcome: GsbCutoffComputation::OUTCOME_REPURCHASE_FORFEITED, isFrozen: false,
        eligibility: IncomeEligibilityService::FORFEITED, personalBvPaise: $personalBvPaise,
        leftToday: $leftToday, rightToday: $rightToday, weakerTotal: 0,
        strongerSide: $cfSide ?? 'L', powerSideBefore: $cfSide,
        cfBeforePower: $cfPower, cfBeforeSlab1: $cfSlab1, newPowerCf: $cfPower, newSlab1Cf: $cfSlab1,
    );
}
if ($existing?->status === GsbCutoffResult::STATUS_REPURCHASE_FORFEITED
    && GsbCutoffResult::where('distributor_id', $distributorId)->whereDate('cutoff_date', '>', $date->toDateString())->exists()) {
    throw new \RuntimeException(/* same wording as L174 */);
}
```
Delete the verdict resolution at L314-318 (matched computations carry `ELIGIBLE`) and the L516-542 branch. `settle()` before `OUTCOME_NO_MATCH`:
```php
if ($computation->outcome === GsbCutoffComputation::OUTCOME_REPURCHASE_FORFEITED) {
    // No $cf->update(): the store is deliberately left as it stood.
    return $this->saveResult($existing, [
        'distributor_id' => $distributorId, 'cutoff_date' => $date->toDateString(),
        'left_bv_paise' => $computation->leftToday, 'right_bv_paise' => $computation->rightToday,
        'weaker_bv_paise' => 0, 'gross_gsb_paise' => 0, 'admin_charge_paise' => 0, 'tds_paise' => 0, 'net_gsb_paise' => 0,
        'power_cf_before_paise' => $computation->cfBeforePower, 'power_side_before' => $computation->powerSideBefore,
        'power_cf_after_paise' => $computation->cfBeforePower, 'power_side_after' => $computation->powerSideBefore,
        'slab1_weaker_cf_before_paise' => $computation->cfBeforeSlab1, 'slab1_weaker_cf_after_paise' => $computation->cfBeforeSlab1,
        'status' => GsbCutoffResult::STATUS_REPURCHASE_FORFEITED,
    ]);
}
```
Update the `GsbIdleCutoffBatch` parity comment. Guard in the command as specified.
- [ ] **Step 4:** Run: `GsbCutoffServiceTest`, `RepurchaseCycleServiceTest`, `GsbIdleCutoffBatchTest`, `GsbDailyCutoffCommandGuardTest`, `EngineStatusServiceTest`, `MentorshipBonusServiceTest`, `GsbPersonalBvTopupServiceTest`, `AdminGsbCalculationTest`, `AdminDailyCutoffTest`, `AdminGsbInputOutputTest`, `EngineReplayServiceTest` → PASS.
- [ ] **Step 5: Commit** `fix(compensation): forfeit GSB on failed repurchase days, preserve carry-forward, gate the cut-off on repurchase:evaluate` + trailer.

---

### Task 4: Rank qualification counts only compliant days — one shared BV method

**Files:** `Services/RankQualificationService.php` (new public `countedGenosBvForMonth()`; `checkRanks1And2()` L195-250; inject `IncomeEligibilityService`); `Services/RankStatusService.php` L295-316 (`monthGenosBv()` delegates); `help/compensation.md` §8; tests `RankQualificationServiceTest.php`, `RankStatusServiceTest.php`.

**Interfaces — Produces:** `countedGenosBvForMonth(Carbon $month, ?array $distributorIds = null): array<int, array{left:int,right:int}>` — month sum minus each distributor's forfeited ranges, clamped at 0 per side.

- [ ] **Step 1: Failing tests** (R1 = 2.5L/side; `group_bv_daily` per day): RB ex 1 (still failed at month end ⇒ rank 1), RB ex 3 (24–26 lost, 27–31 counted, recorded `left_genos_bv_paise` = counted 2.5L), RB ex 2 (short ⇒ none), `a cycle that fails on the 1st of next month does not touch the closed month`, `no exclusion when the engine is off`, `personal weaker-leg top-up unaffected by failed days`. `RankStatusServiceTest`: `rank progress shows the counted Genos BV the qualification run uses`.
- [ ] **Step 2:** FAIL. **Step 3:** implement (one month query + one query per forfeited range; clamp with `max(0, …)`); `checkRanks1And2()` iterates the map; writes counted values. **Step 4:** both test files + `RankCheckCommandTest` → PASS.
- [ ] **Step 5: Commit** `fix(compensation): rank qualification and rank progress count only compliant repurchase days` + trailer.

---

### Task 5: Rank Bonus is never repurchase-held

**Files:** `Services/RankBonusService.php` (drop `$eligibility`; L204-237, L363, L369-374, L420-434, L757, L767-778, `releaseHeldRow()` L806-816, L895-902; docblocks L83, L219, L371, L470, L803); `DTOs/RankMonthRoster.php` (drop `repurchaseHeldIds`, `repurchaseHeldFor()`); `Models/RankBonusResult.php` L54, `Models/FortuneBonusResult.php` L47 (drop `STATUS_REPURCHASE_HELD`); delete untracked `2026_09_06_100001_add_repurchase_held_to_rank_and_fortune_results.php`, `Listeners/ReleaseHeldRankBonusOnReactivation.php`, `ReleaseHeldFortuneOnReactivation.php`, `AppServiceProvider.php` L124-125 + imports; `AdminRankBonusInputOutputController.php` L263 (Blocked = `repurchase_wallet_blocked` only), `AdminRankBonusCalculationController.php` L45/L114; blades `rb-calculation`, `_tab-rank-bonus`, `income/rank-bonus`; `help/compensation.md` L137, L141; working-tree `CLAUDE.md` L99 sentence → forfeit rule; test `RankBonusServiceTest.php` (delete L459, L522).

- [ ] **Step 1:** `it('credits a rank achiever whose repurchase cycle is failed at month end — repurchase only filters BV')` → FAIL. **Step 3:** removals. **Step 4:** `RankBonusServiceTest`, `AdminRankBonus*Test`, `MonthlyCloseCommandTest` → PASS. (An orphaned `migrations` row for the deleted file on the dev DB is harmless — forward-only.)
- [ ] **Step 5: Commit** `fix(compensation): rank bonus is never held by the repurchase cycle` + trailer.

---

### Task 6: GBB + Fortune drop the cycle hold; month-end wallet gate restored; requalification/AO-GO read it

**Files:** create `Services/RepurchaseWalletGateService.php`; `GrowthBoosterBonusService.php` (`resolveRoster()` L164-180, freeze L218-232, late-earner L590, delete `partitionByRepurchase()` L703-743, drop `$eligibility`, docblock L55); `DTOs/GbbMonthRoster.php` (`payable`, `walletBlocked`, `skippedNoAgp`; `totalAgp()` = payable); `FortuneBonusService.php` (L57-60, L317-321, L337-339, L363-370, L418 key `repurchase_wallet_blocked`, drop `$eligibility`); `Models/FortuneBonusResult.php` add `STATUS_REPURCHASE_WALLET_BLOCKED` (enum value exists via `2026_09_05_100002`); `RankRequalificationGateService.php` `walletClearedMap()` L111-142 → delegate; `Models/GbbMonthlyResult.php` (legacy constants stay); `AdminGbbInputOutputController.php` L45-46, `AdminGbbCalculationController.php` L36/L70, `AdminFortuneBonusCalculationController.php` + `fortune-bonus/*` blades (wallet-blocked count + badge), `income/growth-booster`, `income/fortune-bonus`; `plan-settings/index.blade.php` L159; `gbb-calculation/index.blade.php` L22; `help/compensation.md` L127, L183. Tests: `GrowthBoosterBonusServiceTest.php`, `FortuneBonusServiceTest.php`, `RankRequalificationGateServiceTest.php` (create if absent), `AogoOfferServiceTest.php`, new `RepurchaseWalletGateServiceTest.php`.

**Interfaces — Produces:** `clearedAtMonthEnd(array $distributorIds, Carbon $month): array<int,bool>` = `repurchaseWalletBalancesAsOfPaise($ids, $month->copy()->endOfMonth()->setTime(23,59,59)) <= 0`; all-true when the engine is off.

- [ ] **Step 1: Failing tests.** GBB: delete L315, L483, L506, L529, L762, L798, L818; keep L840; add `pays GBB on AGP from compliant days even when the cycle is failed at month end`, `forfeits the month for wallet money at month end — repurchase_wallet_blocked, gross 0, outside the denominator`, `a re-run prices against the frozen roster; the wallet gate is not re-judged`. Fortune: delete L871, L917; add the two equivalents (blocked ⇒ no credit, position kept, amount is leftover). Gate: `all-clear when the engine is off`; `judges the last instant of the month — a deduction on the 1st does not count`. Requal: `wallet condition is the month-end balance, not the cycle verdict`. AO-GO tests still pass.
- [ ] **Step 2:** FAIL. **Step 3:** implement; GBB roster splits `eligibleEarners()` by `$cleared`; blocked rows frozen at freeze time and never re-judged. **Step 4:** five test files + `MonthlyCloseCommandTest`; phpstan 7 → PASS; `grep -rn "IncomeReactivated\|ReleaseHeld\|repurchaseHeld\|STATUS_REPURCHASE_HELD" app/app` → event class, its dispatch, GSB/GBB legacy constants only.
- [ ] **Step 5: Commit** `fix(compensation): GBB and Fortune drop the repurchase hold; month-end wallet gate from the ledger` + trailer.

---

### Task 7: Weekly payout — Wednesday→Tuesday earning week, paid one Tuesday later

**Files:** create `2026_09_07_100002_add_earned_on_to_wallet_ledger_entries.php` (`date('earned_on')->nullable()->after('bonus_month')`; index `idx_wallet_type_swept_earned (type, swept_by_payout_batch_id, earned_on)`); create `2026_09_07_100003_backfill_earned_on_on_wallet_ledger_entries.php` (`gsb_cutoff_result` ← `cutoff_date`; `mentorship_bonus_result` ← `cutoff_date`; every type sharing the reference — `gsb_credit`, `mb_credit`, `repurchase_transfer`, `repurchase_deduction` — gets the same date; shape of `2026_09_08_100000_backfill_bonus_month…`, **include mentorship**); `Models/WalletLedgerEntry.php` (fillable + `date` cast); `Models/PayoutBatch.php` (`weeklyEarningWindow()`); `Services/WalletService.php` (`credit()` L126, `debit()` L163, `creditWithRepurchaseDeduction()` L206 gain trailing `?Carbon $earnedOn = null`; `credit()` throws `InvalidArgumentException` for a `GROUP_A_TYPES` credit with null `earnedOn`; `sumUnsweptByTypes()` L569 gains `?Carbon $earnedOnOrBefore = null`); `GsbCutoffService.php` L564-571 (`earnedOn: $date`); `MentorshipBonusService.php` L186-195 (`earnedOn: Carbon::parse($accrual->cutoffDate)`); `Services/PayoutService.php` (`runWeeklyBatch()` window applied at L101-106, L186-192, `holdLineItem()` L657-685 both sums, `unsweptRepurchaseTransfers()` L933-940; filter `->where(fn ($q) => $q->whereDate('earned_on', '<=', $end)->orWhereNull('earned_on'))`; docblock L62-68); `admin/compensation/weekly-payouts/index.blade.php` ("Earnings through" column; header L9). Tests: `PayoutServiceTest.php` (every Group A credit gets `earnedOn`; weekly tests use batch date ≥ earned + 7), `PayoutCommandFailureTest.php`, `tests/Feature/Compensation/PayoutBankDecryptionTest.php`, `WalletServiceTest.php`, new `BackfillEarnedOnMigrationTest.php`, `AdminWeeklyPayoutsTest`.

**Interfaces — Produces:** `PayoutBatch::weeklyEarningWindow(Carbon $batchDate): array{start: Carbon, end: Carbon}` = `[T−13, T−7]`. Invariant: a Group A row with `earned_on > T − 7` is never swept by batch `T`.

- [ ] **Step 1: Failing tests:** `pays income earned Wed 5 Aug – Tue 11 Aug on Tue 18 Aug and not on 11 Aug (GSB ex 1)`; `boundary: earned T−7 swept, T−6 not`; `a held line (KYC pending) reports only the payable window`; `sweeps a repurchase_transfer only with its credit`; `mentorship credits follow the same week (A3)`; `below-minimum balances roll and are paid when the window reaches them`; `income cap keyed on the earned month when swept in the next month`; `credit() refuses a Group A credit without an earned date`; `backfill stamps gsb, mentorship and their repurchase_transfer rows`.
- [ ] **Step 2:** FAIL. **Step 3:** implement; monthly types keep `earned_on = null`. **Step 4:** listed tests → PASS; `php artisan migrate` on dev (forward-only); `SELECT COUNT(*) FROM wallet_ledger_entries WHERE type IN ('gsb_credit','mb_credit') AND earned_on IS NULL` = 0.
- [ ] **Step 5: Commit** `feat(payout): weekly batch pays the Wednesday–Tuesday earning week one Tuesday later` + trailer.

---

### Task 8: Scheduler, Engine Runs, jobs, recompute and reset tooling

**Files:**
- `app/routes/console.php` L26-52 — cadences unchanged (evaluate 00:05, cut-off 00:10 for yesterday, weekly Tue 03:00). Add a comment at L39 stating the cut-off refuses without an evaluate run as at that date (Task 3) and why 00:05/00:10 is an ordering *hint*, not a guarantee.
- `Support/EngineRegistry.php` — `repurchase.evaluate` L92-100 description (Task 1); `gsb.daily-cutoff` L107-122 description mention the guard; `gsb.weekly-payout` L124-140 description → "sweeps GSB and Mentorship credits earned in the Wednesday–Tuesday week that closed seven days before the batch date"; dependency `['key' => 'gsb.daily-cutoff', 'expand' => 'week']` unchanged. `tests/Feature/EngineRegistryTest.php` pins cadences — update descriptions only.
- `Services/EngineChainResolver.php` L150-161 — `week` expansion uses `PayoutBatch::weeklyEarningWindow($period)` (`start … end`), comment rewritten. Test `EngineChainResolverTest.php`: `weekly payout chain checks the cut-offs of the earning week T−13…T−7`.
- Engine Runs page (`AdminEngineRunsController` + `engine-runs/index.blade.php`) — no code change; verify the dependency chips and descriptions render (browser step B2). Manual trigger of `gsb.daily-cutoff` for D plans `repurchase.evaluate` for D first (existing resolver) — satisfies the guard; add a test in `AdminEngineRunsTest` asserting the planned chain contains `repurchase.evaluate` when the flag is on.
- `Jobs/RunEngineChainJob.php` — no change (runs the resolved plan on the `compensation` queue). **Deploy checklist:** restart the `compensation` queue worker and the scheduler after deploy (memory `engine-runs-admin-page`; R-71 pre-deploy-code worker).
- `Jobs/RecomputeAllJob.php` / `Services/Recompute/CompensationRecomputeRunner.php` / `EngineReplayService.php` — no change: replay sets the clock per day (`EngineReplayService.php:422`) and resolves `repurchase.evaluate` before each cut-off; `earned_on` is stamped by the replayed engines; the final `repurchase:evaluate` on the real clock (`Runner.php:135-138`) stays. Test: `CompensationRecomputeRunnerTest` (or `EngineReplayServiceTest`): `a full replay of the client GSB example produces forfeited rows for 7–8 Aug and a fresh cycle from 9 Aug`.
- `Support/DerivedTables.php` — `TABLES` and `DATE_COLUMNS` already list `repurchase_cycles`, `wallet_ledger_entries`, `payout_batches`, `engine_runs`; no new tables in this work. Add nothing; assert in `DerivedTablesTest` that every table touched by this plan is listed.
- `Services/Recompute/WindowedStateWiper.php` — **gap to close**: cycles are deleted by `cycle_start_date >= from`, but a cycle that *started before* the window and whose `due_date >= from` carries a verdict (`resolved_at`, `wallet_balance_paise`, `wallet_zeroed`, `fulfilled_on`, `failure_reason`, `status`, `completed_bv_paise`) computed from ledger rows the wipe deletes. Add `resetCycleVerdictsInWindow(Carbon $from)`: for `cycle_start_date < from AND due_date >= from` set those columns back to `null / null / null / null / null / 'active' / 0`, and for `cycle_start_date < from AND due_date < from AND fulfilled_on >= from` (late fulfilment inside the window) set `fulfilled_on = null`, `status = 'suspended'`, `completed_at = null`. Replay then re-resolves them from the rebuilt ledger. Add to `preview()`. Test `WindowedStateWiperTest`: `resets the verdict of a cycle that straddles the window start and re-resolves it on replay to the same answer`.
- `Services/Recompute/CompensationStateWiper.php` — full wipe already truncates cycles/ledger/batches/engine runs and deletes queued `PropagateGroupBvJob`s; no change.
- `app/Console/Commands/PurchaseDataResetCommand.php` (`platform:reset-purchases`, admin "reset purchase data") — verify it wipes via `DerivedTables` + orders so `earned_on`/forfeited rows go with everything else; add its table list to the `DerivedTablesTest` assertion. No behaviour change.
- `AdminManualControlsController` — `retryCutoff()` → `runForDistributor()` (no guard; admin action, documented in help); `recalcCarryForward()` is a logged stub (L272) — leave; reverse/credit/force-payout unchanged (`manual_credit` is never swept weekly — R-74 stands). Update help `payout-operations.md` L205-209 and `admin-actions.md` where they describe retry/CF.
- Queues: nothing new is queued; the deleted listeners were synchronous. Confirm with `grep -rn "ShouldQueue" app/app/Modules/Compensation/Listeners` → empty.

- [ ] **Steps:** write the four tests above (resolver week, engine-runs chain, replay example, wiper reset) → FAIL → implement (`EngineChainResolver`, `WindowedStateWiper`, registry text, comments, help) → PASS incl. `EngineRegistryTest`, `EngineChainResolverTest`, `WindowedStateWiperTest`, `DerivedTablesTest`, `AdminEngineRunsTest`, `MonthlyCloseCommandTest`, `MonthlyPayoutCloseCommandTest`.
- [ ] **Commit** `fix(compensation): payout-week chain window, straddling-cycle verdict reset in windowed recompute, registry copy` + trailer.

---

### Task 9: Reports and distributor surfaces — matrix

| Surface | File | Required state |
|---|---|---|
| Admin GSB calculation report | `AdminGsbCalculationController` + `gsb-calculation/index.blade.php` | filter + red badge "Repurchase forfeited"; totals unaffected; CSV emits status |
| Admin Daily cut-offs | `AdminDailyCutoffController` + blade | selectable, badge, ordering bucket |
| Admin GSB Input & Output | controller + blade | per-day "Days not counted (repurchase)"; pool reconciliation unchanged |
| Admin Rank calc / I&O | `AdminRankBonus*Controller` + blades | no held state; Blocked = wallet-blocked only; Genos BV columns = counted |
| Admin GBB calc / I&O | `AdminGbb*Controller` + blades | no held/suspended filters (legacy badges kept); wallet-blocked count; explainer rewritten |
| Admin Fortune calc | `AdminFortuneBonusCalculationController` + blades | wallet-blocked count + badge; leftover includes blocked |
| Admin distributor → Repurchase tab | `_tab-repurchase.blade.php` | no grace; "Days not counted"; forfeit wording |
| Admin distributor → Rank Bonus / Payouts tabs | `_tab-rank-bonus`, `_tab-payouts` | no held; week rule |
| Admin Weekly payouts | `weekly-payouts/index.blade.php` | "Earnings through dd Mon"; header copy |
| Admin Compensation overview | `overview.blade.php` L11, L41, L49 | "next Tuesday pays earnings through <end>" |
| Admin Engine Runs | registry descriptions | correct (Task 8) |
| Admin plan settings | `plan-settings/index.blade.php` L30, L159; registry | no grace; cycle_days text; GBB explainer = wallet gate |
| Admin manual controls | `_form-force-payout.blade.php` L5 + help | week rule; retry note |
| Distributor GSB history | `income/gsb-history.blade.php` | forfeited label + help icon: "Your repurchase condition was not met on this day, so the day's Genos BV was not counted." |
| Distributor My Business | `my-business.blade.php`, `MyBusinessController` | last slab match ignores forfeited; "Next payout … covers earnings through dd Mon" |
| Distributor Rank progress | `RankStatusService` (Task 4) | counted BV |
| Distributor Income dashboard / wallet / snapshot | `income/dashboard.blade.php` L14/16/61, `income/wallet.blade.php` L13/40/45/199, `dashboard/_income-snapshot.blade.php` L62/66 | week rule; never "cooling-off" |
| Distributor repurchase widget | `Support/RepurchaseWalletStatus.php` | unchanged; verify no "held" wording |
| Help docs | `help/compensation.md` L93, L95 (drop stale snapshot sentence), L122-141, L183; `help/payout-operations.md` L41-46, L205-209; `help/admin-actions.md` | forfeit model + week rule + retry note |
| Public plan page (DSA §6.2) | `app/database/seeders/content/compensation.md` L210 | "GSB and Mentorship Bonus are calculated daily. Each earning week runs Wednesday to Tuesday and is paid on the following Tuesday at 03:00 IST." **Do not re-seed without the user's explicit yes**; R-75 notice |

- [ ] Each row done; `php artisan view:cache` clean; page feature tests green; `grep -rn "cooling" app/resources/views/income app/resources/views/dashboard app/resources/help/payout-operations.md` shows only statutory text.
- [ ] **Commit** `docs(compensation): forfeit model and payout-week copy across admin and distributor surfaces`.

---

### Task 10: Risk register, spec status, memory, deploy checklist

- [ ] `docs/compliance/risk-register.md`: **R-75** (published weekly cadence change; DSA §6.2 30-day notice before the copy goes live; blocks prod deploy of the Task 7/9 copy). Amend R-36 and the Rank rows describing hold-and-release. Note under R-72 that this spec leaves it open.
- [ ] `docs/compensation/repurchase-client-examples-2026-09-07.md` status → implemented (commit range). Add a deploy checklist: `php artisan migrate`; restart `compensation` queue worker + scheduler; staging legacy `repurchase_held` rows (edge 20) decided; flag stays OFF in prod until §6.2 notice.
- [ ] Memory `repurchase_grace_and_bonus_dates_incoming.md` → shipped; note the cut-off guard, the wallet-gate restoration and the wiper reset.
- [ ] **Commit** `compliance(risk-register): R-75 payout week notice; forfeit-model amendments`.

---

## Edge-case matrix (each has a named test)

| # | Case | Behaviour | Task |
|---|---|---|---|
| 1 | `repurchase:evaluate` lags the 00:10 cut-off | cut-off refuses for the day, FAILED engine run, admin re-runs; never a silent forfeit | 3 |
| 2 | Fulfilment day itself | new cycle starts that day ⇒ eligible; BV + preserved CF match | 3 |
| 3 | Small-BV failed day | forfeited; no-match accumulation must not run | 3 |
| 4 | Pending personal-BV top-up on a failed day | stays pending | 3 |
| 5 | Frozen distributor on a failed day | frozen branch wins | 3 |
| 6 | Idle (zero BV, zero CF) failed distributor | `no_match` via idle batch; store unchanged | 3 |
| 7 | Re-run of a forfeited day | idempotent; no rewind | 3 |
| 8 | Forfeited row, verdict later eligible, later dates advanced CF | refuse; reprocess oldest-first | 3 |
| 9 | Two consecutive failed cycles | two ranges; verdict follows the covering cycle | 2 |
| 10 | Failure starting the 1st of next month | closed month untouched | 4 |
| 11 | Group BV reversal on a forfeited day | counted BV clamped at 0 per side | 4 |
| 12 | Personal weaker-leg top-up on failed days | unaffected | 4 |
| 13 | Achiever failed at month end | rank credited on the 1st, paid on the 8th | 5 |
| 14 | Wallet money at month end | GBB/Fortune month forfeited, requal/AO-GO fail; frozen on roster | 6 |
| 15 | Wallet deducted on the 1st at 00:10 | judged 23:59:59 of the last day ⇒ not counted | 6 |
| 16 | Weekly boundary `T−7` / `T−6` | swept / not | 7 |
| 17 | Below-minimum rollover across weeks | rolls, paid when the window reaches it | 7 |
| 18 | Income cap across the month boundary | keyed on `bonus_month` | 7 |
| 19 | Held lines (KYC/bank) | windowed gross only | 7 |
| 20 | Legacy `repurchase_held` GSB/GBB rows on dev/staging | stay legacy, never released; before flag-on: replay with the user's yes, or accept | Verification A6 |
| 21 | Legacy `grace` cycle rows | grey badge; backfill literal untouched | 1 |
| 22 | Flag off | no forfeits/exclusion, gate all-clear, guard skipped; payout week still applies | all |
| 23 | Windowed recompute starting mid-cycle | straddling cycle's verdict reset and re-resolved from the rebuilt ledger | 8 |
| 24 | Manual Engine Runs trigger of a cut-off | chain plans `repurchase.evaluate` first ⇒ guard satisfied | 8 |
| 25 | Weekly payout chain from Engine Runs | checks the cut-offs of `T−13…T−7`, not `T−6…T` | 8 |

## Bonus-calculation cross-check

- **GSB**: pool from pass-1 matched computations — forfeited excluded; pricing/CF/top-up unchanged for eligible days.
- **Mentorship**: reads only `credited` GSB rows with a slab (`MentorshipBonusService.php:62`) — forfeited sponsee day ⇒ no MB points; never gated by the sponsor's own repurchase.
- **GBB**: AGP from `credited` slab 1–3 rows (`GrowthBoosterBonusService.php:754`); month-end wallet gate; prior-month-rank gate unchanged.
- **Fortune**: enrolment gates unchanged; month-end wallet gate at payout; blocked amounts are leftover.
- **Rank**: counted Genos BV; requalification §8 = monthly personal BV + month-end wallet; pool/roster freeze unchanged.
- **Payout**: weekly = one earning week per batch (admin charge weekly cap now per week by construction); monthly unchanged; repurchase deduction, `bonus_month` cap windowing, TDS unchanged.

## Verification A — automated (after Task 10)

1. `php artisan test tests/Modules/Compensation tests/Feature/Compensation tests/Feature/EngineRegistryTest.php tests/Feature/Admin` green; `vendor/bin/phpstan analyse --memory-limit=1G` level 7 green; `vendor/bin/pint --test`.
2. Dev DB: `php artisan migrate` (forward-only). Seed the client's GSB example 1 on the test tree (anchor 2026-07-07, no repurchase until 2026-08-09) and replay via `compensation:recompute-all` **only after the user's explicit yes (5-line warning)**: `gsb_cutoff_results` 07/08 Aug `repurchase_forfeited` with `power_cf_after = power_cf_before`; 09 Aug credited; cycle row `2026-08-09 → 2026-09-08`.
3. Rank: seed RB example 3; `rank:check-qualifications --month=2026-08` ⇒ rank 1, `left_genos_bv_paise = 2.5L`; `rank:monthly-run` credits; no `repurchase_held` rows in any results table.
4. Payout: `gsb:weekly-payout --date=2026-08-18` pays 06 + 11 Aug, leaves 12 Aug; `--date=2026-08-25` pays it.
5. Guard: `gsb:daily-cutoff --date=<yesterday>` before `repurchase:evaluate` ⇒ exit 1 naming the command and a FAILED engine run; after evaluate ⇒ runs.
6. Staging note: legacy held rows (edge 20) decided before deploy. Production unaffected (repurchase flag OFF); payout-week copy waits for R-75.

## Verification B — browser (claude-in-chrome; local app per memory `local-dev-credentials`: admin@arovolife.test / admin12345, `APP_URL` in `app/.env`; distributor from the test tree)

- [ ] B1 Admin → Compensation → Plan settings: no grace setting; cycle-days description reads "30 days after the start date".
- [ ] B2 Admin → Engine Runs: `repurchase.evaluate`, `gsb.daily-cutoff`, `gsb.weekly-payout` descriptions correct; the cut-off card shows the evaluate dependency chip; trigger `gsb.daily-cutoff` for a closed date and confirm the audit preview lists `repurchase.evaluate` first; the run completes SUCCEEDED in the events feed.
- [ ] B3 Admin → GSB calculation report: filter "Repurchase forfeited" lists the 07/08 Aug rows with ₹0 and unchanged CF columns; CSV download contains the status.
- [ ] B4 Admin → Daily cut-offs: same rows visible with the red badge; sort bucket sane.
- [ ] B5 Admin → GSB Input & Output for 07 Aug: "Days not counted (repurchase)" = 1 for the seeded distributor; pool leftover reconciles.
- [ ] B6 Admin → Distributor → Repurchase tab: cycles 07 Jul–06 Aug (suspended, "Days not counted: 2") and 09 Aug–08 Sep (active); no grace badge.
- [ ] B7 Admin → Rank Bonus calculation + I&O for 2026-08: RB ex 3 distributor qualified with counted BV 2.5L; no "held" anywhere; Blocked column only wallet-blocked.
- [ ] B8 Admin → GBB and Fortune calculation pages for 2026-08: wallet-blocked count shown; no held/suspended filter options.
- [ ] B9 Admin → Weekly payouts: batch 18 Aug shows "Earnings through 11 Aug 2026" and the ₹4,000 line; batch 25 Aug shows the 12 Aug income.
- [ ] B10 Distributor (test tree) → Income → GSB history: 07/08 Aug rows read "Repurchase not met — day not counted" with the help icon; 09 Aug credited.
- [ ] B11 Distributor → My Business: next payout line includes "covers earnings through …"; last slab match ignores the forfeited days; Rank progress shows the counted Genos BV.
- [ ] B12 Distributor → Income dashboard + wallet: payout copy reads the week rule; the word "cooling-off" appears only in the statutory context.
- [ ] B13 Admin → Help: compensation and payout-operations pages render the new text.
- [ ] After browser work: recommend `/compact`.

## Out of scope (state in the hand-off)

- R-72 (post-freeze rank qualifiers) unchanged.
- "Next payout" *date* computations unchanged; only the copy explains what that Tuesday covers.
- `recalcCarryForward()` remains a logged stub.
