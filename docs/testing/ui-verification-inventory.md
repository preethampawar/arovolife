# UI verification: inventory / warehouse module & order fulfilment

Playwright browser specs for the inventory module (slices S1-S5) and the
order status / cancellation / refund flows it touches. These are **browser**
tests against a running app — they are not run in CI here and are not part
of `php artisan test`. Run them against your local Docker stack.

## Files

- `tests/Browser/inventory-warehouse.spec.js` — warehouses, suppliers,
  purchase orders, GRNs, stock, transfers, adjustments, reports, CSV export,
  low-stock/expiry alert surfaces.
- `tests/Browser/order-fulfilment.spec.js` — storefront checkout (stub
  gateway) → pack (FEFO) → ship → deliver → timeline; cancel-after-pack stock
  return; returns/refund with a saleable inspection.
- `tests/Browser/fixtures.js` — shared login helpers plus two additions used
  by both new specs:
  - `submitAndConfirm(page, buttonLocator)` — clicks a submit button and,
    if the platform confirm-modal appears, clicks its Confirm button too.
    Needed because most of the inventory module's write actions (archive,
    send, post/cancel a GRN, dispatch, receive) are marked
    `data-confirm-impact` only, **not** `data-confirm` — the confirm-modal
    script (`resources/views/components/confirm-modal.blade.php`) only
    intercepts `data-confirm`, so those forms submit natively with no
    dialog. Commerce order actions (pack/ship/deliver/cancel) and returns
    actions do carry `data-confirm` and do show the modal. The helper
    handles both without the test needing to know which case applies.
  - `readStockOnHand(page, sku, warehouseCode)` — reads the "On hand" column
    for a SKU + warehouse from `/admin/inventory/stock`, used to assert a
    quantity actually changed rather than just that a page returned 200.

## Running

```bash
cd app
APP_URL=http://localhost:8084 npx playwright test tests/Browser/inventory-warehouse.spec.js tests/Browser/order-fulfilment.spec.js
```

Env vars (all optional, defaults shown):

| Var | Default | Used for |
|---|---|---|
| `APP_URL` | `http://localhost:8084` | Base URL of the running app (playwright.config.js) |
| `A11Y_ADN` | `394325128` | Distributor ADN used to log in as a purchasing distributor |
| `A11Y_PASSWORD` | `Test1234!` | That distributor's password |

Admin credentials are hard-coded in `fixtures.js` (`admin@arovolife.test` /
`admin12345`) to match the rest of the browser suite — there is no env
override for them.

## `InventoryFeature` — turn enforcement on/off

`InventoryFeature` (`app/Modules/Shared/Features/InventoryFeature.php`)
gates *enforcement* only — checkout availability checks and the daily alert
mail — never the recording of stock movements (GRNs, transfers, adjustments
post and record their ledger entries regardless of the flag). Both spec
files exercise the *recording* side and do not require the flag on. The
low-stock/expiry **enforcement** assertions (a variant actually blocking
checkout, or the daily alert firing) are not covered here and need the flag
on to mean anything.

To flip it in tinker:

```bash
php artisan tinker --execute 'Laravel\Pennant\Feature::activate(\App\Modules\Shared\Features\InventoryFeature::class);'
php artisan tinker --execute 'Laravel\Pennant\Feature::deactivate(\App\Modules\Shared\Features\InventoryFeature::class);'
```

Turn it on only after `php artisan inventory:backfill-opening`,
`php artisan inventory:verify` reports no drift, and at least one real GRN
has been posted — see the flag's own docblock.

## What each spec needs from your dev database

**`inventory-warehouse.spec.js`** creates its own warehouses, supplier,
purchase order, GRN, transfer and adjustment (all timestamp-suffixed so
re-runs don't collide) — it only needs:
- an admin with `inventory.manage` and `inventory.view`;
- at least one catalog product/variant so the PO/GRN "choose a product"
  dropdowns are not empty. If none exists, the PO-creation test
  `test.skip()`s with that reason and every later test in the file that
  depends on `state.variantSku`/`state.poNo` skips in turn.

**`order-fulfilment.spec.js`** needs real storefront/checkout data it does
not create:
- a purchasable shop product with stock at a warehouse that fulfils orders;
- the distributor behind `A11Y_ADN` allowed to buy (KYC-approved, past
  registration cooling-off);
- the payment gateway resolving to the **stub** (Razorpay off, or no live
  key configured) — see `PaymentGatewayResolver` /
  `App\Modules\Payments\Services\StubGateway`. If checkout redirects to the
  real Razorpay Checkout modal instead, the placement test skips with a
  message naming that as the reason, rather than trying to click through a
  real payment;
- for the returns/refund test: an existing return request in `opened`
  status with its order in `refund_requested` (i.e. someone used
  **My Orders → Return this order** on a delivered, non-cooling-off order).
  The suite does not open one itself — a return needs a delivered order
  that is already past its cooling-off window in most cases, which a single
  fresh test run cannot manufacture — so if none exists the test
  `test.skip()`s and tells you to open one from the storefront first.

## What a skip means

Every `test.skip()` in these two files carries a message naming exactly
what was missing (empty catalog, no purchasable product, wrong payment
gateway, no return request, order not in the right status, etc.). A skip is
not a pass — if a spec you expected to run fully skips, read the message,
supply the missing data or configuration, and re-run.

## Selectors

All selectors are built from visible text, ARIA roles, table headers, and
existing `data-confirm*` hooks in the Blade views — no `data-testid`
attributes were needed or added.
