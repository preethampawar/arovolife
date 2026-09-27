# E5 redesign — compliance review fixes (N1, M1–M5, L1–L5)

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task, in this session, on Opus. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** close the compliance-officer's second review of `fix/engine-fail-safety-e5` (2026-09-27): one High (N1, second-night reservation drift), five Mediums, five Lows. H1 and H2 were confirmed closed and must stay closed.

**Architecture:** the reservation for a distributor who is still owed earlier days is computed **on top of the pure results of those owed days, chained in memory in date order**, so the pool freezes what the backfill will pay. The backfill then **compares what it paid with what was reserved** and records any excess in the audit log and the digest. Everything else is audit, copy and surfacing.

**Spec:** `docs/plans/engine-fail-safety-e5-redesign-2026-09-27.md` plus the compliance-officer review of 2026-09-27 quoted per task below. User decision 2026-09-27: option A (chain in memory, detect excess, record the residual drift in R-113, fix all Mediums and Lows).

Status: approved 2026-09-27. Planned on Fable; implement on Opus.
Branch: continue on `fix/engine-fail-safety-e5` (head `2b693001`). One commit per task. All paths relative to `app/` unless they start with `docs/`.

Facts to keep in mind:

- `GsbCutoffService::computeForDistributor(int, Carbon)` (`Services/GsbCutoffService.php:114`) reads the store at `:170-173` (`GsbCarryforward` row → `$cfPower`, `$cfSlab1`, `$cfSide`), rewinds from `$existing` at `:181-187`, simulates the top-up at `:297-314` through `GsbPersonalBvTopupService::pendingPlanForDistributor(int, Carbon)`, and returns a `GsbCutoffComputation` (`Services/DTOs/GsbCutoffComputation.php`) carrying `outcome`, `strongerSide`, `powerSideBefore`, `cfBeforePower`, `cfBeforeSlab1`, `newPowerCf`, `newSlab1Cf`, `topupOrderIds`. `settleMatchable()` (`:584`) writes the store as `power_side_bv_paise = newPowerCf`, `power_side = strongerSide`, `slab1_weaker_bv_paise = newSlab1Cf` for both no-match and matched; a forfeited day leaves the store untouched; below-min / already-settled write nothing.
- `GsbDailyCutoffCommand` (`Console/Commands/GsbDailyCutoffCommand.php`): by-name refusals `:192-235`; deferral discovery `:258-282`; backfill call + `$stillOwed` → `CAUSE_EARLIER_DAY_OPEN` `:286-311`; roster chunk loop `:343-420` (deferred ids skip the idle batch, `$deferredSeen`); pool freeze `:437-455`; pass 2 writes the deferral rows for `$deferredSeen` at `:468-485`, zero-reservation rows for computations that threw at `:541-556`; `backfillDeferrals()` `:718-826`; `resolveDeferral()` `:833`; `writeDeferral()` `:850`.
- `GsbCutoffDeferral` model: `CAUSE_EVALUATION_FAILED`, `CAUSE_EARLIER_DAY_OPEN`, `RESOLUTION_BACKFILLED`, `RESOLUTION_MANUAL`, `scopeOpen()`; columns `reserved_slab`, `reserved_gsb_paise`, `reserved_msb_points`, `resolved_at`, `resolution`, `gsb_cutoff_result_id`.
- `MsbAccrual` DTO has `public readonly int $points`. `MentorshipBonusService::reservedPointsFor(int $sponseeId, int $slab): int` exists.
- Audit rows in this module are `AuditLog::create([...])` (see `Http/Controllers/Admin/AdminManualControlsController.php:114` for the shape used by the Retry refusal — copy it; `actor_id` null for an engine, and digests via `AuditLog::digest()` raw bytes, never hex).
- Digest: `Services/EngineHealthService.php` `deferredCutoffs(Carbon $now): array` (`:452`) feeds the `deferredCutoffs` bucket; dashboard tile `resources/views/admin/dashboard/panels/compensation.blade.php:34`; help doc `resources/help/compensation.md` E5 paragraph (the line containing "the money that was reserved"); risk register `docs/compliance/risk-register.md` row R-113 (line 123).
- Tests: `docker exec -e DB_CONNECTION=mysql -e DB_DATABASE=arovolife_test -e DB_HOST=db -e DB_PORT=3306 -e DB_USERNAME=arovolife -e DB_PASSWORD=secret arovolife-app php artisan test --compact <path>` — never bare. Deferral tests live in `tests/Modules/Compensation/GsbDailyCutoffCommandGuardTest.php` (helpers `seedDeferralAchiever`, `seedDeferralCompanyBv`, `deferAchieverOn25th`, `seedEvaluateRunWithDeferrals`), the digest in `tests/Feature/Compensation/EngineHealthDigestTest.php`, the model in `tests/Modules/Compensation/GsbCutoffDeferralTest.php`. Pint on explicit paths; Larastan level 7.
- Every commit: `Compliance-Review: compliance-officer` trailer. Never name the client.

## Global Constraints

- The chained computation is **pure**: no store write, no top-up apply, no result row. Only `settle()` writes.
- Nothing is credited on a stale verdict; the backfill still computes each owed day fresh.
- Every refusal and every write-off is a log line **and** an `audit_log` row; ADNs and ids only.
- No new queue, no new table; one additive migration (Task 2).

## Review Focus

1. A distributor owed two days whose second day only matches because of the first day's carry-forward — the second day's reservation now includes that slab. Task 1 pins it (the reviewer's 10k/8k → 6k/8k example).
2. A backfill that pays more than was reserved — detected, audited, in the digest. Task 2 pins it.
3. A deferral-row write that fails on the night — the run stops before any settle, nothing is credited. Task 4 pins it.
4. `evaluate_skip_cap = 0` — the run fails closed on the first evaluation error, as before E5. Task 5 pins it.
5. An MB failure inside the backfill — counted, the run row fails, the deferral stays resolvable. Task 3 pins it.

---

### Task 1: N1 — chain the owed days in memory for the reservation

**Review text (High):** "From the second consecutive deferred night, the reservation and the backfill are computed on different carry-forward stores, so the backfill can pay money no pool reserved. Pass 1 reserves day D+1 on a store that never absorbed day D … Example: store empty after D−1. Day D is L 10,000 / R 8,000, no match, reserved 0. Night D+1 is still failing: L 6,000 / R 8,000 on the unadvanced store, no match, reserved 0. Later backfill: D leaves power CF 10k on L and slab-1 CF 8k. D+1 then has L 16k / R 8k with weakerTotal 16k, so slab 1 matches. That fixed gross was never in D+1's `fixed_payout_paise`, and the sponsor's MB points were never in D+1's MSB denominator."

**Files:**
- Create: `app/Modules/Compensation/Services/DTOs/GsbCarryforwardSnapshot.php`
- Modify: `app/Modules/Compensation/Services/GsbCutoffService.php` (`computeForDistributor`), `app/Modules/Compensation/Services/GsbPersonalBvTopupService.php` (`pendingPlanForDistributor`), `app/Modules/Compensation/Console/Commands/GsbDailyCutoffCommand.php`
- Test: `tests/Modules/Compensation/GsbDailyCutoffCommandGuardTest.php`, `tests/Modules/Compensation/GsbCutoffServiceTest.php`

**Interfaces:**
- Produces `GsbCarryforwardSnapshot` (final readonly): `?string $powerSide`, `int $powerPaise`, `int $slab1Paise`, `list<int> $consumedTopupOrderIds`; static `after(GsbCutoffComputation $c, ?GsbCarryforwardSnapshot $prior): self` — for `OUTCOME_NO_MATCH` and `OUTCOME_MATCHED` returns (`$c->strongerSide`, `$c->newPowerCf`, `$c->newSlab1Cf`, prior consumed + `$c->topupOrderIds`); for every other outcome (forfeited, below-min, already-settled) returns `$prior` unchanged, or, when `$prior` is null, (`$c->powerSideBefore`, `$c->cfBeforePower`, `$c->cfBeforeSlab1`, `[]`) — the store as the computation read it. Mirror `settleMatchable()` exactly; add a comment naming it as the parity partner.
- `GsbCutoffService::computeForDistributor(int $distributorId, Carbon $date, ?GsbCarryforwardSnapshot $assumedStore = null): GsbCutoffComputation`. When `$assumedStore` is given: `$cfPower/$cfSlab1/$cfSide` come from it instead of the `GsbCarryforward` row; the `$existing->advancedCarryForward()` rewind must not fire (throw `\LogicException('An assumed store cannot be combined with a settled row for the same date')` if `$existing !== null && $existing->advancedCarryForward()`); the top-up plan excludes `$assumedStore->consumedTopupOrderIds`. The out-of-order guard is unchanged.
- `GsbPersonalBvTopupService::pendingPlanForDistributor(int $distributorId, Carbon $cutoffDate, array $excludeOrderIds = []): array` — filters the accruals by `order_id` before summing.
- Command: for every distributor in `$deferredIds` that has open deferrals dated before tonight (the `$stillOwed` query at `:303`, widened to select `distributor_id, cutoff_date` ordered by `cutoff_date`), build `array<int, list<Carbon>> $owedDays`. In the chunk loop, for such a distributor: `$snapshot = null; foreach ($owedDays[$id] as $day) { $snapshot = GsbCarryforwardSnapshot::after($this->cutoff->computeForDistributor($id, $day, $snapshot), $snapshot); }` then `computeForDistributor($id, $date, $snapshot)`. Any throwable in the chain falls into the existing catch paths (out-of-order → not owed; other → the zero-reservation deferral at `:541`). Add a `Log::info('gsb.cutoff.reservation_chained', ['distributor_id', 'owed_days' => count, 'date'])` per chained distributor.

- [ ] **Step 1: failing test — the reviewer's example.** In the guard test file: seed an achiever with min BV met, defer them for day D with L 10,000 / R 8,000 BV (no match) and for day D+1 with L 6,000 / R 8,000 (owed day still open, evaluation still failing on D+1's night). Run the full cut-off for D+1. Assert the D+1 `gsb_daily_pools.fixed_payout_paise` includes slab 1's gross for that distributor and the D+1 deferral row has `reserved_slab = 1`, while D's deferral row has `reserved_slab = null`. Then run the full cut-off for D+2 with the evaluation healthy and assert two result rows in date order, D `no_match`, D+1 `credited` slab 1, and that the D+1 pool's leftover is unchanged by the credit (paid out of the reservation).
- [ ] **Step 2: run it, expect FAIL** (D+1 reserved_slab null today).
- [ ] **Step 3: service test** in `GsbCutoffServiceTest.php`: `computeForDistributor()` with an assumed store of (`'L'`, 10,000, 8,000) on a day with L 6,000 / R 8,000 matches slab 1 and reports `cfBeforePower = 10000`, `cfBeforeSlab1 = 8000`; with a consumed top-up order id, the plan excludes it. Expect FAIL.
- [ ] **Step 4: implement** the DTO, the service parameter, the top-up exclusion and the command chaining as specified.
- [ ] **Step 5: run** the guard file, `GsbCutoffServiceTest`, `GsbDailyCutoffCommandTest`, `GsbIdleCutoffBatchTest` — all green.
- [ ] **Step 6: commit** `fix(gsb): reserve a still-deferred distributor's day on top of the days they are owed`.

### Task 2: N1 — the backfill detects and records an excess over the reservation

**Review text:** "The backfill never compares its gross with `reserved_gsb_paise` / `reserved_msb_points`, so an overspend is not even detected. Minimum to unblock: at backfill, compare the gross and MB points with the reserved columns, and write an `audit_log` row plus a digest line when the backfill exceeds them (`gsb.cutoff.backfill_exceeds_reservation`: pool date, reserved, paid, delta); widen R-113 to name the multi-night carry-forward drift and the title / sponsor-BV / reversal drift."

**Files:**
- Create: `app/Modules/Compensation/Database/Migrations/2026_09_27_110000_add_paid_columns_to_gsb_cutoff_deferrals_table.php` — nullable `paid_gsb_paise` (unsigned big int), nullable `paid_msb_points` (unsigned int), nullable `exceeded_reservation_at` (timestamp). Forward-only; run `php artisan migrate` in the container on dev.
- Modify: `GsbCutoffDeferral` model (`$fillable`, `casts()`, `scopeExceededSince(Carbon $since)`), `GsbDailyCutoffCommand::backfillDeferrals()`, `EngineHealthService::deferredCutoffs()` + digest view/text, `docs/compliance/risk-register.md` R-113, `resources/help/compensation.md`.
- Test: guard test file, `EngineHealthDigestTest.php`.

- [ ] **Step 1: failing test.** Defer an achiever on day D with `reserved_slab = null`, `reserved_gsb_paise = 0`, `reserved_msb_points = 0` (write the row directly), then arrange D so a fresh computation matches slab 1 (e.g. seed the BV after the deferral row was written), and run the full cut-off for D+1. Assert: the deferral resolves `backfilled` with `paid_gsb_paise` = slab 1 gross, `paid_msb_points` = the sponsor's points (or 0 with MB off), `exceeded_reservation_at` set; an `audit_log` row `gsb.cutoff.backfill_exceeds_reservation` exists whose metadata carries `distributor_id`, `adn`, `cutoff_date`, `reserved_gsb_paise`, `paid_gsb_paise`, `reserved_msb_points`, `paid_msb_points`, `delta_gsb_paise`, `delta_msb_points`; a `Log::warning` with the same key. Digest test: the morning digest's deferred-cut-offs section carries a line "Backfilled over the reservation" for that row (resolved in the last 24 h), naming ADN, day, reserved ₹ vs paid ₹, and the instruction that the day's pool figures were exceeded by that amount and the client should be told.
- [ ] **Step 2: run, expect FAIL.**
- [ ] **Step 3: implement.** After `settle()` in `backfillDeferrals()`: `$paidGross = $result->status === CREDITED ? (int) $result-><gross column> : 0`; after MB: `$paidPoints = $accrual?->points ?? 0`. Update the deferral with the paid columns; when `$paidGross > $deferral->reserved_gsb_paise || $paidPoints > $deferral->reserved_msb_points` set `exceeded_reservation_at`, write the audit row and the warning. Digest: a second list in the bucket from `GsbCutoffDeferral::exceededSince($now->subDay())`; text as in Step 1; the count on the dashboard tile is Task 6's job.
- [ ] **Step 4: R-113.** Rewrite the row: keep the two verdict residuals, add (3) multi-night carry-forward drift is now closed by the in-memory chain, and the residual drift sources that remain — a personal-purchase title crossed between the deferring night and the backfill (raises `maxGsbSlab`), the sponsor crossing the MB minimum-BV gate, group BV reversed in between, and a deferred distributor whose own computation threw (zero reserved) — each of which can make the backfill pay more than the day reserved; control: the backfill records paid vs reserved on the row, writes `gsb.cutoff.backfill_exceeds_reservation` to the audit log and the next digest lists it; the excess is real money paid out of the day's leftover or, when that is short, on top of the priced pool. Owner "Platform", status "Accepted by the user 2026-09-27 (option A); pending the client's sight of it".
- [ ] **Step 5: help doc.** Replace "paid at each day's frozen pool value — the money that was reserved" with wording that says the reservation is computed on top of any earlier days still owed, and that the digest reports any backfill that paid more than was reserved.
- [ ] **Step 6: run** guard + digest files green. Pint. **Commit** `fix(gsb): the backfill records what it paid against what the night reserved`.

### Task 3: M4 + M3 — backfill MB failures count; an out-of-order deferral is superseded, not silently manual

**Review text (M4):** "an MB accrual or credit that throws is logged, but the deferral is still resolved as `backfilled`. The failure is not counted in `$mbFailed` or the exit code." **(M3):** "When the backfill meets `CutoffReplayedOutOfOrder`, it resolves the deferral as `manual` with no `gsb_cutoff_result_id`. That closes an owed day with no result row, recorded only in an application log line that rotates, and it drops off the digest."

**Files:** `GsbDailyCutoffCommand.php`, `GsbCutoffDeferral.php` (add `RESOLUTION_SUPERSEDED = 'superseded'`, `RESOLUTION_WRITTEN_OFF = 'written_off'` for Task 6, `scopeSupersededSince(Carbon)`), `EngineHealthService.php`, guard + digest tests.

- [ ] **Step 1: failing tests.** (a) Backfill with MB on where `accrueForSponsee` throws (bind a partial mock of `MentorshipBonusService` in the container that throws for that sponsee): the deferral still resolves `backfilled` with its result id, the run's summary counts `mb-failed: 1`, and the command exits non-zero exactly as the nightly path does when `$mbFailed > 0`. (b) An open deferral for day D where a `credited` row already exists for D+1 (operator ran them by name): the backfill resolves it `superseded`, writes `audit_log` `gsb.cutoff.deferral_superseded` (distributor_id, adn, cutoff_date, later_row_date), and the digest lists it under "Superseded owed days — check the later row is right" for 7 days after `resolved_at`.
- [ ] **Step 2: run, expect FAIL.**
- [ ] **Step 3: implement.** `backfillDeferrals()` returns `mb_failed`; the caller adds it to `$mbFailed`. Replace the `RESOLUTION_MANUAL` on the out-of-order path with `RESOLUTION_SUPERSEDED` + audit row; keep the log line. Digest: third list in the bucket from `supersededSince($now->subDays(7))`.
- [ ] **Step 4: run** guard + digest green. **Commit** `fix(gsb): backfill MB failures fail the run, and a superseded owed day is audited and shown`.

### Task 4: M5 — a deferral row is written before any settle, or the run stops

**Review text:** "If `writeDeferral` itself fails, the day has neither a result row nor a deferral row … If nobody re-runs the night before the next one, the next night advances the store past it and the day is lost."

**Files:** `GsbDailyCutoffCommand.php`; guard test.

- [ ] **Step 1: failing test.** Make the deferral write fail (e.g. seed an existing *resolved* deferral row for the same (distributor, date) with an attribute that makes `updateOrCreate` violate a constraint — or simpler: bind a `GsbCutoffDeferral` observer / model event `creating` that throws for that distributor id in the test). Run the full cut-off for the night: assert **no** `gsb_cutoff_results` row was written for anyone that night (the run aborted before pass 2), the run row is `failed` with an error naming the ADN and `gsb.cutoff.deferral_write_failed`, and the pool for the date is frozen (freezing before the abort is fine — a re-run reuses it, as today).
- [ ] **Step 2: run, expect FAIL** (today the run continues and settles everyone else).
- [ ] **Step 3: implement.** Move the deferral-row writes out of the pass-2 loop: after the pool freeze and before pass 2, loop `$deferredSeen` (with computation) and the threw-set (without) and write every deferral row inside one `DB::transaction`. Any throwable → `Log::error('gsb.cutoff.deferral_write_failed', …)`, `$this->error(...)`, and `return self::FAILURE` with a message that names the ADNs and says nothing was settled tonight and the night must be re-run. Delete the per-row write at `:468-485` and the `:541-556` loop. Pass 2 keeps skipping deferred ids.
- [ ] **Step 4: run** guard + `GsbDailyCutoffCommandTest` + `NightlyRunCommandTest` green. **Commit** `fix(gsb): every deferral row is written before the first settle, or the night stops`.

### Task 5: M1 + M2 + L2 — refusal copy, refusal logs, and cap 0 = never skip

**Review text (M1):** the later-date refusal says "run that day first (`--date=<owed> --distributor=N --force`)" and leaves out the re-evaluate step, and `--force` lifts the evaluate gate. **(M2):** both CLI by-name deferral refusals write only to the console. **(L2):** `evaluate_skip_cap = 0` cannot turn skip-and-continue off.

**Files:** `GsbDailyCutoffCommand.php:192-235`, `RepurchaseEvaluateCommand.php` (`effectiveSkipCap`), `config/arovolife.php` comment, `resources/help/compensation.md`; tests `GsbDailyCutoffCommandGuardTest.php`, `RepurchaseEvaluateCommandTest.php`.

- [ ] **Step 1: failing tests.** (a) The later-date refusal text contains `repurchase:evaluate --date=<owed date> --distributor=<id>` before the `gsb:daily-cutoff … --force` line. (b) Both CLI refusals produce `Log::warning('gsb.cutoff.refused_open_deferral', [distributor_id, adn, owed_date, date, forced])` and an `audit_log` row `compensation.cutoff.by_name_refused` (same shape as the admin Retry refusal, `actor_id` null). (c) With `arovolife.compensation.evaluate_skip_cap` set to 0, one throwing distributor fails the evaluate run closed (exit non-zero, no summary `failed_distributor_ids`), as before E5.
- [ ] **Step 2: run, expect FAIL.**
- [ ] **Step 3: implement.** `effectiveSkipCap()`: configured 0 → return 0 and the caller treats 0 as "no skipping" (first failure aborts, message says the cap is off). Config comment + help doc: "0 turns skip-and-continue off".
- [ ] **Step 4: run** both files green. **Commit** `fix(gsb): by-name deferral refusals are logged and audited, and skip cap 0 fails closed`.

### Task 6: L1 + L3 + L4 + L5 — dashboard count, a write-off command, same-night resolution, copy

**Review text (L1):** the tile counts digest items (grouped into one). **(L3):** nothing can resolve an inactive distributor's row. **(L4):** a full re-run that settles a formerly deferred distributor leaves the row open one morning. **(L5):** help copy overstates the guarantee (done in Task 2, verify).

**Files:** `resources/views/admin/dashboard/panels/compensation.blade.php:34`; new `app/Modules/Compensation/Console/Commands/GsbWriteOffDeferralCommand.php` (`gsb:write-off-deferral {distributor : id or ADN} {date : YYYY-MM-DD} {--reason=}`), registered wherever this module's commands are registered (check `Providers/*ServiceProvider.php` — memory: module commands MUST be registered or cron fails silently); `GsbDailyCutoffCommand.php:490-500`; tests `AdminDashboardPanelTest.php`, new `tests/Modules/Compensation/GsbWriteOffDeferralCommandTest.php`, guard file.

- [ ] **Step 1: failing tests.** (a) Dashboard tile shows the number of open owed days (sum of the bucket items' `count`), e.g. 3 for three open rows. (b) `gsb:write-off-deferral` refuses without `--reason`, refuses a resolved row, and on an open row sets `resolution = written_off`, `resolved_at`, writes `audit_log` `gsb.cutoff.deferral_written_off` (distributor_id, adn, cutoff_date, reason, reserved figures), and the row leaves the digest. (c) A full re-run for date D that writes a non-failed result row for a distributor with an open deferral for D resolves it `backfilled` with the result id, the same night.
- [ ] **Step 2: run, expect FAIL.**
- [ ] **Step 3: implement.** Help doc: replace "write the day off in the audit log" with the command and that it is a decision, never automatic.
- [ ] **Step 4: run** the three files green. **Commit** `fix(gsb): deferred cut-offs count owed days, can be written off by decision, and resolve on a same-night re-run`.

## After the six commits

1. Pint + Larastan on every touched file.
2. Full suites: `tests/Modules/Compensation tests/Feature/Compensation tests/Feature/ActionCenter tests/Feature/RunClockTest.php` (were 1,664 green at `2b693001`).
3. `compliance-officer` once more over `git diff main...HEAD`, brief: the second review's N1, M1–M5, L1–L5 by name; it must state whether each is closed and whether H1/H2 stay closed. A remaining Major stops the merge.
4. Merge to `main` (`--no-ff`), push `main` and the branch. No deploy from the implementer; the session owner deploys (has two migrations).
