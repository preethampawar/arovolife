# FINAL QA sign-off — feat/compensation-rsp-updates-2026-10 (2026-10-09, after Task 14)

- Worktree: `/Users/preetham/Documents/arovolife/arovolife/arovolife-rsp`, isolated stack (`arovolife-rsp-app` / `arovolife-rsp-db`, http://localhost:8094).
- Tests ran on `arovolife2_test`, one process at a time, with the `-e DB_*` override command.
- This sign-off replaces the earlier one (8ce3e8ba), which was written before Task 14. That version is still in git history.

**Range: `main` (26788e16) .. HEAD (f4babbe7)**
- 53 commits (`git log --oneline main..HEAD | wc -l`).
- 306 files, +13,974 / −1,656 (`git diff --shortstat main...HEAD`).
- No files deleted.
- `git log HEAD..main` is empty, so the branch contains all of `main`.

**What I re-checked.** Every claim below was re-run or re-grepped in this pass. Nothing was copied forward from the old sign-off.

**Compliance-Review trailers**
- `git log --format='%H %s%n%b' main..HEAD | grep -c Compliance-Review` → **31** of 53 commits.
- The 22 commits without the line:
  - 19 `rsp-runner` chore/security/report commits;
  - the merge e3e15f06;
  - 8ce3e8ba (the old sign-off, docs only);
  - 99c182e2 `test(engine-runs): pin that a throwing run records a single engine_runs row`.
- `git log --no-merges --invert-grep --grep='Compliance-Review: compliance-officer' main..HEAD -- app` lists only 99c182e2. It touches only `app/tests/Modules/Compensation/EngineRunRecorderTest.php` (test-only), so it is acceptable without the trailer.
- Every commit that touches money, settings, help copy or migrations carries the line.
- All 14 Task 14 money commits carry it as a trailer git can parse.
- The cosmetic issue from the old sign-off still stands: in 5 older commits a blank line separates the text from `Co-Authored-By`, so `%(trailers:key=Compliance-Review)` reads empty. Those are e4aadca6, 6efb67d1, 088e976e, fc579ad2 and 5299b259.

## 1. Test suites

**Run 1 — full four suites** (one command, log `.playwright-mcp/final-qa-fullsuite.log`):
`php artisan test --compact tests/Modules/Compensation tests/Modules/Admin tests/Modules/Commerce tests/Feature/Console`
```
FAILED  Tests\Modules\Compensation\CarryOverDisplayTest > it carry cards…
FAILED  Tests\Modules\Compensation\IncomeControllerTest > it counts a wal…
FAILED  Tests\Modules\Compensation\IncomeControllerTest > it gives the pe…
Tests:    3 failed, 2092 passed (30962 assertions)
Duration: 660.60s
```
These are exactly the 3 known main-side failures and nothing else. The assertions that fail:
- `CarryOverDisplayTest`: To contain `from-emerald-500 to-green-700`.
- `IncomeControllerTest`: To contain `Repurchase wallet not cleared at month end — not paid`.
- `IncomeControllerTest`: To contain `power (Left)`.

The pass count matches the Task 14 orchestrator's own run (2092 passed, 30962 assertions), which used the other test DB.

**Run 2:** `tests/Modules/Compensation/PlanInvariantsTest.php` alone gave `Tests: 10 passed (19992 assertions)` in 18.07s.

**Run 3:** `tests/Feature/EngineRegistryTest.php` alone gave `Tests: 1 failed, 14 passed (1374 assertions)`.
- The one failure is `EngineRegistryTest > it has exactly one registry en…`, i.e. "has exactly one registry entry per compensation console command".
- In the expected-vs-actual diff, the only entries missing from the registry list are `GsbWriteOffDeferralCommand` and `PayoutsReconcileCommand`. Every other line is the same list re-indexed.
- Both commands come from `main`:
  - `GsbWriteOffDeferralCommand`: last `main` commit 7e924224.
  - `PayoutsReconcileCommand`: last `main` commit 63aa9e3a.
- `git diff main...HEAD` on both files is empty: the branch never touched them.
- **Verdict: main-side, as the Task 14 report states.**

**Why I did not run them on `main`.** I did not run these four failures on a `main` checkout, because a checkout is a git write and out of bounds. "Main-side" rests on three facts:
- the failing tests and the code they assert on are not in the branch's hunks;
- for EngineRegistryTest, the two missing commands are untouched `main` files;
- the earlier task reports analysed the same failures the same way.

## 2. Lint, static analysis, compiled views

- **Pint `--test`.** Every non-blade PHP file the branch added or changed, i.e. `git diff --name-only --diff-filter=AMR --relative=app main...HEAD -- 'app/*.php' ':!app/*.blade.php'` piped to `pint --test`, gave `PASS … 126 files`.
- **Larastan (project-wide).** `vendor/bin/phpstan analyse --no-progress --memory-limit=1G` gave `[OK] No errors`.
  - The branch changes `app/phpstan-baseline.neon` by +677 / −60.
  - Its only added `path: app/…` entry is `app/Modules/Payments/Services/RazorpayGateway.php`, which is main-side and listed in spec doc §10.
  - Every other addition is under `tests/**`.
- **Compiled views.**
  - `view:cache` printed "Blade templates cached successfully".
  - `find storage/framework/views -name "*.php" -exec php -l {} \; | grep -vc "No syntax errors"` gave **0**, out of 491 compiled views.
  - `view:clear` afterwards: 0 files remain.

## 3. Spec numbers → pinning tests

Each test name below was grepped at HEAD (`grep -nE "^(it|test)\(…" -r tests/Modules tests/Feature`) and its asserted literals were read. All of these files ran green in Run 1 (or Run 2).

| Client figure | Test (file:line › `it`) | Asserted literals |
|---|---|---|
| Repurchase due = start + 29 | `RepurchaseCycleDueDateTest.php:14` › "opens the first cycle with due_date = anchor + 29 days (client example 14 Feb → 15 Mar)" | `toBe('2026-03-15')` (l.26) |
| Verdict on 16 Mar, not 15 Mar | `RepurchaseCycleDueDateTest.php:54` › "takes the verdict on the day AFTER the due date, never on it (F-1c)" | evaluate `2026-03-15` keeps it open (l.61–64); `2026-03-16` resolves it (l.68) |
| MSB cap ₹120 | `MsbDailyPoolServiceTest.php:209` › "caps the MSB point value at comp.msb.point_value_cap_paise (client example 1: 150 → 120)" | raw 15_000, value 12_000, cap 12_000, payout 12_000×1_000, leftover 15_000_000−12_000_000 |
| MSB 108.5383 → 108 | `MsbDailyPoolServiceTest.php:225` › "leaves a sub-cap value alone (client example 2: 108.5383 → 108)" | 1_382 points → value 10_800 = raw |
| MSB gate, failed sponsor up to rank 5 | `MentorshipBonusServiceTest.php:445` › "awards no MB points to a sponsor (rank ≤ 5) who is failed on the cut-off day, recording a gated row" | gated row, ₹0 |
| … kept out of the denominator | `GsbDailyCutoffCommandTest.php:116` › "keeps a repurchase-gated sponsor's points out of the day's MSB denominator and records them (client 2026-10-09)" | — |
| Royalty cap ₹3,600/day | `MentorshipBonusServiceTest.php:708` › "caps a failed rank-6+ sponsor at ₹3,600 across all accruals of the day, withholding the rest" | 252_000 + 108_000; withheld 144_000; cap 360_000; Σ 360_000 |
| … order-independent | `MentorshipBonusServiceTest.php:742` › "settles the cap to the same day total whichever sponsee is credited first (F-5)"; `PlanInvariantsTest.php:312` (both orders) | Σ 360_000 |
| GBB 4 %, ₹240 cap | `GrowthBoosterBonusServiceTest.php:188` › "caps the GBB point value at ₹240 (client example 1: 320 → 240)" | "50L BV × 4% = 2,00,000; 625 AGP"; raw 32_000 → value 24_000 |
| GBB lifetime exclusion | `GrowthBoosterBonusServiceTest.php:533` › "excludes a distributor ranked in M-2 even with no rank in M-1 (lifetime rule)"; `:548` › "pays GBB in the month a distributor first reaches a rank, but never afterwards" | — |
| GBB verdict gate | `GrowthBoosterBonusServiceTest.php:1123` › "blocks a distributor who is failed on the last day of the month (A-G1) and keeps them out of the denominator" | — |
| RAP 72/189/468/1,125/2,583/5,688/11,934/23,877/39,501, AGO 36, cap ₹200, pass 1 = ranks ≤3 | `CompensationPlanSettingsServiceTest.php:99` › "exposes RAP points for every rank per the 05-10-2026 Rank Income Point System" | `[72, 189, 468, 1125, 2583, 5688, 11934, 23877, 39501]`, `aogoPointsPerGrant()` 36, cap 20_000, first pass 3, `rankPoolPct` gone |
| Two passes, A2 (₹188) | `RankBonusServiceTest.php:1337` › "example A2: AGO + Rank 1 share the whole pool at the floored value (188)"; `PlanInvariantsTest.php:355` | 1_008 pts, 18_800, R1 72×18_800 |
| C1 (capped ₹200) | `RankBonusServiceTest.php:1356` › "example C1: pass 1 is capped at ₹200 when the raw value exceeds it"; `PlanInvariantsTest.php:370` | raw 21_700 → 20_000; R3 468×20_000 |
| D2 (pass 2 ₹186) | `RankBonusServiceTest.php:1374` › "example D2: ranks 4–9 share the remainder at the floored value (186)"; `PlanInvariantsTest.php:387` | pass-1 payout 115_920_000; pass-2 pool 3_200_000_000−115_920_000; 165_474 pts; 18_600; R8 23_877×18_600 |
| Pass identities over random cohorts | `PlanInvariantsTest.php:413` › "F-9: holds every pass identity over 50 random cohorts and turnovers (property draw, seed 20261009)" | — |
| Award tranche table, R9 6,87,47,400 | `LifetimeAwardCatalogTest.php:26` › "pins the client's whole 2026-10-09 tranche table, in paise"; `:16` › "exposes the 2026-10-09 award tranches and budgets via the SSOT" | rank 3 `[4_860_000, 5_940_000]`; rank 9 `[918_630_000, 956_070_000, 5_000_040_000]`; budget 6_874_740_000 |
| Release on the n-th qualification | `RankBonusServiceTest.php:833` › "opens award tranche A on the first qualification and tranche B on the second (rank 3)"; `:861` › "releases a tranche once the rank has been qualified at least tranche times"; `AdminLifetimeAwardsTest.php:79` › "refuses to deliver a tranche the rank has not been qualified for enough times" | — |
| Task 14: Rank cap must be a whole rupee | `RankBonusServiceTest.php:1454` › "refuses a point value cap that is not a whole rupee before any write (fail-safe principle 1)"; `CompensationPlanSettingsServiceTest.php:109` › "refuses a Rank point value cap below ₹1 or not a whole rupee instead of clamping it (F-6)"; `tests/Modules/Admin/AdminSettingsWholeRupeeCapTest.php` (18 cases, in Run 1) | — |

Line numbers moved after Task 14 (the RankBonusServiceTest tranche cases are now at 833/861, the examples at 1337/1356/1374). The test names are the stable reference.

## 4. Fail-safe surface (file:line)

All paths are under `app/app/Modules/`.

**Cap and setting accessors.** All of these throw `RuntimeException`; none clamps. The service is `Compensation/Services/CompensationPlanSettingsService.php`.

| Guard | Location |
|---|---|
| `msbPointValueCapPaise()` < 100 / not whole rupee | `:293`, throws `:296-297`, `:299-300` |
| `msbRoyaltyMinRank()` outside 1–9 | `:318`, throw `:321-322` |
| `msbRoyaltyFailedDailyCapPaise()` < 100 | `:340`, throw `:343-344` |
| `gbbPointValueCapPaise()` < 100 / not whole rupee | `:377`, throws `:380-381`, `:383-384` |
| **`rankPointValueCapPaise()`** < 100 / not whole rupee (Task 14) | `:686`, throws `:689-690`, `:692-693` |
| **`rankFirstPassMaxRank()`** outside 1–9, clamp removed (Task 14) | `:709`, throw `:712-713` |

**Settings Save check (Task 14).**
- `Admin/Http/Controllers/AdminSettingsController.php` sets `'multiple_of' => 100` on four keys at `:1284`, `:1308`, `:1331`, `:1375`.
- The check is enforced at `:1964` (`$multipleOf = $meta['multiple_of'] ?? null`).
- It was verified live in the browser (§6).

**Rank freeze** (`Compensation/Services/RankBonusService.php`).
- `assertFreezable()` at `:295` reads both accessors (`:297-298`) first. It then checks for RAP ≤ 0 with payable achievers (`:302`) and for missing tranches. Finally it calls `unresolvedDueOnOrBefore` (`:309`) and throws `RepurchaseVerdictsPending` (`:312`).
- `assertFreezable()` is called at `:161`, before `replacePrematureFreeze()` at `:164`, and again at `:429` inside `freezeMonth()` (`:422`).
- The envelope is `max(0, intdiv($turnoverPaise * $envelopeBp, 10_000))` at `:439`.
- `reconcileFreeze(..., $writtenIds)` is called at `:567` and defined at `:598`.
- `reportStrayPendingRows()` is at `:644`. It logs **`rank.freeze.stray_pending_rows`** at `:657` and writes the audit row at `:660`.
- `pricedByFreeze()` is at `:677` and `isStrayPendingRow()` at `:690`.
- **`rank.credit.stray_pending_row_skipped`** is logged at `:1113`.
- `monthHasCreditedResults()` is at `:948`.
- A missing tranche throws at `:1222` and `:1252`.
- The audit action `awards.tranche.unearned_pending_removed` is at `:1297`.

**GBB** (`Compensation/Services/GrowthBoosterBonusService.php`): cap read at `:136`; `unresolvedDueOnOrBefore` at `:250`; `intdiv` envelope at `:280`; `gbb.result.excluded_from_frozen_denominator` at `:757` (log) and `:760` (audit).

**MSB / Mentorship**
- `Compensation/Services/MsbDailyPoolService.php`: cap at `:84`, `Money::floorRupee` at `:96`.
- `Compensation/Services/MentorshipBonusService.php`:
  - `sponsorVerdictStale()` at `:167`;
  - `where('cutoff_date', …)` at `:173`, `:179`, `:411`, `:598`;
  - `msb.royalty.cap_withheld` at `:422`;
  - `msb.credit.repurchase_gated` at `:517`.

**Verdict prerequisite**
- `Compensation/Services/IncomeEligibilityService.php:162` `unresolvedDueOnOrBefore()`.
- **`OpenMonthGuard::verdictsPendingRefusal()`** at `Compensation/Support/OpenMonthGuard.php:96`.
- **`RankQualificationsGate::monthsMissingCheck()`** at `Compensation/Support/RankQualificationsGate.php:152`. It logs **`rank.check.prerequisite_waived`** at `:179`.
- `rank.report.first_pass_max_rank_invalid` is logged at `Compensation/Http/Controllers/Admin/AdminRankBonusInputOutputController.php:373`.

**Migrations** (`Compensation/Database/Migrations/2026_10_09_*`). Each writes one `plan.migration.*` action.
- The 13 actions:
  - 100000 `:33`
  - 100300 `:28`
  - 100500 `:31`
  - 100600 `:22`
  - 100700 `:32`
  - 100800 `:29`
  - 100900 `:37`
  - 100950 `:25`
  - 101000 `:24`
  - 101100 `:26`
  - 101200 `:30`
  - **101300 `:27` `plan.migration.narrow_awards_credit_wallet_type`**
  - **101400 `:32` `plan.migration.narrow_rank_repurchase_held_status`**
- 101300 and 101400 follow the order count → refuse ("Replay or wipe history first.") → MySQL `ENUM` narrow → `AuditLog::create`:
  - 101300: count `:33`, throw `:36`, DDL `:40`, audit `:45`.
  - 101400: refusal `:41`, DDL `:45`.
- I compared 101300's enum list with the latest widening, `2026_09_05_120000`. It is that list minus `awards_credit`; no later widening of `wallet_ledger_entries.type` exists in the repo.
- I compared 101400's list with `2026_09_05_100002`. It is identical (`pending, credited, reversed, requalification_held, repurchase_wallet_blocked`).

**Data migrations' `down()`**
- These throw "restore from the … audit row": 100000 `:114`, 100300 `:84`, 100500 `:82`, 100600 `:86`, 100800 `:95`, 100900 `:144`, 100950 `:165`, 101100 `:69`.
- These are schema-only: 100700 (`:100`, re-adds `pool_pct` NULL) and 101000 (`:65`, re-creates the cash columns empty). This deviates from the rule that `down()` must restore or throw; see §9.
- 101200, 101300 and 101400 `down()` widen the enum back and move no row.

## 5. Stack DB sanity (read-only, `arovolife` on `arovolife-rsp-db`)

**Migrations:** `php artisan migrate:status --pending` printed `INFO  No pending migrations.`

**Settings rows present** (the store is `settings`):
- `comp.gbb.pool_rate_bp = 400`
- `comp.rank.first_pass_max_rank = 3`
- `comp.rank.point_value_cap_paise = 20000`

**Absent as intended:** `comp.gbb.agp_cap`, `comp.admin_charge.applies_to_awards`.

**Keys with no override row.** The following fall back to `SCALAR_DEFAULTS` (`CompensationPlanSettingsService.php` l.91–136). The settings page shows exactly these values (§6).

| Key | Default |
|---|---|
| `comp.msb.point_value_cap_paise` | 12_000 |
| `comp.msb.royalty_min_rank` | 6 |
| `comp.msb.royalty_failed_daily_cap_paise` | 360_000 |
| `comp.gbb.point_value_cap_paise` | 24_000 |
| `comp.rank.aogo_points` | 36 |
| `comp.rank.envelope_bp` | 2_000 |
| `comp.repurchase.cycle_days` | 30 |

**Enums (`SHOW COLUMNS`)**

| Column | Values |
|---|---|
| `gsb_cutoff_results.status` | `no_match, calculated, credited, failed, frozen, below_600bv, reversed, repurchase_forfeited` |
| `gbb_monthly_results.status` | `pending, credited, reversed, repurchase_wallet_blocked, repurchase_failed_blocked` |
| `mentorship_bonus_results.status` | `credited, failed, repurchase_gated` |
| `rank_bonus_results.status` | `pending, credited, reversed, requalification_held, repurchase_wallet_blocked` (no `repurchase_held`, Task 14) |
| `wallet_ledger_entries.type` | 16 values, **no `awards_credit`** (Task 14) |
| `fortune_bonus_results.status` | `pending, credited, skipped, repurchase_wallet_blocked, repurchase_held`; still carries `repurchase_held` with 0 rows (§9) |

**Plan tables**
- `rank_tiers.rap_points` = 72 / 189 / 468 / 1125 / 2583 / 5688 / 11934 / 23877 / 39501.
- `lifetime_award_tranches`: **20 rows**. The per-rank Σ `amount_paise` equals `rank_tiers.lifetime_award_budget_paise` for ranks 1–9 (`ok = 1` on all nine; rank 9 = 6,874,740,000).
- `rank_monthly_passes`: **0 rows**. No two-pass month has been frozen on this stack (§9).

**Audit rows.** The 13 `plan.migration.*` actions are present once each:
- ids 8484, 8488, 8490–8498 (02:36 → 10:05);
- **8499 `narrow_awards_credit_wallet_type`** and **8500 `narrow_rank_repurchase_held_status`** (12:30:57).

**Stack-data note (unchanged).** `mentorship_bonus_results` ids 119 and 120 (cut-off 2026-10-03) are hand-inserted display fixtures (see the Task 3 and Task 4 reports), not engine output.

## 6. Pages checked

**Method**
- One Playwright pass with the existing developer session, over the deduplicated URLs from the 14 reports' UI tables, plus the extra pages the brief asked for.
- For each page I checked:
  - the HTTP status from `page.goto`;
  - the body text for `ErrorException|Whoops|SQLSTATE|Undefined variable|Undefined array key|Server Error|RuntimeException`;
  - `console`/`pageerror` listeners;
  - a western-grouping regex `\d{1,3},\d{3},\d{3}`;
  - one **viewport** screenshot into `docs/plans/rsp-reports/screens/final/<name>.png`, which overwrites the old sign-off's full-page captures.
- A second pass checked content with `textContent` plus input values and titles.
- `laravel.log` has no ERROR/CRITICAL/WARNING entry outside `testing.*` after 13:41 IST, and my pass ran ~13:58–14:16 IST. The 13:28/13:29 `getaddrinfo for db` errors are from the earlier DB-container OOM, and the 13:41 `--columns` error is someone's CLI typo. All three predate this run.

**Result:** every row below returned HTTP 200, no exception text, 0 console errors and 0 western-grouped numbers.

| Page | URL | Content verified | Screenshot |
|---|---|---|---|
| Settings → compensation plan | `/admin/settings?group=compensation_plan` | forms for all 9 plan keys with the expected values/limits (MSB cap 12000 min 100 max 100000000; royalty rank 6 [1–9]; royalty cap 360000; GBB cap 24000; Rank cap 20000; first pass 3 min **1** max 9; cycle days 30; AGO 36; GBB rate 400); no form for `agp_cap` / `applies_to_awards` | `settings-compensation-plan.png` |
| … **24050 submitted on `comp.msb.point_value_cap_paise`** | POST `/admin/settings/comp.msb.point_value_cap_paise` (input enabled via `evaluate`) | refused: "Enter a whole-rupee amount (a multiple of 100 paise)."; input back at 12000; DB before/after: 0 override rows, `MAX(audit_log.id)` 8500 → 8500, `MAX(settings.updated_at)` unchanged | `settings-msb-cap-24050-refused.png` |
| Plan settings | `/admin/compensation/plan-settings` | "one 20% pool, two passes", "48,600", "59,400", "currently 4%", "currently ₹240"; no "default ₹240" | `plan-settings.png` |
| Plan settings → ranks | `…/plan-settings?tab=ranks` | renders | `plan-settings-ranks.png` |
| GBB index | `/admin/compensation/gbb` | "capped at ₹240", "never held a rank", "4%" | `gbb-index.png` |
| GBB month Jul / Aug / Oct | `/admin/compensation/gbb/2026-07`, `/2026-08`, `/2026-10` | render | `gbb-month-2026-07/08/10.png` |
| GBB I&O | `/admin/compensation/gbb-input-output` | "Raw point value", "Cap", "₹240.00" (Task 14 columns) | `gbb-input-output.png` |
| GBB I&O Jul / Oct | `…?month=2026-07`, `…?month=2026-10` | Oct: "GBB pool (4%)", "₹240.00" | `gbb-input-output-2026-07/10.png` |
| GBB calculation (+ Jul, Oct, status filter) | `/admin/compensation/gbb-calculation[?month=2026-07 / 2026-10 / status=repurchase_failed_blocked]` | "Repurchase condition failed" | `gbb-calculation*.png` (4) |
| Rank bonus index | `/admin/compensation/rank-bonus` | renders | `rank-bonus-index.png` |
| Rank bonus month Aug | `/admin/compensation/rank-bonus/2026-08` | renders | `rank-bonus-2026-08.png` |
| **RB I&O** | `/admin/compensation/rb-input-output` | **"September 2026" listed** with the legacy label "priced under the per-rank pool rule…"; 0 × "leftover ₹0.00" | `rb-input-output.png` |
| RB I&O Aug | `…?month=2026-08` | renders | `rb-input-output-2026-08.png` |
| RB calculation (unfiltered) | `/admin/compensation/rb-calculation` | "July 2026", "August 2026" headers, "per-rank pool rule" | `rb-calculation.png` |
| RB calculation Aug | `…?month=2026-08` | renders | `rb-calculation-2026-08.png` |
| Engine runs | `/admin/compensation/engine-runs` | renders (nothing triggered) | `engine-runs.png` |
| MSB I&O | `/admin/compensation/msb-input-output` | renders | `msb-input-output.png` |
| MSB I&O 3 Oct (fixture day) | `…?from=2026-10-03&to=2026-10-03` | "Royalty cap withheld", "₹120.00" | `msb-input-output-2026-10-03.png` |
| MSB calculation 15 Aug / gated / credited | `/admin/compensation/msb-calculation?…` | gated filter: "Repurchase gated" | `msb-calculation-*.png` (3) |
| Daily cut-offs | `/admin/compensation/daily-cutoffs` | renders. The brief's `/daily-cutoff` (singular) returns 404 because no such route exists; the route is `daily-cutoffs`. My 404 capture `daily-cutoff.png` was emptied to 0 bytes (`rm` is denied); delete it by hand | `daily-cutoffs.png` |
| GSB calculation | `/admin/compensation/gsb-calculation` | renders | `gsb-calculation.png` |
| AW&RW calculation | `/admin/compensation/aw-rw-calculation` | "merchandise only, never cash", "Tranche" | `aw-rw-calculation.png` |
| Lifetime awards | `/admin/lifetime-awards` | "Tranche", "merchandise only, never cash" | `lifetime-awards.png` |
| Lifetime awards catalogue | `/admin/lifetime-awards/catalog` | "6,87,47,400", "1,08,000" | `lifetime-awards-catalog.png` |
| Distributor 1 → MB | `/admin/compensation/distributors/1?tab=mb` | "Repurchase gated", "withheld by the daily royalty cap" | `distributor-1-mb.png` |
| Distributor 24 → repurchase | `/admin/compensation/distributors/24?tab=repurchase` | "30-day repurchase window, inclusive of its first day" | `distributor-24-repurchase.png` |
| Distributor 246 → rank bonus | `/admin/compensation/distributors/246?tab=rank-bonus` | renders | `distributor-246-rank-bonus.png` |
| TDS report | `/admin/reports/profit/tds` | renders | `reports-tds.png` |
| Help → Compensation | `/admin/help/compensation` | "Window length", "capped at ₹120", "Mentorship Royalty daily cap", "never held a rank", "6,87,47,400", "merchandise only, never cash", "whole-rupee", "multiple of 100", "stray"; the retired sentence "fills in only the prior month" is **gone** | `help-compensation.png` (viewport) |
| Help → Glossary | `/admin/help/glossary` | "no per-distributor cap", "39,501" | `help-glossary.png` |
| Help → Payout operations | `/admin/help/payout-operations` | renders | `help-payout-operations.png` |

Console log for the session: `.playwright-mcp/console-2026-10-09T08-32-14-596Z.log`. Its only error is the 404 on the non-existent `/admin/compensation/daily-cutoff`.

**Distributor pages: not checked in the browser.**
- The pages are `/dashboard`, `/income/mentorship`, `/income/growth-booster` and `/income/rank-bonus`.
- No distributor password was provided. The Task 1 report reset distributor 24's password via tinker, which is denied here.
- I did not use `admin/impersonate/{userId}/start`: it is a write, and it would replace the developer session.
- These blades are covered by HTTP tests that passed in Run 1: `IncomeControllerTest` (except the 2 known main-side cases), `RepurchaseCycleCardTest` and `RepurchaseCycleDueDateTest`.

## 7. Deploy record review (spec doc `docs/compensation/rsp-new-updates-2026-10-09.md` §8)

**What still matches the branch**
- Step 2 now says not to run `app:deploy` yet, because it runs `migrate --force` and `ProductionSeeder` itself.
- Step 3 gives the linear dev/staging order: migrate → evaluate → seeders → replay → migrate again → `migrate:status --pending`.
- Step 5 uses three separate `db:seed --class` calls, with the warning that a repeated `--class` keeps only the last one.
- Step 7 is `app:deploy --skip-migrate --skip-seed`.
- Step 8 has the nvm Node v24 note and `/usr/bin/php8.4`.
- §9 SQL is unchanged and its columns exist. The `awards_credit` filter is not in the §9.1 type list, so the narrowed enum does not break it.

**Stale against the branch** (documentation only; not a code defect):
1. **§8 step 3 never mentions 101300 / 101400.**
   - The list of refusing migrations names only 101100 and 101200, and the second migrate says "(101100/101200 now apply)".
   - On dev/staging the second `migrate --force` will apply 101300/101400 as well, because `migrate` runs every pending migration.
   - They refuse only if an `awards_credit` / `repurchase_held` row survives. The replay truncates `wallet_ledger_entries` wholesale (`Support/DerivedTables.php:58`), so that should not happen.
   - On **production** the text says "`migrate` runs through in one go". There is a gap here: **101300 refuses on any `awards_credit` row, swept or not**, while 101100 refuses only on unswept ones. A production database that ever paid an award in cash would therefore stop at 101300. The `migrate:status --pending` check in step 7 would catch it, but the record should say so.
   - The Task 14 report says "the two new migrations run after the deploy checklist's replay step"; the checklist itself does not.
2. **§4 (lines 118–120)** still says "`wallet_ledger_entries.type` still lists `awards_credit` … narrowing that enum is a separate decision". Task 14 narrowed it.
3. **§6 first row (line 149)** is wrong on two counts:
   - It says `rankPointValueCapPaise()` "returns the raw value", that `assertFreezable()` "throws for < 100 only (a non-whole-rupee rank cap is not refused)", and that "the Save form does not yet check whole rupees". All three are false since eef29b63 (§4 above, and the live refusal in §6).
   - The §6 audit-action list (lines 157–165) and the migrations row (line 155) lack `plan.migration.narrow_awards_credit_wallet_type`, `plan.migration.narrow_rank_repurchase_held_status`, `rank.freeze.stray_pending_rows` and the 101300/101400 refusals.
4. **§5 table** has no Task 14 row. §10 does carry the full Task 14 record, and the header says "Tasks 1–14".

§9 staging procedures are still **not executed**. They are deploy-time work, not done.

## 8. Branch hygiene

- **Plan checkboxes:** `git show HEAD:docs/plans/compensation-rsp-updates-2026-10-09.md | grep -c "^\s*- \[ \]"` → **0**; 87 boxes are ticked.
- **Runner state:** `scripts/rsp-runner/state.json` has `"completed": true`, and every entry of `order` (1, 2, 3, 4, 5, 6, 7, 8+9, 10, 11, 13, 12, 14) is in `done`.
- **No `.env` changes:** `git diff --name-only main...HEAD | grep -E '(^|/)\.env|^docker/'` matches only `docker/docker-compose.rsp.yml`.
- **Docker:**
  - That file is **new**, not a change to an existing file. It is the isolated stack (+159 lines, added in d13adcfe / 540e8fdd).
  - Its ports are bound to 127.0.0.1 only: 8094, 3317, 6389, 1037, 8037.
  - Merging brings it into `main`, together with `scripts/rsp-runner/` (7 files, +813). That is a choice for the user (§9).
- **Untracked leftovers after my run (`git status --short`):**
  - `app/tests/Modules/Compensation/_tmp_unused.txt` — 0 bytes, an implementer's accident;
  - `docs/plans/rsp-reports/screens/task-14/admin-help-compensation.png` and `.jpg` — 0 bytes, emptied oversized captures;
  - `docs/plans/rsp-reports/screens/final/daily-cutoff.png` — 0 bytes, mine (see §6);
  - my new screenshots: `daily-cutoffs.png`, `msb-input-output.png`, `rank-bonus-index.png`, `rb-calculation.png`, `rb-input-output.png`, `settings-msb-cap-24050-refused.png`;
  - plus 33 modified tracked screenshots in `screens/final/`, now viewport captures.
- Delete the four 0-byte files by hand before committing.

## 9. Open items the user must know before merging

1. **Four main-side test failures** (fix on `main`, not on this branch):
   - `CarryOverDisplayTest` "carry cards…";
   - `IncomeControllerTest` "counts a wal…" and "gives the pe…";
   - `tests/Feature/EngineRegistryTest` "has exactly one registry entry per compensation console command". `GsbWriteOffDeferralCommand` and `PayoutsReconcileCommand` are neither registered nor exempted.

   Also main-side: `RazorpayGateway.php:131`, which is baselined rather than fixed.
2. **The Larastan baseline was regenerated on this branch** (+677 / −60; test noise plus the Razorpay entry). Expect a conflict if `main` has touched `phpstan-baseline.neon`; regenerate it after the merge rather than hand-merging.
3. **Migration `down()` deviations:** 100700 and 101000 are schema-only. A rollback leaves NULL columns rather than refusing. 101200/101300/101400 `down()` only widen enums back.
4. **The spec doc's deploy record is partly stale (§7 items 1–4).** Update §4, §6 and §8 step 3 so they name 101300/101400 and the whole-rupee checks before anyone deploys. In particular, add the production note that 101300 refuses on *any* `awards_credit` row.
5. **Client questions still open:**
   - assumptions A-G1 (GBB verdict gate reading), A-M1 (rank as-of the cut-off), A-M2 (royalty cap per day, withheld permanently), A-R1 (AGO stays a pass-1 participant), A-A1 (tranche n on the n-th qualification);
   - the award merchandise list (a placeholder catalogue is seeded);
   - the Lifetime Awards funding source (R-114, open);
   - the "Mentorship Royalty" naming in distributor-facing copy, which is deliberately not in this plan.
6. **Not narrowed:** `fortune_bonus_results.status` still carries `repurchase_held` (0 rows on this stack; nothing writes it).
7. **No real two-pass month exists on any environment** (`rank_monthly_passes` = 0 rows here). Two-pass pricing is pinned only by tests (A2/C1/D2, the 50-draw property test, and the HTTP tests). The first real 1st-of-month freeze must be checked per spec §8 step 9. The §9 staging reconciliation is still to be done at deploy.
8. **Task 14 deviations recorded in spec doc §10:**
   - 6.2 is pin-only (the "double row" did not reproduce);
   - the Rank cap message says "refusing to price the Rank Bonus" (no month is available to the accessor);
   - the Mentorship deferral set loads at the first accrual, not inside `warmSponsorsFor()`;
   - a pools-only legacy month has no strip on the rb-calculation page; it is listed on RB I&O, as seen above for September 2026;
   - the settings input keeps `step="1"`, so the browser does not pre-block 24050 and the server refuses it, as verified;
   - `EngineReplayService::unscheduledPrerequisites()` reads only `shift`, and its docblock still names the prev-month shift.
9. **Infra files land on `main` with the merge:** `docker/docker-compose.rsp.yml` and `scripts/rsp-runner/`. Keep them, or drop them in a follow-up commit, as you prefer.
10. **Distributor pages were not driven in a browser in this pass** (§6). They are covered by passing HTTP tests.
11. **Stack fixtures** `mentorship_bonus_results` 119/120 (2026-10-03) are hand-inserted display data. They do not matter for the merge.

**Summary.** None of the items above is a defect in the branch's engine code:
- The full four-suite run shows only the 3 known main-side failures. EngineRegistryTest fails only on two untouched `main` commands. The invariants file passes.
- Pint, Larastan and the compiled-view lint are clean.
- Every client figure has a named, passing pinning test.
- Every guard and audit action, including Task 14's, is present at the file:line given.
- The stack DB matches the plan, with both Task 14 enums narrowed and both audit rows written.
- Every listed admin page renders cleanly, and the whole-rupee Save refusal works live.

The doc staleness in §7 should be fixed before the first deploy. It does not block the merge.

QA VERDICT: APPROVED
