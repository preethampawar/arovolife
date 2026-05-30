---
name: arovolife-compensation-plan
description: Reference for the Arovolife compensation plan — slabs, ranks, Auto Pool, mentorship, caps and repurchase rules. Mostly informational during Phase 1 (no commissions yet); becomes operational from Phase 4 onwards. Use whenever a feature needs to understand how a rupee flows.
---

# Arovolife Compensation Plan — reference

**Phase 1 note:** no compensation is calculated in Phase 1. This skill
exists so that the Phase 1 data model, events and audit trails are
*forward-compatible* with the engines that arrive in Phases 4-6.

## Revenue sharing envelope

| Component | Share of revenue | Cadence | Phase introduced |
|---|---|---|---|
| Retail margin | 10–30% | Per sale | Phase 2 |
| Group Sales Bonus (GSB) | 40% | Daily cut-off 23:59; weekly Tuesday payout | Phase 4 |
| Auto Pool | participation-based | Monthly | Phase 6 |
| Mentorship Bonus | 5% (slab 10 % → 1 %) | With GSB | Phase 4 |
| Rank Bonus | ~21% | Monthly 8th | Phase 5 |
| Lifetime Rewards | 32% (non-cash) | On rank achievement | Phase 5 |
| Franchise Bonus | 3%, cap ₹1 L/month | Monthly 8th | Phase 7 |

## Caps and deductions

- Total distributor-side commission = 35% of sales revenue, hard-capped at ₹50 lakh/month.
- Admin charge: 3% or ₹30,000, whichever is lower; applies to GSB, MB, RB. Exempt: awards & rewards and Franchise Bonus.
- TDS: 5% (per Income Tax rules; verify current rate on payout).
- Repurchase wallet deduction: 10% of last-month GSB/MB/RB, cap ₹10,000.
- Minimum payout: ₹500.
- Mandatory monthly repurchase: 600 BV on or before the 15th; wallet must
  also be cleared to zero before the 15th.

## Partner personal purchase ladder (A→ND)

| Level | BV | Title |
|---|---|---|
| 1 | 3,000 | Agent |
| 2 | 5,000 | Retailer |
| 3 | 15,000 | Dealer |
| 4 | 50,000 | Wholesaler |
| 5 | 1,00,000 | Distributor |
| 6 | 2,00,000 | Regional Distributor |
| 7 | 3,00,000 | National Distributor |

## GSB slab table (matched BV on weaker leg)

| Left / Right matched | Personal purchase title | Score | Incentive |
|---|---|---|---|
| 15,000 / 15,000 | Agent (3,000 BV) | 4 | ₹1,000 |
| 30,000 / 30,000 | Retailer (5,000 BV) | 12 | ₹3,000 |
| 90,000 / 90,000 | Dealer (15,000 BV) | 24 | ₹6,000 |
| 2,70,000 / 2,70,000 | Wholesaler (50,000 BV) | 48 | ₹12,000 |
| 8,00,000 / 8,00,000 | Distributor (1,00,000 BV) | 96 | ₹24,000 |
| 24,00,000 / 24,00,000 | Regional (2,00,000 BV) | 160 | ₹40,000 |
| 72,00,000 / 72,00,000 | National (3,00,000 BV) | 240 | ₹60,000 |

## Mentorship Bonus slab (on top of GSB)

10%, 9%, 8%, 7%, 6%, 5%, 4%, 3%, 2%, then 1% lifetime — stepped per
30,000 cumulative GSB received.

## Rank ladder (Phase 5)

| Rank | Name | Criteria (high level) | Pool | Months |
|---|---|---|---|---|
| 1 | Silver | 3 L / 3 L Group BV (with 2.5 L substitution rule) | 7% | 1 + 2 |
| 2 | Pearl | 5 L / 5 L (with 4 L substitution rule) | 4% | 1 + 2 |
| 3 | Emerald | Two rank-2 leaders on each side | 3% | 1 + 1 |
| 4 | Gold | Two rank-3 leaders on each side | 2.3% | 1 + 1 |
| 5 | Diamond | Two rank-4 leaders on each side | 1.7% | 1 + 1 |
| 6 | Blue Diamond | Two rank-5 leaders on each side | 1.2% | 1 |
| 7 | Royal Diamond | Two rank-6 leaders on each side | 0.9% | 1 |
| 8 | Crown Diamond | Two rank-7 leaders on each side | 0.6% | 1 |
| 9 | Elite Diamond | Two rank-8 leaders on each side | 0.3% | 1 |

Ranks 3+ require "Prove Your Position" — re-achieve the pattern twice
(or thrice for ranks 6-9) in a calendar month before the promotion is
confirmed.

## Auto Pool (₹51 · 3×9 matrix)

| Level | Members |
|---|---|
| 0 (You) | 1 |
| 1 | 3 |
| 2 | 9 |
| 3 | 27 |
| 4 | 81 |
| 5 | 243 |
| 6 | 729 |
| 7 | 2,187 |
| 8 | 6,561 |
| 9 | 19,683 |

Total members = 29,523; total turnover at ₹51 = ₹15,05,673.

Eligibility gates (summary):

- New joiner: self-purchase 5,000 BV + 15,000 BV fresh on each leg in the same month.
- Non-rank holder: 600 BV personal + wallet zero + 15,000 BV fresh on each leg in the current month.
- Rank 1: 15,000 BV position + 1,000 BV repurchase + wallet zero, repeat any rank 2× within the month.
- Rank 2: 1,000 BV repurchase + wallet zero, repeat any rank 3× within the month.
- Rank 3: 1,000 BV repurchase + wallet zero, repeat any rank 4× within the month.
- Rank 4: 1,000 BV repurchase + wallet zero, repeat any rank 5× within the month.
- Rank 5: 1,000 BV repurchase + wallet zero, repeat any rank 6× within the month.
- Pool benefit is capped at rank 5.

## Compliance notes (repeat of the mandatory ones)

- Every pool entry, every rank promotion, every bonus row must be tied
  to a `product_sale_id`. Recruitment alone never earns.
- Never display a "potential earnings" chart anywhere.
- Non-cash rewards (cars, insurance, trips) are fulfilled via vendor
  workflows; perquisite tax treatment verified before release.
