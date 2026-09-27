# Engine fail-safety — Batch 2 of the 2026-09-26 code review

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task, in this session, on Opus. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** the nightly engine chain degrades per distributor instead of platform-wide, never runs two copies of one engine at once, never prices a day whose BV is still in the queue, and records a flag-off monthly payout as what it is.

**Architecture:** five surgical fixes inside the existing orchestrator → leaf command → service layering. No new tables, no new queues, one new config key, one new support class (the queue-backlog wait). Every refusal becomes a `skipped` engine run with a reason, never a silent non-start, exactly as ADR-0016 already does for the preflight.

**Tech Stack:** Laravel 13 / PHP 8.4 / Pest, MySQL (`arovolife_test` for tests), Pennant feature flags, the `compensation` database queue (ADR-0011).

**Spec:** the verified findings E1–E5 of the 2026-09-26 review, reproduced in "What this fixes" below, plus the client's decision on E5 (2026-09-27): *when one distributor's repurchase evaluation errors, the nightly run continues for everyone else; the failed distributors are skipped and listed for the morning.*

Status: approved for implementation 2026-09-27. Planned on Fable; implement on Opus.
Branch: `fix/engine-fail-safety` off `main` (`a180b714` or later). One commit per task, in order.
All paths below are relative to `app/` (the Laravel root) unless they start with `docs/`.

## What this fixes

| # | Problem | Where |
|---|---|---|
| E1 | `payout:monthly-run` exits 0 with "Compensation feature flag is OFF" and is recorded **succeeded** (`Console/Commands/MonthlyPayoutCommand.php:60-64`): the registry entry has `featureFlagClass: null` (`Support/EngineRegistry.php:431`), so `RecordEngineRun` cannot tell the no-op from a run. The weekly payout carries the flag class (`:240`) and is recorded `skipped`. | registry |
| E2 | The GSB "no match" branch writes the carry-forward store and then the result row with no transaction (`Services/GsbCutoffService.php:595-616`), and the personal-BV top-up before it (`:573`) is outside every transaction too. The re-run rewind (`:181`) only triggers when a result row exists, so a crash between the two writes advances carry-forward **twice** on the next run. The frozen and credited branches are wrapped (`:651`, `:667`). | cut-off service |
| E3 | A scheduled run and a manual trigger of the same engine can overlap: the orchestrator calls each step with no lock (`Console/Commands/Concerns/OrchestratesEngineSteps.php:124`), the preflight only checks for a rebuild (`Support/RunPrerequisites.php:120`), and the leaf commands have no in-flight guard. The unique index on `gsb_cutoff_results` blocks a double credit; the damage is E2's double carry-forward plus one failed step. | preflight, leaves, admin trigger |
| E4 | BV that lands after 00:05 is never priced: neither `RunPrerequisites` nor `NightlyRunCommand` looks at the queue, and the nightly run is its own process (`routes/console.php:76-81`), so a backed-up compensation worker lands `PropagateGroupBvJob` BV on a day already cut off — and a day's pools are frozen once, never repriced. | nightly run |
| E5 | One distributor's evaluation error stops every cut-off: `repurchase:evaluate` exits 1 when any distributor threw (`Console/Commands/RepurchaseEvaluateCommand.php:212`), so the nightly run aborts (`NightlyRunCommand.php:126-137`) and nobody is credited. Fail-closed by design; the client chose skip-and-continue. | evaluate, cut-off, digest |

Facts to keep in mind while implementing:

- Engine runs are recorded by `Listeners/RecordEngineRun.php` from the console events: the `running` row is written in `starting()` **before** `handle()` runs, so inside a command `app(EngineRunContext::class)->activeRunId()` is the command's own row. A `--distributor` run is never recorded (partial run). Resolve `EngineRunContext` per call with `app()`, never in a constructor — commands are process-lifetime singletons, the context is container-scoped.
- A deliberate refusal is `app(EngineRunContext::class)->noteSkipped($reason)` + non-zero exit → the row is `skipped` with the reason in `error`. A refusal recorded as `failed` is reported by the health digest for thirty days as something a re-run could fix. Follow the closed-day guard in `GsbDailyCutoffCommand.php:93-121` exactly.
- `EngineStatusService::hasRunInFlight(string $key, ?int $exceptRunId)` (`Services/EngineStatusService.php:347`) is the in-flight test: `running` and started within `EngineRun::STALE_AFTER_MINUTES` (120). Reuse it; do not write a second one.
- Orchestration tree: leaves carry `orchestratedBy`; `EngineDefinition::chainRoot()` walks it up. The nightly run owns `repurchase.evaluate` and `gsb.daily-cutoff`; the monthly run owns two orchestrators that own the monthly leaves.
- A manual trigger is `AdminEngineRunsController::trigger()` → `RunEngineChainJob` on the `compensation` queue → `EngineRunService::runOne()` → `Artisan::call` of the leaf. So a guard **inside the leaf command** covers the manual path and the scheduled path alike.
- The test files that record engine runs opt into console events with `uses(RefreshDatabase::class, WithConsoleEvents::class)` (`tests/Modules/Compensation/NightlyRunCommandTest.php:22`). `GsbDailyCutoffCommandGuardTest.php` records runs without it — check how before adding a test that reads a run row there.
- Tests run only with the isolated DB:
  `docker exec -e DB_CONNECTION=mysql -e DB_DATABASE=arovolife_test -e DB_HOST=db -e DB_PORT=3306 -e DB_USERNAME=arovolife -e DB_PASSWORD=secret arovolife-app php artisan test --compact <path>`
  Never a bare `php artisan test`.
- Pint on explicit paths (`--dirty` fails in the container). Larastan level 7 on every touched file: `docker exec arovolife-app vendor/bin/phpstan analyse --memory-limit=2G --no-progress <paths>`.
- GSB credits are money, so every commit carries `Compliance-Review: compliance-officer`; run the `compliance-officer` agent once over `git diff main...HEAD` before the branch is pushed. Add the session's attribution trailers to every commit.
- Never name the client in code, comments, docs or commit messages — "the client".

## Global Constraints

- `declare(strict_types=1);` in every PHP file; `final` classes; PSR-12 via Pint; Larastan level 7 clean.
- No new Laravel retries on any engine job; `compensation` queue stays one worker, tries 1.
- No enum widening on `gsb_cutoff_results.status` — this batch adds no result status.
- All user-facing copy: "Genos", "group", lakh grouping through `IndianNumber`; ADNs may appear in admin surfaces and the digest, never names or contact details.
- Every refusal is a `skipped` run row with a reason, plus a log line, never only one of the two.

## Review Focus

Inputs the fixes will meet that no single task's tests exercise fully. Each has a test added to the task that owns it.

1. **A leaf invoked by its own orchestrator** must not refuse on the sight of its own `running` row or its parent's — Task 3 pins it (the nightly run still calls both steps).
2. **A stale `running` row** from a worker killed 3 hours ago must not hold the engines hostage — Task 3 pins the `STALE_AFTER_MINUTES` cut.
3. **A job on another queue** (`default`, `otp`) at 00:05 must not delay the night — Task 4 pins it.
4. **A systemic evaluation fault** (hundreds throwing) must still fail closed rather than skip half the platform — Task 5 pins the cap.
5. **The single-distributor retry** the morning remedy tells the operator to run must not itself be skipped by the skip list — Task 5 pins the `--distributor` bypass.

---

### Task 1: E1 — a flag-off monthly payout is recorded as skipped

**Files:**
- Modify: `app/Modules/Compensation/Support/EngineRegistry.php:431`
- Test: `tests/Modules/Compensation/EngineRunRecorderTest.php`

**Interfaces:**
- Consumes: `RecordEngineRun::featureFlagIsOff()` (`Listeners/RecordEngineRun.php:218`), which reads `featureFlagClass` off the registry and records a 0-exit as `skipped` / `feature_flag_off`.
- Produces: nothing new. `payout.monthly` now behaves like `gsb.weekly-payout` (`:240`).

Why the registry and not the command: the command's own check (`MonthlyPayoutCommand.php:60`) is right — `GenosSalesBonusFeature` is the compensation master flag; `MonthlyRunPlanner` (`:132`, `:248`) already treats it that way. What is missing is the declaration that lets the recorder know the exit was a no-op. Side effects, all consistent with the weekly payout: with the flag off the Engine Runs page hides the card (`AdminEngineRunsController.php:77`), the digest's "missing" section counts the skip as ran (`EngineHealthService.php:140`), and the monthly run still fires from the 8th and records the leaf skipped instead of succeeded.

- [ ] **Step 1: Write the failing test** — append to `tests/Modules/Compensation/EngineRunRecorderTest.php`, after the existing flag-off test at `:80`:

```php
it('records a flag-off monthly payout batch as skipped, never succeeded', function (): void {
    // E1 (2026-09-26 review): the batch exits 0 on the compensation flag being
    // off and was recorded succeeded — the only payout engine that was.
    Feature::for(null)->deactivate(GenosSalesBonusFeature::class);

    Artisan::call('payout:monthly-run', ['--month' => '2026-08']);

    $run = EngineRun::where('engine_key', 'payout.monthly')->sole();

    expect($run->status)->toBe(EngineRun::STATUS_SKIPPED)
        ->and($run->summary['reason'])->toBe('feature_flag_off')
        ->and($run->period_start->toDateString())->toBe('2026-08-01');
});
```

Add the `use App\Modules\Shared\Features\GenosSalesBonusFeature;` and `use Laravel\Pennant\Feature;` imports if the file lacks them.

- [ ] **Step 2: Run it, expect FAIL** — `… php artisan test --compact tests/Modules/Compensation/EngineRunRecorderTest.php` → the new test fails with status `succeeded`.

- [ ] **Step 3: Implement** — in `Support/EngineRegistry.php` at the `payout.monthly` definition change

```php
featureFlagClass: null,
```
to
```php
// The compensation master flag, exactly as the weekly payout declares it:
// the command no-ops on it, and only the registry can tell RecordEngineRun
// that the 0-exit was a no-op to record as skipped, not a run (E1).
featureFlagClass: GenosSalesBonusFeature::class,
```
(`GenosSalesBonusFeature` is already imported — it is used at `:221`.)

- [ ] **Step 4: Run, expect PASS** — the recorder test file, then also `tests/Modules/Compensation/MonthlyPayoutCloseCommandTest.php tests/Modules/Compensation/MonthlyRunCommandTest.php tests/Modules/Compensation/MonthlyRunPlannerTest.php tests/Modules/Compensation/AdminEngineRunsControllerTest.php tests/Modules/Compensation/EngineHealthDigestTest.php tests/Modules/Compensation/EngineChainResolverTest.php`. If a test asserts the monthly payout card is visible with the flag off, that assertion was pinning the bug — update it to the hidden-card expectation the weekly payout already has.

- [ ] **Step 5: Pint + Larastan on the registry, then commit**
`fix(engines): record a flag-off monthly payout batch as skipped, like the weekly one`

### Task 2: E2 — the no-match branch and the top-up are atomic

**Files:**
- Modify: `app/Modules/Compensation/Services/GsbCutoffService.php:571-720` (`settle()` from the top-up onward)
- Test: `tests/Modules/Compensation/GsbCutoffServiceTest.php`

**Interfaces:**
- Consumes: `GsbCutoffComputation` (unchanged), `GsbPersonalBvTopupService::applyPendingForDistributor()` (unchanged), `saveResult()` (unchanged).
- Produces: `settle()` keeps its signature and every return value; only atomicity changes.

Shape: everything after the forfeit branch (the top-up at `:573`, the `firstOrCreate` at `:582`, the no-match branch, the frozen branch, the credited branch) moves into one private method, and `settle()` calls it inside a single `DB::transaction`. The two inner `DB::transaction` calls in the frozen and credited branches stay as they are — nested, Laravel turns them into savepoints, and the credited branch's `catch (Throwable)` → `saveResult(FAILED)` still works because the inner rollback is to the savepoint, not the outer transaction. Net effect: a crash anywhere between the top-up and the result row rolls **all** of it back, so the next run recomputes from the same before-state; a settle that completes commits the top-up, the store and the row together.

- [ ] **Step 1: Write the failing tests** — append to `tests/Modules/Compensation/GsbCutoffServiceTest.php` after the no-match idempotency test at `:452`:

```php
it('rolls the carry-forward advance back when the no-match result row cannot be written', function () {
    // E2: the store was advanced, the row failed, and the next run — seeing no
    // row to rewind from — advanced the store a second time.
    $dist = makeDistributorWithBv(300_000);
    GroupBvDaily::create([
        'distributor_id' => $dist->id,
        'date' => today()->toDateString(),
        'left_bv_paise' => 1_000_000,
        'right_bv_paise' => 800_000,
    ]);

    GsbCutoffResult::saving(function (): void {
        throw new RuntimeException('simulated write failure');
    });

    expect(fn () => app(GsbCutoffService::class)->runForDistributor($dist->id, Carbon::today()))
        ->toThrow(RuntimeException::class);

    expect(GsbCutoffResult::count())->toBe(0)
        ->and(GsbCarryforward::where('distributor_id', $dist->id)->exists())->toBeFalse();
});

it('rolls an applied personal-BV top-up back with the no-match row it belonged to', function () {
    enableTopupGolive();
    $dist = makeDistributorWithBv(300_000);            // 3,000 BV pending top-up
    GsbCarryforward::create([
        'distributor_id' => $dist->id,
        'power_side_bv_paise' => 1_600_000,            // R touches slab 1 → top-up fires
        'power_side' => 'R',
        'slab1_weaker_bv_paise' => 0,
    ]);
    GroupBvDaily::create([
        'distributor_id' => $dist->id,
        'date' => today()->toDateString(),
        'left_bv_paise' => 1_000_000,                  // 10,000 + 3,000 top-up = 13,000 < 15,000: no match
        'right_bv_paise' => 0,
    ]);

    GsbCutoffResult::saving(function (): void {
        throw new RuntimeException('simulated write failure');
    });

    expect(fn () => app(GsbCutoffService::class)->runForDistributor($dist->id, Carbon::today()))
        ->toThrow(RuntimeException::class);

    // Nothing of the settle survived: no top-up row, the daily leg untouched,
    // the store exactly as it stood.
    expect(GsbPersonalBvTopup::count())->toBe(0);
    $daily = GroupBvDaily::where('distributor_id', $dist->id)->whereDate('date', today())->first();
    expect($daily->left_bv_paise)->toBe(1_000_000);
    $cf = GsbCarryforward::where('distributor_id', $dist->id)->first();
    expect($cf->power_side_bv_paise)->toBe(1_600_000)
        ->and($cf->power_side)->toBe('R')
        ->and($cf->slab1_weaker_bv_paise)->toBe(0);
});
```

If the file lacks `use RuntimeException;` add it. Before trusting the second test, run it once **without** the `saving` listener and confirm the run lands on `STATUS_NO_MATCH` with a `GsbPersonalBvTopup` row — that proves the scenario exercises the top-up on the no-match branch. If the top-up rule needs a different leg value to fire, adjust the numbers until it does and keep the outcome no-match.

- [ ] **Step 2: Run, expect FAIL** — `… tests/Modules/Compensation/GsbCutoffServiceTest.php` → first test fails on the carry-forward row existing; second on the top-up row existing.

- [ ] **Step 3: Implement** — in `GsbCutoffService::settle()`, immediately after the `OUTCOME_REPURCHASE_FORFEITED` branch's closing `}`, replace the remainder of the method with:

```php
        // Everything from the top-up to the result row commits together (E2).
        // Until 2026-09-27 the top-up and the no-match branch ran outside any
        // transaction: a crash between advancing the store and writing the row
        // left no row to rewind from, and the next run advanced the store a
        // second time. The frozen and credited branches keep their own inner
        // transactions — nested, they become savepoints, and the credited
        // branch's catch still records a FAILED row inside this one.
        return DB::transaction(fn (): GsbCutoffResult => $this->settleMatchable($computation, $existing));
    }

    /**
     * The top-up, the carry-forward store and the no-match / frozen / credited
     * branches — always inside settle()'s transaction, never called directly.
     */
    private function settleMatchable(GsbCutoffComputation $computation, ?GsbCutoffResult $existing): GsbCutoffResult
    {
        $distributorId = $computation->distributorId;
        $date = $computation->date;

        // ← move the existing body here unchanged: the top-up block, the
        //   GsbCarryforward::firstOrCreate, the PARITY PARTNER comment and the
        //   no-match branch, $gross / $baseData, the frozen branch, the
        //   credited try/catch, and the final firstOrFail() return.
    }
```

Do not touch the moved lines beyond re-indenting. `GsbIdleCutoffBatch::row()` is the documented parity partner of the no-match branch; it writes rows in bulk with no store change, so nothing there changes.

- [ ] **Step 4: Run, expect PASS** — the whole `GsbCutoffServiceTest.php`, then `tests/Modules/Compensation/GsbDailyCutoffCommandTest.php tests/Modules/Compensation/GsbIdleCutoffBatchTest.php tests/Modules/Compensation/GsbDailyPoolServiceTest.php`. The existing "retries after failure and credits exactly once" test (`:396`) must still pass — it is the savepoint behaviour.

- [ ] **Step 5: Pint + Larastan on the service, commit**
`fix(gsb): settle the top-up, carry-forward and no-match row in one transaction`

### Task 3: E3 — one run of an engine at a time

**Files:**
- Modify: `app/Modules/Compensation/Support/EngineRegistry.php` (two helpers)
- Modify: `app/Modules/Compensation/Support/RunPrerequisites.php` (one method)
- Modify: `app/Modules/Compensation/Console/Commands/Concerns/OrchestratesEngineSteps.php:95-115` (`orchestratorPreflight`)
- Modify: `app/Modules/Compensation/Console/Commands/GsbDailyCutoffCommand.php` (guard after the closed-day guard)
- Modify: `app/Modules/Compensation/Console/Commands/RepurchaseEvaluateCommand.php` (guard after the date parse)
- Modify: `app/Modules/Compensation/Http/Controllers/Admin/AdminEngineRunsController.php:1044-1050` (`trigger()`)
- Test: `tests/Modules/Compensation/RunPrerequisitesTest.php`, `NightlyRunCommandTest.php`, `GsbDailyCutoffCommandGuardTest.php`, `RepurchaseEvaluateCommandTest.php`, `AdminEngineRunsControllerTest.php`

**Interfaces:**
- Produces:
  - `EngineRegistry::descendantKeys(string $key): list<string>` — every engine orchestrated by `$key`, transitively (`orchestratedBy` chain), excluding `$key`.
  - `EngineRegistry::ancestorKeys(string $key): list<string>` — `$key`'s orchestrator, its orchestrator, … up to the root; empty for a root or a leaf the scheduler fires directly.
  - `RunPrerequisites::inFlightRefusal(array $keys, ?int $exceptRunId, string $heldBackKey): ?string` — a complete message (like `rebuildInFlightRefusal()`) naming the first engine in `$keys` with a run in flight other than `$exceptRunId`, or null. Logs `compensation.scheduler.deferred_for_concurrent_run` at warning when it refuses.
- Consumes: `EngineStatusService::hasRunInFlight()`, `EngineRunContext::activeRunId()`.

Three places ask, each with its own key set, and the sets are what keep a run from refusing itself:

| Caller | Keys checked | Except |
|---|---|---|
| orchestrator preflight | own key + `descendantKeys(own)` | own run id (`activeRunId()`) |
| leaf command (`gsb:daily-cutoff`, `repurchase:evaluate`) | own key only | own run id (null on a `--distributor` run, which has no row — it still refuses if a full run is in flight) |
| admin trigger | target + `ancestorKeys(target)` + `descendantKeys(target)` | none |

A leaf never checks its ancestors: when the nightly run invokes it, the nightly row **is** running, by design. `--force` on the leaf does not lift this guard (a concurrent run is never right); it keeps lifting the evaluate guard as today. A refused leaf is a `skipped` row (`noteSkipped`) + exit 1; a refused orchestrator preflight goes through `abortRun($night, 'preflight', …)` as the rebuild refusal does; a refused trigger is a `ValidationException` on `engine`.

Message text (one template in `RunPrerequisites`):
```
A {label} run started at {HH:MM} is still in flight (run #{id}), so {held-back label} was held back rather than write the same rows beside it.
Wait for it to finish — the Engine Runs page shows it running — then re-run: php artisan {signature} {periodOption}={period}
```
For the controller, drop the second line and end with "Wait for it to finish, then trigger again."

- [ ] **Step 1: Write the failing tests**

`tests/Modules/Compensation/RunPrerequisitesTest.php` — append:

```php
it('refuses while any of the named engines has a run in flight, except the caller\'s own', function (): void {
    Carbon::setTestNow('2026-09-19 00:05:00');
    $own = seedRunRow('compensation.nightly-run', '2026-09-19', EngineRun::STATUS_RUNNING, '2026-09-19 00:05:00');
    seedRunRow('gsb.daily-cutoff', '2026-09-18', EngineRun::STATUS_RUNNING, '2026-09-19 00:01:00');

    $refusal = app(RunPrerequisites::class)->inFlightRefusal(
        ['compensation.nightly-run', 'repurchase.evaluate', 'gsb.daily-cutoff'],
        $own->id,
        'compensation.nightly-run',
    );

    expect($refusal)->toContain('GSB Daily Cut-off')  // use EngineRegistry::get('gsb.daily-cutoff')->label
        ->and($refusal)->toContain('held back')
        ->and($refusal)->toContain('php artisan compensation:nightly-run --date=2026-09-19');
});

it('ignores a running row older than the stale cut', function (): void {
    Carbon::setTestNow('2026-09-19 00:05:00');
    seedRunRow('gsb.daily-cutoff', '2026-09-18', EngineRun::STATUS_RUNNING, '2026-09-18 21:00:00');

    expect(app(RunPrerequisites::class)->inFlightRefusal(['gsb.daily-cutoff'], null, 'gsb.daily-cutoff'))->toBeNull();
});

it('lists an orchestrator\'s steps transitively, and its ancestors upward', function (): void {
    expect(EngineRegistry::descendantKeys('compensation.nightly-run'))->toBe(['repurchase.evaluate', 'gsb.daily-cutoff'])
        ->and(EngineRegistry::descendantKeys('compensation.monthly-run'))->toContain('gbb.monthly', 'payout.monthly')
        ->and(EngineRegistry::ancestorKeys('payout.monthly'))->toBe(['compensation.monthly-payout-close', 'compensation.monthly-run'])
        ->and(EngineRegistry::ancestorKeys('compensation.nightly-run'))->toBe([]);
});
```
(Replace the label literal with `EngineRegistry::get('gsb.daily-cutoff')->label` so the test cannot drift from the registry.)

`tests/Modules/Compensation/NightlyRunCommandTest.php` — append, modelled on the rebuild test at `:342`:

```php
it('holds the run back while a manually triggered cut-off is in flight, and says so on the run row', function (): void {
    Carbon::setTestNow('2026-09-19 00:05:00');

    EngineRun::create([
        'engine_key' => 'gsb.daily-cutoff',
        'period_start' => '2026-09-18',
        'status' => EngineRun::STATUS_RUNNING,
        'trigger' => EngineRun::TRIGGER_MANUAL,
        'started_at' => Carbon::parse('2026-09-19 00:03:00'),
    ]);

    expect(Artisan::call('compensation:nightly-run'))->toBe(1);
    expect(StubEngineStepCommand::$calls)->toBe([]);

    $run = EngineRun::where('engine_key', 'compensation.nightly-run')->sole();
    expect($run->status)->toBe(EngineRun::STATUS_SKIPPED)
        ->and($run->error)->toContain('still in flight')
        ->and($run->error)->toContain('held back');
});

it('does not refuse on the sight of its own running row', function (): void {
    // The recorder writes the nightly run's own `running` row before handle()
    // runs; the preflight must except it or every night refuses itself.
    Carbon::setTestNow('2026-09-19 00:05:00');
    seedComputedCutoffs('2026-09-17', '2026-09-17');

    expect(Artisan::call('compensation:nightly-run'))->toBe(0);
    expect(StubEngineStepCommand::$calls)->not->toBe([]);
});
```

`tests/Modules/Compensation/GsbDailyCutoffCommandGuardTest.php` — append (this file records run rows; follow how `:110` reads one):

```php
it('refuses while another cut-off run is in flight, as a skipped run', function (): void {
    Feature::for(null)->activate(RepurchaseEngineFeature::class);
    seedEvaluateRun('2026-08-26');
    Carbon::setTestNow('2026-08-26 00:10:00');

    EngineRun::create([
        'engine_key' => 'gsb.daily-cutoff',
        'period_start' => '2026-08-25',
        'status' => EngineRun::STATUS_RUNNING,
        'trigger' => EngineRun::TRIGGER_MANUAL,
        'started_at' => Carbon::parse('2026-08-26 00:06:00'),
    ]);

    expect(Artisan::call('gsb:daily-cutoff', ['--date' => '2026-08-25']))->toBe(1)
        ->and(Artisan::output())->toContain('still in flight')
        ->and(GsbCutoffResult::count())->toBe(0);

    $own = EngineRun::where('engine_key', 'gsb.daily-cutoff')->where('trigger', EngineRun::TRIGGER_CONSOLE)->latest('id')->first();
    expect($own->status)->toBe(EngineRun::STATUS_SKIPPED)
        ->and($own->error)->toContain('still in flight');
});

it('--force does not lift the in-flight guard', function (): void {
    Feature::for(null)->activate(RepurchaseEngineFeature::class);
    Carbon::setTestNow('2026-08-26 00:10:00');
    EngineRun::create([
        'engine_key' => 'gsb.daily-cutoff',
        'period_start' => '2026-08-25',
        'status' => EngineRun::STATUS_RUNNING,
        'trigger' => EngineRun::TRIGGER_MANUAL,
        'started_at' => Carbon::parse('2026-08-26 00:06:00'),
    ]);

    expect(Artisan::call('gsb:daily-cutoff', ['--date' => '2026-08-25', '--force' => true]))->toBe(1);
});
```

`tests/Modules/Compensation/RepurchaseEvaluateCommandTest.php` — append:

```php
it('refuses while another evaluation run is in flight', function (): void {
    Carbon::setTestNow('2026-07-30 00:05:00');
    EngineRun::create([
        'engine_key' => 'repurchase.evaluate',
        'period_start' => '2026-07-30',
        'status' => EngineRun::STATUS_RUNNING,
        'trigger' => EngineRun::TRIGGER_MANUAL,
        'started_at' => Carbon::parse('2026-07-30 00:02:00'),
    ]);
    distributorWithAnchor('2026-07-07');

    expect(Artisan::call('repurchase:evaluate', ['--date' => '2026-07-30']))->toBe(1)
        ->and(Artisan::output())->toContain('still in flight')
        ->and(RepurchaseCycle::count())->toBe(0);
});
```

`tests/Modules/Compensation/AdminEngineRunsControllerTest.php` — next to the flag-off refusal at `:333`, same request shape:

```php
it('refuses to trigger an engine while its orchestrator is in flight', function (): void {
    Feature::activate(GenosSalesBonusFeature::class);
    EngineRun::create([
        'engine_key' => 'compensation.nightly-run',
        'period_start' => now()->toDateString(),
        'status' => EngineRun::STATUS_RUNNING,
        'trigger' => EngineRun::TRIGGER_CONSOLE,
        'started_at' => now()->subMinutes(2),
    ]);

    // Same POST as the flag-off test, targeting gsb.daily-cutoff for yesterday.
    $response = /* copy the trigger request from the test at :333 */;

    $response->assertSessionHasErrors('engine');
    expect(session('errors')->first('engine'))->toContain('still in flight');
    Queue::assertNothingPushed();   // or however the file asserts no chain job
});
```

- [ ] **Step 2: Run, expect FAIL** — the five files; the new tests fail on missing methods / exit 0.

- [ ] **Step 3: Implement**

`Support/EngineRegistry.php` — add after `rebuildKeys()`:

```php
    /**
     * Every engine this orchestrator runs, transitively — the monthly run owns
     * two orchestrators that own the monthly leaves. Registry order.
     *
     * @return list<string>
     */
    public static function descendantKeys(string $orchestratorKey): array
    {
        $keys = [];

        foreach (self::all() as $key => $definition) {
            if ($definition->orchestratedBy === null || $key === $orchestratorKey) {
                continue;
            }

            if (in_array($orchestratorKey, self::ancestorKeys($key), true)) {
                $keys[] = $key;
            }
        }

        return $keys;
    }

    /**
     * The orchestrator that runs this engine, then its orchestrator, up to the
     * root. Empty for a root and for an engine the scheduler fires directly.
     *
     * @return list<string>
     */
    public static function ancestorKeys(string $key): array
    {
        $keys = [];
        $parent = self::get($key)->orchestratedBy;

        while ($parent !== null) {
            $keys[] = $parent;
            $parent = self::get($parent)->orchestratedBy;
        }

        return $keys;
    }
```

`Support/RunPrerequisites.php` — add after `rebuildInFlightRefusal()`:

```php
    /**
     * Null unless one of $keys has a run in flight other than $exceptRunId.
     *
     * `withoutOverlapping()` is per scheduled command; a manual trigger runs
     * the same leaf through RunEngineChainJob in another process, and nothing
     * serialised the two (E3, 2026-09-26 review). The unique index stops a
     * double credit; what it cannot stop is the rolling carry-forward store
     * being advanced twice. The caller chooses the key set: an orchestrator
     * asks about itself and its steps, a leaf about itself only (its
     * orchestrator's row is running by design), the admin trigger about the
     * target and everything above and below it.
     *
     * Returns a COMPLETE message, like {@see rebuildInFlightRefusal()}.
     *
     * @param  list<string>  $keys
     */
    public function inFlightRefusal(array $keys, ?int $exceptRunId, string $heldBackKey): ?string
    {
        foreach ($keys as $key) {
            if (! $this->status->hasRunInFlight($key, $exceptRunId)) {
                continue;
            }

            $running = EngineRun::query()
                ->where('engine_key', $key)
                ->where('status', EngineRun::STATUS_RUNNING)
                ->when($exceptRunId !== null, fn ($q) => $q->whereKeyNot($exceptRunId))
                ->latest('started_at')
                ->first();

            $held = EngineRegistry::get($heldBackKey);

            $reason = sprintf(
                "A %s run started at %s is still in flight (run #%d), so %s was held back rather than write the "
                ."same rows beside it.\nWait for it to finish — the Engine Runs page shows it running — then re-run: "
                .'php artisan %s',
                EngineRegistry::get($key)->label,
                $running?->started_at?->format('H:i') ?? '?',
                $running?->id ?? 0,
                $held->label,
                $held->commandSignature,
            );

            Log::warning('compensation.scheduler.deferred_for_concurrent_run', [
                'held_back_engine_key' => $heldBackKey,
                'running_engine_key' => $key,
                'running_run_id' => $running?->id,
                'reason' => $reason,
            ]);

            return $reason;
        }

        return null;
    }
```
(Import `App\Modules\Compensation\Models\EngineRun`. The re-run line names the command only — the caller appends the period where it knows it; the orchestrator preflight passes the message through unchanged, which is enough for an operator.)

`Concerns/OrchestratesEngineSteps.php` — `orchestratorPreflight()`: after the stale-worker check and before the rebuild check, add

```php
        $concurrent = app(RunPrerequisites::class)->inFlightRefusal(
            [$registryKey, ...EngineRegistry::descendantKeys($registryKey)],
            app(EngineRunContext::class)->activeRunId(),
            $registryKey,
        );

        if ($concurrent !== null) {
            return $concurrent;
        }
```
and extend the docblock's numbered list with a fourth check: "A run of this orchestrator, or of any step it owns, still in flight — a manual trigger from the Engine Runs page, in another process."

`GsbDailyCutoffCommand.php` — directly after the closed-day guard (before `$singleId` is read), add:

```php
        // One cut-off at a time (E3). A manual trigger from the Engine Runs
        // page runs this same command on the queue worker while the scheduled
        // night may be running it here; the unique index stops a double credit,
        // not a double advance of the carry-forward store. `--force` does not
        // lift this: a concurrent run is never right. A `--distributor` retry
        // has no row of its own and still refuses while a full run is in flight.
        $concurrent = app(RunPrerequisites::class)->inFlightRefusal(
            ['gsb.daily-cutoff'],
            app(EngineRunContext::class)->activeRunId(),
            'gsb.daily-cutoff',
        );

        if ($concurrent !== null) {
            $this->error($concurrent);
            app(EngineRunContext::class)->noteSkipped($concurrent);

            return self::FAILURE;
        }
```

`RepurchaseEvaluateCommand.php` — the same block after `$asOf` is resolved, with `'repurchase.evaluate'` for both keys. Import `RunPrerequisites`.

`AdminEngineRunsController::trigger()` — after the flag-off `ValidationException`:

```php
        // The scheduled run of this engine, or of the run that owns it, may be
        // on it right now; the leaf would refuse anyway, but as a skipped row on
        // the page a minute later — say it here, before the job is queued.
        $concurrent = app(RunPrerequisites::class)->inFlightRefusal(
            [$engine->key, ...EngineRegistry::ancestorKeys($engine->key), ...EngineRegistry::descendantKeys($engine->key)],
            null,
            $engine->key,
        );

        if ($concurrent !== null) {
            throw ValidationException::withMessages([
                'engine' => strtok($concurrent, "\n").' Wait for it to finish, then trigger again.',
            ]);
        }
```

- [ ] **Step 4: Run, expect PASS** — the five test files plus `tests/Modules/Compensation/MonthlyRunCommandTest.php tests/Modules/Compensation/WeeklyRunCommandTest.php tests/Modules/Compensation/GsbDailyCutoffCommandTest.php tests/Modules/Compensation/EngineRunRecorderTest.php` (the weekly file name may differ — run whatever tests the weekly orchestrator). The existing "runs a closed day with no override at all" and the nightly "runs only the two nightly steps" tests are the self-row check for the leaves.

- [ ] **Step 5: Pint + Larastan on the six PHP files, commit**
`fix(engines): refuse a second run of an engine while one is in flight`

### Task 4: E4 — the nightly run waits for the compensation queue to drain

**Files:**
- Create: `app/Modules/Compensation/Support/CompensationQueueBacklog.php`
- Modify: `app/Modules/Compensation/Console/Commands/NightlyRunCommand.php` (`handle()`, after the preflight)
- Modify: `routes/console.php:73-81` (comment only)
- Test: `tests/Modules/Compensation/CompensationQueueBacklogTest.php` (new), `NightlyRunCommandTest.php`

**Interfaces:**
- Produces:

```php
final class CompensationQueueBacklog
{
    public const QUEUE = 'compensation';
    public const DEFAULT_MAX_WAIT_SECONDS = 1200;   // 20 min: the run must be done before the 02:30 snapshot and 03:00 weekly run
    public const DEFAULT_POLL_SECONDS = 15;

    public function __construct(
        private readonly int $maxWaitSeconds = self::DEFAULT_MAX_WAIT_SECONDS,
        private readonly int $pollSeconds = self::DEFAULT_POLL_SECONDS,
    ) {}

    /** Jobs on the compensation queue right now — waiting or reserved. */
    public function depth(): int;

    /**
     * Poll until the queue is empty or the wait is exhausted. Returns the depth
     * left (0 = drained). $onTick receives (int $depth, int $waitedSeconds) once
     * per poll, for the console.
     */
    public function waitUntilDrained(?callable $onTick = null): int;
}
```
- Consumes: the `jobs` table (`config('queue.connections.database.table', 'jobs')`), the `queue` column.

Only the nightly run waits: it is the one run that prices BV, and `PropagateGroupBvJob` rides the `compensation` queue (`Jobs/PropagateGroupBvJob.php:41`). The weekly and monthly runs sweep entries earned days ago and owe nothing to the queue. Counting **every** job on the queue rather than only BV jobs is deliberate: a `RunEngineChainJob` or a rebuild job queued at 23:59 is exactly what Task 3 refuses to run beside. `depth()` treats a query failure as 0 with a `Log::warning('compensation.queue_backlog.unreadable')` — the wait is a safety net, and a broken `jobs` table must not take the night hostage on top of everything else it breaks.

- [ ] **Step 1: Write the failing tests**

New `tests/Modules/Compensation/CompensationQueueBacklogTest.php`:

```php
<?php

declare(strict_types=1);

use App\Modules\Compensation\Support\CompensationQueueBacklog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

function queuedJob(string $queue): void
{
    DB::table('jobs')->insert([
        'queue' => $queue,
        'payload' => '{}',
        'attempts' => 0,
        'reserved_at' => null,
        'available_at' => time(),
        'created_at' => time(),
    ]);
}

it('counts only the compensation queue', function (): void {
    queuedJob('compensation');
    queuedJob('compensation');
    queuedJob('default');

    expect((new CompensationQueueBacklog)->depth())->toBe(2);
});

it('returns at once when the queue is empty', function (): void {
    $ticks = 0;

    expect((new CompensationQueueBacklog(maxWaitSeconds: 60, pollSeconds: 1))->waitUntilDrained(function () use (&$ticks): void {
        $ticks++;
    }))->toBe(0)
        ->and($ticks)->toBe(0);
});

it('gives up with the depth left once the wait is exhausted', function (): void {
    queuedJob('compensation');

    expect((new CompensationQueueBacklog(maxWaitSeconds: 0, pollSeconds: 0))->waitUntilDrained())->toBe(1);
});
```

`NightlyRunCommandTest.php` — append:

```php
it('holds the night back while compensation jobs are still queued, and says so on the run row', function (): void {
    Carbon::setTestNow('2026-09-19 00:05:00');
    app()->instance(CompensationQueueBacklog::class, new CompensationQueueBacklog(maxWaitSeconds: 0, pollSeconds: 0));
    DB::table('jobs')->insert([
        'queue' => 'compensation', 'payload' => '{}', 'attempts' => 0,
        'reserved_at' => null, 'available_at' => time(), 'created_at' => time(),
    ]);

    expect(Artisan::call('compensation:nightly-run'))->toBe(1);
    expect(StubEngineStepCommand::$calls)->toBe([]);

    $run = EngineRun::where('engine_key', 'compensation.nightly-run')->sole();
    expect($run->status)->toBe(EngineRun::STATUS_SKIPPED)
        ->and($run->error)->toContain('compensation queue')
        ->and($run->error)->toContain('backfills');
});

it('is not held back by a job on another queue', function (): void {
    Carbon::setTestNow('2026-09-19 00:05:00');
    seedComputedCutoffs('2026-09-17', '2026-09-17');
    app()->instance(CompensationQueueBacklog::class, new CompensationQueueBacklog(maxWaitSeconds: 0, pollSeconds: 0));
    DB::table('jobs')->insert([
        'queue' => 'default', 'payload' => '{}', 'attempts' => 0,
        'reserved_at' => null, 'available_at' => time(), 'created_at' => time(),
    ]);

    expect(Artisan::call('compensation:nightly-run'))->toBe(0);
});

it('runs under --force even with jobs queued', function (): void {
    Carbon::setTestNow('2026-09-19 00:05:00');
    seedComputedCutoffs('2026-09-17', '2026-09-17');
    app()->instance(CompensationQueueBacklog::class, new CompensationQueueBacklog(maxWaitSeconds: 0, pollSeconds: 0));
    DB::table('jobs')->insert([
        'queue' => 'compensation', 'payload' => '{}', 'attempts' => 0,
        'reserved_at' => null, 'available_at' => time(), 'created_at' => time(),
    ]);

    expect(Artisan::call('compensation:nightly-run', ['--force' => true]))->toBe(0);
    expect(StubEngineStepCommand::$calls)->not->toBe([]);
});
```

- [ ] **Step 2: Run, expect FAIL** — class missing / exit 0.

- [ ] **Step 3: Implement**

`Support/CompensationQueueBacklog.php`:

```php
<?php

declare(strict_types=1);

namespace App\Modules\Compensation\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * How much work the compensation queue still holds at the instant the nightly
 * run wants to price a day.
 *
 * 00:05 was chosen so an order paid at 23:58 could land its PropagateGroupBvJob
 * before the cut-off (routes/console.php). Five minutes is a guess about the
 * worker, and a backed-up worker lands BV on a day already cut off — a day
 * whose pools are frozen once and never repriced (E4, 2026-09-26 review). So
 * the night waits for the queue, up to a cap, and refuses past it.
 *
 * Every job on the queue counts, not only BV: a manual engine chain or a
 * rebuild queued at 23:59 is exactly what the run must not start beside.
 */
final class CompensationQueueBacklog
{
    public const QUEUE = 'compensation';

    /** The run must finish before the 02:30 snapshot and the 03:00 weekly run. */
    public const DEFAULT_MAX_WAIT_SECONDS = 1200;

    public const DEFAULT_POLL_SECONDS = 15;

    public function __construct(
        private readonly int $maxWaitSeconds = self::DEFAULT_MAX_WAIT_SECONDS,
        private readonly int $pollSeconds = self::DEFAULT_POLL_SECONDS,
    ) {}

    public function depth(): int
    {
        try {
            return DB::table((string) config('queue.connections.database.table', 'jobs'))
                ->where('queue', self::QUEUE)
                ->count();
        } catch (Throwable $e) {
            // A safety net, not a gate: a jobs table that cannot be read must
            // not hold the night on top of everything else it breaks.
            Log::warning('compensation.queue_backlog.unreadable', ['error' => $e->getMessage()]);

            return 0;
        }
    }

    public function waitUntilDrained(?callable $onTick = null): int
    {
        $waited = 0;
        $depth = $this->depth();

        while ($depth > 0 && $waited < $this->maxWaitSeconds) {
            if ($onTick !== null) {
                $onTick($depth, $waited);
            }

            sleep(max(1, $this->pollSeconds));
            $waited += max(1, $this->pollSeconds);
            $depth = $this->depth();
        }

        return $depth;
    }

    public function maxWaitSeconds(): int
    {
        return $this->maxWaitSeconds;
    }
}
```

`NightlyRunCommand::handle()` — after the preflight block and before `$steps = …`:

```php
        // The queue, after the preflight and before any engine: BV still in
        // flight belongs to the day about to be cut off (E4). The weekly and
        // monthly runs sweep entries earned days ago and do not wait.
        $backlog = app(CompensationQueueBacklog::class);
        $remaining = $backlog->waitUntilDrained(function (int $depth, int $waited): void {
            $this->line(sprintf('  %d job(s) still on the compensation queue after %ds — waiting.', $depth, $waited));
        });

        if ($remaining > 0) {
            $reason = sprintf(
                '%d job(s) were still on the compensation queue after waiting %d minute(s), so the nightly run was '
                ."held back: BV they carry belongs to %s, and a day's pools are frozen once.\nCheck the "
                .'compensation worker (php artisan app:status). Nothing is lost: the next nightly run backfills '
                .'%s, or run it by hand once the queue is empty: php artisan compensation:nightly-run --date=%s',
                $remaining,
                intdiv($backlog->maxWaitSeconds(), 60),
                $night->copy()->subDay()->format('d M Y'),
                $night->format('d M Y'),
                $night->toDateString(),
            );

            if (! $this->option('force')) {
                return $this->abortRun($night, 'preflight', $reason);
            }

            $this->warn("Queue not drained but --force was passed:\n{$reason}");
        }
```
Import `App\Modules\Compensation\Support\CompensationQueueBacklog`. Add one sentence to the class docblock's "WHAT RUNS" section: "Before step 1 the run waits, up to twenty minutes, for the compensation queue to empty — BV still queued belongs to the day about to be cut off."

`routes/console.php` — extend the "00:05 rather than 23:59" comment: "…The night starts five minutes in so queued propagation can land, and the run itself waits for the compensation queue to drain before it prices anything (`CompensationQueueBacklog`)."

- [ ] **Step 4: Run, expect PASS** — both files, then `tests/Modules/Compensation/EngineHealthDigestTest.php` (a preflight skip is a chain alert; nothing should change there).

- [ ] **Step 5: Pint + Larastan on the three PHP files, commit**
`fix(engines): the nightly run waits for the compensation queue before it prices a day`

### Task 5: E5 — skip the distributors the evaluation could not judge, and list them for the morning

**Files:**
- Modify: `config/arovolife.php` (one key)
- Modify: `app/Modules/Compensation/Console/Commands/RepurchaseEvaluateCommand.php`
- Modify: `app/Modules/Compensation/Console/Commands/GsbDailyCutoffCommand.php` (roster query, `repurchaseFailureNote()`)
- Modify: `app/Modules/Compensation/Services/EngineHealthService.php`, `Services/DTOs/EngineHealthReport.php`, `Notifications/EngineHealthDigestNotification.php`
- Modify: `resources/views/admin/dashboard/panels/compensation.blade.php:29-35` (sixth bucket)
- Modify: `resources/help/compensation.md:27`
- Test: `RepurchaseEvaluateCommandTest.php`, `GsbDailyCutoffCommandGuardTest.php`, `EngineHealthDigestTest.php`

**Interfaces:**
- Produces:
  - config `arovolife.compensation.evaluate_skip_cap` (int, default 500).
  - `RepurchaseEvaluateCommand::OUTCOME_COMPLETED_WITH_SKIPS = 'completed_with_skips'`; summary gains `failed_distributor_ids: list<int>` (at most the cap). `OUTCOME_FAILED_PARTIAL` is kept for the above-cap case.
  - `EngineHealthReport` gains `public array $skippedDistributors = []` as the **last** constructor parameter; `total()` counts it. Item shape: `array{engine: string, key: string, period: string, period_value: string, cutoff_date: string, count: int, adns: list<string>, ids: list<int>, steps: list<string>}`.
- Consumes: `EngineStatusService::latestRunAfterDay('repurchase.evaluate', $date)` (`:309`), `lastRun()` (`:448`).

The rule, per the client: a distributor whose evaluation throws keeps last night's verdict, is left out of tonight's cut-off, and is named in the 08:00 digest with the exact remedy. Above the cap the run is a fault, not data — it fails exactly as today and the cut-off refuses. Why a cap at all: the cut-off must never silently skip half the platform because the database blinked mid-run.

What the morning remedy is: fix the distributor's data, then before tonight's 00:05 run `repurchase:evaluate --date=<tonight> --distributor=<id>` (refreshes the verdict; a partial run, never recorded) followed by `gsb:daily-cutoff --date=<yesterday> --distributor=<id>` — the single-distributor cut-off prices against the day's frozen pool, which is the existing admin-retry path, and it bypasses the skip list because the operator asked for that distributor by name. Missed that window, the day has been passed by a later cut-off and only a developer night rebuild can replay it (`R-91`). The digest says all three of those things in admin words.

- [ ] **Step 1: Write the failing tests**

`RepurchaseEvaluateCommandTest.php` — rewrite the two failure tests at `:109` and `:131` to the new contract, and add two:

```php
it('carries on past a throwing distributor, evaluates the rest, and exits 0', function (): void {
    // E5, client decision 2026-09-27: one distributor's data problem costs
    // that distributor tonight, never the other N.
    $first = distributorWithAnchor('2026-07-07');
    $broken = distributorWithAnchor('2026-07-13');
    $last = distributorWithAnchor('2026-07-24');

    RepurchaseCycle::creating(function (RepurchaseCycle $cycle) use ($broken): void {
        if ((int) $cycle->distributor_id === $broken->id) {
            throw new RuntimeException('simulated per-distributor failure');
        }
    });

    expect(Artisan::call('repurchase:evaluate', ['--date' => '2026-07-30']))->toBe(0);

    expect(RepurchaseCycle::where('distributor_id', $first->id)->exists())->toBeTrue()
        ->and(RepurchaseCycle::where('distributor_id', $broken->id)->exists())->toBeFalse()
        ->and(RepurchaseCycle::where('distributor_id', $last->id)->exists())->toBeTrue();
});

it('records a completed_with_skips summary naming the skipped ADNs, ids and exception class', function (): void {
    distributorWithAnchor('2026-07-07');
    $broken = distributorWithAnchor('2026-07-13');

    RepurchaseCycle::creating(function (RepurchaseCycle $cycle) use ($broken): void {
        if ((int) $cycle->distributor_id === $broken->id) {
            throw new RuntimeException('simulated per-distributor failure');
        }
    });

    expect(Artisan::call('repurchase:evaluate', ['--date' => '2026-07-30']))->toBe(0);

    $run = lastEvaluateRun();

    expect($run->status)->toBe(EngineRun::STATUS_SUCCEEDED)
        ->and($run->summary['outcome'])->toBe(RepurchaseEvaluateCommand::OUTCOME_COMPLETED_WITH_SKIPS)
        ->and($run->summary['evaluated'])->toBe(1)
        ->and($run->summary['failed'])->toBe(1)
        ->and($run->summary['failed_adns'])->toBe([$broken->adn])
        ->and($run->summary['failed_distributor_ids'])->toBe([$broken->id])
        ->and($run->summary['failure_classes'])->toBe([RuntimeException::class])
        ->and($run->summary['reason'])->toContain('skipped');
});

it('fails closed above the skip cap, because that is a fault in the run and not in the data', function (): void {
    config(['arovolife.compensation.evaluate_skip_cap' => 1]);
    distributorWithAnchor('2026-07-07');
    distributorWithAnchor('2026-07-13');

    RepurchaseCycle::creating(function (): void {
        throw new RuntimeException('simulated systemic failure');
    });

    expect(Artisan::call('repurchase:evaluate', ['--date' => '2026-07-30']))->toBe(1);

    $run = lastEvaluateRun();

    expect($run->status)->toBe(EngineRun::STATUS_FAILED)
        ->and($run->summary['outcome'])->toBe(RepurchaseEvaluateCommand::OUTCOME_FAILED_PARTIAL)
        ->and($run->summary['failed'])->toBe(2)
        ->and($run->summary['reason'])->toContain('more than the 1');
});
```

`GsbDailyCutoffCommandGuardTest.php` — update the refusal test at `:68` so the seeded failed run's summary reads `'outcome' => 'failed_partial', 'evaluated' => 6, 'failed' => 600` (above the cap) and the assertion adds `->toContain('more than the')`; then append:

```php
it('leaves out the distributors last night\'s evaluation skipped, and says so', function (): void {
    Feature::for(null)->activate(RepurchaseEngineFeature::class);
    $kept = Distributor::factory()->create(['status' => 'active']);
    $skipped = Distributor::factory()->create(['status' => 'active']);

    EngineRun::create([
        'engine_key' => 'repurchase.evaluate',
        'period_start' => '2026-08-26',
        'status' => EngineRun::STATUS_SUCCEEDED,
        'trigger' => EngineRun::TRIGGER_CONSOLE,
        'started_at' => Carbon::parse('2026-08-26 00:05:00'),
        'finished_at' => Carbon::parse('2026-08-26 00:06:00'),
        'summary' => [
            'outcome' => 'completed_with_skips',
            'failed' => 1,
            'failed_adns' => [$skipped->adn],
            'failed_distributor_ids' => [$skipped->id],
        ],
    ]);

    expect(Artisan::call('gsb:daily-cutoff', ['--date' => '2026-08-25']))->toBe(0)
        ->and(Artisan::output())->toContain((string) $skipped->adn)
        ->and(GsbCutoffResult::where('distributor_id', $kept->id)->exists())->toBeTrue()
        ->and(GsbCutoffResult::where('distributor_id', $skipped->id)->exists())->toBeFalse();
});

it('never skips a distributor the operator asked for by name', function (): void {
    Feature::for(null)->activate(RepurchaseEngineFeature::class);
    $skipped = Distributor::factory()->create(['status' => 'active']);

    EngineRun::create([
        'engine_key' => 'repurchase.evaluate',
        'period_start' => '2026-08-26',
        'status' => EngineRun::STATUS_SUCCEEDED,
        'trigger' => EngineRun::TRIGGER_CONSOLE,
        'started_at' => Carbon::parse('2026-08-26 00:05:00'),
        'finished_at' => Carbon::parse('2026-08-26 00:06:00'),
        'summary' => ['outcome' => 'completed_with_skips', 'failed' => 1, 'failed_adns' => [$skipped->adn], 'failed_distributor_ids' => [$skipped->id]],
    ]);

    expect(Artisan::call('gsb:daily-cutoff', ['--date' => '2026-08-25', '--distributor' => (string) $skipped->id]))->toBe(0)
        ->and(GsbCutoffResult::where('distributor_id', $skipped->id)->exists())->toBeTrue();
});
```
(If a bare active distributor with no BV is bulk-written as `below_600bv` by the idle batch, the `kept` assertion holds on that row; if the command writes nothing for such a distributor, give `kept` 600 BV of personal ledger the way `GsbDailyCutoffCommandTest.php` builds its distributors, and mirror that for `skipped`.)

`EngineHealthDigestTest.php` — append:

```php
it('lists the distributors last night\'s evaluation skipped, with the day they missed and the remedy', function (): void {
    seedHealthyRuns();
    EngineRun::where('engine_key', 'repurchase.evaluate')->update(['summary' => json_encode([
        'outcome' => 'completed_with_skips',
        'failed' => 1,
        'failed_adns' => ['ADN12345'],
        'failed_distributor_ids' => [42],
    ])]);

    // run the digest the way this file's other tests do
    $text = digestText(sentDigest());

    expect($text)->toContain('could not judge')
        ->and($text)->toContain('ADN12345')
        ->and($text)->toContain('07 Sep 2026')                       // the cut-off day: the run's period minus one
        ->and($text)->toContain('--distributor=42')
        ->and($text)->toContain('before tonight');
});

it('does not list skips from an evaluation older than a day', function (): void {
    seedHealthyRuns();
    EngineRun::where('engine_key', 'repurchase.evaluate')->update([
        'started_at' => '2026-09-06 00:05:00',
        'finished_at' => '2026-09-06 00:06:00',
        'summary' => json_encode(['outcome' => 'completed_with_skips', 'failed' => 1, 'failed_adns' => ['ADN12345'], 'failed_distributor_ids' => [42]]),
    ]);
    // (the missing 2026-09-08 evaluation will be reported separately — that is the existing behaviour)

    // run the digest
    expect(digestText(sentDigest()))->not->toContain('could not judge');
});
```

- [ ] **Step 2: Run, expect FAIL** — three files.

- [ ] **Step 3: Implement**

`config/arovolife.php` — add (inside the existing `compensation` array if there is one, else a new top-level key):

```php
    'compensation' => [
        // How many distributors one repurchase evaluation may skip and still
        // succeed. Above it the run is a fault, not data, and fails closed.
        'evaluate_skip_cap' => (int) env('COMP_EVALUATE_SKIP_CAP', 500),
    ],
```

`RepurchaseEvaluateCommand.php`:
- Add `public const OUTCOME_COMPLETED_WITH_SKIPS = 'completed_with_skips';` with a docblock: "The run finished; up to the cap of distributors threw and keep the previous run's verdict. Exited 0 — the cut-off runs for everyone else and leaves them out (client decision 2026-09-27)." Reword `OUTCOME_FAILED_PARTIAL`'s docblock to "More than the cap threw: a fault in the run, not in the data. Recorded — and exited — as a failure, as before."
- After `$failed = count($failedIds);` add `$cap = max(0, (int) config('arovolife.compensation.evaluate_skip_cap', 500)); $withinCap = $failed <= $cap;`.
- Summary: `'outcome' => $failed === 0 ? self::OUTCOME_COMPLETED : ($withinCap ? self::OUTCOME_COMPLETED_WITH_SKIPS : self::OUTCOME_FAILED_PARTIAL)`, add `'failed_distributor_ids' => array_slice($failedIds, 0, max($cap, 1))`, `'skip_cap' => $cap`, and three reasons:

```php
'reason' => match (true) {
    $failed === 0 => sprintf('Evaluated %d distributor(s) as of %s with no failures.', $evaluated, $asOf->toDateString()),
    $withinCap => sprintf(
        'Evaluated %d distributor(s) as of %s; %d could not be evaluated and were skipped — they keep the '
            ."previous run's verdict and tonight's GSB cut-off leaves them out. Fix them and re-run both for "
            .'each one before the next night, or the day needs a rebuild.',
        $evaluated, $asOf->toDateString(), $failed,
    ),
    default => sprintf(
        'Evaluated %d distributor(s) as of %s; %d could not be evaluated — more than the %d the run may skip, '
            .'so this is a fault in the run, not in the data. The GSB cut-off refuses until it is fixed and the '
            .'evaluation re-run.',
        $evaluated, $asOf->toDateString(), $failed, $cap,
    ),
},
```
- Exit: `return $withinCap ? self::SUCCESS : self::FAILURE;`. The console "Done —" line gains `, skipped: {$failed}` wording when within the cap.
- Update the class docblock's last paragraph and the comment above the per-distributor `try` (`:130-134`) to the new rule.

`GsbDailyCutoffCommand.php`:
- New private method:

```php
    /**
     * The distributors the evaluation covering $date could not judge — left out
     * of a full run (client decision 2026-09-27). Read from the latest evaluate
     * run after the day, whatever its status: over-skipping is conservative
     * (each one is named for the morning and retried by hand), under-skipping
     * prices a day on a verdict nobody refreshed.
     *
     * @return array{ids: list<int>, adns: list<string>}
     */
    private function skippedByEvaluation(Carbon $date): array
    {
        $run = $this->engineStatus->latestRunAfterDay('repurchase.evaluate', $date);
        $summary = is_array($run?->summary) ? $run->summary : [];

        return [
            'ids' => array_values(array_map(intval(...), is_array($summary['failed_distributor_ids'] ?? null) ? $summary['failed_distributor_ids'] : [])),
            'adns' => array_values(array_map(strval(...), is_array($summary['failed_adns'] ?? null) ? $summary['failed_adns'] : [])),
        ];
    }
```
- After the roster `$query` is built and before `$total = (clone $query)->count()`:

```php
        // A full run leaves out the distributors last night's evaluation could
        // not judge; a --distributor run never does — the operator named them
        // after re-evaluating, and that retry prices against the frozen pool.
        $skippedByEvaluation = ['ids' => [], 'adns' => []];

        if ($singleId === null && $this->eligibility->engineActive()) {
            $skippedByEvaluation = $this->skippedByEvaluation($date);

            if ($skippedByEvaluation['ids'] !== []) {
                $query->whereNotIn('id', $skippedByEvaluation['ids']);

                $this->warn(sprintf(
                    '%d distributor(s) left out: their repurchase evaluation failed and they keep the previous '
                    .'verdict%s. Their %s cut-off is not computed until it is re-run for them by name.',
                    count($skippedByEvaluation['ids']),
                    $skippedByEvaluation['adns'] === [] ? '' : ' — ADN '.implode(', ', $skippedByEvaluation['adns']),
                    $date->toDateString(),
                ));

                Log::warning('gsb.cutoff.skipped_unevaluated', [
                    'date' => $date->toDateString(),
                    'count' => count($skippedByEvaluation['ids']),
                    'adns' => $skippedByEvaluation['adns'],
                ]);
            }
        }
```
- Add `, left out: N` to the final "Done —" line.
- `repurchaseFailureNote()` message: replace "Fix them first — the cut-off stays shut while any failure stands, because the day's pools are frozen once and never repriced." with "That is more than the %d the run may skip, so it was recorded as a failure: fix the cause, re-run the evaluation, then this cut-off." reading the cap from `$summary['skip_cap'] ?? config('arovolife.compensation.evaluate_skip_cap', 500)`. Update the comment block above the guard (`:137-146`) to say the platform-wide refusal now applies only above the cap.

`Services/DTOs/EngineHealthReport.php` — add `@phpstan-type SkippedDistributorsItem array{engine: string, key: string, period: string, period_value: string, cutoff_date: string, count: int, adns: list<string>, ids: list<int>, steps: list<string>}`, the parameter `public array $skippedDistributors = []` last with its `@param`, and `+ count($this->skippedDistributors)` in `total()`.

`Services/EngineHealthService.php` — pass `skippedDistributors: $this->skippedDistributors($now)` in `report()` and add:

```php
    /**
     * Last night's evaluation skipped somebody (client decision 2026-09-27):
     * named here because the run itself succeeded and nothing else would say
     * so. Only the most recent run, and only within a day — after that the
     * day has been passed by a later cut-off and the remedy is a rebuild.
     *
     * @return list<SkippedDistributorsItem>
     */
    private function skippedDistributors(Carbon $now): array
    {
        $run = $this->status->lastRun('repurchase.evaluate');
        $summary = is_array($run?->summary) ? $run->summary : [];

        if ($run === null
            || $run->status !== EngineRun::STATUS_SUCCEEDED
            || (int) ($summary['failed'] ?? 0) < 1
            || $run->started_at === null
            || $run->started_at->lessThan($now->copy()->subDay())) {
            return [];
        }

        $definition = $this->definitionFor('repurchase.evaluate');
        $period = $run->period_start;
        $cutoffDay = $period->copy()->subDay();
        $ids = array_values(array_map(intval(...), is_array($summary['failed_distributor_ids'] ?? null) ? $summary['failed_distributor_ids'] : []));
        $adns = array_values(array_map(strval(...), is_array($summary['failed_adns'] ?? null) ? $summary['failed_adns'] : []));

        return [[
            'engine' => $definition->label ?? 'repurchase.evaluate',
            'key' => 'repurchase.evaluate',
            'period' => $this->displayPeriod($definition, $period),
            'period_value' => $this->periodValue($definition, $period),
            'cutoff_date' => $cutoffDay->format('d M Y'),
            'count' => (int) $summary['failed'],
            'adns' => $adns,
            'ids' => $ids,
            'steps' => [
                sprintf('Open each distributor (ADN %s) and fix what the evaluation tripped on — the exception class is on the run\'s summary on the Engine Runs page.', $adns === [] ? '…' : implode(', ', $adns)),
                sprintf(
                    'Before tonight\'s 00:05 run, ask the developer to re-run both for each one by name: php artisan repurchase:evaluate --date=%s --distributor=<id>, then php artisan gsb:daily-cutoff --date=%s --distributor=<id> (ids: %s). The single-distributor cut-off prices against the day\'s frozen pool.',
                    $period->toDateString(),
                    $cutoffDay->toDateString(),
                    $ids === [] ? '…' : implode(', ', $ids),
                ),
                sprintf('Missed that window? %s can then only be rebuilt as a whole — ask the developer for a night rebuild of that day.', $cutoffDay->format('d M Y')),
            ],
        ]];
    }
```
(Import `EngineRun` if not already; use the existing `definitionFor()`, `displayPeriod()`, `periodValue()` helpers.)

`Notifications/EngineHealthDigestNotification.php` — a block after the stuck section, in the same style:

```php
        if ($this->report->skippedDistributors !== []) {
            $mail->line('**Distributors the repurchase evaluation could not judge**');

            foreach ($this->report->skippedDistributors as $item) {
                $position++;
                $mail->line(sprintf(
                    '**%d. %s — %s** (%d distributor(s) skipped: ADN %s; their %s GSB cut-off was not computed)',
                    $position, $item['engine'], $item['period'], $item['count'],
                    $item['adns'] === [] ? '…' : implode(', ', $item['adns']),
                    $item['cutoff_date'],
                ));
                $this->steps($mail, $item);   // whatever the file's per-item step helper is called
            }
        }
```

`resources/views/admin/dashboard/panels/compensation.blade.php` — sixth bucket `['label' => 'Skipped distributors', 'count' => count($report->skippedDistributors)]`, and update the "five buckets" comment to six.

`resources/help/compensation.md:27` — replace the paragraph with:

> `repurchase:evaluate` never abandons a run over one distributor: a distributor whose evaluation throws is recorded, **skipped**, and the run carries on and succeeds (client decision 2026-09-27). The skipped distributors keep the previous run's verdict and that night's GSB cut-off leaves them out — their day is not computed, and it is not lost: the 08:00 engine health digest names them (ADN, exception class, the day they missed) with the remedy, which is to fix the cause and have the developer re-run the evaluation and then the cut-off **for each one by name** before the next 00:05 run; the single-distributor cut-off prices against the day's frozen pool. Once a later night has passed the day, only a night rebuild can replay it. Above the skip cap (developer setting, default 500) a run is a fault, not data: it is recorded as a **failure** with a `failed_partial` summary and the cut-off refuses platform-wide until it is fixed and re-run, as before. Every evaluation run records its counts on the Engine Runs page — distributors evaluated, windows judged fulfilled and forfeited, distributors currently withheld, and skipped.

- [ ] **Step 4: Run, expect PASS** — the three files, then `tests/Modules/Compensation/NightlyRunCommandTest.php tests/Modules/Compensation/GsbDailyCutoffCommandTest.php tests/Modules/Compensation/AdminEngineRunsControllerTest.php tests/Feature/ActionCenter/EngineRunsFailedProviderTest.php` and whatever test covers the dashboard compensation panel (`grep -rl "Chain alerts" tests`).

- [ ] **Step 5: Pint + Larastan on every touched PHP file, commit**
`feat(engines): skip the distributors the repurchase evaluation could not judge and list them for the morning`

## After the five commits

1. Pint on every touched path; Larastan on every touched PHP file.
2. Full engine suites: `tests/Modules/Compensation tests/Feature/Compensation tests/Feature/ActionCenter tests/Feature/RunClockTest.php`.
3. `compliance-officer` agent over `git diff main...HEAD` (GSB credits and the skip rule are money and a forfeit path); `Compliance-Review: compliance-officer` on each commit — amend before push, the branch is local.
4. Push (this session's blanket consent covers it; a new session confirms first). Merge to `main` locally, push.
5. Deploy staging then production with the standard recipe (Cloudways `git_pull`, then `app:deploy --maintenance --health-url=…`). No migrations; a new config key and changed commands → the deploy's `queue:restart` covers the worker; confirm `app:status` shows the scheduler ticking and the compensation worker up.

## Verification after deploy (required before Batch 3 is planned)

Staging first, then the read-only parts on production. Report each line with its actual output into `docs/testing/engine-fail-safety-verification-2026-09.md` (check, environment, result).

1. `php artisan app:status` on both: scheduler ticking, compensation worker up, 0 failed jobs since the deploy.
2. Queue backlog read (both): `php artisan tinker --execute='echo app(\App\Modules\Compensation\Support\CompensationQueueBacklog::class)->depth(), PHP_EOL;'` prints `0` on an idle queue.
3. In-flight guard, staging only, no data written: insert a synthetic `running` row for `gsb.daily-cutoff` dated yesterday with `started_at = now()` (tinker, `EngineRun::create`), run `php artisan gsb:daily-cutoff --date=<yesterday>` → exit 1, output "still in flight", a new `skipped` row with that reason; then delete the synthetic row and the skipped row. If a recompute projection is standing, the preflight refusal comes first — run `compensation:recompute-all --horizon=now` first or wait for the 23:30 reset.
4. Admin trigger refusal, staging: with the same synthetic row in place, Engine Runs → trigger GSB Daily Cut-off → the form error says the run is in flight; remove the row.
5. Next morning (both): the nightly run's `repurchase.evaluate` row shows `outcome: completed` (or `completed_with_skips` naming ADNs), the cut-off succeeded, and the 08:00 digest either says nothing or lists the skipped distributors under "could not judge". On staging the engines run through the recompute replay, so read the replay's rows.
6. Flag-off monthly payout (E1) and the transaction (E2) are pinned by tests only; no staging observation is possible without switching the compensation flag off or crashing a settle. Say so in the table.

Only when every line is green, hand back with "Batch 2 verified" so Batch 3 (S1–S3) can be planned.

## Out of scope here

Batch 3 access (S1–S3), Batch 4 one-liners (M1, M3, M4, M5 reverse MB with GSB). Not done on purpose: an Action Center item for skipped distributors (the digest is the morning list; add one if the client asks), a per-distributor "skipped" result row (would widen the `gsb_cutoff_results` enum for a row that carries no money), and the Batch 1 Razorpay end-to-end test (user decision 2026-09-27: after Batch 4).
