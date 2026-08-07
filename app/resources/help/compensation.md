# Compensation Module — Admin Reference

## What it does
The Compensation section tracks GSB (Genos Sales Bonus), Mentorship Bonus, wallet balances, and weekly payouts for all distributors.

## Daily cut-off
The day closes at midnight IST; the cut-off job runs at 00:10 the next morning and processes the previous day (the 10-minute buffer lets in-flight BV propagation from late-evening orders land before the day is settled). For each active distributor:
- Reads their Left and Right Genos BV accumulated during the day
- Adds any carry-forward from previous days
- Matches against GSB slabs (constrained by their personal purchase title)
- Credits the wallet with the **gross** GSB amount — admin charge and TDS are deducted later, at payout time (not at cut-off)

### 600 BV eligibility gate
Distributors whose lifetime personal BV is below the minimum (default 600 BV, admin-editable) are skipped at cut-off with status `below_600bv`: their day's Genos BV is discarded, never carried forward, and never retroactively counted. Genos BV still *propagates* into the raw accumulator intraday, so on this page their Left/Right Genos BV cards show the raw figures struck through with an amber **"Not credited — personal BV below 600"** pill; the distributor's own income dashboard shows 0 instead. This is why a distributor can appear to "have" Genos BV yet earn no GSB.

### Genos BV Ledger tab
The distributor compensation page has a **Genos BV Ledger** tab: a transaction-style view grouped by day. Each day lists every paid downline order that credited the distributor's Left or Right Genos BV (order link, buyer ADN, side, +BV — snapshotted per ancestor at credit time, not double-written), any cancelled-order **reversal** rows (red, −BV), closed by that day's **cut-off settlement** row showing the status, any slab matched, and the carry-forward that survived (power side + slab-1 weaker). Use it to answer "where did this Left/Right BV come from and where did it go?".

Distributors have the same ledger under **My Income → Genos Ledger**, with two differences (data minimisation): buyers appear as **ADN only** (no names, no order links), and the page is hidden entirely while the distributor is below the 600 BV personal minimum — same gate as their dashboard.

### Distributor slab ladder (My Income → Genos BV)
The distributor's Genos BV tab opens with a **slab ladder**: all active GSB slabs with their matched-BV threshold and title requirement, a green **Earned ×N** count per slab (credited cut-offs only), and an amber **Next target** row showing how much more matched BV is needed — mirroring exactly what tonight's cut-off will measure (slab 1 includes the lifetime weaker carry-forward; slabs 2–7 count the day's fresh BV plus power CF on its side). Compliance note: the ladder deliberately shows **no rupee amounts** on unearned slabs — progress is expressed in BV only, so nothing on the page projects future income. Below the 600 BV personal minimum the ladder shows the plan thresholds but no group-BV figures.

## Slab table
Each slab's gross GSB = **slab score × a score value** (KP 2026-07-29 daily-pool model). Slabs 1–2 use their **fixed** score value (default ₹250/point, admin-editable under Compensation → Plan settings) and are always paid in full. Slabs 3–7 use the day's **pro-rated pool score value** — computed at each cut-off, never above the fixed ₹250 cap (their score/score-value fields are read-only in Plan settings). Values below reflect the default configuration; slab 3–7 amounts are **maximums**, not guarantees.

| Slab | Matched BV (each side) | Score | Score value | Gross GSB | Title required |
|------|----------------------|-------|-------------|-----------|----------------|
| 1 | 15,000 BV | 8 | ₹250 fixed | ₹2,000 | Retailer (3,000 lifetime) |
| 2 | 36,000 BV | 16 | ₹250 fixed | ₹4,000 | Dealer (7,000 lifetime) |
| 3 | 1,00,000 BV | 32 | pool (≤ ₹250) | up to ₹8,000 | Wholesaler (15,000 lifetime) |
| 4 | 3,00,000 BV | 60 | pool (≤ ₹250) | up to ₹15,000 | Distributor (32,000 lifetime) |
| 5 | 9,00,000 BV | 112 | pool (≤ ₹250) | up to ₹28,000 | Regional Distributor (68,000) |
| 6 | 27,00,000 BV | 184 | pool (≤ ₹250) | up to ₹46,000 | National Distributor (1,44,000) |
| 7 | 81,00,000 BV | 280 | pool (≤ ₹250) | up to ₹70,000 | Global Distributor (3,00,000) |

### Daily GSB pool (slabs 3–7, KP 2026-07-29)
Every cut-off freezes the day's pool economics **before any credit**:

1. **Pool** = the *GSB daily pool rate* (Settings → Compensation plan, default **45%**) of the day's company-wide BV (signed sum of the BV ledger: accruals − reversals).
2. **Fixed payout** = every matched slab 1–2 gross that day (score × fixed value) — paid in full even on a day when this alone exceeds the pool (KP-approved; the report then shows a negative leftover).
3. **Variable score value** = `min(₹250 cap, floor-to-whole-rupee((pool − fixed payout) ÷ total slab 3–7 scores that day))`. Never negative; ₹0 possible on a starved day. On a day with zero slab 3–7 achievers the cap itself is frozen, so a later admin retry pays full value.
4. Each slab 3–7 achiever's gross = **their score × the day's variable value**, and the value used is snapshotted on the result row (`score_value_paise`) — admin plan edits and later re-runs never change history. Single-distributor retries always reuse the frozen day value.

Worked example (KP): 10L BV day → pool ₹4,50,000; 14× slab 1 + 11× slab 2 = ₹72,000 fixed; remaining ₹3,78,000 ÷ 1,712 scores (8/6/4/2/1 achievers on slabs 3–7) = 220.79 → **₹220/score**; variable payout ₹3,76,640; leftover ₹1,360 stays with the company.

The per-day economics are visible under **Compensation → GSB Input & Output / Day**: each day's total BV, pool, fixed section (slabs 1–2), variable section (slabs 3–7 with the variance vs the ₹250 cap), section totals, grand total and leftover, searchable by day number / week number / date range with CSV export. The whole model ships behind the **GSB daily pool pricing** feature flag — flag off = every slab pays its fixed value, no pool rows are written.

The title column is the distributor's **lifetime personal purchase BV** requirement (KP's confirmed 27-06-2026 table, stored in the admin-editable `gsb_slabs` rows). The cut-off pays the **lower** of the matched-BV slab and the title slab: a Retailer whose Genos matches Slab-3-level volume is still paid Slab 1 only, and the weaker-side BV above 15,000 is consumed, not banked. Between 600 and 2,999 personal BV, Genos BV accumulates as carry-forward but **no slab pays at all** — Slab 1 itself requires the Retailer title.

## Carry over & carry forward
Partner-canonical terminology (KP, Aug 2026), used on every distributor-facing page:
- **Carry over** — business that occurs **before** matching. It keeps accumulating day after day as that side's opening balance — never a deduction. This is what the rolling `gsb_carryforward` store holds between matches, and what distributor pages label "carried over".
- **Carry forward** — the remaining BVs **after** matching: when a slab pays, the weaker side resets to 0 and the power side's remainder carries forward. The distributor's My Business page shows this as the `power_cf_after` of their most recent matched cut-off; it reads **0 until their first slab matches**.
- **The rolled balance is the next day's opening balance, not a deduction.** The cut-off folds each side's balance back onto that same side before measuring (`GsbCutoffService::computeForDistributor()`), so a leg that closed at 6,000 BV starts the next day at 6,000 BV. Before the first slab matches, both sides simply keep accumulating. Expect distributor queries about "my BV disappeared"; the dashboard, Genos BV and My Business pages all show the carry-over-inclusive figure.
- **Power side** (stronger leg): rolling balance capped at 4,50,000 BV
- **Slab-1 weaker side**: accumulates indefinitely toward the 15K first match
- **Equal sides tie-break** (KP 2026-07-21): when the two legs are exactly equal at cut-off, the **Left** leg is treated as the stronger/power side — its excess carries forward and the Right leg settles to zero.

## Personal-BV weaker-leg top-up (conditional, KP 2026-07-21)
A distributor's own purchase BV helps them reach a slab by being added to their weaker Genos leg — but only when it can matter. Their personal purchase BV **accumulates** (pending) and is credited to the weaker leg **only on a cut-off where either leg's effective BV, including carry-forward, has reached a slab's matching value** (the smallest is 15,000 BV). If neither leg reaches it that day, the personal BV stays pending and is tried again the next day. Once credited it is consumed for that match; fresh purchases start a new pending balance. The distributor's real lifetime personal BV (titles, repurchase, bank-release checks) is never altered by this — the top-up only nudges the day's Genos match.

## Mentorship Bonus (MSB) — daily pool engine (KP 2026-07-30)
When a **directly sponsored** distributor's cut-off credits a GSB slab, the sponsor earns that slab's **MSB points** — 21 / 18 / 15 / 12 / 9 / 6 / 3 for slabs 1–7, admin-editable on Compensation → Plan settings. The points have **no configured rupee value**: like GSB, the Mentorship Bonus now funds itself from a share of the day's BV.

1. **Pool** = the *MSB daily pool rate* (Settings → Compensation plan, default **3%**) of the day's company-wide BV — the same signed BV-ledger sum the GSB pool uses.
2. **Point value** = `floor-to-whole-rupee(pool ÷ the day's total MSB points)`. No cap, no per-slab value. Only sponsors who will actually be paid count toward that total, so a sponsor below the 600 BV personal minimum neither earns nor dilutes the pool.
3. Every earner is credited **their points × that one value**, and the value is snapshotted on each row (`msb_point_value_paise`) — admin plan edits and later re-runs never change history.

Worked example (KP): a 1,00,000 BV day → pool ₹3,000. Two sponsors accrue 21 points each (sponsees matched slab 1) and one accrues 18 (slab 2) = 60 points → **₹50/point**; they earn ₹1,050, ₹1,050 and ₹900 — ₹3,000, exactly the pool. With five earners on 21+18+15+12+9 = 75 points the same pool gives ₹40/point.

Because the value depends on the whole day, MSB is credited in a **third pass** of the cut-off: every distributor settles first, then the pool is frozen, then each sponsor is credited. A day on which nobody accrued points freezes a ₹0 value and the pool goes unspent; a later retry of such a day therefore credits ₹0 (the report flags those days). A retry on a normal day prices against the frozen value, which can push the day's leftover slightly negative — the company absorbs it.

The per-day arithmetic is visible under **Compensation → MSB Input & Output / Day**: each day's total received BV, the 3% pool, every earning sponsor with their points, the day's point value and income, footed by total points and total income, searchable by day number / week number / date range with CSV export. The **MSB Calculation Report** remains the per-credit sponsor–sponsee ledger.

This engine replaced the fixed ₹250-per-point model (2026-07-25), which had itself replaced the 10%→1% rate ladder on cumulative sponsee GSB — old rows keep their amounts and show "—" in the points columns.

## Cancelled / refunded orders — Genos BV reversal
When an order is cancelled (pre-shipment) or its refund is approved (cooling-off or buyback), its Genos BV is automatically reversed from **exactly the upline distributors it was originally credited to** (a per-ancestor snapshot taken at credit time), on the **same side** — all the way up to the company root. Per KP's confirmed rules (Q8, 27-06-2026):
- **No clawback**: GSB already credited or paid out is never taken back, and earned titles/ranks stay (sticky).
- If the day the reversal lands is **not yet settled**, the BV is simply subtracted from that day's accumulator (shown in the ledger as a red −BV row).
- Whatever the day can't absorb becomes an **open adjustment (negative-carry)** on that side: the next propagated purchases in the same group pay it down **before** any new Genos BV is credited. Both ledgers show an amber "adjustment pending" banner while any balance is open, and credit rows note when part of an order's BV was consumed by an adjustment.
- The buyer's **personal BV** is reversed as before (net lifetime BV drops; the repurchase obligation no longer counts that order).

## Weekly payout (Group A: GSB + Mentorship)
Runs every Tuesday. Minimum payout ₹100. Deductions applied here (not at cut-off): repurchase (10% of prior month GSB + MB + GBB + Fortune + Rank, max ₹10,000 — a monthly figure collected once, spread across the month's weekly batches until fully recovered), then admin charge (3%, capped ₹25,000/cycle), then TDS (5% of the payable).

Distributors whose **KYC is not yet verified** (account not `active`) get a `kyc_pending` line ("Awaiting KYC" in the wallet): they still earn and see all income, but nothing is debited, swept, or transferred to the bank until their KYC is approved — the first batch after approval pays it out. (Same rule in the monthly batch.)

Distributors with **no bank account on file** get a `no_bank_account` line: nothing is debited or swept, the balance stays in the wallet, and the first batch after they add bank details pays it out. (Same rule in the monthly batch.)

## Growth Booster Bonus (GBB) — monthly AGP pool (KP 2026-08-05)

A monthly bonus for early-stage distributors, funded from a share of the month's company BV and divided by the AGP everyone earned.

### Who is eligible
Only distributors who held **no rank in the previous month**. Someone reaching a rank for the **first time in the current month is still eligible**; someone who held any rank last month is not. The gate is evaluated per month, so eligibility can return if a distributor holds no rank in a later prior month.

### AGP (arovolife Growth Points)
AGP accrue per **GSB slab match** during the month — **12 AGP** for a slab-1 match, **5** for slab 2, **2** for slab 3, none for slabs 4–7. Multiple matches in the same month each add AGP. A distributor's AGP is capped at **120 per month**; anything above the cap is not counted and is not carried to the next month.

### Monthly pool & point value
1. **Pool** = the *GBB monthly pool rate* (Settings → Compensation plan, default **5%**) of the month's company-wide **BV** — the same signed BV-ledger sum the GSB and MSB pools use, **not** order sales value (KP 2026-08-05).
2. **Point value** = `floor-to-whole-rupee(pool ÷ the total AGP of all eligible distributors that month)`. Everyone is paid at the same value; income = **their AGP × that value**. The flooring remainder stays unspent with the company.
3. The month's economics are frozen in a `gbb_monthly_pools` row **before any credit** and never recomputed, so re-runs and single-distributor retries price against the same snapshot (`gbb.pool.frozen` audit entry) — the same auditable, tamper-evident design as the GSB and MSB daily pools.

### Repurchase interaction
- **Held** (`repurchase_held`) — a distributor inside their repurchase grace window has their GBB **calculated but not credited**; it is released automatically when they complete their repurchase. Held distributors **still count in the pool denominator**, because they may yet be paid.
- **Forfeited** (`repurchase_suspended`) — once the grace window lapses the month's GBB is forfeited and can never be paid, so those distributors are **excluded from the denominator** (their AGP does not dilute anyone else's point value).

GBB sits behind its own feature flag and is **OFF**; nothing is credited until the plan change is published with the §6.2 notice period. The per-month arithmetic (BV, pool, eligible distributors, total AGP, point value, income, leftover, held/suspended rows) is visible in the admin **GBB calculation report**.

## Rank Bonus — points model & AO-GO (KP 2026-08-05)

Each rank's pool is its `pool %` **of the 20% Rank-Bonus envelope of the month's company BV** — the percentage is a share of the envelope, not of turnover directly. Worked example: a month with 10,00,000 BV gives an envelope of 20% = ₹2,00,000, so Rank 1's 7% share is ₹14,000. (Tier percentages: R1 7% … R5 1.7% … R9 0.3%.) Credited by the monthly run on the 8th.

- **Rank 1 (Silver) — points-based.** Every achiever earns **10 RAP** (Rank Achievement Points) and every AO-GO grantee **5 points**. Point value = Rank-1 pool ÷ the month's total points, floored to the whole rupee (remainder unspent). Income = own points × point value. The RAP figure is per-rank config (`rap_points` on the rank tier); a blank `rap_points` means equal split.
- **Ranks 2–9 — equal split** among that rank's achievers (`rap_points` blank).
- **AO-GO ("Achieve Once – Get Once")** replaces the retired 1+2 carry-forward: a distributor who genuinely achieved a rank but holds none this month earns 5 points in the Rank-1 pool (whatever rank they held) — max 3 lifetime uses, never in consecutive months, a rank must be re-achieved between uses, and the month's requalification conditions must be met. A failed month consumes no use.
- **Requalification conditions (§8).** Achieving a rank a **2nd or later time** is credited only if, in that month, personal purchase BV ≥ the rank's repurchase BV (R1 1,000 … R9 2,300) **and** the repurchase wallet is cleared. Otherwise the row is recorded as `requalification_held` — visible in the RB report, excluded from the pool denominator, never back-paid. First-time achievers are exempt.
- **Q-Period (PYP).** Achieving rank r its `Q-Period` number of times (lifetime total — gaps between months and multiple achievements within one month all count) grants permission to attain rank r+1, permanently — R1/R2 once, R3–R5 twice, R6–R9 three times. It no longer filters payment; payment follows achievement. For ranks 3+, two qualified partners per Genos side are not enough: the candidate must personally have completed the lower rank's Q-Period.
- **R2 Genos BV** is 8,00,000 per side (was 5L); Rank 1 may be skipped — Rank 2 is attainable directly.

The **RB Monthly Calculation** report shows two tables: Rank 1 (Arete Center, RAP, AO-GO points, point value, total income; AO-GO rows show RAP as "—") and Ranks 2–9 (rank + income).

## Monthly payout (Groups B/C/D: GBB, Rank, Fortune, Awards, ADC)
Runs monthly. A per-group admin charge (3%, each group capped ₹25,000/cycle) and TDS (5%) are applied. Repurchase is deducted only in the weekly batch.

### Arete Development Center (ADC) Bonus
The bonus base is the month's **net** member BV — refunds are deducted, so a cancelled or refunded order no longer pays the centre owner. A centre whose members net to zero for the month is skipped as no-BV, and one that nets below zero is counted separately as net-negative; neither is ever credited a negative amount. The monthly run reports both counts.

Each centre carries a pincode, district and state alongside its legacy free-text location, set on **Add Center** and changeable later from **Edit** on the centres list — every create and every edit is written to the audit log with its before and after values. The **ADC Monthly Calculation** report is searchable by any of them (pincode matches exactly or by prefix) and shows the centre name and its Pincode / District / State on screen and in the CSV export; it is visible only while the ADC feature is enabled.

## ₹50 lakh combined monthly income cap
The five cash bonuses (GSB, Mentorship, GBB, Rank, Fortune) share one ₹50,00,000/month gross ceiling, enforced across the month's weekly and monthly batches together. Income above the cap is forfeited at payout with an explicit `income_cap_forfeit` wallet debit (no phantom balance, no carry to next month). When the monthly batch has to trim, Rank is forfeited first, then GBB, then Fortune. Awards (non-cash) and ADC (own ₹1L cap) are outside this ceiling.

All of the above rates and caps are admin-editable under **Settings → Compensation plan — rates, caps & periods**.

## Manual controls
Use Manual Controls (always audit-logged) for: failed cut-offs (Retry is safe/idempotent), BV reversals after cut-off (Recalculate CF), incorrect credits (Reverse), and frozen accounts.
