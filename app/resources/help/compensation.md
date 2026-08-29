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

### Distributor compensation page — tabs
Every bonus with a per-distributor history has its own tab: GSB History, Genos BV Ledger, Mentorship Bonus, Growth Booster, Rank Bonus, Fortune Bonus, ADC Bonus, Daily BV Log, Wallet Ledger, Repurchase, Payout History and Audit Log. Each bonus tab is **flag-gated** — a bonus whose feature flag is off has no tab, and a link to it (a stale bookmark, or a report opened after the flag was switched off) simply opens the first visible tab instead of erroring. Every "Calculation report" links its ADN column to the matching tab.

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

The per-day economics are visible under **Compensation → GSB Input & Output / Day**: each day's total BV, pool, fixed section (slabs 1–2), variable section (slabs 3–7 with the variance vs the ₹250 cap), section totals, grand total and leftover, searchable by day number / week number / date range with CSV export. Every period block (and the CSV) carries a **Computed** timestamp — when its figures were frozen, i.e. the data as it stood at that moment; on a testing recompute this is the recompute time. The whole model ships behind the **GSB daily pool pricing** feature flag — flag off = every slab pays its fixed value, no pool rows are written.

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
A distributor's own purchase BV helps them reach a slab by being added to their weaker Genos leg — but only when it can matter. Their personal purchase BV **accumulates** (pending) and is credited to the weaker leg **only on a cut-off where either leg's effective BV, including carry-forward, has reached a slab's matching value** (the smallest is 15,000 BV). If neither leg reaches it that day, the personal BV stays pending and is tried again the next day. Once credited it is consumed for that match; fresh purchases start a new pending balance. The distributor's real lifetime personal BV (titles, repurchase, bank-release checks) is never altered by this — the top-up only nudges the day's Genos match. Pending personal BV is **never part of a leg's carry over before the cut-off runs** (client, 2026-08-25): the distributor's Genos pages show it as a separate "personal purchase — pending tonight's cut-off" line under the weaker side, and it enters the Left/Right figures only once the 23:59 cut-off has credited it.

## Mentorship Bonus (MSB) — daily pool engine (KP 2026-07-30)
When a **directly sponsored** distributor's cut-off credits a GSB slab, the sponsor earns that slab's **MSB points** — 21 / 18 / 15 / 12 / 9 / 6 / 3 for slabs 1–7, admin-editable on Compensation → Plan settings. The points have **no configured rupee value**: like GSB, the Mentorship Bonus now funds itself from a share of the day's BV.

1. **Pool** = the *MSB daily pool rate* (Settings → Compensation plan, default **3%**) of the day's company-wide BV — the same signed BV-ledger sum the GSB pool uses.
2. **Point value** = `floor-to-whole-rupee(pool ÷ the day's total MSB points)`. No cap, no per-slab value. Only sponsors who will actually be paid count toward that total, so a sponsor below the 600 BV personal minimum neither earns nor dilutes the pool.
3. Every earner is credited **their points × that one value**, and the value is snapshotted on each row (`msb_point_value_paise`) — admin plan edits and later re-runs never change history.

Worked example (KP): a 1,00,000 BV day → pool ₹3,000. Two sponsors accrue 21 points each (sponsees matched slab 1) and one accrues 18 (slab 2) = 60 points → **₹50/point**; they earn ₹1,050, ₹1,050 and ₹900 — ₹3,000, exactly the pool. With five earners on 21+18+15+12+9 = 75 points the same pool gives ₹40/point.

Because the value depends on the whole day, MSB is credited in a **third pass** of the cut-off: every distributor settles first, then the pool is frozen, then each sponsor is credited. A day on which nobody accrued points freezes a ₹0 value and the pool goes unspent; a later retry of such a day therefore credits ₹0 (the report flags those days). A retry on a normal day prices against the frozen value, which can push the day's leftover slightly negative — the company absorbs it.

The per-day arithmetic is visible under **Compensation → MSB Input & Output / Day**: each day's total received BV, the 3% pool, every earning sponsor with their points, the day's point value and income, footed by total points and total income, searchable by day number / week number / date range with CSV export. Every period block (and the CSV) carries a **Computed** timestamp — when its figures were frozen, i.e. the data as it stood at that moment; on a testing recompute this is the recompute time. The **MSB Calculation Report** remains the per-credit sponsor–sponsee ledger.

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
- **Forfeited** (`repurchase_suspended`) — a distributor who misses their repurchase due date is suspended immediately (grace_days = 0). Their month's GBB is forfeited and can never be paid, so those distributors are **excluded from the denominator** (their AGP does not dilute anyone else's point value).

GBB sits behind its own feature flag and is **OFF**; nothing is credited until the plan change is published with the §6.2 notice period. The per-month arithmetic (BV, pool, eligible distributors, total AGP, point value, income, leftover, held/suspended rows) is visible in the admin **GBB calculation report**.

The pool reconciliation itself lives under **Compensation → GBB Input & Output / Month**: one block per month showing the frozen pool row verbatim (month BV, pool, total AGP, point value), every AGP earner with their income and status — held rows sit inside the frozen denominator, suspended rows show the excluded AGP at ₹0 — footed by total AGP, total income and the leftover. Searchable by month or month range, with CSV export. Every period block (and the CSV) carries a **Computed** timestamp — when its figures were frozen, i.e. the data as it stood at that moment; on a testing recompute this is the recompute time.

## Rank Bonus — points model & AO-GO (KP 2026-08-05)

Each rank's pool is its `pool %` **of the 20% Rank-Bonus envelope of the month's company BV** — the percentage is a share of the envelope, not of turnover directly. Worked example: a month with 10,00,000 BV gives an envelope of 20% = ₹2,00,000, so Rank 1's 7% share is ₹14,000. (Tier percentages: R1 7% … R5 1.7% … R9 0.3%.) Credited by the monthly run on the 8th.

- **Rank 1 (Silver) — points-based.** Every achiever earns **10 RAP** (Rank Achievement Points) and every AO-GO grantee **5 points**. Point value = Rank-1 pool ÷ the month's total points, floored to the whole rupee (remainder unspent). Income = own points × point value. The RAP figure is per-rank config (`rap_points` on the rank tier); a blank `rap_points` means equal split.
- **Ranks 2–9 — equal split** among that rank's achievers (`rap_points` blank).
- **AO-GO ("Achieve Once – Get Once")** replaces the retired 1+2 carry-forward: a distributor who genuinely achieved a rank but holds none this month earns 5 points in the Rank-1 pool (whatever rank they held) — max 3 lifetime uses, never in consecutive months, a rank must be re-achieved between uses, and the month's requalification conditions must be met. A failed month consumes no use.
- **Requalification conditions (§8).** Achieving a rank a **2nd or later time** is credited only if, in that month, personal purchase BV ≥ the rank's repurchase BV (R1 1,000 … R9 2,300) **and** the repurchase wallet is cleared. Otherwise the row is recorded as `requalification_held` — visible in the RB report, excluded from the pool denominator, never back-paid. First-time achievers are exempt.
- **Q-Period (PYP).** Achieving rank r its `Q-Period` number of times (lifetime total — gaps between months and multiple achievements within one month all count) grants permission to attain rank r+1, permanently — R1/R2 once, R3–R5 twice, R6–R9 three times. It no longer filters payment; payment follows achievement. For ranks 3+, two qualified partners per Genos side are not enough: the candidate must personally have completed the lower rank's Q-Period.
- **Genos BV matches (KP 2026-08-13).** R1 needs 2,50,000 BV per side (was 3L) and R2 needs 6,00,000 BV per side (was 8L), each within one calendar month; Rank 1 may be skipped — Rank 2 is attainable directly. Up to 15,000 BV (R1) / 30,000 BV (R2) of that month's personal purchase BV supplements the weaker side.

**Compensation → RB Input & Output / Month** is the per-month pool reconciliation: one block per month with a row per rank — pool % and ₹ pool, qualifiers, held re-qualifiers, Rank 1's points and point value, ranks 2–9's equal share, income and the derived leftover — plus an AO-GO line under Rank 1 and a month grand total, with CSV export. The ₹ pools, counts and point values are frozen snapshots from the run's result rows; the envelope % and per-rank pool % are current plan settings (the engine stores no monthly pool table), and a rank that had no qualifiers shows an asterisked pool estimated from the month's turnover and those current settings. Every period block (and the CSV) carries a **Computed** timestamp — when its figures were frozen, i.e. the data as it stood at that moment; on a testing recompute this is the recompute time.

The **RB Monthly Calculation** report shows two tables: Rank 1 (Arete Center, RAP, AO-GO points, point value, total income; AO-GO rows show RAP as "—") and Ranks 2–9 (rank + income). Clicking an ADN in either table opens that distributor's compensation page on its **Rank Bonus** tab (monthly RB rows, the rank qualifications behind them, an **AO-GO offer — this month** checklist, and any AO-GO grants). The checklist evaluates the four AO-GO rules live from what is recorded today, so support can answer "why no AO-GO this month?" without re-deriving them; the grant itself is still created only when the month's Rank Bonus run executes.

### What the distributor sees

The distributor's own **My Income → Rank Bonus** page opens with a **My rank status** panel: current rank (this month's achievement, or last month's while the current month is still accumulating), highest rank ever achieved, a per-rank "achieved ×N" list, and the next rank's published conditions measured against their own current figures — Ranks 1–2 as this month's Left/Right Genos BV (including the weaker-leg personal-BV top-up), Ranks 3–9 as the lower rank's Q-Period count plus qualified partners per Genos side. A row is shown when the month's §8 requalification conditions were missed. Progress is expressed only in BV, counts and plan conditions — never in rupees, and never as a projection. The same rank names appear as **Current Rank / Highest Rank** on the distributor's dashboard ID card (owner-only, and only while the Rank Bonus flag is on).

Below it, an **AO-GO offer** panel appears for anyone the offer can apply to (a rank achieved at least once): lifetime uses ("Used 1 of 3"), the points a grant is worth, and this month's conditions as a tick-list — a rank achieved in an earlier month, no rank held this month, lifetime uses remaining, plus (once the offer has been used) not used last month and a rank re-achieved since the last use, and always the month's requalification conditions. When the monthly run has already created the grant, the panel says so with the points recorded. Points only — no rupee figure, and no suggestion the grant will be made.

## Fortune Bonus — monthly pool, downline points & level cascade (KP 2026-08-09)

A monthly bonus funded from a share of the month's company BV, distributed through a level cascade: a guaranteed ₹30 minimum for every qualifier, per-level point values with per-member caps at the top of the matrix, a shared residual value deeper down, and the flat minimum at the bottom.

### The matrix

A **3-wide forced matrix, 9 levels deep**, rebuilt from scratch **every month** — nothing carries over. Enrolment is **first-come, first-served**, ordered by the date of each distributor's first GSB credit (ties broken by distributor id), so the earliest qualifier that month takes position 1 and everyone else fills the matrix sequentially below.

### Points

Points come from the distributors placed **below** you in the matrix, by how many levels down they sit:

| Levels below you | 1 | 2 | 3 | 4 | 5 | 6 | 7 | 8 | 9 |
|---|---|---|---|---|---|---|---|---|---|
| **Points per member** | 9 | 8 | 7 | 6 | 5 | 4 | 3 | 2 | 1 |

Members deeper than level 9 earn nothing. All nine figures are **admin-editable** on Compensation → Plan settings. A distributor earns no points from their own position, so the newest entries at the bottom of the month's matrix finish the month on zero points — they still receive the ₹30 minimum.

### Monthly pool & the level cascade

1. **Pool** = the *Fortune monthly pool rate* (Settings → Compensation plan, default **5%**) of the month's company-wide **BV** — the same signed BV-ledger sum the GSB, MSB and GBB pools use.
2. **Minimum commission** — every qualifier is guaranteed the configured minimum (default **₹30**), reserved off the pool before anything else. If a month's pool cannot cover the guarantees, every qualifier gets the same pro-rated whole-rupee share (`floor(pool ÷ qualifiers)`) and nothing else that month; a ₹0 pool credits nothing.
3. **Capped levels** (matrix levels 0–6 by default) settle top-down. Each level prices at `floor-to-whole-rupee(remaining pool ÷ ALL remaining points)`, and each member receives `minimum + points × value`, limited by the level's per-member cap — **₹30,000** at levels 0–3, **₹20,000** at level 4, **₹10,000** at level 5, **₹5,000** at level 6, every cap **including** the ₹30. What a level actually consumed is deducted before the next level's value is computed.
4. **Residual levels** (7–8 by default) share **one** value computed over their **combined** points from whatever pool remains, uncapped: `minimum + points × value`.
5. **Flat level** (9) receives the minimum only.
6. Payout modes, caps and the minimum are all admin-editable (Plan settings → Fortune levels; the minimum under Settings → Compensation plan). There is **no manual override** of any computed value. Whatever the cascade cannot distribute — flooring remainders and cap headroom — stays unspent with the company.
7. The month's economics — the pool row **and one row per matrix level** (`fortune_monthly_pool_levels`: mode, cap, participants, points, value, paid) — are frozen **before any credit** and never recomputed, so re-runs and single-distributor retries reconstruct incomes from the same snapshot (`fortune.pool.frozen` audit entry) — the same auditable design as the GSB, MSB and GBB pools. Sparse months keep the **absolute-level** treatment: caps stay glued to levels 0–6 even when deeper levels are empty.

### Who is enrolled

Every gate is assessed **per month**, and the distributor's repurchase wallet must be clear — missing the repurchase due date suspends the month's Fortune Bonus immediately (grace_days = 0; there is no held window).

| Tier | Personal BV required | GSB slab achievements required in the month |
|---|---|---|
| New joiner (month 1) | 3,000 BV lifetime personal purchases (Retailer title) | **Slab 1 specifically** (the 15K/15K match), in the same calendar month |
| Non-ranked (month 2+) | holds one of the 7 personal-purchase titles (3,000 BV lifetime) **and** 600 BV this month, wallet zero | at least 1 of the 7 slabs |
| Rank 1 | 1,000 BV, wallet zero | 8 |
| Rank 2 | 1,100 BV, wallet zero | 11 |
| Rank 3 | 1,200 BV, wallet zero | 14 |
| Rank 4 | 1,300 BV, wallet zero | 17 |
| Rank 5 | 1,400 BV, wallet zero | 20 |

**Ranks 6–9 are not eligible.** "Slab achievements" is a **count of credited cut-offs**, not distinct slabs — GSB has only 7 slabs, so hitting the same slab repeatedly is how a rank-5 distributor reaches 20. All BV figures and achievement counts are admin-editable on Compensation → Plan settings.

Fortune sits behind its own feature flag and is **OFF**; nothing is enrolled or credited until the plan change is published with the §6.2 notice period. Enrolment runs on the 9th at 08:45 IST and the monthly credit run at 09:00, both for the previous month. The per-month arithmetic (company BV, pool, minimum guarantee, per-level values/caps/paid, payout, leftover, and the per-distributor level/points/value/income rows) is visible in the admin **FB Monthly Calculation** report and on the Fortune month screen's per-level economics table.

This engine replaced a fixed rupee amount per matrix level (₹3.39 … ₹51.00), which paid the same figure regardless of the month's volume — the old per-level amounts no longer exist anywhere in the plan configuration.

## Monthly payout (Groups B/C/D: GBB, Rank, Fortune, Awards, ADC)
Runs monthly. A per-group admin charge (3%, each group capped ₹25,000/cycle) and TDS (5%) are applied. Repurchase is deducted only in the weekly batch.

### Arete Development Center (ADC) Bonus
The bonus base is the month's **net** member BV — refunds are deducted, so a cancelled or refunded order no longer pays the centre owner. A centre whose members net to zero for the month is skipped as no-BV, and one that nets below zero is counted separately as net-negative; neither is ever credited a negative amount. The monthly run reports both counts.

Each centre carries a pincode, district and state alongside its legacy free-text location, set on **Add Center** and changeable later from **Edit** on the centres list — every create and every edit is written to the audit log with its before and after values. The **ADC Monthly Calculation** report is searchable by any of them (pincode matches exactly or by prefix) and shows the centre name and its Pincode / District / State on screen and in the CSV export; it is visible only while the ADC feature is enabled.

**Development phases are tracked manually.** Every centre sits in one of four phases (Phase 1 — up to ₹20,000/month · 400 sq ft basic setup; Phase 2 — up to ₹40,000/month · 600 sq ft with TV/Wi-Fi/stage; Phase 3 — up to ₹60,000/month · 900 sq ft with AC and projector; Phase 4 — up to ₹80,000/month · 1,200 sq ft full facility). The phase is judged on the centre's ADC income in a **single calendar month**, not on lifetime income. When a centre's monthly income crosses the next level, the owner must email the company a letter and photos of the developed centre; after verifying them, upgrade the phase on the centre's **Edit** form (audit-logged).

**If the owner does not prove the upgrade**, apply the penalty on the same form: set the **Monthly cap override** to the lower slab income (for example, ₹20,000 for a centre that crossed into the Phase-2 income level without upgrading) — the engine then pays at most that amount for up to 3 months. The override can only lower the standard ₹1,00,000 cap, never raise it. If the centre still hasn't developed after 3 months, set its status to **Inactive** to stop the income entirely while the company investigates or transfers the centre to another distributor. Clear the override (leave the field blank) as soon as the upgrade is verified; the audit log records every change with its dates.

## ₹50 lakh combined monthly income cap
The five cash bonuses (GSB, Mentorship, GBB, Rank, Fortune) share one ₹50,00,000/month gross ceiling, enforced across the month's weekly and monthly batches together. Income above the cap is forfeited at payout with an explicit `income_cap_forfeit` wallet debit (no phantom balance, no carry to next month). When the monthly batch has to trim, Rank is forfeited first, then GBB, then Fortune. Awards (non-cash) and ADC (own ₹1L cap) are outside this ceiling.

All of the above rates and caps are admin-editable under **Settings → Compensation plan — rates, caps & periods**.

## Manual controls
Use Manual Controls (always audit-logged) for: failed cut-offs (Retry is safe/idempotent), BV reversals after cut-off (Recalculate CF), incorrect credits (Reverse), and frozen accounts.

## Engine Runs (run a bonus engine manually)
**Compensation → Plan & controls → Engine Runs** lists every compensation engine with its schedule, feature-flag state, last run and a run-events log. Use it when a scheduled run failed or never happened and a whole engine needs to run for a whole period — for a single distributor fix, use Manual Controls instead.

- **Dependencies run first.** Triggering an engine also runs the engines it depends on, for any periods that are missing — e.g. running Growth Booster first fills any missing GSB daily cut-offs for the month and the prior month's rank check. Periods already computed are skipped. Running an engine never triggers the engines *downstream* of it (Growth Booster does not fire the monthly payout).
- **Runs are queued.** A trigger returns immediately; the work runs in the background on the dedicated `compensation` queue, which has its own single worker (Supervisord Job 3 on Cloudways). Refresh the page or open **Run events** to follow progress. A run stuck in *running* for over 2 hours is shown as *stale* — the process died and the run can be re-triggered.
- **A failed run is not retried automatically.** The compensation worker runs each job once (tries 1) on purpose: a run that died halfway has already written some credits, and an automatic retry would replay on top of them. The failure is recorded in **Run events** and in the failed-jobs list; read the reason, then re-trigger from this page deliberately. If a trigger never leaves *queued*, the compensation worker is down — that is the first thing to check.
- **Safe to re-run.** Every engine is idempotent: distributors already credited for the period are skipped and frozen pool economics are reused, so a re-run never credits anybody twice.
- **Only closed periods can be run for the pool engines.** The GSB Daily Cut-off accepts yesterday or earlier; Growth Booster, Rank Bonus, ADC and Fortune accept the previous month or earlier. These engines freeze the period's pool economics permanently the moment they run — running one for a day or month still in flight would price the whole period on partial sales (a cut-off run at 23:27 froze one staging day's pool at ₹0 before the evening's orders landed, and the overnight run then paid real achievers out of the empty pool). To preview what today or this month pays, use the recompute testing tool below instead. Repurchase Evaluation and the Rank Qualification Check are the exceptions — neither freezes a pool, so both may run for the current period.
- **A pool frozen too early heals itself when nothing was paid from it.** If a day's GSB/MSB pool was somehow frozen before the day ended (e.g. by the recompute tool's "as at this moment" preview) and no bonus was credited against it, the next scheduled cut-off replaces it with the real numbers and writes a `gsb.pool.refrozen` / `msb.pool.refrozen` audit entry. Once anything **was** credited against it, the pool is kept — frozen economics never move under money that has been paid — and the fix is a recompute of that window.
- **Every trigger is audit-logged** with your admin ID, the reason you provide, and the exact chain of steps that was planned.
- **Rank Qualification Check is manual-only** (it has no schedule). It writes the qualification rows that Rank Bonus, Growth Booster, Fortune Bonus and Lifetime Awards all read — run it for a month before running those engines.
- **The two payout-batch engines (GSB Weekly Payout, Monthly Payout Batch) are scheduler-only.** They create payout batches, and batches are approved by the same finance permission — allowing a manual trigger would let one person both create and approve a payout. They still appear on the page with their last run and events.
- **An engine whose feature flag is off is not shown on the page** — like every other surface of a disabled feature. Enable the flag under Feature Flags and the engine reappears with its run history intact.
- **Each run shows how long it actually took** ("took 119ms"), so a slow engine is visible rather than guessed at.

### Testing tools (staging and local only, never production)

Two red cards appear at the top of the page when the recompute gate is open. The gate has four locks and all four must open: the environment is not production, `COMP_RECOMPUTE_ENABLED` is set, the database you are connected to is named in `COMP_RECOMPUTE_ALLOWED_DATABASES`, and you have typed that database name into the card. The third lock is the one that matters most — an environment label is something an operator types, so it cannot prove the database behind it is free of real cooling-off windows, invoices and payout records. Both cards are removed once the client signs the plan off.

- **Recompute** — deletes derived rows and rebuilds them from the surviving orders. Leave the dates empty to replay everything from the first BV date (slowest). To check only what today or this month pays, set a **From** date and leave *keep earlier history* ticked: only the rows from that date onwards are removed and rebuilt, and anything before it is left exactly as it is. A From date inside a closed month is widened to that month's 1st, because a monthly bonus can only be rebuilt for a whole month. Under **Engines to replay** you can limit the run to particular engines — anything you leave out is not replayed at all, so its results for that window are deleted and never rebuilt. Because that damage is done before the run summary can name it, a partial selection asks you to tick an acknowledgement first, and the confirmation counts the engines that will be left missing.
- **Reset purchase data** — deletes the **orders themselves** plus everything derived from them, for starting a fresh test cycle. Users, distributors, the Genos tree, KYC, consents, the catalog and the whole plan configuration are kept. Unlike a recompute there is then no history to rebuild, so a recompute afterwards finishes in seconds until new orders are placed. It refuses outright while a recompute holds the replay lock, because truncating the orders under a running replay is the one thing re-running cannot fix. Every request is written to the audit log **before** anything is deleted, with who asked for it, which database, and how many rows each table held at the time.
