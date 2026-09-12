# Action Center — design + implementation plan

One screen that answers "what needs a human right now", for admins and platform managers.

---

## 0. How to use this document (read first, Claude Code)

- Everything you need is in this file. **Do not grep the repo** beyond the files named in §2.
- Build **one slice (§9) per session**, run only that slice's tests, commit, then start a new session.
- Conventions unchanged: `declare(strict_types=1)`, `final` services, `$fillable`, `casts()`, Larastan
  level 7, Pint, one concern per migration, audit rows for admin writes, `{{ }}` only in Blade.
- The Action Center **reads**. It owns no business state and no status column. The only thing it
  writes is a snooze row and its audit entry. If a fix needs new state, that belongs in the owning
  module, not here.
- Do not add anything not in this document. If something is ambiguous, pick the simpler option and
  note it in the commit body.

### 0.1 Model and session plan

| Slice | Model | Effort | Why |
|---|---|---|---|
| A1 Foundation | **Opus 5** | high | The contract every later slice copies; caching and permission scoping decided once. |
| A2 Orders + stock providers | **Sonnet 5** | medium | Repetitive query classes against a fixed contract. |
| A3 Money providers | **Sonnet 5** | medium | Read-only queries over existing worklists. |
| A4 People, compliance, platform providers | **Sonnet 5** | medium | Same shape again. |
| A5 UI | **Sonnet 5** | medium | Blade + Tailwind against existing admin patterns. |
| A6 Digest + docs | **Haiku 4.5** | low | One command, one notification, prose. |
| A7 Browser spec | **Sonnet 5** | low | Playwright, after A5 is merged and running locally. |

Session rules (token budget): one fresh session per slice; opening prompt
`Read docs/plans/2026-09-12-action-center.md §0, §1, §2, §4 and slice A<n> (§9). Build only A<n>.`;
no subagents; read only §2 files; iterate with `php artisan test tests/Feature/ActionCenter --compact`;
full suite never; Pint + Larastan once before the commit; if the same test fails three times, stop
and report.

---

## 1. Principle: aggregate, never re-derive

Every signal below already exists somewhere — a worklist class, a status column, a scheduled command,
a dashboard counter. The Action Center is a **registry of read-only providers** over those same
tables, plus one page that ranks them. Two consequences:

- No `action_center_items` table, no sync job, nothing to drift. A count is a live query.
- Every row **deep-links to the screen that already fixes it**. The Action Center does not
  re-implement "approve KYC" or "settle refund"; it routes to those screens with filters applied.

The one exception is **snooze** (§5): a manager's statement that an item is known and deferred.

---

## 2. Files Claude Code may read (and nothing else)

Contract and patterns to copy:
- `app/Modules/Inventory/Services/InventoryAlertService.php` — the closest existing shape.
- `app/Modules/Inventory/InventoryServiceProvider.php`, `app/Modules/Shared/Features/InventoryFeature.php`.
- `app/Modules/Inventory/Services/InventorySettings.php` — the settings pattern.
- `app/Modules/Admin/Http/Controllers/AdminDashboardController.php` — existing cards.
- `resources/views/admin/layouts/admin.blade.php` — sidebar, badge markup.
- `resources/views/admin/inventory/reports/*.blade.php` + `stock/index.blade.php` — filter/table UI.
- `routes/web.php` (admin block), `routes/console.php` (schedule block).
- `database/seeders/RolesAndPermissionsSeeder.php` — permission names.
- `app/Modules/Compliance/Models/AuditLog.php` — audit rows.

Sources each provider queries (read the one you need, not all):
- `app/Modules/Payments/Support/RefundWorklist.php`, `InvoiceGapWorklist.php`
- `app/Modules/Inventory/Services/InventoryAlertService.php`, `StockLedger.php`
- `app/Modules/Commerce/Models/Order.php` (status constants), `OrderItem.php`
- `app/Modules/Inventory/Models/{StockMovement,StockBatch,StockTransfer,PurchaseOrder,PurchaseInvoice}.php`
- `app/Modules/Returns/Models/ReturnRequest.php`
- `app/Modules/Compensation/Services/EngineHealthService.php`, payout batch model
- `app/Modules/Grievance/Models/Ticket.php`, `Console/Commands/GrievanceSlaSweepCommand.php`
- `app/Modules/Identity/Models/{Distributor,DistributorRequest}.php`, `app/Modules/Kyc/Models/*`

---

## 3. Domain model

### 3.1 The contract

`app/Modules/ActionCenter/Contracts/ActionProvider.php`:

```php
interface ActionProvider
{
    public function key(): string;            // 'orders.paid_not_packed' — stable, used in routes
    public function group(): string;          // ActionGroup::ORDERS|STOCK|MONEY|PEOPLE|COMPLIANCE|PLATFORM
    public function label(): string;          // 'Paid orders not packed'
    public function description(): string;    // one sentence, what the admin should do
    public function permission(): string;     // e.g. 'commerce.order.manage'
    public function severity(): string;       // Severity::CRITICAL|WARNING|INFO — the type's ceiling
    public function statutory(): bool;        // true = cannot be snoozed (§5)
    public function slaHours(): ?int;         // null = backlog, no clock
    public function count(): int;             // cheap COUNT, no eager loads
    public function items(int $limit = 50): Collection; // ActionItem list, oldest/most overdue first
    public function targetRoute(): ?string;   // existing screen this type routes to, if any
}
```

`ActionItem` is a readonly DTO: `subjectType`, `subjectId`, `title`, `subtitle`, `occurredAt`,
`dueAt` (nullable), `severity`, `url` (deep link to the fixing screen), `meta` (array, small).

**Rules for providers** — every provider must:
1. Return counts from a single indexed query; no N+1, no model hydration in `count()`.
2. Exclude snoozed subjects (the base class does this — call `parent::excludeSnoozed($query)`).
3. Be pure reads. A provider never writes.
4. Degrade quietly: a provider whose feature flag is off (e.g. inventory) returns 0 and is hidden.

### 3.2 One new table

`action_center_snoozes` — id, `action_key` (64), `subject_type` (64), `subject_id` (unsigned big),
`snoozed_until` (datetime(3)), `reason` (text, required), `actor_user_id` (FK users, nullOnDelete),
`created_at`/`updated_at` (datetime(3)). Unique (`action_key`,`subject_type`,`subject_id`).
Index (`action_key`,`snoozed_until`).

### 3.3 Settings (`InventorySettings` pattern, prefix `action_center.`)

| Key | Default | Meaning |
|---|---|---|
| `action_center.pack_sla_hours` | 24 | paid → packed |
| `action_center.ship_sla_hours` | 24 | packed → shipped |
| `action_center.delivery_chase_days` | 7 | shipped → delivered |
| `action_center.transfer_transit_days` | 5 | dispatched → received |
| `action_center.grn_draft_days` | 3 | GRN drafted, unposted |
| `action_center.po_overdue_days` | 7 | PO sent, nothing received |
| `action_center.kyc_review_hours` | 48 | KYC waiting |
| `action_center.max_snooze_days` | 30 | cap on a snooze |

---

## 4. The action catalogue (v1)

Severity is the type's ceiling; an item past its SLA is promoted to the next level up.
"Fix at" is the existing screen the row deep-links to — build no new fixing screens.

### Orders & fulfilment — permission `commerce.order.manage`

| Key | Condition | Sev | SLA | Fix at |
|---|---|---|---|---|
| `orders.paid_not_packed` | `status=paid`, `packed_at` null, `paid_at` older than pack SLA | warning | 24h | admin order show |
| `orders.packed_not_shipped` | `status=ready_to_ship`, `packed_at` older than ship SLA | warning | 24h | admin order show |
| `orders.shipped_not_delivered` | `status=shipped`, `shipped_at` older than chase days | warning | 7d | admin order show |
| `orders.unpaid_expiring` | `status=placed`, `paid_at` null, `placed_at` older than the payment-expiry window | info | — | admin order show |
| `orders.restock_not_reconciled` | order `cancelled`/`refunded`, has `sale_out` movements for its items with no netting `sale_reversal` | **critical** | — | admin order show (+ stock adjustment) |
| `returns.awaiting_inspection` | `return_requests.received_at` not null, no `return_inspections` row | warning | 48h | admin returns show |
| `returns.awaiting_receipt` | `entitlements_held_at` not null, `received_at` null | **critical** | 10d alert / 21d escalation | admin returns show |
| `orders.invoice_missing` | `orders.paid_at` not null, no `invoices` row | **critical** (statutory) | — | admin payments → generate invoice |

### Stock — permission `inventory.view` (act with `inventory.manage`), hidden when `InventoryFeature` off

| Key | Condition | Sev | SLA | Fix at |
|---|---|---|---|---|
| `stock.low` | `InventoryAlertService::lowStock()` | warning | — | low-stock report |
| `stock.expiring` | `expiring(settings days)` | warning | — | batch-expiry report |
| `stock.expired_on_hand` | `expired()` with qty > 0 | **critical** | — | adjustments (write-off) |
| `stock.ledger_drift` | projection ≠ Σ movements (the `inventory:verify` query) | **critical** | — | stock index + runbook |
| `stock.transfer_in_transit` | `stock_transfers.dispatched_at` older than transit days, `received_at` null | warning | 5d | transfer show |
| `stock.grn_draft_stale` | `purchase_invoices` draft older than GRN draft days | info | 3d | GRN show |
| `stock.po_overdue` | PO `sent`, `expected_at` past (or sent older than PO overdue days), not fully received | info | 7d | PO show |

### Money — permissions `finance.record`, `finance.approve`

| Key | Condition | Sev | SLA | Fix at |
|---|---|---|---|---|
| `refunds.failed` | `RefundWorklist` failed intents (excluding GOODS_NOT_RETURNED) | **critical** | — | admin refunds |
| `refunds.manual_owed` | `refund_approved` with no intent (COD/manual) | **critical** | 7 business days | admin refunds |
| `refunds.past_promise` | sent/queued older than 7 business days | **critical** (statutory) | 7 business days | admin refunds |
| `payouts.batch_awaiting_approval` | payout batch created, not approved | warning | — | payout batch show |
| `payouts.batch_partially_failed` | batch status `partially_failed`, or held lines | **critical** | — | payout batch show |
| `payouts.bank_details_missing` | distributor with payable balance and no usable bank record | warning | — | distributor show |
| `payments.unreconciled` | captured intent whose order is not `paid` (the reconcile gap) | **critical** | — | admin payments |

### People — permissions `kyc.review`, `distributor.request.handle`, `placement.decide`, `adc.application.review`

| Key | Condition | Sev | SLA | Fix at |
|---|---|---|---|---|
| `kyc.pending_review` | submission awaiting review, older than KYC hours | warning | 48h | KYC show |
| `distributor_requests.open` | status submitted/under review | warning | — | request show |
| `line_change.pending` | pending line-change decisions | warning | — | line-change screen |
| `adc.applications_pending` | application awaiting review | info | — | applications screen |
| `distributors.cooling_off_expiring` | active, `cooling_off_end_at` within 7 days | info | 7d | distributor show |
| `distributors.frozen_stale` | `users.status=frozen` with no decision for 14 days | warning | 14d | distributor show |

### Compliance & platform — `grievance.handle`, `messaging.moderate`, `content.publish`, admin/developer

| Key | Condition | Sev | SLA | Fix at |
|---|---|---|---|---|
| `grievance.sla_due_or_breached` | unsettled tickets past ack/first-response/resolution clocks | **critical** (statutory) | statutory | grievance show |
| `grievance.third_party_overdue` | third-party update older than 15 days | warning | 15d | grievance show |
| `messaging.reported_pending` | reported messages unmoderated | warning | — | messaging screen |
| `content.required_page_unpublished` | a consent-linked page not published | **critical** | — | content editor |
| `platform.engine_runs_failed` | `EngineHealthService` failed / missing / stuck runs | **critical** | — | compensation console |
| `platform.failed_jobs` | `failed_jobs` rows | warning | — | (count only, no screen) |

**Not in v1** (write them down so they are not re-litigated): fraud/velocity scoring, duplicate-PAN
heuristics, GST return filing status, courier-API exception feeds, e-way-bill expiry. Each needs data
the platform does not hold yet.

---

## 5. Snooze (the only write)

- `POST /admin/action-center/{key}/snooze` — body: `subject_type`, `subject_id`, `days` (1..max),
  `reason` (required, min 10 chars). Requires the **provider's own permission**, not a global one.
- Writes the row + an `AuditLog` entry (`action_center.snoozed`, subject = the underlying subject,
  details = key, until, reason). Un-snooze (`DELETE`) writes `action_center.unsnoozed`.
- A provider whose `statutory()` is true **refuses to snooze** (422). Statutory clocks are not a
  manager's to silence: grievance SLA, refund promise window, invoice gaps, returns awaiting receipt.
- Snoozes expire on their own. Nothing sweeps them; the query filters on `snoozed_until > now()`.

---

## 6. Service, registry, caching

- `ActionCenterRegistry` — providers registered in `ActionCenterServiceProvider` as a tagged array,
  in catalogue order. `for(User $user): Collection` filters by `$user->can($provider->permission())`
  and by feature flags.
- `ActionCenterService::summary(User $user): Collection` — per group: providers with counts,
  severities, the worst item age. Counts are cached **60 seconds** under
  `action_center.summary.{user_id}` (cache store is Redis in this app). `items()` is never cached.
- `ActionCenterService::snooze()/unsnooze()` — the §5 rules, inside a transaction with the audit row.
- Every count query must have an index. Add in one migration if missing:
  `orders (status, packed_at)`, `orders (status, shipped_at)`, `stock_transfers (dispatched_at, received_at)`.

---

## 7. UI

- **`/admin/action-center`** (`action.center.view`, granted to admin, developer, admin-operations,
  admin-finance, admin-compliance): groups as cards; each row = label, count, severity dot, oldest
  item age; click → type page. Critical group first. Empty state: "Nothing needs action right now."
- **`/admin/action-center/{key}`**: the type's items, oldest first, with severity, age, due-at,
  a "Fix →" deep link and a "Snooze" control (hidden for statutory types). Filters: severity, age
  bucket, and warehouse for stock types. 50 per page.
- **Sidebar**: an "Action Center" link at the top of the admin nav with a count badge of the viewer's
  **critical** items (cached with the summary). Zero = no badge, not a grey zero.
- **Dashboard**: one card, the five oldest critical items across all groups the viewer may see.
- Copy rules: state the fact and the age. No urgency theatre, no income figures, nothing about
  earnings (hard rule 3).

---

## 8. Email digest — deferred

A daily digest email was scoped and **deferred by KP on 2026-09-12**; it is not part of this build.
When it is planned, it belongs here: one command, one notification, a recipients setting, silent when
every group is empty. The settings key `action_center.digest_recipients` in §3.3 is therefore **not**
added yet — leave it out until the digest is planned.

---

## 9. Build slices

| Slice | Model | Scope | Tests |
|---|---|---|---|
| **A1 Foundation** | Opus 5 | Module skeleton, `ActionProvider` contract, `ActionItem` DTO, `ActionGroup`/`Severity`, `AbstractProvider` (snooze exclusion, severity promotion, age helpers), `ActionCenterRegistry`, `ActionCenterService` (summary + caching + snooze/unsnooze), `action_center_snoozes` migration + model, index migration (§6), `ActionCenterSettings`, `ActionCenterFeature` flag, `action.center.view` permission (seeder + roles), service provider registration, **and exactly one provider end-to-end** (`orders.paid_not_packed`) as the reference implementation. | `RegistryPermissionScopingTest`, `SnoozeTest` (writes audit, blocks statutory, caps days, expires), `SummaryCacheTest`, `PaidNotPackedProviderTest` |
| **A2 Orders + stock** | Sonnet 5 | The remaining 7 order/returns providers and all 7 stock providers from §4. | One test per provider asserting the boundary condition (just inside / just outside the SLA) + `RestockNotReconciledTest` (the netting query) |
| **A3 Money** | Sonnet 5 | The 7 money providers, reusing `RefundWorklist` and `InvoiceGapWorklist` rather than rewriting their queries. | One test per provider; `RefundsPastPromiseTest` must use business days |
| **A4 People, compliance, platform** | Sonnet 5 | The 6 people + 6 compliance/platform providers. | One test per provider; `GrievanceSlaProviderTest` must agree with the sweep command's clocks |
| **A5 UI** | Sonnet 5 | Controller, index + type views, filters, snooze form, sidebar badge, dashboard card, routes, permission gates. | `ActionCenterScreensTest` (200 + permission scoping per role), `SnoozeUiTest`, `BadgeCountTest` |
| **A6 Docs** | Haiku 4.5 | Settings screen rows for §3.3, `docs/roadmap.md` section, `docs/architecture/adr-0013-action-center.md`, `docs/runbooks/action-center.md` (what each action means and where it is fixed). No digest — deferred, see §8. | — |
| **A7 Browser spec** | Sonnet 5 | `tests/Browser/action-center.spec.js` — page renders, a type page lists items, a snooze hides a row, a statutory type shows no snooze control. | Playwright, run locally |

Sequencing: A1 → (A2, A3, A4 in any order) → A5 → A6 → A7.
Estimate ≈ 45 new files, ≈ 8 edited, ≈ 20 test files.

---

## 10. Decisions taken (do not re-litigate mid-build)

1. **Live queries, no materialised alert table.** Nothing to sync, nothing to drift. The cost is
   query load on one screen; the 60-second summary cache covers it.
2. **Permission-scoped, not role-scoped.** A provider names the permission that may act on it; the
   viewer sees exactly what they can fix. Finance never sees a KYC queue they cannot clear.
3. **Deep links, not new actions.** The Action Center routes to the screens that already work. The
   only write it owns is snooze.
4. **Statutory items cannot be snoozed.** Grievance SLAs, the refund promise window, missing tax
   invoices and returns awaiting receipt stay visible until they are actually resolved.
5. **Silence means nothing to do.** No digest email when every group is empty; no zero badges.
6. **Severity is derived, not stored.** A type has a ceiling; an item past its SLA is promoted. No
   one maintains a priority column.
