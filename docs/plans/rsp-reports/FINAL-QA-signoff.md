# FINAL QA sign-off — R.S.P. compensation updates (2026-10-09)

- Branch: `feat/compensation-rsp-updates-2026-10`, worktree `/Users/preetham/Documents/arovolife/arovolife/arovolife-rsp`
- Range: `main` (26788e16) .. HEAD (b62f9098): 36 commits, 242 files, +11,314 / −1,605. `git log HEAD..main` is empty, so the branch contains all of `main`.
- Date: 2026-10-09. Run by the independent QA agent on the isolated stack (`arovolife-rsp-app` / `arovolife-rsp-db`, http://localhost:8094). Tests ran one process at a time with the `arovolife_test` override command.
- HEAD moved during the run: 5299b259 → b62f9098 (the orchestrator's "Task 12 approved" report commit, which also committed the plan's Task 12 ticks). Every check below was run against the working tree at b62f9098, or against code that commit did not change.

## 1. Test suites (summary lines, the 3 known main-side failures named)

**Run 1a:** `php artisan test --compact tests/Modules/Compensation tests/Modules/Commerce tests/Modules/Admin`
```
FAILED  Tests\Modules\Compensation\CarryOverDisplayTest > it carry cards…
FAILED  Tests\Modules\Compensation\IncomeControllerTest > it counts a wal…
FAILED  Tests\Modules\Compensation\IncomeControllerTest > it gives the pe…
Tests:    3 failed, 1998 passed (30459 assertions)
Duration: 511.88s
```
These are exactly the three known failures and nothing else. Their assertions:
- `CarryOverDisplayTest`: the source is expected to contain `from-emerald-500 to-green-700`.
- `IncomeControllerTest`: the page is expected to contain `Repurchase wallet not cleared at month end — not paid`.
- `IncomeControllerTest`: the page is expected to contain `power (Left)`.

The branch did not touch them. `CarryOverDisplayTest.php` is not in the branch diff. The `IncomeControllerTest.php` hunks are a status rename at line 947 (`repurchase_held` → `failed`) and two added tests after line 1279; the two failing tests are not in any hunk. I did not run these tests on `main` myself (that would need a checkout, which is out of bounds). "Fails on main" rests on the Task 1 report's analysis and on the fact that this branch contains `main` unchanged for those lines.

**Run 1b:** `php artisan test --compact tests/Feature/Console tests/Feature/Compliance tests/Feature/Compensation`
```
Tests:    1 skipped, 186 passed (2427 assertions)
Duration: 65.44s
```

**Run 1c:** `tests/Modules/Compensation/PlanInvariantsTest.php` on its own: `Tests: 10 passed (19992 assertions)`, 21.31s. The 10 cases are F-5 in both orders, F-9 A2 / C1 / D2, F-9 over 50 random cohorts, the F-10 atomic freeze, the Task 7 `total_agp` identity (2 cases) and the Task 5 MSB day identity.

**Run 1d (hard rule 2):** `tests/Feature/Compliance/CommissionHasProductSaleTest.php` on its own: `Tests: 8 passed (23 assertions)`. It is also inside run 1b.

## 2. Lint, static analysis, compiled views

- **Pint `--test`** on every changed PHP path (the explicit migration, command, controller and seeder files, plus the directories `app/Modules/Compensation/{Http,Models,Services,Support}`, `database/seeders`, `tests/Modules/Compensation` and the individual test files): `FAIL … 363 files, 2 style issues`. The two issues are `tests/Modules/Compensation/CarryOverDisplayTest.php` and `tests/Modules/Compensation/RankBonusStylingTest.php` (`blank_line_after_opening_tag`). **Neither is in the branch diff**; they were included only because I passed the whole `tests/Modules/Compensation` directory, so this is main-side. Every file the branch changed passes.
- **Larastan** (project-wide, level 7): `vendor/bin/phpstan analyse --no-progress --memory-limit=1G` → `[OK] No errors`.
  - The branch regenerated `phpstan-baseline.neon` (+641/−54). Its only new `app/` entry is `app/Modules/Payments/Services/RazorpayGateway.php` (undefined `$adn`, main-side, listed in spec doc §10).
  - Every other added entry is Pest dynamic-method noise in test files.
  - No changed `app/Modules/Compensation` file was moved into the baseline.
- **Compiled views:**
  - `php artisan view:clear` → `view:cache` → "Blade templates cached successfully".
  - `find storage/framework/views -name "*.php" -exec php -l {} \; | grep -v "No syntax errors"` → 0 lines out of 491 compiled views.
  - `view:clear` afterwards.

## 3. Spec numbers → pinning tests (table)

Each test below was found by grep at HEAD and its body was read; the numbers quoted are the asserted literals.

| Client figure | Test (file › `it`) | Asserted |
|---|---|---|
| Repurchase due = start + 29 (14 Feb → 15 Mar) | `tests/Modules/Compensation/RepurchaseCycleDueDateTest.php:14` › "opens the first cycle with due_date = anchor + 29 days (client example 14 Feb → 15 Mar)" | `toBe('2026-03-15')` (l.26) |
| Verdict on 16 Mar, not 15 Mar | same file `:54` › "takes the verdict on the day AFTER the due date, never on it (F-1c)" | evaluate `2026-03-15` keeps it active (l.61–64), evaluate `2026-03-16` resolves it (l.68) |
| MSB cap ₹120 (150 → 120) | `MsbDailyPoolServiceTest.php:209` › "caps the MSB point value … (client example 1: 150 → 120)" | raw 15_000, value 12_000, cap 12_000, payout 12_000×1_000, leftover 3_000_000 |
| MSB 108.5383 → 108 | `MsbDailyPoolServiceTest.php:225` › "leaves a sub-cap value alone (client example 2: 108.5383 → 108)" | 1_382 points → value 10_800 = raw |
| Failed sponsor ≤ rank 5 gated (gated row) | `MentorshipBonusServiceTest.php:445` › "awards no MB points to a sponsor (rank ≤ 5) who is failed on the cut-off day, recording a gated row" | reserved points 0; row `repurchase_gated`, 21 pts, gross 0; audit `msb.credit.repurchase_gated` |
| … out of the denominator | `GsbDailyCutoffCommandTest.php:116` › "keeps a repurchase-gated sponsor's points out of the day's MSB denominator and records them (client 2026-10-09)" | per Task 3 report: `total_points = 21` with one eligible + one gated |
| Royalty cap ₹3,600 (252,000 + 108,000 = 360,000) | `MentorshipBonusServiceTest.php:708` › "caps a failed rank-6+ sponsor at ₹3,600 across all accruals of the day, withholding the rest" | 252_000 / 108_000, withheld 144_000, cap 360_000, ledger Σ 360_000 |
| … both orders | `MentorshipBonusServiceTest.php:742` › "settles the cap to the same day total whichever sponsee is credited first (F-5)"; also `PlanInvariantsTest.php:312` (2 orders) | Σ gross 360_000, Σ withheld 144_000 |
| GBB 4 % + ₹240 cap (625 AGP: 320 → 240) | `GrowthBoosterBonusServiceTest.php:188` › "caps the GBB point value at ₹240 (client example 1: 320 → 240)" | `pool_rate_bp` 400, pool 20_000_000, `total_agp` 625, raw 32_000, value 24_000 |
| GBB lifetime exclusion (M-2) | `GrowthBoosterBonusServiceTest.php:533` › "excludes a distributor ranked in M-2 even with no rank in M-1 (lifetime rule)" | credited 0, total_agp 0, no row |
| … first-rank month paid, then never | `GrowthBoosterBonusServiceTest.php:548` › "pays GBB in the month a distributor first reaches a rank, but never afterwards" | July `credited`, September no row |
| GBB month-end verdict gate (blocked out of `total_agp`) | `GrowthBoosterBonusServiceTest.php:1123` › "blocks a distributor who is failed on the last day of the month (A-G1) and keeps them out of the denominator" | `total_agp` 12 (of 24), status `repurchase_failed_blocked`, gross 0 |
| RAP 72/189/468/1,125/2,583/5,688/11,934/23,877/39,501, AGO 36, cap ₹200 | `CompensationPlanSettingsServiceTest.php:99` › "exposes RAP points for every rank per the 05-10-2026 Rank Income Point System" | `[72, 189, 468, 1125, 2583, 5688, 11934, 23877, 39501]`, AGO 36, cap 20_000, first pass 3 |
| Rank A2 (₹188) | `RankBonusServiceTest.php:1146` › "example A2: AGO + Rank 1 share the whole pool at the floored value (188)"; `PlanInvariantsTest.php:355` | 1_008 points, value 18_800, R1 gross 72×18_800 |
| Rank C1 (capped ₹200) | `RankBonusServiceTest.php:1165` › "example C1: pass 1 is capped at ₹200 …"; `PlanInvariantsTest.php:370` | raw 21_700 → 20_000; R3 468×20_000 |
| Rank D2 (pass 2 ₹186) | `RankBonusServiceTest.php:1183` › "example D2: ranks 4–9 share the remainder at the floored value (186)"; `PlanInvariantsTest.php:387` | pass-2 pool 3_084_080_000, points 165_474, value 18_600, R8 23_877×18_600, leftover 6_263_600 |
| Award tranches (whole table, R9 = 6,87,47,400, Emerald 48,600 + 59,400) | `LifetimeAwardCatalogTest.php:26` › "pins the client's whole 2026-10-09 tranche table, in paise"; `:16` › "exposes the 2026-10-09 award tranches and budgets via the SSOT" | rank 3 `[4_860_000, 5_940_000]`; rank 9 `[918_630_000, 956_070_000, 5_000_040_000]`, budget 6_874_740_000 |
| Release on the n-th qualification | `RankBonusServiceTest.php:670` › "releases a tranche once the rank has been qualified at least tranche times"; `:642` › "opens award tranche A on the first qualification and tranche B on the second (rank 3)"; `AdminLifetimeAwardsTest.php:79` › "refuses to deliver a tranche the rank has not been qualified for enough times" (tranche 3 at count 2 → 422) | `isReleasable()` false → true |

Documentation inaccuracy (not a code defect): the Task 11 report names `LifetimeAwardCatalogTest` for "releases a tranche once…". The test actually lives in `RankBonusServiceTest.php:670`.

## 4. Fail-safe surface (file:line)

All paths are under `app/app/Modules/Compensation/`.

**`RuntimeException` guards (refuse, never clamp):**

| Guard | Location |
|---|---|
| `msbPointValueCapPaise()`: < 100 or not a whole rupee | `Services/CompensationPlanSettingsService.php:293-300` |
| `msbRoyaltyMinRank()`: outside 1–9 | `:318-322` |
| `msbRoyaltyFailedDailyCapPaise()`: < 100 | `:340-344` |
| `gbbPointValueCapPaise()`: < 100 or not a whole rupee | `:377-384` |
| `rankPointValueCapPaise()` | `:679`, returned raw; checked in `RankBonusService::assertFreezable()` at `Services/RankBonusService.php:284-307` (cap < 100 at :288, RAP ≤ 0 with payable achievers at :293, pending verdicts at :300) |
| Missing tranche | `RankBonusService.php:1082` and `:1112` (`lifetime_award_tranches has no rows for rank …`) |
| GBB cap re-check inside the freeze | `GrowthBoosterBonusService.php:271` |

**`unresolvedDueOnOrBefore`:**
- Defined at `Services/IncomeEligibilityService.php:162`.
- Used by the GBB freeze at `GrowthBoosterBonusService.php:250` and the Rank freeze at `RankBonusService.php:300`.

**Integer envelopes:**
- Rank: `RankBonusService.php:430` `max(0, intdiv($turnoverPaise * $envelopeBp, 10_000))`.
- GBB: `GrowthBoosterBonusService.php:280` `max(0, intdiv($companyBvPaise * $rateBp, 10_000))`.
- `round(` in `Services/`: no hits in the engine services (MSB, GBB, Rank, Mentorship).
- Remaining `round(` hits are pre-existing payout-time TDS and admin-charge rounding: `PayoutService.php:496,693,1997`, `CompensationPlanSettingsService.php:512`, `PayoutReconciliationService.php:473`. There are also non-money uses (progress, duration, percent).
- The branch touched `PayoutService.php:693` only to change the TDS base from `$tdsBase` to `$payable`, because the awards cash path was removed; the rounding itself is unchanged from `main`.

**Freeze reconciliation:**
- `RankBonusService::freezeMonth()` (`:413`) calls `reconcileFreeze()` at `:544`, inside the transaction.
- `reconcileFreeze()` at `:567-588` throws unless roster gross = pool payout = pass payout ≤ envelope and pass-2 pool = envelope − pass-1 payout.

**Audit actions:** all 11 `plan.migration.*` actions are in `Database/Migrations/`:
- `100000` redate (:33)
- `100300` gbb settings (:28)
- `100500` rank_tiers RAP (:31)
- `100600` rank settings (:22)
- `100700` rank_monthly_pools (:32)
- `100800` tranches (:29)
- `100900` milestone tranche (:37)
- `100950` catalogue (:25)
- `101000` cash columns (:24)
- `101100` applies_to_awards (:26)
- `101200` legacy statuses (:30)

The other audit actions:
- `msb.royalty.cap_withheld`: `Services/MentorshipBonusService.php:361`
- `msb.credit.repurchase_gated`: `Services/MentorshipBonusService.php:456`
- `gbb.result.excluded_from_frozen_denominator`: `Services/GrowthBoosterBonusService.php:757-760`
- `awards.tranche.unearned_pending_removed`: `Services/RankBonusService.php:1157`

**Data migrations' `down()`:**
- These throw "restore from the … audit row": `100000` (:115), `100300` (:85), `100500` (:82), `100600` (:86), `100800` (:96), `100900` (:145), `100950` (:166), `101100` (:70).
- **Deviation from the literal rule** (they neither restore listed ids nor throw):
  - `101000` drop cash columns, `down()` at :65-76. Re-creates the five columns nullable and empty.
  - `100700` rank_monthly_pools reshape, `down()` at :100-112. Re-adds `pool_pct` as NULL and drops `pass`.
  - Both are schema-only. They move no rows and guess no values, and the dropped values are listed in their audit rows; the comments say so. `101200` is schema-only too (widens the enums back, moves no row).
  - Not blocking: these environments hold disposable test data only. The user should know a rollback of `101000` or `100700` silently leaves NULLs instead of refusing.
- The `2026_09_11_100000` backfill has the `Schema::hasColumn` no-op guard (diff lines +50-52).

**Display formatting:**
- `git grep -nE "(^|[^A-Za-z])Number::format"` over every changed blade directory → no hits. The changed blades use `IndianNumber::format` / `::rupees`.
- Hard rule 2: `CommissionHasProductSaleTest` 8 passed (§1).

## 5. Stack DB sanity

All of this was read-only against the `arovolife` database on `arovolife-rsp-db`.

**Migrations:** `php artisan migrate:status --pending` → `No pending migrations.`

**`rank_tiers`:**
- `rap_points` = 72 / 189 / 468 / 1125 / 2583 / 5688 / 11934 / 23877 / 39501.
- `SHOW COLUMNS … LIKE 'pool_pct'` on `rank_tiers` and on `rank_monthly_pools` → empty (column gone).

**`lifetime_award_tranches`:**
- 20 rows.
- Per-rank Σ `amount_paise` equals `rank_tiers.lifetime_award_budget_paise` for ranks 1–9 (`ok = 1` on all nine; rank 9 = 6,874,740,000).

**Settings (the store is `settings`; there is no `compensation_plan_settings` table on this stack):**
- `comp.gbb.pool_rate_bp = 400` (audit: `moved: true, before: "500"`).
- `comp.rank.point_value_cap_paise = 20000` and `comp.rank.first_pass_max_rank = 3` (both `inserted: true`).
- `comp.gbb.agp_cap` is absent (audit: `existed: true, value "120"`, deleted).
- `comp.admin_charge.applies_to_awards` is absent (audit: `deleted: true, value_before "false"`).
- `comp.rank.aogo_points`: **no row on this stack.** The audit says `moved: false, before: null`, so the effective value is `SCALAR_DEFAULTS` = 36, pinned by `CompensationPlanSettingsServiceTest.php:103`.
- The MSB / royalty / GBB cap keys likewise have no override rows and fall back to `SCALAR_DEFAULTS` (12,000 / 6 / 360,000 / 24,000).

**Status enums:**
- `gsb_cutoff_results.status` = `no_match, calculated, credited, failed, frozen, below_600bv, reversed, repurchase_forfeited`.
- `gbb_monthly_results.status` = `pending, credited, reversed, repurchase_wallet_blocked, repurchase_failed_blocked`.
- Neither contains `repurchase_held` / `repurchase_suspended`.
- `mentorship_bonus_results.status` = `credited, failed, repurchase_gated`.

**Audit rows:** all 11 `plan.migration.*` actions are present once each (02:36 → 10:05 on 2026-10-09). The redate row lists 39 moved cycles and `now_past_due_ids` [218…224] (F-1).

**Stack-data note:** `mentorship_bonus_results` ids 119 and 120 (cut-off 2026-10-03) are SQL display fixtures, documented in the Task 3 report (line 80) and the Task 4 report (line 78). Row 120 shows ₹120 × 21 = ₹1,080 against a day whose pool is ₹0 (0 BV). It has no wallet entry and is not engine output, so it does not count against the engine. Anyone reading `/admin/compensation/msb-input-output?from=2026-10-03` on this stack sees an impossible-looking day for that reason.

## 6. Pages checked (table: page | URL | result | screenshot)

**Method:**
- One Playwright pass (admin/developer session already open) over the 30 unique URLs in the eleven task reports' "UI verification" tables, plus the three month variants they reference.
- For each page: HTTP status from `page.goto`, body text scanned for `ErrorException` / `Whoops` / `SQLSTATE` / `Undefined variable` / `Undefined array key`, `console`/`pageerror` listeners, a western-grouping regex `\d{1,3},\d{3},\d{3}`, page-specific strings, and a full-page screenshot.
- `browser_console_messages(level=error, all=true)` at the end → `Errors: 0`.
- Four content checks first came back false because the text sits in a collapsed `<details>`, a CSS-uppercased header, an input value or a tooltip. All four were re-verified with `textContent` and input values (marked † below).

Result on every row: HTTP 200, no exception text, 0 console errors, 0 western-grouped numbers. Screenshots are in `docs/plans/rsp-reports/screens/final/`.

| Page | URL | Content check (verified) | Screenshot |
|---|---|---|---|
| Settings → compensation plan | `/admin/settings?group=compensation_plan` | all 7 new/changed keys listed (cycle length, MSB cap, royalty rank, royalty cap, GBB cap, Rank cap, pass-1 ranks); `50,00,000` | `settings-compensation-plan.png` |
| Help → Compensation | `/admin/help/compensation` | "Window length", "capped at ₹120", "Mentorship Royalty daily cap", "never held a rank", "6,87,47,400", "merchandise only, never cash" | `help-compensation.png` |
| Distributor 24 → repurchase | `/admin/compensation/distributors/24?tab=repurchase` | "30-day repurchase window, inclusive of its first day"; `3,00,000` | `distributor-24-repurchase.png` |
| Plan settings | `/admin/compensation/plan-settings` | † "one 20% pool, two passes", "48,600", "59,400" (collapsed explainer) | `plan-settings.png` |
| Plan settings → ranks | `/admin/compensation/plan-settings?tab=ranks` | † nine `rap_points` inputs 72…39501, `required min=1`; 0 `pool_pct` inputs | `plan-settings-ranks.png` |
| Lifetime awards | `/admin/lifetime-awards` | Tranche column, "merchandise only, never cash" | `lifetime-awards.png` |
| Lifetime awards catalogue | `/admin/lifetime-awards/catalog` | "6,87,47,400"; `1,08,000` | `lifetime-awards-catalog.png` |
| GSB calculation | `/admin/compensation/gsb-calculation` | "Credited"; `21,03,000` | `gsb-calculation.png` |
| GBB I&O | `/admin/compensation/gbb-input-output` | `40,76,998` lakh grouping | `gbb-input-output.png` |
| GBB I&O Oct 2026 | `…/gbb-input-output?month=2026-10` | "GBB pool (4%)", "₹240.00" | `gbb-input-output-2026-10.png` |
| GBB I&O Jul 2026 | `…/gbb-input-output?month=2026-07` | "Blocked — repurchase condition failed", "2,83,90,777" | `gbb-input-output-2026-07.png` |
| GBB calculation | `/admin/compensation/gbb-calculation` | "Repurchase condition failed" option | `gbb-calculation.png` |
| GBB calculation Oct 2026 | `…/gbb-calculation?month=2026-10` | "min( Cap" | `gbb-calculation-2026-10.png` |
| GBB calculation Jul 2026 | `…/gbb-calculation?month=2026-07` | "14,19,538.85" (pre-release figures intact) | `gbb-calculation-2026-07.png` |
| GBB calculation filtered | `…/gbb-calculation?status=repurchase_failed_blocked` | "Repurchase condition failed" | `gbb-calculation-repurchase-failed.png` |
| GBB month Aug 2026 | `/admin/compensation/gbb/2026-08` | month header renders | `gbb-month-2026-08.png` |
| GBB month Oct 2026 | `/admin/compensation/gbb/2026-10` | "4%", "₹240.00" | `gbb-month-2026-10.png` |
| GBB month Jul 2026 | `/admin/compensation/gbb/2026-07` | "14,19,538.85", "6,481" | `gbb-month-2026-07.png` |
| GBB index | `/admin/compensation/gbb` | "capped at ₹240", "never held a rank" | `gbb-index.png` |
| Help → Payout operations | `/admin/help/payout-operations` | TDS section renders | `help-payout-operations.png` |
| TDS report | `/admin/reports/profit/tds` | TDS explainer; `4,38,947` | `reports-tds.png` |
| RB I&O Aug 2026 | `/admin/compensation/rb-input-output?month=2026-08` | "priced under the per-rank pool rule", "2,40,76,998", Pass column | `rb-input-output-2026-08.png` |
| Rank bonus month Aug 2026 | `/admin/compensation/rank-bonus/2026-08` | F-7 label, "1,605" | `rank-bonus-2026-08.png` |
| RB calculation Aug 2026 | `/admin/compensation/rb-calculation?month=2026-08` | F-7 label, "value of the pass that priced it" | `rb-calculation-2026-08.png` |
| AW&RW calculation | `/admin/compensation/aw-rw-calculation` | "merchandise only, never cash", Tranche, "6,83,400" / "2,77,600" | `aw-rw-calculation.png` |
| MSB calculation 15 Aug | `…/msb-calculation?from=2026-08-15&to=2026-08-15` | "Point value = min( Cap", "21,03,000", "1,752" | `msb-calculation-2026-08-15.png` |
| MSB I&O 3 Oct | `…/msb-input-output?from=2026-10-03&to=2026-10-03` | † "Royalty cap withheld" header (CSS-uppercase), gated banner, "1,440.00", "₹120.00" (fixture day, §5) | `msb-input-output-2026-10-03.png` |
| MSB calculation gated | `…/msb-calculation?status=repurchase_gated` | "Repurchase gated" | `msb-calculation-repurchase-gated.png` |
| MSB calculation credited | `…/msb-calculation?status=credited` | "Credited"; `18,30,000` | `msb-calculation-credited.png` |
| Distributor 1 → MB tab | `/admin/compensation/distributors/1?tab=mb` | "Repurchase gated", "withheld by the daily royalty cap" | `distributor-1-mb.png` |
| Help → Glossary | `/admin/help/glossary` | "no per-distributor cap", "39,501" | `help-glossary.png` |
| Engine runs | `/admin/compensation/engine-runs` | page renders (the rebuild-preview POST from the Task 6 report was not re-posted) | `engine-runs.png` |
| Distributor 246 → rank bonus | `/admin/compensation/distributors/246?tab=rank-bonus` | † tooltip "earlier per-rank pool rule" (in the DOM, tooltip text) | `distributor-246-rank-bonus.png` |

**Distributor pages (`/income/*`, dashboard):** the reports list no distributor URLs, and no distributor credentials were provided, so these were **not driven in a browser (unverified in the UI)**. The changed distributor blades are covered by HTTP tests that passed in run 1a:
- `income/mentorship`: `IncomeControllerTest` "tells the sponsor why a repurchase-gated Mentorship day paid nothing…" and "tells a royalty sponsor what the daily Mentorship Royalty cap withheld…".
- `income/rank-bonus`: `IncomeControllerTest.php:752,768,791,1138,1152,1451,1459`.
- `income/growth-booster`: `IncomeControllerTest.php:1011` and `AdminGbbCalculationTest.php:216`.
- `dashboard/_repurchase-cycle`: `RepurchaseCycleCardTest`, `RepurchaseCycleDueDateTest` "the dashboard card reports a 30-day window…".

## 7. Deploy record review

I read `docs/compensation/rsp-new-updates-2026-10-09.md` §8 and §9 as the operator would. Command signatures were checked with `--help` in the container:

| Command | Result |
|---|---|
| `repurchase:evaluate` | exists (`--date`, `--distributor`); a bare call evaluates as of today |
| `db:seed --class=X` | single-class option, as the doc states |
| `RankTiersSeeder` / `LifetimeAwardTranchesSeeder` / `LifetimeAwardRewardsSeeder` | all exist under `app/database/seeders/` |
| `compensation:recompute-all` | exists (`--horizon`, `--from`, `--windowed`, `--only`, `--if-projected`, `--force`), "TEST ENVIRONMENTS ONLY" |
| `migrate:status --pending` | works (§5) |
| `app:deploy` | exists (`--maintenance` = "Wrap migrations in php artisan down/up") |

The §9.1 and §9.2 SQL references only columns and enum values that exist on the stack schema:
- `wallet_ledger_entries.type` / `bonus_month` / `amount_paise`
- `msb_daily_pools.point_value_paise` / `payout_paise` / `leftover_paise`
- every `rank_monthly_passes` column used
- `gbb_monthly_pools.pool_paise` / `payout_paise`
- `mentorship_bonus_results.mb_gross_paise`

Steps that cannot be done exactly as written, or need clarification (none is a code defect):
1. **Step 2 vs 3:** `app:deploy` does more than stop workers. It also runs `migrate --force` (unless `--skip-migrate`) and `db:seed ProductionSeeder` (unless `--skip-seed`). Two consequences:
   - An operator who runs `app:deploy --maintenance` for step 2 has already run step 3's migrate.
   - On dev/staging that migrate stops at the 101100/101200 refusals mid-pipeline, before step 4's `repurchase:evaluate`.

   The doc should say which flags to combine (for example `--skip-migrate --skip-seed`, followed by manual `down` → `migrate` → `repurchase:evaluate` → `up`), or that steps 3–4 replace the pipeline's own migrate. `ProductionSeeder` itself is safe: it seeds plan tables only while they are empty (`ProductionSeeder.php:285-305`).
2. **Step 3 sub-bullet ordering:** "migrate stops at the refusing migration → run step 6 → migrate again" sends the operator past steps 4–5 and back. It is workable, but the order should be written out linearly for dev/staging.
3. **Step 8:** `npm run build` on Cloudways needs the nvm Node (memory: v24). The doc does not say so.
4. **§9:** explicitly *not executed* in this run, because it needs the staging DB and runner, which are unreachable from the isolated stack. These are still open deploy-time verifications, not completed ones.

## 8. Branch hygiene (checkboxes, trailers, leftovers)

**Checkboxes:** `git show HEAD:docs/plans/compensation-rsp-updates-2026-10-09.md | grep -c "^- \[ \]"` → **0**. The Task 12 ticks were uncommitted at the start of my run and are committed in b62f9098.

**`git status --short` after my run:** `?? docs/plans/rsp-reports/screens/final/` only. My screenshots, plus this file once written. The earlier leftovers (0-byte `.commit-msg-task-*.txt`, `screens/task-1/*`, root `*.png`) were removed by another process during the run.

**Compliance-Review trailers:**
- Every non-runner commit carries the text `Compliance-Review: compliance-officer` (`git log --invert-grep --grep=…` lists only runner/report commits).
- Those runner/report commits touch nothing under `app/` (`git log … --invert-grep … -- app` is empty).
- **5 commits carry it where git's trailer parser does not see it**, because a blank line separates it from `Co-Authored-By`: e4aadca6 feat(rank) two-pass, 6efb67d1 feat(rank) RAP/AGO, 088e976e fix(settings), fc579ad2 test(compensation), 5299b259 docs(compensation).
- So `%(trailers:key=Compliance-Review)` shows them empty. Any tooling that reads trailers will miss them. This is cosmetic; history would need rewriting to fix it, so it is left as is.

## 9. Open items the user must know before merging

1. **Main-side failures (not this branch):**
   - The 3 failing tests in §1 (`CarryOverDisplayTest` "carry cards…", `IncomeControllerTest` "counts a wal…" / "gives the pe…").
   - The 2 Pint style issues in `CarryOverDisplayTest.php` / `RankBonusStylingTest.php`.
   - `RazorpayGateway.php:131`, now baselined rather than fixed.

   Fix all of these on `main`.
2. **Larastan baseline** was regenerated on this branch (+641/−54, test-file noise plus the Razorpay entry). Expect a conflict-prone file if `main` also touches it.
3. **Migration `down()` deviation:** `101000` and `100700` are schema-only. Rollback leaves NULL columns rather than refusing (§4).
4. **Deploy record:** clarify step 2/3 (`app:deploy` runs migrate + seed itself), linearise the dev/staging order, and note nvm for `npm run build` (§7). §9 staging reconciliation is still to be done at deploy.
5. **Spec doc §10 leftovers** (recorded by the reviews, not done):
   - `awards_credit` is still in the `wallet_ledger_entries.type` enum, and `repurchase_held` is still in `rank_bonus_results.status`.
   - There is no whole-rupee check on Save for the caps, and the **Rank** cap guard checks only `< 100`: a cap of `20050` would pay non-whole-rupee point values. Align it with MSB/GBB.
   - GBB CSV exports lack the raw/cap columns.
   - "₹240" / "4 %" are hardcoded in two developer explainers.
   - The F-4 stale-flag query runs once per accrual.
   - Several engine-run recording hand-offs remain (`RankBonusRunCommand` / `FortuneBonusEnrollCommand` refusal not `noteSkipped()`, double rows on a manual trigger, `--in-flight` guard order).
   - The rb-calculation unfiltered page lacks the strip for pass-2-only months.
6. **Client questions still open:** assumptions A-G1, A-M1, A-M2, A-R1, A-A1; the award merchandise list (placeholder catalogue seeded); and the risk-register entry on the Lifetime Awards funding source (hard rule 2, indirect link to sales).
7. **No real two-pass month exists on any environment yet.** Two-pass rendering is pinned by HTTP tests through the real engine; the first real 1st-of-month freeze must be checked per §8 step 9.
8. **Stack fixture rows** 119/120 in `mentorship_bonus_results` (2026-10-03) are hand-inserted display data (§5). They are irrelevant to the merge but look impossible on the MSB I&O page of this stack.
9. **Minor documentation slip:** the Task 11 report names the wrong file for the release-rule test (§3).

None of the items above is a defect in the branch's engine code. All five engines' client figures are pinned by passing tests, the invariants file passes, the fail-safe guards and audit actions are present, the stack DB matches the plan, and every listed admin page renders cleanly.

QA VERDICT: APPROVED
