# E5 redesign — deferred cut-offs for distributors the evaluation could not judge

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task, in this session, on Opus. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** honour the client's skip-and-continue decision (2026-09-27) without the two High findings the compliance review raised against the first E5: a skipped distributor's day is never lost, and a late credit never overspends the day's frozen pools.

**Architecture:** a durable `gsb_cutoff_deferrals` row per (distributor, day) the full cut-off could not judge. The same night, the deferred distributor's matched share is still computed (pass 1 is pure) and **reserved** in the day's frozen GSB pool and MSB denominator. The **next full cut-off** backfills open deferrals in date order, one distributor at a time through the existing single-distributor path, **before** it computes the new day — so the carry-forward store is advanced in order and the out-of-order guard is never met. The digest and dashboard read the table, not a 24-hour summary window.

**Tech Stack:** Laravel 13 / PHP 8.4 / Pest, MySQL; one migration, one model, no new queues.

**Spec:** `docs/plans/engine-fail-safety-2026-09-27.md` Task 5 (superseded by this file), the compliance-officer review of 2026-09-27 (two High findings, quoted below), and the user's decision of 2026-09-27: ship E1–E4, redesign E5.

Status: approved for implementation 2026-09-27. Planned on Fable; implement on Opus.
Branch: `fix/engine-fail-safety-e5` off `main` (`d74bf99e` or later). First action: `git cherry-pick accbe09c` (the held-back E5 commit — it applies cleanly on top of E1–E4 and is the base this plan edits). One commit per task after it.
All paths below are relative to `app/` unless they start with `docs/`.

## What the review found, and what changes

| Finding | Why the first E5 had it | This plan |
|---|---|---|
| **H1** A skipped distributor's day is lost for good on production: once the next night's cut-off advances their carry-forward, a by-name retry of the missed day throws `CutoffReplayedOutOfOrder`; `NightRebuilder` refuses once any later day is cut off (D11, R-91); the windowed recompute is dev/staging only. The digest only read the last 24 h. | The remedy was a human racing the next 00:05. | The **next full run** backfills the deferred day for that distributor *before* computing the new day, so the store is advanced in order by the engine itself. The deferral row is durable and drives the digest until it is resolved. |
| **H2** Skipped distributors were outside the frozen GSB pool and MSB denominator, so a by-name retry paid them, and their sponsor's MB, on top of an already fully priced pool. | The roster `whereNotIn` removed them before pass 1. | They stay in pass 1 (pure computation, stale verdict) so their matched slab counts in `fixed_payout_paise` / `variable_total_score` and their sponsor's MSB points in `total_points`. Only pass 2 (settle) skips them. The backfill then pays at the frozen values — the money that was reserved. |
| Flat cap of 500 is larger than the network; a single exception class across every failure is systemic. | Plan chose an absolute cap. | Cap = the smaller of `evaluate_skip_cap` and 1% of the roster the run looked at, never below 10; and ten or more failures sharing one exception class fail closed. |
| A by-name cut-off did not check the distributor was re-evaluated. | No record of a by-name evaluation. | A by-name cut-off of a date with an open deferral refuses unless `--force`; the normal path is the next full run. |

Money that can still be off, and why it is accepted: the reservation uses the **stale** verdict and tonight's simulated top-up. If the fresh verdict later forfeits the day, the reserved share stays in `leftover_paise` (under-distribution, conservative). If a fresh evaluation flips a forfeited day to eligible, that distributor's share was not reserved and the backfill pays it on top — this needs an evaluation to throw *and* the verdict to flip, and it is the same tolerance the engine has always had for a crashed compute's retry. Record both in the risk register entry this plan adds.

Facts to keep in mind:

- The single-distributor path already exists and is what the backfill reuses: `GsbDailyCutoffCommand` with `--distributor` warms nothing, skips the idle batch, computes, prices against `poolForDate()` (never freezes), settles, accrues MB and credits it at `msbPoolService->poolForDate()`. Read it end to end before Task 3; the backfill is that path run in-process for one (distributor, date) at a time.
- `GsbCutoffService::computeForDistributor()` throws `CutoffReplayedOutOfOrder` when a later row advanced the store. The backfill runs before the new day is computed, so the deferred distributor has no later row — unless an operator ran them by name in between, in which case the deferral is already resolved (Task 3 marks it).
- `MentorshipBonusService::accrueForSponsee(int $sponseeId, GsbCutoffResult $result)` (`Services/MentorshipBonusService.php:62`) needs a credited row; the reservation needs the same answer from a computation. Extract the sponsor lookup + min-BV gate + slab points into `reservedPointsFor(int $sponseeId, int $slab): int` and call it from both.
- `MentorshipBonusService::creditAccrual(MsbAccrual, ?MsbDailyPool)` (`:117`) with a null pool writes an `msb.pool.missing` audit row and pays nothing — the backfill passes `poolForDate($deferralDate)`, exactly as the single retry does.
- The cherry-picked commit's summary keys stay: `outcome` (`completed` / `completed_with_skips` / `failed_partial`), `failed`, `failed_adns` (≤ 50), `failed_distributor_ids` (≤ cap), `failure_classes`, `skip_cap`.
- Tests: `docker exec -e DB_CONNECTION=mysql -e DB_DATABASE=arovolife_test -e DB_HOST=db -e DB_PORT=3306 -e DB_USERNAME=arovolife -e DB_PASSWORD=secret arovolife-app php artisan test --compact <path>` — never bare. Pint on explicit paths; Larastan level 7 (`docker exec arovolife-app vendor/bin/phpstan analyse --memory-limit=2G --no-progress <paths>`).
- Money: every commit carries `Compliance-Review: compliance-officer`; run the agent once over `git diff main...HEAD` at the end. Never name the client.
- Migrations live in `app/Modules/Compensation/Database/Migrations/`; the deploy runs `migrate` — restart is covered by `app:deploy`.

## Global Constraints

- No enum widening on `gsb_cutoff_results.status`. A deferred (distributor, day) has **no** result row until it is backfilled; the deferral row is the record.
- The deferral table is written only by the full cut-off and resolved only by a settle that produced a result row (backfill, or a `--force` by-name run).
- Reservation never *pays*: pass 2 skips deferred computations; nothing is credited for a stale verdict.
- Every refusal is a `skipped` run row with a reason plus a log line.
- ADNs and ids in admin surfaces and the digest; never names or contact details.

## Review Focus

1. **A deferral whose distributor fails evaluation again the next night** — the old row stays open, a new one is added for the new day, and the backfill later resolves both oldest-first. Task 3 pins it.
2. **A deferral older than the month close** — the backfill still credits (weekly Group A sweeps pick up a late `earned_on`); the digest ages it and says the month's figures moved. Task 4 pins the ageing line; the credit path is the existing `creditWithRepurchaseDeduction`.
3. **A deferred distributor who is no longer active** — never backfilled (roster is `status = active`), stays open, digest says "inactive, needs a decision". Task 3 pins it.
4. **A by-name run that settles a deferred day** — resolves the row with `resolution = manual`; the next full run must not backfill it again. Task 3 pins it.
5. **The pool freeze on a night with deferrals and the pool-pricing flag off** — nothing to reserve; the deferral rows are still written. Task 2 pins it.

---

### Task 1: the deferral table, model and the sharper cap

**Files:**
- Create: `app/Modules/Compensation/Database/Migrations/2026_09_27_100000_create_gsb_cutoff_deferrals_table.php`
- Create: `app/Modules/Compensation/Models/GsbCutoffDeferral.php`
- Modify: `app/Modules/Compensation/Console/Commands/RepurchaseEvaluateCommand.php` (cap rule)
- Test: `tests/Modules/Compensation/GsbCutoffDeferralTest.php` (new), `tests/Modules/Compensation/RepurchaseEvaluateCommandTest.php`

**Interfaces:**
- Produces:

```php
final class GsbCutoffDeferral extends Model
{
    public const CAUSE_EVALUATION_FAILED = 'evaluation_failed';
    public const RESOLUTION_BACKFILLED = 'backfilled';
    public const RESOLUTION_MANUAL = 'manual';

    // columns: id, distributor_id, cutoff_date (date), cause, evaluate_run_id (nullable),
    // reserved_slab (nullable tinyint), reserved_gsb_paise (bigint, 0), reserved_msb_points (int, 0),
    // resolved_at (nullable), resolution (nullable), gsb_cutoff_result_id (nullable), timestamps
    // unique (distributor_id, cutoff_date); index (resolved_at, cutoff_date)

    public function scopeOpen(Builder $q): Builder;   // whereNull('resolved_at')
}
```
- `RepurchaseEvaluateCommand::effectiveSkipCap(int $lookedAt): int` — `max(10, min(configured, intdiv($lookedAt, 100)))`; summary `skip_cap` carries the effective value. Fail closed also when `failed >= 10` and `count($failureClasses) === 1` (summary `outcome = failed_partial`, reason names the class and says "every failure is the same class — a fault in the run").

- [ ] **Step 1: Write the failing tests**

`tests/Modules/Compensation/GsbCutoffDeferralTest.php`:

```php
<?php

declare(strict_types=1);

use App\Modules\Compensation\Models\GsbCutoffDeferral;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('holds one open deferral per distributor and day', function (): void {
    GsbCutoffDeferral::create(['distributor_id' => 7, 'cutoff_date' => '2026-09-26', 'cause' => GsbCutoffDeferral::CAUSE_EVALUATION_FAILED]);

    expect(GsbCutoffDeferral::open()->count())->toBe(1);
    expect(fn () => GsbCutoffDeferral::create(['distributor_id' => 7, 'cutoff_date' => '2026-09-26', 'cause' => GsbCutoffDeferral::CAUSE_EVALUATION_FAILED]))
        ->toThrow(QueryException::class);
});

it('leaves the open scope once resolved', function (): void {
    $row = GsbCutoffDeferral::create(['distributor_id' => 7, 'cutoff_date' => '2026-09-26', 'cause' => GsbCutoffDeferral::CAUSE_EVALUATION_FAILED]);
    $row->update(['resolved_at' => now(), 'resolution' => GsbCutoffDeferral::RESOLUTION_BACKFILLED]);

    expect(GsbCutoffDeferral::open()->count())->toBe(0);
});
```

`RepurchaseEvaluateCommandTest.php` — change the existing cap test to the relative rule and add the single-class rule:

```php
it('caps skips at 1% of the roster it looked at, never below 10', function (): void {
    expect(RepurchaseEvaluateCommand::effectiveSkipCap(300))->toBe(10)
        ->and(RepurchaseEvaluateCommand::effectiveSkipCap(5_000))->toBe(50)
        ->and(RepurchaseEvaluateCommand::effectiveSkipCap(1_000_000))->toBe(500);   // configured 500 wins
});

it('fails closed when ten or more failures share one exception class', function (): void {
    config(['arovolife.compensation.evaluate_skip_cap' => 500]);
    for ($i = 0; $i < 10; $i++) { distributorWithAnchor('2026-07-0'.(($i % 9) + 1)); }

    RepurchaseCycle::creating(function (): void {
        throw new RuntimeException('simulated systemic failure');
    });

    expect(Artisan::call('repurchase:evaluate', ['--date' => '2026-07-30']))->toBe(1);
    $run = lastEvaluateRun();
    expect($run->summary['outcome'])->toBe(RepurchaseEvaluateCommand::OUTCOME_FAILED_PARTIAL)
        ->and($run->summary['reason'])->toContain('same class');
});
```
Keep the two skip tests from the cherry-picked commit (one and two failures on a tiny roster are under the floor of 10 and still skip).

- [ ] **Step 2: Run, expect FAIL.**

- [ ] **Step 3: Implement** — migration in the style of `2026_09_17_100000_create_gsb_reversal_requests_table.php` (docblock: what the row is, why no result row exists until backfill, why the FK to `gsb_cutoff_results` nulls on delete — the R-91 production rebuild deletes result rows). Model with guarded `$fillable`, `casts()` for `cutoff_date`, `resolved_at`, the integers. In the evaluate command: `public static function effectiveSkipCap(int $lookedAt): int`, `$lookedAt = $evaluated + $failed`, the single-class rule, and the reason text.

- [ ] **Step 4: Run, expect PASS** — both files plus `GsbDailyCutoffCommandGuardTest.php`.

- [ ] **Step 5: Pint + Larastan, commit** — `feat(gsb): deferral rows for cut-offs the evaluation could not judge, and a relative skip cap`

### Task 2: reserve, don't skip — the full cut-off writes deferrals

**Files:**
- Modify: `app/Modules/Compensation/Console/Commands/GsbDailyCutoffCommand.php` (replace the `whereNotIn` block from the cherry-pick)
- Modify: `app/Modules/Compensation/Services/MentorshipBonusService.php` (`reservedPointsFor()`)
- Test: `tests/Modules/Compensation/GsbDailyCutoffCommandGuardTest.php` (replace the two skip tests), `tests/Modules/Compensation/GsbDailyCutoffCommandTest.php`

**Interfaces:**
- Produces: `MentorshipBonusService::reservedPointsFor(int $sponseeId, int $slab): int` — 0 when the sponsee has no sponsor, the sponsor is under the min BV, or the slab has no `msb_score`; otherwise the points. `accrueForSponsee()` calls it.
- Consumes: `GsbCutoffDeferral`, `skippedByEvaluation()` from the cherry-pick (kept, renamed `deferredByEvaluation()`).

Flow inside `handle()` for a full run (`$singleId === null`):
1. `$deferred = deferredByEvaluation($date)` (ids from the covering evaluate run's summary, as today). No `whereNotIn`.
2. Pass 1 computes everyone, deferred included. Keep `$computations[$id]` for all; keep a set `$deferredIds`.
3. Pool freeze: unchanged — the aggregates loop already covers every computation, so the deferred matched shares are in `fixed_payout_paise` / `variable_total_score`. Add a console line "reserved for N deferred distributor(s)".
4. Pass 2: `if (isset($deferredIds[$id])) { continue; }` — nothing settled, nothing credited. Instead, after pricing (call `price()` for them too, so `reserved_gsb_paise` is the priced gross), `GsbCutoffDeferral::updateOrCreate([distributor_id, cutoff_date], [cause, evaluate_run_id, reserved_slab, reserved_gsb_paise, reserved_msb_points])` — `reserved_msb_points = $mentorshipActive && $c->isMatched() ? $this->mentorship->reservedPointsFor($id, $c->slabIndex) : 0`. Add those points to `$msbTotalPoints` **before** the MSB pool freezes.
5. Log `gsb.cutoff.deferred` with count + ADNs; console "Done — … deferred: N".

An in-flight, closed-day or evaluate refusal still happens before any of this. A `--distributor` run never defers (Task 3 decides what it does with an open deferral).

- [ ] **Step 1: Write the failing tests**

Replace the two skip tests in `GsbDailyCutoffCommandGuardTest.php` with:

```php
it('reserves a deferred distributor\'s share in the frozen pools and writes a deferral instead of a row', function (): void {
    Feature::for(null)->activate(RepurchaseEngineFeature::class);
    Feature::for(null)->activate(GsbDailyPoolPricingFeature::class);
    Feature::for(null)->activate(MentorshipBonusFeature::class);
    // Build a slab-3 achiever and a slab-1 sponsor/sponsee pair the way
    // GsbDailyCutoffCommandTest does (seedSlabAchiever(3), seedGsbCreditingPair(),
    // seedCompanyDayBv(…)); the slab-3 achiever is the one the evaluation could not judge.
    [$achiever, $pair] = /* per the helpers above */;
    seedEvaluateRunWithDeferrals('2026-08-26', [$achiever->id], [$achiever->adn]);   // helper: succeeded run, summary with failed_distributor_ids

    expect(Artisan::call('gsb:daily-cutoff', ['--date' => '2026-08-25']))->toBe(0);

    $deferral = GsbCutoffDeferral::where('distributor_id', $achiever->id)->sole();
    expect($deferral->cutoff_date->toDateString())->toBe('2026-08-25')
        ->and($deferral->reserved_slab)->toBe(3)
        ->and($deferral->reserved_gsb_paise)->toBeGreaterThan(0)
        ->and($deferral->resolved_at)->toBeNull();
    expect(GsbCutoffResult::where('distributor_id', $achiever->id)->exists())->toBeFalse();

    // Reserved: the pool's variable score total includes the achiever's slab-3 score.
    $pool = GsbDailyPool::whereDate('cutoff_date', '2026-08-25')->sole();
    expect($pool->variable_total_score)->toBeGreaterThanOrEqual(/* slab-3 score from the plan tables */);
    // and the MSB denominator includes the sponsor's points for the deferred sponsee when they have a sponsor.
});

it('writes the deferral even when pool pricing is off', function (): void {
    Feature::for(null)->activate(RepurchaseEngineFeature::class);
    $d = Distributor::factory()->create(['status' => 'active']);
    seedEvaluateRunWithDeferrals('2026-08-26', [$d->id], [$d->adn]);

    expect(Artisan::call('gsb:daily-cutoff', ['--date' => '2026-08-25']))->toBe(0)
        ->and(GsbCutoffDeferral::where('distributor_id', $d->id)->exists())->toBeTrue()
        ->and(GsbDailyPool::count())->toBe(0);
});
```
Add `seedEvaluateRunWithDeferrals(string $period, array $ids, array $adns)` next to `seedEvaluateRun()` in that file. Pin `reservedPointsFor()` in `tests/Modules/Compensation/MentorshipBonusServiceTest.php` (or the nearest MB test file): sponsor under min BV → 0; slab with `msb_score` → the score.

- [ ] **Step 2: Run, expect FAIL.**
- [ ] **Step 3: Implement** per the flow above. Delete `skippedByEvaluation()`'s `whereNotIn` use and the "left out" wording; the console and log say "deferred".
- [ ] **Step 4: Run, expect PASS** — the guard file, `GsbDailyCutoffCommandTest.php`, the MB test file, `GsbCutoffServiceTest.php`.
- [ ] **Step 5: Pint + Larastan, commit** — `feat(gsb): reserve a deferred distributor's share in the day's pools and defer the settle`

### Task 3: the next full run backfills open deferrals in date order

**Files:**
- Modify: `app/Modules/Compensation/Console/Commands/GsbDailyCutoffCommand.php` (a `backfillDeferrals()` step before pass 1; the `--distributor` rule)
- Test: `tests/Modules/Compensation/GsbDailyCutoffCommandGuardTest.php`

**Interfaces:**
- Produces: private `backfillDeferrals(Carbon $tonightDate, array $stillDeferredIds): array{settled: int, failed: int, left_open: int}` on the command.

Rules:
- Runs on a full run only, after every guard and before pass 1. Selects `GsbCutoffDeferral::open()->where('cutoff_date', '<', $date)` joined to active distributors, **excluding** `$stillDeferredIds` (tonight's failed evaluation set), ordered by `cutoff_date, distributor_id`.
- For each: run the single-distributor sequence for `(distributor_id, cutoff_date)`: `computeForDistributor` → `price(…, poolForDate(deferralDate), $poolPricingActive)` → `settle` → if credited and MB active: `accrueForSponsee` then `creditAccrual($accrual, $msbPoolService->poolForDate(deferralDate))`. Then `update(['resolved_at' => now(), 'resolution' => RESOLUTION_BACKFILLED, 'gsb_cutoff_result_id' => $result->id])`.
- `CutoffReplayedOutOfOrder` for a deferral means a later row exists (an operator ran them by name without `--force`, or an older bug): resolve it as `manual` with a `Log::warning('gsb.cutoff.deferral_already_passed')` rather than loop on it for ever — the row that exists is the truth.
- Any other throwable: log `gsb.cutoff.deferral_failed`, leave the row open, count it, continue; the run's exit code is not affected (the deferral is still owed and still listed).
- Console: "Backfilled N deferred cut-off(s) (F failed, O left open)".
- `--distributor` run for a date with an **open** deferral: refuse (skipped-style message on the console; partial runs have no run row) unless `--force`, in which case it settles and resolves the row as `manual`. Message: "ADN X has a deferred cut-off for D that tonight's full run will backfill automatically. To settle it now, re-evaluate them by name first (php artisan repurchase:evaluate --date=<today> --distributor=X) and pass --force."

- [ ] **Step 1: Write the failing tests** (append to the guard file; use the helpers from Task 2):

```php
it('backfills an open deferral before computing the new day, and advances the store in order', function (): void {
    // Day 25 deferred for the achiever; night 27 evaluates them fine → the run
    // settles 25 for them first, then computes 26 for everyone.
    …seed achiever with GroupBvDaily rows for 2026-08-25 and 2026-08-26, pool rows frozen for 25 (run the 25 cut-off with the deferral first, as in Task 2's test)…
    seedEvaluateRun('2026-08-27', '2026-08-27 00:05:00');   // clean run, no failed ids

    expect(Artisan::call('gsb:daily-cutoff', ['--date' => '2026-08-26']))->toBe(0);

    $deferral = GsbCutoffDeferral::where('distributor_id', $achiever->id)->sole();
    expect($deferral->resolution)->toBe(GsbCutoffDeferral::RESOLUTION_BACKFILLED)
        ->and($deferral->gsb_cutoff_result_id)->not->toBeNull();
    $rows = GsbCutoffResult::where('distributor_id', $achiever->id)->orderBy('cutoff_date')->pluck('status', 'cutoff_date');
    expect($rows->keys()->map(fn ($d) => Carbon::parse($d)->toDateString())->all())->toBe(['2026-08-25', '2026-08-26']);
    // paid at the frozen 25 Aug value, not recomputed
    expect(GsbCutoffResult::where('distributor_id', $achiever->id)->whereDate('cutoff_date', '2026-08-25')->sole()->score_value_paise)
        ->toBe(GsbDailyPool::whereDate('cutoff_date', '2026-08-25')->sole()->variable_score_value_paise);
});

it('keeps a deferral open while the distributor still fails evaluation, and adds one for the new day', function (): void {
    … day 25 deferred; night 27's evaluate run lists the achiever again …
    expect(Artisan::call('gsb:daily-cutoff', ['--date' => '2026-08-26']))->toBe(0);
    expect(GsbCutoffDeferral::open()->where('distributor_id', $achiever->id)->pluck('cutoff_date')->map->toDateString()->all())
        ->toBe(['2026-08-25', '2026-08-26']);
});

it('never backfills an inactive distributor, and leaves the row open', function (): void {
    … day 25 deferred; distributor set status = 'inactive'; clean evaluate run …
    expect(Artisan::call('gsb:daily-cutoff', ['--date' => '2026-08-26']))->toBe(0);
    expect(GsbCutoffDeferral::open()->count())->toBe(1);
});

it('refuses a by-name run of a deferred day without --force, and resolves it as manual with it', function (): void {
    … day 25 deferred …
    expect(Artisan::call('gsb:daily-cutoff', ['--date' => '2026-08-25', '--distributor' => (string) $achiever->id]))->toBe(1)
        ->and(Artisan::output())->toContain('backfill automatically');
    expect(Artisan::call('gsb:daily-cutoff', ['--date' => '2026-08-25', '--distributor' => (string) $achiever->id, '--force' => true]))->toBe(0);
    expect(GsbCutoffDeferral::sole()->resolution)->toBe(GsbCutoffDeferral::RESOLUTION_MANUAL);
});

it('does not backfill a deferral a by-name run already settled', function (): void {
    … after the --force run above, a full run for 26 leaves exactly two rows and no second credit …
    expect(WalletLedgerEntry::where('distributor_id', $achiever->id)->where('type', 'gsb_credit')->count())->toBe(/* one per day */ 2);
});
```
Fill the elided seeding with the Task 2 helpers; the assertions are the contract.

- [ ] **Step 2: Run, expect FAIL.**
- [ ] **Step 3: Implement** per the rules above.
- [ ] **Step 4: Run, expect PASS** — the guard file, `GsbDailyCutoffCommandTest.php`, `NightlyRunCommandTest.php`, `GsbCutoffServiceTest.php`.
- [ ] **Step 5: Pint + Larastan, commit** — `feat(gsb): the next full cut-off backfills deferred days in order before it advances the store`

### Task 4: the morning list reads the deferral table

**Files:**
- Modify: `app/Modules/Compensation/Services/EngineHealthService.php` (`skippedDistributors()` → `deferredCutoffs()`), `Services/DTOs/EngineHealthReport.php` (rename the bucket to `deferredCutoffs`), `Notifications/EngineHealthDigestNotification.php`, `resources/views/admin/dashboard/panels/compensation.blade.php`, `resources/help/compensation.md`, `docs/compliance/risk-register.md`
- Test: `tests/Modules/Compensation/EngineHealthDigestTest.php`

**Interfaces:**
- Item shape: `array{engine: string, key: string, period: string, period_value: string, count: int, oldest: string, adns: list<string>, ages: array<string,int>, steps: list<string>}` — one item per open-deferral group (all open rows, grouped as one item; `oldest` is the earliest `cutoff_date`, `ages` maps ADN → days open).

Rules:
- Source: `GsbCutoffDeferral::open()` joined to distributors for the ADN and status. No time window: an open row is reported every morning until resolved.
- Headline: "N deferred GSB cut-off(s) waiting on a fixed repurchase evaluation — oldest D (A days)".
- Steps (admin words): (1) "Nothing to run: the next nightly cut-off backfills each one automatically the first night the distributor evaluates cleanly, paid at that day's frozen pool value." (2) "Open each distributor (ADN …) and fix what the evaluation tripped on — the exception class is on the Repurchase Evaluation run's summary on Engine Runs." (3) When any row is 3+ days old: "ADN … has waited A days: check the distributor is still active (an inactive distributor is never backfilled — decide whether to reactivate or write the day off in the audit log)." (4) When any row's day is in a month already closed: "A backfill for D credits a month whose figures have moved; the weekly payout picks the credit up on its next Tuesday."
- Digest sends when the bucket is non-empty (it counts in `total()`).
- Dashboard bucket label: "Deferred cut-offs".
- Help doc: rewrite the E5 paragraph: skipped → deferred, backfilled automatically, reserved in the pools, the `--force` by-name route, the cap rule, the inactive case.
- Risk register: add an entry "R-113 Deferred cut-off reservation uses the stale verdict" with the two residuals from "Money that can still be off" above, owner: platform, status: accepted by the user 2026-09-27 pending the client's sight of it.

- [ ] **Step 1: Write the failing tests** — replace the two `skippedDistributors` tests from the cherry-pick with:

```php
it('lists open deferred cut-offs every morning until they are resolved', function (): void {
    seedHealthyRuns();
    $d = Distributor::factory()->create(['status' => 'active', 'adn' => '100000077']);
    GsbCutoffDeferral::create(['distributor_id' => $d->id, 'cutoff_date' => '2026-09-03', 'cause' => GsbCutoffDeferral::CAUSE_EVALUATION_FAILED]);

    // run the digest the way this file's other tests do
    $text = digestText(sentDigest());

    expect($text)->toContain('deferred GSB cut-off')
        ->and($text)->toContain('100000077')
        ->and($text)->toContain('03 Sep 2026')
        ->and($text)->toContain('backfills each one automatically')
        ->and($text)->toContain('has waited 5 days');
});

it('stops listing a deferral once it is resolved', function (): void {
    seedHealthyRuns();
    $d = Distributor::factory()->create(['status' => 'active']);
    GsbCutoffDeferral::create(['distributor_id' => $d->id, 'cutoff_date' => '2026-09-07', 'cause' => GsbCutoffDeferral::CAUSE_EVALUATION_FAILED, 'resolved_at' => now(), 'resolution' => 'backfilled']);

    // run the digest
    expect(app(EngineHealthService::class)->report(now())->deferredCutoffs)->toBe([]);
    Notification::assertNothingSent();
});
```

- [ ] **Step 2: Run, expect FAIL.**
- [ ] **Step 3: Implement** per the rules; delete the `skippedDistributors` code from the cherry-pick.
- [ ] **Step 4: Run, expect PASS** — the digest file, `AdminEngineRunsControllerTest.php`, the dashboard panel test (`grep -rl "Chain alerts" tests`), `tests/Feature/ActionCenter`.
- [ ] **Step 5: Pint + Larastan, commit** — `feat(engines): the health digest lists deferred cut-offs until they are backfilled`

## After the four commits

1. Pint + Larastan on every touched file.
2. Full suites: `tests/Modules/Compensation tests/Feature/Compensation tests/Feature/ActionCenter tests/Feature/RunClockTest.php`.
3. `compliance-officer` over `git diff main...HEAD`. It must see the H1/H2 findings closed by name; a remaining Major stops the merge again.
4. Merge to `main`, push (session consent), deploy staging then production (`git_pull` + `app:deploy --maintenance`; this one **has a migration**).
5. Verification, into `docs/testing/engine-fail-safety-verification-2026-09.md` (append an E5 section): `migrate:status` shows the deferrals table on both; staging only — seed one synthetic deferral row for an active staging distributor for the day before yesterday, run `gsb:daily-cutoff --date=<yesterday>` and confirm the backfill line, the resolved row and the two result rows in date order, then remove the synthetic rows if the settle should not stand (say which in the table); next morning's digest on both.

## Out of scope

Batch 3 (S1–S3), Batch 4 (M1, M3, M4, M5). An Action Center item for open deferrals (the digest and dashboard cover the morning; add one if the client asks). Repairing a deferral for a distributor terminated before it was backfilled — a decision, recorded through the audit log, not code.
