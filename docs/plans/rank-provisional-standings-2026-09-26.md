# Rank progress: live partner counts + admin view — plan (2026-09-26)

Status: BUILT 2026-09-26 on branch `feat/rank-progress-snapshot` (not merged,
not deployed). Compliance conditions 1–6 and Fable review §7 applied; diff-audit
fixes: `replayable` became `latestOnly` (later success heals an earlier failed
day; an older day never replaces a newer snapshot), O(1) Q-Period lookup, and
the note says "as of {date}" only when the Rank 3–9 partner counts come from
the snapshot (Rank 1–2 rows are live: "Progress so far this month.").
All paths are relative to `arovolife-code/app/`.

## 1. Goal

A read-only answer to "where does this distributor stand on the rank ladder
this month?", for the distributor (own data) and for admins, **without ever
writing `rank_qualifications` before the month closes** (the table's only
writer stays the monthly `rank:check-qualifications` on the 1st).

The distributor-facing progress view already exists — `RankStatusService`
(`app/Modules/Compensation/Services/RankStatusService.php`), rendered on
My Income → Rank Bonus (`resources/views/income/rank-bonus.blade.php`) and the
dashboard hero (`resources/views/dashboard/_hero.blade.php`). Two gaps remain:

1. **Ranks 3–9 partner counts read 0 all month.** `legQualifierCounts()`
   counts `rank_qualifications` rows for the CURRENT month, which only exist
   after the 1st. A distributor chasing Rank 3 sees "Rank 2 partners — Left: 0"
   all month even when partners already meet Rank 2's conditions.
2. **Admins see no live standing.** `AdminDistributorCompController::show()`
   lists recorded qualifications only.

Plus a user requirement (2026-09-26): every surface that shows a live or
provisional rank figure carries a note that **ranks can still change if orders
are cancelled or refunded later**.

### Definition of done
- On the 15th of a month, a distributor whose Left group has two members
  meeting Rank 2's conditions as of yesterday's cut-off sees
  "Rank 2 partners — Left Genos: 2 of 2", labelled as provisional, with the
  cancellation/refund note.
- An admin on the distributor's compensation page sees the same distributor's
  provisional standing for the month (highest rank whose conditions are met as
  of yesterday, plus the requirement rows).
- `rank_qualifications` row count is unchanged by anything this feature does.
- No money engine reads the new table (enforced by a test).
- Flag off → zero trace; pages behave exactly as today.

## 2. Approach

**Nightly provisional snapshot, stored separately, read by the pages.**

Evaluating ranks 3–9 needs the whole cascade (Rank 2 qualifiers → Rank 3
candidates → …) across the placement tree. That is the monthly engine's work;
doing it per page view is impossible at 10 lakh distributors (a top-of-tree
distributor's leg is most of the platform). So:

1. **Extract a pure evaluator** from `RankQualificationService::checkForMonth()`:
   `evaluateMonth(Carbon $month, int $occurrence = 1): RankEvaluation` computes
   the qualifier ID sets per rank (plus each rank-1/2 qualifier's counted
   Left/Right BV) with **no writes**. `checkForMonth()` becomes
   `evaluateMonth()` + persist (the existing `updateOrCreate` loops and
   `voidRank1CarryForwardsForRank2Qualifiers`). One measurement path, two
   consumers — the service docblock's "never re-invented" rule holds.
   - Q-Period subtlety: today `checkHigherRank()` calls `qPeriodCounts()`
     AFTER the cascade has written this month's lower-rank rows, so the current
     month counts. The evaluator must reproduce that without writing:
     `count = DB achieved rows (month_start <= M) excluding rows for
     (M, this occurrence) + (1 if in-memory qualified for r-1 this run)`.
     For the persisted path this equals today's number (the run re-upserts the
     same (M, occurrence) rows); a parity test pins it (§5).
2. **New command `rank:provisional-standings --date=<yesterday>`** calls
   `evaluateMonth(month of <date>)` and REPLACES that month's rows in a new
   table `rank_provisional_standings`
   (`distributor_id`, `month_start`, `rank_number`, `as_of_date`,
   `left_genos_bv_paise` nullable, `right_genos_bv_paise` nullable,
   timestamps; unique (`distributor_id`, `month_start`, `rank_number`);
   index (`month_start`, `rank_number`)). Replace = delete month rows + chunked
   insert inside one transaction, so a refund that drops someone below a
   threshold removes them the next night (unlike `updateOrCreate`).
   - Runs for the month containing `<date>` (yesterday), so on the 1st it
     evaluates the just-closed month one last time — harmless; the real rows
     are written by the monthly close the same morning.
   - The previous month's provisional rows are deleted once the real
     `rank.check` for that month has succeeded (they are superseded).
3. **Scheduling: its own daily entry at 00:20 IST, NOT a step of
   `compensation:nightly-run`.** A failure must never mark the money chain
   failed or block the weekly/monthly runs that wait on it. It refuses (records
   `skipped`, reason `nightly_not_done`) unless `gsb.daily-cutoff` has
   succeeded for `<date>`, so the snapshot is always "as of a settled day".
   Registered in `EngineRegistry` (label "Rank Progress Snapshot", period
   Date, `requiresClosedPeriod: true`, no downstream dependants, flag =
   the new flag below) so it appears on the Engine Runs page and in
   `engine_runs`. Registered in the module's console command list
   (see memory: unregistered commands fail silently at cron).
4. **Readers:**
   - `RankStatusService::legQualifierCounts()` — when the flag is on and the
     month is the current month, count from `rank_provisional_standings`
     instead of `rank_qualifications`, via ONE query joining
     `TeamStatsService::scopedQuery($distributor, $side)` to the snapshot
     (single-source-of-truth rule for downline counting; also removes today's
     `whereIn(<whole leg ids>)`, which does not scale). Recorded current-month
     rows (occurrence > 1, rare) are unioned in so nothing that counts today
     stops counting.
   - `RankStatus` DTO gains `?Carbon $provisionalAsOf` (null when the flag is
     off) so views can label the counts "as of <date>".
   - Admin: `AdminDistributorCompController::show()` adds a "This month so far
     (provisional)" panel: `RankStatusService::forDistributor($distributor)`
     (same requirement rows the distributor sees) + the distributor's own
     highest provisional rank from the snapshot.
5. **The note** (final copy, compliance-officer 2026-09-26):
   - Full form (Rank Bonus progress block, admin panel):
     > "Progress as of the end of {d M}. This is not a rank. Ranks are decided
     > on the 1st of next month from that month's final orders, and these
     > figures can go down if orders are cancelled or refunded."
   - Short form (dashboard hero):
     > "As of {d M}. Not yet a rank — can go down if orders are cancelled or
     > refunded."
   - Admin panel heading: "This month so far (provisional, as of {d M})".

   Shown whenever the flag is on, including for the ranks 1–2 BV rows (they
   are live figures too). No amounts or bonus names anywhere near the counts.

6. **Display split (compliance, HR3 — no implied projection):**
   - **Distributors** see requirement rows + the note only. NEVER a
     provisional rank label, "on track", "likely" or similar.
   - **Admins** additionally see "highest provisional rank", labelled
     Provisional, read-only, via existing admin RBAC on
     `AdminDistributorCompController` (no audit row needed for a read).
   - **Partner counts are counts only** — no drill-down, names, IDs or links
     to who qualifies, now or later (CoE §2.11 ¶4: a live "1 of 2" must not
     become a pointer at a named downline). Breaking them down per person
     would bring in R-65's `genealogy.downline_stats_visible` gate and its
     30-day notice; aggregate own-leg counts do not need it.
   - `RankStatusService::labelsFor()` / `labelsForMany()` (feed the Genos ID
     cards seen by sponsor/upline/admin and the ADC application's sponsor
     rank) **never read the snapshot**. `RankProvisionalStandingService::highestFor()`
     is called only from `AdminDistributorCompController`.
7. **Flag:** new `RankProgressSnapshotFeature` (default false), key
   `compensation.rank_progress_snapshot`, `'requires' =>
   ['compensation.rank_bonus']`, owner developer, in
   `AdminFeatureFlagController`. Off → command records `skipped`
   (`feature_flag_off`), readers fall back to today's behaviour, no note, no
   admin panel.

## 3. Touch list

| File | Change |
|---|---|
| `app/Modules/Compensation/Services/RankQualificationService.php` | Extract `evaluateMonth()`; `checkRanks1And2`/`checkHigherRank` return IDs without writing; `checkForMonth()` = evaluate + persist. Behaviour unchanged. |
| `app/Modules/Compensation/Services/DTOs/RankEvaluation.php` (new) | `final readonly`: `array<int,int[]> $qualifierIds` per rank, `array<int,array{left:int,right:int}> $rank12Bv`. |
| `app/Modules/Compensation/Database/Migrations/2026_09_26_120000_create_rank_provisional_standings_table.php` (new) | Table above. |
| `app/Modules/Compensation/Models/RankProvisionalStanding.php` (new) | Model, `$fillable`, `casts()`. |
| `app/Modules/Compensation/Services/RankProvisionalStandingService.php` (new) | `snapshot(Carbon $asOf)`: evaluate + replace; `purgeSuperseded()`; `highestFor(int $distributorId, Carbon $month)`; `asOf(Carbon $month)`. |
| `app/Modules/Compensation/Console/Commands/RankProvisionalStandingsCommand.php` (new) | `rank:provisional-standings --date=`; flag check; nightly-done guard; `EngineRunContext::noteSkipped()`. |
| Compensation module service provider (console command registration) | Register the command. |
| `routes/console.php` / wherever `EngineCadence` schedules are wired | Daily 00:20 IST, `withoutOverlapping()`. |
| `app/Modules/Compensation/Support/EngineRegistry.php` | New `EngineDefinition` `rank.provisional-standings`. |
| `app/Modules/Compensation/Support/DerivedTables.php` | Add `rank_provisional_standings` (reproducible from BV; recompute/reset must wipe it). |
| `app/Modules/Shared/Features/RankProgressSnapshotFeature.php` (new) | Flag class, default false. |
| `app/Modules/Admin/Http/Controllers/AdminFeatureFlagController.php` | Register flag. |
| `app/Modules/Compensation/Services/RankStatusService.php` | `legQualifierCounts()` reads snapshot (one joined query); `provisionalAsOf` on DTO. |
| `app/Modules/Compensation/Services/DTOs/RankStatus.php` | `?Carbon $provisionalAsOf`. |
| `app/Modules/Compensation/Http/Controllers/Admin/AdminDistributorCompController.php` | Provisional panel data. |
| `resources/views/income/rank-bonus.blade.php`, `resources/views/dashboard/_hero.blade.php`, `resources/views/admin/compensation/distributors/show.blade.php` | Note + "as of" label; admin panel. Lucide icons, IndianNumber, html.dark theme tokens, help-icon tooltip per UI conventions. |
| `resources/help/compensation.md` | Document the snapshot, its timing, the note, and state it is "never used for any bonus, pool, offer, announcement or termination decision". |
| `docs/compliance/risk-register.md` | New row (required before the flag goes ON): "Live provisional downline rank-condition counts" — Statutory/DPDP, Low; x-ref R-65, CoE §2.11. Controls: counts only, own subtree via `TeamStatsService::scopedQuery`, no provisional rank shown to distributors, repo-wide guard test, flag default OFF. No DSA §6.2 / DPDP §5 notice needed. |

## 4. Sequencing (each chunk leaves main shippable)

1. Evaluator extraction + parity tests (no behaviour change). → verify: full
   Compensation suite green, parity tests green.
2. Migration, model, `RankProvisionalStandingService`, command, registry,
   schedule, DerivedTables, flag. → verify: command on dev fixtures writes
   expected rows; `rank_qualifications` count unchanged; flag-off records
   `skipped`.
3. `RankStatusService` reader + DTO. → verify: unit tests for counts with and
   without snapshot, flag off = old behaviour.
4. Views + admin panel + note + help doc. → verify in browser (dev, :8084),
   light and dark theme, phone width.

## 5. Tests

- **Parity (golden):** seeded tree with rank 1/2/3/4 qualifiers across two
  months, occurrence 1 and 2, a forfeited-day distributor, a weaker-leg
  top-up case: `evaluateMonth()` sets == rows `checkForMonth()` writes; and
  `checkForMonth()` writes identical rows to the pre-refactor version
  (capture expected rows before refactor).
- **No-write guarantee:** `evaluateMonth()` and the snapshot command leave
  `rank_qualifications` and `wallet_ledger_entries` counts unchanged.
- **Refund drops a standing:** qualifier on night 1; reverse enough group BV;
  night 2 snapshot no longer lists them.
- **Architecture guard (repo-wide, compliance HR2):** scan every PHP file
  under `app/` (all modules, controllers, commands, jobs, listeners) for
  `rank_provisional_standings` and `RankProvisionalStanding`; fail on any hit
  outside the allowlist: the model, the migration,
  `RankProvisionalStandingService`, `RankProvisionalStandingsCommand`,
  `EngineRegistry`, `DerivedTables`, `RankStatusService`,
  `AdminDistributorCompController`. Positive assertion: `PurchaseOfferService`,
  `AnnouncementService`, `InactivityTerminationService` and
  `RepurchaseCycleService` still reference `rank_qualifications` /
  `RankQualification`.
- **Labels never provisional:** with a snapshot row making X "Rank 3" and no
  `rank_qualifications` row, `labelsFor()`/`labelsForMany()` return no rank for
  X; the distributor's own Rank Bonus page and dashboard render no rank name
  derived from the snapshot.
- **Guards:** flag off → `skipped/feature_flag_off`; cut-off not done →
  `skipped/nightly_not_done`; purge removes previous month only after its
  `rank.check` succeeded.
- **Reader:** partner counts from snapshot, scoped to own legs; another
  distributor's page never reads outside their subtree.
- Tests run on `arovolife_test` per `docs/local-dev-environment.md`; verify
  schema-sensitive bits on dev MySQL (SQLite hides column widths).

## 6. Risks / open questions

- **Refactor of a money-path service.** Mitigated by golden parity tests
  captured before the change; chunk 1 ships alone.
- **Compliance — RESOLVED (compliance-officer, 2026-09-26: approve with
  conditions 1–6, all folded in above).** The downline-stats gate is not
  required for aggregate counts; admin provisional rank accepted; distributors
  never see a provisional rank label. Commit trailer:
  `Compliance-Review: compliance-officer (plan rank-provisional-standings-2026-09-26, conditions 1–6)`.
- **Cost at scale.** Evaluation is the monthly engine's work every night
  (~platform-wide closure join for ranks 3–9). Benchmark with
  `ScaleBenchmarkCommand` at 10 lakh before enabling on prod; it runs outside
  the money chain so a slow run cannot delay payouts.
- **Recompute on dev/staging** wipes the table (DerivedTables); the replay
  should call the snapshot per replayed day or the pages show no provisional
  data until the next 00:20 — decide in review (default: not replayed; the
  next nightly run repopulates).
- Prod deploy: migrate + restart scheduler/queue; flag stays off until
  compliance sign-off (batched with the launch sign-offs).

## 7. Fable review (2026-09-26) — decisions, SUPERSEDE the sections above where they differ

1. **Slot 02:30 IST**, cadence `EngineCadence::dailyForPreviousDay('02:30')`,
   schedule passes an explicit `Carbon::yesterday('Asia/Kolkata')->toDateString()`
   (never the word "yesterday" — `RecordEngineRun::resolvePeriod()` regex),
   `->when($compensationEnginesMayRun)` like the other entries. Guard: the
   cut-off for `<date>` must be `succeeded`, or `skipped/feature_flag_off`
   while the GSB flag is off (mirror `RankQualificationsGate::checkedFor()`).
   Refusal returns **FAILURE** (not skipped) so `EngineHealthService` surfaces
   it; only our own flag off records `skipped/feature_flag_off`.
   `EngineRegistryTest` engine count +1.
2. **Q-Period parity (preserve today's behaviour exactly):**
   `count(r-1) = DB achieved rows (month_start <= M) excluding (M, o)
   + (inMemoryQualified[r-1] OR dbRowExists(M, o, r-1) ? 1 : 0)`.
   Snapshot path: no (M, o) rows exist → pure in-memory. Golden test includes
   the re-run-after-reversal (stale row) scenario.
3. **Replay exclusion:** `EngineDefinition` gains `replayable: bool = true`;
   `EngineReplayService::enginesDueOn()` skips `replayable: false`. The
   snapshot is `false`; the next 02:30 run repopulates after a recompute.
4. **Reader:** `TeamStatsService::scopedQuery()` is private → add public
   `scopedIdSubquery(Distributor, string): Builder` (selects `d.id`), touch
   `app/Modules/Identity/Services/TeamStatsService.php`. Command registration
   is `app/Providers/AppServiceProvider.php` (~line 171), not a module provider.
5. **"As of" is exact:** `evaluateMonth()` takes `?Carbon $through`;
   `countedGenosBvForMonth()` and the monthly personal-BV map bound by it
   (monthly path passes null → month end, unchanged).
6. **Simplify:** table keeps ONE month (each snapshot deletes all rows and
   inserts the new month in one transaction); no `purgeSuperseded()`; no BV
   columns — columns: `distributor_id`, `month_start`, `rank_number`,
   `as_of_date`, timestamps.
7. **1st of month / no snapshot yet:** `provisionalAsOf` null while flag on
   → partner counts fall back to recorded rows and the view says
   "No progress snapshot yet this month" instead of a stale date.
