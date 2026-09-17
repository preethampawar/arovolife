# Order Fulfilment — Courier Integration, Manual Dispatch and Centre Collection

**Date:** 2026-09-18
**Closes:** R-94 outright. R-47 when Slice 7 lands **with** declaration v3.
**Reopens:** R-21 — its closure rested on a permission the declaration does not contain (ruling below).
**Moves, does not close:** R-24 — only if the ADC bonus is gated on a recorded handover (H5).
**Compliance status:** reviewed 2026-09-18 — **FAIL**, 5 High. Slice 2 blocked on H3, Slice 7 blocked on H1/H2/H4/H5. Slices 1, 3, 4, 5, 6, 8 clear.
**Client decisions taken 2026-09-18:** no Shiprocket account yet; core dispatch
slice only; collection fee is a new admin setting defaulting to ₹0; the admin
picks the dispatch route per order.

---

## Goal

When this is done, a buyer who chooses "Collect from Arete Centre" at checkout
gets what the screen promised: they are not charged a delivery fee for a
delivery that never happens, the centre's postal address is never presented to
them as their own shipping address, the parcel is consigned to the centre and
tracked, the centre acknowledges receiving it, the buyer is told it is ready,
and the handover is recorded against the order as the evidence the ADC
commission is paid for. A buyer who chooses home delivery gets a real courier
leg — pushed to Shiprocket or recorded manually, at the admin's choice per
order — with carrier, AWB and live tracking visible to them and to staff.

The Fulfilment module stops being a three-file stub and becomes the module that
owns dispatch.

## Non-goals

- **Live Shiprocket traffic.** There is no account. `ShiprocketGateway::permitted()`
  returns false without credentials and the feature flag ships OFF. Every test
  runs against a recorded fake. Going live is a later, credentialled step.
- Serviceability and rate lookup at checkout. The flat `ShippingService` fee stays.
- Pickup scheduling, NDR workflows, RTO reconciliation into the Returns pipeline.
- Multi-parcel / partial shipments. One shipment per order, as today.
- COD. The `payment_method` enum was deliberately narrowed to `online`.
- Changing `ShippingService`'s flat-fee or free-threshold model.
- E-way bill, shipping-label printing beyond storing the gateway's label URL.

---

## Compliance ruling (2026-09-18) — read before Slice 2 or Slice 7

The contradiction between R-21 and R-47 was put to the compliance officer and
**R-47 is correct**. The declaration text, `AreteCenterDeclarations.php:23`
(VERSION `v2`), reads:

> "I will use the centre **only** for training, product demonstration and
> distributor support — **not as a retail store, outlet or e-commerce
> fulfilment point** (Direct Seller Agreement §9)."

Two limbs, and the collection flow fails both: the positive list is exhaustive
and receiving a parcel for a buyer is none of the three things on it; and
"e-commerce fulfilment point" is the express words, not a paraphrase. R-21's
claim that the declaration permits "an online order whose buyer chose it as a
collection point" is **not in the text**. R-21 therefore **reopens** — its
closure rested on a permission that does not exist.

**Hard rule 7 itself is NOT breached.** The operative contract text is
`terms.md` §5.1 and §5.2, not §9. §5.2(1) is not engaged (the order is placed on
arovolife's own platform and ADN-attributed at purchase); §5.2(2) is not engaged
because a sealed consignment for one identified, already-paid buyer is not
"stock … for over-the-counter resale". A collection point is not offline retail,
provided there is no assortment, no price, no bill, no payment and no choice at
the premises. What the design breaches is the **declaration** — which is the
company's stated evidence that hard rule 7 is observed. Breaching your evidence
does not breach the rule; it destroys the defence.

### Blocking remediation

**H1 + H2 — declaration v3 and per-centre re-acceptance (gates Slice 7).**
Bump `VERSION` to `v3`, reword `training_use_only` to permit supervised
collection of orders already placed and paid on arovolife's own platform while
expressly retaining the no-stock / no-price / no-bill / no-payment / no-other-
channel prohibitions, and add a sixth `buyer_data_duty` declaration. Fix the
citation while there: it cites DSA §9 (PII Handling); the right sections are
§5.1 and §5.2 (**M5**).
**A version bump alone changes nothing for existing centres.** Declarations are
keyed to an *application* with a unique index on `(application_id,
declaration_key)`, so v3 cannot even be recorded against a live centre — and
`AdminAreteCenterController::store()` creates centres at `STATUS_ACTIVE` with no
application, so those centres have **zero declaration rows in any version**.
Required: a new `arete_center_declarations` table keyed on `center_id`, and a
hard gate that no centre receives a first consignment until v3 is recorded
against **that centre**. Routing parcels to operators who have undertaken not to
receive them is the company inducing breach of its own undertaking — worse for
the Rule 4 defence than having no declaration at all.

**H3 — collection orders would be mis-taxed (gates Slice 2).**
`InvoiceGenerator.php:58` is `$placeOfSupply = strtoupper($order->ship_state ?? $sellerState);`.
AD-2 nulls `ship_state` for collection orders, so place of supply silently falls
back to the **seller's** state, `$isIntraState` becomes true, and every
inter-state collection order is invoiced CGST/SGST where IGST is due — frozen at
checkout, so "render from the centre at display time" cannot repair it. Derive
place of supply from the **centre's** state when `delivery_type = 'collect'`.
Add `InvoiceGenerator.php` and `invoice.blade.php` to Slice 2.

**H4 — DPDP notice (gates Slice 7).** Slice 7 discloses buyer name and phone to
an independent distributor. `privacy.md` §7 lists no ADC-operator recipient and
§4 has no fulfilment-by-centre purpose, while the policy promises sharing occurs
"only under written agreements that require equivalent protection". Add the
recipients row and the purpose, publish under the §16.2 notice mechanism used
for R-65, and name in the permission matrix exactly which buyer fields the
operator sees (**L1**).

**H5 — the handover record must be load-bearing (gates Slice 7).**
`AreteDevelopmentCenterBonusService.php:83-93` sums BV joined on
`orders.arete_center_id` and counts orders by `placed_at`. Nothing reads a
shipment. As written, this plan writes a handover record that the money never
consults — a centre is still paid 3% on an order never consigned, never
acknowledged and never collected. Gate the monthly BV sum on
`collected_at IS NOT NULL` for that centre. Add the service to Slice 7.
R-22 is untouched by this plan: there is still no `adc_bonus_result_orders`
table, so a frozen result still cannot name the sales or the handovers it paid
against.

**Consequence for the permission matrix.** Once H5 lands, the centre-operator
surface **is** a money surface, so the matrix note below that it is "not a money
surface" is deleted. It stays acceptable under the uncapped-centres problem
(R-24) only because the authenticator originates with the **buyer**: the
operator cannot manufacture a collection code.

## Architecture decisions

**AD-1 — A real `orders.delivery_type` column, not inference from `arete_center_id`.**
Today `arete_center_id IS NOT NULL` is the only signal that a buyer chose
collection. That is fragile in a way that matters: the FK is `nullOnDelete`, so
deactivating and deleting a centre silently converts a collection order into a
shipping order with a forged address. An explicit `enum('ship','collect')`
records the buyer's choice and survives the centre.
*Alternative considered:* infer from the centre id. Rejected for the above.

**AD-2 — Stop writing the centre's address into `orders.ship_*`.**
This is the heart of R-47. For a collection order the `ship_*` columns stay
null and every surface renders "Collect from &lt;centre&gt;" instead of an
address the buyer never gave labelled "Shipping to". **The tax invoice is the
exception and keeps the centre address**, because under GST the place of supply
for goods is where delivery terminates — but it is rendered from
`arete_center_id` at display time and labelled "Collection point", not copied
into the order as the buyer's address.
*Alternative considered:* keep the copy and relabel the UI. Rejected: the data
would still assert something false, and `RefundOrder` and the address book both
read those columns.
**⚠️ Tax consequence (H3), non-obvious and blocking.** `InvoiceGenerator` reads
`$order->ship_state ?? $sellerState` for place of supply. Nulling `ship_state`
therefore silently invoices every inter-state collection order as intra-state.
`InvoiceGenerator::generate()` must branch on `delivery_type` and take the
centre's state. Test: an inter-state collection order produces IGST.

**AD-3 — The collection fee is its own column and its own setting.**
`orders.collection_fee_paise`, fed by a new admin-owned
`commerce.collection_fee_rupees` defaulting to **0**. Not a reuse of
`shipping_paise`, because `ProfitReportService` aggregates that as
`shipping_collected_paise` and `RefundOrder` refunds it on cooling-off; merging
the two would corrupt both. With the default at ₹0 this *is* the R-94 fix — a
collection order stops paying for a delivery — while leaving the client a lever
to charge a handling fee later without a deploy.

**AD-4 — Courier behind a contract, shaped exactly like the Razorpay gateway.**
`CourierGateway` interface; `ManualCourier` (always permitted, records what the
admin typed — this is today's behaviour, preserved); `ShiprocketGateway` (flag +
credential gated). A `CourierGatewayResolver` picks. Secrets in
`config/arovolife.php`, business levers in the `settings` table, every API call
audited to a `shipment_events` table with a `(gateway, gateway_event_id)` unique
index, store-then-queue webhooks. This is a proven pattern in this codebase and
it means the whole feature ships and is testable with no Shiprocket account.

**AD-5 — A collection order still has a courier leg.**
The parcel is consigned *to the centre*, not to the buyer. So collection orders
use the same dispatch routing; only the consignee differs. This is what keeps
the centre a collection point rather than a shop: it receives a sealed
consignment for one identified buyer, holds no sellable stock, and bills nothing.

**AD-6 — Handover proof reuses the existing, never-written `pod_hash_sha256`.**
The column exists on `shipments` and nothing reads or writes it.
**Corrected after review (M1, M2).** The plan originally added a second
`handover_otp_hash` column while also saying not to — there is one proof column,
the existing `pod_hash_sha256`, and file #2 does not add another.
And a bare `sha256(order_no|otp|collected_at|centre_id)` **cannot verify
anything**: it embeds `collected_at`, so it is a post-hoc receipt, not a check on
the code the operator types. Verification goes through the existing
`Shared\Otp\OtpService` — the project's single source of truth for OTPs, which
already hashes, TTLs, caps attempts at 5 and compares in constant time — issued
as `OtpService::issue('order_collection', "order:{$id}")`. The persisted receipt
in `pod_hash_sha256` becomes an **HMAC keyed on an app secret**, not a bare
sha256: an unsalted hash over a 6-digit code is brute-forceable in 10^6 tries by
anyone with DB read, and forgeable by anyone with DB write. The code never
enters `shipment_events.payload` or any log. This record is the "centre-acknowledged handover
record as the evidence the commission is paid against" that R-47 requires, and
it repairs the R-24 rationale that the ADC bonus is consideration for recorded
fulfilment work.

**AD-7 — One new order status: `awaiting_collection`.**
Between `shipped` and `delivered`. The buyer-visible "ready to collect" state is
the single most important notification in the flow and there is nowhere to put
it otherwise. `delivered_at` becomes the collection time, so the 30-day
per-order cooling-off clock starts correctly.
**⚠️ This is a MySQL enum widen.** Per the standing note on enum changes, the
migration needs a non-MySQL branch — SQLite renders the enum as a `CHECK`
constraint and tests run on SQLite. Follow the pattern in
`app/Modules/Returns/Database/Migrations/2026_06_18_000001_add_refund_approved_and_reconcile_returns.php`,
which already widened this exact column.

**AD-8 — The dispatch route is chosen per order by an operator, never inferred.**
A dispatch queue lists `ready_to_ship` orders; the operator picks Manual or
Shiprocket. No pincode rules, no automatic fallback. Degrades gracefully when
Shiprocket is down or unconfigured, and every choice is attributable.

---

## Permission matrix

| Capability | Buyer (own order) | Centre operator (assigned distributor) | admin-support | admin-operations | admin-finance | developer |
|---|---|---|---|---|---|---|
| See own order's carrier / AWB / tracking | ✅ own only | ❌ | ✅ | ✅ | ✅ | ✅ |
| See "ready for collection" + collection OTP | ✅ own only | ❌ (sees order no, not OTP) | ✅ | ✅ | ✅ | ✅ |
| View admin dispatch queue | ❌ | ❌ | ✅ read-only | ✅ | ❌ | ✅ |
| Record manual dispatch (carrier + AWB) | ❌ | ❌ | ❌ | ✅ `commerce.order.manage` | ❌ | ✅ |
| Push shipment to Shiprocket | ❌ | ❌ | ❌ | ✅ `commerce.order.manage` | ❌ | ✅ |
| Fetch / re-fetch label | ❌ | ❌ | ❌ | ✅ `commerce.order.manage` | ❌ | ✅ |
| Centre acknowledges consignment received | ❌ | ✅ own centre only | ❌ | ✅ (override) | ❌ | ✅ |
| Record buyer collection (handover) | ❌ | ✅ own centre only | ❌ | ✅ (override) | ❌ | ✅ |
| See the centre's inbound consignment list | ❌ | ✅ own centre only | ✅ | ✅ | ❌ | ✅ |
| Edit `commerce.collection_fee_rupees` | ❌ | ❌ | ❌ | ✅ (admin-owned) | ❌ | ✅ |
| Edit `fulfilment.shiprocket.*` settings | ❌ | ❌ | ❌ | ❌ (404) | ❌ | ✅ |
| Toggle the Shiprocket feature flag | ❌ | ❌ | ❌ | ❌ | ❌ | ✅ |
| Receive the Shiprocket webhook | n/a — unauthenticated route, gated by HMAC signature only | | | | | |

**Centre-operator scope is ownership, not role.** "Own centre" means
`arete_centers.assigned_distributor_id === auth()->user()->distributor->id`.
That is a policy check on the model, never a role name — and note **R-24 is
still open**: one distributor may own unlimited centres, so this policy grants
access to every centre they are assigned. That is acceptable here (a wider
handover surface is not a money surface) but it must be stated in the policy
docblock so it is not mistaken for a cap.

---

## File changes

| # | Path (relative to `app/`) | New/Mod | Change summary |
|---|---|---|---|
| 1 | `app/Modules/Commerce/Database/Migrations/2026_09_18_100000_add_delivery_type_and_collection_fee_to_orders.php` | New | `delivery_type` enum, `collection_fee_paise`, backfill, `awaiting_collection` status widen (MySQL + SQLite branches) |
| 2 | `app/Modules/Fulfilment/Database/Migrations/2026_09_18_100001_extend_shipments_for_courier_and_collection.php` | New | Shipment gateway/label/centre/handover columns + unique order index |
| 3 | `app/Modules/Fulfilment/Database/Migrations/2026_09_18_100002_create_shipment_events_table.php` | New | Audited courier API/webhook log, mirrors `payment_events` |
| 4 | `app/Modules/Commerce/Models/Order.php` | Mod | Fillable + casts + `DELIVERY_*`/`STATUS_AWAITING_COLLECTION` consts, `shipment()` relation, `isCollection()` |
| 5 | `app/Modules/Fulfilment/Models/Shipment.php` | Mod | New fillable/casts/consts, `areteCenter()` relation, `STATUS_AT_CENTRE` |
| 6 | `app/Modules/Fulfilment/Models/ShipmentEvent.php` | New | Audit model |
| 7 | `app/Modules/Commerce/Support/OrderStatusBadge.php` | Mod | Label + colour + filter chip for `awaiting_collection` |
| 8 | `app/Modules/Admin/Http/Controllers/AdminSettingsController.php` | Mod | Registry entries + `fulfilment` group + `GROUP_OWNERS` entry |
| 9 | `app/Modules/Shared/Features/ShiprocketFulfilmentFeature.php` | New | Pennant flag, default false |
| 10 | `app/Modules/Admin/Http/Controllers/AdminFeatureFlagController.php` | Mod | Registry entry for the flag |
| 11 | `database/seeders/ProductionSeeder.php` | Mod | Seed the two new settings (strictly additive `firstOrCreate`) |
| 12 | `database/seeders/CommerceFeatureFlagSeeder.php` | Mod | Same keys for dev |
| 13 | `app/Modules/Commerce/Services/ShippingService.php` | Mod | `collectionFeePaise()`; `feePaise()` docblock states it is delivery-only |
| 14 | `app/Modules/Commerce/Services/CheckoutService.php` | Mod | `place()` takes `deliveryType`; fee branch; stop forging `ship_*` |
| 15 | `app/Modules/Commerce/Http/Controllers/Storefront/CheckoutController.php` | Mod | Pass delivery type; stop synthesising the centre address; pass both fees to the view |
| 16 | `resources/views/shop/checkout.blade.php` | Mod | Live fee swap in the summary on toggle; "no delivery fee" note |
| 17 | `app/Modules/Commerce/Services/ProfitReportService.php` | Mod | Aggregate `collection_fee_collected_paise` |
| 18 | `resources/views/admin/reports/profit/summary.blade.php` | Mod | Render the new line |
| 19 | `app/Modules/Returns/Services/RefundOrder.php` | Mod | Refund the collection fee on cooling-off, same rule as shipping |
| 20 | `app/Modules/Fulfilment/Contracts/CourierGateway.php` | New | Interface |
| 21 | `app/Modules/Fulfilment/Data/CourierShipment.php` | New | Typed result object |
| 22 | `app/Modules/Fulfilment/Services/ManualCourier.php` | New | Records admin-entered carrier/AWB |
| 23 | `app/Modules/Fulfilment/Services/ShiprocketClient.php` | New | Thin audited HTTP client |
| 24 | `app/Modules/Fulfilment/Services/ShiprocketGateway.php` | New | Contract impl |
| 25 | `app/Modules/Fulfilment/Services/CourierGatewayResolver.php` | New | Route selection |
| 26 | `app/Modules/Fulfilment/Services/DispatchService.php` | New | Orchestrates pack → consign → AWB |
| 27 | `app/Modules/Fulfilment/Services/CollectionHandoverService.php` | New | Consign-to-centre, acknowledge, OTP handover |
| 28 | `app/Modules/Fulfilment/Support/FulfilmentSettings.php` | New | Cached settings reader |
| 29 | `app/Modules/Fulfilment/Support/ShiprocketPayloadScrubber.php` | New | PII redaction before persistence |
| 30 | `app/Modules/Fulfilment/Exceptions/ShiprocketApiException.php` | New | |
| 31 | `app/Modules/Fulfilment/FulfilmentServiceProvider.php` | Mod | Register singletons |
| 32 | `config/arovolife.php` | Mod | `fulfilment.shiprocket` credentials block |
| 33 | `.env.example` | Mod | The four Shiprocket keys, blank |
| 34 | `app/Modules/Fulfilment/Http/Controllers/Admin/AdminDispatchController.php` | New | Queue + route actions |
| 35 | `resources/views/admin/fulfilment/dispatch-queue.blade.php` | New | The queue |
| 36 | `resources/views/admin/commerce/orders-show.blade.php` | Mod | Centre panel, route picker, label link, tracking |
| 37 | `resources/views/admin/commerce/orders-index.blade.php` | Mod | Delivery-type column + `awaiting_collection` chip |
| 38 | `app/Modules/Commerce/Services/OrderStateMachine.php` | Mod | `markAwaitingCollection()`; `markDelivered()` accepts the new from-state |
| 39 | `app/Modules/Fulfilment/Http/Controllers/CentreConsignmentController.php` | New | Centre-operator surface |
| 40 | `app/Modules/Fulfilment/Policies/ShipmentPolicy.php` | New | Ownership checks |
| 41 | `resources/views/my/arete-centre/consignments.blade.php` | New | Centre inbound list + acknowledge/handover |
| 42 | `app/Modules/Fulfilment/Http/Controllers/ShiprocketWebhookController.php` | New | Signature-verified, store-then-queue |
| 43 | `app/Modules/Fulfilment/Jobs/ProcessShiprocketWebhookJob.php` | New | Applies the event |
| 44 | `routes/web.php` | Mod | Admin dispatch, centre consignment, webhook routes |
| 45 | `app/Modules/Commerce/Notifications/OrderReadyForCollectionNotification.php` | New | With the collection OTP |
| 46 | `app/Modules/Commerce/Notifications/OrderDispatchedNotification.php` | New | Carrier, AWB, tracking link |
| 47 | `resources/views/emails/order-ready-for-collection.blade.php` | New | |
| 48 | `resources/views/emails/order-dispatched.blade.php` | New | |
| 49 | `resources/views/shop/orders/show.blade.php` | Mod | Collection panel; tracking link |
| 50 | `resources/views/shop/orders/_timeline.blade.php` | Mod | Collection steps |
| 51 | `app/Modules/ActionCenter/Providers/Orders/AtCentreNotCollectedProvider.php` | New | Parcels sitting at a centre |
| 52 | `resources/help/order-fulfilment.md` | New | Staff help page |
| 53 | `resources/help/status-reference.md` | Mod | Replace "(Scaffolded — later phases.)" |
| 54 | `docs/runbooks/shiprocket.md` | New | Credentials, modes, webhook, failure triage |
| 55 | `docs/architecture/adr-0016-courier-gateway.md` | New | AD-4 and AD-5 |
| 56 | `docs/compliance/risk-register.md` | Mod | R-47, R-94, R-21, R-24 |
| 57 | `docs/roadmap.md` | Mod | Move R-47 out of "deliberately not built" |

### Files added by the compliance review (2026-09-18)

| # | Path (relative to `app/`) | New/Mod | Change summary | Slice |
|---|---|---|---|---|
| 58 | `app/Modules/Tax/Services/InvoiceGenerator.php` | Mod | **H3** — place of supply from the centre's state when `delivery_type = 'collect'` | 2 |
| 59 | `resources/views/shop/orders/invoice.blade.php` | Mod | **H3** — render the centre as "Place of supply / collection point"; never a blank address | 2 |
| 60 | `app/Modules/Compensation/Support/AreteCenterDeclarations.php` | Mod | **H1/M5** — `VERSION` → `v3`, reworded `training_use_only`, new `buyer_data_duty`, citation §9 → §5.1/§5.2 | 7 |
| 61 | `app/Modules/Compensation/Database/Migrations/2026_09_18_100003_create_arete_center_declarations_table.php` | New | **H2** — per-**centre** declarations (`center_id`, key, version, accepted_at, ip); the existing table is keyed to an application and its unique index blocks a re-acceptance | 7 |
| 62 | `app/Modules/Compensation/Services/AreteCenterApplicationService.php` | Mod | **H2** — write per-centre rows at approval as well as per-application | 7 |
| 63 | `app/Modules/Compensation/Http/Controllers/Admin/AdminAreteCenterController.php` | Mod | **H2** — admin-created centres (`store()` goes straight to `STATUS_ACTIVE` with no application, hence no declaration in any version) cannot receive a consignment until the assigned distributor accepts v3 | 7 |
| 64 | `app/Modules/Compensation/Services/AreteDevelopmentCenterBonusService.php` | Mod | **H5** — gate the monthly BV sum and order count on `collected_at IS NOT NULL` for that centre | 7 |
| 65 | `database/seeders/content/privacy.md` | Mod | **H4** — ADC-operator recipients row + §4 fulfilment-by-centre purpose | 7 |
| 66 | `database/seeders/content/returns.md` | Mod | **M3** — the collection fee is refundable on cooling-off only, matching #19 | 9 |
| 67 | `app/Modules/Fulfilment/Services/CollectionHandoverService.php` | Mod of #27 | **M2** — verification through `Shared\Otp\OtpService`; receipt is an HMAC, not a bare sha256 | 7 |
| 68 | settings (#8) | Mod | **M4** — `fulfilment.max_dwell_days` (admin-owned, default 15, matching the DSA §5.4 return window) with automatic return-to-origin; state the limit in the v3 declaration | 7 |

**⚠️ Content pages need an explicit publish.** `privacy.md` and `returns.md` are
seeded content: `seedAsDraft()` skips any slug whose row already exists, so
editing the markdown changes **nothing live**. `php artisan content:publish
<slug>` must run per environment, it is audit-logged as
`content_page.republished`, and it does **not** snapshot the old body. Back the
row up first. This is a destructive step and needs its own confirmation.

### Per-file detail

*(Detail blocks for files 1–12 are written out below; blocks 13–57 follow the
same form and are written before their slice is dispatched. No implementer
starts a slice whose detail block is not yet written.)*

**#1 — `2026_09_18_100000_add_delivery_type_and_collection_fee_to_orders.php`**
```php
public function up(): void
{
    Schema::table('orders', function (Blueprint $table): void {
        $table->enum('delivery_type', ['ship', 'collect'])
            ->default('ship')->after('arete_center_id');
        $table->bigInteger('collection_fee_paise')->default(0)->after('shipping_paise');
    });

    // Every order that named a centre was a collection order. Written before
    // the column existed, so the backfill is the only record of intent.
    DB::table('orders')->whereNotNull('arete_center_id')
        ->update(['delivery_type' => 'collect']);

    // Status widen. MySQL rewrites the enum; SQLite rebuilt the table with a
    // CHECK constraint, so it needs the doctrine-free rebuild path used by
    // 2026_06_18_000001. Never a bare ->change() — it silently drops the
    // constraint on SQLite and the tests then pass against a column that
    // accepts anything.
}
```
`down()` drops both columns and narrows the enum. Guard the status widen behind
`DB::getDriverName()`.

**#2 — `2026_09_18_100001_extend_shipments_for_courier_and_collection.php`**
Adds to `shipments`: `gateway string(16) default 'manual'`,
`gateway_shipment_id string(64) nullable`, `label_url string(512) nullable`,
`arete_center_id unsignedBigInteger nullable` (FK `nullOnDelete`),
`consigned_at`, `at_centre_at`, `collected_at` (all `dateTime(3)` nullable),
`collected_by_user_id foreignId nullable`, `handover_otp_hash string(64) nullable`.
Widens `status` with `at_centre`. Adds `uniq_shipments_order` on `order_id` —
one shipment per order, which `pack()` assumes but never enforced. Adds
`uniq_shipments_gateway_ref` on `(gateway, gateway_shipment_id)`.
**Note:** `pod_hash_sha256` already exists and is unused; it is the handover
proof (AD-6). Do not add a second column for it.

**#3 — `2026_09_18_100002_create_shipment_events_table.php`**
Copy `2026_09_04_120100_create_payment_events_table.php` field for field,
substituting shipment for payment: `shipment_id` nullable FK, `order_id`
nullable FK, `gateway string(16)`, `direction string(16)`
(outbound|webhook|system), `event_type string(64)`, `gateway_event_id string(64)`
nullable, `signature_verified bool default false`, `http_status`, `duration_ms`,
`payload json` nullable, `error text` nullable, `processed_at`,
`processing_error`, `created_at dateTime(3)`. Unique
`uniq_shipment_events_gateway_event` on `(gateway, gateway_event_id)`.

**#8 — settings registry**
Add to `registry()` — `commerce.collection_fee_rupees` sits in the existing
`commerce` group, which is `'admin'` by `GROUP_OWNERS`, so it needs no explicit
`owner`:
```php
'commerce.collection_fee_rupees' => [
    'group' => 'commerce',
    'label' => 'Collection fee (₹)',
    'description' => 'Charged instead of the delivery fee when a buyer collects from an Arete Development Centre. Zero means collection is free — which is the launch position. The delivery fee and the free-shipping threshold never apply to a collection order.',
    'type' => 'int',
    'min' => 0,
    'max' => 100000,
    'default' => '0',
],
```
Add a new `fulfilment` group to `groups()` and `GROUP_OWNERS['fulfilment'] = 'developer'`,
holding `fulfilment.shiprocket.enabled` (bool, default `'false'`),
`fulfilment.shiprocket.pickup_location` (string), and
`fulfilment.default_route` (enum manual|shiprocket, default `manual`). Every one
carries `'feature' => ShiprocketFulfilmentFeature::class` except the collection
fee, so the group is invisible while the flag is off (zero-trace gating).

**#9 — `ShiprocketFulfilmentFeature`**
Plain class in `app/Modules/Shared/Features/`, `resolve(mixed $scope): bool`
returning `false`, with the docblock stating what stays hidden while off: no
dispatch-route picker (manual only), no `fulfilment` settings group, webhook
route 404s. Collection handover is **not** behind this flag — it must work
without Shiprocket.

---

## Slices

| Slice | Title | Files (#) | Depends on | Model |
|---|---|---|---|---|
| 1 | Schema, settings, flag | 1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12 | — | Sonnet |
| 2 | Collection fee + honest checkout (**R-94**) | 13, 14, 15, 16, 17, 18, 19, 58, 59 | 1 — **H3 blocks merge** | Opus |
| 3 | Courier contract + manual driver | 20, 21, 22, 25, 28, 31 | 1 | Opus |
| 4 | Shiprocket client + gateway | 23, 24, 29, 30, 32, 33 | 3 | Opus |
| 5 | Dispatch service + admin queue | 26, 34, 35, 36, 37, 38, 44 | 3 | Opus |
| 6 | Webhook + buyer tracking | 42, 43, 46, 48, 49, 50, 44 | 4, 5 | Opus |
| 7 | Centre collection handover (**R-47 core**) | 27, 39, 40, 41, 45, 47, 51, 60–65, 67, 68 | 5 — **and H1/H2/H4/H5 closed first** | Opus |
| 8 | Tests | see test plan | 2–7 | Sonnet |
| 9 | Docs, register, roadmap, ADR, runbook | 52, 53, 54, 55, 56, 57 | 2–7 | Opus |

Slices 3 and 2 are independent of each other and can run concurrently once
Slice 1 lands. Slice 7 is the compliance-critical one and does not start until
the declaration question is settled.

---

## Test plan

| # | Scenario | Type | Maps to |
|---|---|---|---|
| T1 | Collection order at ₹0 setting is charged no delivery fee and no collection fee | Pest | R-94 |
| T2 | Collection order with the setting at ₹50 is charged ₹50, not the ₹60 delivery fee | Pest | AD-3 |
| T3 | Free-shipping threshold does not zero a collection fee | Pest | AD-3 |
| T4 | A collection order's `ship_*` columns are all null after checkout | Pest | AD-2, R-47 |
| T5 | Deleting the chosen centre leaves `delivery_type = 'collect'` intact | Pest | AD-1 |
| T6 | Cooling-off refund returns the collection fee; a non-cooling-off return does not | Pest | #19 |
| T7 | `ManualCourier` records carrier + AWB and moves the shipment to dispatched | Pest | AD-4 |
| T8 | `ShiprocketGateway::permitted()` is false with no credentials, and the resolver falls back to manual | Pest | AD-4 |
| T9 | Shiprocket push writes exactly one `shipment_events` outbound row, payload scrubbed of buyer phone | Pest | AD-4 |
| T10 | A replayed webhook with the same `gateway_event_id` is a 200 no-op and writes no second row | Pest | AD-4 |
| T11 | A webhook with a bad signature is 400 and stores nothing | Pest | AD-4 |
| T12 | Handover OTP hash matches `sha256(order_no\|otp\|collected_at\|centre_id)` and the OTP is nowhere in the DB | Pest | AD-6 |
| T13 | Collection order reaching `awaiting_collection` then `delivered` opens cooling-off from the collection time | Pest | AD-7 |
| T14 | Buyer sees carrier, AWB and tracking on their own order | Playwright | matrix row 1 |
| T15 | Buyer sees "ready for collection" and the OTP on their own order; another buyer gets 403 | Playwright | matrix row 2 |
| T16 | admin-operations can push to Shiprocket; admin-support gets 403 **and** the button is absent | Playwright | matrix rows 4–5 |
| T17 | admin-finance gets 403 on every dispatch POST and sees no dispatch queue link | Playwright | matrix row 3 |
| T18 | Centre operator acknowledges their own centre's consignment; a distributor assigned to no centre gets 403 and sees no menu item | Playwright | matrix rows 7–9 |
| T19 | Centre operator cannot acknowledge a *different* centre's consignment (ownership, not role) | Playwright | matrix rows 7–8 |
| T20 | With the flag OFF: no route picker, no `fulfilment` settings group, webhook route 404s | Playwright | #9 zero-trace |
| T21 | admin-operations can edit the collection fee; the `fulfilment.*` keys 404 for them | Playwright | matrix rows 11–12 |
| T22 | Validation: dispatching an unpacked order, and handover on a consignment not yet at the centre, both fail cleanly | Playwright | edge |

Playwright specs go in `tests/Browser/`, one file `order-fulfilment-dispatch.spec.js`,
using the existing role storage-state fixtures. Seed through factories, never
through UI clicking. `tests/Browser/order-fulfilment.spec.js` already exists and
must keep passing.

---

## Acceptance criteria

- [ ] `php artisan migrate` forward-only on dev succeeds; `--pretend` shows no
      ALTER/DROP against existing data beyond the two documented enum widens.
- [ ] The full suite passes on `arovolife_test` with the explicit `-e` overrides
      from `docs/local-dev-environment.md`. Never a bare `php artisan test`.
- [ ] `vendor/bin/pint --dirty` clean; Larastan level 7 clean.
- [ ] `npm run build` succeeds (Tailwind classes in the new Blade files).
- [ ] With the flag OFF, a full click-through of checkout → pack → ship →
      deliver behaves exactly as it does today for a home-delivery order.
- [ ] A collection order placed at the ₹0 default shows no fee line, has null
      `ship_*`, and the confirmation page says "Collect from" with the centre
      named — no "Shipping to".
- [ ] An inter-state collection order is invoiced **IGST**, with the centre's
      state as place of supply (H3). This is the criterion that blocks Slice 2.
- [ ] **No centre receives a consignment before declaration v3 is recorded
      against that centre** — enforced in code, not scheduled (H1, H2).
- [ ] The ADC bonus for a month excludes any order with no recorded handover at
      that centre (H5).
- [ ] `privacy.md` names the ADC operator as a recipient and the fields
      disclosed match the permission matrix exactly (H4, L1), and the page has
      been republished on each environment.
- [ ] Collection codes are issued and verified through `OtpService`; no
      plaintext code exists in the database, in `shipment_events.payload` or in
      any log (M2).
- [ ] `compliance-officer` re-run returns PASS, and only then does any
      Slice 2 or Slice 7 commit carry a `Compliance-Review:` trailer.
- [ ] `security-auditor` has reviewed the webhook endpoint and the OTP handling.
- [ ] Every commit touching checkout money or the ADC declaration carries a
      `Compliance-Review:` trailer.

---

## Related workstream — R-91 §14 rehearsal (not part of these slices)

Approved 2026-09-18, tracked here so it is not lost. The §14 GSB-rebuild
procedure has never been executed. The rehearsal runs on staging, treating
staging as the production stand-in, and proves: the guard refuses as documented;
the 6.0 swept-credit check stops the double-pay; reverse → delete → restate
carry-forward → replay produces the right figures; step 7 verification catches a
bad rebuild.

**It is destructive on staging** (deletes `gsb_cutoff_results` rows, reverses
credits) and therefore needs the full five-part warning and an explicit
go-ahead before any command runs — separately from this plan's approval.
Output: a rehearsal record under `docs/runbooks/`, corrections to §14 where the
written procedure turns out to be wrong, and an R-91 status update. The two
named §14b authorisers stay deferred to the named-officer launch gate.

---

## Outcome — Slice 1 (2026-09-18)

**Shipped.** Schema, settings and the feature flag are in place on dev.

| # | File | Result |
|---|---|---|
| 1 | `2026_09_18_100000_add_delivery_type_and_collection_fee_to_orders.php` | `delivery_type` + `collection_fee_paise`, backfill, `awaiting_collection` widen |
| 2 | `2026_09_18_100001_extend_shipments_for_courier_and_collection.php` | gateway/label/centre/handover columns, `at_centre` widen, `uniq_shipments_order` |
| 3 | `2026_09_18_100002_create_shipment_events_table.php` | audited courier log |
| 4, 5, 6 | `Order`, `Shipment`, `ShipmentEvent` models | consts, fillable, casts, relations, `isCollection()`, `isCollected()` |
| 7 | `OrderStatusBadge` | "Ready to collect", purple, added to `FILTERABLE` |
| 8 | `AdminSettingsController` | `commerce.collection_fee_rupees` (admin, ₹0) + `fulfilment` group (developer) |
| 9, 10 | `ShiprocketFulfilmentFeature` + flag registry | default OFF |
| 11, 12 | `ProductionSeeder`, `CommerceFeatureFlagSeeder` | four new keys |
| — | `tests/Feature/Fulfilment/OrderDeliveryTypeTest.php` | 6 tests, 25 assertions |

**Verification:** `migrate --pretend` confirmed additive (no DROP, both enum widens
strictly widening); migrated dev; backfill exact — 3 orders named a centre, all 3
now `collect`, zero mismatches. Pint clean. Larastan level 7 clean, and the
baseline **shrank by 20 errors** (4855 → 4765 lines) because the new `@property`
docblocks resolved suppressions that had been carried in
`phpstan-baseline.neon`. Full suite: **2985 passed, 1 skipped, 0 failed**.

**Deviations from the plan, and why:**

1. **`fulfilment.max_dwell_days` (M4) deferred to Slice 7.** A dwell limit with
   no return-to-origin behind it is a lever that does nothing, which is worse
   than an absent one. It lands with the enforcement.
2. **`CommerceFeatureFlagSeeder` was not run on dev.** It uses
   `updateOrInsert`, so running it would have reset every commerce and
   compensation flag to its seeded default — a mutation, not an additive seed.
   The four new keys were inserted individually, skipping any that existed.
   *Worth knowing generally: this seeder is NOT safe to re-run on a
   long-lived environment without confirmation, despite its harmless name.*
3. **The Slice 1 test runs with foreign keys ON**, against the house
   `disableTestForeignKeys()` habit. The `nullOnDelete` cascade is the
   behaviour under test — with FKs disabled it never fires and the test passes
   while proving nothing.

**Noticed, deliberately not fixed** (pre-existing, outside this slice):
`Shipment::order()` has a baselined `missingType.generics` error. The new
`areteCenter()` beside it carries its generics, so the two now read
inconsistently. One line, but it is not this slice's change to make.
