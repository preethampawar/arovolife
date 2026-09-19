# Engine cadence split — progress and hand-off (written 2026-09-18, session ~80%)

> **BUILD COMPLETE 2026-09-19 — do not act on the instructions below.** Every
> slice shipped: S1 `55567a42`, S2 `19a5107a`, S3 `da584146`, S4 `b0e390d0`,
> S5 `ececec4a`; merged to `main` as `e45fdf40`, pushed, and deployed to
> staging the same day. The agreed fresh start (ADR-0016, §5 step 7) ran on
> **staging** (1,691 rows replaced, 183 engine runs, all succeeded) and on
> **dev** (28,625 rows replaced, 316 orders re-propagated, 79 days, 183 runs;
> 182 succeeded + 2 `offers.monthly` skipped for a flag that is off). Both
> environments are on the post-split baseline; production does not exist yet.
> **Still open, tracked elsewhere:** the staging rebuild rehearsal and the
> deletion of `FortuneStagingE2ESeedCommand` before launch (both R-102).
> Kept as the build's record; superseded, not deleted — §2's auto-mode rules
> and §6's resolved findings are the parts still worth reading.

**Purpose:** a fresh Claude session picks up the build from here. Read this file, then the plan, then act. Nothing here needs the previous session's context.

**Opening prompt for the new session:**
> Read `docs/plans/2026-09-18-engine-cadence-split-progress.md` and continue the engine-cadence-split pipeline from where it left off, in auto mode.

## 1. Where everything is

| Item | Location |
|---|---|
| Branch | `feat/engine-cadence-split` (off `main`; nothing pushed) |
| The approved plan (authority for every slice) | `docs/plans/2026-09-18-engine-cadence-split.md` (untracked copy of `~/.claude/plans/adaptive-fluttering-gadget.md`; S5 commits it) |
| Superseded first-month plan | `docs/plans/2026-09-18-nightly-chain-first-month.md` (untracked; S5 marks it superseded, does not delete) |
| Decisions memory | `~/.claude/projects/-Users-preetham-Documents-arovolife-arovolife-arovolife-code/memory/engine_retry_and_heal_doctrine_2026_09_18.md` |
| Commits so far | S1 `55567a42`, S2 `19a5107a` (both carry `Compliance-Review:` trailers) |

**Never stage these six files** — they are another slice's uncommitted dashboard work and must stay exactly as they are: `app/app/Modules/Admin/Services/DashboardPanelData.php`, `app/resources/help/dashboard.md`, `app/resources/views/admin/dashboard.blade.php`, `app/resources/views/admin/dashboard/panels/people.blade.php`, `app/tests/Browser/admin-dashboard.spec.js`, `app/tests/Modules/Admin/AdminDashboardPanelTest.php`.

## 2. How the work is run ("auto mode", approved by the user)

- **Pipeline per slice:** `implementer` (Opus) builds → `reviewer` (Fable) reports CONFIRMED/PLAUSIBLE → `compliance-officer` reviews the money paths → fixes applied by the implementer → commit with the trailer. `qa` (Opus) runs once at the end of the full build (tests + browser check of the Engine Runs page as admin vs developer). Never more than two implementers at once; S3 runs alone.
- **Commit trailer, every slice:** `Compliance-Review: compliance-officer (<date>, <slice> — <verdict>)` then `Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>` and `Claude-Session: https://claude.ai/code/session_01HxGK9huR6DbmD3fkKeVJtL`.
- **Staging files:** build the list from `git status --porcelain`, exclude the six dashboard files and the untracked plans, pipe through `xargs git add -A --` (zsh does not word-split a `$FILES` variable).
- **Tests — never bare:** `docker exec -e DB_CONNECTION=mysql -e DB_DATABASE=arovolife_test -e DB_HOST=db -e DB_PORT=3306 -e DB_USERNAME=arovolife -e DB_PASSWORD=secret arovolife-app php artisan test --compact <paths>`. ONE test process at a time (two on `arovolife_test` collide and leave a half-migrated schema).
- **Lint:** `vendor/bin/pint --dirty --format agent` from `app/`; `docker exec arovolife-app ./vendor/bin/phpstan analyse --level=7 --no-progress <files>`. Whole-project phpstan residue is **36 errors, all pre-existing and outside this branch** (`AdminDashboardPanelTest` 18, `GrnPurchaseOrderLinesTest` 8, `AdminRoleSeparationTest` 3, two `ActionCenter` grievance provider tests 3 each, `RegistryPermissionScopingTest` 1). It must not grow. Re-pinning an existing baseline COUNT is allowed; adding a new baseline entry is not.
- **User rules:** confirm before any push (solo dev); every destructive command (recompute, migrate:fresh, deletes) gets the five-part warning and its own explicit yes; questions to the user go as a simple example plus A/B/C/D options with the recommended option first; never name the client, say "the client".
- **Editor diagnostics** (intelephense "undefined method/type") in this repo are stale-index noise when phpstan passes — verify the import exists, then ignore.

## 3. Slice status

| Slice | Content | Status |
|---|---|---|
| S1 | Support layer: `PlatformStart`, `MonthCutoffCoverage`, `WeeklyRunPlanner`, `MonthlyRunPlanner`(+`MonthlyRunPhase`, `MonthlyRunDeferral`), `RunPrerequisites`, `FrozenPayoutGuard`, D13 in `EngineStatusService`, A1/A6 frontier, D1/D2, R-101 | **Committed `55567a42`** |
| S2 | `compensation:nightly-run` (narrowed) / `weekly-run` / `monthly-run`, trait `OrchestratesEngineSteps`, three scheduler entries, `RecordSkippedOrchestratorRun`, registry (16 engines, `rootOrchestratorKeys()`), D5, `creditingRefusal()` in the seven monthly engines, admin retry deleted (`RetryNightlyChainJob`, `retry-chain` route, `retryChain()`, button), three informational banners, step list scoped by the failed run's window, IST pin on `defaultPeriodDate()`, `EngineHealthService` §20 copy (pulled forward), `help/compensation.md` retry lines rewritten (pulled forward) | **Committed `19a5107a`** — 1,171 tests green; reviewer + compliance PASS |
| S3 | Rebuild core: `CarryforwardRewind` (extracted from `WindowedStateWiper`), `PayoutService::unbuildBatch()` + `BatchIsFrozen`, `Services/Rebuild/*` (`RebuildKind`, `RebuildPlan`, `RebuildPreflight`, `NightRebuilder`, `MonthRebuilder`, `RebuildPlanner`), `RebuildPeriodJob`, four commands `compensation:rebuild-night/-week/-month/-payout` + trait `RebuildsPeriod`, four registry entries (`developerOnly`, `rebuildKeys()`, 20 engines), `AppServiceProvider` registration, engine cards hide `developerOnly` | **Committed `da584146`** — reviewer + compliance PASS (DN-1 signed off, five conditions recorded in R-102). |
| S4 | Developer surface: `AdminEngineRunsController::rebuildPreview()`/`rebuild()`, `role:developer` routes (`POST engine-runs/rebuild/preview`, `POST engine-runs/rebuild`), `@developer` panel + preview card in `engine-runs/index.blade.php`, `parsePeriodOrFail()` refusals (`creditingRefusal()` for month engines; A1 belt: refuse a manual `gsb.daily-cutoff` for a date with a later advancing cut-off row), whatever §20 health copy S2 did not already cover (most of it is done — verify), controller tests (admin family 403 + no panel copy; developer sees panel; preview/fingerprint/queue/audit `compensation.rebuild.queued`) | **Committed `b0e390d0`** — three residuals recorded as R-102 addenda. |
| S5 | Docs (Sonnet): ADR-0016 + ADR-0015 status line; runbooks `engine-failure-triage.md`, `artisan-commands.md`; remaining ~27 "nightly chain" cadence sentences in `help/compensation.md`, `help/payout-operations.md` L41/L80; risk register R-102 + addenda R-81/R-89/R-90/R-91 + a pre-launch note that `FortuneStagingE2ESeedCommand` calls `FortuneBonusService::runForMonth()` directly and bypasses the frozen guard (staging-only, marked delete-before-launch, no env gate); mark the first-month plan superseded; commit the plan copy; reword `overview.blade.php:11` / `manual-controls/index.blade.php:11` only if now false | **Committed `ececec4a`** — ADR-0016, both runbooks, help copy, R-102 + addenda, Fortune pre-launch note. |

## 4. S3 files present on disk when this was written

New: `app/app/Modules/Compensation/Console/Commands/{RebuildNightCommand,RebuildWeekCommand,RebuildMonthCommand,RebuildPayoutCommand}.php`, `Console/Commands/Concerns/RebuildsPeriod.php`, `Exceptions/BatchIsFrozen.php`, `Jobs/RebuildPeriodJob.php`, `Services/Rebuild/` (whole folder), `Services/Recompute/CarryforwardRewind.php`; tests `CarryforwardRewindTest`, `NightRebuildTest`, `PayoutUnbuildBatchTest`, `WeekRebuildTest`, `MonthRebuildTest`, `PayoutRebuildTest`, `RebuildPeriodJobTest` under `app/tests/Modules/Compensation/`.
Modified: `Models/GsbCutoffResult.php`, `Services/EngineStatusService.php`, `Services/PayoutService.php`, `Services/Recompute/WindowedStateWiper.php`, `Support/EngineDefinition.php`, `Support/EngineRegistry.php`, `Providers/AppServiceProvider.php`, `phpstan-baseline.neon`, `resources/views/admin/compensation/engine-runs/index.blade.php`, `tests/Feature/EngineRegistryTest.php`, `tests/Modules/Compensation/{AdminEngineRunsControllerTest,GsbCutoffServiceTest}.php`.

## 5. Exact next steps for the new session

1. **Verify S3 as it stands** (the previous implementer's final report may never have arrived). Dispatch one `implementer` (Opus) with: the plan sections for S3 (file rows 12, 15, 29–33; §12 rebuild rows, §13, §27–§33; D4, D9–D14, DN-1, DN-2, DN-5, DN-6; the Tests table rows for the seven S3 test files; `EngineRegistryTest` → twenty), and the instruction to finish anything incomplete, then run in this order, one process at a time: the seven new test files; `WindowedRecomputeTest`, `CompensationRecomputeTest`, `PayoutServiceTest`, `EngineRegistryTest`, `ScheduledCommandsAreRegisteredTest`, `AdminEngineRunsControllerTest`; then all of `tests/Modules/Compensation` + `tests/Feature/EngineRegistryTest.php`; pint; phpstan on every changed file; whole-project phpstan stays at 36; `php artisan list compensation` shows the four rebuild commands; `schedule:list` still has exactly three compensation run entries; a read-only dry run `compensation:rebuild-night --date=<today> --actor=<developer id>` answered "no" at the confirm (nothing written; never confirm a rebuild on the dev DB). Report plan-vs-code disagreements (column names, status constants, `withSweepLock` return type, the plan's "Could not verify" list on `RankQualificationService`).
2. **Review S3:** `reviewer` (Fable) on `git diff` + the untracked S3 files — hardest review of the build: `NightRebuilder` must assert CF equality against the pre-wipe `*_before` values and figure equality after the re-run; `plan()`/`wipe()` must share query builders; every refusal in plan §30; D11 newest-night rule; nothing deletes a credit.
3. **Compliance:** `compliance-officer` with an EXPLICIT sign-off requested on DN-1 (deleting a never-approved batch's own debit rows), D10, D11, D12, the `payout.batch.unbuilt` audit row, the sweep lock, and the actor/maker rule (D14: the rebuilt batch's maker is the developer, who then cannot approve it — R-81).
4. Apply findings, re-run the affected tests, **commit S3** with the trailer (message body: what a rebuild deletes/un-sweeps/refuses; DN-1 sign-off quoted).
5. **S4 + S5 together** (two implementers max; S5 on Sonnet). Then reviewer + compliance on S4 (the developer gate: `role:developer` + controller 404, zero trace for admins, fingerprint confirm, reason min 10, audit row). Commit each.
6. **QA** (Opus): full suite + browser check of Engine Runs as `admin` (three banners possible, no "rebuild"/"retry" in the HTML) and as `developer` (`staff:create` makes one): panel, preview with counts + warnings, confirm modal, queued job, `compensation.rebuild.queued` in the Audit Log; `admin-finance` POST to `engine-runs/rebuild` → 403.
7. **Ask the user before pushing.** Then rollout per the plan: merge to `main`, Cloudways `git_pull`, `app:deploy --skip-composer --skip-npm --skip-migrate --skip-seed`, restart scheduler + the three `queue:work` crons, confirm `DB_QUEUE_RETRY_AFTER` > 7200, `schedule:list` three entries; rehearse one rebuild on staging (record in R-102); then the **fresh start**: `php artisan compensation:recompute-all --horizon=now` on dev (container `arovolife-app`, DB `arovolife`) and on staging (SSH, Cloudways-local MySQL) — each with the five-part destructive warning (exact command, target, `count(*)` per derived table, non-destructive alternative, "Proceed? yes/no") and its own yes at execution time; never delete `audit_log`; do NOT `--force`-close staging's Aug/Sep beforehand. Production does not exist.

## 6. Review findings already resolved (do not re-raise)

- S1: D13 + out-of-order refusal (A1 skipped, A6 frontier), frozen month with no close row (A2), replay anchor (A3), prerequisites message (A4), A6–A12 compliance fixes, R-101.
- S2: two S1 test files red on phpstan (Pest matcher has no `not` — chain split, no baseline entry); banner step list scoped by run window; weekly deferral copy; three docblocks; IST pin; §20 digest copy; four help lines; weekly banner wording. Compliance's note that `MonthlyRunPlanner::closePhase()` uses `frozenBatchFor()` was stale — it already uses `creditingRefusal()`.
- Accepted deviations: `MonthlyRunPlanner::closePhase(Carbon $night, bool $ignorePrerequisites = false)` (CLI `--force` only); `Tests\Support\StubEngineStepCommand` replaces the file-local stub; `chainStepOutcomes(EngineRun $run)`.
