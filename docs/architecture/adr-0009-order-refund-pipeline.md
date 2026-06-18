# ADR-0009 — Phase-2 order refund pipeline (cooling-off + buyback → double-entry ledger)

- **Status:** Proposed (revised after compliance-officer review — matrix made total + termination row, invoice/GST flag restored, cooling-off one-click carve-out, distinct pre-settlement state)
- **Date:** 2026-06-18
- **Deciders:** Laravel Architect, Finance, Compliance Officer, Product Owner
- **Supersedes:** —
- **Builds on:** ADR-0004 (double-entry ledger), ADR-0005 (per-order cooling-off clock), ADR-0006 (BV ledger)

## Context

Phase 2 already has the foundations but no working **refund pipeline**:

- The per-order cooling-off clock is opened on delivery — `OrderStateMachine::markDelivered()` sets `delivered_at` and opens `order_cooling_off(opened_at, ends_at = +30d, status='open')` (ADR-0005). ✅
- The append-only double-entry ledger is built — `Ledger\Services\LedgerPoster::post()` enforces `sum(debits)=sum(credits)`, chart of accounts seeded by `LedgerAccountSeeder` (ADR-0004). ✅
- BV reversal on refund exists — `Commerce\Services\BvLedgerService::reverse(Order)` (ADR-0006). ✅
- The `Returns` module has models (`ReturnRequest`, `ReturnInspection`, `BuybackDecision`) but **no services**.
- `orders.status` already includes `refund_requested`, `refund_inspection`, `refunded`.

What's missing is the **orchestration**: a customer/admin opening a return, the **T&C §8 buyback matrix** computing the refund amount, an admin inspection/decision, and **executing the refund** (order status + ledger reversal + BV reversal + refund event).

The Product Owner has asked for the **full refund pipeline now** (money actually moves through the ledger), but the **real payment-gateway refund is deferred to Phase 3** — so refunds post to the **ledger** now via the **stub gateway** (records the refund; no real settlement), and Phase 3 wires Razorpay capture/refund.

## Decision

Build a single refund pipeline in the **Returns** module that **orchestrates** the existing pieces — it does not re-implement clocks, ledgers, or BV.

### Two entry points, one execution path

| Entry | Trigger | Refund basis |
|---|---|---|
| **Cooling-off cancellation** | Customer cancels within the 30-day `order_cooling_off` window, goods **saleable** | **Full** Direct Seller Price (T&C §8 cooling-off row) |
| **Buyback / return** | Damage or dissatisfaction return | **Buyback matrix** (saleable → DS Price; non-saleable → DS Price **less GST**) |

Both converge on one `RefundOrder` service so the money/BV/state effects are identical and tested once.

### Buyback matrix (single source — `Returns\Services\BuybackMatrix`)

Pure, **total** function `(reason, saleable, daysSinceDelivered) → { eligible, refund_paise, refund_gst: bool, invoice: bool, window_days }`, encoding T&C §8 exactly. Every `(reason, saleable)` pair returns an explicit row — where §8 grants no refund, `eligible:false` (never an undefined fall-through). `invoice`/`refund_gst` drives whether a **GST credit note** (true) or a **non-tax buyback voucher** (false) is issued.

| Reason | Saleable | Window | Eligible | Refund | GST refunded / invoice |
|---|---|---|---|---|---|
| cooling_off | yes | 30d | ✅ | DS Price — **full (incl. GST)** | Yes — credit note |
| cooling_off | no | 30d | ❌ | — (cooling-off requires saleable) | — |
| damage | yes | 10d | ✅ | DS Price | Yes — credit note |
| damage | no | 10d | ✅ | DS Price **less GST** | No |
| dissatisfaction | yes | 30d | ✅ | DS Price | Yes — credit note |
| dissatisfaction | no | 30d | ✅ | DS Price **less GST** | No |
| general_buyback | yes | — | ✅ | DS Price **less GST** | No |
| general_buyback | no | — | ❌ | — | — |
| termination_buyback | yes | — | ✅ | DS Price **less GST** | No |
| termination_buyback | no | — | ❌ | — | — |

`reason` enum: `cooling_off`, `damage`, `dissatisfaction`, `general_buyback`, `termination_buyback`. A `BuybackMatrix` test asserts **every** row above, including the `eligible:false` cases.

### State flow

```
Cooling-off cancellation (saleable, within 30d) — NON-discretionary, one-click:
delivered → (customer cancels)   refund_requested → (auto-execute) refund_approved

Buyback / return (damage / dissatisfaction / general / termination):
delivered → (open return)        refund_requested
          → (admin inspection)   refund_inspection
          → (approve + execute)  refund_approved
          → (reject)             back to delivered, return closed, NO refund
```

**Cooling-off is one-click (hard rule #5).** A saleable cooling-off cancellation
within the window is **auto-eligible** and executes without a discretionary
admin gate — `RefundOrder` runs straight away. An inspection may *verify*
saleability after the fact, but it can **never be a precondition** that blocks
the customer's cancellation right. Only the buyback/return reasons go through the
admin inspect → decide gate.

**Two refund states, deliberately distinct (hard rule #5 transparency):**
- `refund_approved` — the refund is **initiated**: ledger reversed, BV reversed,
  credit note issued. Customer copy: *"Refund initiated — credited to your
  original payment method within N business days."* This is the Phase-2 terminal
  state (the stub gateway records but does not settle).
- `refunded` — **settled**: the real gateway has returned the money (Phase 3).
  Never shown to the customer until money has actually moved.

Transitions go through `OrderStateMachine` (guards + audit). `order_cooling_off.status`
is set `cancelled` only when a refund actually proceeds. **A rejected return must
re-assert the original `order_cooling_off.ends_at`** so it does not consume any
remaining cooling-off window — the customer keeps the days they had left.

(Requires adding `refund_approved` to the `orders.status` enum via migration.)

### Money (this phase: ledger only)

`RefundOrder` runs in one DB transaction and:
1. Posts a **double-entry reversal** via `LedgerPoster::post()` — reverse the cash/refund-payable, GST output (**only when `refund_gst === true`**), and revenue/COGS lines for the refunded amount. Balanced by construction.
2. Calls `BvLedgerService::reverse($order)` — reverses the order's BV accrual.
3. Issues the return document **bound to the matrix flag**: a **GST credit note** (Tax module) against the original invoice **only when `invoice/refund_gst === true`**; for the less-GST rows, a **non-tax buyback voucher** (no credit note) — issuing a GST credit note on a no-invoice less-GST buyback would be a GST-correctness defect.
4. Moves the order to **`refund_approved`** (refund *initiated*, not settled) and emits `order.refund_approved` carrying an **idempotency key** (`refund:{order_id}`). The Phase-2 **stub gateway** records the refund intent but does **not** settle; **Phase 3** wires the real Razorpay refund, which on settlement moves the order to **`refunded`**. No double-refund: `orders` gets at most one refund (unique guard on the refund ledger tx).

### Module ownership (no circular deps)

`Returns` is downstream: it depends on `Ledger` (post), `Commerce` (order + state machine + BV reverse), `Tax` (credit note). Nothing depends back on `Returns`. The customer return UI lives in Commerce storefront; the admin inspection/decision UI in Admin.

## Options considered

- **A. Put refund logic in Commerce.** Rejected — Commerce would then depend on Ledger/Tax/Returns, bloating it; the buyback/inspection lifecycle is clearly Returns' concern.
- **B. Defer the whole money side to Phase 3 (request+status only).** Rejected by the PO — they want the ledger to actually move now (full pipeline), with only the gateway settlement deferred.
- **C. Mutable refund flags on orders instead of ledger entries.** Rejected — violates ADR-0004 (append-only double-entry is the single source of truth for money).

## Consequences

- **Positive:** one tested execution path for both refund types; money is auditable from day one via the existing ledger; BV/commission attribution stays correct (BV reversed); Phase 3 only has to wire the gateway, not invent the pipeline.
- **Negative:** pulls real ledger postings into Phase 2 (more financial surface to test and review now). Mitigated by ADR-0004 already being built + balanced-by-construction posting.
- **Neutral:** stub-gateway refunds are recorded but not settled until Phase 3 — admins must understand "refunded in ledger" ≠ "money back in the customer's bank yet" until Phase 3. Surface this in the admin help docs.

## Phase-4 forward dependency (commission clawback)

In Phase 2 no commissions exist, so reversing BV (`BvLedgerService::reverse`) is
sufficient. **From Phase 4**, once commissions accrue on BV, a late per-order
refund must additionally trigger a **commission clawback** against the
distributor's wallet (the `wallet_debt` mechanism anticipated in ADR-0005) —
**never** mutating their tree position. The refund event must be designed so the
Phase-4 compensation engine can subscribe and clawback. Noted here so it isn't
lost.

## Compliance notes

- Cooling-off refund is the **full** DS Price for saleable goods within 30 days (hard rule #5). The matrix mirrors T&C §8 exactly; the GST-refund flag follows the saleable/non-saleable split.
- No income/earnings implication anywhere in the refund UI (hard rule #3).
- Every refund is audit-logged with actor + reason; admin refund actions are gated by `can:finance.record` (R-17 admin-finance).
- To be run past the `compliance-officer` subagent before and after implementation.

## Build steps (post-approval)

1. Migration: add `refund_approved` to the `orders.status` enum; `return_requests.reason` enum incl. `termination_buyback`.
2. `Returns\Services\BuybackMatrix` (pure, **total**) + tests asserting **every** row incl. the `eligible:false` cases and the `invoice`/`refund_gst` flag.
3. `Returns\Services\OpenReturn` (customer/admin) → `ReturnRequest`, order → `refund_requested`, within-window + eligibility guards. **Cooling-off saleable → auto-routes straight to `RefundOrder` (one-click, no admin gate, hard rule #5).**
4. `Returns\Services\InspectReturn` (admin, buyback reasons only) → `ReturnInspection` + `BuybackDecision`, order → `refund_inspection`. **Reject → re-assert `order_cooling_off.ends_at`** (don't consume remaining window).
5. `Returns\Services\RefundOrder` → ledger reversal + BV reverse + **credit note only when `refund_gst`** (else buyback voucher) + order → **`refund_approved`** + `order.refund_approved` event; idempotent (`refund:{order_id}`).
6. Storefront "Return this order" UI (within window) with accurate **"refund initiated"** copy + admin inspection/decision UI (gated `can:finance.record`).
7. Per-order D-20/D-7/D-1 return-window reminders (reuse the Compliance SMS sender, Phase-2 template — ADR-0005).
8. Update help docs (`cooling-off.md`, `compliance-dos-and-donts.md`) + risk register; full test pass; **re-run `compliance-officer` before merge**.
