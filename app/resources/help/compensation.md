# Compensation Module — Admin Reference

## What it does
The Compensation section tracks GSB (Genos Sales Bonus), Mentorship Bonus, wallet balances, and weekly payouts for all distributors.

## Daily cut-off
The day closes at midnight IST; the cut-off job runs at 00:10 the next morning and processes the previous day (the 10-minute buffer lets in-flight BV propagation from late-evening orders land before the day is settled). For each active distributor:
- Reads their Left and Right Genos group BV accumulated during the day
- Adds any carry-forward from previous days
- Matches against GSB slabs (constrained by their personal purchase title)
- Credits the wallet with the **gross** GSB amount — admin charge and TDS are deducted later, at payout time (not at cut-off)

### 600 BV eligibility gate
Distributors whose lifetime personal BV is below the minimum (default 600 BV, admin-editable) are skipped at cut-off with status `below_600bv`: their day's group BV is discarded, never carried forward, and never retroactively counted. Group BV still *propagates* into the raw accumulator intraday, so on this page their Left/Right Group BV cards show the raw figures struck through with an amber **"Not credited — personal BV below 600"** pill; the distributor's own income dashboard shows 0 instead. This is why a distributor can appear to "have" group BV yet earn no GSB.

### Genos BV Ledger tab
The distributor compensation page has a **Genos BV Ledger** tab: a transaction-style view grouped by day. Each day lists every paid downline order that credited the distributor's Left or Right group BV (order link, buyer ADN, side, +BV — snapshotted per ancestor at credit time, not double-written), any cancelled-order **reversal** rows (red, −BV), closed by that day's **cut-off settlement** row showing the status, any slab matched, and the carry-forward that survived (power side + slab-1 weaker). Use it to answer "where did this Left/Right BV come from and where did it go?".

Distributors have the same ledger under **My Income → Genos Ledger**, with two differences (data minimisation): buyers appear as **ADN only** (no names, no order links), and the page is hidden entirely while the distributor is below the 600 BV personal minimum — same gate as their dashboard.

### Distributor slab ladder (My Income → Genos BV)
The distributor's Genos BV tab opens with a **slab ladder**: all active GSB slabs with their matched-BV threshold and title requirement, a green **Earned ×N** count per slab (credited cut-offs only), and an amber **Next target** row showing how much more matched BV is needed — mirroring exactly what tonight's cut-off will measure (slab 1 includes the lifetime weaker carry-forward; slabs 2–7 count the day's fresh BV plus power CF on its side). Compliance note: the ladder deliberately shows **no rupee amounts** on unearned slabs — progress is expressed in BV only, so nothing on the page projects future income. Below the 600 BV personal minimum the ladder shows the plan thresholds but no group-BV figures.

## Slab table
Each slab's gross GSB = **slab score × ₹360** (KP-confirmed; the ₹360 score rate and every slab figure are admin-editable under Compensation → Plan settings). Values below reflect the live plan configuration.

| Slab | Matched BV (each side) | Score | Gross GSB | Title required |
|------|----------------------|-------|-----------|----------------|
| 1 | 15,000 BV | 5 | ₹1,800 | Retailer (3,000 lifetime) |
| 2 | 36,000 BV | 10 | ₹3,600 | Dealer (7,000 lifetime) |
| 3 | 90,000 BV | 20 | ₹7,200 | Wholesaler (15,000 lifetime) |
| 4 | 2,70,000 BV | 38 | ₹13,680 | Distributor (32,000 lifetime) |
| 5 | 8,10,000 BV | 70 | ₹25,200 | Regional Distributor (68,000) |
| 6 | 24,30,000 BV | 117 | ₹42,120 | National Distributor (1,44,000) |
| 7 | 72,90,000 BV | 167 | ₹60,120 | Global Distributor (3,00,000) |

The title column is the distributor's **lifetime personal purchase BV** requirement (KP's confirmed 27-06-2026 table, stored in the admin-editable `gsb_slabs` rows). The cut-off pays the **lower** of the matched-BV slab and the title slab: a Retailer whose Genos matches Slab-3-level volume is still paid Slab 1 only, and the weaker-side BV above 15,000 is consumed, not banked. Between 600 and 2,999 personal BV, group BV accumulates as carry-forward but **no slab pays at all** — Slab 1 itself requires the Retailer title.

## Carry-forward
- **Power side** (stronger leg): carries forward capped at 4,50,000 BV
- **Slab-1 weaker side**: accumulates indefinitely toward the 15K first match

## Cancelled / refunded orders — group BV reversal
When an order is cancelled (pre-shipment) or its refund is approved (cooling-off or buyback), its group BV is automatically reversed from **exactly the upline distributors it was originally credited to** (a per-ancestor snapshot taken at credit time), on the **same side** — all the way up to the company root. Per KP's confirmed rules (Q8, 27-06-2026):
- **No clawback**: GSB already credited or paid out is never taken back, and earned titles/ranks stay (sticky).
- If the day the reversal lands is **not yet settled**, the BV is simply subtracted from that day's accumulator (shown in the ledger as a red −BV row).
- Whatever the day can't absorb becomes an **open adjustment (negative-carry)** on that side: the next propagated purchases in the same group pay it down **before** any new group BV is credited. Both ledgers show an amber "adjustment pending" banner while any balance is open, and credit rows note when part of an order's BV was consumed by an adjustment.
- The buyer's **personal BV** is reversed as before (net lifetime BV drops; the repurchase obligation no longer counts that order).

## Weekly payout (Group A: GSB + Mentorship)
Runs every Tuesday. Minimum payout ₹100. Deductions applied here (not at cut-off): repurchase (10% of prior month GSB + MB + GBB + Fortune + Rank, max ₹10,000 — a monthly figure collected once, spread across the month's weekly batches until fully recovered), then admin charge (3%, capped ₹25,000/cycle), then TDS (5% of the payable).

Distributors whose **KYC is not yet verified** (account not `active`) get a `kyc_pending` line ("Awaiting KYC" in the wallet): they still earn and see all income, but nothing is debited, swept, or transferred to the bank until their KYC is approved — the first batch after approval pays it out. (Same rule in the monthly batch.)

Distributors with **no bank account on file** get a `no_bank_account` line: nothing is debited or swept, the balance stays in the wallet, and the first batch after they add bank details pays it out. (Same rule in the monthly batch.)

## Monthly payout (Groups B/C/D: GBB, Rank, Fortune, Awards, ADC)
Runs monthly. A per-group admin charge (3%, each group capped ₹25,000/cycle) and TDS (5%) are applied. Repurchase is deducted only in the weekly batch.

## ₹50 lakh combined monthly income cap
The five cash bonuses (GSB, Mentorship, GBB, Rank, Fortune) share one ₹50,00,000/month gross ceiling, enforced across the month's weekly and monthly batches together. Income above the cap is forfeited at payout with an explicit `income_cap_forfeit` wallet debit (no phantom balance, no carry to next month). When the monthly batch has to trim, Rank is forfeited first, then GBB, then Fortune. Awards (non-cash) and ADC (own ₹1L cap) are outside this ceiling.

All of the above rates and caps are admin-editable under **Settings → Compensation plan — rates, caps & periods**.

## Manual controls
Use Manual Controls (always audit-logged) for: failed cut-offs (Retry is safe/idempotent), BV reversals after cut-off (Recalculate CF), incorrect credits (Reverse), and frozen accounts.
