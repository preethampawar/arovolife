# R.S.P. New Updates (2026-10-09) — Compensation Engine Changes Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Bring the five compensation engines (repurchase cycle, Mentorship, Growth Booster, Rank Bonus, Awards & Rewards) in line with the client's "R.S.P. - NEW UPDATES" section of 2026-10-05/09, modifying the existing engines in place and keeping every parameter in its existing DB-driven single source of truth.

**Architecture:** No engine is replaced. Repurchase keeps its anchor/forfeit model and only shifts the due date by one day. MSB and GBB keep pool ÷ points and gain a capped point value. Rank Bonus moves from nine per-rank pools to one 20% pool divided in two passes (AGO + Ranks 1–3 first, Ranks 4–9 from the remainder) at a capped point value, with RAP points on every rank. Awards gain per-tranche release (A/B/C on the 1st/2nd/3rd qualification). The repurchase failed-day verdict stays in exactly one place, `IncomeEligibilityService::verdictAsOf()`, and the two engines that did not consult it (Mentorship, Growth Booster) now do.

**Tech Stack:** Laravel 13, PHP 8.4, Pest tests (SQLite :memory: — see `docs/local-dev-environment.md` for the `-e` overrides; never bare `php artisan test`), Pint, Larastan level 7.

**Spec:** Client Google Doc `1c7fWGWMkOfKP-AMkjLNJAU6AbcJu9oWHvkjUhlGqMWw`, section "R.S.P. - NEW UPDATES" (subsections 1 Re-purchase, 2 Mentorship Bonus, 3 Growth Booster Bonus, "RANK INCOME POINT SYSTEM" dated 05-10-2026, "LIFETIME AWARDS AND REWARDS"). The spec summary below is authoritative for this plan; the decisions in §Decisions were taken by the user on 2026-10-09.

---

## Spec summary (what the client asked for)

### 1. Repurchase
- Cycle begins the day a distributor first reaches 600 BV of personal purchase (already implemented). Window is **30 days**; the doc's example is 14-02-2026 → 15-03-2026, i.e. **due_date = start + 29 days** (the start day counts as day 1).
- Obligation: non-ranked 600 BV; ranks 1–9: 1,000 / 1,100 / 1,200 / 1,300 / 1,400 / 1,600 / 1,800 / 2,000 / 2,300 BV (already seeded in `rank_tiers.repurchase_bv_paise`).
- Two conditions on the last day: BV met within the window AND repurchase wallet = ₹0. Either fails → failed. Failed days forfeit downline/Left/Right Genos BV; a fresh 30-day cycle starts on the fulfilment day. (Already implemented, 2026-09-07 spec.)
- Wallet: personal purchases consume the repurchase wallet before the bank (already implemented). An Easy Purchase order placed by a customer through a distributor's link must never debit the distributor's wallet (already true: the wallet is the **buyer's**, `Auth::user()?->distributor?->id`; pinned by a test in Task 1).

### 2. Mentorship Bonus (MSB) — 3%
- Points per sponsee slab: 21/18/15/12/9/6/3 (already seeded `gsb_slabs.msb_score`). Point value = 3% of the day's BV ÷ the day's total points, floored to whole rupees (already implemented), **capped at ₹120 per point** (new).
- Sponsor up to rank 5: if the sponsor is failed on their repurchase condition on the cut-off day, the MB points from their sponsees' slab matches that day are **not awarded** (new).
- Sponsor rank 6+: MB becomes "Mentorship Royalty". Paid even while failed, but while failed the sponsor's MB is **capped at ₹3,600 per day** (₹1,08,000 per month) (new). While not failed, full amount.

### 3. Growth Booster Bonus (GBB) — 4%
- Pool rate **4%** (was 5%). Monthly. Points per GSB slab: slab 1 → 12, slab 2 → 5, slab 3 → 2 (already seeded `gsb_slabs.agp_per_occurrence`). Point value = pool ÷ total points, floored, **capped at ₹240 per point** (new).
- Only for distributors who have **never** held a rank. The month they first reach Rank 1, both GBB and Rank Bonus are paid; from the next month on, never GBB again (new; was "not ranked in the previous month").
- To qualify, the distributor must satisfy the repurchase condition (600 BV within the cycle and wallet zero at the cycle end) (new gate, see A-G1).

### 4. Rank Bonus — 20% of the month's turnover
Rank Achievement Points (RAP): AGO offer 36; R1 72; R2 189; R3 468; R4 1,125; R5 2,583; R6 5,688; R7 11,934; R8 23,877; R9 39,501. Point value cap **₹200**.
- Pass 1: AGO + Ranks 1–3 share the whole 20% pool: value₁ = min(₹200, floor(pool ÷ Σ pass-1 points)).
- Pass 2: Ranks 4–9 share the **remainder** (pool − pass-1 payout): value₂ = min(₹200, floor(remainder ÷ Σ pass-2 points)).
- Each participant is paid own points × the pass value. Leftover stays with the company. Worked examples A1, A2, B1, B2, C1, C2, D1, D2 in the doc; D2's 8th-rank line uses 186 (the floored value), the printed "200" is a typo (user confirmed 2026-10-09).

### 5. Lifetime Awards & Rewards (merchandise only, never cash)
| Rank | Total | Tranche A | Tranche B | Tranche C |
|---|---|---|---|---|
| 1 Silver | 15,400 | 15,400 | | |
| 2 Pearl | 36,000 | 36,000 | | |
| 3 Emerald | 1,08,000 | 48,600 | 59,400 | |
| 4 Gold | 3,24,000 | 1,45,800 | 1,78,200 | |
| 5 Diamond | 9,72,000 | 4,37,400 | 5,34,600 | |
| 6 Blue Diamond | 28,26,000 | 8,47,800 | 9,32,400 | 10,45,800 |
| 7 Royal Diamond | 81,74,700 | 24,52,500 | 26,97,300 | 30,24,900 |
| 8 Crown Diamond | 2,37,06,000 | 71,11,800 | 78,22,800 | 87,71,400 |
| 9 Elite Diamond | **6,87,47,400** | 91,86,300 | 95,60,700 | 5,00,00,400 |

Tranche A releases on the 1st qualification, B on the 2nd, C on the 3rd. Ranks 1–2 release immediately. R9 total is 6,87,47,400 (user confirmed 2026-10-09; the doc's heading "6,87,74,400" is a digit swap). The merchandise item list is pending from the client; the catalogue is seeded with one placeholder item per tranche.

## Decisions (user, 2026-10-09)
1. **Cycle end** = start + 29 days (option B).
2. **GBB first-rank month**: exclusion test is "qualified in any month strictly before this one" (option A).
3. **Retire** `comp.gbb.agp_cap` (option B).
4. **R9 awards**: seed the tranche sum 6,87,47,400 (option A, then confirmed).
5. **Rank Bonus history**: rebuild all past months on the new formula (option B; test data only, launch 2026-11-10). *Amended by the fail-safe review below (F-7):* history is replayed only where the recompute runner exists (dev, staging). `MonthRebuilder::refusals()` refuses any month whose following month has already run, so a month-by-month production rebuild is impossible by design; production history stays on the old formula until the pre-launch wipe.

## Assumptions (state in the commit body; confirm with the client later)
- **A-G1** The GBB "must satisfy the repurchase condition" gate is read as: the distributor is not forfeited on the last day of the month (`verdictAsOf(monthEnd)` eligible) in addition to the existing month-end wallet-zero gate. Blocked distributors are recorded as a pool-excluded roster row.
- **A-M1** The rank used for the Mentorship gate/royalty is the sponsor's highest qualified rank **as decided by the cut-off date**: the maximum `rank_qualifications.rank_number` with `status = qualified` and `month_start` strictly before the cut-off's month (a month's rank is decided on the 1st of the next month, so it is not yet known on any day inside that month). `RepurchaseCycleService::currentRank()` is the lifetime maximum with no date and is NOT used here — see F-2 in the fail-safe review.
- **A-M2** The ₹3,600 royalty cap is per sponsor per cut-off day, aggregated across all their sponsees' accruals that day; the excess is withheld permanently (recorded, never released).
- **A-R1** The AGO offer stays a Rank-1-row participant (36 points, pass 1); `comp.rank.aogo_lifetime_max` and the grant mechanics are unchanged.
- **A-A1** Each tranche is released when `qualification_count ≥ tranche number`; the existing 1/2/3 rank-based release threshold is replaced by this per-tranche rule.

## Fail-safe principles (apply in every task)
1. **Misconfiguration stops the engine, never pays zero or over.** A cap ≤ 0, a rank with payable achievers but RAP ≤ 0, or a missing tranche row throws a `RuntimeException` **before any write**. The engine-run recorder already turns an exception into a failed run that the health digest reports.
2. **A pending prerequisite stops the engine.** A monthly engine whose verdict depends on the repurchase evaluation must refuse to freeze while any earner has a cycle due on or before the month end that is still unresolved. An unresolved cycle reads as "eligible" in `verdictAsOf()`, which is the overpaying direction.
3. **Freeze, then reconcile, then commit.** Every pool freeze ends with an arithmetic reconciliation inside the same transaction (Σ roster gross = Σ pass payout ≤ envelope). A mismatch throws and rolls back.
4. **Integer paise only.** No `round()` on money: `intdiv()` for the envelope, `Money::floorRupee()` for point values. 64-bit `int` holds 10^12 paise × 2,000 bp comfortably.
5. **Nothing silent.** Every withheld or capped amount is a column on the row that would have carried it and an `audit_log` row when it changes what a distributor receives.
6. **Migrations move only what the rule changes.** Settings rows move from the old default only (an admin override is kept); resolved cycles never move; dropped columns are dropped after every reader is gone (grep proves it).
7. **History is rebuilt deliberately, not patched.** Decision 5B: past months are re-derived by the existing rebuild tooling after deploy, with the user's per-environment yes. No migration rewrites credited rows. *Scope per F-7 below:* the recompute runner on dev and staging only; production has no month-by-month path (`MonthRebuilder::refusals()` refuses a month once the next one has run) and keeps its old-formula months until the pre-launch wipe.

## Global Constraints
- `declare(strict_types=1);` in every PHP file; `final` services; `$fillable` + `casts()` on models; one concern per migration; migrations live in `app/Modules/Compensation/Database/Migrations/` (restart scheduler + queue on deploy).
- Every parameter lives in `compensation_plan_settings`-backed scalars (`CompensationPlanSettingsService::SCALAR_DEFAULTS` + `SettingsSeeder::seedCompensationPlanScalars()` + the `AdminSettingsController` registry entry, all three in the same task) or in `rank_tiers` / `gsb_slabs` / `lifetime_award_*` tables. No new hardcoded rupee constants in engine code.
- Money is integer paise. Point values floor to whole rupees via `Money::floorRupee()`.
- Display numbers go through `IndianNumber::format` / `@bv`; never `Number::format`.
- `audit_log` digests are `AuditLog::digest()` raw bytes; freeze/refreeze actions keep their `AuditLog::create([...])` rows.
- Admin help docs under `resources/help/*.md` must change in the same task as the rule they describe.
- Attribute plan decisions to "the client", never by name.
- Tests: run with the test-DB overrides from `docs/local-dev-environment.md`; never `migrate:fresh` on `arovolife`.
- Commit trailer on every commit touching money: `Compliance-Review: compliance-officer`.
- Do not push or deploy; local commits only (memory: no deploy without approval).

## Review Focus
1. **Refund-heavy (negative BV) month or day** → pool ≤ 0 → point value 0, no negative payouts, leftover 0. Pinned in Task 9 (rank) and Task 2 (MSB).
2. **Pass 1 with zero participants** → pass 2 must divide the whole envelope, not 0. Pinned in Task 9.
3. **Sponsor with several sponsees on one day while failed at rank 6+** → cumulative cap across accruals, order-independent total ≤ ₹3,600. Pinned in Task 4.
4. **Distributor ranked two months ago but not last month** → under the new lifetime rule they get no GBB; under the old rule they did. Pinned in Task 6.
5. **Re-dating open cycles** → a cycle already resolved must not move; an open cycle whose new due date is already in the past must be resolved by the next `repurchase:evaluate`, not left active. Pinned in Task 1.

## Fail-safe findings (review 2026-10-09 — binding on every task)

The seven principles above are the doctrine. Four more the engines already follow and this plan must not weaken: **freeze the roster, not just the pool** (who shares a pool is decided once, inside the freeze transaction); **every input to a frozen figure is as-of the period**, never "current"; **a refusal is a `skipped` run with a reason**, never a silent non-start or a `failed` row a re-run cannot fix; **money is written in one transaction with the row that explains it**. The findings below (F-1 … F-12) are the places where the tasks as first written would have broken one of those, plus the data-handling rules that apply to every migration in this plan. Each finding names the task it amends; the implementer treats them as part of that task. Where a finding and a task body disagree, the finding wins.

### Rules for every data migration in this plan (Tasks 1, 5, 8, 9, 11)
- **One `audit_log` row per data migration**, action `plan.migration.<name>`, `details` = row counts touched and, for anything under 500 rows, the list of `(id, before, after)`. The row is the only durable record of what the migration changed; `down()` is not.
- **`down()` never guesses.** A `down()` that applies the inverse arithmetic to *every* row (Task 1 as first written: `due_date + 1` for all open cycles) also moves rows created *after* `up()` under the new rule. `down()` either restores exactly the ids the audit row lists, or throws `RuntimeException('restore from the plan.migration.* audit row')`. Pin this in the migration test.
- **Settings moves keep admin overrides and say so.** `where('value', <old default>)` is right, but the audit row records whether the row was moved or left (`{'key':…, 'moved':bool, 'value':…}`) so an environment still on 5% after deploy is visible, not silent.
- **Stale columns and settings are dropped in this release (user decision 2026-10-09, overriding the "one release later" default).** Every environment holds test data that is wiped before launch, so there is no history worth keeping readable. The rule that survives: a column is dropped only in a migration that runs **after** every reader is gone (`grep` proves it in the same task), and a migration that narrows an enum first asserts no row carries the removed value and throws otherwise (Task 13). The 2026-09-11 backfill migration gets a `Schema::hasColumn()` guard so a fresh install still migrates end to end.
- **Enum and unique-index changes carry a SQLite branch** (Tasks 7, 11) and are run on dev MySQL before commit (memory: SQLite tests miss column widths).
- **Migrations run with the queue workers and scheduler stopped.** Task 1 re-dates cycles the 00:05 evaluate reads; Task 9 reshapes a table the 1st-of-month freeze writes. `app:deploy --maintenance` already does this; a bare `php artisan migrate` on a live box does not.

### Findings

**F-1 (Task 1) — the re-date can flip a verdict for days already priced.** A cycle re-dated from due 9 Oct to due 8 Oct, evaluated on the 10th and failed, is forfeited from the 9th; GSB and MSB for the 9th were already settled at the eligible verdict. This is the same tolerance the deferral backfill accepts, but here it is caused deliberately, so: (a) the migration **lists** in its audit row every open cycle whose new due date is before today (`due_date < CURDATE()` after the update) — these are the only cycles that can flip retroactively; (b) the deploy runbook runs `repurchase:evaluate` immediately after `migrate`, inside the same maintenance window, so the flip is recorded before the next cut-off, not a day later; (c) the Task 1 test pins the boundary the client's example implies: cycle 14 Feb → 15 Mar is judged by the 00:05 run on **16 Mar**. **Verified 2026-10-09:** `RepurchaseCycleService::refresh()` treats `asOf <= due_date` as "window still open" and resolves on the first run dated after the due date, so no selector change is needed; the Task 1 test asserts `evaluate(d, 15 Mar)` leaves the cycle active and `evaluate(d, 16 Mar)` resolves it. A cycle whose due date is the month's last day is therefore forfeited from the 1st and **cannot** block that month's GBB (A-G1) — state this in the help doc so nobody reads it as a bug.

**F-2 (Tasks 3, 4) — rank must be as-of the cut-off date.** `currentRank()` is `max(rank_number)` over all qualified rows with no date. A sponsor at rank 5 on 10 Aug whose August qualification to rank 6 is written on 1 Sep would be gated (no MB) by the live run and paid as royalty by a backfill or a staging replay of the same day — two answers for one frozen day. Add `RepurchaseCycleService::rankAsOf(int $distributorId, Carbon $date): int` = max qualified `rank_number` with `month_start < $date->startOfMonth()`, and use it in `sponsorPointsFor()` and `creditAccrual()`. Leave `currentRank()` and the repurchase obligation untouched (the obligation is snapshotted at cycle open and is not in scope). Pin: the same `(sponsor, cut-off date)` returns the same gate answer before and after a later month's rank qualification row is inserted.

**F-3 (Tasks 3, 4) — the verdict used must be frozen on the row.** `sponsor_repurchase_failed` (Task 4) is written for every Mentorship row, not only when the cap bites, and Task 3's gate refusal (sponsor failed, rank below royalty) is recorded too: write a `mentorship_bonus_results` row with `status = 'repurchase_gated'`, `msb_points = <points that would have accrued>`, `mb_gross_paise = 0` instead of returning `null` from `sponsorPointsFor()`. Reason: a sponsor who asks "why was I not paid on the 10th" must get an answer from the row, not from re-deriving a verdict that may since have changed; and the Input & Output report must show the gated points. The gated sponsor still leaves the day's **denominator** (the plan's reading stands) — only the record changes. Widen the `status` enum with the SQLite branch rule above.

**F-4 (Task 3) — a deferred sponsor is reserved at a stale verdict; say so, do not hide it.** When the sponsor's own evaluation was deferred that night (`gsb_cutoff_deferrals` open for the sponsor and date), the gate reads a stale verdict. Do not add machinery; record it: `details.sponsor_deferred = true` on the row (nullable boolean column `sponsor_verdict_stale`, same migration as Task 4), and add the case to the risk-register entry Task 12 writes. The exposure is bounded by the deferral cap (≤ 1% of the roster, ≥ 10) that already exists.

**F-5 (Task 4) — the daily cap must be order-independent and race-free in the engine, not in the lock.** `SELECT … FOR UPDATE` on a `SUM` does not stop a concurrent insert of a new row on SQLite (tests) and relies on gap locks on MySQL. The engine's real guard is that the compensation queue is one worker with `tries = 1` and the E3 in-flight guard blocks a manual trigger during the nightly. Keep the `lockForUpdate()` (harmless on MySQL) but pin the invariant where it matters: after the whole day is settled, `Σ mb_gross_paise` for a failed royalty sponsor on that date `≤ cap`, asserted in the Task 4 test by settling two sponsees in **both** orders. If `msb_royalty_failed_daily_cap_paise` is lowered mid-day by an admin, rows already written keep their cap: freeze `royalty_cap_paise` on each row (one more column in the Task 4 migration) and read the cap once per `creditAccrual()`.

**F-6 (Tasks 2, 5, 8) — a cap of 0 is a silent zero payout.** The registry entries allow `min 0`. An admin typing 0 would make every point worth ₹0 and the whole pool leftover, with no error anywhere. Two layers, per principle 1: the registry `min` becomes `100` (₹1) on all three cap settings so the value cannot be entered, and the accessors **throw** `RuntimeException("<key> must be ≥ 100 paise")` rather than clamp, so a bad row that reaches the engine by any other route stops the freeze before a write (the run recorder turns it into a failed run the digest reports). Never `max(…)`-clamp a money setting: a clamp pays a number nobody chose. Frozen rows already carry the cap they were priced with, so a later change never moves a frozen day or month — state this in each registry `impact` text ("Takes effect from the next freeze; frozen periods are unchanged").

**F-7 (Task 12, decision 5) — production history cannot be rebuilt month by month.** `MonthRebuilder::refusals()` refuses a month when the following month's monthly engines have run, so only the latest closed month is ever rebuildable through `compensation:rebuild-month`. The recompute runner (`compensation:recompute-all`) is dev/staging only (ADR-0014) and must stay so. Therefore: replay history on dev and staging with the recompute runner; on production make **no** history rebuild — the deploy changes only months frozen after it, and the pre-launch wipe (before 2026-11-10) removes the old-formula months. Record in the spec doc that production months before the deploy date show the per-rank-pool formula and the admin report must label them (Task 10: when a month has no `rank_monthly_passes` rows, render "priced under the per-rank pool rule in force before <deploy date>" instead of the pass summary).

**F-8 (Task 6) — the lifetime exclusion makes month M depend on every earlier month.** Under the old rule a GBB roster depended on M−1 only; now a rebuild of month K < M that changes who qualified for a rank changes M's GBB roster. The existing refusal (F-7) already forbids rebuilding K while M has run, so the ordering is enforced — but the recompute runner must replay months **oldest first**, which it does. Pin it: a test that runs July (rank 1 qualified), then September GBB, then asserts the September roster excludes the distributor; and a second that wipes and re-runs July with no qualification and asserts a fresh September run includes them. Add a one-line warning to the `MonthRebuilder` preview when a later month exists even if it has not run: "Growth Booster eligibility in later months depends on this month's rank qualifications."

**F-9 (Task 9) — integer arithmetic for the envelope.** `(int) round($turnoverPaise * $envelopeBp / 10_000)` goes through a float. Use `intdiv($turnoverPaise * $envelopeBp, 10_000)` with `max(0, …)` as `GrowthBoosterBonusService::freezeMonth()` does; PHP ints are 64-bit and 16 Cr BV × 2000 is 3.2 × 10¹⁴, far inside range. Pin the identities as a test, not only the worked examples: for every frozen month `Σ rank pool_paise + pass-2 leftover_paise = envelope_paise`, `pass-2 pool_paise = envelope − pass-1 payout`, every `payout_paise ≤ pool_paise`, and every roster row `gross_paise = points × point_value` of its pass. Run them over the A2, C1, D2 fixtures and over a random cohort (property test, 50 draws of cohort sizes 0–12 per rank and turnover −1 Cr … 20 Cr).

**F-10 (Task 9) — the pass rows are part of the frozen month.** `replacePrematureFreeze()`, `MonthRebuilder::wipe()`, `MonthRebuilder::plan()` row counts, `DerivedTables`, and `BonusCalculationSnapshots` must all carry `rank_monthly_passes` next to `rank_monthly_pools`. The plan lists the first four; add the snapshot and grep `rank_monthly_pools` across `app/` and `tests/` for any list it missed. Write passes and pools in the **same** transaction (the plan does) and pin: a freeze that throws after the pass rows are written leaves no pass rows (wrap the test in a forced exception on the 9th pool insert).

**F-11 (Task 11) — existing milestones need amounts and a release-rule change notice.** The migration adds `amount_paise default 0` and `tranche default 1`: existing pending milestones would show ₹0 and, under `qualification_count ≥ tranche`, become releasable earlier than under the old 1/2/3 rule. Backfill `amount_paise` from tranche 1 of the rank in the same migration (audit row per the rules above), and have the Admin lifetime-awards page flag rows whose `isReleasable()` changed from false to true at migration time (`released_rule_changed_at` timestamp, nullable) so the operator knows why a tranche is suddenly due. Delivered rows are never touched. The cash disbursement path, its constants, columns and the `applies_to_awards` setting are removed in Task 13 (user decision 2026-10-09); awards are merchandise only.

**F-12 (all engines) — operational fallbacks without a deploy.** Every rule in this plan can be neutralised from `/admin/compensation/plan-settings` or the settings registry if the client reverses a decision; document the exact value in the spec doc's "Where each number lives" table:

| Rule | Neutralise with |
|---|---|
| MSB ₹120 cap | `comp.msb.point_value_cap_paise` = 100 000 000 (₹10 lakh, effectively uncapped) |
| MSB failed-sponsor gate | `comp.msb.royalty_min_rank` = 1 (everyone is royalty: no gate, cap still applies) |
| MSB royalty cap | `comp.msb.royalty_failed_daily_cap_paise` = 100 000 000 |
| GBB 4% | `comp.gbb.pool_rate_bp` = 500 |
| GBB ₹240 cap | `comp.gbb.point_value_cap_paise` = 100 000 000 |
| Rank two-pass | `comp.rank.first_pass_max_rank` = 9 (one pass, one pool, same cap) — **not** the old per-rank split, which is gone |
| Rank ₹200 cap | `comp.rank.point_value_cap_paise` = 100 000 000 |

The GBB lifetime exclusion and the GBB verdict gate have **no** setting; they are code. If the client wants them reversible, that is a follow-up decision — ask before adding a flag; do not add one silently (feature-flag zero-trace rule).

### Verification added to Task 12
- **Step 2a — invariants file.** `tests/Modules/Compensation/PlanInvariantsTest.php` holds F-5 (cap ≤, both orders), F-9 (pool identities + property draws), F-10 (atomic freeze) and the Task 7 denominator identity (`gbb_monthly_pools.total_agp = Σ payable agp`). It runs in the module suite.
- **Step 2b — before/after reconciliation on staging.** Before the recompute replay: `select bonus_type, month, sum(amount_paise)` of wallet credits per month into a file. After: the same query; the diff per month must be explained entirely by the rule changes (rank formula, GBB rate/cap/exclusion/gate, MSB caps/gates). Attach both files to the spec doc commit. The `RepurchaseShortfallGuard` post-replay reconciliation (memory: detect-after) must report zero residual.
- **Step 2c — negative and empty periods.** Run the recompute on a staging window that includes the known refund-heavy day and the known empty-roster month; assert no negative `point_value_paise`, no negative `payout_paise`, no `gross_paise < 0`, no wallet credit of 0 written.

---

### Task 1: Repurchase due date = start + (cycle_days − 1); re-date open cycles; pin Easy Purchase wallet rule

**Files:**
- Modify: `app/app/Modules/Compensation/Services/RepurchaseCycleService.php:22-30` (class docblock) and `:653-658` (`openCycle`)
- Modify: `app/app/Modules/Compensation/Services/CompensationPlanSettingsService.php:110-114` (comment on `comp.repurchase.cycle_days`)
- Modify: `app/app/Modules/Admin/Http/Controllers/AdminSettingsController.php:1375` (description of `comp.repurchase.cycle_days`)
- Modify: `app/app/Modules/Compensation/Services/DTOs/RepurchaseCycleCard.php:~180` (comment that explains the window length)
- Create: `app/app/Modules/Compensation/Database/Migrations/2026_10_09_100000_redate_open_repurchase_cycles_to_29_days.php`
- Modify: `docs/compensation/repurchase-client-examples-2026-09-07.md` §1 "Length" row
- Test: `app/tests/Modules/Compensation/RepurchaseCycleServiceTest.php` (existing expectations), `app/tests/Modules/Compensation/RepurchaseCycleDueDateTest.php` (new), `app/tests/Modules/Commerce/EasyPurchaseWalletTest.php` (new)

**Interfaces:**
- Produces: unchanged signatures. `openCycle()` now writes `due_date = start + (cycle_days − 1)`.

- [x] **Step 1: Write the failing due-date test**

```php
<?php

declare(strict_types=1);

use App\Modules\Compensation\Models\RepurchaseCycle;
use App\Modules\Compensation\Services\RepurchaseCycleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

beforeEach(fn () => seedCompensationPlanTables());

it('opens the first cycle with due_date = anchor + 29 days (client example 14 Feb → 15 Mar)', function (): void {
    $d = uiDistributor();
    uiPaidSelfOrder($d['id'], 30_000, Carbon::parse('2026-02-05 10:00:00'));
    uiPaidSelfOrder($d['id'], 20_000, Carbon::parse('2026-02-10 10:00:00'));
    uiPaidSelfOrder($d['id'], 10_000, Carbon::parse('2026-02-14 10:00:00'));

    $cycle = app(RepurchaseCycleService::class)->evaluate($d['id'], Carbon::parse('2026-02-20'));

    expect($cycle)->not->toBeNull()
        ->and($cycle->cycle_start_date->toDateString())->toBe('2026-02-14')
        ->and($cycle->due_date->toDateString())->toBe('2026-03-15');
});

it('a rolled-over cycle also spans 30 calendar days inclusive', function (): void {
    $d = uiDistributor();
    uiPaidSelfOrder($d['id'], 60_000, Carbon::parse('2026-07-07 10:00:00'));
    app(RepurchaseCycleService::class)->evaluate($d['id'], Carbon::parse('2026-07-08'));

    $first = RepurchaseCycle::where('distributor_id', $d['id'])->orderBy('id')->first();
    expect($first->due_date->toDateString())->toBe('2026-08-05');
});
```

- [x] **Step 2: Run it to verify it fails**

Run (from `app/`, with the test-DB overrides from `docs/local-dev-environment.md`): `php artisan test tests/Modules/Compensation/RepurchaseCycleDueDateTest.php`
Expected: FAIL — due dates come back one day late (`2026-03-16`, `2026-08-06`).

- [x] **Step 3: Change `openCycle()`**

```php
        // 30 days INCLUSIVE of the start day (client 2026-10-09: 14 Feb → 15 Mar),
        // so the verdict day is cycle_days − 1 after the anchor.
        $due = $start->copy()->addDays($this->plan->repurchaseCycleDays() - 1);
```

Update the class docblock (lines 22–30) to say "start 14 Feb, due 15 Mar: the start day is day 1 and the due date is cycle_days − 1 days after it", and the `SCALAR_DEFAULTS` comment above `comp.repurchase.cycle_days` to "due_date = start + days − 1 (14 Feb → 15 Mar)". Update the `AdminSettingsController` description for the same key and the `RepurchaseCycleCard.php` comment near line 180 to the same wording. Replace the §1 "Length" row of `docs/compensation/repurchase-client-examples-2026-09-07.md` with: `**due_date = start + 29 days** (30 days inclusive; client 2026-10-09 supersedes the +30 reading).`

- [x] **Step 4: Write the re-dating migration**

```php
<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Client 2026-10-09: the repurchase window is 30 days INCLUSIVE of the
     * anchor day (14 Feb → 15 Mar), so due_date = start + cycle_days − 1. The
     * 2026-09-07 engine wrote start + cycle_days. Pull every still-OPEN cycle's
     * due date back by one day; resolved cycles are history and never move.
     * An open cycle whose new due date is already past is left for the next
     * repurchase:evaluate run to resolve.
     */
    public function up(): void
    {
        // Read first, so the audit row lists exactly what moved and down()
        // can restore exactly those ids (fail-safe rule: down() never guesses).
        $open = DB::table('repurchase_cycles')
            ->whereNull('resolved_at')
            ->get(['id', 'distributor_id', 'due_date']);

        $today = now()->toDateString();
        $moved = [];
        $nowPastDue = [];

        foreach ($open as $row) {
            $before = Carbon::parse($row->due_date)->toDateString();
            $after = Carbon::parse($row->due_date)->subDay()->toDateString();
            DB::table('repurchase_cycles')->where('id', $row->id)->update(['due_date' => $after]);
            $moved[] = ['id' => (int) $row->id, 'distributor_id' => (int) $row->distributor_id, 'before' => $before, 'after' => $after];
            if ($after < $today) {
                $nowPastDue[] = (int) $row->id; // F-1: these can flip a verdict retroactively
            }
        }

        AuditLog::create([
            'actor_id' => null,
            'action' => 'plan.migration.redate_open_repurchase_cycles_to_29_days',
            'subject_type' => 'repurchase_cycle',
            'subject_id' => 0,
            'details' => [
                'moved_count' => count($moved),
                'now_past_due_ids' => $nowPastDue,
                'rows' => count($moved) <= 500 ? $moved : array_slice($moved, 0, 500),
            ],
        ]);
    }

    public function down(): void
    {
        throw new RuntimeException(
            'Restore repurchase_cycles.due_date from the plan.migration.redate_open_repurchase_cycles_to_29_days '
            .'audit row (ids and before values are listed there). A blanket +1 day would also move cycles opened '
            .'under the new rule.'
        );
    }
};
```
(Import `App\Modules\Compliance\Models\AuditLog` — grep for the model the other migrations in this folder import — and `Illuminate\Support\Carbon`.)

Add a migration test modelled on `tests/Modules/Compensation/RedateOpenRepurchaseCyclesMigrationTest.php` (copy its structure for loading and running a single migration file): one open cycle `2026-07-07 → 2026-08-06` becomes `2026-08-05`; one resolved cycle (`resolved_at` set) keeps `2026-08-06`; the audit row lists the moved id with before/after; `down()` throws. Also add to `RepurchaseCycleDueDateTest.php`: `evaluate($d, 2026-03-15)` leaves the 14 Feb cycle `active`; `evaluate($d, 2026-03-16)` resolves it (verdict taken the day after the due date, F-1c).

- [x] **Step 5: Pin the Easy Purchase wallet rule**

Create `app/tests/Modules/Commerce/EasyPurchaseWalletTest.php`. Look at `tests/Pest.php::uiCustomerFor()` and an existing storefront checkout test (grep `tests/Modules/Commerce` for `checkout.place` or `CheckoutController`) for the request shape, then assert: a customer (no distributor) checking out through `?ref=<ADN>` leaves `walletService->repurchaseWalletBalancePaise(<referrer id>)` unchanged and writes no `repurchase_wallet_used` ledger entry for the referrer.

```php
it('an Easy Purchase order never debits the referring distributor\'s repurchase wallet', function (): void {
    $sponsor = uiDistributor();
    uiRepurchaseWallet($sponsor['id'], 50_000);
    // ...place a guest/customer order through the sponsor's ref link using the
    // same helpers the existing storefront checkout test uses...
    expect(app(\App\Modules\Compensation\Services\WalletService::class)
        ->repurchaseWalletBalancePaise($sponsor['id']))->toBe(50_000);
    expect(DB::table('wallet_ledger_entries')
        ->where('distributor_id', $sponsor['id'])
        ->where('type', 'repurchase_wallet_used')->exists())->toBeFalse();
});
```

- [x] **Step 6: Run the repurchase suite and fix stale expectations**

Run: `php artisan test tests/Modules/Compensation --filter=Repurchase`
Expected: the new tests PASS; some existing tests in `RepurchaseCycleServiceTest.php`, `RepurchaseCycleCardTest.php`, `RepurchaseEvaluateCommandTest.php` assert `+30` due dates — change each expected date back by one day (and any `'2026-08-06'` style fixture that the service itself is expected to produce). Do not change fixtures that are inserted directly as rows.

- [x] **Step 7: Commit**

```bash
git add -A app/app/Modules/Compensation app/app/Modules/Admin app/tests docs/compensation
git commit -m "fix(repurchase): the 30-day window is inclusive of the anchor day (due = start + 29)

Client 2026-10-09 example: 14 Feb → 15 Mar. Open cycles re-dated by migration;
resolved cycles untouched. Pins that an Easy Purchase order never debits the
referrer's repurchase wallet.

Compliance-Review: compliance-officer
Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>"
```

---

### Task 2: MSB point-value cap (₹120)

**Files:**
- Modify: `app/app/Modules/Compensation/Services/CompensationPlanSettingsService.php` (`SCALAR_DEFAULTS` near line 90; new accessor after `msbPoolRateBp()` line 251)
- Modify: `app/database/seeders/SettingsSeeder.php:87` (add key)
- Modify: `app/app/Modules/Admin/Http/Controllers/AdminSettingsController.php:1260` (registry entry after `comp.msb.pool_rate_bp`)
- Create: `app/app/Modules/Compensation/Database/Migrations/2026_10_09_100100_add_point_value_cap_to_msb_daily_pools.php`
- Modify: `app/app/Modules/Compensation/Models/MsbDailyPool.php` (`$fillable`, `casts()`)
- Modify: `app/app/Modules/Compensation/Services/MsbDailyPoolService.php:69-105`
- Modify: `resources/help/compensation.md` (MSB section: add the cap sentence)
- Test: `app/tests/Modules/Compensation/MsbDailyPoolServiceTest.php`

**Interfaces:**
- Produces: `CompensationPlanSettingsService::msbPointValueCapPaise(): int` (default 12_000). `msb_daily_pools.point_value_cap_paise` (unsigned big int) and `raw_point_value_paise` (the uncapped floored value).

- [x] **Step 1: Failing tests**

Append to `MsbDailyPoolServiceTest.php` (reuse the file's existing helper that seeds company BV for a date):

```php
it('caps the MSB point value at comp.msb.point_value_cap_paise (client example 1: 150 → 120)', function (): void {
    // 50L BV × 3% = 1,50,000; 1,000 points → raw ₹150 → capped ₹120
    seedMsbCompanyBv(500_000_000, Carbon::parse('2026-07-10 12:00:00')); // 50L BV in paise; use the file's helper name
    $pool = app(MsbDailyPoolService::class)->freezePoolForDate(Carbon::parse('2026-07-10'), 1_000);

    expect($pool->raw_point_value_paise)->toBe(15_000)
        ->and($pool->point_value_paise)->toBe(12_000)
        ->and($pool->point_value_cap_paise)->toBe(12_000)
        ->and($pool->payout_paise)->toBe(12_000 * 1_000)
        ->and($pool->leftover_paise)->toBe(15_000_000 - 12_000_000);
});

it('leaves a sub-cap value alone (client example 2: 108.5383 → 108)', function (): void {
    seedMsbCompanyBv(500_000_000, Carbon::parse('2026-07-11 12:00:00'));
    $pool = app(MsbDailyPoolService::class)->freezePoolForDate(Carbon::parse('2026-07-11'), 1_382);

    expect($pool->point_value_paise)->toBe(10_800)->and($pool->raw_point_value_paise)->toBe(10_800);
});

it('a negative-BV day freezes a zero value, never a negative one', function (): void {
    seedMsbCompanyBv(-100_000_00, Carbon::parse('2026-07-12 12:00:00'));
    $pool = app(MsbDailyPoolService::class)->freezePoolForDate(Carbon::parse('2026-07-12'), 40);

    expect($pool->point_value_paise)->toBe(0)->and($pool->payout_paise)->toBe(0)->and($pool->leftover_paise)->toBe(0);
});
```

(50L BV in paise is `5_000_000 * 100 = 500_000_000`; write the literal as `500_000_000`.)

- [x] **Step 2: Run** `php artisan test tests/Modules/Compensation/MsbDailyPoolServiceTest.php` → FAIL (unknown column / method).

- [x] **Step 3: Setting, accessor, seeder, registry**

`SCALAR_DEFAULTS`: 
```php
        // Client 2026-10-09: the highest MB point value the company pays.
        // ₹120 = 12,000 paise. Pool ÷ points above this is capped; the excess
        // stays with the company as leftover.
        'comp.msb.point_value_cap_paise' => 12_000,
```
Accessor:
```php
    /** Ceiling on the daily MSB point value (client 2026-10-09: ₹120). Raw value; the freeze refuses anything under ₹1. */
    public function msbPointValueCapPaise(): int
    {
        return $this->scalarInt('comp.msb.point_value_cap_paise');
    }
```
`SettingsSeeder`: `'comp.msb.point_value_cap_paise' => '12000',           // ₹120 ceiling per MB point`.
`AdminSettingsController` registry (same shape as `comp.msb.pool_rate_bp`): group `compensation_plan`, feature `MentorshipBonusFeature::class`, label `MSB point value cap (paise)`, description `Highest rupee value one Mentorship Bonus point can be worth on a day. 12000 = ₹120. When the day's pool ÷ points exceeds it, every earner is paid at the cap and the difference stays with the company.`, impact `Lowers what Mentorship earners receive on strong days. Takes effect from the next daily cut-off.`, type `int`, min 0, max 1_000_000, default `'12000'`.

- [x] **Step 4: Migration + model**

```php
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('msb_daily_pools', function (Blueprint $table): void {
            $table->unsignedBigInteger('raw_point_value_paise')->nullable()->after('point_value_paise');
            $table->unsignedBigInteger('point_value_cap_paise')->nullable()->after('raw_point_value_paise');
        });
    }

    public function down(): void
    {
        Schema::table('msb_daily_pools', function (Blueprint $table): void {
            $table->dropColumn(['raw_point_value_paise', 'point_value_cap_paise']);
        });
    }
};
```
Add both to `MsbDailyPool::$fillable` and cast as `'integer'`.

- [x] **Step 5: Apply the cap in `freezePoolForDate()`** (guard first, then arithmetic, then write)

```php
        $capPaise = $this->plan->msbPointValueCapPaise();
        if ($capPaise < 100) {
            // Fail-safe principle 1 / F-6: a sub-rupee cap would pay every sponsor
            // ₹0 for the day and look like a quiet day. Stop the night instead.
            throw new \RuntimeException('comp.msb.point_value_cap_paise must be at least 100 paise (₹1); refusing to freeze the MSB pool for '.$date->toDateString());
        }

        $rawValuePaise = Money::floorRupee($poolPaise, $totalPoints);
        // Client 2026-10-09: the point value never exceeds the company's cap.
        $valuePaise = min($rawValuePaise, $capPaise);

        $payoutPaise = $valuePaise * $totalPoints;
```
Add `'raw_point_value_paise' => $rawValuePaise, 'point_value_cap_paise' => $capPaise,` to the `create([...])` and to `$details`. Registry entry: `min` 100 (₹1), not 0. Add a test: with the setting set to `0`, `freezePoolForDate()` throws and writes no `msb_daily_pools` row.

- [x] **Step 6: Run** the file → PASS. Add to `resources/help/compensation.md` under the Mentorship heading: "The day's point value is capped at ₹120 (setting *MSB point value cap*); the excess stays with the company."

- [x] **Step 7: Commit** `feat(msb): cap the daily MB point value at ₹120` with the compliance trailer.

---

### Task 3: Mentorship gate — a failed sponsor up to rank 5 earns no MB points that day

**Files:**
- Modify: `app/app/Modules/Compensation/Services/MentorshipBonusService.php:48-60` (constructor), `:62` (`accrueForSponsee`), `:97-146` (`reservedPointsFor`, `sponsorPointsFor`)
- Modify: `app/app/Modules/Compensation/Console/Commands/GsbDailyCutoffCommand.php:521-523` (`reservedPointsFor` call gains the date)
- Modify: `app/app/Modules/Compensation/Services/CompensationPlanSettingsService.php` (new scalar `comp.msb.royalty_min_rank`, default 6) + `SettingsSeeder` + `AdminSettingsController` registry
- Modify: `resources/help/compensation.md`
- Test: `app/tests/Modules/Compensation/MentorshipBonusServiceTest.php`

**Interfaces:**
- Consumes: `IncomeEligibilityService::verdictAsOf(int, Carbon): RepurchaseVerdict` (`->isEligible()`), `RepurchaseCycleService::currentRank(int): int`.
- Produces: `MentorshipBonusService::reservedPointsFor(int $sponseeId, int $slab, Carbon $cutoffDate): int`; `CompensationPlanSettingsService::msbRoyaltyMinRank(): int`.

- [x] **Step 1: Failing tests** (append; reuse the file's existing helpers for a sponsor/sponsee pair and a credited `GsbCutoffResult`; seed a failed cycle the way `RankBonusServiceTest` / `IncomeEligibilityService` tests do — a resolved `RepurchaseCycle` with `due_date` before the cut-off and `fulfilled_on` null, `status` failed — and enable `RepurchaseEngineFeature`):

```php
it('awards no MB points to a sponsor (rank ≤ 5) who is failed on the cut-off day', function (): void {
    Feature::for(null)->activate(RepurchaseEngineFeature::class);
    [$sponsor, $sponsee] = msbSponsorPair(); // existing helper in this file
    seedFailedRepurchaseCycle($sponsor, dueDate: '2026-08-06'); // see IncomeEligibility tests for the row shape
    $result = msbCreditedCutoff($sponsee, slab: 1, date: '2026-08-10');

    expect(app(MentorshipBonusService::class)->reservedPointsFor($sponsee, 1, Carbon::parse('2026-08-10')))->toBe(0)
        ->and(app(MentorshipBonusService::class)->accrueForSponsee($sponsee, $result))->toBeNull();
});

it('still accrues for a failed sponsor at rank 6 or above (Mentorship Royalty)', function (): void {
    Feature::for(null)->activate(RepurchaseEngineFeature::class);
    [$sponsor, $sponsee] = msbSponsorPair();
    seedFailedRepurchaseCycle($sponsor, dueDate: '2026-08-06');
    seedRankQualification($sponsor, 6, '2026-07-01');
    $result = msbCreditedCutoff($sponsee, slab: 1, date: '2026-08-10');

    expect(app(MentorshipBonusService::class)->accrueForSponsee($sponsee, $result)?->points)->toBe(21);
});

it('accrues normally for an eligible sponsor', function (): void {
    [$sponsor, $sponsee] = msbSponsorPair();
    $result = msbCreditedCutoff($sponsee, slab: 2, date: '2026-08-10');
    expect(app(MentorshipBonusService::class)->accrueForSponsee($sponsee, $result)?->points)->toBe(18);
});
```

- [x] **Step 2: Run** → FAIL (signature / gate missing).

- [x] **Step 3: Setting** `comp.msb.royalty_min_rank` (default `6`) in `SCALAR_DEFAULTS`, `SettingsSeeder` (`'6'`), registry (label `Mentorship Royalty from rank`, description `From this rank the Mentorship Bonus becomes Mentorship Royalty: it is paid even while the sponsor's repurchase condition is failed, subject to the daily royalty cap. Below it, a failed sponsor earns no MB points that day.`, type int, min 1, max 9, default `'6'`), accessor `msbRoyaltyMinRank(): int`.

- [x] **Step 4: Inject and gate**

Constructor: add `private readonly IncomeEligibilityService $eligibility, private readonly RepurchaseCycleService $cycles,`.

`reservedPointsFor(int $sponseeId, int $slab, Carbon $cutoffDate): int` → `return $this->sponsorPointsFor($sponseeId, $slab, $cutoffDate)[1] ?? 0;`

`accrueForSponsee()` passes `Carbon::parse($cutoffResult->cutoff_date)` (check the property name on `GsbCutoffResult`; it is the `cutoff_date` column).

In `sponsorPointsFor(int $sponseeId, int $slab, Carbon $cutoffDate)`, after the personal-BV gate:

```php
        // Client 2026-10-09: a sponsor who is failed on their repurchase
        // condition on the cut-off day earns nothing from their sponsees' slabs
        // — unless they hold the royalty rank (6+), where the accrual stands and
        // only the daily royalty cap applies at credit time (creditAccrual()).
        // Failed sub-royalty sponsors leave the day's denominator like the BV
        // gate above: they can never be paid for the day.
        if (! $this->eligibility->verdictAsOf((int) $sponsorId, $cutoffDate)->isEligible()
            && $this->cycles->rankAsOf((int) $sponsorId, $cutoffDate) < $this->plan->msbRoyaltyMinRank()) { // F-2: as-of the cut-off date, never currentRank()
            return null;
        }
```

Update the call in `GsbDailyCutoffCommand.php:522` to `->reservedPointsFor($distributorId, $computation->slabIndex, $date)`. Grep for any other caller: `grep -rn "reservedPointsFor(" app/`.

**Scale (10-lakh roster):** the gate adds one verdict and one rank lookup per sponsor. The command already warms `IncomeEligibilityService::warmCycleCache($ids)` for the distributors being cut off (line 72–73); extend it to their sponsors in the same place — one query `DB::table('sponsorship')->whereIn('distributor_id', $ids)->pluck('sponsor_id')`, merged into the warmed id list — and add `RepurchaseCycleService::warmRanksAsOf(array $ids, Carbon $date)` next to the new `rankAsOf()` (F-2) so the per-sponsor rank read is one query per chunk, not one per accrual. Pin with a query-count test in the style of `RepurchaseCycleStartedAtTest` ("reads the anchor only once"): 50 sponsees under 5 sponsors → the MB gate issues ≤ 2 queries against `repurchase_cycles` and ≤ 2 against `rank_qualifications`.

- [x] **Step 5: Run** the MSB tests and `GsbDailyCutoffCommandTest.php` → PASS. Help doc: under Mentorship add "A sponsor below rank 6 who is failed on their repurchase condition on a cut-off day earns no Mentorship points that day."

- [x] **Step 6: Commit** `feat(msb): failed sponsors below the royalty rank earn no MB points` with the compliance trailer.

---

### Task 4: Mentorship Royalty daily cap (₹3,600 while failed, rank 6+)

**Files:**
- Modify: `app/app/Modules/Compensation/Services/MentorshipBonusService.php:148-250` (`creditAccrual`)
- Modify: `app/app/Modules/Compensation/Services/CompensationPlanSettingsService.php` (`comp.msb.royalty_failed_daily_cap_paise` = 360_000) + `SettingsSeeder` + registry
- Create: `app/app/Modules/Compensation/Database/Migrations/2026_10_09_100200_add_royalty_cap_columns_to_mentorship_bonus_results.php`
- Modify: `app/app/Modules/Compensation/Models/MentorshipBonusResult.php` (`$fillable`, `casts()`)
- Modify: `app/app/Modules/Compensation/Http/Controllers/Admin/AdminMsbInputOutputController.php` and `resources/views/admin/compensation/msb-input-output/index.blade.php` — add a "Royalty cap withheld" total column (grep the controller for where it sums `mb_gross_paise` and add the same sum for `royalty_cap_withheld_paise`).
- Modify: `resources/help/compensation.md`
- Test: `app/tests/Modules/Compensation/MentorshipBonusServiceTest.php`, `AdminMsbInputOutputTest.php`

**Interfaces:**
- Produces: `mentorship_bonus_results.sponsor_repurchase_failed` (bool), `royalty_cap_withheld_paise` (unsigned big int, default 0). `CompensationPlanSettingsService::msbRoyaltyFailedDailyCapPaise(): int`.

- [x] **Step 1: Failing test**

```php
it('caps a failed rank-6+ sponsor at ₹3,600 across all accruals of the day, withholding the rest', function (): void {
    Feature::for(null)->activate(RepurchaseEngineFeature::class);
    [$sponsor, $a] = msbSponsorPair();
    $b = msbSponseeFor($sponsor); // second sponsee under the same sponsor (add helper if missing)
    seedFailedRepurchaseCycle($sponsor, dueDate: '2026-08-06');
    seedRankQualification($sponsor, 6, '2026-07-01');
    $pool = MsbDailyPool::create([
        'cutoff_date' => '2026-08-10', 'company_bv_paise' => 1, 'pool_rate_bp' => 300, 'pool_paise' => 1,
        'total_points' => 42, 'point_value_paise' => 12_000, 'raw_point_value_paise' => 12_000,
        'point_value_cap_paise' => 12_000, 'payout_paise' => 42 * 12_000, 'leftover_paise' => 0,
    ]);
    $svc = app(MentorshipBonusService::class);
    $r1 = $svc->creditAccrual($svc->accrueForSponsee($a, msbCreditedCutoff($a, 1, '2026-08-10')), $pool); // 21 × 120 = 2,520
    $r2 = $svc->creditAccrual($svc->accrueForSponsee($b, msbCreditedCutoff($b, 1, '2026-08-10')), $pool); // 2,520 → only 1,080 fits

    expect($r1->mb_gross_paise)->toBe(252_000)->and($r1->royalty_cap_withheld_paise)->toBe(0)
        ->and($r2->mb_gross_paise)->toBe(108_000)->and($r2->royalty_cap_withheld_paise)->toBe(144_000)
        ->and($r2->sponsor_repurchase_failed)->toBeTrue();
    expect(WalletLedgerEntry::where('distributor_id', $sponsor)->where('type', 'mb_credit')->sum('amount_paise'))
        ->toBe(360_000); // check the ledger column name used by the file's other tests
});

it('does not cap an eligible rank-6 sponsor', function (): void { /* same setup without the failed cycle; expect 2 × 252_000 */ });
```

- [x] **Step 2: Run** → FAIL.

- [x] **Step 3: Setting** `comp.msb.royalty_failed_daily_cap_paise` default `360_000` (₹3,600/day = ₹1,08,000/30 days) in all three places; accessor `msbRoyaltyFailedDailyCapPaise(): int`. Registry label `Mentorship Royalty daily cap while failed (paise)`, description `Most a rank-6+ sponsor can earn from Mentorship on one cut-off day while their repurchase condition is failed. 360000 = ₹3,600 (₹1,08,000 a month). Not applied while the condition is met.`

- [x] **Step 4: Migration**

```php
        Schema::table('mentorship_bonus_results', function (Blueprint $table): void {
            $table->boolean('sponsor_repurchase_failed')->default(false)->after('status');
            $table->boolean('sponsor_verdict_stale')->default(false)->after('sponsor_repurchase_failed'); // F-4
            $table->unsignedBigInteger('royalty_cap_paise')->nullable()->after('sponsor_verdict_stale');    // F-5: cap frozen on the row
            $table->unsignedBigInteger('royalty_cap_withheld_paise')->default(0)->after('royalty_cap_paise');
            // Stale since the 2026-07-30 points engine: always written null, read nowhere.
            $table->dropColumn(['mb_rate_pct', 'sponsee_cumulative_gsb_paise']);
        });
```
`$fillable` + casts (`'boolean'`, `'integer'`); remove `mb_rate_pct` and `sponsee_cumulative_gsb_paise` from `$fillable`, `casts()`, the `@property` docblock and the two `=> null` lines in `creditAccrual()`. `down()` re-adds the two legacy columns nullable and drops the four new ones. Run `grep -rn "mb_rate_pct\|sponsee_cumulative_gsb_paise" app resources tests` → only historical migrations remain.

- [x] **Step 5: Apply in `creditAccrual()`**, inside the transaction before `MentorshipBonusResult::create`:

```php
            $cutoffDate = Carbon::parse($accrual->cutoffDate);
            $sponsorFailed = ! $this->eligibility->verdictAsOf($accrual->sponsorId, $cutoffDate)->isEligible();
            $withheld = 0;

            if ($sponsorFailed) {
                // Client 2026-10-09: Mentorship Royalty while failed is capped per
                // day. Everything already credited to this sponsor for the day
                // counts against the cap; the excess is withheld for good.
                // lockForUpdate serialises two accruals of the same sponsor.
                $alreadyPaid = (int) MentorshipBonusResult::query()
                    ->where('sponsor_id', $accrual->sponsorId)
                    ->whereDate('cutoff_date', $cutoffDate->toDateString())
                    ->lockForUpdate()
                    ->sum('mb_gross_paise');
                $capPaise = $this->plan->msbRoyaltyFailedDailyCapPaise();
                if ($capPaise < 100) {
                    throw new \RuntimeException('comp.msb.royalty_failed_daily_cap_paise must be at least 100 paise; refusing to credit Mentorship for '.$cutoffDate->toDateString());
                }
                $room = max(0, $capPaise - $alreadyPaid);
                $withheld = max(0, $mbGross - $room);
                $mbGross -= $withheld;

                if ($withheld > 0) {
                    // Fail-safe principle 5: money a distributor did not get is a
                    // statutory record, like msb.credit.zero_value above.
                    AuditLog::create([
                        'action' => 'msb.royalty.cap_withheld',
                        'subject_type' => 'distributor',
                        'subject_id' => $accrual->sponsorId,
                        'details' => ['cutoff_date' => $cutoffDate->toDateString(), 'sponsee_id' => $accrual->sponseeId, 'cap_paise' => $capPaise, 'already_paid_paise' => $alreadyPaid, 'withheld_paise' => $withheld],
                    ]);
                }
            }
```
The 10% credit-time repurchase deduction (`creditWithRepurchaseDeduction`) runs on the **capped** gross: cap first, then deduction. State this in the help doc.
Then add `'sponsor_repurchase_failed' => $sponsorFailed, 'royalty_cap_withheld_paise' => $withheld, 'royalty_cap_paise' => $sponsorFailed ? $capPaise : null,` to the `create([...])` (F-5: the cap is frozen on the row; F-3/F-4: `sponsor_repurchase_failed` is written on every row and `sponsor_verdict_stale` when the sponsor has an open deferral for the date — both columns in this task's migration). `$mbGross` must become non-`use`-by-value: move its computation inside the closure or pass by reference (`use (&$mbGross)` is acceptable; cleaner is to compute inside).

- [x] **Step 6: Report + help.** In `AdminMsbInputOutputController` add a `royalty_withheld_paise` total beside the gross total; show it in the blade as "Royalty cap withheld" with `IndianNumber::format`. Help: "From rank 6 the Mentorship Bonus is Mentorship Royalty: it continues while the repurchase condition is failed, capped at ₹3,600 a day; the excess is withheld."

- [x] **Step 7: Run** `php artisan test tests/Modules/Compensation --filter=Msb` and `--filter=Mentorship` → PASS. **Commit** `feat(msb): Mentorship Royalty daily cap for failed rank-6+ sponsors` with the compliance trailer.

---

### Task 5: GBB pool 4%, point-value cap ₹240, retire the per-distributor AGP cap

**Files:**
- Modify: `app/app/Modules/Compensation/Services/CompensationPlanSettingsService.php:91-92` (defaults), `:265-273` (remove `gbbAgpCap()`, add `gbbPointValueCapPaise()`)
- Modify: `app/database/seeders/SettingsSeeder.php:88-89`
- Modify: `app/app/Modules/Admin/Http/Controllers/AdminSettingsController.php:1270-1290` (update `comp.gbb.pool_rate_bp` default/description; replace the `comp.gbb.agp_cap` entry with `comp.gbb.point_value_cap_paise`)
- Create: `app/app/Modules/Compensation/Database/Migrations/2026_10_09_100300_update_gbb_settings_and_add_point_value_cap.php`
- Modify: `app/app/Modules/Compensation/Models/GbbMonthlyPool.php`, `GbbMonthlyResult.php:93` (comment)
- Modify: `app/app/Modules/Compensation/Services/GrowthBoosterBonusService.php:29-30` (docblock), `:200-212` (freeze), `:742-745` (drop the cap)
- Modify: `resources/help/compensation.md`
- Test: `app/tests/Modules/Compensation/GrowthBoosterBonusServiceTest.php`, `CompensationPlanSettingsServiceTest.php`, `AdminPlanSettingsTest.php` (if it lists `agp_cap`)

**Interfaces:**
- Produces: `gbbPointValueCapPaise(): int` (default 24_000); `gbb_monthly_pools.raw_point_value_paise`, `point_value_cap_paise`. `gbbAgpCap()` is **removed** — grep `gbbAgpCap\|agp_cap` across `app/`, `resources/`, `tests/`, `database/` and remove every use.

- [ ] **Step 1: Failing tests** (use the file's existing helper that seeds credited slab cut-offs for a distributor and the company-BV helper):

```php
it('caps the GBB point value at ₹240 (client example 1: 320 → 240)', function (): void {
    // 50L BV × 4% = 2,00,000; 625 AGP → raw 320 → 240
    seedGbbCompanyBv(500_000_000, Carbon::parse('2026-07-15'));
    seedGbbAgp(625, '2026-07'); // spread across distributors with the file's helper; total must be 625
    $out = app(GrowthBoosterBonusService::class)->runForMonth(Carbon::parse('2026-07-01'));
    $pool = GbbMonthlyPool::where('month_start', '2026-07-01')->firstOrFail();

    expect($pool->raw_point_value_paise)->toBe(32_000)->and($pool->point_value_paise)->toBe(24_000)
        ->and($pool->pool_rate_bp)->toBe(400);
});

it('no longer caps a single distributor\'s AGP at 120', function (): void {
    // 11 slab-1 cut-offs = 132 AGP in the month
    // ...seed 11 credited slab-1 cut-offs for one distributor, run, assert agp_earned == 132
});
```

- [ ] **Step 2: Run** → FAIL.

- [ ] **Step 3: Settings.** `SCALAR_DEFAULTS`: `'comp.gbb.pool_rate_bp' => 400,` remove `'comp.gbb.agp_cap'`, add `'comp.gbb.point_value_cap_paise' => 24_000,`. `SettingsSeeder`: `'400'`, remove `agp_cap`, add `'comp.gbb.point_value_cap_paise' => '24000',`. Registry: pool rate description `… 400 = 4%.`, default `'400'`; replace the `agp_cap` entry with `comp.gbb.point_value_cap_paise` (label `Growth Booster point value cap (paise)`, description `Highest rupee value one Growth Booster point can be worth in a month. 24000 = ₹240. Pool ÷ points above it is capped and the difference stays with the company.`, min 0, max 1_000_000, default `'24000'`). Accessor:

```php
    /** Ceiling on the monthly GBB point value (client 2026-10-09: ₹240). Raw value; the freeze refuses anything under ₹1. */
    public function gbbPointValueCapPaise(): int
    {
        return $this->scalarInt('comp.gbb.point_value_cap_paise');
    }
```
Registry `min` for the cap is `100`, not 0.

- [ ] **Step 4: Migration** (settings rows + pool columns):

```php
    public function up(): void
    {
        // Client 2026-10-09: GBB pool 5% → 4%. Only move a row still at the old
        // default so an admin override is kept.
        DB::table('settings')->where('key', 'comp.gbb.pool_rate_bp')->where('value', '500')->update(['value' => '400']);
        // The per-distributor AGP cap is retired; only the point-value cap remains.
        DB::table('settings')->where('key', 'comp.gbb.agp_cap')->delete();

        Schema::table('gbb_monthly_pools', function (Blueprint $table): void {
            $table->unsignedBigInteger('raw_point_value_paise')->nullable()->after('point_value_paise');
            $table->unsignedBigInteger('point_value_cap_paise')->nullable()->after('raw_point_value_paise');
        });
    }
```
(Check the settings table's key/value column names in `SettingsSeeder` first.) `down()` drops the two columns; the setting changes are not reversed.

- [ ] **Step 5: Engine.** In `freezeMonth()`, before `DB::transaction` opens:
```php
        $capPaise = $this->plan->gbbPointValueCapPaise();
        if ($capPaise < 100) {
            throw new \RuntimeException('comp.gbb.point_value_cap_paise must be at least 100 paise (₹1); refusing to freeze the Growth Booster pool for '.$yearMonth);
        }
```
and inside it:
```php
            $rawValuePaise = Money::floorRupee($poolPaise, $totalAgp);
            $valuePaise = min($rawValuePaise, $capPaise);
```
and write both new columns. Test: with the setting at `0`, `runForMonth()` throws and writes no `gbb_monthly_pools` row. In `buildAgpMap()` delete the cap block and return `$agpMap` as built. Fix the docblock at lines 29–30 and the `GbbMonthlyResult.php:93` comment. Run `grep -rn "agp_cap\|gbbAgpCap" app resources tests database` → must be empty.

- [ ] **Step 6: Run** `--filter=Gbb` and `--filter=GrowthBooster` and `CompensationPlanSettingsServiceTest` and `AdminPlanSettingsTest` → PASS. Help: "The pool is 4% of the month's company BV; the point value is capped at ₹240." **Commit** `feat(gbb): 4% pool, ₹240 point-value cap, retire the 120 AGP cap` with the compliance trailer.

---

### Task 6: GBB lifetime rank exclusion (ranked in any earlier month)

**Files:**
- Modify: `app/app/Modules/Compensation/Services/GrowthBoosterBonusService.php:34-40` (docblock), `:670-715` (`eligibleEarners`, rename `rejectRankedLastMonth` → `rejectEverRanked`)
- Modify: `app/app/Modules/Compensation/Models/RankQualification.php` (add scope `rankedBefore(string $monthStart)`)
- Modify: `resources/help/compensation.md`
- Test: `app/tests/Modules/Compensation/GrowthBoosterBonusServiceTest.php`

- [ ] **Step 1: Failing tests**

```php
it('pays GBB in the month a distributor first reaches a rank, but never afterwards', function (): void {
    $d = uiDistributor()['id'];
    seedGbbCompanyBv(10_000_000, Carbon::parse('2026-07-10'));
    seedGbbCreditedCutoff($d, slab: 1, date: '2026-07-10');
    seedRankQualification($d, 1, '2026-07-01');          // ranks for the first time in July
    app(GrowthBoosterBonusService::class)->runForMonth(Carbon::parse('2026-07-01'));
    expect(GbbMonthlyResult::where('distributor_id', $d)->where('year_month', '2026-07-01')->value('status'))->toBe('credited');

    seedGbbCompanyBv(10_000_000, Carbon::parse('2026-09-10'));
    seedGbbCreditedCutoff($d, slab: 1, date: '2026-09-10'); // no rank in August or September
    app(GrowthBoosterBonusService::class)->runForMonth(Carbon::parse('2026-09-01'));
    expect(GbbMonthlyResult::where('distributor_id', $d)->where('year_month', '2026-09-01')->exists())->toBeFalse();
});
```
(Check `GbbMonthlyResult`'s month column name — `year_month` per `DerivedTables` — and the credited status constant.)

- [ ] **Step 2: Run** → FAIL (September row exists under the prior-month rule).

- [ ] **Step 3: Scope + rename**

`RankQualification`:
```php
    /** Qualified in any month strictly before $monthStart (lifetime GBB exclusion, client 2026-10-09). */
    protected function rankedBefore(Builder $query, string $monthStart): void
    {
        $query->where('month_start', '<', $monthStart)
            ->where('status', self::STATUS_QUALIFIED);
    }
```
`GrowthBoosterBonusService`:
```php
    /**
     * Drop every distributor who held a qualified rank in ANY month before
     * $monthStart (client 2026-10-09: once ranked, never GBB again). The month a
     * distributor first ranks still pays GBB alongside the Rank Bonus. Carry-
     * forward rows count — a paid carry row still means "ranked".
     */
    private function rejectEverRanked(Collection $agpMap, Carbon $monthStart): Collection
    {
        if ($agpMap->isEmpty()) {
            return $agpMap;
        }

        $rankedIds = RankQualification::query()
            ->rankedBefore($monthStart->toDateString())
            ->whereIn('distributor_id', $agpMap->keys()->all())
            ->distinct()
            ->pluck('distributor_id')
            ->map(fn ($id): int => (int) $id)
            ->flip();

        return $agpMap->reject(fn (int $agp, int $distributorId): bool => $rankedIds->has($distributorId));
    }
```
Update `eligibleEarners()` and the class docblock lines 34–40.

- [ ] **Step 4: Run** `--filter=GrowthBooster` → PASS; fix any existing test that asserted the prior-month rule (ranked two months ago → previously paid, now excluded). Help: "Growth Booster is for distributors who have never held a rank. The month a distributor first reaches Rank 1 both bonuses are paid; from the next month Growth Booster stops for good." **Commit** `feat(gbb): lifetime rank exclusion` with the compliance trailer.

---

### Task 7: GBB month-end repurchase verdict gate

**Files:**
- Modify: `app/app/Modules/Compensation/Services/GrowthBoosterBonusService.php:157-183` (`resolveRoster`), constructor (inject `IncomeEligibilityService`)
- Modify: `app/app/Modules/Compensation/Services/DTOs/GbbMonthRoster.php` (add `repurchaseFailed` collection)
- Modify: `app/app/Modules/Compensation/Models/GbbMonthlyResult.php` (new status `repurchase_failed_blocked` in `POOL_EXCLUDED_STATUSES`; widen the enum/CHECK by migration — see memory "enum widens need a non-MySQL branch")
- Create: `app/app/Modules/Compensation/Database/Migrations/2026_10_09_100400_add_repurchase_failed_blocked_status_to_gbb_monthly_results.php`
- Modify: `app/app/Modules/Compensation/Http/Controllers/Admin/AdminGbbInputOutputController.php` + its blade: count the new status next to `wallet_blocked`
- Modify: `resources/help/compensation.md`
- Test: `GrowthBoosterBonusServiceTest.php`, `AdminGbbInputOutputTest.php`

- [ ] **Step 1: Failing test**

```php
it('blocks a distributor who is failed on the last day of the month (A-G1) and keeps them out of the denominator', function (): void {
    Feature::for(null)->activate(RepurchaseEngineFeature::class);
    $ok = uiDistributor()['id'];
    $failed = uiDistributor()['id'];
    seedGbbCompanyBv(10_000_000, Carbon::parse('2026-07-10'));
    seedGbbCreditedCutoff($ok, 1, '2026-07-10');       // 12 AGP
    seedGbbCreditedCutoff($failed, 1, '2026-07-10');   // 12 AGP, earned before failing
    seedFailedRepurchaseCycle($failed, dueDate: '2026-07-20'); // failed from 21 Jul, unfulfilled at month end

    app(GrowthBoosterBonusService::class)->runForMonth(Carbon::parse('2026-07-01'));
    $pool = GbbMonthlyPool::where('month_start', '2026-07-01')->firstOrFail();

    expect($pool->total_agp)->toBe(12)
        ->and(GbbMonthlyResult::where('distributor_id', $failed)->value('status'))->toBe(GbbMonthlyResult::STATUS_REPURCHASE_FAILED_BLOCKED)
        ->and(GbbMonthlyResult::where('distributor_id', $failed)->value('gbb_gross_paise'))->toBe(0);
});
```

- [ ] **Step 2: Run** → FAIL.

- [ ] **Step 3: Status + migration.** Add `public const string STATUS_REPURCHASE_FAILED_BLOCKED = 'repurchase_failed_blocked';` to `GbbMonthlyResult` and to `POOL_EXCLUDED_STATUSES`. Migration widens the `status` column: on MySQL `ALTER TABLE gbb_monthly_results MODIFY status ENUM(<existing values>, 'repurchase_failed_blocked')` (copy the existing list from the create/alter migrations); on SQLite the column is a string with a CHECK — follow the pattern of the most recent migration that widened a GBB/rank status (grep `Database/Migrations` for `repurchase_wallet_blocked`).

- [ ] **Step 4: Roster.** `GbbMonthRoster` gains `public Collection $repurchaseFailed` (distributor → agp) and `totalAgp()` stays `payable->sum()`. In `resolveRoster()` after `$earners`:

```php
        // Client 2026-10-09 (A-G1): the repurchase CONDITION must hold, not only
        // the wallet. A distributor forfeited on the month's last day is blocked
        // and excluded from the denominator, like the wallet gate.
        $ids = $earners->keys()->map(fn ($id): int => (int) $id)->all();
        $this->eligibility->warmCycleCache($ids);
        $failedIds = collect($ids)->filter(fn (int $id): bool => ! $this->eligibility->verdictAsOf($id, $monthEnd)->isEligible())->flip();
        $this->eligibility->forgetCycleCache();

        $repurchaseFailed = $earners->filter(fn (int $agp, $id): bool => $failedIds->has((int) $id));
        $earners = $earners->reject(fn (int $agp, $id): bool => $failedIds->has((int) $id));
```
then the wallet gate as before; pass `repurchaseFailed: $repurchaseFailed` to the roster, and in `freezeMonth()` write them with `writeRosterRow(..., 0, GbbMonthlyResult::STATUS_REPURCHASE_FAILED_BLOCKED)`. Add `'repurchase_failed' => count` to the `runForMonth()` return array and the docblock shape (both places, lines 108 and 435).

- [ ] **Step 4a: Prerequisite guard (fail-safe principle 2).** An unresolved cycle reads as *eligible*, so a GBB freeze that runs before the 00:05 `repurchase:evaluate` has resolved the month's last-day verdicts would pay distributors who are about to be failed. Add to `IncomeEligibilityService`:

```php
    /**
     * Ids among $distributorIds whose repurchase cycle is due on or before $date
     * and still has no verdict. A monthly engine must refuse to freeze while this
     * is non-empty: an unresolved cycle reads as eligible, which overpays.
     *
     * @param  int[]  $distributorIds
     * @return list<int>
     */
    public function unresolvedDueOnOrBefore(Carbon $date, array $distributorIds): array
    {
        if (! $this->engineActive() || $distributorIds === []) {
            return [];
        }

        return RepurchaseCycle::query()
            ->whereIn('distributor_id', $distributorIds)
            ->whereNull('resolved_at')
            ->whereDate('due_date', '<=', $date->toDateString())
            ->pluck('distributor_id')
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->values()
            ->all();
    }
```
In `GrowthBoosterBonusService::resolveRoster()` before the verdict loop:
```php
        $pending = $this->eligibility->unresolvedDueOnOrBefore($monthEnd, $ids);
        if ($pending !== []) {
            throw new \RuntimeException(sprintf(
                'Growth Booster %s: %d earner(s) have a repurchase cycle due on or before %s with no verdict yet (ids %s). Run repurchase:evaluate first.',
                $monthEnd->format('Y-m'), count($pending), $monthEnd->toDateString(), implode(',', array_slice($pending, 0, 20)),
            ));
        }
```
Test: an earner with an **active** cycle due on the month's last day and no `resolved_at` → `runForMonth()` throws, no pool row, no roster row. Task 9 adds the same guard to the Rank freeze over its payable ids.

- [ ] **Step 5: Report** — in `AdminGbbInputOutputController` wherever `wallet_blocked` is counted, count the new status the same way and show it as "Blocked (repurchase failed)". Help: "Growth Booster also requires the repurchase condition to be met at the month end."

- [ ] **Step 6: Run** `--filter=Gbb`, `--filter=GrowthBooster`, `MonthlyEnginesFrozenMonthTest`, `MonthRebuildTest` → PASS. **Commit** `feat(gbb): month-end repurchase verdict gate` with the compliance trailer.

---

### Task 8: Rank Bonus data model — RAP on every rank, drop pool_pct, AGO 36 points, new settings

**Files:**
- Modify: `app/database/seeders/RankTiersSeeder.php:20-57` (rows + comment)
- Create: `app/app/Modules/Compensation/Database/Migrations/2026_10_09_100500_set_rank_tier_rap_points_and_drop_pool_pct.php`
- Create: `app/app/Modules/Compensation/Database/Migrations/2026_10_09_100600_update_rank_bonus_settings_for_two_pass_pool.php`
- Modify: `app/app/Modules/Compensation/Services/CompensationPlanSettingsService.php:97-108` (defaults), `:500-580` (`rankTiers()` drop `pool_pct`; remove `rankPoolPct()`; `rankRapPoints(): int`; new `rankPointValueCapPaise()`, `rankFirstPassMaxRank()`)
- Modify: `app/database/seeders/SettingsSeeder.php:90-93`
- Modify: `app/app/Modules/Admin/Http/Controllers/AdminSettingsController.php:1290+` (envelope description; `aogo_points` default `'36'`; two new entries)
- Modify: `app/app/Modules/Compensation/Http/Controllers/Admin/AdminPlanSettingsController.php:134-164` (drop `pool_pct`, `rap_points` required int ≥ 1)
- Modify: `resources/views/admin/compensation/plan-settings/index.blade.php:435-455` (remove the Pool % input; RAP required)
- Test: `CompensationPlanSettingsServiceTest.php`, `AdminPlanSettingsTest.php`, `BackfillRankMonthlyPoolsMigrationTest.php` (see note)

**Interfaces:**
- Produces: `rankRapPoints(int $rank): int` (non-nullable, 0 if unset); `rankPointValueCapPaise(): int` (default 20_000); `rankFirstPassMaxRank(): int` (default 3); `aogoPointsPerGrant()` default 36. `rank_tiers.pool_pct` and `rank_monthly_pools.pool_pct` **no longer exist** (Task 9 drops the pools column together with the pool reshape; this task drops `rank_tiers.pool_pct`).

- [ ] **Step 1: Failing tests** (in `CompensationPlanSettingsServiceTest.php`):

```php
it('exposes RAP points for every rank per the 05-10-2026 Rank Income Point System', function (): void {
    $plan = app(CompensationPlanSettingsService::class);
    expect(array_map(fn (int $r) => $plan->rankRapPoints($r), range(1, 9)))
        ->toBe([72, 189, 468, 1125, 2583, 5688, 11934, 23877, 39501]);
    expect($plan->aogoPointsPerGrant())->toBe(36)
        ->and($plan->rankPointValueCapPaise())->toBe(20_000)
        ->and($plan->rankFirstPassMaxRank())->toBe(3)
        ->and(method_exists($plan, 'rankPoolPct'))->toBeFalse();
});
```

- [ ] **Step 2: Run** → FAIL.

- [ ] **Step 3: Seeder rows** (replace the array; drop the `pool_pct` element and its header word; keep every other column):

```php
            // rank, name, pyp, rap_points, personal_bv, group_bv, weaker_leg_topup_bv, structural_per_side, repurchase_bv_paise, lifetime_award_budget_paise
            [1, 'Silver Partner',        1,    72,    700_000,  25_000_000, 1_500_000, null, 100_000,     1_540_000],
            [2, 'Pearl Partner',         1,   189,  1_500_000,  60_000_000, 3_000_000, null, 110_000,     3_600_000],
            [3, 'Emerald Partner',       2,   468,  3_200_000,        null,         0,    2, 120_000,    10_800_000],
            [4, 'Gold Partner',          2,  1125,  6_800_000,        null,         0,    2, 130_000,    32_400_000],
            [5, 'Diamond Partner',       2,  2583, 14_400_000,        null,         0,    2, 140_000,    97_200_000],
            [6, 'Blue Diamond Partner',  3,  5688, 30_000_000,        null,         0,    2, 160_000,   282_600_000],
            [7, 'Royal Diamond Partner', 3, 11934, 30_000_000,        null,         0,    2, 180_000,   817_470_000],
            [8, 'Crown Diamond Partner', 3, 23877, 30_000_000,        null,         0,    2, 200_000, 2_370_600_000],
            [9, 'Elite Diamond Partner', 3, 39501, 30_000_000,        null,         0,    2, 230_000, 6_874_740_000],
```
Re-index the `array_map` keys accordingly and replace the `pool_pct`/RAP comment with: "rap_points (client 2026-10-05 Rank Income Point System): every rank carries Rank Achievement Points; the 20% envelope is one pool divided in two passes at a capped point value (see RankBonusService). lifetime_award_budget_paise = the sum of the rank's award tranches (client 2026-10-09)."

- [ ] **Step 4: Migration 100500** — set `rank_tiers.rap_points` per rank (`[1=>72,…,9=>39501]`) **unconditionally** (the old values 10/null were never a client choice), make the column `NOT NULL`, drop `pool_pct` (user decision 2026-10-09: stale columns go now), and write the audit row:

```php
        $before = DB::table('rank_tiers')->orderBy('rank_number')->get(['rank_number', 'rap_points', 'pool_pct'])->keyBy('rank_number');

        foreach ([1 => 72, 2 => 189, 3 => 468, 4 => 1125, 5 => 2583, 6 => 5688, 7 => 11934, 8 => 23877, 9 => 39501] as $rank => $points) {
            DB::table('rank_tiers')->where('rank_number', $rank)->update(['rap_points' => $points]);
        }

        Schema::table('rank_tiers', function (Blueprint $t): void {
            $t->unsignedInteger('rap_points')->nullable(false)->default(0)->change(); // was unsignedSmallInteger nullable; 39,501 needs > 16 bits? No (65,535) — keep small if preferred, but unsigned int costs nothing
            $t->dropColumn('pool_pct');
        });

        AuditLog::create([
            'actor_id' => null,
            'action' => 'plan.migration.rank_tiers_rap_points_two_pass',
            'subject_type' => 'rank_tier',
            'subject_id' => 0,
            'details' => ['before' => $before->all(), 'after_rap_points' => [1 => 72, 2 => 189, 3 => 468, 4 => 1125, 5 => 2583, 6 => 5688, 7 => 11934, 8 => 23877, 9 => 39501], 'dropped' => ['pool_pct']],
        ]);
```
`down()` throws `RuntimeException('restore rank_tiers from the plan.migration.rank_tiers_rap_points_two_pass audit row')`.

**`BackfillRankMonthlyPoolsMigrationTest` / the 2026-09-11 backfill migration:** it reads `rank_tiers.pool_pct` and writes `rank_monthly_pools.pool_pct` (line 74). On a fresh schema it runs before both drops, so ordinary migrations are fine. Add a one-line guard at its top — `if (! Schema::hasColumn('rank_tiers', 'pool_pct') || ! Schema::hasColumn('rank_monthly_pools', 'pool_pct')) { return; }` — so re-running it against the final schema is a no-op rather than an error, and adjust its test to assert the no-op on the current schema.

- [ ] **Step 5: Migration 100600** — settings rows:
```php
        DB::table('settings')->where('key', 'comp.rank.aogo_points')->where('value', '5')->update(['value' => '36']);
        DB::table('settings')->insertOrIgnore([
            ['key' => 'comp.rank.point_value_cap_paise', 'value' => '20000', /* same extra columns SettingsSeeder writes */],
            ['key' => 'comp.rank.first_pass_max_rank', 'value' => '3', /* … */],
        ]);
```
(Copy the exact column set from `SettingsSeeder::seedCompensationPlanScalars()`.)

- [ ] **Step 6: Service + registry.** `SCALAR_DEFAULTS`: `'comp.rank.aogo_points' => 36,` plus
```php
        // Client 2026-10-05 Rank Income Point System: one 20% pool, two passes,
        // ₹200 ceiling per Rank Achievement Point.
        'comp.rank.point_value_cap_paise' => 20_000,
        // Ranks 1..N (plus the AGO offer) are priced first from the whole
        // envelope; ranks N+1..9 share what is left.
        'comp.rank.first_pass_max_rank' => 3,
```
Accessors: `rankPointValueCapPaise(): int` (raw `scalarInt`; the freeze refuses < 100), `rankFirstPassMaxRank(): int` (`min(9, max(0, scalarInt))`), `rankRapPoints(int $rank): int` → `(int) ($this->rankTiers()[$rank]['rap_points'] ?? 0)`. Delete `rankPoolPct()` and the `'pool_pct'` line in `rankTiers()`; rewrite the `rankEnvelopeBp()` docblock: "The whole envelope is one pool, divided in two passes (see RankBonusService)". `SettingsSeeder`: `aogo_points` → `'36'`, add the two keys. Registry: envelope description → `Share of monthly company BV set aside for the Rank Bonus. 2000 = 20%. One pool: the AGO offer and Ranks 1–3 are priced from it first, Ranks 4–9 from the remainder, both at most ₹200 per point.`; `aogo_points` default `'36'`; new entries `Rank point value cap (paise)` (default `'20000'`, min 100, max 10_000_000, impact `Takes effect from the next monthly freeze; frozen months are unchanged.`) and `Ranks priced in pass 1 (1..N)` (default `'3'`, min 0, max 9, same impact text).

- [ ] **Step 7: Admin form.** `AdminPlanSettingsController::updateRankTier()` (lines 134–164): remove `pool_pct` validation and assignment; `'rap_points' => ['required', 'integer', 'min:1', 'max:65535']`, assign `(int)`. Blade lines 435–455: delete the Pool % input, mark RAP `required min="1"`.

- [ ] **Step 8: Run** `CompensationPlanSettingsServiceTest`, `AdminPlanSettingsTest`, `BackfillRankMonthlyPoolsMigrationTest` → PASS (fix any assertion that read `pool_pct`). **Commit** `feat(rank): RAP points on every rank, AGO 36, drop per-rank pool percentages` with the compliance trailer. (Task 9 will temporarily break `RankBonusService` compile until its own commit — do Tasks 8 and 9 back to back; run the full Compensation suite only after Task 9.)

---

### Task 9: Rank Bonus two-pass pool at a capped point value

**Files:**
- Create: `app/app/Modules/Compensation/Database/Migrations/2026_10_09_100700_reshape_rank_monthly_pools_for_two_pass_pricing.php`
- Create: `app/app/Modules/Compensation/Models/RankMonthlyPass.php`
- Modify: `app/app/Modules/Compensation/Models/RankMonthlyPool.php` (`$fillable`, casts: remove `pool_pct`; add `pass`)
- Modify: `app/app/Modules/Compensation/Services/RankBonusService.php:24-50` (docblock), `:330-425` (`freezeMonth`), `:540-565` (`recordFreeze`), `:600-615` (`replacePrematureFreeze` details), `:680-760` (`creditFromFrozenPools` return shape)
- Modify: `app/app/Modules/Compensation/Services/Rebuild/MonthRebuilder.php:100-190` (delete `rank_monthly_passes` with the pools), `app/app/Modules/Compensation/Support/DerivedTables.php:70-150` (register the table, month granularity on `month_start`)
- Modify: `resources/help/compensation.md` (Rank Bonus section rewritten to the two-pass rule)
- Test: `app/tests/Modules/Compensation/RankBonusServiceTest.php`

**Interfaces:**
- Produces: table `rank_monthly_passes` (`month_start` date, `pass` tinyint 1|2, `company_turnover_paise` bigint, `envelope_bp` uint, `envelope_paise` bigint, `pool_paise` bigint — the amount this pass divided, `total_points` uint, `raw_point_value_paise` ubigint, `point_value_cap_paise` ubigint, `point_value_paise` ubigint, `payout_paise` bigint, `leftover_paise` bigint, timestamps, unique(`month_start`,`pass`)). `rank_monthly_pools` gains `pass` tinyint and loses `pool_pct`; for each rank row `pool_paise` = that rank's allotment (`total_points × point_value`), `leftover_paise` = 0; the pass row carries the real leftover. `runForMonth()` return gains `'passes' => array<int, array{pool_paise:int,total_points:int,raw_point_value_paise:int,point_value_paise:int,payout_paise:int,leftover_paise:int}>`.

- [ ] **Step 1: Failing tests** (append to `RankBonusServiceTest.php`; `seedRankCompanyBv`, `seedRankQualification` exist in the file; `RankAogoGrant` rows can be created directly as the file's AO-GO tests do):

```php
/** Qualify $n fresh distributors at $rank for the month. */
function seedRankCohort(int $n, int $rank, string $monthStart): array
{
    $ids = [];
    for ($i = 0; $i < $n; $i++) {
        $id = uiDistributor()['id'];
        seedRankQualification($id, $rank, $monthStart);
        $ids[] = $id;
    }
    return $ids;
}

it('example A2: AGO + Rank 1 share the whole pool at the floored value (188)', function (): void {
    $m = '2026-09-01';
    seedRankCompanyBv(95_000_000_00, Carbon::parse('2026-09-10')); // 9,50,000 BV → envelope 1,90,000
    $r1 = seedRankCohort(9, 1, $m);
    seedAogoGrants(10, $m); // 10 AO-GO grantees for the month, 36 points each — reuse/adapt the file's AO-GO fixture
    $out = app(RankBonusService::class)->runForMonth(Carbon::parse($m));

    expect($out['passes'][1]['total_points'])->toBe(1_008)
        ->and($out['passes'][1]['raw_point_value_paise'])->toBe(18_800)
        ->and($out['passes'][1]['point_value_paise'])->toBe(18_800)
        ->and(RankBonusResult::where('distributor_id', $r1[0])->value('gross_paise'))->toBe(72 * 18_800)   // ₹13,536
        ->and($out['passes'][1]['leftover_paise'])->toBe(49_600)                 // what pass 1 hands to pass 2
        ->and($out['passes'][2]['pool_paise'])->toBe(19_000_000 - 1_008 * 18_800) // ₹496 remainder, nobody in pass 2
        ->and($out['passes'][2]['total_points'])->toBe(0)
        ->and($out['passes'][2]['leftover_paise'])->toBe(49_600);
});

it('example C1: pass 1 is capped at ₹200 when the raw value exceeds it', function (): void {
    $m = '2026-09-01';
    seedRankCompanyBv(630_000_000_00, Carbon::parse('2026-09-10')); // 63L BV → envelope 12,60,000
    seedRankCohort(9, 1, $m); seedRankCohort(8, 2, $m); $r3 = seedRankCohort(7, 3, $m); seedAogoGrants(10, $m);
    $out = app(RankBonusService::class)->runForMonth(Carbon::parse($m));

    expect($out['passes'][1]['raw_point_value_paise'])->toBe(21_700)
        ->and($out['passes'][1]['point_value_paise'])->toBe(20_000)
        ->and(RankBonusResult::where('distributor_id', $r3[0])->value('gross_paise'))->toBe(468 * 20_000) // ₹93,600
        ->and($out['passes'][2]['pool_paise'])->toBe(126_000_000 - 115_920_000)                        // ₹1,00,800 left
        ->and($out['passes'][2]['leftover_paise'])->toBe(10_080_000);
});

it('example D2: ranks 4–9 share the remainder at the floored value (186)', function (): void {
    $m = '2026-09-01';
    seedRankCompanyBv(16_000_000_000_00, Carbon::parse('2026-09-10')); // 16 Cr BV → envelope 3.2 Cr
    seedRankCohort(9, 1, $m); seedRankCohort(8, 2, $m); seedRankCohort(7, 3, $m); seedAogoGrants(10, $m);
    seedRankCohort(6, 4, $m); seedRankCohort(5, 5, $m); seedRankCohort(4, 6, $m);
    seedRankCohort(3, 7, $m); $r8 = seedRankCohort(2, 8, $m); $r9 = seedRankCohort(1, 9, $m);
    $out = app(RankBonusService::class)->runForMonth(Carbon::parse($m));

    expect($out['passes'][1]['point_value_paise'])->toBe(20_000)
        ->and($out['passes'][1]['payout_paise'])->toBe(115_920_000)                 // ₹11,59,200
        ->and($out['passes'][2]['pool_paise'])->toBe(3_200_000_000 - 115_920_000)   // ₹3,08,40,800
        ->and($out['passes'][2]['total_points'])->toBe(165_474)
        ->and($out['passes'][2]['raw_point_value_paise'])->toBe(18_600)
        ->and(RankBonusResult::where('distributor_id', $r8[0])->value('gross_paise'))->toBe(23_877 * 18_600) // ₹44,41,122
        ->and(RankBonusResult::where('distributor_id', $r9[0])->value('gross_paise'))->toBe(39_501 * 18_600) // ₹73,47,186
        ->and($out['passes'][2]['leftover_paise'])->toBe(6_263_600);                 // ₹62,636
});

it('with nobody in pass 1, pass 2 divides the whole envelope', function (): void {
    $m = '2026-09-01';
    seedRankCompanyBv(10_000_000_00, Carbon::parse('2026-09-10')); // 10L BV → envelope 2,00,000
    $r4 = seedRankCohort(1, 4, $m);
    $out = app(RankBonusService::class)->runForMonth(Carbon::parse($m));
    expect($out['passes'][1]['total_points'])->toBe(0)->and($out['passes'][1]['point_value_paise'])->toBe(0)
        ->and($out['passes'][2]['pool_paise'])->toBe(20_000_000)
        ->and($out['passes'][2]['raw_point_value_paise'])->toBe(17_700) // floor(2,00,000 / 1,125) = 177
        ->and(RankBonusResult::where('distributor_id', $r4[0])->value('gross_paise'))->toBe(1_125 * 17_700);
});

it('a refund-heavy month prices both passes at zero and credits nothing', function (): void {
    $m = '2026-09-01';
    seedRankCompanyBv(-5_000_000_00, Carbon::parse('2026-09-10'));
    seedRankCohort(2, 1, $m);
    $out = app(RankBonusService::class)->runForMonth(Carbon::parse($m));
    expect($out['credited'])->toBe(0)->and($out['passes'][1]['point_value_paise'])->toBe(0)
        ->and($out['passes'][2]['pool_paise'])->toBe(0);
});
```
(`seedAogoGrants(int $n, string $monthStart)` — write it in the test file by creating `RankAogoGrant` rows with `points => 36` the way the file's existing AO-GO tests do; the engine reads `$roster->aogoGrants->sum('points')`.) Pay the highest rank only is the default; cohorts here hold one rank each.

- [ ] **Step 2: Run** `--filter=RankBonusService` → FAIL.

- [ ] **Step 3: Migration 100700**

```php
    public function up(): void
    {
        Schema::create('rank_monthly_passes', function (Blueprint $table): void {
            $table->id();
            $table->date('month_start');
            $table->unsignedTinyInteger('pass');
            $table->bigInteger('company_turnover_paise');
            $table->unsignedInteger('envelope_bp');
            $table->bigInteger('envelope_paise');
            $table->bigInteger('pool_paise');
            $table->unsignedInteger('total_points');
            $table->unsignedBigInteger('raw_point_value_paise');
            $table->unsignedBigInteger('point_value_cap_paise');
            $table->unsignedBigInteger('point_value_paise');
            $table->bigInteger('payout_paise');
            $table->bigInteger('leftover_paise');
            $table->timestamps();
            $table->unique(['month_start', 'pass'], 'uq_rank_pass_month_pass');
        });

        Schema::table('rank_monthly_pools', function (Blueprint $table): void {
            $table->unsignedTinyInteger('pass')->default(1)->after('rank_number');
            $table->dropColumn('pool_pct'); // user decision 2026-10-09: stale columns go now; readers removed in Tasks 8–10
        });
    }
```
`down()` drops the new table and the `pass` column and re-adds `decimal('pool_pct', 8, 4)->nullable()`. Before committing Task 10, run `grep -rn "pool_pct" app resources tests database/seeders` → only the 2026-06-27 create, the 2026-09-07 create and the guarded 2026-09-11 backfill migrations remain. Model `RankMonthlyPass` (final, `$fillable` = every column, integer casts, `month_start` date cast). `RankMonthlyPool`: remove `pool_pct` from `$fillable`/casts, add `pass`.

- [ ] **Step 4: Rewrite `freezeMonth()`**

```php
    private function freezeMonth(Carbon $monthStartCarbon, string $monthStart, Carbon $monthEnd): Collection
    {
        return DB::transaction(function () use ($monthStartCarbon, $monthStart, $monthEnd): Collection {
            $roster = $this->resolveRoster($monthStartCarbon, $monthStart);

            // Fail-safe principle 1: configuration that would pay ₹0 or divide
            // by a missing number stops the freeze before any write.
            $capPaise = $this->plan->rankPointValueCapPaise();
            if ($capPaise < 100) {
                throw new \RuntimeException("comp.rank.point_value_cap_paise must be at least 100 paise (₹1); refusing to freeze the Rank Bonus for {$monthStart}");
            }
            foreach (self::RANKS as $rank) {
                if ($roster->payableFor($rank) !== [] && $this->plan->rankRapPoints($rank) <= 0) {
                    throw new \RuntimeException("rank_tiers.rap_points is not set for rank {$rank} but it has payable achievers; refusing to freeze the Rank Bonus for {$monthStart}");
                }
            }

            // Fail-safe principle 2: every payable achiever's repurchase verdict
            // for the month must exist (the GBB twin, Task 7).
            $allPayable = array_merge(...array_map(fn (int $r): array => $roster->payableFor($r), self::RANKS));
            $pending = $this->eligibility->unresolvedDueOnOrBefore($monthEnd, $allPayable);
            if ($pending !== []) {
                throw new \RuntimeException(sprintf('Rank Bonus %s: %d achiever(s) have an unresolved repurchase cycle due on or before %s (ids %s). Run repurchase:evaluate first.', $monthStart, count($pending), $monthEnd->toDateString(), implode(',', array_slice($pending, 0, 20))));
            }

            $turnoverPaise = $this->gsbPool->companyBvPaiseBetween($monthStartCarbon, $monthEnd);
            $envelopeBp = $this->plan->rankEnvelopeBp();
            // F-9: integer arithmetic only. 16 Cr BV × 2,000 bp = 3.2 × 10¹⁴, far inside 64-bit.
            $envelopePaise = max(0, intdiv($turnoverPaise * $envelopeBp, 10_000));
            $firstPassMax = $this->plan->rankFirstPassMaxRank();

            // Points per rank: payable achievers × RAP, plus the AGO offer's
            // points on the Rank-1 row (client 2026-10-05: AGO is a pass-1
            // participant with its own RAP).
            $aogoPoints = (int) $roster->aogoGrants->sum('points');
            $rankPoints = [];
            foreach (self::RANKS as $rank) {
                $rankPoints[$rank] = count($roster->payableFor($rank)) * $this->plan->rankRapPoints($rank)
                    + ($rank === 1 ? $aogoPoints : 0);
            }

            $passRanks = [
                1 => array_values(array_filter(self::RANKS, fn (int $r): bool => $r <= $firstPassMax)),
                2 => array_values(array_filter(self::RANKS, fn (int $r): bool => $r > $firstPassMax)),
            ];

            // Pass 1 divides the whole envelope; pass 2 divides what pass 1
            // left. Each pass: floor to the rupee, then cap.
            $passes = [];
            $remaining = $envelopePaise;
            foreach ([1, 2] as $pass) {
                $points = array_sum(array_intersect_key($rankPoints, array_flip($passRanks[$pass])));
                $raw = Money::floorRupee($remaining, $points);
                $value = min($raw, $capPaise);
                $payout = $value * $points;

                $passes[$pass] = RankMonthlyPass::create([
                    'month_start' => $monthStart,
                    'pass' => $pass,
                    'company_turnover_paise' => $turnoverPaise,
                    'envelope_bp' => $envelopeBp,
                    'envelope_paise' => $envelopePaise,
                    'pool_paise' => $remaining,
                    'total_points' => $points,
                    'raw_point_value_paise' => $raw,
                    'point_value_cap_paise' => $capPaise,
                    'point_value_paise' => $value,
                    'payout_paise' => $payout,
                    'leftover_paise' => $remaining - $payout,
                ]);

                $remaining -= $payout;
            }

            /** @var Collection<int, RankMonthlyPool> $pools */
            $pools = collect();

            foreach (self::RANKS as $rank) {
                $pass = $rank <= $firstPassMax ? 1 : 2;
                $pointValuePaise = (int) $passes[$pass]->point_value_paise;
                $rapPoints = $this->plan->rankRapPoints($rank);
                $payableIds = $roster->payableFor($rank);
                $heldIds = $roster->heldFor($rank);
                /** @var Collection<int, RankAogoGrant> $grants */
                $grants = $rank === 1 ? $roster->aogoGrants : collect();
                $rankAogoPoints = $rank === 1 ? $aogoPoints : 0;
                $totalPoints = $rankPoints[$rank];
                $grossPerQualifier = $rapPoints * $pointValuePaise;
                $payoutPaise = $totalPoints * $pointValuePaise;

                $pool = RankMonthlyPool::create([
                    'month_start' => $monthStart,
                    'rank_number' => $rank,
                    'pass' => $pass,
                    'company_turnover_paise' => $turnoverPaise,
                    'envelope_bp' => $envelopeBp,
                    'pool_paise' => $payoutPaise,          // this rank's allotment
                    'rap_points' => $rapPoints,
                    'payable_count' => count($payableIds),
                    'aogo_points' => $rankAogoPoints,
                    'total_points' => $totalPoints,
                    'point_value_paise' => $pointValuePaise,
                    'gross_per_qualifier_paise' => $grossPerQualifier,
                    'payout_paise' => $payoutPaise,
                    'leftover_paise' => 0,                 // the pass row carries the leftover
                ]);

                $pools[$rank] = $pool;

                foreach ($heldIds as $distributorId) {
                    $this->writeRosterRow($distributorId, $pool, RankBonusResult::STATUS_REQUALIFICATION_HELD, 0);
                }
                foreach ($payableIds as $distributorId) {
                    $this->writeRosterRow($distributorId, $pool, RankBonusResult::STATUS_PENDING, $grossPerQualifier,
                        rapPoints: $rapPoints, totalPoints: $totalPoints, pointValuePaise: $pointValuePaise);
                }
                foreach ($grants as $grant) {
                    $this->writeRosterRow((int) $grant->distributor_id, $pool, RankBonusResult::STATUS_PENDING,
                        $grant->points * $pointValuePaise, aogoPoints: $grant->points, totalPoints: $totalPoints, pointValuePaise: $pointValuePaise);
                }
            }

            // Fail-safe principle 3: reconcile before commit. Any mismatch is a
            // bug, and a bug must roll the whole freeze back, not pay out.
            $rosterGross = (int) RankBonusResult::query()->where('month_start', $monthStart)
                ->where('status', RankBonusResult::STATUS_PENDING)->sum('gross_paise');
            $passPayout = (int) collect($passes)->sum('payout_paise');
            $poolPayout = (int) $pools->sum('payout_paise');
            if ($rosterGross !== $passPayout || $poolPayout !== $passPayout || $passPayout > $envelopePaise
                || (int) $passes[2]->pool_paise !== $envelopePaise - (int) $passes[1]->payout_paise) {
                throw new \RuntimeException(sprintf(
                    'Rank Bonus %s freeze does not reconcile: roster gross %d, pool payout %d, pass payout %d, envelope %d. Rolled back.',
                    $monthStart, $rosterGross, $poolPayout, $passPayout, $envelopePaise,
                ));
            }

            $this->recordFreeze($monthStart, $pools, collect($passes));

            return $pools;
        });
    }
```
(`writeRosterRow()` returns null for an already-credited row and skips the write, so on a re-freeze after a partial credit `$rosterGross` can legitimately be lower than `$passPayout`; `replacePrematureFreeze()` already refuses to re-freeze once anything is credited, so inside `freezeMonth()` the identity is exact. If a test shows otherwise, compare against the sum of what `writeRosterRow()` actually wrote this call, collected in a local accumulator.)

The constructor gains `private readonly IncomeEligibilityService $eligibility` (the `RankQualificationService` already injects it the same way).

`recordFreeze(string $monthStart, Collection $pools, Collection $passes)`: add `'passes' => $passes->map(fn (RankMonthlyPass $p): array => $p->only(['pool_paise','total_points','raw_point_value_paise','point_value_cap_paise','point_value_paise','payout_paise','leftover_paise']))->all()` to `$details`. In `replacePrematureFreeze()` also `RankMonthlyPass::where('month_start', $monthStart)->delete()` next to the pools delete. In `creditFromFrozenPools()` add `'passes' => RankMonthlyPass::where('month_start', $monthStart)->get()->keyBy('pass')->map(fn ($p) => [...same keys as ints...])->all()` to the return array and both docblock shapes. Rewrite the class docblock lines 24–50 to describe the two-pass rule with example D2 (3.2 Cr envelope; pass 1 points 5,796 → ₹200 cap; pass 2 3,08,40,800 ÷ 1,65,474 → ₹186).

- [ ] **Step 5: Rebuild/wiper.** `MonthRebuilder`: add `'rank_monthly_passes' => $this->rankPasses($month)->count()/->delete()` beside the pools (private query helper `RankMonthlyPass::where('month_start', $month->toDateString())`). `DerivedTables`: add `'rank_monthly_passes'` to the table list and `'rank_monthly_passes' => ['column' => 'month_start', 'granularity' => 'month']`. Grep `rank_monthly_pools` across `app/` for any other wiper/snapshot list and add the passes table beside it.

- [ ] **Step 6: Run** `php artisan test tests/Modules/Compensation` (whole module) → PASS; fix the existing Rank tests that encoded 7%/equal-split arithmetic to the new values (the numbers change, the behaviours — frozen roster, held, AO-GO settle, premature freeze — do not). Help doc Rank Bonus section: the two-pass rule, RAP table, ₹200 cap, leftover stays with the company.

- [ ] **Step 7: Commit** `feat(rank): one 20% pool priced in two passes at a ₹200-capped point value` with the compliance trailer.

---

### Task 10: Rank Bonus admin surfaces (Input & Output report, formula block, snapshots)

**Files:**
- Modify: `app/app/Modules/Compensation/Http/Controllers/Admin/AdminRankBonusInputOutputController.php:100-190, 250-380` (replace the `pool_pct` column with `pass`, add pass summary rows)
- Modify: `resources/views/admin/compensation/rb-input-output/index.blade.php:90-100`
- Modify: `resources/views/admin/compensation/_formulas/rb-month.blade.php` (rewrite to the two-pass formula)
- Modify: `app/app/Modules/Compensation/Services/BonusCalculationSnapshots.php` (its rank-month block feeding `rb-month.blade.php`: expose `passes` from `rank_monthly_passes`)
- Test: `AdminRankBonusInputOutputTest.php`, `AdminRbCalculationTest.php`, `RankBonusStylingTest.php`

- [ ] **Step 1: Failing test** — in `AdminRankBonusInputOutputTest.php` after freezing a month (reuse its fixture): assert the page shows `Pass 1`, `Pass 2`, the pass point value and leftover, and no `Pool %` header:

```php
    $res->assertSee('Pass 1')->assertSee('Pass 2')->assertDontSee('Pool %');
```

- [ ] **Step 2: Run** → FAIL.

- [ ] **Step 3: Controller.** Replace `['key' => 'pool_pct', 'label' => 'Pool % (current)']` with `['key' => 'pass', 'label' => 'Pass']`; in the per-rank array replace `'pool_pct' => …` with `'pass' => $pool?->pass ?? ($rank <= $this->plan->rankFirstPassMaxRank() ? 1 : 2)`; add a `passes` array to the month payload read from `RankMonthlyPass::where('month_start', …)->orderBy('pass')->get()`; update the docblock shape at line 255. Blade: render the `passes` summary above the rank table (pool, points, raw value, cap, value, payout, leftover — all through `IndianNumber`), and the `pass` cell in place of the percent cell.

- [ ] **Step 4: Formula block.** `rb-month.blade.php` currently explains Rank 1 only (`$rank1['pool_pct']`). Rewrite as: 1. Turnover × envelope = envelope; 2. Pass 1 points = Σ(payable × RAP for ranks ≤ N) + AGO points; 3. value₁ = min(cap, floor(envelope ÷ points₁)); 4. payout₁; 5. remainder; 6. Pass 2 points; 7. value₂ = min(cap, floor(remainder ÷ points₂)); 8. leftover. Source the numbers from `BonusCalculationSnapshots` (add a `passes` key to its rank-month snapshot built from `RankMonthlyPass`).

- [ ] **Step 5: Run** the three tests + `php -l` on the compiled views (`php artisan view:cache` then lint, per memory "Blade component attrs + view lint") → PASS. **Commit** `feat(rank): admin report and formula show the two-pass pricing`.

---

### Task 11: Lifetime Awards — tranches, new amounts, merchandise only

**Files:**
- Create: `app/app/Modules/Compensation/Database/Migrations/2026_10_09_100800_create_lifetime_award_tranches_table.php`
- Create: `app/app/Modules/Compensation/Database/Migrations/2026_10_09_100900_add_tranche_to_lifetime_award_milestones.php`
- Create: `app/app/Modules/Compensation/Models/LifetimeAwardTranche.php`
- Create: `app/database/seeders/LifetimeAwardTranchesSeeder.php`; register it wherever `LifetimeAwardRewardsSeeder` is called (grep `LifetimeAwardRewardsSeeder::class` in `database/seeders` and `tests/Pest.php::seedCompensationPlanTables()`)
- Modify: `app/database/seeders/LifetimeAwardRewardsSeeder.php` (placeholder catalogue, one item per tranche, worth = tranche amount)
- Modify: `app/app/Modules/Compensation/Models/LifetimeAwardMilestone.php` (`$fillable` + `tranche`, `amount_paise`; replace `releaseThreshold()`/`isReleasable()`; drop `DISBURSEMENT_CASH` use)
- Modify: `app/app/Modules/Compensation/Services/CompensationPlanSettingsService.php` (new `lifetimeAwardTranches(int $rank): array`)
- Modify: `app/app/Modules/Compensation/Services/RankBonusService.php:850-890` (`syncLifetimeAward` creates one milestone per earned tranche)
- Modify: `app/app/Modules/Admin/Http/Controllers/AdminLifetimeAwardsController.php:143-215` (`disbursement_type` → `in:goods` only; show tranche)
- Modify: `app/app/Modules/Compensation/Http/Controllers/Admin/AdminAwRwCalculationController.php:36` (`type` filter goods only) and the two blades under `resources/views/admin/compensation/aw-rw-calculation/` and `resources/views/admin/lifetime-awards/` (grep for `cash`): remove the cash option, add a Tranche column
- Modify: `app/app/Modules/Compensation/Services/BonusCalculationSnapshots.php:215-260` (`awRwMonths` adds per-tranche counts)
- Modify: `resources/help/compensation.md` (Awards section)
- Test: `LifetimeAwardCatalogTest.php`, `RankBonusServiceTest.php` (milestone tests), `AdminAwRwCalculationTest.php`

**Interfaces:**
- Produces: `lifetime_award_tranches` (`rank_number` tinyint, `tranche` tinyint, `amount_paise` ubigint, unique(rank, tranche)); `lifetimeAwardTranches(int $rank): list<array{tranche:int, amount_paise:int}>`; `lifetime_award_milestones.tranche` tinyint default 1, `amount_paise` ubigint default 0, unique becomes (`distributor_id`,`rank_number`,`tranche`) — read the existing unique index name at `2026_06_25_200004…:24` and drop/recreate it. `LifetimeAwardMilestone::isReleasable()` → `qualification_count >= tranche`.

- [ ] **Step 1: Failing tests**

`LifetimeAwardCatalogTest.php` — replace the budget expectations:
```php
it('exposes the 2026-10-09 award tranches and budgets via the SSOT', function (): void {
    $plan = app(CompensationPlanSettingsService::class);
    expect($plan->lifetimeAwardBudgetPaise(1))->toBe(1_540_000)
        ->and($plan->lifetimeAwardBudgetPaise(9))->toBe(6_874_740_000)
        ->and(array_column($plan->lifetimeAwardTranches(9), 'amount_paise'))->toBe([918_630_000, 956_070_000, 5_000_040_000])
        ->and($plan->lifetimeAwardTranches(1))->toHaveCount(1)
        ->and($plan->lifetimeAwardTranches(4))->toHaveCount(2);
});

it("reconciles every rank's tranches and reward items to its budget", function (): void {
    $plan = app(CompensationPlanSettingsService::class);
    foreach (range(1, 9) as $rank) {
        $budget = $plan->lifetimeAwardBudgetPaise($rank);
        expect(array_sum(array_column($plan->lifetimeAwardTranches($rank), 'amount_paise')))->toBe($budget);
        expect(array_sum(array_column($plan->lifetimeAwardRewards($rank), 'worth_paise')))->toBe($budget);
    }
});
```
`RankBonusServiceTest.php`:
```php
it('opens award tranche A on the first qualification and tranche B on the second (rank 3)', function (): void {
    $d = uiDistributor()['id'];
    foreach (['2026-07-01', '2026-08-01'] as $i => $m) {
        seedRankCompanyBv(10_000_000_00, Carbon::parse($m)->addDays(5));
        seedRankQualification($d, 3, $m);
        app(RankBonusService::class)->runForMonth(Carbon::parse($m));
        expect(LifetimeAwardMilestone::where('distributor_id', $d)->where('rank_number', 3)->pluck('tranche')->sort()->values()->all())
            ->toBe(range(1, $i + 1));
    }
    $b = LifetimeAwardMilestone::where('distributor_id', $d)->where('rank_number', 3)->where('tranche', 2)->firstOrFail();
    expect($b->amount_paise)->toBe(5_940_000)->and($b->isReleasable())->toBeTrue()->and($b->triggered_month->toDateString())->toBe('2026-08-01');
});
```

- [ ] **Step 2: Run** → FAIL.

- [ ] **Step 3: Tranches table + seeder**

```php
        Schema::create('lifetime_award_tranches', function (Blueprint $table): void {
            $table->id();
            $table->unsignedTinyInteger('rank_number');
            $table->unsignedTinyInteger('tranche');
            $table->unsignedBigInteger('amount_paise');
            $table->timestamps();
            $table->unique(['rank_number', 'tranche'], 'uq_award_tranche_rank_tranche');
        });
```
Seeder (idempotent `upsert` on the unique pair), amounts in paise:
```php
        $rows = [
            1 => [1_540_000],
            2 => [3_600_000],
            3 => [4_860_000, 5_940_000],
            4 => [14_580_000, 17_820_000],
            5 => [43_740_000, 53_460_000],
            6 => [84_780_000, 93_240_000, 104_580_000],
            7 => [245_250_000, 269_730_000, 302_490_000],
            8 => [711_180_000, 782_280_000, 877_140_000],
            9 => [918_630_000, 956_070_000, 5_000_040_000],
        ];
```
Model `LifetimeAwardTranche` (final, fillable, int casts). Accessor:
```php
    /** @return list<array{tranche: int, amount_paise: int}> ordered by tranche (client 2026-10-09). */
    public function lifetimeAwardTranches(int $rank): array
    {
        if ($this->lifetimeAwardTrancheCache === null) {
            $this->lifetimeAwardTrancheCache = [];
            foreach (DB::table('lifetime_award_tranches')->orderBy('rank_number')->orderBy('tranche')->get() as $row) {
                $this->lifetimeAwardTrancheCache[(int) $row->rank_number][] = ['tranche' => (int) $row->tranche, 'amount_paise' => (int) $row->amount_paise];
            }
        }
        return $this->lifetimeAwardTrancheCache[$rank] ?? [];
    }
```
Catalogue seeder: replace the itemised list with, per rank and tranche, `['Merchandise, tranche A — items to be specified by the company', <amount>]` (A/B/C by index) so the reconciliation holds; keep the header comment noting the client will supply the item list.

- [ ] **Step 4: Milestone per tranche.** Migration 100900: add `tranche` (`unsignedTinyInteger`, default 1, after `rank_number`), `amount_paise` (`unsignedBigInteger`, default 0), drop the old unique on (`distributor_id`,`rank_number`) and add unique (`distributor_id`,`rank_number`,`tranche`) named `uq_award_milestone_dist_rank_tranche`. Model: add both to `$fillable`/casts; replace `releaseThreshold()`+`isReleasable()` with

```php
    /** A tranche is released once the rank has been qualified at least `tranche` times (client 2026-10-09). */
    public function isReleasable(): bool
    {
        return $this->qualification_count >= $this->tranche;
    }
```
Grep `releaseThreshold(` and remove callers. `syncLifetimeAward()`:

```php
        $tranches = $this->plan->lifetimeAwardTranches($rank);

        foreach ($tranches as $t) {
            if ($qualificationCount < $t['tranche']) {
                break;
            }

            $milestone = LifetimeAwardMilestone::firstOrCreate(
                ['distributor_id' => $distributorId, 'rank_number' => $rank, 'tranche' => $t['tranche']],
                [
                    'triggered_month' => $monthStart,
                    'qualification_count' => $qualificationCount,
                    'amount_paise' => $t['amount_paise'],
                    'award_description' => sprintf('%s — tranche %s, merchandise per plan', $this->plan->rankName($rank), chr(64 + $t['tranche'])),
                    'status' => LifetimeAwardMilestone::STATUS_PENDING,
                ],
            );

            if ($milestone->status === LifetimeAwardMilestone::STATUS_PENDING) {
                $milestone->update(['qualification_count' => $qualificationCount]);
            }
        }
```
(`qualificationCount` computed as today.) Note `triggered_month` of tranche B is the month of the second qualification: the `firstOrCreate` only fires when `$qualificationCount` first reaches 2, which is that month's run.

After the loop, prune what a rebuild can leave behind (fail-safe principle 7): a **pending** milestone of this rank whose `tranche > $qualificationCount` was created when the count was higher and is no longer earned. Delete it with an audit row; delivered or cancelled rows are never touched:

```php
        $orphans = LifetimeAwardMilestone::query()
            ->where('distributor_id', $distributorId)->where('rank_number', $rank)
            ->where('status', LifetimeAwardMilestone::STATUS_PENDING)
            ->where('tranche', '>', $qualificationCount)
            ->get(['id', 'tranche', 'amount_paise', 'triggered_month']);

        if ($orphans->isNotEmpty()) {
            AuditLog::create([
                'action' => 'awards.tranche.unearned_pending_removed',
                'subject_type' => 'distributor',
                'subject_id' => $distributorId,
                'details' => ['rank' => $rank, 'qualification_count' => $qualificationCount, 'removed' => $orphans->toArray()],
            ]);
            LifetimeAwardMilestone::whereIn('id', $orphans->pluck('id'))->delete();
        }
```
Test: qualify rank 3 in July and August (tranches A, B), wipe August with `MonthRebuilder`, re-run July → tranche B is gone, tranche A pending, audit row present.

- [ ] **Step 5: Merchandise only.** `AdminLifetimeAwardsController::markDelivered()`: drop the `disbursement_type` input entirely (there is one kind now) and delete the cash branch (lines 165–190 compute gross/TDS and credit `awards_credit` to the wallet — delete, keep the goods path). `AdminAwRwCalculationController::index()`: remove the `type` filter. Blades: remove the "cash"/"goods" option and filter, add a "Tranche" column (A/B/C via `chr(64 + $row->tranche)`) and the `amount_paise` via `IndianNumber`. `BonusCalculationSnapshots::awRwMonths()`: group counts by tranche as well as rank and drop the cash gross/TDS/net fields from the shape (keep the budget). The cash columns, constants and the `awards_credit` payout path are removed in Task 13 (one place, with the enum guard).

- [ ] **Step 6: Run** `--filter=LifetimeAward`, `--filter=AwRw`, `--filter=RankBonusService`, `MonthRebuildTest` (pending milestones are deleted on rebuild — the per-tranche rows must still be matched by `pendingMilestones($month)`) → PASS. Help: Awards section = the tranche table, release rule, "merchandise only, never cash". **Commit** `feat(awards): per-tranche lifetime awards with the 2026-10-09 amounts, merchandise only` with the compliance trailer.

---

### Task 13: Stale settings, columns, statuses and code sweep (user request 2026-10-09)

Run **after Tasks 1–11** and before Task 12. Each removal is one commit, each guarded by the full Compensation + Admin suites. Nothing here changes a rule; it removes what the rules above made dead.

**Files:**
- Create: `app/app/Modules/Compensation/Database/Migrations/2026_10_09_101000_drop_lifetime_award_cash_columns.php`
- Create: `app/app/Modules/Compensation/Database/Migrations/2026_10_09_101100_remove_admin_charge_applies_to_awards_setting.php`
- Create: `app/app/Modules/Compensation/Database/Migrations/2026_10_09_101200_narrow_legacy_repurchase_held_statuses.php`
- Modify: `app/app/Modules/Compensation/Models/LifetimeAwardMilestone.php`, `app/app/Modules/Compensation/Enums/BonusType.php:23-25`, `app/app/Modules/Compensation/Services/CompensationPlanSettingsService.php:71,192` (`applies_to_awards`, `adminChargeAppliesTo()` awards branch), `app/database/seeders/SettingsSeeder.php`, `app/app/Modules/Admin/Http/Controllers/AdminSettingsController.php` (registry entry), `app/app/Modules/Compensation/Services/PayoutService.php:630-710` (group-C `awards_credit` sums and the `applies_to_awards` comment), `app/app/Modules/Compensation/Services/WalletService.php:49` (`awards_credit` in the commission-type list)
- Modify: `app/app/Modules/Compensation/Models/GsbCutoffResult.php`, `GbbMonthlyResult.php` (legacy `repurchase_held` / `repurchase_suspended` constants and `POOL_EXCLUDED_STATUSES`), `app/app/Modules/Compensation/Http/Controllers/Admin/AdminGsbCalculationController.php`, `GrowthBoosterBonusService.php` (the `held` counter in the return shape), and the five blades that branch on them: `resources/views/income/growth-booster.blade.php`, `resources/views/admin/compensation/gsb-calculation/index.blade.php`, `resources/views/admin/compensation/gbb-input-output/index.blade.php`, `resources/views/admin/compensation/gbb/show.blade.php`, `resources/views/admin/compensation/gbb-calculation/index.blade.php`; `resources/help/compensation.md` (remove the held/suspended explanations)
- Test: `tests/Feature/Console/ProductionSeederPlanDefaultsTest.php`, `tests/Modules/Admin/AdminSettingsViewTest.php` (both list `applies_to_awards`), `PayoutServiceTest.php`, `AdminGbbInputOutputTest.php`, `AdminGsbCalculationTest.php`, `LifetimeAwardCatalogTest.php`

**Interfaces:**
- Removes: `comp.admin_charge.applies_to_awards`; `BonusType::LifetimeAwards` participation in `adminChargeAppliesTo()`; `lifetime_award_milestones.disbursement_type/gross_paise/admin_charge_paise/tds_paise/net_paise`; `LifetimeAwardMilestone::DISBURSEMENT_GOODS/CASH`; the `awards_credit` wallet type as a payout group; `GsbCutoffResult::STATUS_REPURCHASE_HELD`, `GbbMonthlyResult::STATUS_REPURCHASE_HELD/STATUS_REPURCHASE_SUSPENDED` (names per the model constants — read them first).

- [ ] **Step 1: Awards cash columns and setting.** Migration 101000: drop the five columns (`down()` re-adds them nullable); remove them and both `DISBURSEMENT_*` constants from the model, `@property` block, `$fillable`, `casts()`. Migration 101100: `DB::table('settings')->where('key', 'comp.admin_charge.applies_to_awards')->delete()` with a `plan.migration.*` audit row recording the deleted value. Remove the key from `SCALAR_DEFAULTS`, `SettingsSeeder`, the registry, and the `applies_to_*` match in `adminChargeAppliesTo()`; remove `BonusType::LifetimeAwards` if nothing else reads it (grep). In `PayoutService` remove the `awards_credit` group-C sums and the `applies_to_awards` comment; in `WalletService::` line 49 remove `'awards_credit'`. Run `grep -rn "awards_credit\|applies_to_awards\|LifetimeAwards\b\|DISBURSEMENT_" app resources tests database/seeders` → empty (historical migrations excepted). Fix the two tests that enumerate settings keys. Commit `chore(awards): remove the cash disbursement path, its setting and columns`.

- [ ] **Step 2: Legacy held/suspended statuses.** These came from the 2026-09-06 "hold and release" model that the 2026-09-07 forfeit spec replaced; no engine writes them, and after the history replay (dev/staging) or the pre-launch wipe (prod) no row carries them. Migration 101200 **asserts first**:
```php
        $gsb = DB::table('gsb_cutoff_results')->whereIn('status', ['repurchase_held'])->count();
        $gbb = DB::table('gbb_monthly_results')->whereIn('status', ['repurchase_held', 'repurchase_suspended'])->count();
        if ($gsb > 0 || $gbb > 0) {
            throw new RuntimeException("Refusing to narrow status enums: {$gsb} gsb_cutoff_results and {$gbb} gbb_monthly_results rows still carry a legacy held/suspended status. Replay or wipe history first.");
        }
```
then narrows the MySQL `ENUM`s and the SQLite `CHECK` constraints (copy the exact current value lists from the latest widening migrations; keep the SQLite branch). Remove the constants, the `held` key from `GrowthBoosterBonusService::runForMonth()`'s return shape and its docblocks, the `held` columns/branches in the two controllers and five blades, and the help-doc sentences. `php -l` the compiled views. Commit `chore(compensation): retire the pre-forfeit held/suspended statuses`.

- [ ] **Step 3: Dead settings scan.** `grep -o "'comp\.[a-z_.]*'" database/seeders/SettingsSeeder.php | sort -u` versus the same over `CompensationPlanSettingsService.php` must match exactly (it does today; keep it so). `grep -rn "rankPoolPct\|gbbAgpCap\|releaseThreshold\|currentRank(" app resources tests` → only `currentRank()` in `RepurchaseCycleService` and its repurchase-obligation callers remain.

- [ ] **Step 4: Run** the whole `tests/Modules/Compensation`, `tests/Modules/Admin`, `tests/Feature/Console` suites → PASS.

---

### Task 12 (run last): Spec record, Pint/Larastan, full suite, deploy checklist

**Files:**
- Create: `docs/compensation/rsp-new-updates-2026-10-09.md` — copy the "Spec summary", "Decisions" and "Assumptions" sections of this plan verbatim, plus a "Where each number lives" table (setting key / table.column per parameter) and the deploy checklist below.
- Modify: `docs/roadmap.md` — one line under the current phase pointing at the new spec doc.
- Modify: `docs/compliance/risk-register.md` — add **R-1xx**: "Lifetime Awards are flat per-rank merchandise values with no stated turnover share; hard rule 2 requires every reward to trace to product sales. Ranks derive from sale BV, so the link is indirect. Open question to the client: state the funding source (e.g. a % of monthly turnover) before launch." Status open.

- [ ] **Step 1:** `vendor/bin/pint --dirty` and `vendor/bin/phpstan analyse` (level 7) → clean.
- [ ] **Step 2:** Full module suite with the test-DB overrides: `php artisan test tests/Modules/Compensation tests/Modules/Commerce tests/Modules/Admin` → PASS. Paste the summary line into the final report.
- [ ] **Step 3:** Compile-check views: `php artisan view:clear && php artisan view:cache` then `for f in storage/framework/views/*.php; do php -l "$f" >/dev/null || echo "$f"; done` → no output.
- [ ] **Step 4:** Write the deploy checklist into the spec doc:
  1. **Snapshot first**: `mysqldump` of the environment's DB to the server's backup path (staging: `/home/master/applications/ahdhesuhty/`, prod: per `cloudways_prod_deploy` memory). Record the file name in the deploy log.
  2. **Stop the workers and the scheduler** (`app:deploy --maintenance` does this). No migration in this plan may run against a live 00:05 evaluate or a 1st-of-month freeze (fail-safe rules above).
  3. `php artisan migrate` (adds columns/tables, re-dates open cycles, moves settings; each data migration writes its `plan.migration.*` audit row). Read the audit rows back: the number of re-dated cycles, how many are now past due (F-1), and whether `comp.gbb.pool_rate_bp` / `comp.rank.aogo_points` were moved or left.
  4. `php artisan repurchase:evaluate` once, inside the window (F-1): resolves every cycle the re-date made past due before any cut-off prices another day.
  5. `php artisan db:seed --class=RankTiersSeeder --class=LifetimeAwardTranchesSeeder --class=LifetimeAwardRewardsSeeder` — RankTiersSeeder is an upsert that **overwrites** admin edits to rank tiers; confirm with the user per environment before running (CLAUDE.md destructive-action rule).
  6. **History (decision 5, as amended by F-7):** dev and staging only — `compensation:recompute-all` with the before/after reconciliation of Step 2b around it. **Production: no history rebuild** (impossible month by month by design; pre-launch wipe removes the old months). Destructive on dev/staging; needs the user's explicit yes per environment.
  7. Restart scheduler + queue workers; `php artisan migrate:status --pending` must be empty.
  8. `php artisan view:cache` and `npm run build` on the server (blades changed). Use `/usr/bin/php8.4` for every artisan call on Cloudways.
  9. First night after deploy: read the engine-health digest and the `gsb_cutoff_deferrals` count; first 1st-of-month: check `rank_monthly_passes` has exactly two rows for the month and the F-9 identities hold on the admin Rank report.
- [ ] **Step 5: Commit** `docs(compensation): record the 2026-10-09 R.S.P. updates, decisions and deploy steps`.

---

## Not in this plan (deliberately)
- Renaming "Mentorship Bonus" to "Mentorship Royalty" in distributor-facing copy for rank 6+ (copy/UX decision; raise with the client along with the award funding question).
- The merchandise item list for awards (client to supply; placeholder catalogue seeded).
- Any change to the weekly payout cadence, admin charge, TDS, repurchase deduction, Fortune Bonus or ADC — the doc does not touch them.
- Staging/production deploy — needs per-deploy approval.

## Self-review notes
- Spec coverage: §1 → Task 1 (+ existing engine); §2 → Tasks 2–4; §3 → Tasks 5–7; §4 → Tasks 8–10; §5 → Task 11; records → Task 12.
- Fail-safe review names: `rankAsOf(int, Carbon)` + `warmRanksAsOf(array, Carbon)` (F-2, Task 3), `mentorship_bonus_results.status = 'repurchase_gated'` + `sponsor_verdict_stale` + `royalty_cap_paise` (F-3/4/5), `released_rule_changed_at` (F-11), `IncomeEligibilityService::unresolvedDueOnOrBefore(Carbon, array)` (principle 2, Tasks 7 and 9), `PlanInvariantsTest.php` (Step 2a), `plan.migration.*` audit actions, `awards.tranche.unearned_pending_removed`, `msb.royalty.cap_withheld`.
- User overrides applied 2026-10-09 to the fail-safe review: stale columns (`pool_pct` ×2, award cash columns, legacy MSB columns) are **dropped in this release**, not deferred; cap settings **throw** below ₹1 instead of clamping; Task 13 sweeps stale settings, statuses and code.
- Order of execution: 1 → 2 → 3 → 4 → 5 → 6 → 7 → 8+9 (back to back) → 10 → 11 → 13 → 12.
- Names used across tasks: `msbPointValueCapPaise`, `msbRoyaltyMinRank`, `msbRoyaltyFailedDailyCapPaise`, `gbbPointValueCapPaise`, `rankPointValueCapPaise`, `rankFirstPassMaxRank`, `rankRapPoints(): int`, `lifetimeAwardTranches`, `RankMonthlyPass`, `LifetimeAwardTranche`, `STATUS_REPURCHASE_FAILED_BLOCKED`, `reservedPointsFor(int, int, Carbon)` — consistent in every task that cites them.
- Review-focus items 1–5 are pinned in Tasks 9, 9, 4, 6 and 1 respectively.
