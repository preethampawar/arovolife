# Fortune Bonus — client's consolidated notes (2026-09-03)

> **Internal — not for distributor circulation.** The rupee figures below are engineering worked examples, never marketing or training material (R-37: no income projections, never framed as luck or chance).

Source of truth for the Fortune Bonus (FB) cascade from 2026-09-03. Supersedes the
2026-08-09 cascade (levels 7–8 shared residual value, level 9 flat ₹30) and the earlier
handwritten September sheets, which the product owner withdrew the same day.

## Matrix

3-wide, levels 0–9 (U = level 0). Level L holds 3^L positions: 1, 3, 9, 27, 81, 243, 729,
2,187, 6,561, 19,683 — 29,524 in total. Positions fill first-come-first-served by first GSB
credit date in the month; the matrix resets every calendar month.

## FB points (fixed, per distributor below, by relative level)

| Relative level below | 1 | 2 | 3 | 4 | 5 | 6 | 7 | 8 | 9 |
|---|---|---|---|---|---|---|---|---|---|
| FB points | 9 | 8 | 7 | 6 | 5 | 4 | 3 | 2 | 1 |

## Per-distributor cap per matrix level (developer-editable on Plan Settings, admin-visible)

| Level | 0 | 1 | 2 | 3 | 4 | 5 | 6 | 7 | 8 | 9 |
|---|---|---|---|---|---|---|---|---|---|---|
| Cap (₹, includes the ₹30) | 30,000 | 30,000 | 30,000 | 30,000 | 20,000 | 10,000 | 5,000 | 2,500 | 1,500 | 30 |

## Calculation

1. Pool = 5% of the month's company BV.
2. Guaranteed income ₹30 × qualifiers is reserved first; every qualifier receives it.
3. Level by level, 0 → 9:
   - point value = ⌊ remaining pool ÷ FB points not yet paid (this level and all below) ⌋
   - income per distributor = ₹30 + min(points × value, cap − ₹30)
   - remaining pool −= the amounts paid above the ₹30; remaining points −= the level's points
4. A distributor with nobody below has 0 points and receives the ₹30 only. Whatever is not
   paid stays with the company as leftover — it is not redistributed.
5. If the pool cannot cover ₹30 × qualifiers, everyone gets ⌊ pool ÷ qualifiers ⌋ (2026-08-09).

## September example (from the notes)

Turnover BV ₹5,32,00,000 → pool ₹26,60,000; 121 qualifiers (levels 0–4 full); ₹3,630 reserved
→ ₹26,56,370. Points: L0 774, L1 288 × 3, L2 99 × 9, L3 27 × 27, L4 0 — total 3,258.
Values ₹815 → ₹1,057 → ₹1,565 → ₹3,109; levels 0–3 all capped at ₹30,000; level 4 ₹30 each.
Paid ₹12,02,430; leftover ₹14,57,570. Locked by `FortuneDistributionCalculatorTest`.

## Decisions taken with the product owner (2026-09-03)

| Question | Decision |
|---|---|
| ₹30 credited separately? | One wallet line, one Income column, split shown in a note |
| Income below the cap | ₹30 + points × value (e.g. 99 × ₹20 + ₹30 = ₹2,010) |
| ₹36cr example shift (L8 ₹624, leftover ₹24,576) | Intended |
| Where caps live | Plan Settings — admin visible, developer editable |

## Implemented pending the client's confirmation

**"Repurchase Wallet zero" (built 2026-09-03).** Read as: the distributor's repurchase-wallet
balance (10% payout deduction credits minus checkout debits) was ₹0 on the last day of the
month being enrolled. Applies to the non-ranked and rank 1–5 tiers; month-1 joiners are exempt
(rule 1 has no wallet condition); a distributor who never received a deduction passes. Entries
after month end are ignored even though enrolment runs on the 9th. Developer toggle
`comp.fortune.require_repurchase_wallet_zero` (default ON). If the client instead means "only
the repurchase BV matters", switch it OFF; if they mean "the BV must be bought *with* the
wallet", that is a further change.

## Still open with the client

FCFS vs Genos/sponsor-derived placement; rank of the month vs highest rank ever; positions
beyond 29,524 (placed but ₹0?); new-distributor timing; the 9th as run date.
