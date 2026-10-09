# R.S.P. — New Updates (2026-10-09): compensation engine changes

**Status:** Implemented on branch `feat/compensation-rsp-updates-2026-10` (Tasks 1–14 of
`docs/plans/compensation-rsp-updates-2026-10-09.md`, 2026-10-09). Local commits only;
nothing is deployed. The user merges to `main` after `docs/plans/rsp-reports/FINAL-QA-signoff.md`
says APPROVED, then deploys per §8 with a per-environment yes.

**Sources**

- Client Google Doc `1c7fWGWMkOfKP-AMkjLNJAU6AbcJu9oWHvkjUhlGqMWw`, section "R.S.P. - NEW UPDATES"
  (subsections 1 Re-purchase, 2 Mentorship Bonus, 3 Growth Booster Bonus, "RANK INCOME POINT
  SYSTEM" dated 05-10-2026, "LIFETIME AWARDS AND REWARDS").
- The user's decisions of 2026-10-09 (§2) and the fail-safe review of the same day (plan file,
  findings F-1 … F-12).
- Per-task records: `docs/plans/rsp-reports/2026-10-09-task-*.md` (what changed, the test that pins
  each client figure, the Playwright screenshots).

Earlier client documents that still apply unchanged: `repurchase-client-examples-2026-09-07.md`
(the forfeit model; only the due date moved) and `kp-clarifications-2026-06-26.md` (the slab table,
grace rules, admin charge).

---

## 1. Spec summary (what the client asked for)

### 1.1 Repurchase
- Cycle begins the day a distributor first reaches 600 BV of personal purchase (already implemented). Window is **30 days**; the doc's example is 14-02-2026 → 15-03-2026, i.e. **due_date = start + 29 days** (the start day counts as day 1).
- Obligation: non-ranked 600 BV; ranks 1–9: 1,000 / 1,100 / 1,200 / 1,300 / 1,400 / 1,600 / 1,800 / 2,000 / 2,300 BV (already seeded in `rank_tiers.repurchase_bv_paise`).
- Two conditions on the last day: BV met within the window AND repurchase wallet = ₹0. Either fails → failed. Failed days forfeit downline/Left/Right Genos BV; a fresh 30-day cycle starts on the fulfilment day. (Already implemented, 2026-09-07 spec.)
- Wallet: personal purchases consume the repurchase wallet before the bank (already implemented). An Easy Purchase order placed by a customer through a distributor's link must never debit the distributor's wallet (already true: the wallet is the **buyer's**, `Auth::user()?->distributor?->id`; pinned by a test in Task 1).

### 1.2 Mentorship Bonus (MSB) — 3%
- Points per sponsee slab: 21/18/15/12/9/6/3 (already seeded `gsb_slabs.msb_score`). Point value = 3% of the day's BV ÷ the day's total points, floored to whole rupees (already implemented), **capped at ₹120 per point** (new).
- Sponsor up to rank 5: if the sponsor is failed on their repurchase condition on the cut-off day, the MB points from their sponsees' slab matches that day are **not awarded** (new).
- Sponsor rank 6+: MB becomes "Mentorship Royalty". Paid even while failed, but while failed the sponsor's MB is **capped at ₹3,600 per day** (₹1,08,000 per month) (new). While not failed, full amount.

### 1.3 Growth Booster Bonus (GBB) — 4%
- Pool rate **4%** (was 5%). Monthly. Points per GSB slab: slab 1 → 12, slab 2 → 5, slab 3 → 2 (already seeded `gsb_slabs.agp_per_occurrence`). Point value = pool ÷ total points, floored, **capped at ₹240 per point** (new).
- Only for distributors who have **never** held a rank. The month they first reach Rank 1, both GBB and Rank Bonus are paid; from the next month on, never GBB again (new; was "not ranked in the previous month").
- To qualify, the distributor must satisfy the repurchase condition (600 BV within the cycle and wallet zero at the cycle end) (new gate, see A-G1).

### 1.4 Rank Bonus — 20% of the month's turnover
Rank Achievement Points (RAP): AGO offer 36; R1 72; R2 189; R3 468; R4 1,125; R5 2,583; R6 5,688; R7 11,934; R8 23,877; R9 39,501. Point value cap **₹200**.
- Pass 1: AGO + Ranks 1–3 share the whole 20% pool: value₁ = min(₹200, floor(pool ÷ Σ pass-1 points)).
- Pass 2: Ranks 4–9 share the **remainder** (pool − pass-1 payout): value₂ = min(₹200, floor(remainder ÷ Σ pass-2 points)).
- Each participant is paid own points × the pass value. Leftover stays with the company. Worked examples A1, A2, B1, B2, C1, C2, D1, D2 in the doc; D2's 8th-rank line uses 186 (the floored value), the printed "200" is a typo (user confirmed 2026-10-09).

### 1.5 Lifetime Awards & Rewards (merchandise only, never cash)
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

## 2. Decisions (user, 2026-10-09)
1. **Cycle end** = start + 29 days (option B).
2. **GBB first-rank month**: exclusion test is "qualified in any month strictly before this one" (option A).
3. **Retire** `comp.gbb.agp_cap` (option B).
4. **R9 awards**: seed the tranche sum 6,87,47,400 (option A, then confirmed).
5. **Rank Bonus history**: rebuild all past months on the new formula (option B; test data only, launch 2026-11-10). *Amended by the fail-safe review (F-7):* history is replayed only where the recompute runner exists (dev, staging). `MonthRebuilder::refusals()` refuses any month whose following month has already run, so a month-by-month production rebuild is impossible by design; production history stays on the old formula until the pre-launch wipe.
6. *(2026-10-09, Task 13)* Stale settings, columns and statuses are **dropped in this release**, not one release later: every environment holds test data that is wiped before launch.

## 3. Assumptions (stated in the commit bodies; confirm with the client)
- **A-G1** The GBB "must satisfy the repurchase condition" gate is read as: the distributor is not forfeited on the last day of the month (`verdictAsOf(monthEnd)` eligible) in addition to the existing month-end wallet-zero gate. Blocked distributors are recorded as a pool-excluded roster row.
- **A-M1** The rank used for the Mentorship gate/royalty is the sponsor's highest qualified rank **as decided by the cut-off date**: the maximum `rank_qualifications.rank_number` with `status = qualified` and `month_start` strictly before the cut-off's month (a month's rank is decided on the 1st of the next month, so it is not yet known on any day inside that month). `RepurchaseCycleService::currentRank()` is the lifetime maximum with no date and is NOT used here — see F-2 in the fail-safe review.
- **A-M2** The ₹3,600 royalty cap is per sponsor per cut-off day, aggregated across all their sponsees' accruals that day; the excess is withheld permanently (recorded, never released).
- **A-R1** The AGO offer stays a Rank-1-row participant (36 points, pass 1); `comp.rank.aogo_lifetime_max` and the grant mechanics are unchanged.
- **A-A1** Each tranche is released when `qualification_count ≥ tranche number`; the existing 1/2/3 rank-based release threshold is replaced by this per-tranche rule.

Open questions for the client (raise together, before launch):
1. Funding source of the Lifetime Awards (hard rule 2; risk register R-114).
2. Whether "Mentorship Bonus" is renamed "Mentorship Royalty" in distributor-facing copy for rank 6+ (copy only; not built).
3. The merchandise item list per tranche (placeholder catalogue seeded).
4. `comp.rank.first_pass_max_rank` must stay ≥ 1: at 0 the AGO offer would be priced in pass 2 together with Rank 1 (A-R1 holds for every value ≥ 1). Nobody should set 0 without a decision.
5. The two engine gates with no setting (GBB lifetime exclusion, GBB verdict gate) are code; if the client wants them reversible that is a separate decision (no flag added silently — feature-flag zero-trace rule).

## 4. Where each number lives

Every parameter is DB-driven (`compensation_plan_settings` scalars edited at Admin → Settings →
Compensation plan, or the plan tables edited at Admin → Compensation → Plan settings). Nothing in engine
code is a rupee constant. "Frozen on" is the column that keeps the value a period was priced with, so a
later edit never moves a frozen day or month. "Neutralise with" is the operational fallback (F-12) if the
client reverses a decision — no deploy needed.

| Rule | Client value | Setting / column | Default stored | Frozen on | Neutralise with |
|---|---|---|---|---|---|
| Repurchase window | 30 days inclusive, due = start + 29 | `comp.repurchase.cycle_days`; `RepurchaseCycleService` computes `due = start + (cycle_days − 1)` | `30` | `repurchase_cycles.cycle_start_date` / `due_date` | — (a cycle length is not reversible per cycle; set 31 to return to the old +30 reading for *new* cycles) |
| Repurchase obligation | 600 BV; R1–R9 1,000 … 2,300 | `comp.repurchase.non_ranked_bv_paise`; `rank_tiers.repurchase_bv_paise` | 60 000; per rank | `repurchase_cycles.required_bv_paise` | — |
| MSB pool | 3 % | `comp.msb.pool_rate_bp` | 300 | `msb_daily_pools.pool_rate_bp` | — |
| MSB points per slab | 21/18/15/12/9/6/3 | `gsb_slabs.msb_score` | seeded | `mentorship_bonus_results.msb_points` | — |
| MSB point-value cap | ₹120 | `comp.msb.point_value_cap_paise` | 12 000 | `msb_daily_pools.point_value_cap_paise`, `raw_point_value_paise` | `100000000` (₹10 lakh, effectively uncapped) |
| MSB failed-sponsor gate | no MB points while failed, rank ≤ 5 | `comp.msb.royalty_min_rank` (gate applies below it); rank as-of the cut-off via `RepurchaseCycleService::rankAsOf()` | 6 | `mentorship_bonus_results.status = repurchase_gated`, `sponsor_repurchase_failed`, `sponsor_verdict_stale` | `1` (everyone is royalty: no gate, cap still applies) |
| Mentorship Royalty daily cap | ₹3,600 while failed, rank 6+ | `comp.msb.royalty_failed_daily_cap_paise` | 360 000 | `mentorship_bonus_results.royalty_cap_paise`, `royalty_cap_withheld_paise` | `100000000` |
| GBB pool | 4 % (was 5 %) | `comp.gbb.pool_rate_bp` | 400 | `gbb_monthly_pools.pool_rate_bp` | `500` |
| GBB points per slab | 12 / 5 / 2 | `gsb_slabs.agp_per_occurrence` | seeded | `gbb_monthly_results.agp_earned` | — |
| GBB point-value cap | ₹240 | `comp.gbb.point_value_cap_paise` | 24 000 | `gbb_monthly_pools.point_value_cap_paise`, `raw_point_value_paise` | `100000000` |
| GBB per-distributor AGP cap | retired | `comp.gbb.agp_cap` **deleted** (migration 100300) | — | — | — (there is no cap to restore) |
| GBB lifetime rank exclusion | never GBB after the first ranked month | code: `GrowthBoosterBonusService` reads `rank_qualifications` for every month strictly before M; `gbb:monthly-run` walks every earlier month's `rank.check` run | — | roster absence (no row) | none — code (decision needed before any flag) |
| GBB repurchase verdict gate | not forfeited on the month's last day (A-G1) | code: `IncomeEligibilityService::verdictAsOf(monthEnd)`; `unresolvedDueOnOrBefore()` refuses the freeze while a cycle is unresolved | — | `gbb_monthly_results.status = repurchase_failed_blocked` (out of `total_agp`) | none — code; engine flag OFF = fail-open (no gate) |
| Rank envelope | 20 % of the month's turnover | `comp.rank.envelope_bp` | 2 000 | `rank_monthly_passes.envelope_bp`, `envelope_paise`, `company_turnover_paise` | — |
| RAP per rank | 72 / 189 / 468 / 1,125 / 2,583 / 5,688 / 11,934 / 23,877 / 39,501 | `rank_tiers.rap_points` (Plan settings → Rank tiers) | seeded | `rank_monthly_pools.rap_points`; `rank_bonus_results.rap_points` | — |
| AGO offer points | 36 | `comp.rank.aogo_points` | 36 | `rank_bonus_results.aogo_points`; `rank_monthly_pools.aogo_points` | — |
| Rank point-value cap | ₹200 | `comp.rank.point_value_cap_paise` | 20 000 | `rank_monthly_passes.point_value_cap_paise`, `raw_point_value_paise` | `100000000` |
| Two-pass split | pass 1 = AGO + R1–R3, pass 2 = R4–R9 from the remainder | `comp.rank.first_pass_max_rank`; `rank_monthly_passes` (exactly two rows per month); `rank_monthly_pools.pass` | 3 | the pass rows (`pool_paise`, `total_points`, `point_value_paise`, `payout_paise`, `leftover_paise`) | `9` (one pass, one pool, same cap) — **not** the old per-rank split, which is gone |
| Per-rank pool percentages | retired | `rank_tiers.pool_pct` and `rank_monthly_pools.pool_pct` **dropped** (migrations 100500 / 100700) | — | — | — |
| Award tranches | table in §1.5 | `lifetime_award_tranches (rank_number, tranche, amount_paise)` — SSOT; `rank_tiers.lifetime_award_budget_paise` = Σ tranches; `lifetime_award_rewards` catalogue reconciles to the budget | seeded (`LifetimeAwardTranchesSeeder`) | `lifetime_award_milestones.tranche`, `amount_paise`, `qualification_count`, `triggered_month` | edit the tranche rows (Plan settings) — takes effect on the next qualification |
| Award release rule | tranche n on the n-th qualification | code: `LifetimeAwardMilestone::isReleasable()` = `qualification_count ≥ tranche`; `released_rule_changed_at` flags rows the migration made releasable (F-11) | — | — | — |
| Award disbursement | merchandise only | cash path removed (Task 13): no `disbursement_type`, no `awards_credit` written, `comp.admin_charge.applies_to_awards` deleted | — | — | — |

Retired statuses: `repurchase_held` / `repurchase_suspended` no longer exist on `gsb_cutoff_results` and
`gbb_monthly_results` (migration 101200); `repurchase_held` no longer exists on `rank_bonus_results`
(101400) and `awards_credit` no longer exists in `wallet_ledger_entries.type` (101300) — Task 14, §10.
Still carrying `repurchase_held` (0 rows, nothing writes it): `fortune_bonus_results.status`.

## 5. What each task changed, and the test that pins the client figure

| Task | Commit | Change | Pinned by |
|---|---|---|---|
| 1 | `900e46b7` | due = start + 29; open cycles re-dated by migration 100000 (audit `plan.migration.redate_open_repurchase_cycles_to_29_days` lists every moved id and the ids now past due); Easy Purchase wallet rule | `RepurchaseCycleDueDateTest` (14 Feb → 15 Mar; verdict on 16 Mar), `RedateOpenRepurchaseCyclesTo29DaysMigrationTest`, `EasyPurchaseWalletTest` |
| 2 | `f5844037` | MSB point value `min(cap, floorRupee(pool ÷ points))`; raw + cap frozen on the pool row; cap < ₹1 or not whole rupee → `RuntimeException` before any write (F-6) | `MsbDailyPoolServiceTest` (150 → 120; 108.5383 → 108; negative day → 0), `AdminMsbCalculationTest` |
| 3 | `cc7cb9d3` | failed sponsor below `royalty_min_rank` → `repurchase_gated` row with the points, ₹0, out of the denominator; rank as-of the cut-off (`rankAsOf`, F-2) | `MentorshipBonusServiceTest`, `GsbDailyCutoffCommandTest` (denominator 21) |
| 4 | `0b556a88` | failed rank-6+ sponsor capped at ₹3,600/day across all accruals; cap frozen per row; excess withheld with audit `msb.royalty.cap_withheld`; F-4 `sponsor_verdict_stale` | `MentorshipBonusServiceTest` (252,000 + 108,000 = 360,000; both orders; mid-day change) |
| 5 | `dc2d7d29` | GBB 4 %, ₹240 cap, `agp_cap` retired (migration 100300, moved only from the old default) | `GrowthBoosterBonusServiceTest` (625 AGP: 320 → 240), `UpdateGbbSettingsMigrationTest` |
| 6 | `c38df53f` | lifetime rank exclusion (any earlier month); `gbb:monthly-run` walks every earlier month's rank check; rebuild preview warning (F-8) | `GrowthBoosterBonusServiceTest` (M−2 excluded; first-rank month paid; voided rank ignored), `MonthRebuildTest`, `EngineRunRecorderTest` |
| 7 | `90d9a5d6` | month-end verdict gate (A-G1): `repurchase_failed_blocked` rows out of `total_agp`; freeze refuses while a cycle due ≤ month end is unresolved (principle 2) | `GrowthBoosterBonusServiceTest` (12 payable + 12 blocked → value on 12; F-1 last-day boundary; refusal writes nothing) |
| 8 | `6efb67d1` | `rank_tiers.rap_points`, `pool_pct` dropped, AGO 36, `comp.rank.point_value_cap_paise`, `comp.rank.first_pass_max_rank` (migrations 100500 / 100600) | `CompensationPlanSettingsServiceTest` |
| 9 | `e4aadca6` | one 20 % envelope (`intdiv`, F-9) priced in two passes; `rank_monthly_passes`; pools become per-rank allotments; freeze reconciliation in the transaction; `unresolvedDueOnOrBefore` guard | `RankBonusServiceTest` (A2 ₹188; C1 ₹200 capped; D2 pass 2 ₹186; empty pass 1; refund-heavy month → 0), `rankAssertPassIdentities()` |
| 10 | `33588495` | Input & Output report, formula block and snapshots show the passes; legacy months labelled (F-7); missing pass row flagged, never "leftover ₹0" | `AdminRankBonusInputOutputTest`, `AdminRbCalculationTest` |
| 11 | `d5c78319` | `lifetime_award_tranches`, per-tranche milestones, 2026-10-09 amounts, F-11 backfill + flag, catalogue replaced (migrations 100800 / 100900 / 100950) | `LifetimeAwardCatalogTest` (whole table; Σ tranches = Σ items = budget), `LifetimeAwardTranchesMigrationTest`, `RankBonusServiceTest` (tranche A then B) |
| 13 | `332d7754`, `9daa0faa` | cash disbursement path, `applies_to_awards`, held/suspended statuses removed (migrations 101000 / 101100 / 101200; 101100 refuses while an unswept `awards_credit` row exists, 101200 while any row still carries a retired status; 101000 drops the cash columns after recording every row that held a value) | `LifetimeAwardCashRemovalMigrationTest`, `LegacyStatusNarrowingMigrationTest`, `PayoutServiceTest` |
| 12 | this commit | `PlanInvariantsTest` (F-5 order-independence, F-9 identities + 50 seeded random draws, F-10 atomic freeze, Task 7 `total_agp` identity, MSB freeze identity, ledger Σ = roster Σ for Rank and GBB); the `comp.msb.point_value_cap_paise` registry `max` raised from 1 000 000 to 100 000 000 so the F-12 neutralisation value can actually be saved (the other three caps already allowed it); Larastan baseline re-synced; this record | `PlanInvariantsTest`, `CompensationPlanSettingsServiceTest` (100000000 accepted) |

Pre-existing milestones (Task 11 hand-off): a milestone created before the tranche model with
`qualification_count ≥ 2` has tranche B (and C) opened on its next appearance on a frozen roster, or by
the dev/staging replay; it carries `released_rule_changed_at` when the new rule made it releasable at
migration time. Production keeps its old milestone descriptions until the pre-launch wipe.

## 6. Fail-safe guards and audit actions (where to look when something refuses)

| Guard | Where | What it does |
|---|---|---|
| Cap settings below ₹1 or not a whole rupee | MSB and GBB point-value caps: `CompensationPlanSettingsService::msbPointValueCapPaise()` and `gbbPointValueCapPaise()` throw for < 100 or not a multiple of 100; the royalty daily cap `msbRoyaltyFailedDailyCapPaise()` throws for < 100 only (it is a day total, not a point value). Rank: `rankPointValueCapPaise()` throws for < 100 or not a multiple of 100 and `rankFirstPassMaxRank()` throws outside 1–9 (no clamp); `RankBonusService::assertFreezable()` reads both before any write (Task 14). Registry `min` 100, `max` 100000000, `multiple_of` 100 on all four caps; `comp.rank.first_pass_max_rank` `min` 1. | `RuntimeException` before any write; the run recorder marks the run failed and the 08:00 digest reports it. Never clamped or rounded. Settings Save refuses a non-whole-rupee cap with "Enter a whole-rupee amount (a multiple of 100 paise)." and keeps the old value (Task 14); a bad row reaching the engine by any other route is still refused at the freeze. |
| Royalty rank outside 1–9 | `msbRoyaltyMinRank()` | `RuntimeException`, nothing written |
| RAP ≤ 0 on a rank with achievers; missing tranche row for a rank with achievers | `RankBonusService::freezeMonth()` | `RuntimeException` before the first pool/pass/milestone row |
| Unresolved repurchase cycle due on or before the month end | `IncomeEligibilityService::unresolvedDueOnOrBefore()` → `RepurchaseVerdictsPending` (GBB and Rank freezes) | refuses the freeze ("Run repurchase:evaluate first"); recorded as a failed run with the remedy |
| Freeze reconciliation | Rank: Σ roster gross = Σ pass payout ≤ envelope inside the freeze transaction; GBB/MSB: identity by construction, pinned in `PlanInvariantsTest` | mismatch throws and rolls back |
| Pass rows and pool rows | same transaction (F-10) | a freeze that throws leaves no pass rows |
| Migrations | every data migration writes one `plan.migration.<name>` audit row (ids, before/after, `moved` flags); `down()` restores only listed ids or throws "restore from the plan.migration.* audit row"; 101100 refuses while an unswept `awards_credit` row exists; 101200 refuses while any `repurchase_held` / `repurchase_suspended` row exists | nothing moves silently |

Audit actions added by this plan: `plan.migration.redate_open_repurchase_cycles_to_29_days`,
`plan.migration.update_gbb_settings_and_add_point_value_cap`, `plan.migration.rank_tiers_rap_points_two_pass`,
`plan.migration.rank_bonus_settings_two_pass`, `plan.migration.rank_monthly_pools_two_pass`,
`plan.migration.lifetime_award_tranches`, `plan.migration.add_tranche_to_lifetime_award_milestones`,
`plan.migration.lifetime_award_catalogue`, `plan.migration.drop_lifetime_award_cash_columns`,
`plan.migration.remove_admin_charge_applies_to_awards_setting`,
`plan.migration.narrow_legacy_repurchase_held_statuses`, `msb.credit.repurchase_gated`,
`msb.royalty.cap_withheld`, `gbb.result.excluded_from_frozen_denominator`,
`awards.tranche.unearned_pending_removed`.

Risk register: R-114 (award funding source, open), R-115 (verdict-dependent gates: F-1 last-day
boundary, F-4 stale verdict, F-7 production history), R-70 wording updated to the lifetime exclusion.

## 7. History and reporting (decision 5 as amended by F-7)

- **Dev and staging:** replay history with `compensation:recompute-all` (ADR-0014, test environments
  only) after the migrations. The replay re-derives every month on the new rules oldest-first, which
  F-8 requires (a later month's GBB roster depends on every earlier month's rank qualifications).
- **Production:** **no history rebuild.** `MonthRebuilder::refusals()` refuses a month once the following
  month has run, so a month-by-month rebuild is impossible by design, and the recompute runner must
  never exist there. Months frozen before the deploy stay on the per-rank-pool formula (and the old GBB
  rate/rule, the old award milestones); the pre-launch wipe before 2026-11-10 removes them.
- **Labelling:** a Rank Bonus month with no `rank_monthly_passes` rows is rendered on the admin report
  and drill-down as priced under the per-rank pool rule in force before the deploy, with "—" in every
  Pass cell and no pass summary; the CSV carries the same label. A month whose pass-2 row is missing is
  flagged "freeze is incomplete — leftover unknown", never "leftover ₹0".

## 8. Deploy checklist (per environment, with the user's explicit yes each time)

Nothing here runs without approval (memory: no deploy without approval). Use `/usr/bin/php8.4` for every
artisan call on Cloudways; bare `php` there is 8.2.

1. **Snapshot first.** `mysqldump` of the environment's database to the server's backup path (staging:
   `/home/master/applications/ahdhesuhty/`; production: per the `cloudways_prod_deploy` memory). Record
   the file name in the deploy log.
2. **Maintenance on, workers and scheduler stopped**: `php artisan down`, then stop the queue workers
   and the scheduler (Cloudways supervisor / the flock'd master crontab, per
   `docs/runbooks/cloudways-deployment.md`). No migration in this plan may run against a live 00:05
   evaluate or a 1st-of-month freeze. **Do not run `app:deploy` yet** — its pipeline runs
   `migrate --force` and `db:seed ProductionSeeder` on its own (`--skip-migrate` / `--skip-seed` turn
   them off); steps 3–6 replace that migrate, and `app:deploy` comes back in step 7.
3. `php artisan migrate --force`. Adds columns and tables, re-dates open cycles, moves settings; each data
   migration writes its `plan.migration.*` audit row. **Read the audit rows back:** the number of
   re-dated cycles and how many are now past due (F-1); whether `comp.gbb.pool_rate_bp`,
   `comp.rank.aogo_points` and the rank-4/5 award budgets were `moved` or left (an environment still on
   5 % / an admin override is visible there, never silent).
   - Migration **101200** refuses while any `gsb_cutoff_results` / `gbb_monthly_results` row still carries
     `repurchase_held` / `repurchase_suspended`, and **101100** refuses while an unswept `awards_credit`
     ledger row exists. **Linear order on dev/staging:** `migrate --force` (it stops at the first refusing
     migration; every earlier one stays applied) → step 4 `repurchase:evaluate` → step 5 seeders if
     wanted → step 6 replay (`compensation:recompute-all` wipes the held/suspended and `awards_credit`
     rows and re-derives every month on the new rules; the engines never write the retired statuses)
     → `migrate --force` again (101100/101200 and then **101300/101400** apply — 101300 refuses while
     ANY `wallet_ledger_entries` row of type `awards_credit` exists, swept or not, and 101400 while any
     `rank_bonus_results` row carries `repurchase_held`; the replay removes both) → `migrate:status
     --pending` empty → step 7.
     On production the counts are expected to be zero (the forfeit model shipped on 2026-09-07 and the
     awards cash path never paid anyone, before production existed), so `migrate` runs through in one
     go; if a count is not zero — including a paid-out `awards_credit` row, which 101300 refuses too —
     the pre-launch wipe clears it; do not delete rows by hand.
   - The three award migrations (100800 / 100900 / 100950) are data migrations with audit rows; they
     keep an admin-edited catalogue and budgets and only replace the untouched defaults.
4. `php artisan repurchase:evaluate` once, inside the same window (F-1): resolves every cycle the re-date
   made past due before any cut-off prices another day.
5. Seeders — three separate calls (`db:seed --class` takes one class; a repeated `--class` keeps only the
   last one and silently skips the others):

   ```
   php artisan db:seed --class=RankTiersSeeder
   php artisan db:seed --class=LifetimeAwardTranchesSeeder
   php artisan db:seed --class=LifetimeAwardRewardsSeeder
   ```

   All three are **upserts that overwrite admin edits** to rank tiers, tranches and the award catalogue —
   confirm with the user per environment before running (CLAUDE.md destructive-action rule). Migration
   100800 already seeded the tranches, so this step is only needed where an admin edit must be reset.
   `SettingsSeeder` now also seeds five previously unseeded scalars (`comp.admin_charge.weekly_cap_paise`,
   `comp.admin_charge.monthly_cap_paise`, `comp.monthly_income_cap_paise`, `comp.repurchase.cycle_days`,
   `comp.fortune.min_commission_paise`) insert-or-ignore — additive, safe. Do **not** run
   `CommerceFeatureFlagSeeder` (it overwrites every commerce/compensation flag).
6. **History (dev and staging only):** take the before snapshot of §9.1, run `compensation:recompute-all`,
   take the after snapshot, reconcile per §9.1 and check §9.2. Destructive; needs the user's explicit yes
   per environment. **Production: nothing** (§7).
7. `php artisan app:deploy --skip-migrate --skip-seed` (composer, caches, `view:cache`, the front-end
   build; blades changed and `git pull` never rebuilds `public/build`), then restart the scheduler and
   the queue workers and `php artisan up`. `php artisan migrate:status --pending` must be empty.
   `ProductionSeeder` is additive (it seeds plan tables only while they are empty), so `--skip-seed`
   is a precaution, not a requirement.
8. If building by hand instead: `npm run build` on Cloudways needs the nvm Node (v24) on the server,
   and `/usr/bin/php8.4` for every artisan call.
9. **First night after deploy:** read the engine-health digest and the `gsb_cutoff_deferrals` count.
   **First 1st-of-month:** `rank_monthly_passes` has exactly two rows for the month; the F-9 identities
   hold on the admin Rank report (Σ allotments + pass-2 leftover = envelope; pass-2 pool = envelope −
   pass-1 payout); `gbb_monthly_pools.total_agp` equals the credited rows' AGP; collect the first real
   two-pass screenshots for the record (none exist yet — no two-pass month could be frozen on the dev
   stack because the in-flight month is refused by the wallet gate).

## 9. Staging verification procedures (Step 2b / 2c — run at deploy, not performed in this run)

These need the staging database and the recompute runner; neither is reachable from the isolated runner
stack, so they are written here for the deploy and were **not** executed on 2026-10-09.

### 9.1 Before/after reconciliation around the replay
Before step 6 and again after it, save the output of:

```sql
SELECT type, bonus_month, COUNT(*) AS rows_, SUM(amount_paise) AS paise
FROM wallet_ledger_entries
WHERE type IN ('gsb_credit','mb_credit','gbb_credit','rank_credit','fortune_credit','adc_credit')
GROUP BY type, bonus_month ORDER BY bonus_month, type;
```

Attach both files to the deploy log. The difference per month must be explained entirely by the rule
changes: Rank (two-pass pricing at the ₹200 cap and the RAP table), GBB (4 %, ₹240 cap, lifetime
exclusion, verdict gate), MSB (₹120 cap, failed-sponsor gate, royalty cap). GSB, Fortune and ADC
totals must be unchanged apart from the repurchase re-date (F-1). The post-replay
`RepurchaseShortfallGuard` reconciliation (detect-after; `app/Modules/Compensation/Services/Rebuild/`)
must report zero residual.

### 9.2 Negative and empty periods
Run the recompute over a staging window that includes the known refund-heavy day and the known
empty-roster month, then assert:

```sql
SELECT 'msb' AS t, COUNT(*) FROM msb_daily_pools WHERE point_value_paise < 0 OR payout_paise < 0 OR leftover_paise < 0
UNION ALL SELECT 'gbb', COUNT(*) FROM gbb_monthly_pools WHERE point_value_paise < 0 OR payout_paise < 0
UNION ALL SELECT 'rank', COUNT(*) FROM rank_monthly_passes WHERE point_value_paise < 0 OR payout_paise < 0 OR pool_paise < 0
UNION ALL SELECT 'rank_over_pool', COUNT(*) FROM rank_monthly_passes WHERE payout_paise > pool_paise
UNION ALL SELECT 'gbb_over_pool', COUNT(*) FROM gbb_monthly_pools WHERE payout_paise > pool_paise
UNION ALL SELECT 'msb_rows', COUNT(*) FROM mentorship_bonus_results WHERE mb_gross_paise < 0
UNION ALL SELECT 'zero_credits', COUNT(*) FROM wallet_ledger_entries WHERE amount_paise = 0 AND type LIKE '%_credit';
```

Every count must be 0. (`rank_bonus_results.gross_paise` and `gbb_monthly_results.gbb_gross_paise` are
unsigned columns, so a negative gross there is impossible by schema; the pass/pool rows and the ledger
are where a sign error would show.) The same invariants are pinned on synthetic data by
`tests/Modules/Compensation/PlanInvariantsTest.php` (50 seeded random cohort draws with turnover from
−1 Cr to 20 Cr BV).

## 10. Leftovers recorded by the task reviews — closed by Task 14 (user request 2026-10-09)

Every item below except the main-side ones was done on this branch by Task 14 (report
`docs/plans/rsp-reports/2026-10-09-task-14.md`, which lists the commit hashes).

- **Done (L1)** — `awards_credit` dropped from the `wallet_ledger_entries.type` enum
  (migration `2026_10_09_101300`, audit `plan.migration.narrow_awards_credit_wallet_type`) and
  `repurchase_held` from `rank_bonus_results.status` (`2026_10_09_101400`,
  `plan.migration.narrow_rank_repurchase_held_status`). Both count first and refuse with
  "Replay or wipe history first." while a row carries the value; `down()` widens back (MySQL only).
  Commits `chore(wallet): drop the unused awards_credit ledger type`, `chore(rank): drop the unused
  repurchase_held result status`. Note for staging/production: the rank value came from a migration
  (`2026_09_06_100001`) later deleted from the repo as unrun, so a database that never ran it sees 101400
  as a restatement of its current enum — safe either way. Still open, out of scope:
  `fortune_bonus_results.status` also carries `repurchase_held` (0 rows, nothing writes it).
- **Done (L2 + L8)** — Save refuses a cap that is not a multiple of 100 paise ("Enter a whole-rupee amount
  (a multiple of 100 paise).") on `comp.msb.point_value_cap_paise`, `comp.msb.royalty_failed_daily_cap_paise`,
  `comp.gbb.point_value_cap_paise`, `comp.rank.point_value_cap_paise` (registry key `multiple_of`);
  `CompensationPlanSettingsService::rankPointValueCapPaise()` throws below 100 or off a whole rupee and
  `rankFirstPassMaxRank()` throws outside 1–9 (registry `min` 1) instead of clamping;
  `RankBonusService::assertFreezable()` reads both before any write (the first-pass read used to sit
  after `replacePrematureFreeze()`). 100000000 still saves. Commit `fix(settings): refuse non-whole-rupee
  caps on save and at the Rank freeze`.
- **Done (L3)** — the GBB Input & Output and GBB calculation downloads carry "Raw Point Value (Rs)" and
  "Point Value Cap (Rs)" right after "Point Value (Rs)" (earner rows and MONTH TOTAL rows; empty for a
  month frozen before the cap). Commit `feat(gbb): add raw point value and cap to the GBB CSV exports`.
- **Done (L4)** — the two developer explainers read `comp.gbb.pool_rate_bp` and
  `comp.gbb.point_value_cap_paise` through the controllers (`IndianNumber::percentFromBp`, `intdiv`);
  the GBB index renders a refusal note instead of a 500 when the stored cap is one the engine refuses.
  Commit `fix(gbb): show the configured pool rate and cap in the developer explainers`.
- **Done (L5)** — `MentorshipBonusService` loads the date's open `gsb_cutoff_deferrals` once per warmed
  night (at the first accrual, after the command has written tonight's deferrals) and falls back to the
  per-accrual `exists()` only on un-warmed paths; the locked day-SUM and the two other date lookups use
  `where('cutoff_date', …)` on the `DATE` columns. Commit `perf(msb): warm the stale-verdict lookup and
  lock the day by index`.
- **Done (L6)** — six engine-run hand-offs, one commit each: (1) `RankBonusRunCommand` /
  `FortuneBonusEnrollCommand` record the rank-gate refusal as `skipped`; (2) the "two rows on a throwing
  manual trigger" did **not** reproduce — the recorder listener opens the row, the terminate event closes
  it and `EngineRunService::finalise()` writes the message onto the same row — pinned by three tests
  (run service, chain job, console); (3) `RepurchaseVerdictsPending` on an **open** month
  (`OpenMonthGuard::isOpen()`) is recorded as `skipped` with `OpenMonthGuard::verdictsPendingRefusal()`
  ("wait for the month to close"), closed months keep `failed`; (4) `RankQualificationsGate::monthsMissingCheck()`
  holds the oldest-first walk, `GbbMonthlyRunCommand` and the `EngineChainResolver`
  (`['key' => 'rank.check', 'expand' => 'prior-months']`) both use it, waived months logged
  `rank.check.prerequisite_waived`; (5) `RankBonusService::runForMonth()` skips the pre-freeze check when a
  credited premature freeze is kept (`monthHasCreditedResults()`); (6) `reconcileFreeze()` reconciles only
  the roster `freezeMonth()` wrote, stray pre-freeze `pending` rows get an audit row
  `rank.freeze.stray_pending_rows` in the freeze transaction, are never credited (`pricedByFreeze()` —
  the five figures copied from the pool row; a row whose rank has no pool is stray too), are left out of
  the tranche pre-check and log `rank.credit.stray_pending_row_skipped` on every run that skips them.
- **Done (L7)** — the unfiltered Rank calculation page builds its month headers from the rows on the
  current page of both tables plus (first page) every two-pass month frozen with nobody to pay;
  `AdminRankBonusInputOutputController::monthQuery()` also unions `rank_monthly_pools`, so a
  pools-only legacy month (September 2026 on dev) is listed with the legacy label and its frozen leftover.
  A pools-only month still has no strip on the calculation page (`BonusCalculationSnapshots::rankBonusMonth()`
  returns null for it); the I&O page is where it is listed. The I&O page falls back to the frozen pass
  rows when `comp.rank.first_pass_max_rank` is unreadable (logged `rank.report.first_pass_max_rank_invalid`).
  Commit `fix(rank): show every frozen month on the Rank calculation page`.
- **Main-side, still open:** Larastan `app/Modules/Payments/Services/RazorpayGateway.php:131`
  (baselined, not fixed); the three failing tests `CarryOverDisplayTest` "carry cards…" and
  `IncomeControllerTest` "counts a wallet…" / "gives the pe…"; and a fourth,
  `tests/Feature/EngineRegistryTest` "has exactly one registry entry per compensation console command"
  (`GsbWriteOffDeferralCommand` and `PayoutsReconcileCommand`, both from `main`, are neither registered
  nor in the test's non-engine list). Fix all four on `main`.
- **Not changed (recorded):** the settings input keeps `step="1"`, so the browser does not pre-block a
  value such as 24050 — the server refuses it. `EngineReplayService::unscheduledPrerequisites()` reads
  only `shift` (inert for `rank.check`, which is scheduled); its docblock still names the prev-month shift.

## 11. Not in this plan (deliberately)
- Renaming "Mentorship Bonus" to "Mentorship Royalty" in distributor-facing copy for rank 6+.
- The merchandise item list for awards (client to supply; placeholder catalogue seeded).
- Any change to the weekly payout cadence, admin charge, TDS, repurchase deduction, Fortune Bonus or ADC.
- Staging/production deploy — needs per-deploy approval.
