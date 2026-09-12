# Inventory, Warehouse & Order Management Runbook

Stock tracking and fulfilment operations for the inventory module (shipped Phases S1–S5, 2026-09-12).

---

## Opening stock backfill

On first deployment after `php artisan migrate`, existing `inventory_levels.on_hand` values (set by seeders or admin form) have no ledger movements. This command creates the opening record.

```bash
php artisan inventory:backfill-opening
```

**Behaviour:**

- Idempotent: skips any (variant, warehouse) pair that already has movements.
- For each level with `on_hand > 0`, creates a `stock_batches` row (batch_no = `OPENING`, no expiry, unit_cost = variant.cost_paise) and an `opening` type movement.
- Dry run available: `--dry-run` flag shows changes without writing.

**Example:**

```bash
# See what would be written
php artisan inventory:backfill-opening --dry-run

# Run the backfill
php artisan inventory:backfill-opening
```

**When to run:**

- Once, immediately after deploy on staging / production.
- Before any manual stock adjustments on the new warehouses.

---

## Stock reconciliation and verification

`inventory:verify` recomputes the two stock projections from the append-only `stock_movements` table and compares against the recorded totals.

```bash
php artisan inventory:verify
```

**Projections verified:**

1. `inventory_levels.on_hand` = Σ `stock_movements.qty` for (variant, warehouse_code)
2. `stock_batches.qty_on_hand` = Σ `stock_movements.qty` for that batch

**Exit status:**

- `0` — both projections match. Stock is consistent.
- `1` — drift found. Command outputs the mismatched (variant, warehouse, batch) keys and the differences.

**Schedule:**

- Runs weekly: **Monday at 03:00 IST** (`Schedule::command(…)->weeklyOn(1, '03:00')`).
- Can also run manually anytime.

**Example:**

```bash
# Manual verification (e.g. after a stock adjustment or import)
php artisan inventory:verify
```

**If drift is found:**

The command outputs the mismatched rows. The most common causes:

1. **A movement was inserted directly into the DB** (not via StockLedger). Check recent inserts into `stock_movements`.
2. **A projection column was manually edited.** Check `audit_log` for `inventory.*` entries.
3. **A batch was created or updated outside StockLedger.** Check `audit_log` for changes to `stock_batches.qty_on_hand`.

**Recovery:**

If the drift is explainable and small (≤ 5 units), use `inventory:adjust` to correct:

```bash
# Adjust a batch or level to match physical count
php artisan inventory:adjust \
  --variant-id=123 \
  --warehouse-code=DEFAULT \
  --batch-no=GRN-2026-000001 \
  --qty-delta=+2 \
  --reason=count_correction \
  --notes="Post-verify reconciliation per audit log entry 4521"
```

If drift is large or unexplained, stop and audit the `audit_log` and `stock_movements` in order before correcting.

---

## Goods Receipt Note (GRN) cancellation policy

### When a GRN can be cancelled

`PurchaseInvoiceService::cancel()` allows cancellation **only if:**

1. The invoice status is `posted` (not `draft` or already `cancelled`).
2. **Every line's quantity is still on hand** — none of that stock has been allocated to an order.

The check is per batch: a batch that came from the GRN cannot have qty < the line's received qty anywhere in the world (warehouses, reserved, orders, returns).

### When cancellation fails

If any line has sold, the command rejects with:

```
Cannot cancel GRN GRN-2026-000051: stock from line SKU-X (batch GRN-2026-000051-B1, qty 50 received) has qty 48 on hand (2 sold in order ORD-00542). Reverse the order first.
```

**Resolution:**

1. Inspect the order(s) that consumed the stock: `SELECT * FROM order_items WHERE product_variant_id=X AND order_id=ORD_ID`.
2. If the order has not been delivered yet, cancel it first: `admin/commerce/orders/{order}/cancel`.
3. If the order has been delivered, a full refund is required (the stock cannot come back). Create a return request in the order, inspect it (condition = `saleable`), and process the refund. Only then can the GRN be cancelled.
4. Once all orders are cancelled or returned, retry the GRN cancel.

### Cancellation writes purchase_reversal movements

When cancellation succeeds, for each line:

- A `purchase_reversal` movement is created (qty = −original qty).
- Batch on_hand and level on_hand decrease.
- Audit log records `inventory.grn.cancelled` with reason and actor.

**Example:**

```bash
# Admin UI: admin/inventory/grns/{grn}/cancel
# Paste reason in modal: "Supplier returned the goods; credit note received."
# Click Confirm.

# Or via code (rare):
$service->cancel($invoice, 'Supplier credit note', $actorUserId);
```

---

## Daily inventory alerts

Sends a summary email to ops if any low-stock or expiring stock exists.

```bash
php artisan inventory:alerts
```

**Email includes:**

1. Low stock — levels where `on_hand − reserved ≤ reorder_level` (only if `reorder_level > 0`).
2. Expiring soon — batches where `expiry_date` is ≤ 90 days away and `expiry_date ≥ today`.
3. Expired — batches where `expiry_date < today` and `qty_on_hand > 0`.

**Throttling:**

- A level/batch is re-alerted at most once per 7 days (tracked in `inventory_levels.low_stock_alerted_at` and `stock_batches.expiry_alerted_at`).
- Same-day re-runs of the command skip already-alerted rows.

**Schedule:**

- Daily at **08:30 IST** (`Schedule::command(…)->dailyAt('08:30')`).
- Gated by `InventoryFeature` (the flag must be ON).

**Example:**

```bash
# Manual send (e.g. after a GRN or adjustment)
php artisan inventory:alerts

# On staging, emails go to the admin user; on production, to inventory.alert_email setting
```

---

## Feature flag: InventoryFeature

The inventory module logs all movements regardless, but two features are flag-gated:

1. **Availability enforcement** — checkout will refuse to place an order for more units than are in `available` (on_hand − reserved) across fulfilling warehouses.
2. **Alert job** — `inventory:alerts` only sends if the flag is ON.

### Turning the flag on

Production checklist before enabling `InventoryFeature`:

1. ✓ Deploy S1–S5 (migrations, models, services, permissions, flag infrastructure). The flag ships with default OFF.
2. ✓ Run `php artisan inventory:backfill-opening` to give every current level an opening movement.
3. ✓ Run `php artisan inventory:verify` to confirm both projections match.
4. ✓ Ops creates at least one warehouse (beyond the seeded `DEFAULT`).
5. ✓ Ops enters the first GRN to verify the UI works and stock records appear.
6. ✓ Ops creates and completes one stock transfer.
7. ✓ One complete order flow on staging (place → pack → ship → deliver) with a tracked variant.
8. ✓ Verify that `inventory:alerts` runs and sends (if enabled).

**To enable via admin UI:**

- Log in as `admin` (or a role with `admin.*` permissions).
- Navigate to `admin/settings/feature-flags`.
- Find **Inventory enforcement** in the list.
- Click the toggle to ON.
- (Optional) read the killswitch note: "OFF = stock still recorded, but checkout no longer blocks on availability and alerts stop."

**To enable via code (testing only):**

```php
// In a test
Feature::for(null)->activate(InventoryFeature::class);
$this->postJson('/shop/checkout', […]); // now enforces availability
Feature::for(null)->deactivate(InventoryFeature::class);
```

### Turning the flag off

Only in an emergency (e.g. a bug found in pack logic on live, orders still being placed).

```bash
# Admin UI: admin/settings/feature-flags > toggle OFF
```

When the flag is OFF:

- Orders place without availability check.
- `inventory:alerts` does not send (the job runs but sends nothing).
- All movements are still recorded. No data loss.
- The next flag-on will see the complete ledger and can reconcile.

---

## Stock adjustment command

For corrections and damage write-offs.

```bash
php artisan inventory:adjust \
  --variant-id=ID \
  --warehouse-code=CODE \
  --batch-no=BATCH \
  --qty-delta=±N \
  --reason=count_correction|damaged|expired|theft_loss|sample|other \
  --notes="Reason for adjustment"
```

**Parameters:**

| Param | Required | Note |
|---|---|---|
| `variant-id` | ✓ | Product variant ID. |
| `warehouse-code` | ✓ | Warehouse code (e.g. `DEFAULT`, `HYD-01`). |
| `batch-no` | Optional | Specific batch. If omitted, adjustment applies to the warehouse's total (for `other` reason only). |
| `qty-delta` | ✓ | Signed integer. `+5` adds, `−3` removes. |
| `reason` | ✓ | Enum. `damage`, `expired`, `theft_loss` write a `write_off` movement; others write `adjustment_in` / `adjustment_out`. |
| `notes` | ✓ | Non-empty reason string (audit trail). |

**Example:**

```bash
# Physical count found 3 extra units in a batch (likely a receive discrepancy)
php artisan inventory:adjust \
  --variant-id=12 \
  --warehouse-code=HYD-01 \
  --batch-no=PO-2026-000034 \
  --qty-delta=+3 \
  --reason=count_correction \
  --notes="Physical count 2026-09-12 found 3 units over-received on GRN-2026-000034"

# A batch expired and cannot be sold
php artisan inventory:adjust \
  --variant-id=12 \
  --warehouse-code=DEFAULT \
  --batch-no=GRN-2026-000015 \
  --qty-delta=-25 \
  --reason=expired \
  --notes="Batch GRN-2026-000015 (exp 2026-08-31) purged during weekly rotation"
```

---

## Reports and analytics

All reports are in `admin/inventory/reports` behind `inventory.view` permission.

- **Stock on hand** — current level per warehouse with reorder status.
- **Movement ledger** — full transaction history, filterable by type.
- **Batch & expiry** — ageing, risk buckets.
- **Low stock** — levels at/under reorder threshold.
- **Stock valuation** — per-warehouse inventory value at cost.
- **Purchase register** — GRNs by supplier, GST helper for GSTR-2.
- **Transfer register** — in-transit tracking.
- **Order fulfilment** — packing backlog and delivery status.
- **Returns & restock** — inspection outcomes and restock decisions.
- **Stock in/out summary** — reconciliation: opening + purchases + returns + transfers − sales ± adjustments = closing.

Every report is CSV-exportable (`?export=csv`).

---

## Related commands and settings

| Command/Setting | Purpose |
|---|---|
| `admin/inventory/warehouses` | Create/edit warehouses; only DELETE is archive. |
| `admin/inventory/suppliers` | Supplier master. |
| `admin/inventory/purchase-orders` | PO CRUD, send, cancel. |
| `admin/inventory/grns` | GRN CRUD, post, cancel. |
| `admin/inventory/stock` | Stock by level; drill into batches; inline adjust. |
| `admin/inventory/transfers` | Create/dispatch/receive transfers. |
| `admin/inventory/adjustments` | Adjustment history. |
| `InventorySettings` | Defaults: warehouse_code, expiry_alert_days, alert_email, enforce_availability. |
| `admin/settings` | `inventory.*` settings (dev-owned). |

---

## Troubleshooting

| Symptom | Cause | Fix |
|---|---|---|
| "Insufficient stock" error on checkout but stock shows available | Availability check is OFF (flag). | Enable `InventoryFeature`. |
| "Stock moved but level didn't update" | Direct DB insert, not via StockLedger. | Check `audit_log`; run `inventory:verify`. |
| GRN cancel fails with "qty sold" | An order consumed that stock. | Cancel or return the order first. |
| Batch expiry date in the past but still on hand | `inventory:alerts` found it. | Adjust qty to 0 with reason `expired`. |
| Pick list shows wrong batch or qty | Allocation happened at a different warehouse. | Check all warehouses in stock report. |

---

## See also

- `docs/architecture/adr-0012-stock-ledger.md` — design decisions.
- `docs/compliance/risk-register.md` — R-46, R-47 (franchise stock not tracked / fulfilment not wired).
- `docs/plans/2026-09-11-inventory-warehouse-order-management.md` — full specification.
