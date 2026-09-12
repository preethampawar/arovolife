# ADR-0012 — Stock ledger as append-only movements with projections

- **Status:** Accepted
- **Date:** 2026-09-12
- **Deciders:** Laravel Architect, Product Manager, Operations
- **Builds on:** ADR-0004 (double-entry ledger)
- **Supersedes:** —

## Context

Stock visibility and fulfilment accuracy are operational requirements (Phase — Order Management, 2026-09). The current system has no movement history, no batch tracking, and no way to audit stock allocations after an order ships. Warehouse operations, returns processing, and fraud detection all require a traceable record.

The design must serve three use cases:

1. **Accurate picking and allocation.** When an order reaches the warehouse, the system must know exactly which batches can fulfill it (FEFO logic: earliest expiry first, never expired).
2. **Batch chain-of-custody.** Every unit must be traceable from receiving (GRN) through allocation, sale, and return.
3. **Reconciliation.** Stock counts (projections) can drift from reality; a command must detect and surface drift without requiring a full audit.

The guiding principle is the same as ADR-0004's wallet ledger: **append-only transactions with projections**.

## Options considered

### A. Mutable `inventory_levels` with no audit trail

Today's approach: each operation updates `inventory_levels.on_hand` directly. No history. A missing debit hides forever.

- **Pros:** Minimal schema.
- **Cons:** Audit trail lost; reconciliation impossible; no batch history; allocation decisions are not recorded.

### B. Inventory journal table + mutable level projections (same as today but with history)

Keep `inventory_levels.on_hand` mutable, add a journal table. The level can drift from the journal; they are not guaranteed consistent.

- **Pros:** Familiar pattern.
- **Cons:** Drift grows undetected. Batch allocation is not recorded in the journal, only at pick time (late). Reversal of a sale requires inverting the logic of a pick, which may be ambiguous.

### C. Append-only `stock_movements` ledger + batches + warehouse-scoped warehouse_code key

Every movement (purchase, sale, return, transfer, adjustment) is immutable. Level and batch on_hand are recalculated from movements in the same transaction. The movement ledger is the source of truth.

- **Pros:** No lost history; audit trail is complete; reverts are explicit (`purchase_reversal`, `sale_reversal`) not computed; FEFO allocation is locked into the movement record. Drift is detectable. Batch is a first-class entity.
- **Cons:** Slightly higher write complexity (two inserts + projection recalc per movement). The projection must be rebuilt reliably.

## Decision

Adopt **Option C** — `stock_movements` (append-only ledger) + `inventory_levels` (projection of on_hand) + `stock_batches` (batch projection).

### Schema essentials

**Movement table (append-only):**

```sql
stock_movements (
    id, product_variant_id FK, warehouse_code VARCHAR(32),
    stock_batch_id FK nullable (NULL for batch-less movements),
    type ENUM(purchase_in, purchase_reversal, sale_out, sale_reversal,
              transfer_out, transfer_in, return_in, adjustment_in, 
              adjustment_out, write_off, opening),
    qty INT (signed: + is in, − is out, never 0),
    unit_cost_paise BIGINT,
    reference_type VARCHAR(32), reference_id BIGINT (e.g. 'order_item' / order_item.id),
    reason VARCHAR(255) nullable,
    actor_user_id FK nullable,
    occurred_at DATETIME(3), created_at DATETIME(3)
)

CREATE INDEX idx_sm_variant_wh_time (product_variant_id, warehouse_code, occurred_at);
CREATE INDEX idx_sm_reference (reference_type, reference_id);
CREATE INDEX idx_sm_type_time (type, occurred_at);

-- No UPDATE or DELETE. Every correction is a reversing movement.
```

**Level projection table (mutable, recalculated per transaction):**

```sql
inventory_levels (
    product_variant_id FK, warehouse_code VARCHAR(32) [FK warehouses(code)],
    on_hand INT (= SUM(qty) for this variant/warehouse from stock_movements),
    reserved INT (existing; sum of order_items.qty placed but not shipped),
    reorder_level INT (new),
    low_stock_alerted_at DATETIME(3) nullable,
    PRIMARY KEY(product_variant_id, warehouse_code)
)
```

**Batch projection table (mutable, recalculated per transaction):**

```sql
stock_batches (
    id, product_variant_id FK, warehouse_code VARCHAR(32),
    batch_no VARCHAR(64),
    mfg_date DATE nullable, expiry_date DATE nullable,
    unit_cost_paise BIGINT,
    qty_on_hand INT (= SUM(qty) for this batch from stock_movements),
    received_at DATETIME(3) (first receipt into this warehouse, for ageing),
    source_type VARCHAR(32), source_id BIGINT (e.g. 'purchase_invoice_item'),
    expiry_alerted_at DATETIME(3) nullable,
    PRIMARY KEY(product_variant_id, warehouse_code, batch_no)
)

CREATE INDEX idx_stock_batches_wh_expiry (warehouse_code, expiry_date);
```

### Warehouse key strategy

**Decision: `warehouse_code` is a string, not an int FK.**

Rationale: Existing `inventory_levels` rows key on `warehouse_code = 'DEFAULT'` (string, seeded by the first warehouse create). Changing it to a FK would require backfilling every existing row. Instead, add the FK *separately* to `inventory_levels` and key all new tables on the string directly.

- Benefit: zero backfill; codes are human-readable in every report (e.g. `HYD-01`, not `warehouse_id=17`).
- Trade-off: one string column per movement row. Minimal; the index is on the variant/warehouse/time tuple.

### In-transit handling (simplification)

**Decision: No in-transit warehouse. Transfers are recorded as "dispatched" until received.**

When a transfer is dispatched, stock writes `transfer_out` movements and leaves the source warehouse. The destination warehouse has zero units until receipt, even though the units are physically en route. The transfer register shows in-transit qty = dispatched − received for visibility.

- Benefit: no phantom warehouse to explain; transfer receipt is the point of control.
- Trade-off: for a few days, units are "missing" from the system perspective. Mitigated by transfer status and the register.
- Rationale: a transfer-in-transit warehouse adds complexity (receives → transfers out again?) and a third location to reconcile. The destination receiving is the event that matters operationally.

### FEFO allocation at pack, not at place

**Decision: Batch selection happens at pack time, not at order placement.**

When an order is placed, `CheckoutService` reserves units against the variant level (a counter). When the order reaches the warehouse (pack), `OrderFulfilmentService::pack()` selects batches FEFO, allocates them to order_items, and writes `sale_out` movements.

- Benefit: batches are chosen when someone is physically picking, which is when the choice is true. Inventory is not "frozen" between placement and pack.
- Trade-off: if all non-expired batches sell out between placement and pack, packing fails and the order cannot ship. Mitigated by the availability check (gated by InventoryFeature) that runs at checkout.

### Projection consistency

**Invariants (enforced in `StockLedger` and `inventory:verify`):**

1. Every `sale_out` movement must reference an `order_item`.
2. Every `purchase_in` must reference a `purchase_invoice_item`.
3. `on_hand >= 0` and `qty_on_hand >= 0` (a movement that would go negative throws `InsufficientStockException`).
4. Expired batches (expiry_date < today) are never FEFO-allocated, even if on_hand > 0.
5. Every movement must have a non-zero `qty` (prevents silent no-ops).
6. A purchase_reversal can only be issued if the batch still has on_hand >= the reversal qty (nothing sold yet).

## Consequences

### Positive

- **Audit trail.** Every unit is traceable: GRN → batch → order → ship → return / cancel → restock.
- **Batch awareness.** Expiry, cost, source, ageing all flow through.
- **Reconciliation.** `inventory:verify` compares projections to movements; drift surfaces clearly.
- **Reversion clarity.** A cancelled order writes `sale_reversal`, not a mutation. The ledger records both the sale and the reversal.
- **FEFO locking.** The movement record captures which batch left the warehouse for which order; picker errors are auditable.

### Negative

- **Projection maintenance.** Every movement must recalculate both `on_hand` and `qty_on_hand` in the same transaction; a failed recalc halts the write.
- **Consistency burden.** Developers must use `StockLedger::post()` exclusively; direct inserts corrupt the projections.
- **Drift resolution.** If drift is found, determining cause requires audit-log inspection and manual reconciliation (not automatic).

### Neutral

- **In-transit delay.** Units show zero at the destination for the duration of transfer. Operationally acceptable; reports show transfer status explicitly.
- **Batch merging.** GRNs with identical batch_no/expiry/cost into the same warehouse merge into one batch. This is desired (one SKU/batch instance per location) and automatic.

## Alternatives rejected

### Alt 1: Financial postings (ADR-0004 style ledger entries)

Record stock as journal entries: Dr asset.inventory, Cr expense.cogs. Every movement becomes a paired ledger entry.

**Rejected because:**
- Finance postings are deferred (Phase S7) pending accountant guidance.
- Mixing operational stock movements with financial accounting creates a schema dependency that is not yet required.
- The stock movements ledger is the source; finance is a consumer (downstream). Accounts are seeded; movement writes are independent.

### Alt 2: Nested set tree for batch expiry calculation

Store batches in a nested-set hierarchy so expiry grouping queries are faster.

**Rejected because:**
- FEFO allocation is at pack time, once per order. Query performance is acceptable.
- Nested-set maintenance on every batch insert adds complexity for minimal benefit.
- Batches are not hierarchical; they are flat (one batch per variant/warehouse/batch_no).

### Alt 3: Automatic write-off on expiry

When a batch expires, automatically write an adjustment_out movement.

**Rejected because:**
- Expiry detection and write-off are operational decisions. Auto-writing movements hides the decision and its audit trail.
- The batch is still on the shelf until someone picks it up and records the damage. Recording it gone before that happens is inaccurate.
- The alert system (`inventory:alerts`) flags expiring stock; ops then chooses to write off or salvage.

## References

- ADR-0004 — Append-only double-entry ledger
- `docs/plans/2026-09-11-inventory-warehouse-order-management.md` § 3, § 4
- `docs/runbooks/inventory.md` — operational procedures
- `OrderFulfilmentService::pack()` — FEFO allocation at pack time
- `StockLedger` service — single writer for all movements
- `InventorySettings` — reorder level, alert thresholds
