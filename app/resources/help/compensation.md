# Compensation Module — Admin Reference

## What it does
The Compensation section tracks GSB (Genos Sales Bonus), Mentorship Bonus, wallet balances, and weekly payouts for all distributors.

## Daily cut-off
Runs automatically every day at 23:59 IST. For each active distributor:
- Reads their Left and Right Genos group BV accumulated during the day
- Adds any carry-forward from previous days
- Matches against GSB slabs (constrained by their personal purchase title)
- Deducts admin charge (3%, max ₹30,000) and TDS (5% of net-of-admin)
- Credits the wallet with the net GSB amount

## Slab table
| Slab | Matched BV (each side) | Gross GSB | Title required |
|------|----------------------|-----------|----------------|
| 1 | 15,000 BV | ₹1,000 | Retailer (3K lifetime) |
| 2 | 30,000 BV | ₹3,000 | Dealer (5K lifetime) |
| 3 | 90,000 BV | ₹6,000 | Wholesaler (15K lifetime) |
| 4 | 2,70,000 BV | ₹12,000 | Distributor (50K lifetime) |
| 5 | 8,00,000 BV | ₹24,000 | Regional Distributor (1L) |
| 6 | 24,00,000 BV | ₹40,000 | National Distributor (2L) |
| 7 | 72,00,000 BV | ₹60,000 | Global Distributor (3L) |

## Carry-forward
- **Power side** (stronger leg): carries forward capped at 4,50,000 BV
- **Slab-1 weaker side**: accumulates indefinitely toward the 15K first match

## Weekly payout
Runs every Tuesday. Minimum ₹500. Repurchase deduction: 10% of prior month GSB + MB (max ₹10,000).

## Manual controls
Use Manual Controls (always audit-logged) for: failed cut-offs (Retry is safe/idempotent), BV reversals after cut-off (Recalculate CF), incorrect credits (Reverse), and frozen accounts.
