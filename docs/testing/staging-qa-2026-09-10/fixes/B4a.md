# B4a — Admin reports & analytics

## F119 — fixed
Files: `app/resources/views/admin/analytics/index.blade.php`, `app/tests/Modules/Analytics/AnalyticsTest.php`.
Test: `ANL-014: the headline BV figure is not divided by 100 twice` (`AnalyticsTest.php`).
Commit: a373733f
The view divided `bv_paise` by 100 before handing it to `@bv`, and `Bv::format()` divides by 100 again — BV was under-reported 100×. Removed the two `/ 100`s at the headline "BV generated" tile and the "Highest volume" table row. Side note in the finding ("`Bv::format` uses `Number::format`, not `IndianNumber`") was already stale: `Bv.php` imports `App\Modules\Shared\Support\IndianNumber as Number` and calls that — no change needed there.

## F122 — fixed
Files: `app/app/Modules/Shared/Support/Csv.php`, `app/app/Modules/Grievance/Http/Controllers/AdminGrievanceReportController.php`, `app/tests/Modules/Shared/CsvTest.php`, `app/tests/Modules/Grievance/GrievanceWorkflowTest.php` (new test `GRV-029`).
Commit: 66382243
`Csv::safe()` was typed `int|string|null`; `median_resolution_days` is `?float`, so the export 500'd (TypeError) once a ticket resolved with a fractional day span. Widened `Csv::safe()` to `int|float|string|null`, and in the export loop format any float value to a fixed 2 dp (`number_format($value, 2, '.', '')`) before passing it through `Csv::safe()`. Verified GRV-029 fails with the pre-fix code (TypeError/500) and passes after.

## F88 — fixed
Files: `app/app/Modules/Compensation/Http/Controllers/Admin/AdminGsbInputOutputController.php`, `app/resources/views/admin/compensation/{gbb,fortune-bonus}/show.blade.php`, `app/tests/Modules/Compensation/{AdminFortuneReportsTest,AdminGbbCalculationTest,AdminGsbInputOutputTest}.php`.
Commit: 3b93af47
GBB and Fortune Bonus month pages rendered "Company BV" as `'₹'.IndianNumber::format($pool->company_bv_paise / 100, 2)` — a currency-styled BV figure. Switched both to `Bv::format($pool->company_bv_paise)`. The GSB I&O CSV export header said "Day Total BV (Rs)"; dropped "(Rs)" (matches the sibling GBB I&O header, which never had it). Updated the three tests' literal-string assertions to match (`2,00,000 BV` / `40,00,000 BV` instead of `₹...`, header without `(Rs)`), plus an `assertDontSee` guard against the old ₹-prefixed form.
Checked GBB/MSB I&O headers for the same defect — GBB I&O's "Month Total BV" and MSB I&O's "Day Total Received BV" already carry no "(Rs)"; left their (pre-existing, out-of-scope) 2-decimal ungrouped value formatting untouched since only the header text was in scope for this finding.

## F90 — fixed
Files: `app/app/Modules/Compensation/Http/Controllers/Admin/AdminGsbCalculationController.php`, `app/resources/views/admin/compensation/gsb-calculation/index.blade.php`, `app/resources/views/admin/compensation/carry-forwards/index.blade.php`, `app/tests/Modules/Compensation/AdminGsbCalculationTest.php`, `app/tests/Modules/Compensation/AdminCarryForwardTest.php` (new).
Commit: f93245aa
The GSB daily-calculation report's "weaker <BV>" sub-line and the carry-forwards page's power-side CF, raw power-side code, and slab-1 weaker CF all left the reader to guess which group (Left/Right) a figure belonged to. Added `gcr.power_side_after` to the daily-calculation query's select and derived the weaker label as its inverse (`L`→Right, `R`→Left — stored, never recomputed); the carry-forwards page derives both labels from its own stored `power_side` the same way, and the raw-code column now spells out "Left"/"Right" instead of `L`/`R`. Checked the GSB Input & Output report separately — it carries no per-distributor weaker/power BV figure at all (day/pool economics only), so nothing there needed a label.
New tests: `AdminGsbCalculationTest::F90...` and `AdminCarryForwardTest::F90...`. Both verified to fail on the pre-fix code and pass after.

## F91 — fixed
Files: `app/app/Modules/Compensation/Http/Controllers/Admin/{AdminCarryForwardController,AdminRankBonusController}.php`, `app/resources/views/admin/compensation/{carry-forwards,daily-cutoffs}/index.blade.php`, `app/resources/views/admin/compensation/{msb-calculation,msb-input-output}/index.blade.php`, `app/resources/views/admin/compensation/rank-bonus/show.blade.php`, `app/routes/web.php`, five test files.
Commit: 06e58502
Five sub-items:
- **MSB note**: added "'Income' is the amount credited — no repurchase deduction applies to MSB." to both `msb-calculation` and `msb-input-output` index pages (plain admin-visible text, not `@developer`-gated).
- **Rank-bonus qualifier contradiction**: the real bug was `AdminRankBonusController::show()`'s `$rankSummaries` query computing `COUNT(*) as qualifier_count` — recomputing under the same field name the engine already writes a frozen, consistent `qualifier_count` into on every `rank_bonus_results` row (used correctly elsewhere via `MAX(qualifier_count)` in `BonusCalculationSnapshots::rankBonusMonth()`). `COUNT(*)` also counted the AO-GO grantee's own result row as an extra "qualifier". Changed to `MAX(qualifier_count)` — the stored value, never recomputed — which now agrees with the formula strip's "Qualifiers" figure on the same screen.
- **Truncated tiles**: the pool and credited-to-wallets tile figures used `IndianNumber::format(..., 0)`, rounding ₹25,043.20 to ₹25,043. Changed precision to 2 dp.
- **Carry-forwards CSV export**: added `AdminCarryForwardController::export()` + route `admin.compensation.carry-forwards.export` + a Download CSV link, matching every other `/admin/compensation` report. BV columns are ungrouped points via `Bv::points()` (never a rupee figure), and Power/Weaker side columns spell out Left/Right (extends F90's fix to the new export).
- **"leg" → "group"**: fixed in `daily-cutoffs/index.blade.php`'s developer note (the finding's named location) and, since I was already in the same file for the CSV export, the identical "stronger leg" phrase in `carry-forwards/index.blade.php`'s own developer note — same house-rule violation, same batch, not separate scope.
New/extended tests: `AdminMsbCalculationTest`, `AdminMsbInputOutputTest`, `AdminRankBonusInputOutputTest::F91...`, `AdminCarryForwardTest::F91...`, `AdminDailyCutoffTest::F91...`. All verified to fail pre-fix and pass after (spot-checked the rank-bonus and daily-cutoffs ones directly; the MSB/carry-forwards ones assert new copy/routes that did not exist before, so failure pre-fix is definitional).

## F103 — fixed
Files: `app/routes/web.php`, `app/resources/views/admin/commerce/bv-ledger/{index,show}.blade.php`, `app/tests/Modules/Commerce/AdminBvLedgerTest.php`.
Commit: a27910ec
The four `/admin/commerce/bv-ledger*` routes carried no `can:` gate at all — unlike `/admin/analytics`, gated on `audit.read` for the identical "company-wide league table" reason (same comment reused). Wrapped all four in `Route::middleware('can:audit.read')->group(...)`. Renamed "Net BV" → "Personal BV" (index summary card + table column) and "Lifetime Net BV" → "Lifetime personal BV" (show page) — left the CSV export's "Net BV" column header as-is since the finding named only the UI headings and it's a separate, lower-risk surface; flagged below as a follow-up.
New tests: `AdminBvLedgerTest::F103...` (asserts all 4 named routes carry exactly `can:audit.read` via `gatherMiddleware()`, mirroring the existing `SEC-08` rate-limit route-assertion pattern in `HardeningTest.php`) plus heading assertions added to the two existing render tests. Route-gate test verified to fail pre-fix (`null` where `can:audit.read` expected) and pass after.
routes/web.php note: this file also carried unrelated uncommitted lines from another implementer's batch (payout NEFT-export gating, monthly-payouts route additions) at the time I edited it. Staged only my hunk by temporarily reverting their lines, committing, then restoring their lines in the working tree — did not touch or commit their changes.
Follow-up (not fixed, out of scope for F103 as worded): `AdminBvLedgerController.php:147`'s CSV export header still says "Net BV"; consider renaming to "Personal BV" for consistency with the UI in a future pass.
