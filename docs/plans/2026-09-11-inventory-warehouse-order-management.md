# Inventory, Warehouse & Order Management — design + implementation plan

**Status:** approved design, ready for build · **Owner:** KP · **Date:** 2026-09-11
**Module:** `app/Modules/Inventory/` (new) + surgical hooks in Commerce, Returns, Catalog
**Build style:** one slice per Claude Code session, no exploration outside the files named here.

---

## 0. How to use this document (read first, Claude Code)

- Everything you need is in this file. **Do not grep the repo** beyond the files listed in §2 and the
  hook points in §6 — they were verified on 2026-09-11 at commit `4ffb5b36`.
- Build **one slice (§8) per session**, run only that slice's tests, commit, then `/compact`.
- Conventions apply unchanged: `declare(strict_types=1)`, `final` services, `$fillable`, `casts()`,
  Larastan L7, Pint, migrations one concern each, audit rows for admin writes, `{{ }}` only in Blade.
- Commits touching `OrderStateMachine`, `CheckoutService`, `InspectReturn` need the
  `Compliance-Review: compliance-officer` trailer.
- Do not add anything not in this document. If something is ambiguous, pick the simpler option and
  note it in the commit body.

### 0.1 Model and session plan (pick the model before starting a slice)

| Slice | Model | Effort | Why |
|---|---|---|---|
| S1 Foundation | **Opus 5** | high | Row locks, transactions, projection invariants — everything else depends on it. |
| S2 Purchasing | **Sonnet 5** | medium | Standard CRUD + one `post()` service, fully specified in §4.5. |
| S3 Warehouses, transfers, adjustments | **Sonnet 5** | medium | Same pattern as S2 on top of the S1 ledger. |
| S4 Order sync | **Opus 5** | high | Edits money-adjacent code (`OrderStateMachine`, `CheckoutService`, `InspectReturn`); Commerce + Returns suites must stay green. |
| S5 Reports + alerts | **Sonnet 5** | low–medium | Many files, low complexity (queries, Blade, CSV). |
| S6 Docs | **Haiku 4.5** or Sonnet 5 | low | Prose only. |
| Review of S1 and S4 diffs | **Fable** (planning/review session) | — | Independent review before merge. |

Never use Haiku for a slice that writes PHP.

**Session rules (token budget):**

1. One fresh Claude Code session per slice. Opening prompt:
   `Read docs/plans/2026-09-11-inventory-warehouse-order-management.md §0, §0.1, §2 and slice S<n> (§8) plus the §3/§4/§6 parts it references. Build only S<n>.`
2. Do not spawn subagents or plan-mode agents; the plan is already written.
3. Read only the files in §2. If a file outside §2 seems necessary, read the single method needed and note it in the commit body.
4. Test runs: only `php artisan test tests/Feature/Inventory --compact` while iterating; add `tests/Feature/Commerce tests/Feature/Returns` once at the end of S4. No full-suite runs until the slice is done. Larastan and Pint once, before the commit.
5. When a slice's tests pass: commit (Conventional Commits, `Compliance-Review` trailer where §0 requires it), then stop. Next slice = next session.
6. If the same test fails three times in a row, stop and report the failure instead of looping.

---

## 1. What exists today (analysis result)

| Area | Finding | Consequence |
|---|---|---|
| Catalog | `products` → `product_variants` (admin UI creates one **"Default"** variant per product, `variant_sku = <SKU>-V1`; `primaryVariant()` = first active). `product_categories` self-referential tree. Variants carry `cost_paise`, `landing_price_paise`, `gst_rate_bp`, `inventory_policy` (`track`/`no_track`). | Stock is keyed on **`product_variant_id`** everywhere. Categories are reporting dimensions only. |
| Stock | `inventory_levels` (variant × `warehouse_code` string, default `'DEFAULT'`; `on_hand`, `reserved`). No `warehouses` table, no movement ledger, no batches. Admin product form writes `on_hand` directly. | Keep the table as the **projection**; add an append-only `stock_movements` ledger underneath (same principle as the wallet, ADR-0004). Keep `warehouse_code` (string) as the key — no FK churn on existing rows. |
| Checkout | `CheckoutService::placeOrder()` L286 increments `reserved`. **No availability check anywhere** (`InventoryLevel::available()` has zero callers). **`on_hand` is never decremented** — shipping does not deduct stock. | Add availability check + deduction at pack. |
| Orders | Statuses: `draft, placed, paid, ready_to_ship, shipped, delivered, confirmed, cancelled, refund_requested, refund_inspection, refund_approved, refunded`. `ready_to_ship` exists and is **unused** (4 refs). Admin actions: ship / deliver / cancel (`AdminOrderController`, permission `commerce.order.manage`). `ship_carrier`, `ship_tracking_no` on `orders`. | Reuse `ready_to_ship` as **"packed"**. No new order statuses. |
| Shipments | `shipments` table + `Shipment` model exist (`warehouse_code`, `carrier_code`, `awb_no`, status `created/picked/dispatched/delivered/returned_to_origin`) and are **unused**. | Start writing one shipment row per order at pack. |
| Cancel | `OrderStateMachine::cancel()` L410–420 releases `reserved`; allowed only before ship. | After pack, cancel must also restock (return_in). |
| Returns | `return_requests` → `receive()` (`returns.receive`) → `InspectReturn::record(condition: saleable/non_saleable/damaged)` → buyback → refund pipeline (ADR-0009). **No restock.** | Hook restock into `InspectReturn::record()` for `saleable` only. |
| Ledger | Accounts `asset.inventory`, `expense.cogs`, `asset.gst_input_itc` are seeded and have **zero code references**. | Financial postings for stock are **deferred** (§8 slice S7). Not built in this pass. |
| Franchise | R-46: consignment stock not tracked; franchise can only be a handover point. | `warehouses.type = franchise` + transfers give the "demonstrably Company-owned" record R-46 asks for. No POS, no sale_out except platform orders. |
| RBAC | Permissions seeded in `RolesAndPermissionsSeeder` (`commerce.order.manage`, `returns.receive`, `finance.record`, `audit.read`…); roles `admin-operations`, `admin-finance`, `admin-compliance`, `admin`, `developer`. | Add `inventory.manage` (ops) and `inventory.view` (ops + finance). |
| Flags | `app/Modules/Shared/Features/*Feature.php` classes registered in `AdminFeatureFlagController` (L133 pattern) and checked with `Feature::for(null)->active(X::class)`. | Add `InventoryFeature` gating **only** the checkout availability check and the alert job. Ledger writes are always on (additive, zero risk). |
| Settings | `settings` table key/value; `PurchaseOfferSettings` shows the `SCALAR_DEFAULTS` + `scalar()` pattern. | Copy that pattern into `InventorySettings`. |
| Tests | Pest 5, `tests/Feature/<Module>/…`, seeders run in `tests/Pest.php`. | Add `tests/Feature/Inventory/`. |

**Compliance check (hard rules):** nothing here touches joining, commissions, income copy, orientation, cooling-off, PAN, PII. Hard rule 7 is *strengthened*: a franchise warehouse can only receive transfers and fulfil platform orders — there is no counter-sale path. Cooling-off refunds are untouched; restock is a consequence of inspection, never a condition of refund.

---

## 2. Files Claude Code may read (and nothing else)

```
app/app/Modules/Catalog/Models/{Product,ProductVariant,InventoryLevel}.php
app/app/Modules/Catalog/Http/Controllers/Admin/AdminProductController.php   (syncDefaultVariant L176–204)
app/app/Modules/Catalog/Http/Requests/ProductRequest.php                     (L54–55)
app/app/Modules/Commerce/Services/CheckoutService.php                        (L264–290 only)
app/app/Modules/Commerce/Services/CartService.php                            (add/update qty methods only)
app/app/Modules/Commerce/Services/OrderStateMachine.php                      (markShipped L91–204, cancel L280–440)
app/app/Modules/Commerce/Http/Controllers/Admin/AdminOrderController.php
app/app/Modules/Commerce/Models/Order.php                                    (constants + fillable/casts)
app/app/Modules/Commerce/Services/PurchaseOfferSettings.php                  (pattern only)
app/app/Modules/Fulfilment/Models/Shipment.php
app/app/Modules/Returns/Services/InspectReturn.php                           (record() L44–118)
app/app/Modules/Compliance/Models/AuditLog.php                               (fillable + digest())
app/app/Modules/Shared/Features/PurchaseOffersFeature.php
app/app/Modules/Admin/Http/Controllers/AdminFeatureFlagController.php        (L120–140)
app/database/seeders/{RolesAndPermissionsSeeder,SettingsSeeder,ProductCatalogSeeder}.php
app/resources/views/admin/layouts/admin.blade.php                            (L225–265 sidebar array)
app/resources/views/admin/commerce/orders-show.blade.php
app/resources/views/admin/catalog/products/form.blade.php                    (L135–150)
app/resources/views/shop/orders/show.blade.php
app/routes/web.php                                                           (L380–405 admin commerce block)
app/routes/console.php                                                       (schedule pattern L28–60)
tests/Pest.php, one existing test in tests/Feature/Commerce/ for the style
```

---

## 3. Domain model (7 new tables, 3 altered)

All money in paise (`bigInteger`), all qty `integer`, timestamps `dateTime(…,3)` like the rest of the schema. Keys use `warehouse_code` (string 32) everywhere so existing `inventory_levels` / `shipments` rows join without a backfill.

### 3.1 New tables

```php
// 2026_09_12_100000_create_warehouses_table.php
Schema::create('warehouses', function (Blueprint $t) {
    $t->id();
    $t->string('code', 32)->unique('uniq_warehouses_code');            // 'DEFAULT', 'HYD-01', 'FR-00012'
    $t->string('name', 120);
    $t->enum('type', ['hub', 'warehouse', 'franchise'])->default('warehouse');
    $t->string('line1', 255)->nullable(); $t->string('city', 100)->nullable();
    $t->string('state', 64)->nullable();  $t->string('pincode', 10)->nullable();
    $t->string('contact_phone_e164', 20)->nullable();
    $t->boolean('fulfils_orders')->default(true);    // false = storage only / not pickable
    $t->enum('status', ['active', 'archived'])->default('active');
    $t->dateTime('created_at', 3)->useCurrent(); $t->dateTime('updated_at', 3)->useCurrent()->useCurrentOnUpdate();
});
// same migration, after create: seed the hub so existing rows are valid
DB::table('warehouses')->insert(['code' => 'DEFAULT', 'name' => 'Central Hub', 'type' => 'hub', 'fulfils_orders' => 1]);
```

```php
// 2026_09_12_100001_create_suppliers_table.php
suppliers: id, name(150), gstin(15, nullable), contact_name(100,n), phone_e164(20,n), email(150,n),
           line1, city, state, pincode (n), status enum(active, archived), timestamps
```

```php
// 2026_09_12_100002_create_purchase_orders_tables.php
purchase_orders: id, po_no(24, unique), supplier_id FK restrict, warehouse_code(32) idx,
    status enum(draft, sent, partially_received, received, cancelled) default draft,
    expected_at date n, notes text n, created_by_user_id FK users nullOnDelete, sent_at n, closed_at n, timestamps
purchase_order_items: id, purchase_order_id FK cascade, product_variant_id FK restrict,
    qty_ordered uint, qty_received uint default 0, unit_cost_paise bigint, timestamps
    unique(purchase_order_id, product_variant_id)
```

```php
// 2026_09_12_100003_create_purchase_invoices_tables.php  (this IS the GRN — one document, two roles)
purchase_invoices: id, grn_no(24, unique), supplier_id FK restrict, purchase_order_id FK nullable nullOnDelete,
    warehouse_code(32) idx, supplier_invoice_no(64), supplier_invoice_date date,
    status enum(draft, posted, cancelled) default draft,
    subtotal_paise, gst_paise, total_paise bigint default 0, notes text n,
    posted_at n, posted_by_user_id FK n, cancelled_at n, created_by_user_id FK n, timestamps
    unique(supplier_id, supplier_invoice_no)            // same supplier invoice cannot be entered twice
purchase_invoice_items: id, purchase_invoice_id FK cascade, product_variant_id FK restrict,
    batch_no(64), mfg_date date n, expiry_date date n, qty uint, unit_cost_paise bigint,
    gst_rate_bp uint, taxable_value_paise, gst_paise, line_total_paise bigint, timestamps
    index(purchase_invoice_id)
```

```php
// 2026_09_12_100004_create_stock_batches_table.php
stock_batches: id, product_variant_id FK restrict, warehouse_code(32), batch_no(64),
    mfg_date date n, expiry_date date n, unit_cost_paise bigint default 0,
    qty_on_hand int default 0,                     // projection of movements for this batch
    received_at dateTime(3),                       // first receipt into this warehouse (ageing)
    source_type(32) n, source_id ubigint n,        // 'purchase_invoice_item' | 'stock_transfer_item' | 'return_request' | 'adjustment'
    expiry_alerted_at dateTime(3) n, timestamps
    unique(product_variant_id, warehouse_code, batch_no) 'uniq_stock_batches_variant_wh_batch'
    index(warehouse_code, expiry_date) 'idx_stock_batches_wh_expiry'
```

```php
// 2026_09_12_100005_create_stock_movements_table.php  (APPEND-ONLY — no update/delete path anywhere)
stock_movements: id, product_variant_id FK restrict, warehouse_code(32), stock_batch_id FK n restrict,
    type enum(purchase_in, purchase_reversal, sale_out, sale_reversal, transfer_out, transfer_in,
              return_in, adjustment_in, adjustment_out, write_off, opening),
    qty int,                                       // signed: +in / −out, never 0
    unit_cost_paise bigint default 0,
    reference_type(32) n, reference_id ubigint n,  // 'order_item' | 'purchase_invoice_item' | 'stock_transfer_item' | 'return_request' | 'stock_adjustment'
    reason(255) n, actor_user_id FK n nullOnDelete, occurred_at dateTime(3), created_at dateTime(3) useCurrent
    index(product_variant_id, warehouse_code, occurred_at) 'idx_sm_variant_wh_time'
    index(reference_type, reference_id) 'idx_sm_reference'
    index(type, occurred_at) 'idx_sm_type_time'
```

```php
// 2026_09_12_100006_create_stock_transfers_tables.php
stock_transfers: id, transfer_no(24, unique), from_warehouse_code(32), to_warehouse_code(32),
    status enum(draft, dispatched, received, cancelled) default draft, notes n,
    created_by_user_id, dispatched_by_user_id, received_by_user_id FK n, dispatched_at n, received_at n, timestamps
stock_transfer_items: id, stock_transfer_id FK cascade, product_variant_id FK restrict,
    stock_batch_id FK restrict (source batch), qty uint, timestamps
```

```php
// 2026_09_12_100007_create_stock_adjustments_table.php  (physical count / damage / correction — audited)
stock_adjustments: id, adjustment_no(24, unique), warehouse_code(32), product_variant_id FK, stock_batch_id FK n,
    qty_delta int (signed, ≠0), reason enum(count_correction, damaged, expired, theft_loss, sample, other),
    notes text, actor_user_id FK, occurred_at, created_at
```

### 3.2 Altered tables (one migration each)

```php
// 2026_09_12_100008_add_reorder_level_to_inventory_levels.php
inventory_levels: + reorder_level int default 0, + low_stock_alerted_at dateTime(3) n
// 2026_09_12_100009_add_fulfilment_columns_to_orders.php
orders: + warehouse_code(32) n (set at pack), + packed_at dateTime(3) n, + packed_by_user_id FK n
// 2026_09_12_100010_add_fk_warehouse_code_on_inventory_levels.php
inventory_levels.warehouse_code → FK warehouses(code) restrictOnDelete   // safe: DEFAULT seeded in 100000
```

`shipments` is used as-is (no change). `inventory_levels.on_hand` = Σ `stock_movements.qty` for (variant, warehouse); `stock_batches.qty_on_hand` = Σ for the batch. Both are recalculated **inside the same transaction** as every movement, and `inventory:verify` (§7) proves equality.

### 3.3 Invariants (encode as tests, §9)

1. `on_hand(variant, wh) == SUM(stock_movements.qty)` for that key, always.
2. `qty_on_hand(batch) >= 0` and `on_hand >= 0` (a movement that would go negative throws `InsufficientStockException`).
3. `available = on_hand − reserved`; a tracked variant cannot be placed in an order beyond `available` across `fulfils_orders` warehouses (flag-gated).
4. Every `sale_out` movement references an `order_item`; every `purchase_in` references a `purchase_invoice_item`. There is no way to create a `sale_out` without an order (hard-rule-2 symmetry: sales exist only as platform orders).
5. Expired batches (`expiry_date < today`) are never allocated by FEFO.
6. A posted purchase invoice is immutable; cancellation writes `purchase_reversal` movements (never deletes).

---

## 4. Services (single source of truth — controllers call only these)

All in `app/Modules/Inventory/Services/`. All `final`, constructor-promoted `DatabaseManager $db`. Every method that moves stock runs in **one transaction with `lockForUpdate()`** on the `inventory_levels` row (create it if missing) and the affected `stock_batches` rows, and writes an `audit_log` row (`action` in the `inventory.*` namespace) with `before_hash`/`after_hash` = `AuditLog::digest((string) on_hand_before / after)`.

### 4.1 `StockLedger` — the only class that writes `stock_movements`

```php
final class StockLedger
{
    /** @param array{type:string, variant_id:int, warehouse_code:string, batch_id:?int, qty:int, unit_cost_paise:int,
     *               reference_type:?string, reference_id:?int, reason:?string, actor_user_id:?int, occurred_at?:Carbon} $m */
    public function post(array $m): StockMovement;      // validates qty≠0, type ∈ enum, locks level+batch, throws InsufficientStockException, updates both projections, returns row
    public function onHand(int $variantId, string $warehouseCode): int;     // reads projection
    public function available(int $variantId, ?string $warehouseCode = null): int; // null = Σ over active fulfils_orders warehouses; on_hand − reserved
}
```

### 4.2 `WarehouseService` — CRUD + `default(): Warehouse` (code `DEFAULT`) + `fulfilling(): Collection` + `assertActive(string $code)`.

### 4.3 `SupplierService` — CRUD only.

### 4.4 `PurchaseOrderService` — `create/update(draft)`, `send()`, `cancel()`. `qty_received` is updated by `PurchaseInvoiceService::post()`; when all lines full → status `received`, else `partially_received`. No stock effect on its own.

### 4.5 `PurchaseInvoiceService` (GRN)

```php
public function createDraft(array $header, array $lines, int $actorUserId): PurchaseInvoice;   // from PO (prefill lines) or blank
public function updateDraft(PurchaseInvoice $pi, array $header, array $lines): PurchaseInvoice; // status must be draft
public function post(PurchaseInvoice $pi, int $actorUserId): PurchaseInvoice;
    // transaction: for each line → firstOrCreate stock_batch (variant, warehouse_code, batch_no) with mfg/expiry/unit_cost/received_at
    //              → StockLedger::post(type purchase_in, +qty, reference purchase_invoice_item)
    //              → if purchase_order_id: bump purchase_order_items.qty_received, roll PO status
    //              → status posted, posted_at, posted_by; audit 'inventory.grn.posted'
    // idempotent: posting a posted invoice throws DomainException
public function cancel(PurchaseInvoice $pi, string $reason, int $actorUserId): void;
    // only if posted and every batch still has qty ≥ line qty (nothing sold yet) → purchase_reversal movements; else throw with a clear message
```

Line totals: `taxable = qty × unit_cost`, `gst = taxable × gst_rate_bp / 10000` (purchase side is tax-exclusive — supplier invoices quote ex-GST; note this differs from the catalogue which is GST-inclusive). Header totals = Σ lines.

### 4.6 `StockTransferService`

```php
createDraft(from, to, lines[variant_id, batch_id, qty], actor)   // validates from≠to, both active, batch belongs to `from`, qty ≤ batch.qty_on_hand − (reserved share? no: reserved is per warehouse total; require qty ≤ available(variant, from))
dispatch(transfer, actor)   // transfer_out from source batches (−qty). Stock is "in transit" = transfer.status dispatched; NOT in any warehouse's on_hand. Simplification accepted.
receive(transfer, actor, ?array $receivedQty)   // transfer_in to destination: firstOrCreate batch with same batch_no/mfg/expiry/unit_cost at `to`; if receivedQty < dispatched → the short qty is written as write_off at destination with reason 'transit_shortage' (audited). Status received.
cancel(transfer, actor)    // draft only. A dispatched transfer is cancelled by receiving it back: create reverse transfer (UI: "Return to sender" button) — keeps the ledger honest.
```

### 4.7 `StockAdjustmentService::adjust(warehouse_code, variant_id, ?batch_id, qty_delta, reason, notes, actor)` — writes `stock_adjustments` row + `adjustment_in|adjustment_out` (or `write_off` when reason ∈ damaged/expired/theft_loss) movement. Requires `notes` non-empty. Also used for **opening stock** (`type opening`, reason `count_correction`, notes "Opening stock") — see migration note §5.

### 4.8 `OrderFulfilmentService` — the bridge to Commerce (the only file that knows both sides)

```php
public function pack(Order $order, ?string $warehouseCode, int $actorUserId): void
    // precondition: status === paid (markShipped L93 already accepts paid|ready_to_ship, so pack sits exactly between them), packed_at null
    // warehouse: given, else InventorySettings::defaultWarehouseCode(), must be active + fulfils_orders
    // for each order_item whose variant.inventory_policy === 'track':
    //     allocate FEFO: batches WHERE variant, warehouse, qty_on_hand>0 AND (expiry_date IS NULL OR expiry_date >= today) ORDER BY expiry_date ASC NULLS LAST, received_at ASC — lockForUpdate
    //     take from batches until item.qty covered; else throw InsufficientStockException("SKU x: need n, have m in <wh>")
    //     StockLedger::post(sale_out, −taken, batch, unit_cost = batch.unit_cost_paise, reference order_item)
    //     inventory_levels.reserved −= item.qty (min with reserved, like cancel does)
    // Shipment::create([order_id, warehouse_code, carrier_code 'MANUAL', status 'picked'])
    // orders: status ready_to_ship, warehouse_code, packed_at, packed_by_user_id
    // audit 'order.packed' with details {order_no, warehouse_code, allocations:[{item_id, batch_no, qty}]}
public function unpackForCancel(Order $order, int $actorUserId): void
    // called by OrderStateMachine::cancel() when packed_at !== null: for each sale_out movement of this order's items → sale_reversal (+qty) into the same batch; shipment status 'returned_to_origin'; audit 'order.unpacked'
public function restockReturn(ReturnRequest $rr, int $actorUserId): void
    // called by InspectReturn::record() when condition === 'saleable': qty = rr.qty into order.warehouse_code (fallback DEFAULT);
    // batch = the batch of the item's sale_out movement if exactly one, else firstOrCreate batch_no "RET-{rma_no}" (expiry copied from original if single); movement return_in, reference return_request. Idempotent on (reference_type, reference_id, type).
public function pickList(Order $order): array   // for the packing slip view: [{sku, name, qty, batch_no, expiry}]
```

### 4.9 `InventoryAlertService` — `lowStock(): Collection` (levels where `on_hand − reserved <= reorder_level` and `reorder_level > 0`), `expiring(int $days): Collection`, `expired(): Collection`. Used by the dashboard card, the reports and the daily command.

### 4.10 `InventorySettings` — copy the `PurchaseOfferSettings` scalar pattern:

```php
SCALAR_DEFAULTS = ['inventory.default_warehouse_code' => 'DEFAULT', 'inventory.expiry_alert_days' => '90',
                   'inventory.alert_email' => '', 'inventory.enforce_availability' => '1']
```

### 4.11 Number sequences — `InventoryNumbering::next('GRN'|'PO'|'TRF'|'ADJ')` → `GRN-2026-000001` using a `MAX()+1` under `lockForUpdate` on the respective table (gap-tolerant; these are not tax documents, so the gap-free `InvoiceNumberSequence` machinery is unnecessary).

---

## 5. Data migration for existing stock

Existing `inventory_levels.on_hand` values (seeders put 500) have no movements. Add a one-off command `inventory:backfill-opening` (idempotent: skips any (variant, wh) that already has a movement): for each level with `on_hand > 0` → create batch `OPENING` (no expiry, unit_cost = variant.cost_paise) and an `opening` movement of `on_hand`. Run once on staging/prod after deploy; document in the runbook. Do **not** do this inside a migration.

---

## 6. Hook points in existing code (exact, minimal diffs)

| # | File · location | Change |
|---|---|---|
| H1 | `CheckoutService::placeOrder()` L286 (`if ($variant->inventory_policy === 'track')`) | Before `increment('reserved')`: `if ($this->inventoryEnforced() && $this->ledger->available($variant->id) < $ci->qty) throw new InsufficientStockException(...)`. Lock the `inventory_levels` row (`->lockForUpdate()->first()`) and `firstOrCreate` it for `DEFAULT` if missing (today `?->` silently skips). `inventoryEnforced()` = `Feature::for(null)->active(InventoryFeature::class) && InventorySettings::enforceAvailability()`. Controller maps the exception to a redirect back with "Only N left of X". |
| H2 | `CartService::addItem()` L89 / `updateQty()` L163 | Same check, non-blocking: clamp to `available` and flash "Only N available". |
| H3 | `OrderStateMachine::markShipped()` L91 | At the top, inside the existing transaction, before the ledger lines: `if ($order->packed_at === null) { $this->fulfilment->pack($order, null, $actorUserId); $order->refresh(); }` → the legacy one-click ship keeps working; carrier/AWB also written to the shipment row (`status dispatched, awb_no, carrier_code, dispatched_at`). |
| H4 | `OrderStateMachine::cancel()` L410 (release block) | Wrap: `if ($order->packed_at !== null) { $this->fulfilment->unpackForCancel($order, $actorUserId); } else { …existing release loop… }`. |
| H5 | `OrderStateMachine::markDelivered()` | Also set `shipments.status = delivered, delivered_at` for the order's shipment (if any). |
| H6 | `InspectReturn::record()` after the inspection row is created, same transaction | `if ($condition === 'saleable') { $this->fulfilment->restockReturn($returnRequest, $inspectorUserId); }` |
| H7 | `AdminOrderController` + `orders-show.blade.php` | New action `pack` (`POST /commerce/orders/{order}/pack`, `can:commerce.order.manage`, body `warehouse_code`) shown when status ∈ {paid} and `packed_at` null; ship form unchanged. Show the pick list (batch/expiry per line), the warehouse and the shipment row. Order timeline (§7.4). |
| H8 | `AdminProductController::syncDefaultVariant()` L200 | **Stop writing `on_hand`** from the product form. Replace with `reorder_level` (form field renamed; `ProductRequest` rule `reorder_level nullable integer min:0`). The form shows current on-hand per warehouse read-only with a link "Adjust stock". Reason: `on_hand` is now a projection; writing it directly breaks invariant 1. |
| H9 | `ProductCatalogSeeder` / `ProductionSeeder` | Keep `on_hand => 500` writes as they are (seeded before backfill); `inventory:backfill-opening` converts them. Alternatively the catalogue seeder posts `opening` movements via `StockAdjustmentService` when the Inventory module is present — prefer this so tests are self-consistent. |
| H10 | `RolesAndPermissionsSeeder` | `'inventory.manage' => 'admin-operations'`, `'inventory.view' => ['admin-operations', 'admin-finance']`. |
| H11 | `AdminFeatureFlagController` L133 pattern | Register `InventoryFeature` (label "Inventory enforcement", killswitch note: "OFF = stock still recorded, but checkout no longer blocks on availability and alerts stop"). |
| H12 | `admin.blade.php` sidebar L234 | After Orders: an "Inventory" group (visible with `inventory.view`): Stock, Warehouses, Suppliers, Purchase orders, Goods receipts (GRN), Transfers, Adjustments, Reports. Badge = low-stock + expiring count. |
| H13 | `shop/orders/show.blade.php` | Buyer timeline: Placed → Paid → Packed → Shipped (carrier + tracking) → Delivered → Cooling-off ends / Return & refund states. Derived from `orders.*_at`, `shipments`, `return_requests` — no new table. |
| H14 | `routes/console.php` | `Schedule::command(InventoryAlertsCommand::class)->dailyAt('08:30')` (IST like the others), guarded by the flag inside the command. |
| H15 | `PurchaseDataResetAction` / `PlatformResetAction` | Truncate `stock_movements`, `stock_batches`, `stock_transfers*`, `stock_adjustments`, `purchase_invoices*`, `purchase_orders*`; zero `on_hand`; keep `warehouses`, `suppliers`. (Both actions already list tables — add to those lists.) |

---

## 7. Admin UI, reports, alerts, commands

### 7.1 Screens (Blade + Tailwind, copy the existing admin index/show/form layout of `admin/commerce/*`)

| Route prefix `admin/inventory/…` | Permission | Screens |
|---|---|---|
| `stock` | `inventory.view` | Index: warehouse filter, search by SKU/name/category, columns on-hand / reserved / available / reorder / value (Σ batch qty × unit_cost) / flag. Row expands to batches. Inline "Adjust" (→ adjustments form prefilled). |
| `warehouses` | `inventory.manage` | index / create / edit (archive instead of delete; DEFAULT cannot be archived). |
| `suppliers` | `inventory.manage` | index / create / edit. |
| `purchase-orders` | `inventory.manage` | index / create / edit (draft) / show / send / cancel / "Create GRN from this PO". |
| `grns` | `inventory.manage` | index / create (optionally from PO) / edit (draft) / show / **post** / cancel. Line editor: variant search (SKU), batch no, mfg, expiry, qty, unit cost, GST %. Totals live via a tiny inline `<script>` (no framework). |
| `transfers` | `inventory.manage` | index / create / show / dispatch / receive (per-line received qty) / cancel. |
| `adjustments` | `inventory.manage` | index / create (warehouse → variant → batch → delta, reason, notes). |
| `reports` | `inventory.view` | One controller `AdminInventoryReportController` with one method per report; each accepts `?export=csv` (use `response()->streamDownload`). |

Every screen's *write* action posts an `audit_log` row (`inventory.warehouse.updated`, `inventory.grn.posted`, `inventory.transfer.dispatched`, …) with the same `before_hash`/`after_hash` discipline used elsewhere.

### 7.2 Reports (all filterable by warehouse + date range where relevant; all CSV-exportable)

1. **Stock on hand** — variant × warehouse: on hand, reserved, available, reorder level, value at cost, status (OK / LOW / OUT).
2. **Stock movement ledger** — every movement with type, ±qty, batch, reference (linked to order / GRN / transfer / return / adjustment), actor.
3. **Batch & expiry** — batches with days-to-expiry, buckets: expired / ≤30 / ≤90 / OK; value at risk.
4. **Low stock** — levels at/under reorder, with suggested reorder qty = `reorder_level × 2 − available` (simple; state it in the UI as a suggestion only).
5. **Stock valuation** — per warehouse and per category: Σ qty × batch unit cost (FIFO-by-batch cost; no weighted average — say so on the page).
6. **Purchase register** — posted GRNs by supplier / month with taxable, GST (input credit) and total — the finance team's GSTR-2 helper.
7. **Transfer register** — transfers with status, in-transit qty (dispatched − received).
8. **Order fulfilment** — orders by stage with age: paid-not-packed, packed-not-shipped, shipped-not-delivered, plus cancelled-after-pack and returns pending receipt. This is the "order tracking" report.
9. **Returns & restock** — return requests with inspection condition and whether/where restocked (join on `stock_movements` reference `return_request`).
10. **Stock in / out summary** — per variant for a period: opening, +purchases, +returns, +transfers in, −sales, −transfers out, ±adjustments, closing. (One query over `stock_movements` grouped by type; verify closing == on_hand.)

### 7.3 Alerts

- **Daily command** `inventory:alerts` → one email to `inventory.alert_email` (falls back to the admin user's email) with two tables: low stock, batches expiring within `inventory.expiry_alert_days`, plus expired batches still on hand. Marks `low_stock_alerted_at` / `expiry_alerted_at` so the same row is re-sent at most weekly. Uses the existing Notifications pattern (`Notification::route('mail', …)`).
- **Dashboard card** on `admin/dashboard.blade.php`: "Inventory — N low stock · N expiring ≤ 90 d · N expired on hand" linking to the reports.
- **Sidebar badge** (H12).
- **Storefront**: a tracked variant with `available <= 0` shows "Out of stock" and the add-to-cart button is disabled (flag-gated). Nothing else changes on public pages — no scarcity copy ("only 2 left!") on the storefront; that reads as sales pressure. Show the plain number only in cart/checkout errors.

### 7.4 Order tracking (customer + admin)

Timeline derived, not stored: `placed_at, paid_at, packed_at, shipped_at (+carrier/tracking), delivered_at, cooling-off ends_at, cancelled_at, refund states from return_requests / order status`. One Blade partial `shop/orders/_timeline.blade.php` used in both `shop/orders/show` and `admin/commerce/orders-show`.

### 7.5 Commands

| Command | Purpose |
|---|---|
| `inventory:backfill-opening` | §5. Idempotent. |
| `inventory:alerts` | §7.3. Scheduled daily. |
| `inventory:verify` | Recomputes Σ movements per (variant, wh) and per batch and diffs against the projections; non-zero exit on drift. Run in CI after the test suite and weekly in prod (`->weeklyOn(1, '03:00')`). |

---

## 8. Build slices (one Claude Code session each; ≈ estimated size)

| Slice | Model | Scope | New files | Tests |
|---|---|---|---|---|
| **S1 Foundation** | Opus 5 | Migrations 100000–100010, models (`Warehouse, Supplier, PurchaseOrder(+Item), PurchaseInvoice(+Item), StockBatch, StockMovement, StockTransfer(+Item), StockAdjustment`), `InventoryServiceProvider` (register in `bootstrap/providers.php`), `StockLedger`, `InventorySettings`, `InventoryFeature`, `InsufficientStockException`, permissions (H10), flag registration (H11), `inventory:backfill-opening`, `inventory:verify`. | ~22 | `StockLedgerTest` (invariants 1, 2, 6-signed-qty), `BackfillOpeningTest`, `VerifyCommandTest` |
| **S2 Purchasing** | Sonnet 5 | `SupplierService`, `PurchaseOrderService`, `PurchaseInvoiceService`, `InventoryNumbering`; admin controllers+views for suppliers, POs, GRNs; sidebar group (H12). | ~14 | `PurchaseInvoicePostTest` (batches created, movements, PO rolled, idempotent, cancel-after-sale refused, duplicate supplier invoice refused) |
| **S3 Warehouses, transfers, adjustments** | Sonnet 5 | `WarehouseService`, `StockTransferService`, `StockAdjustmentService`; controllers+views; stock index screen. | ~12 | `StockTransferTest` (dispatch/receive/shortage/from≠to), `StockAdjustmentTest`, `StockIndexScreenTest` (permission + render) |
| **S4 Order sync** | Opus 5 | `OrderFulfilmentService`, hooks H1–H7, H9, H13, `_timeline` partial, product form change H8. **Compliance-Review trailer.** | ~6 new, 8 edited | `OrderPackFefoTest` (FEFO order, expired skipped, multi-batch split, insufficient → exception, reserved released), `LegacyShipAutoPacksTest`, `CancelAfterPackRestocksTest`, `ReturnSaleableRestocksTest`, `CheckoutAvailabilityTest` (flag on blocks, flag off passes), `ProductFormNoLongerWritesOnHandTest` |
| **S5 Reports + alerts** | Sonnet 5 | `AdminInventoryReportController` + 10 views + CSV, `InventoryAlertService`, `inventory:alerts`, dashboard card, storefront out-of-stock (flag-gated), H14, H15. | ~15 | `ReportsRenderTest` (each report 200 + CSV header row), `StockInOutSummaryReconcilesTest` (closing == on_hand), `AlertsCommandTest` (email once, then suppressed 7 d) |
| **S6 Docs** | Haiku 4.5 | `docs/roadmap.md` section, `docs/architecture/adr-0012-stock-ledger.md` (short: projection + append-only, FEFO, warehouse_code key decision, in-transit simplification), risk register R-46 update ("record now exists; franchise still not fulfilling — R-47 stands"), runbook `docs/runbooks/inventory.md` (backfill, verify, GRN cancel policy). | 4 | — |
| **S7 (optional, deferred)** | Opus 5 | Ledger postings: GRN post → Dr `asset.inventory` (taxable) + Dr `asset.gst_input_itc`, Cr `liability.supplier_payable` (new account seed); pack → Dr `expense.cogs` Cr `asset.inventory` at batch cost; reversals mirror. **Do not build until KP confirms with the accountant** — it changes the P&L the finance role sees. | — | — |

Sequencing is strict S1 → S4; S5 and S6 can follow in either order. Total ≈ 70 new files, ≈ 10 edited files, ≈ 20 test files.

---

## 9. Test list (Pest, `tests/Feature/Inventory/`) — names are the acceptance criteria

```
StockLedgerTest
  posts a movement and updates level + batch projections in one transaction
  refuses qty 0 and refuses a movement that would take a batch negative
  available() sums only active fulfils_orders warehouses and subtracts reserved
PurchaseInvoicePostTest
  posting creates one batch per line (merges same batch_no) and purchase_in movements with unit cost
  posting rolls the PO to partially_received / received
  posting twice throws; cancel after any sale_out on the batch throws; cancel before writes purchase_reversal
  the same supplier invoice number cannot be entered twice for one supplier
StockTransferTest
  dispatch writes transfer_out and reduces source; nothing is added anywhere until receive
  receive writes transfer_in at destination with the same batch_no/expiry/cost; short receipt writes write_off
  from == to, archived warehouse, qty > available are all refused
OrderPackFefoTest
  allocates the earliest-expiring non-expired batch first and splits across batches
  never allocates an expired batch even when it is the only stock (throws InsufficientStock)
  writes one sale_out per (item, batch), releases reserved, creates the shipment row, sets ready_to_ship + packed_at
  pack is idempotent (second call is a no-op)
LegacyShipAutoPacksTest
  markShipped on an unpacked paid order packs first, then ships; shipment row gets carrier + awb
CancelAfterPackRestocksTest
  cancel after pack writes sale_reversal into the same batches and marks the shipment returned_to_origin
  cancel before pack still only releases reserved (unchanged behaviour)
ReturnSaleableRestocksTest
  inspection 'saleable' writes return_in into the original batch; 'damaged' and 'non_saleable' write nothing
  restock is idempotent per return request
CheckoutAvailabilityTest
  with InventoryFeature on, placing more than available fails with a readable message and reserves nothing
  with the flag off, checkout behaves exactly as before (regression guard)
ProductFormTest
  saving a product no longer changes on_hand; reorder_level is saved on the DEFAULT level
ReportsRenderTest · StockInOutSummaryReconcilesTest · AlertsCommandTest · VerifyCommandTest · BackfillOpeningTest
```

Run per slice: `php artisan test tests/Feature/Inventory --compact` plus, in S4 only, `tests/Feature/Commerce` and `tests/Feature/Returns` (regression). Larastan + Pint before each commit as usual.

---

## 10. Decisions taken (so nobody re-litigates them mid-build)

| Decision | Why |
|---|---|
| Keep `warehouse_code` (string) as the key, add `warehouses` table with FK on the code | Zero backfill on `inventory_levels` and `shipments`; codes are human-readable in every report. |
| `inventory_levels.on_hand` becomes a projection of `stock_movements`, written in the same transaction | Same principle as the wallet ledger (ADR-0004); `inventory:verify` is the safety net. |
| GRN = purchase invoice (one document) | The user's flow is "supplier invoice arrives → stock in". A separate GRN document would double the data entry for a small ops team. |
| FEFO allocation at **pack**, not at order placement | Reservation stays a simple counter (existing code); batch choice happens when someone is physically picking, which is when it is true. |
| No in-transit warehouse; transfers are "dispatched" until received | One less location to explain; the transfer register shows in-transit qty. Recorded in ADR-0012 as a known simplification. |
| Only `saleable` returns restock; damaged/non-saleable are visible in the returns report but never re-enter stock | Food supplements (`products.food_type` exists) — nothing questionable goes back on the shelf. |
| No batch quantity on `order_items`; allocation lives in `stock_movements` (reference `order_item`) | Avoids a pivot table; the pick list and the restock path both read movements. |
| Financial postings deferred (S7) | Not requested; changes what finance sees; needs the accountant's rule on valuation. Accounts are already seeded, so it is additive later. |
| Availability enforcement behind `InventoryFeature` (default OFF) | Existing staging/UAT flow must not start rejecting checkouts the day the module deploys. Turn on after `inventory:backfill-opening` and a real GRN. |
| No scarcity copy on the storefront | "Only 2 left" is a pressure tactic; the platform shows out-of-stock only. |

---

## 11. Rollout

1. Deploy S1–S4 with the flag OFF → `php artisan migrate` → `php artisan inventory:backfill-opening` → `php artisan inventory:verify`.
2. Ops creates real warehouses/suppliers, enters the first GRN, runs a transfer.
3. Turn `InventoryFeature` ON in staging; run through order → pack → ship → deliver → return → restock once on UAT.
4. Production: same order; keep `inventory:verify` weekly.
