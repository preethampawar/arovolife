# T11 — `gsb:daily-cutoff` (GSB + MSB daily engine) on staging

Verdict: **PASS-with-notes** — the engine's arithmetic is exact on every one of the ~90 numbers I
reconstructed by hand for 2026-09-04 and 2026-09-05, and the re-run is byte-idempotent. Three defects,
none in the matching/pricing maths: one permanently mis-priced day (09-05) left behind by a mid-day
freeze that the self-heal is deliberately unable to repair once money has moved, and two smaller
observability/reporting gaps.

Scope: SSH + MySQL + code reading on deployed `6f114500`. No browser. One command run:
`gsb:daily-cutoff --date=2026-09-09` (a closed day, already computed). No weekly/monthly engine, no
recompute, no `--date=today`.

---

## 0. Plan parameters as they actually are on staging

`gsb_slabs` (admin-editable, read live):

| slab | title | title_min_bv_paise | matched_bv_paise | score | score_value_paise | msb_score | bonus_paise |
|---|---|---|---|---|---|---|---|
| 1 | Retailer | 300,000 | 1,500,000 (15K BV) | 8 | 25,000 | 21 | 200,000 |
| 2 | Dealer | 700,000 | 3,600,000 (36K BV) | 16 | 25,000 | 18 | 400,000 |
| 3 | Wholesaler | 1,500,000 | 10,000,000 (1L BV) | 32 | 25,000 | 15 | 800,000 |
| 4 | Distributor | 3,200,000 | 30,000,000 (3L BV) | 60 | 25,000 | 12 | 1,500,000 |
| 5 | Regional Distributor | 6,800,000 | 90,000,000 (9L BV) | 112 | 25,000 | 9 | 2,800,000 |
| 6 | National Distributor | 14,400,000 | 270,000,000 (27L BV) | 184 | 25,000 | 6 | 4,600,000 |
| 7 | Global Distributor | 30,000,000 | 810,000,000 (81L BV) | 280 | 25,000 | 3 | 7,000,000 |

Scores **8/16/32/60/112/184/280** and thresholds **15K/36K/1L/3L/9L/27L/81L** — exactly as the brief
expected. `score_value_paise` = 25,000 paise = **₹250** on every slab. All 7 active, all payable.

`settings` (`comp.*`), verbatim from staging:

| key | value | meaning |
|---|---|---|
| `comp.gsb.pool_rate_bp` | 4500 | GSB pool = **45 %** of the day's company BV |
| `comp.msb.pool_rate_bp` | 300 | MSB pool = **3 %** of the day's company BV |
| `comp.gsb.min_bv_paise` | 60,000 | earning gate = **600 BV** personal |
| `comp.gsb.power_cf_cap_paise` | 45,000,000 | power-side carry-forward cap = 450,000 BV |
| `comp.gsb.topup_golive_date` | 2026-07-25 | personal-BV top-up live |
| `comp.repurchase.rate_bp` / `cap_paise` | 1000 / 1,000,000 | **10 %** deduction, **₹10,000**/month ceiling |
| `comp.admin_charge.rate_bp` | 300 | 3 % — **payout time**, not here |
| `comp.tds.rate_bp` | 500 | 5 % — **payout time**, not here |
| `comp.monthly_income_cap_paise` | *(absent → registry default 500,000,000)* | ₹50,00,000 per **earned month** |

Feature flags (`features` table, all global scope): `GenosSalesBonusFeature`, `MentorshipBonusFeature`,
`GsbDailyPoolPricingFeature`, `RepurchaseEngineFeature` — **all `true`**. So the 45 % pro-rated pricing
for slabs 3–7 and the repurchase gate are both live.

---

## 1. Existing state

`gsb_daily_pools` (6 rows; `created_at` **is** the freeze timestamp — there is no `frozen_at` column):

| id | cutoff_date | company_bv | pool (45 %) | fixed_payout | var_score | value_cap | **var value** | var_payout | leftover | created_at |
|---|---|---|---|---|---|---|---|---|---|---|
| 1 | 2026-09-04 | 122,940,000 | 55,323,000 | 800,000 | 32 | 25,000 | 25,000 | 800,000 | 53,723,000 | 2026-09-04 00:10:00 ⚠ |
| 2 | 2026-09-05 | **55,940,000** ⚠ | 25,173,000 | 1,000,000 | 0 | 25,000 | 25,000 | 0 | 24,173,000 | 2026-09-05 14:03:29 ⚠ |
| 3 | 2026-09-06 | 60,000 | 27,000 | 0 | 32 | 25,000 | **800** | 25,600 | 1,400 | 2026-09-07 00:10:03 |
| 4 | 2026-09-07 | 0 | 0 | 0 | 0 | 25,000 | 25,000 | 0 | 0 | 2026-09-08 00:10:03 |
| 5 | 2026-09-08 | 0 | 0 | 0 | 0 | 25,000 | 25,000 | 0 | 0 | 2026-09-09 00:10:03 |
| 6 | 2026-09-09 | 0 | 0 | 0 | 0 | 25,000 | 25,000 | 0 | 0 | 2026-09-10 00:10:03 |

`msb_daily_pools` (6 rows):

| id | cutoff_date | company_bv | pool (3 %) | total_points | point_value | payout | leftover |
|---|---|---|---|---|---|---|---|
| 1 | 2026-09-04 | 122,940,000 | 3,688,200 | 36 | **102,400** | 3,686,400 | 1,800 |
| 2 | 2026-09-05 | **55,940,000** ⚠ | 1,678,200 | 39 | **43,000** | 1,677,000 | 1,200 |
| 3 | 2026-09-06 | 60,000 | 1,800 | 0 | 0 | 0 | 1,800 |
| 4–6 | 09-07..09-09 | 0 | 0 | 0 | 0 | 0 | 0 |

`gsb_cutoff_results` — 1,902 rows, 6 days × 317 distributors:

| date | below_600bv | no_match | credited | Σ gross | Σ net | Σ repurchase | Σ admin | Σ TDS |
|---|---|---|---|---|---|---|---|---|
| 2026-09-04 | 310 | 4 | 3 | 1,600,000 | 1,440,000 | 160,000 | **0** | **0** |
| 2026-09-05 | 310 | 4 | 3 | 1,000,000 | 900,000 | 100,000 | 0 | 0 |
| 2026-09-06 | 310 | 6 | 1 | 25,600 | 23,040 | 2,560 | 0 | 0 |
| 2026-09-07/08/09 | 310 each | 7 each | 0 | 0 | 0 | 0 | 0 | 0 |

Global status breakdown: `below_600bv` 1,860 · `no_match` 35 · `credited` 7. **No `repurchase_forfeited`,
no `failed`, no `frozen`, no `reversed`, no stuck `calculated`.**

`gsb_carryforward` (7 rows, one per earning distributor): d1 18,940,000 **L**; d2 8,560,000 **R**;
d3 8,560,000 **R**; d4–d7 0 **L**. `gsb_personal_bv_topups`: 8 rows, none reversed.
`group_bv_daily`: 7 rows, only d1/d2/d3 and only 09-04/05/06.

### Hand reconstruction — 2026-09-04 (first business day; all carry-forward starts at 0)

Inputs I took independently of the engine: `bv_ledger_entries` (7 accruals on 09-04, Σ **122,940,000**),
`group_bv_daily`, `sponsorship`, `gsb_slabs`. Personal BV *as of the run* = each distributor's ledger sum
at that moment (d1 3,000,000 · d2/d3 1,440,000 · d4 50,000,000 · d5 60,000,000 · d6 26,500,000 ·
d7 36,500,000). Note `group_bv_daily` **already contains the applied top-up**, so the pre-top-up legs
below are the stored legs minus that day's `gsb_personal_bv_topups` row.

| d | pre-top-up L / R | top-up (side, BV) | L / R after | CF power before | title → max slab | stronger | weaker | weakerTotal (+slab-1 CF) | slab | score | priced value | **gross** | CF power after | slab-1 CF after |
|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|
| 1 | 60,720,000 / 60,720,000 (tie) | R, 1,500,000 | 60,720,000 / 62,220,000 | 0 (side null) | 3,000,000 → Wholesaler → **3** | R 62,220,000 | L 60,720,000 | 60,720,000 | **3** (4 blocked by title cap) | 32 | 25,000 (pool) | **800,000** | 62,220,000−60,720,000 = **1,500,000 R** | 0 |
| 2 | 25,000,000 / 35,000,000 | L, 720,000 | 25,720,000 / 35,000,000 | 0 | 1,440,000 → Dealer → **2** | R 35,000,000 | L 25,720,000 | 25,720,000 | **2** | 16 | 25,000 (fixed) | **400,000** | **9,280,000 R** | 0 |
| 3 | 25,000,000 / 35,000,000 | L, 720,000 | 25,720,000 / 35,000,000 | 0 | 1,440,000 → Dealer → **2** | R 35,000,000 | L 25,720,000 | 25,720,000 | **2** | 16 | 25,000 (fixed) | **400,000** | **9,280,000 R** | 0 |
| 4 | 0 / 0 | — | 0 / 0 | 0 | 50,000,000 → Global → 7 | L (tie) | 0 | 0 | none | — | — | **0** `no_match` | 0 L | 0 |
| 5 | 0 / 0 | — | 0 / 0 | 0 | 60,000,000 → Global → 7 | L (tie) | 0 | 0 | none | — | — | **0** `no_match` | 0 L | 0 |
| 6 | 0 / 0 | — | 0 / 0 | 0 | 26,500,000 → National → 6 | L (tie) | 0 | 0 | none | — | — | **0** `no_match` | 0 L | 0 |
| 7 | 0 / 0 | — | 0 / 0 | 0 | 36,500,000 → Global → 7 | L (tie) | 0 | 0 | none | — | — | **0** `no_match` | 0 L | 0 |

**Every cell matches the stored `gsb_cutoff_results` row, field for field** — `left_bv_paise`,
`right_bv_paise`, `weaker_bv_paise`, `slab`, `score`, `score_value_paise`, `gross_gsb_paise`,
`power_cf_before/after_paise`, `power_side_before/after`, `slab1_weaker_cf_before/after_paise`, `status`.
Two details worth calling out because they are easy to get wrong and the engine got both right:

- **d1's slab is 3, not 4.** Weaker leg 60,720,000 clears slab 4's 30,000,000 threshold, but d1's own
  personal BV was only 3,000,000 (Wholesaler), and `GsbCutoffService` gates the loop on
  `$slabIndex <= $title->maxGsbSlab`. The group BV slab is capped by the personal-purchase title.
- **The top-up tie-break.** d1's legs were exactly equal pre-top-up; `topupSide = leftEffective <
  rightEffective ? 'L' : 'R'` sends a tie to **R**, and the stored row's R is 1,500,000 higher than L.
  The independent check is the underlying orders: d1's downline BV on 09-04 is 121,440,000
  (720,000+25,000,000+35,000,000 per side), i.e. **60,720,000 / 60,720,000** — a perfectly symmetric tree.

Pool arithmetic, recomputed by hand:
`122,940,000 × 4500 ÷ 10,000 = 55,323,000` ✓ · fixed = d2 400,000 + d3 400,000 = **800,000** ✓ ·
variable score = d1's 32 ✓ · remainder 54,523,000 ÷ 32 = 1,703,843.75 → floor-to-rupee 1,703,800 →
`min(cap 25,000, …)` = **25,000** ✓ · variable payout 32 × 25,000 = 800,000 ✓ ·
leftover 55,323,000 − 800,000 − 800,000 = **53,723,000** ✓.

### Same reconstruction for 2026-09-05 (carry-forward now in play) — also exact

| d | pre-top-up L / R | top-up | CF before | L eff / R eff (incl. CF) | stronger / weaker | weakerTotal | slab | gross | CF after |
|---|---|---|---|---|---|---|---|---|---|
| 1 | 50,720,000 / 3,720,000 | R 1,500,000 | 1,500,000 **R** | 50,720,000 / 6,720,000 | L / R 6,720,000 | 6,720,000 | **2** (slab 3 needs 10,000,000) | 400,000 | 50,720,000−6,720,000 = **44,000,000 L** |
| 2 | 25,000,000 / 25,000,000 | L 720,000 | 9,280,000 **R** | 25,720,000 / 34,280,000 | R / L 25,720,000 | 25,720,000 | **2** | 400,000 | **8,560,000 R** |
| 3 | 1,500,000 / 1,500,000 | L 720,000 | 9,280,000 **R** | 2,220,000 / 10,780,000 | R / L 2,220,000 | 2,220,000 | **1** (slab 2 needs 3,600,000; slab 1 needs weakerTotal ≥ 1,500,000 **and** stronger ≥ 1,500,000 — 10,780,000 ✓) | 200,000 | **8,560,000 R** |

All nine stored values match. d1 on **09-06** likewise: CF 44,000,000 L, R leg 25,060,000 (entirely the
top-up of orders 15+16, which were paid after the 09-05 run and correctly rolled to the next day),
weaker 25,060,000 → slab 3 (slab 4 needs 30,000,000), CF after = 44,000,000 − 25,060,000 =
**18,940,000 L** ✓, gross 32 × 800 = **25,600** ✓.

---

## 2. MSB — point value and per-row recompute

Rule as implemented (`MentorshipBonusService::accrueForSponsee`): when a **directly sponsored** sponsee's
cut-off reaches `credited` with a slab, that sponsee's **sponsor** (`sponsorship.sponsor_id`, one level,
*not* Genos depth, *not* the placement parent) accrues the matched slab's `msb_score`. The sponsor must
have ≥ 600 BV personal. Points are summed across the whole day, then
`point value = floor_to_rupee(3 % of the day's company BV ÷ total points)`, one value for everyone.

**2026-09-04.** Credited: d1 (slab 3), d2 (slab 2), d3 (slab 2). d1 has **no `sponsorship` row** (tree
root) → its 15 points are not accrued, and correctly do not dilute the denominator. d2 and d3 are both
sponsored by d1, whose personal BV 3,000,000 ≥ 60,000 → 18 + 18 = **36 points** ✓ (stored `total_points`).
Point value = 3,688,200 ÷ 36 = 102,450 → floor to a whole rupee = **102,400** ✓. Payout 36 × 102,400 =
3,686,400 ✓, leftover 1,800 ✓.

| row | sponsor → sponsee | slab | points | value | mb_gross | matches DB |
|---|---|---|---|---|---|---|
| 1 | 1 → 2 | 2 | 18 | 102,400 | 18 × 102,400 = **1,843,200** | ✓ |
| 2 | 1 → 3 | 2 | 18 | 102,400 | **1,843,200** | ✓ |

**2026-09-05.** Credited: d1 (slab 2, no sponsor → 0), d2 (slab 2 → sponsor 1, 18), d3 (slab 1 →
sponsor 1, **21**) = **39 points** ✓. Stored value 43,000 = 1,678,200 ÷ 39 = 43,030.77 floored — which is
arithmetically correct **for the frozen pool**, but the frozen pool itself is wrong (**D1** below).
Rows 3 and 4: 18 × 43,000 = 774,000 ✓ and 21 × 43,000 = 903,000 ✓.

**2026-09-06** froze 0 points (the only credit was d1's own, and d1 has no sponsor), so a ₹0 point value —
the documented MSB rule, deliberately the opposite of GSB's zero-achiever rule. No MB row written. ✓

---

## 3. Deductions on the rows — which column is written when

Read from `GsbCutoffService::settle()` (the matched branch) and `WalletService::creditWithRepurchaseDeduction()`:

| column on `gsb_cutoff_results` | written at cut-off? | value |
|---|---|---|
| `gross_gsb_paise` | **yes** | score × priced score value |
| `repurchase_deduction_paise` | **yes** | `floor(gross × 1000bp/10,000)`, clamped to the month's remaining ₹10,000 ceiling |
| `net_gsb_paise` | **yes** | `gross − repurchase_deduction` |
| `admin_charge_paise` | **hardcoded 0** | never written by this engine |
| `tds_paise` | **hardcoded 0** | never written by this engine |

So the design the memory records is confirmed at the DB: **repurchase at credit time, admin charge and
TDS at payout time.** All 1,902 staging rows carry `admin_charge_paise = 0` and `tds_paise = 0`; admin
(3 %, four independent ₹25,000 group ceilings) and TDS (5 % of payable) are computed in
`PayoutService::runWeeklyBatch()` (`$adminRateBp`/`$tdsRateBp` at :87–88, `$tds = min($payable,
round($payable × 5 %))` at :277) and land as `admin_charge`/`tds` wallet debits via `writePayoutDebits()`
and on `payout_line_items` — never back onto the cut-off row. Full order end-to-end:
**gross → −repurchase (credit time) → swept → −admin (capped) → payable → −TDS → net (payout time)**.

Per-row check on 09-04: d1 800,000 → 80,000 → 720,000 ✓ · d2/d3 400,000 → 40,000 → 360,000 ✓ ·
09-05 d3 200,000 → 20,000 → 180,000 ✓ · 09-06 d1 25,600 → 2,560 → 23,040 ✓. Exactly 10 %, floored,
on every row. d1's September repurchase deductions total 244,198 paise (₹2,441.98) — well under the
₹10,000 monthly ceiling, so the clamp is untested by live data (it is unit-tested).

`mb_credit` takes **no** repurchase deduction (2 plain credits for 09-04, 2 for 09-05, no matching
`repurchase_transfer`). That matches `creditWithRepurchaseDeduction`'s four call sites — GSB, Rank, GBB,
Fortune — and `WEEKLY_REPURCHASE_REF_TYPES = ['gsb_cutoff_result']`. See **N2** for the stale doc line.

---

## 4. Idempotence

`php artisan gsb:daily-cutoff --date=2026-09-09` (closed day, already computed by the 2026-09-10 00:10
scheduled run) →

```
GSB daily cut-off — 2026-09-09
Done — total: 317, engine: 317, bulk: 0, credited: 0, failed: 0, mb-failed: 0, msb-points: 0, msb-point-value: ₹0.00
```

`bulk: 0` is itself correct behaviour: `GsbIdleCutoffBatch::partition()` hands every distributor who
*already has a row for the date* back to the engine, so a re-run takes the full compute path (no bulk
shortcut) and still has to land on identical numbers. It did:

| probe | before | after |
|---|---|---|
| MD5 over all 317 `gsb_cutoff_results` rows for 09-09 (incl. `updated_at`) | `6a2b13ad77a0c69310e97fe8716503dc` | **identical** |
| MD5 over all 7 `gsb_carryforward` rows (incl. `updated_at`) | `c4a55d3d4c77749a38e8ae1077d800d4` | **identical** |
| `wallet_ledger_entries` count / max id | 40 / 40 | 40 / 40 |
| `gsb_daily_pools` / `msb_daily_pools` / `mentorship_bonus_results` / `gsb_personal_bv_topups` | 6 / 6 / 4 / 8 | 6 / 6 / 4 / 8 |
| `audit_log` max id | 3179 | 3179 (**no** new `gsb.pool.frozen`) |

`updated_at` is unchanged because `saveResult()` re-fills identical data and Eloquent skips a
non-dirty save — a stronger result than "same values". The pool was **not** re-frozen: `created_at`
2026-09-10 00:10:03 ≥ the day's end, so `replacePrematureFreeze()` returns false and the existing row is
returned. `engine_runs` id **38**, `gsb.daily-cutoff` / `2026-09-09` / `succeeded` / `console` / 551 ms,
`summary NULL`.

**No closed day is missing a cut-off.** `engine_runs.period_start` for `gsb.daily-cutoff` covers
2026-09-04, 05 (×2), 06, 07, 08, 09 — every calendar day from the first day with any BV through
yesterday. The only uncovered closed days are 2026-09-03 and earlier, which have **zero**
`bv_ledger_entries` and zero `group_bv_daily`, so a run there would write 317 all-zero rows and a ₹0
pool, credit nothing, and produce no wallet or repurchase-ledger evidence — while polluting T14's
recompute window and T31's report totals with a phantom day. I deliberately did not run it. The
fresh-write path is instead evidenced by the genuine first runs already in the data: the 2026-09-07
00:10:03 run for 09-06 froze pool id 3 (`gsb.pool.frozen` audit id 3160, `msb.pool.frozen` 3161), wrote
the `credited` row, and wrote wallet ids 38/39/40 — the **three-entry repurchase ledger** for d1, whose
repurchase wallet was non-zero:

```
38  d1  gsb_credit            +25,600   gsb_cutoff_result 949  earned_on 2026-09-06  bonus_month 2026-09-01
39  d1  repurchase_transfer    −2,560   gsb_cutoff_result 949  earned_on 2026-09-06  bonus_month 2026-09-01
40  d1  repurchase_deduction   +2,560   gsb_cutoff_result 949  earned_on 2026-09-06  bonus_month 2026-09-01
```

`earned_on` = the **cut-off date**, not the 00:10-next-morning write time (so the weekly payout windows
on the day the income was earned), and `bonus_month` = 2026-09-01. Same shape on all 7 `gsb_credit` rows.

---

## 5. Guards

**(a) "The repurchase engine must have seen the whole day."** `GsbDailyCutoffCommand::handle()` refuses
before doing anything when `IncomeEligibilityService::engineActive()` and
`! EngineStatusService::hasSucceededRunAfterDay('repurchase.evaluate', $date)`, logging
`Log::critical('gsb.cutoff.refused_missing_evaluate')` and returning `FAILURE`. The rationale in the
source is exactly right: the forfeit is permanent and has no later correction, so a cut-off that runs
before the day's verdict is final can destroy income irreversibly.

```php
$dayEnds = $day->copy()->startOfDay()->addDay();
->whereDate('period_start', '>', $day->toDateString())
  ->orWhere(fn ($q) => $q->whereDate('period_start', '>=', $day->toDateString())
                         ->where('started_at', '>=', $dayEnds->toDateTimeString()));
```

**Why 2026-09-09 passed:** both arms are satisfied. Arm 1 — `repurchase.evaluate` runs exist with
`period_start = 2026-09-10` (ids 33 at 00:30:03 and 34 at 12:28:49) > 2026-09-09. Arm 2 — ids 35/36 are
dated `2026-09-09` and started 2026-09-10 12:29:13, i.e. after `2026-09-10 00:00`. Either alone would
have opened the gate. `grep refused_missing_evaluate storage/logs/laravel.log` → **0 hits**: the guard
has never fired on staging.

**(b) Premature-freeze self-heal (`gsb.pool.refrozen` / `msb.pool.refrozen`).** It triggers when a pool
row's `created_at` is **before** the cut-off day ended — i.e. it was frozen mid-day and snapshotted
partial company BV and a partial achiever count. It then deletes the row so the caller freezes afresh,
writing a `gsb.pool.refrozen` audit row. **But only while nothing was funded by it**: if any
`gsb_cutoff_result` for the date carries a `POOL_FUNDED_STATUSES` status (or, for MSB, any credited
`mentorship_bonus_result` exists), the row is **kept** and the inconsistency is only logged as
`gsb.pool.premature_freeze_kept` / `msb.pool.premature_freeze_kept`. Staging has 2 `gsb.pool.refrozen`
and 4 `msb.pool.refrozen` audit rows, all from **August** (2026-08-26 and 2026-08-30/31) — the mechanism
demonstrably works when it fires in time. In September it fired twice and **declined to repair both
times** (log lines at 2026-09-05 00:10:03 for 09-04 and 2026-09-06 00:10:03 for 09-05) → **D1**.

**(c) In-flight refusal for `--date=today`.** Not run. Two distinct mechanisms, and only one of them
lives in the command:

- *Admin console*: `EngineRegistry` marks `gsb.daily-cutoff` with `requiresClosedPeriod: true`, and
  `AdminEngineRunsController:568-587` refuses any period past `latestManualPeriod()` (= `Carbon::yesterday()`
  for a Date engine) with *"GSB Daily Cut-off (incl. MSB) freezes the day's pool economics permanently,
  so it can only run once the day has ended — the scheduled run will process it."* The comment names the
  originating incident: *"on 24 Aug 2026 a manual cut-off at 23:27 froze that day's pool at ₹0 before the
  evening's BV had landed."*
- *CLI*: there is **no** closed-day guard of its own. `--date` defaults to `Carbon::today()`. What
  actually stops it today is guard (a): `hasSucceededRunAfterDay('repurchase.evaluate', today)` can never
  be true (nothing can be dated later than today, and nothing dated today can have started after
  tomorrow 00:00), so the command refuses. That is a **side effect**, not a rule — see **D2**.

---

## 6. Forfeit branch

No `repurchase_forfeited` rows exist on staging (statuses are only `below_600bv` / `no_match` /
`credited`), and none can appear before 2026-10-03: T10's **F21** left all 7 repurchase cycles
prematurely `completed`, so `IncomeEligibilityService::verdictAsOf()` returns eligible for every day in
this window. The branch is therefore verified at code level only, and it does preserve carry-forward:

`GsbCutoffService::computeForDistributor()` returns `OUTCOME_REPURCHASE_FORFEITED` with
`newPowerCf = cfBeforePower` and `newSlab1Cf = cfBeforeSlab1` **before any matching is attempted**, and
`settle()`'s forfeit branch writes `power_cf_after_paise = cfBeforePower`,
`slab1_weaker_cf_after_paise = cfBeforeSlab1`, `weaker_bv_paise = 0`, zero gross/net and **no wallet
credit at all** — and on a first run performs *no write to `gsb_carryforward`*, not even the
`firstOrCreate`, so the stores stay exactly where the due date left them and the fulfilment day resumes
on top of them. The raw left/right BV is recorded for the report but never added to anything.
`GsbCutoffResult::POOL_FUNDED_STATUSES` excludes the status, so a forfeited day neither funds nor
advances. Two re-run guards are also in place: a forfeited row re-read on a later re-run restores its
**own** recorded before-state rather than the latest day's, and a forfeit that has since become eligible
throws rather than double-counting when a later cut-off already advanced the store.

---

## 7. Income cap

The cap is **monthly, per earned month, shared across the five cash bonuses (GSB, MB, GBB, Rank,
Fortune)** — `comp.monthly_income_cap_paise`, default **500,000,000 paise = ₹50,00,000**. The key is
**absent from staging's `settings` table**, so the registry default applies. It is **not enforced at
cut-off**: `GsbCutoffService` never reads it. Enforcement is in `PayoutService` (`:226-260`, `:535`),
which measures each credit against the ceiling of the month it was **earned** in and writes an
`income_cap_forfeit` wallet debit plus a `payout.income_cap_forfeited` audit row for the excess.

**No staging row is anywhere near it.** The whole company's September gross across every bonus is
2,625,600 (GSB) + 5,363,400 (MB) + 3,718,884 (Rank) + 1,050,000 (ADC) = 12,757,884 paise ≈ **₹1.28 lakh**;
the largest single distributor (d1) is at ₹90,569. `SELECT type … FROM wallet_ledger_entries GROUP BY type`
returns **no `income_cap_forfeit` rows at all**.

---

## Checks

| # | Check | Result | Evidence |
|---|---|---|---|
| 0 | Plan parameters read from staging, not assumed | **PASS** | §0 — scores 8/16/32/60/112/184/280, thresholds 15K…81L, ₹250, 45 %, 3 %, 600 BV, 10 %/₹10k, 3 %/5 %, CF cap 450,000 BV |
| 1a | Pools / results / CF / top-ups / group BV inventoried | **PASS** | §1 tables; 1,902 result rows, 6+6 pool rows, 7 CF rows, 8 top-ups |
| 1b | Hand reconstruction of 2026-09-04 for all 7 distributors | **PASS** | §1 table — L/R, top-up side, title cap, stronger/weaker, weakerTotal, slab, score, gross, CF before/after all match the stored row exactly |
| 1c | Pool arithmetic for 09-04 recomputed by hand | **PASS** | 55,323,000 / 800,000 / 32 / 25,000 / 800,000 / 53,723,000 — all six columns match |
| 1d | Second reconstruction with CF in play (09-05, 09-06) | **PASS** | §1 — incl. d3's slab-1 dual gate and d1's 44,000,000 → 18,940,000 CF chain |
| 2 | MSB point value + every `mentorship_bonus_results` row recomputed | **PASS (arithmetic)** | §2 — 36 pts → ₹1,024; 39 pts → ₹430; sponsor-tied, 1 level, `msb_score` 21/18; root d1 correctly contributes no points. The 09-05 **input** is wrong → D1 |
| 3 | Deduction columns: which are written at cut-off vs payout | **PASS** | §3 — repurchase at credit time (exactly 10 %, floored, on all 7 rows); admin + TDS hardcoded 0 here and computed in `PayoutService` |
| 4a | Idempotence — re-run 2026-09-09 | **PASS** | §4 — MD5 of results and CF byte-identical incl. `updated_at`; 0 new wallet/pool/audit rows; `engine_runs` 38 succeeded |
| 4b | A closed day with no cut-off | **N/A — none exists** | every day from the first BV day (09-04) through 09-09 has a run; 09-03 and earlier have zero BV. Fresh-write path evidenced from the real 2026-09-07 run instead (pool freeze + audit + 3-entry ledger, `earned_on`/`bonus_month` correct) |
| 5a | `hasSucceededRunAfterDay` gate; why 09-09 passed | **PASS** | §5(a) — both arms satisfied by runs 33/34 (dated 09-10) and 35/36 (dated 09-09, started 09-10 12:29); 0 `refused_missing_evaluate` in the log |
| 5b | Premature-freeze self-heal | **PASS (mechanism) / FAIL (outcome on 09-05)** | §5(b) — 6 August `refrozen` audit rows prove it works; September it correctly declined twice, leaving 09-05 permanently mis-priced → **D1** |
| 5c | In-flight refusal for `--date=today` | **PASS-with-note** | §5(c) — console guard is `requiresClosedPeriod`; the CLI has none of its own and relies on the repurchase gate → **D2** |
| 6 | Forfeit branch present; CF preserved | **PASS (code) — unexercised** | §6 — 0 `repurchase_forfeited` rows (blocked by T10/F21); code preserves both stores and writes nothing to `gsb_carryforward` on a first forfeit |
| 7 | Income cap: which, where, any row hit it | **PASS** | §7 — ₹50,00,000 per earned month, five bonuses, enforced in `PayoutService`, not at cut-off; 0 `income_cap_forfeit` rows; largest earner at ₹90,569 |

---

## Defects

**D1 — High (data, staging) / Medium (design). The 2026-09-05 pool is permanently understated by
25,000,000 BV, and ₹7,488 of Mentorship Bonus was underpaid to distributor 1. The self-heal is
architecturally unable to repair it.**

`gsb_daily_pools.id = 2` and `msb_daily_pools.id = 2` record `company_bv_paise = 55,940,000`. The true
signed `bv_ledger_entries` sum for 2026-09-05 is **80,940,000** — entry id 16 (distributor 1, order 15,
25,000,000 BV, `effective_at 2026-09-05 23:59:09`) landed after the freeze. The freeze happened at
**2026-09-05 14:03:29** (log `gsb.pool.frozen`, and again at 01:47:59 and 13:54:31 with the same short
value), i.e. **mid-day**, from the pre-deploy recompute chain that also produced `engine_runs` 3–15.
`replacePrematureFreeze()` detected it correctly on the next scheduled run and refused to repair it
because money had already moved:

```
[2026-09-06 00:10:03] staging.WARNING: gsb.pool.premature_freeze_kept {"cutoff_date":"2026-09-05","frozen_at":"2026-09-05 14:03:29","company_bv_paise":55940000,…,"reason":"results were already priced against this pool; re-freezing would change economics money moved on"}
[2026-09-06 00:10:03] staging.WARNING: msb.pool.premature_freeze_kept {"cutoff_date":"2026-09-05","frozen_at":"2026-09-05 14:03:29",…,"point_value_paise":43000,…}
```

*Expected*: MSB pool 3 % × 80,940,000 = 2,428,200 → 2,428,200 ÷ 39 points = 62,261.5 → **₹622/point**.
*Actual*: 1,678,200 ÷ 39 = **₹430/point**.

| `mentorship_bonus_results` | points | paid | should have been | short |
|---|---|---|---|---|
| id 3 (d1 ← d2) | 18 | 774,000 | 1,119,600 | 345,600 |
| id 4 (d1 ← d3) | 21 | 903,000 | 1,306,200 | 403,200 |
| | | | | **748,800 paise = ₹7,488** |

GSB itself lost nothing on 09-05: `variable_total_score = 0` (both matches were fixed slabs 1–2), so no
rupee was priced from the short pool. Had a slab 3–7 matched, it would have been.

*Repro*: `SELECT DATE(effective_at), SUM(bv_paise) FROM bv_ledger_entries GROUP BY 1;` → 09-05 =
80,940,000. `SELECT company_bv_paise FROM msb_daily_pools WHERE cutoff_date='2026-09-05';` → 55,940,000.

*The design point, which matters beyond staging*: the guard is all-or-nothing. Once **one** distributor
has been credited against a premature pool, the whole day is frozen wrong forever, and the only signal is
a WARNING line in a rotating log — no `audit_log` row, no `engine_runs` failure, nothing on the Engine
Runs page, nothing in the health digest. On production the recovery is a windowed recompute that nobody
would know to run. Suggest: write an `audit_log` row (not just a log line) on `premature_freeze_kept`,
surface it on the Engine Runs / daily-cut-off report as a "this day's economics are provisional" flag,
and have `compensation:engine-health-digest` report it. Cross-reference **F05** and **F10** — the staging
fix is the same windowed recompute from 2026-09-01 that T14 already needs user go-ahead for.

**D2 — Medium (code). `gsb:daily-cutoff` has no closed-day guard of its own; `--date` defaults to
today, and only the repurchase gate stands in the way.**

`requiresClosedPeriod: true` is enforced **only** in `AdminEngineRunsController::…(:568-587)`. On the CLI,
`--date` defaults to `Carbon::today()` and nothing in `GsbDailyCutoffCommand::handle()` refuses an
in-flight day. Today it is blocked as a side effect of the repurchase gate — but that gate disappears
the moment `RepurchaseEngineFeature` is off (`$this->eligibility->engineActive()`), and `--force`
switches it off explicitly *without* substituting a date check. Either path re-creates the 24 Aug 2026
incident the console guard was written for, and D1 is the same failure mode arriving through the
recompute door. *Suggested fix*: refuse `$date >= Carbon::today()` in the command unless an explicit
developer override is passed, so the rule holds wherever the engine is invoked from — the console guard
already has the wording to reuse.

**D3 — Low (reporting). `admin_charge_paise` and `tds_paise` on `gsb_cutoff_results` are permanently
zero.** They are hardcoded `0` in `settle()`'s `baseData` and in `VOLATILE_FIELD_RESETS`, and nothing
writes them back after payout. That is *correct* for the shipped credit-time/payout-time split, but the
columns are still on the table and read as "this cut-off was charged ₹0 admin and ₹0 TDS". Any report,
export or UI that foots a distributor's deductions from this table will under-report. Either drop the
columns or have the payout write them back — worth one check by whoever owns T31 (compensation reports)
and T22 (income pages).

**D4 — Low (observability). `gsb.daily-cutoff` writes no `engine_runs.summary`.** Run 38 (and every
historical run) has `summary NULL`, despite the command printing a rich line to the console
(`total / engine / bulk / credited / failed / mb-failed / msb-points / msb-point-value`). The Engine Runs
page and the health digest therefore show nothing for the platform's primary daily income engine. Same
gap as T10's D5 on `repurchase.evaluate` — likely one shared fix in the run recorder.

---

## Notes (not defects)

**N1 — `gsb_daily_pools` has no `frozen_at`; `created_at` doubles as the freeze time, and a recompute
rewrites it.** The surviving 09-04 pool row carries `created_at = 2026-09-04 00:10:00` — *before* any of
that day's BV existed (the first order was 23:33:30). The real freeze was 2026-09-04 23:43:25 (log +
audit id 3077); the last recompute replay re-created the row with a time-travelled timestamp. The values
are correct, but `replacePrematureFreeze()` reads that fabricated `created_at`, so every future re-run of
09-04 will classify a legitimately-final pool as premature and log a warning. Consider an explicit
`frozen_at` column set from the data horizon rather than the wall clock.

**N2 — a stale line in the project memory.** `repurchase-wallet-gates-2026-09-05` says the deduction
source is *"`gsb_credit`, `mb_credit`, `gbb_credit`, `fortune_credit`, `rank_credit` only — 5 bonuses per
spec"*. The code takes the deduction from **four**: `creditWithRepurchaseDeduction()` is called only by
`GsbCutoffService`, `RankBonusService`, `GrowthBoosterBonusService` and `FortuneBonusService`, and
`WEEKLY_REPURCHASE_REF_TYPES = ['gsb_cutoff_result']`. `mb_credit` is swept into the weekly payout (and
bears admin charge + TDS there) but carries **no** repurchase transfer — which agrees with the other
memory, `repurchase-wallet-credit-time-system` (*"MSB, ADC, Awards & Rewards are excluded"*). Two
project memories contradict each other; the code is self-consistent. Someone should decide which is the
client's intent and correct the loser — if MSB *should* be deducted, ₹53,634 of staging MB credits are
missing a 10 % transfer.

**N3 — the 45 % pool is a ceiling, not a distribution.** On 09-04 the pool was ₹5,53,230 and ₹5,37,230
(97 %) went unspent, because slabs 1–2 pay a fixed ₹250/score out of it and the slab 3–7 value is capped
at the same ₹250. `leftover_paise` is retained and, per the service docblock, simply not paid. That is
the KP 2026-07-29 design as written, but it is worth confirming with the client that "45 % of turnover"
is understood as a cap rather than a commitment before any of this reaches distributor-facing copy.

---

## Mutations made on staging

| Table | Ids | Before → after |
|---|---|---|
| `engine_runs` | **38** (`gsb.daily-cutoff`, `period_start 2026-09-09`, succeeded, console, 551 ms, summary NULL) | row created |
| `gsb_cutoff_results` | 317 rows for 2026-09-09 | **unchanged** — MD5 incl. `updated_at` identical before and after |
| `gsb_carryforward` | 1–7 | **unchanged** — MD5 incl. `updated_at` identical |
| `wallet_ledger_entries` | — | none (40 rows before and after, max id 40) |
| `gsb_daily_pools` / `msb_daily_pools` / `mentorship_bonus_results` / `gsb_personal_bv_topups` | — | none (6 / 6 / 4 / 8 before and after) |
| `audit_log` | — | none (max id 3179 before and after) |
| anything else | — | none. No weekly payout, no monthly engine, no recompute, no `--date=today`, no `.env`, no schema change. |

## Notes for the orchestrator

1. The matching, pricing, carry-forward and MSB arithmetic is **exact** — I reconstructed 09-04 in full
   and 09-05/09-06 partially by hand from `bv_ledger_entries` + `group_bv_daily` + `gsb_slabs` +
   `sponsorship` and every field matched, including the two easy-to-miss rules (title slab cap, and the
   tie → power-side-Left / top-up-side-Right break).
2. **D1 is the one to act on**: 09-05's pool is permanently 25,000,000 BV short and d1 was underpaid
   ₹7,488 of MSB. Root cause is the same pre-deploy recompute chain behind **F05**; the fix is F10's
   windowed recompute from 2026-09-01, which still needs the user's go-ahead. The *design* half of D1
   (a premature freeze that money touched is unrepairable and invisible outside a rotating log) is worth
   a findings-register entry in its own right.
3. **D2** pairs with F05/F23 in the "a guard exists in one entry point but not the other" family — the
   console refuses an in-flight day, the CLI does not.
4. T12 should note that all `admin_charge_paise` / `tds_paise` on `gsb_cutoff_results` are 0 by design
   (D3) — the payout is where those numbers live, and the columns here will mislead a report that foots
   them.
5. The forfeit branch (check 6) is still unexercised because T10/F21 left all 7 cycles `completed`. If
   any later task needs a real `repurchase_forfeited` day, it needs the F21 fix or a deliberately
   constructed second cycle first.
6. `engine_runs` id 37 (`compensation.monthly-close 2026-08-01`, `running`) was in flight from T13
   during my run; my cut-off is for 2026-09-09 and touched nothing it touches.
