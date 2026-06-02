# ADR-0006 — Personal BV ledger: an append-only projection of confirmed product sales

- **Status:** Accepted
- **Date:** 2026-06-02
- **Deciders:** Laravel Architect, Compliance Officer, Product Owner
- **Supersedes:** —
- **Superseded by:** —

## Context

Distributors need to see, per order and in aggregate, the Business Volume (BV)
their own purchases have generated — and whether that BV is still provisional
(the order is inside its 30-day return window) or has been firmly counted.

Today BV exists only as a per-line snapshot on `order_items.bv_paise`. There is
no record of *accumulated* BV; the `Total Personal BV` ID-card stat is a
`PHASE_LATER_PLACEHOLDER` returning `null`. We need a durable, auditable store
of accumulated personal BV now, without waiting for the Phase 4 Compensation
engine.

This must be done without tripping the hard rules:

- **#2 — commissions/credit only on product sales.** Every BV credit must
  reference a `product_sale` (an `order_id`). No BV may exist without a sale.
- **#3 — no income projection; historical facts, own data only.** BV is a point
  total, never money, never an earnings forecast.
- **Cooling-off is sacred.** BV from an order must not be treated as "earned"
  until that order's 30-day return window (ADR-0005) has closed, and must be
  reversed if the order is refunded.

## Decision

Introduce a dedicated, **append-only personal-BV ledger**, separate from the
monetary double-entry `Ledger` module.

### Why separate from the monetary `Ledger`

The `Ledger` module (ADR-0004) records **rupees** in a double-entry system
(debits == credits, account codes, GST liabilities). BV is **points**, not
money — it has no counter-account, no GST, no payout meaning in Phase 2.
Forcing points into the rupee ledger would corrupt its invariants. They are
different units and different domains, so they get different stores — the same
reasoning as ADR-0005's two cooling-off clocks.

### Scope: personal BV only

This ledger records a distributor's **own-purchase** (self-consumption) BV
only — the basis of the `Total Personal BV` stat. Team/downline BV (binary
matching) is Phase 4 Compensation and is explicitly out of scope here. Accrual
happens only when:

- `order.self_consumption === true` (the buyer is the attributed distributor), **and**
- the `commerce.self_purchase.earns_bv` setting is on.

### Module placement

Lives in the **Commerce** module (`app/app/Modules/Commerce`) as it is a direct
projection of confirmed orders. When the Phase 4 **Compensation** module lands,
it will *read* from this ledger rather than re-deriving BV; extraction into a
Compensation/Wallet context can happen then via a new ADR. Housing it in
Commerce now avoids creating a half-empty module prematurely.

### Schema

```sql
bv_ledger_entries (
    id,
    distributor_id,                 -- FK distributors.id (whose personal BV)
    order_id,                       -- FK orders.id (the product sale; hard rule #2)
    bv_paise BIGINT,                -- signed: + for accrual, - for reversal
    type ENUM('accrual','reversal'),
    effective_at DATETIME(3),       -- when the BV became (un)counted
    created_at, updated_at,
    UNIQUE (order_id, type),        -- idempotency: one accrual + one reversal per order
    INDEX idx_distributor (distributor_id)
)
```

`Total Personal BV` = `SUM(bv_paise)` for the distributor — accruals add,
reversals subtract, netting to zero for a refunded order.

### Lifecycle (drives the per-order status the distributor sees)

```
order delivered → cooling-off opens     → status "Pending (in cooling-off, N days left)"   [NO ledger entry yet]
order.confirmed (cooling-off expired)   → accrue(order): +bv_paise accrual entry            → status "Accumulated"
order refunded                          → reverse(order): -bv_paise reversal entry          → status "Reversed"
```

The ledger therefore holds **counted BV only**. "Pending" BV is never in the
ledger — it is derived from `order_cooling_off.status = 'open'`. This makes
cooling-off structurally impossible to bypass: BV cannot be counted before the
window closes because nothing writes the entry until `expireCoolingOff()` runs.

### Triggers (single source, idempotent)

- `OrderStateMachine::expireCoolingOff()` (→ `STATUS_CONFIRMED`) calls
  `BvLedgerService::accrue($order)`.
- The refund path (→ `STATUS_REFUNDED`) calls `BvLedgerService::reverse($order)`.
- Both are idempotent via the `UNIQUE (order_id, type)` constraint, so a retried
  job or a re-run cron never double-counts.

### Reads (single source)

- `DistributorIdCardStats` reads `Total Personal BV` from
  `BvLedgerService::totalPersonalBvPaise($distributorId)` — replacing the
  `null` placeholder and the tree/ID-card `PHASE_LATER_PLACEHOLDER`s.
- The per-order status string is derived once (cooling-off + ledger presence)
  and reused by the My Orders views (Batch 6).

## Consequences

- **Positive**
  - Accumulated personal BV is durable, auditable and append-only (no mutable
    running total to corrupt).
  - Cooling-off is enforced by construction — no entry exists until the window
    closes; refunds net to zero.
  - Phase 4 Compensation reads a ready-made, sale-linked BV history.
  - Every entry references an `order_id`, satisfying hard rule #2 by schema.

- **Negative**
  - A new table + service + two state-machine hooks.
  - The refund pipeline is only partly built (Phase 3); the `reverse()` hook is
    wired now and unit-tested, but exercises a path that is not yet fully
    reachable from the UI.

- **Neutral**
  - BV is shown only to the owning distributor; nothing here changes the
    public/guest surfaces.

## Enforcement

- A test asserts no accrual entry exists while an order's cooling-off is `open`,
  exactly one appears after `expireCoolingOff()`, and a reversal nets the
  distributor's total to zero after refund.
- A test asserts `accrue()`/`reverse()` are idempotent (second call is a no-op).
- A test asserts no accrual when `self_consumption` is false or
  `commerce.self_purchase.earns_bv` is off.

## References

- Hard rules #2, #3 (CLAUDE.md)
- ADR-0004 (monetary double-entry ledger) — why BV is *not* in it
- ADR-0005 (per-order cooling-off clock) — the trigger for accrual
- DSR 2021 Rule 5(1)(c) (commission only on product sales), 5(1)(d) (no income projection)
