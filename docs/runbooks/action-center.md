# Action Center Runbook

The Action Center aggregates operational signals across all modules into one screen: `/admin/action-center`. It shows what needs a human's attention right now, ranked by severity and age, with a deep link to each fix screen.

---

## The six action groups

### Orders & fulfilment

Actions tied to the e-commerce order pipeline: placement, payment, packing, shipment, delivery tracking, and returns.

### Stock

Inventory management: on-hand levels, batch expiry, warehouse transfers, and purchase order status. All hidden when `InventoryFeature` is OFF. Requires permission `inventory.view` (or `inventory.manage` to snooze).

### Money

Payments and payouts: failed and overdue refunds, payout batch approvals, missing bank details, and payment reconciliation gaps.

### People

Distributor onboarding and lifecycle: KYC reviews, registration requests, line-change decisions, Arete Development Centre applications, cooling-off expiry, and dormant accounts.

### Compliance & platform

Regulatory obligations and system health: grievance SLA clocks, messaging moderation, published consent pages, and compensation engine runs.

---

## Action catalogue

Each action has a stable `key` used in routes and snooze rows. Severity is derived: a type has a ceiling (INFO, WARNING, or CRITICAL) that is raised to the next level if the item is past its SLA.

| Group | Key | Description | Permission | Severity | SLA | Fix at | Statutory |
|---|---|---|---|---|---|---|---|
| **Orders** | `orders.paid_not_packed` | Order paid, not yet packed | `commerce.order.manage` | WARNING | 24h | Admin order detail | No |
| | `orders.packed_not_shipped` | Order packed, not yet shipped | `commerce.order.manage` | WARNING | 24h | Admin order detail | No |
| | `orders.shipped_not_delivered` | Order shipped, not yet delivered | `commerce.order.manage` | WARNING | 7 days | Admin order detail | No |
| | `orders.unpaid_expiring` | Order placed but unpaid, expiry window closing | `commerce.order.manage` | INFO | — | Admin order detail | No |
| | `orders.restock_not_reconciled` | Cancelled order with stock not yet reversed | `commerce.order.manage` | **CRITICAL** | — | Admin order detail + stock adjustment | No |
| | `orders.invoice_missing` | Order paid, but no invoice issued | `commerce.order.manage` | **CRITICAL** | — | Admin order detail → generate invoice | **Yes** (statutory) |
| **Returns** | `returns.awaiting_inspection` | Return received, inspection pending | `commerce.order.manage` | WARNING | 48h | Admin returns detail | No |
| | `returns.awaiting_receipt` | Entitlement held, item not yet received | `commerce.order.manage` | **CRITICAL** | 10d alert / 21d escalation | Admin returns detail | **Yes** (statutory) |
| **Stock** | `stock.low` | Variant below reorder level in any warehouse | `inventory.view` | WARNING | — | Inventory low-stock report | No |
| | `stock.expiring` | Batch expiring within configured window | `inventory.view` | WARNING | — | Inventory batch-expiry report | No |
| | `stock.expired_on_hand` | Batch past expiry with qty > 0 | `inventory.view` | **CRITICAL** | — | Inventory adjustments (write-off) | No |
| | `stock.ledger_drift` | Projection mismatch (ledger ≠ movements) | `inventory.view` | **CRITICAL** | — | Inventory verify + runbook | No |
| | `stock.transfer_in_transit` | Transfer dispatched, not yet received | `inventory.view` | WARNING | 5 days | Inventory transfer detail | No |
| | `stock.grn_draft_stale` | GRN posted, older than draft-age window | `inventory.view` | INFO | 3 days | Inventory GRN detail | No |
| | `stock.po_overdue` | PO sent, expected date past, not fully received | `inventory.view` | INFO | 7 days | Inventory PO detail | No |
| **Money** | `refunds.failed` | Refund intent failed (not GOODS_NOT_RETURNED) | `finance.record` | **CRITICAL** | — | Admin refunds list | No |
| | `refunds.manual_owed` | Refund approved, no payment intent (COD/manual) | `finance.record` | **CRITICAL** | 7 business days | Admin refunds list | No |
| | `refunds.past_promise` | Refund sent/queued >7 business days | `finance.record` | **CRITICAL** | 7 business days | Admin refunds list | **Yes** (statutory) |
| | `payouts.batch_awaiting_approval` | Payout batch created, pending approval | `finance.approve` | WARNING | — | Admin payout batch detail | No |
| | `payouts.batch_partially_failed` | Payout batch has failed or held lines | `finance.approve` | **CRITICAL** | — | Admin payout batch detail | No |
| | `payouts.bank_details_missing` | Distributor has unswept payable income, passes the BV and KYC gates, and has no bank record on file | `finance.record` | WARNING | — | Distributor detail | No |
| | `payments.unreconciled` | Payment captured but order not marked paid | `finance.record` | **CRITICAL** | — | Admin payments list | No |
| **People** | `kyc.pending_review` | KYC submission awaiting review | `kyc.review` | WARNING | 48h | Admin KYC queue | No |
| | `distributor_requests.open` | Registration/update request submitted | `distributor.request.handle` | WARNING | — | Admin request detail | No |
| | `line_change.pending` | Line-change decision pending | `placement.decide` | WARNING | — | Admin line-change screen | No |
| | `adc.applications_pending` | Arete Development Centre application awaiting review | `adc.application.review` | INFO | — | Admin ADC applications screen | No |
| | `distributors.cooling_off_expiring` | Distributor cooling-off period within 7 days | `kyc.review` | INFO | 7 days | Distributor detail | No |
| | `distributors.frozen_stale` | Distributor frozen with no decision for 14 days | `kyc.review` | WARNING | 14 days | Distributor detail | No |
| **Compliance** | `grievance.sla_due_or_breached` | Complaint past ack/response/resolution clock | `grievance.handle` | **CRITICAL** | statutory | Admin grievance detail | **Yes** (statutory) |
| | `grievance.third_party_overdue` | Third-party update >15 days | `grievance.handle` | WARNING | 15 days | Admin grievance detail | No |
| | `messaging.reported_pending` | Message reported and unmoderated | `messaging.moderate` | WARNING | — | Admin messaging screen | No |
| | `content.required_page_unpublished` | Consent-linked page not published | `content.publish` | **CRITICAL** | — | Admin content editor | **Yes** (statutory) |
| | `platform.engine_runs_failed` | Compensation engine run failed or missing | (developer/admin) | **CRITICAL** | — | Admin compensation console | No |
| | `platform.failed_jobs` | Failed jobs in the queue | (developer/admin) | WARNING | — | (count only) | No |

---

## Snoozing and deferral

`POST /admin/action-center/{key}/snooze` — request body:
```json
{
  "subject_type": "order",
  "subject_id": 12345,
  "days": 3,
  "reason": "Waiting for customer callback, will chase on Thursday"
}
```

**Rules:**

- Requires the **provider's own permission** (e.g. `commerce.order.manage` to snooze an order action). The route gates on this.
- `days` is clamped to 1..(admin-configured max, default 30).
- `reason` is required and must be ≥10 characters (free-form explanation for the audit log).
- Snooze expiry is automatic: the query filters `action_center_snoozes.snoozed_until > now()`. No sweep job.
- Snoozed items are still hidden from counts and lists while the snooze is active.
- Un-snooze: `DELETE /admin/action-center/{key}/snooze` with the same subject_type / subject_id. Also audited.

**Why statutory items refuse snooze (422):**

Grievance SLAs, refund promise windows, missing tax invoices, and returns awaiting receipt are non-negotiable commitments to regulators and customers. A manager cannot defer them; they must be resolved. The UI hides the snooze button for statutory actions.

Attempting to snooze a statutory item returns:

```
422 Unprocessable Entity
{
  "error": "Cannot snooze statutory action. This item must be resolved within its SLA."
}
```

Un-snooping works the same way — it succeeds without special permission, as long as the action is not statutory.

---

## Caching and what you see after a fix

The Action Center summary (counts per group and the worst item per group's age/severity) is cached **60 seconds per user** under the key `action_center.summary.{user_id}`.

**What happens after you fix something:**

1. **You fix an order** in Admin → Orders → the order detail page.
2. **Status changes** (e.g. `pending` → `packed`). The order row is saved.
3. **You navigate back to Action Center.** The cache is still valid, so the `orders.paid_not_packed` count still shows it, even though it's no longer actually paid-and-not-packed.
4. **Refresh the page** (browser reload). The cache is now older than 60 seconds (or you snoozed the item, which invalidated the cache immediately). The query runs live and the item disappears.

**To see a change immediately without waiting 60 seconds:**

- **Snooze then un-snooze** the item (if it's not statutory) — this invalidates the cache.
- **Hard-refresh the browser** (Ctrl+Shift+R / Cmd+Shift+R) to clear all caches and force the Action Center to re-query.
- **Wait up to 60 seconds** for the cache to expire naturally.

**Item lists are always fresh:** The `/admin/action-center/{key}` detail page never caches; it always runs live queries.

---

## Feature flag: ActionCenterFeature

The Action Center module (controller, views, routes, providers) ships behind the `ActionCenterFeature` flag (default ON). If disabled, the entire feature is hidden and routes return 404.

### Turning the feature on (already default ON)

If for some reason the flag needs to be re-enabled after a disable:

**Via admin UI:**

- Log in as `admin` (or a role with `admin.*` permissions).
- Navigate to `admin/settings/feature-flags`.
- Find **Action Center** in the list.
- Click the toggle to ON.

**Via code (testing only):**

```php
// In a test
Feature::for(null)->activate(ActionCenterFeature::class);
$this->getJson('/admin/action-center'); // now works
Feature::for(null)->deactivate(ActionCenterFeature::class);
```

### Turning the feature off (emergency only)

Only if a critical bug is discovered that affects production and no hot fix is ready.

**Via admin UI:**

- Log in as `admin`.
- Navigate to `admin/settings/feature-flags`.
- Find **Action Center** in the list.
- Click the toggle to OFF.
- The feature disappears from all menus and routes return 404.
- **Impact:** Providers still run (inventory, refund, grievance modules are unaffected). Only the dashboard and nav link are hidden.

**Via code (testing only):**

```php
Feature::for(null)->deactivate(ActionCenterFeature::class);
```

**Killswitch note:** OFF = the UI is hidden, but no data is deleted or corrupted. Snoozed items remain snoozed until their expiry (the snooze table is not cleared). Turning the flag back ON shows any deferred items that are still within their snooze window.

---

## Settings

Action Center timing thresholds are admin-tunable under Admin → Settings → Operations → (scroll to **Action Center**). All prefixed `action_center.`:

| Setting | Default | Meaning |
|---|---|---|
| `pack_sla_hours` | 24 | Hours from order paid to pack. Orders older than this are WARNING. |
| `ship_sla_hours` | 24 | Hours from order packed to ship. |
| `delivery_chase_days` | 7 | Days from order shipped to delivery alert. |
| `transfer_transit_days` | 5 | Days from transfer dispatched to in-transit alert. |
| `grn_draft_days` | 3 | Days from GRN draft to stale alert. |
| `po_overdue_days` | 7 | Days from PO sent (or expected_at) to overdue alert. |
| `kyc_review_hours` | 48 | Hours from KYC submission to review alert. |
| `max_snooze_days` | 30 | Maximum days a manager may snooze an action. |

All are developer-owned (operations cannot change them without engineering review) because they tie to published SLAs in the Grievance Redressal Policy, Compensation Plan, and Direct Seller Agreement.

---

## Troubleshooting

### An action should be visible but is not

**Check the provider's feature flag:**

- If the action is from the **Stock** group, ensure `InventoryFeature` is ON (default OFF).
- If the action is from any other group, ensure `ActionCenterFeature` is ON (default ON).

**Check permissions:**

- The user must hold the provider's named permission (e.g. `commerce.order.manage` to see orders actions, `finance.record` to see refund actions).
- If the user holds a role but not the permission, they will not see the action group at all.

**Check the condition:**

- The action has a query that matches a specific state (e.g. `orders.packed_not_shipped` requires `status = 'ready_to_ship'`). If no order matches, the count is 0 and the action does not appear.
- Run the provider's test to confirm the boundary condition (see `tests/Feature/ActionCenter/`).

### Snoozed items are still showing

**Check the snooze expiry:**

```bash
php artisan tinker --execute 'DB::table("action_center_snoozes")
  ->where("action_key", "orders.paid_not_packed")
  ->where("subject_type", "order")
  ->where("subject_id", 12345)
  ->first();'
```

If `snoozed_until` is in the past (before `now()`), the item should not appear. If it does, the cache may still be valid. Hard-refresh the browser or wait 60 seconds.

**Check the cache:**

```bash
php artisan tinker --execute 'Cache::tags("action-center")
  ->forget("summary.USER_ID");'
```

Replace USER_ID with the viewer's user.id.

### A count is wrong

**Check the indexed query:**

Each provider's `count()` method runs one indexed query. If the count disagrees with reality, inspect:

1. The database indexes — run `SHOW INDEX FROM <table>` to confirm the expected index exists.
2. The query in the provider — ensure the conditions match the action description.
3. The exclusion of snoozed items — every provider calls `$this->excludeSnoozed($query)` in its count.

Run the provider's test in isolation to reproduce:

```bash
php artisan test tests/Feature/ActionCenter/PaidNotPackedProviderTest --filter "it counts"
```

### Statutory items are snoozable (should not be)

The UI hides the snooze button for statutory actions (`statutory() = true`). If a snooze request succeeds when it should fail, the provider's `statutory()` method is not correctly returning true.

Check the provider class (under `app/Modules/ActionCenter/Providers/`) — the method must return a literal `true`, not a condition.

---

## Development notes

- Each provider extends `AbstractProvider` and implements the `ActionProvider` contract.
- Providers are registered in `ActionCenterServiceProvider` as a tagged array `action-center-providers` in catalogue order.
- The registry filters by permission (user's `->can()`) and by feature flag (`->enabled()`).
- Snooze and un-snooze are handled by `ActionCenterService::snooze()` and `unsnooze()`, which write an audit entry.
- See `docs/architecture/adr-0013-action-center.md` for the design rationale.
