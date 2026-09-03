---
name: arovolife-compensation-plan
description: Reference for the Arovolife compensation plan — slabs, ranks, Fortune Bonus, mentorship, caps and repurchase rules. Source of truth = doc dated 2026-06-19. Mostly informational during Phase 1-3; becomes operational from Phase 4 onwards. Use whenever a feature needs to understand how a rupee flows.
---

# Arovolife Compensation Plan — reference

> ⚠️ **SUPERSEDED IN PART — read this first.** The numbers below are the 2026-06-19 doc. KP Naik issued a newer authoritative plan (*Arovolife Is Our New Life*, **26-06-2026, 3.30pm**) plus a Q&A (answered **27-06-2026**). On every conflict, the newer sources win, in this order:
> 1. `docs/compensation/kp-clarifications-2026-06-26.md` (KP's Q&A — answers + open items)
> 2. `~/.claude/.../memory/compensation_plan_kp_amendments_2026_06_26.md` (numeric params SSOT)
>
> ⚠️ **MSB SUPERSEDED by KP's 2026-07-30 daily-pool engine** — see the "2nd Benefit" section below. MSB points are no longer multiplied by a configured value; the day's 3% pool is divided by the day's total MSB points.
>
> Key deltas the 06-19 text below gets WRONG: admin charge **+ 5% TDS apply to all 7 bonuses** (incl. ADC + Lifetime Awards); repurchase pool = GSB+MB+GBB+Fortune+Rank; min payout **₹100**; repurchase date = Retailer-title anniversary; monthly repurchase BV is **graduated per rank** (R1 1,000 … R9 2,300; R7/R8 TBD); envelope %s restated (GSB 46 / MB 1.5 / Rank 20~21 / Fortune 6 / Lifetime 18.5 / ADC 3).
>
> ⚠️ **GSB SUPERSEDED by KP's 2026-07-21 "New Engine"** (memory `gsb_new_engine_2026_07_21.md`, shipped 2026-07-25). GSB bonus = matched slab's `score` × that slab's **per-slab configurable score value** (default ₹250/point) — NOT the old global ₹360 rate. New scores **8/16/32/60/112/184/280**; both-sides thresholds **15K/36K/1L/3L/9L/27L/81L**; bonuses **₹2,000 / ₹4,000 / ₹8,000 / ₹15,000 / ₹28,000 / ₹46,000 / ₹70,000**. Personal-BV title thresholds unchanged. **Conditional personal-BV top-up**: personal purchase BV accumulates and is credited to the weaker leg only on a cut-off where a leg's effective BV (incl. CF) has touched a slab threshold (real personal BV never mutated). **Equal-sides tie-break**: Left is the power side (its excess carries forward, Right → 0). The sheet's "45% of daily turnover ÷ total score" example is a future company P/L metric only — not implemented. The slab table below shows even older 06-19 numbers — use the New Engine values.
>
> ⚠️ **FORTUNE BONUS — the client's 2026-09-03 notes are the source of truth** (they supersede the 2026-08-09 cascade, which superseded the 2026-08-07 single-point-value rework) — see the "5th Benefit" section below. **2026-09-03 change: every level 0–9 is a capped level with its own per-member ceiling — L7 ₹2,500, L8 ₹1,500, L9 ₹30 (= the minimum only); no residual sharing, no flat mode.** Each level recomputes `floor-to-whole-rupee(remaining pool ÷ ALL remaining points)` and carries the unspent pool and unpaid points forward. Nobody-below → ₹30 only, leftover stays with the company (never redistributed). ₹30 stays inside the single wallet credit (one Income column, split shown in a note). The ₹36cr example now ends L8 ₹624 / leftover ₹24,576 (client confirmed). The paragraph that follows describes the 2026-08-09 rule and is kept for history: The monthly pool is **5% of the month's company BV** (the signed BV-ledger sum); depth points are now **9/8/7/6/5/4/3/2/1** per downline member at relative levels 1–9 (1L-9P … 9L-1P, admin-editable). Distribution: every qualifier gets a **₹30 minimum** (`comp.fortune.min_commission_paise`), reserved off the pool first; **capped levels 0–6** then settle top-down, each at `floor-to-whole-rupee(remaining pool ÷ ALL remaining points)` with per-member caps **₹30k/₹30k/₹30k/₹30k/₹20k/₹10k/₹5k INCLUDING the ₹30**; **levels 7–8 share ONE residual value** over their combined points, uncapped; **level 9 gets the ₹30 only**. Income = `min(₹30 + points × level value, cap)`. Sparse months keep the **absolute-level** treatment, and if the pool can't cover the guarantees everyone gets `floor-to-rupee(pool ÷ N)` (both user-confirmed 2026-08-09). Per-level modes/caps live on `fortune_bonus_levels` (`payout_mode`, `cap_paise`); economics freeze per month as `fortune_monthly_pools` (+`fortune_monthly_pool_levels`) before any credit, no manual override, re-runs reconstruct from the snapshot (`FortuneDistributionCalculator` is the pure allocator, regression-locked to KP's ₹36cr example). Gates unchanged: 8/11/14/17/20 slab-achievements + 1,000–1,400 BV for ranks 1–5; ranks 6–9 excluded. **"Repurchase Wallet zero" gate (built 2026-09-03):** every tier except the month-1 joiner needs a ₹0 repurchase-wallet balance as of the last day of the month (`comp.fortune.require_repurchase_wallet_zero`, default ON; reading pending the client's confirmation). Fortune stays behind its feature flag, OFF, until the plan change is published with the DSA §6.2 notice period (see R-37 in `docs/compliance/risk-register.md`).
>
> ⚠️ **GBB + RANK BONUS SUPERSEDED by the Product Owner's 2026-08-05 confirmations** — see the "3rd Benefit" and "4th Benefit" sections below. **GBB:** the monthly pool is 5% of the month's company **BV** (the signed BV-ledger sum), **not** turnover / order sales value; only distributors who held **no rank in the previous month** are eligible (a first-time rank achiever in the current month stays eligible); the month's pool economics are **frozen** in a `gbb_monthly_pools` row before any credit and never recomputed, so re-runs and retries price against the same snapshot; the point value is `floor-to-whole-rupee(pool ÷ the total AGP of all eligible distributors)` and the flooring remainder stays unspent. **Rank Bonus:** each rank's `pool %` is a share of the **20% Rank-Bonus envelope of company BV**, not of turnover directly — 10,00,000 BV in a month → envelope ₹2,00,000 → Rank 1's 7% share = ₹14,000. **Ranks 1–2 Genos-BV matches were revised again on 2026-08-13 (the client): R1 2,50,000 and R2 6,00,000 per side** — the "4th Benefit" table below has been rewritten and is current. GBB stays behind its feature flag, OFF, until the plan change is published with the DSA §6.2 notice period (see R-36 in `docs/compliance/risk-register.md`).

**Source document (this file):** "Arovolife Is Our New Life" dated 2026-06-19.
**Phase note:** No compensation is calculated in Phases 1–3. This skill exists so that the data model, events and audit trails are *forward-compatible* with the engines that arrive in Phases 4+.

---

## Revenue sharing envelope

| Component | % of revenue | Cadence | Phase introduced |
|---|---|---|---|
| Retail Margin | 10–30% | Per sale | Phase 2 |
| Genos Sales Bonus (GSB) | 40% | Daily cut-off 23:59; weekly Tuesday payout | Phase 4 |
| Mentorship Bonus | 3% daily pool ÷ the day's MSB points (KP 2026-07-30) | With GSB | Phase 4 |
| Growth Booster Bonus | 5% of monthly turnover | Monthly | Phase 4 |
| Rank Bonus | **20%** envelope of the month's company BV (see pool table) | Monthly 8th | Phase 5 |
| Fortune Bonus | 3×9 matrix, participation-based | Monthly reset | Phase 6 |
| Lifetime Awards & Rewards | 32% (non-cash) | On rank achievement | Phase 5 |
| Arete Development Center Bonus | 3%, cap ₹1 L/month | Monthly 8th | Phase 7 |

**Payout schedule (5 payouts/month):**
- GSB: every Tuesday (4× per month)
- Rank Bonus: 8th of month
- Arete Development Center Bonus: 8th of month

---

## Caps and deductions

- Total distributor-side commission hard-capped at ₹50 lakh/month.
- Admin charge: 3% or ₹30,000, whichever is lower; applies to GSB, Mentorship Bonus, Rank Bonus. Exempt: Lifetime Awards & Rewards, Fortune Bonus, Arete Development Center Bonus.
- TDS: 5% (verify current IT rate at payout time).
- Repurchase wallet deduction: 10% of prior-month GSB + Mentorship Bonus + Rank Bonus, capped at ₹10,000.
- Minimum payout: ₹500.
- Mandatory monthly repurchase: 600 BV on or before the 15th; repurchase wallet must be zeroed before the 15th.

---

## Partner personal purchase ladder (lifetime accumulated BV → Title)

These are lifetime BV ranges, not single-purchase thresholds.

| Level | BV range (lifetime) | Title |
|---|---|---|
| 1 | 3,000 – 4,999 | Retailer |
| 2 | 5,000 – 14,999 | Dealer |
| 3 | 15,000 – 49,999 | Wholesaler |
| 4 | 50,000 – 99,999 | Distributor |
| 5 | 1,00,000 – 1,99,999 | Regional Distributor |
| 6 | 2,00,000 – 2,99,999 | National Distributor |
| 7 | 3,00,000 + | Global Distributor |

**Note on previous skill:** Old names Agent/Retailer/Dealer/Wholesaler/Distributor/Regional/National are deprecated. New names above are canonical.

### 600 BV / 3,000 BV eligibility gates

- **Below 600 BV personal:** Downline (Genos left/right) BV is NOT added to the distributor's account at all.
- **600 BV to 2,999 BV personal:** Downline BV accumulates and displays in back-office. GSB is computed and credited to web account only — NOT transferred to bank.
- **3,000+ BV personal (Retailer title):** All bonuses transfer to bank account normally.

---

## 1st Benefit: Genos Sales Bonus (GSB) — 40%

GSB is earned on the BV generated by purchases of distributors in the Left and Right Genos (the binary placement tree).

### How the daily slab is determined

Every day at 23:59, the system:
1. Computes Left Genos BV and Right Genos BV generated that day.
2. Takes the **weaker side** (lower of the two).
3. Looks up the applicable slab from the table below — capped by whichever is lower: the matched BV slab OR the distributor's personal purchase title (whichever corresponds to a lower slab).
4. Pays the bonus for that slab, sets the weaker side to **zero**, and caps the power side at **4,50,000 BV** (excess is flushed, not carried forward).

### Special rule for 1st Slab (15K/15K)

The 1st slab has **no daily cutoff and no time limit**. It accumulates lifetime until the 15K match is hit, then pays and resets the weaker side to zero. All other slabs (2nd–7th) use the daily 23:59 cutoff.

### GSB slab table (KP 2026-07-21 "New Engine" — current)

Bonus = score × per-slab score value (default ₹250). Personal-purchase title thresholds are KP's 27-06-2026 table.

| Left / Right matched BV | Personal purchase title required | Score | Score value | Bonus |
|---|---|---|---|---|
| 15,000 / 15,000 | Retailer (3,000 BV) | 8 | ₹250 | ₹2,000 |
| 36,000 / 36,000 | Dealer (7,000 BV) | 16 | ₹250 | ₹4,000 |
| 1,00,000 / 1,00,000 | Wholesaler (15,000 BV) | 32 | ₹250 | ₹8,000 |
| 3,00,000 / 3,00,000 | Distributor (32,000 BV) | 60 | ₹250 | ₹15,000 |
| 9,00,000 / 9,00,000 | Regional Distributor (68,000 BV) | 112 | ₹250 | ₹28,000 |
| 27,00,000 / 27,00,000 | National Distributor (1,44,000 BV) | 184 | ₹250 | ₹46,000 |
| 81,00,000 / 81,00,000 | Global Distributor (3,00,000 BV) | 280 | ₹250 | ₹70,000 |

### Personal BV added to Rank 1 / Rank 2 weaker leg (only for rank qualification)

- **Rank 1 (Silver):** up to 15,000 of current-month personal BV may be added to the weaker Genos leg.
- **Rank 2 (Pearl):** up to 30,000 of current-month personal BV may be added to the weaker Genos leg.
- This top-up applies ONLY to rank qualification (not to GSB slab calculation).
- Also, for Rank 1 and Rank 2 only: the distributor's current-month personal BV is added to the weaker side for rank achievement. This is NOT applicable to Ranks 3–9.

### Carry-forward rules

- After each bonus payment: **weaker side = 0**, power side capped at **4,50,000 BV** (excess flushed).
- 1st slab (15K/15K): weaker side carry-forwards indefinitely (no time limit).
- 2nd–7th slabs: no carry-forward; calculated fresh each day within the 24-hour window.

---

## 2nd Benefit: Mentorship Bonus — 3% daily pool (KP 2026-07-30)

> ⚠️ **Both earlier models are SUPERSEDED**: the 10%→1% cumulative-GSB rate ladder (retired 2026-07-25) and the fixed ₹250-per-point value (retired 2026-07-30). Do not reintroduce either. There is **no configured MSB point value** — `gsb_slabs.msb_score_value_paise` no longer exists.

When a **directly sponsored** sponsee's daily cut-off credits GSB slab N, the sponsor accrues that slab's **MSB points** (`gsb_slabs.msb_score`, editable on the Plan-settings GSB-slab forms). The rupee value of a point is computed per day:

```
MSB pool        = comp.msb.pool_rate_bp (default 300 bp = 3%) × the day's company BV
point value     = floor_to_whole_rupee(pool ÷ the day's total MSB points)
sponsor income  = their points × point value
```

| Sponsee's slab | MSB points to sponsor |
|---|---|
| 1 | 21 |
| 2 | 18 |
| 3 | 15 |
| 4 | 12 |
| 5 | 9 |
| 6 | 6 |
| 7 | 3 |

**KP's worked examples.** A 1,00,000 BV day → ₹3,000 pool. Two slab-1 sponsors (21+21) + one slab-2 sponsor (18) = 60 points → **₹50/point** → ₹1,050 + ₹1,050 + ₹900 = ₹3,000. Five earners at 21+18+15+12+9 = 75 points → **₹40/point** → 840+720+600+480+360 = ₹3,000.

- Company BV is the same signed `bv_ledger_entries` sum the GSB pool uses; both pool and value are clamped at 0 (a refund-heavy day can be negative).
- **Third pass in the cut-off**: everyone settles → `msb_daily_pools` row frozen (immutable, audit-logged `msb.pool.frozen`) → credits. Single-distributor retries never freeze; they price against the stored value.
- Only sponsors who will actually be paid enter the denominator — the 600 BV personal-minimum gate excludes a sponsor before they can dilute the pool.
- Points + the day's value are **snapshotted** per `mentorship_bonus_results` row (`slab`, `msb_points`, `msb_point_value_paise`); legacy ladder rows have null snapshots.
- A zero-point day freezes a ₹0 value (pool unspent); a retry on such a day writes a ₹0 row with no wallet entry. Deliberately the opposite of the GSB pool's zero-achiever rule — **KP sign-off pending** (risk register R-35).
- Deductions (admin charge/TDS) still at payout; gated by `MentorshipBonusFeature`.
- **Applies only to direct sponsees' GSB slab achievements.** No cumulative per-pair tracking.
- Admin reports: **MSB Calculation** (`/admin/compensation/msb-calculation`) per credit, and **MSB Input & Output Per Day** (`/admin/compensation/msb-input-output`) per day — BV, pool, each earner's points, point value, income, totals, day/week/date search, CSV.

---

## 3rd Benefit: Growth Booster Bonus (NEW — not in old skill)

A monthly bonus for distributors who are not yet ranked (or achieving rank for the first time in the current month). Designed to reward early-stage growth activity.

### Arovolife Growth Points (AGP)

AGP is awarded per GSB slab earned in the month:
- 1st GSB slab (15K match) earned: **12 AGP**
- 2nd GSB slab (36,000 match) earned: **5 AGP**
- 3rd GSB slab (1,00,000 match) earned: **2 AGP**
- 4th–7th slabs: no AGP

Distributors can earn these slabs multiple times in a month; each occurrence adds AGP.
**Maximum 120 AGP from any single distributor.**

### Eligibility

- Eligible: distributors with **no rank in the previous month** (new and early-stage).
- Also eligible: distributors achieving **a rank for the first time** in the current month.
- **Not eligible:** distributors who held any rank in the previous month.

### Payout calculation

> ⚠️ **SUPERSEDED by the Product Owner's 2026-08-05 confirmations** — the pool base is the month's company **BV**, not turnover. Do not reintroduce a turnover base.

- The Growth Booster Bonus pool = **5% of the month's company BV** (the signed BV-ledger sum, the same base the GSB and MSB pools use) — *not* order sales value (PO confirmed 2026-08-05).
- Point value = `floor-to-whole-rupee(pool ÷ the total AGP of all eligible distributors)`. The flooring remainder stays unspent.
- Each distributor receives: their total AGP × that one point value.
- The month's pool economics are **frozen** in a `gbb_monthly_pools` row before any credit and never recomputed, so re-runs and single-distributor retries price against the same snapshot (`gbb.pool.frozen` audit row).
- **Repurchase interaction:** GBB calculated during a distributor's repurchase grace window is *held* (`repurchase_held`) and released when they complete the repurchase — held distributors still count in the pool denominator; once the grace window lapses it is *forfeited* (`repurchase_suspended`) and those distributors are excluded from the denominator.

---

## 4th Benefit: Rank Bonus — 20% envelope (across 9 ranks)

> ✅ **The table below is CURRENT** — reconciled 2026-08-24 against `RankTiersSeeder`.
> The live `rank_tiers` rows are admin-editable at
> `/admin/compensation/plan-settings?tab=ranks` and always win over this file.
> Three revisions replaced the June 2026 plan text:
>
> - **2026-06-27 (the client, Round-2 answers)** — the envelope totals **20%, not 21%** (R2/R3/R4 shares
>   restated), and the personal-BV ladder was revised: R1 7,000 · R3 32,000 · R4 68,000 ·
>   R5 1,44,000 BV lifetime.
> - **2026-08-05 (Product Owner)** — each rank's `Pool %` is a share of the **20% envelope
>   of the month's company BV**, not a percentage of turnover. Worked example: 10,00,000 BV
>   in a month → envelope ₹2,00,000 → Rank 1's 7% share = ₹14,000.
> - **2026-08-13 (the client)** — Ranks 1–2 Genos-BV matches **lowered**: R1 **2,50,000 per
>   side** (was 3L) and R2 **6,00,000 per side** (was 8L on 2026-08-05, itself raised from
>   the June plan's 5L).

Pool split across 9 ranks, credited by the monthly run on the 8th:

| Rank | Name | Personal BV required (lifetime) | Qualification criteria | Pool % | Q-Period (PYP) | Monthly repurchase |
|---|---|---|---|---|---|---|
| 1 | Silver Partner | 7,000 (Dealer) | 2,50,000 / 2,50,000 Genos BV in a calendar month | 7% | 1 | 1,000 BV |
| 2 | Pearl Partner | 15,000 (Wholesaler) | 6,00,000 / 6,00,000 Genos BV in a calendar month | 3.4% | 1 | 1,100 BV |
| 3 | Emerald Partner | 32,000 (Distributor) | 2 Pearl Partners each Genos side | 2.7% | 2 | 1,200 BV |
| 4 | Gold Partner | 68,000 (Regional Distributor) | 2 Emerald Partners each side | 2.2% | 2 | 1,300 BV |
| 5 | Diamond Partner | 1,44,000 (National Distributor) | 2 Gold Partners each side | 1.7% | 2 | 1,400 BV |
| 6 | Blue Diamond Partner | 3,00,000 (Global Distributor) | 2 Diamond Partners each side | 1.2% | 3 | 1,600 BV |
| 7 | Royal Diamond Partner | 3,00,000 (Global Distributor) | 2 Blue Diamond Partners each side | 0.9% | 3 | 1,800 BV |
| 8 | Crown Diamond Partner | 3,00,000 (Global Distributor) | 2 Royal Diamond Partners each side | 0.6% | 3 | 2,000 BV |
| 9 | Elite Diamond Partner | 3,00,000 (Global Distributor) | 2 Crown Diamond Partners each side | 0.3% | 3 | 2,300 BV |

**Total envelope = 7 + 3.4 + 2.7 + 2.2 + 1.7 + 1.2 + 0.9 + 0.6 + 0.3 = 20%**

- **Ranks 1–2 are BV matches; ranks 3–9 are structural.** `RankQualificationService::checkRanks1And2()`
  sums `group_bv_daily` across the calendar month and requires **both** sides to reach the
  threshold; ranks 3+ carry a null group-BV requirement and use
  `structural_qualifiers_per_side = 2` instead. Rank 1 may be skipped — Rank 2 is attainable directly.
- **Weaker-leg top-up (ranks 1–2 only).** Up to 15,000 BV (R1) / 30,000 BV (R2) of *that month's*
  personal purchase BV supplements whichever side is lower, for the qualification test only. The
  raw Left/Right BV is what the `rank_qualifications` row records.
- **Rank 1 is points-based**: 10 RAP per achiever plus 5 points per AO-GO grantee; point value =
  Rank-1 pool ÷ total points, floored to the whole rupee. Ranks 2–9 split their share equally
  (`rap_points` is null for them).
- **Lifetime Awards budget per rank** (`lifetime_award_budget_paise`): ₹15,000 · ₹30,000 · ₹90,000 ·
  ₹3,65,000 · ₹10,00,000 · ₹3,00,00,000 · ₹9,00,00,000 · ₹14,00,00,000 · ₹22,50,00,000.

### 1+2 Rule (Ranks 1 and 2 only) — RETIRED

> ⚠️ **SUPERSEDED by AO-GO (KP 2026-08-05) — the 1+2 rule below is retired and its machinery is removed from the code** (`rank_tiers.carry_forward_months` dropped, no carry-forward rows are created; historical `rank_qualifications.is_carry_forward` rows are retained and still read). In its place: **AO-GO ("Achieve Once – Get Once")** — a distributor who genuinely achieved a rank but holds none this month earns **5 points in the Rank-1 pool** (whatever rank they held), **max 3 lifetime uses**, never in consecutive months, a rank must be re-achieved between uses, and the month's requalification conditions must be met; a failed month consumes no use. Rank 1 is otherwise points-based (10 RAP per achiever, point value = Rank-1 pool ÷ total points floored to the whole rupee); ranks 2–9 split their pool equally. Do not reintroduce 1+2.

- When a distributor qualifies for Rank 1 in month M, they receive the 7% share in M, M+1, and M+2 — even if they don't re-qualify in M+1/M+2, provided they complete 1,000 BV repurchase and zero their wallet each month.
- After the 1+2 window expires, they must re-qualify to restart the cycle.
- This "1+2" benefit can be availed any number of times for Rank 1 (until Rank 2 is achieved).
- If Rank 2 is achieved, the Rank 1 "1+2" is cancelled.
- Same 1+2 rule applies to Rank 2 (4% pool).

### Prove Your Position (PYP)

- Ranks 1 & 2: no PYP needed for initial achievement.
- Ranks 3–5: must achieve the rank **twice** — counted over the **lifetime** (Option C, KP confirmed 2026-08-07): any two qualified occurrences, whenever they happen (gaps fine, two in one month count as two), open the next rank **permanently**. Subsequent re-qualifications require the rank's repurchase BV + wallet zero.
- Ranks 6–9 (incl. Elite Diamond): must achieve the rank **three times**, same lifetime counting. (The old "same calendar month" wording above is the superseded June doc.)

---

## 5th Benefit: Fortune Bonus (replaces "Auto Pool" from old plan)

> ⚠️ **SUPERSEDED by KP's 2026-08-09 level cascade — the whole "Bonus per member (₹)" column below is dead, and so is the 2026-08-07 single month-wide point value.** Current model:
>
> ```
> Fortune pool = comp.fortune.pool_rate_bp (default 500 bp = 5%) × the month's company BV
> FB points    = Σ over your matrix downline of points_per_member[relative depth]
>                depth 1→9, 2→8, 3→7, 4→6, 5→5, 6→4, 7→3, 8→2, 9→1; deeper → 0
> minC         = comp.fortune.min_commission_paise (default ₹30), reserved: pool − minC × qualifiers
>   pool < minC × N  →  everyone gets floor_to_rupee(pool ÷ N), nothing else
> capped levels 0–6 (ascending), each:
>   value_L = floor_to_rupee(remaining ÷ ALL remaining points)
>   income  = min(minC + points × value_L, cap_L)          ← caps 30k/30k/30k/30k/20k/10k/5k incl minC
> levels 7–9 (since 2026-09-03): capped like every other level — caps ₹2,500 / ₹1,500 / ₹30
> (legacy, months frozen before 2026-09-03 only: residual 7–8 shared value; flat 9 = minC)   ← no manual override anywhere
> ```
>
> Per-level depth points, payout modes and caps are admin-editable (`fortune_bonus_levels.points_per_member` / `payout_mode` / `cap_paise`); sparse months keep the ABSOLUTE-level treatment (user decision 2026-08-09). Matrix placement (3-wide, 9 levels, monthly reset, FCFS by first GSB credit date) is unchanged. Pool economics — including one `fortune_monthly_pool_levels` row per occupied level — are frozen per month in `fortune_monthly_pools` before any credit (`fortune.pool.frozen` audit row) and never recomputed; re-runs reconstruct incomes from the snapshot. `FortuneDistributionCalculator::allocate()` is the pure allocator, regression-locked to the client's ₹36cr worked example (per-level values ₹13/13/14/15/16/18/19/20/22, leftover ₹24,576 since 2026-09-03; the legacy residual config — shared ₹20, ₹3,78,870 leftover — is locked separately) and to the client's September 121-qualifier example (values ₹815/1,057/1,565/3,109, level 4 ₹30 each, leftover ₹14,57,570).
>
> **New eligibility gates** (the list further down is the superseded June version): new joiner (month 1) 3,000 BV lifetime personal purchases **and GSB slab 1 specifically** in the same month; non-ranked (month 2+) one of the 7 personal-purchase titles (≥3,000 BV lifetime) + 600 BV this month + wallet zero + ≥1 slab; **rank 1 → 1,000 BV / 8 achievements, rank 2 → 1,100 / 11, rank 3 → 1,200 / 14, rank 4 → 1,300 / 17, rank 5 → 1,400 / 20**; ranks 6–9 still excluded. "8 achievements" means credited slab cut-offs counted with repeats (GSB has only 7 distinct slabs). Repurchase hold/suspension applies as for every other bonus.
>
> **Compliance:** KP's own spec frames this as income "based on luck or chance". That framing must **never** appear in public copy — describe it factually as a participation-based monthly pool (see R-37).

A 3×9 matrix-based monthly bonus, reset each month. Distributors are placed "first-come, first-served" based on GSB activity.

### Matrix structure

| Level | Members | Bonus per member (₹) |
|---|---|---|
| 0 (You) | 1 | 3.39 |
| 1 | 3 | 10.17 |
| 2 | 9 | 30.50 |
| 3 | 27 | 45.79 |
| 4 | 81 | 68.88 |
| 5 | 243 | 25.00 |
| 6 | 729 | 60.00 |
| 7 | 2,187 | 55.00 |
| 8 | 6,561 | 51.00 |
| 9 | 19,683 | — |
| **Total** | **29,524** | **₹449.73** |

Fortune Bonus is capped at rank 5. Ranks 6–9 are **not eligible**.

### Eligibility gates (per month)

- **New joiner (month 1):** complete 3,000 BV personal purchases (Retailer title) AND qualify for GSB 1st income (15K/15K in same calendar month).
- **Non-ranked (month 2+):** 600 BV personal repurchase + wallet zero + achieve at least one of the 7 GSB slabs.
- **Rank 1:** 1,000 BV repurchase + wallet zero + achieve any 4 GSB slabs in the month.
- **Rank 2:** 1,000 BV repurchase + wallet zero + achieve any 6 GSB slabs.
- **Rank 3:** 1,000 BV repurchase + wallet zero + achieve any 8 GSB slabs.
- **Rank 4:** 1,000 BV repurchase + wallet zero + achieve any 10 GSB slabs.
- **Rank 5:** 1,000 BV repurchase + wallet zero + achieve any 12 GSB slabs.

---

## 6th Benefit: Lifetime Awards & Rewards — 32% (non-cash)

Non-cash rewards delivered on rank achievement, subject to delivery conditions:
- Ranks 1–2: awarded and delivered in the same month of achievement (no PYP needed).
- Ranks 3–5: awarded after proving the rank **twice**.
- Ranks 6–9: awarded after proving the rank **three times**.

Non-cash perquisite tax treatment must be verified before any reward is released.

---

## 7th Benefit: Arete Development Center Bonus — 3%

Replaces "Franchise Bonus" from old plan. An official center established with company approval; assigned to a qualifying distributor.

- **Earning:** 3% on BV generated by distributors who are connected to and served by that center.
- **Cap:** ₹1,00,000/month per center.
- **Payout:** monthly on the 8th.
- **Center development phases** (linked to income milestones):
  - Up to ₹20K income: 400 sq ft space, basic furniture/AV
  - Up to ₹40K income: 600 sq ft, TV + Wi-Fi + stage
  - Up to ₹60K income: 900 sq ft, AC + projector
  - Up to ₹80K income: 1,200 sq ft, full facility incl. kitchen + VIP room

---

## Compliance notes (mandatory — never relax)

- Every GSB row, every rank share, every Fortune Bonus entry, every Mentorship Bonus credit must be tied to a `product_sale_id`. Recruitment alone NEVER earns.
- Never display potential earnings projections or forward-looking income charts.
- Non-cash rewards (cars, insurance, trips) fulfilled via vendor workflows; perquisite tax verified before release.
- Admin charge (3% or ₹30,000) applies to GSB, Mentorship Bonus, Rank Bonus. NOT to awards, Fortune Bonus, or Arete Development Center Bonus.
- TDS 5% applies at payout. Verify current Income Tax rate.
- Bank transfer of bonuses blocked until distributor reaches Retailer title (3,000 BV personal lifetime).
