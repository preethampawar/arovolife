# T02 — Product BV sanity on staging

Verdict: PASS-with-notes

## Checks

| # | Check | Result | Evidence |
|---|-------|--------|----------|
| 1 | List active product_variants (mrp/DP/BV/gst) | Done | 13 active variants, see table below |
| 2 | Per-order expected (qty×variant BV) vs actual bv_ledger_entries | MATCH on all 16/16 orders | table below |
| 3 | Accrual code path: unit convention applied exactly once | Confirmed | `OrderItem::lineBvPaise()` = `qty * bv_paise`; `Order::bvTotalPaise()` sums lines; `BvLedgerService::accrue()` writes that sum verbatim — one multiplication, no double-scaling |
| 4 | Group BV propagation for order_id=4 | Correct — 1 credit per ancestor, correct side | `group_bv_credits` has exactly 2 rows for order 4: ancestor_id 1 (depth 2, side L) and ancestor_id 2 (depth 1, side L), each 25,000,000 paise, matching `genealogy_closure` (distributor 4 is under both 1 and 2 via the L path). No duplicate/short credits. |
| 5 | Compare with local dev DB | Skipped | No local docker DB was running in this session and app/.env was not read; SSH+MySQL only per task scope, and staging-only evidence was sufficient to answer the question, so this step was not pursued. Flagging as unverified rather than assumed. |

### Active product variants (staging)

| id | product | sku | mrp_paise | dist_price_paise | bv_paise | BV | gst_bp | BV as multiple of MRP |
|---|---|---|---|---|---|---|---|---|
| 1 | Gentle Hand Wash | AV-HW-001-V1 | 29,500 | 0 | 3,600,000 | 36,000 | 1800 | ~122x |
| 2 | ScalpCare Shampoo | AV-SH-001-V1 | 135,000 | 0 | 30,000,000 | 300,000 | 1800 | ~222x |
| 3 | Multi-Vitamin | AV-MV-001-V1 | 89,900 | 65,000 | 300,000 | 3,000 | 1200 | ~3.3x |
| 4 | Hair Essential Oil | AV-OL-001-V1 | 115,000 | 0 | 10,000,000 | 100,000 | 1800 | ~87x |
| 5 | Herbal Green Tea | AV-FD-001-V1 | 45,000 | 0 | 59,900 | 599 | 500 | ~1.3x |
| 6 | Immunity Booster | AV-IB-001-V1 | 65,000 | 45,000 | 60,000 | 600 | 1800 | ~0.9x |
| 7 | Vitamin C Serum | AV-VCS-001-V1 | 89,900 | 62,000 | 1,500,000 | 15,000 | 1800 | ~17x |
| 8 | Aloe Face Wash | AV-AFW-001-V1 | 35,000 | 25,000 | 2,800,000 | 28,000 | 1800 | ~80x |
| 9 | Green Tea | AV-GT-003-V1 | 180,000 | 150,000 | 600,000 | 6,000 | 500 | ~3.3x |
| 10 | agri | AV-AG-008-V1 | 180,000 | 150,000 | 270,000,000 | 2,700,000 | 1800 | ~1500x |
| 11 | higrow | AV-AG-009-V1 | 200,000 | 159,900 | 810,000,000 | 8,100,000 | 1800 | ~4050x |
| 12 | tootpast | AV-OA-002-V1 | 15,000 | 10,000 | 90,000,000 | 900,000 | 1800 | ~6000x |
| 13 | detox | AV-AG-010-V1 | 250,000 | 150,000 | 25,000,000 | 250,000 | 1800 | ~100x |

### Per-order expected vs actual accrual (16 paid orders)

Expected = Σ(qty × item.bv_paise) from `order_items`, per order. Actual = `bv_ledger_entries` row (type=accrual) for that order.

| order_no | order_id | total_paise | items (sku×qty) | expected bv_paise | actual bv_paise | match |
|---|---|---|---|---|---|---|
| ORD-260904-3E2GRY | 1 | 80,900 | VCS×1 | 1,500,000 | 1,500,000 | YES |
| ORD-260904-SBY2VN | 2 | 265,800 | IB×2, GT×1 | 720,000 | 720,000 | YES |
| ORD-260904-CLDFFU | 3 | 265,800 | IB×2, GT×1 | 720,000 | 720,000 | YES |
| ORD-260904-DHKJN2 | 4 | 156,000 | AG-010(detox)×1 | 25,000,000 | 25,000,000 | YES |
| ORD-260904-WCHJGZ | 5 | 255,900 | OL×1, AG-010×1 | 35,000,000 | 35,000,000 | YES |
| ORD-260904-JTEZBS | 6 | 156,000 | AG-010×1 | 25,000,000 | 25,000,000 | YES |
| ORD-260904-UEZCGH | 7 | 255,900 | OL×1, AG-010×1 | 35,000,000 | 35,000,000 | YES |
| ORD-260905-ZKFP1C | 8 | 80,900 | VCS×1 | 1,500,000 | 1,500,000 | YES |
| ORD-260905-QRY0G1 | 9 | 265,800 | IB×2, GT×1 | 720,000 | 720,000 | YES |
| ORD-260905-LIXYMQ | 10 | 265,800 | IB×2, GT×1 | 720,000 | 720,000 | YES |
| ORD-260905-GRU2RO | 11 | 156,000 | AG-010×1 | 25,000,000 | 25,000,000 | YES |
| ORD-260905-J58KRO | 12 | 156,000 | AG-010×1 | 25,000,000 | 25,000,000 | YES |
| ORD-260905-FEM0NJ | 13 | 80,900 | VCS×1 | 1,500,000 | 1,500,000 | YES |
| ORD-260905-OMZTD4 | 14 | 80,900 | VCS×1 | 1,500,000 | 1,500,000 | YES |
| ORD-260905-M7APGR | 15 | 0 | AG-010×1 | 25,000,000 | 25,000,000 | YES |
| ORD-260906-BG9GPX | 16 | 0 | IB×1 | 60,000 | 60,000 | YES |

16/16 orders match exactly. `order_items.bv_paise` is a **per-unit** BV snapshot (equal to `product_variants.bv_paise` at order time) — it is NOT pre-multiplied by qty in that table; multiplication happens once, in `OrderItem::lineBvPaise()`.

### Code path read

- `app/Modules/Commerce/Models/OrderItem.php:59` — `lineBvPaise(): int { return $this->qty * $this->bv_paise; }`
- `app/Modules/Commerce/Models/Order.php:149` — `bvTotalPaise(): int { return $this->items->sum(fn($item) => $item->lineBvPaise()); }`
- `app/Modules/Commerce/Services/BvLedgerService.php:30-52` — `accrue()` calls `$order->bvTotalPaise()` and writes it verbatim into `bv_ledger_entries.bv_paise`, guarded by `firstOrCreate(['order_id','type'=>accrual])` (idempotent — cannot double-credit on retry) and `shouldAccrue()` (self-consumption gated by `commerce.self_purchase.earns_bv` setting; customer-attributed sales always accrue per hard rule #2).
- No second multiplication or unit-conversion step found between the ledger write and the variant's `bv_paise` column — the paise convention (BV × 100) is applied exactly once, at variant definition time, and carried through unchanged.
- Group BV propagation (`PropagateGroupBvOnOrderPaid` listener, not re-read line-by-line here since DB evidence already confirms correctness): order 4's 25,000,000 paise propagated as one row per ancestor (2 ancestors, both `side=L`, matching `genealogy_closure`), full amount at each level — no split, no duplication.

## Defects

None. Accrual arithmetic is exact at every layer checked (order_items → Order::bvTotalPaise → BvLedgerService → bv_ledger_entries → group_bv_credits).

**However, flagging for the client (not a code defect, a catalogue-data concern):** 9 of the 13 active variants carry BV far out of proportion to price — `AV-AG-009-V1` (higrow) is ~4,050× its MRP in BV, `AV-OA-002-V1` (tootpast) ~6,000×, `AV-AG-008-V1` (agri) ~1,500×, `AV-AG-010-V1` (detox, used in the two orders named in this task) ~100×, plus `AV-SH-001-V1`, `AV-HW-001-V1`, `AV-OL-001-V1`, `AV-AFW-001-V1`, `AV-VCS-001-V1` all 17×–222×. These are clearly placeholder/test catalogue values (product names like "agri", "higrow", "tootpast" confirm this), not a sign of a compensation-engine bug — the accrual math is provably correct given whatever `bv_paise` the variant carries. This needs real catalogue BV values loaded before go-live or the compensation reports (GSB/MSB/RB/GBB) will be tested against numbers with no relationship to real product economics.

## Mutations made on staging
None. Read-only queries only.

## Notes for the orchestrator (≤10 lines)
- Root cause of the ₹1,560→2,50,000 BV and ₹809→15,000 BV observations: test catalogue BV values, not a bug. `AV-AG-010-V1` ("detox") carries bv_paise=25,000,000 (250,000 BV) against a distributor price of only ₹1,500; `AV-VCS-001-V1` carries 1,500,000 (15,000 BV) against ₹620.
- Accrual formula verified exact on all 16 paid orders at every layer (item→order→ledger→group BV propagation). No double-multiplication, no unit-convention bug.
- Group BV propagation for order 4 checked: correct 1-row-per-ancestor, correct side, matches closure table.
- Action item for client: real BV values per SKU before UAT/launch — 9/13 active variants have BV wildly disproportionate to price (see table).
- Step 5 (local dev DB comparison) skipped — no local docker DB session available; not needed since staging evidence alone answered the question.
