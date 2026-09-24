# Order fulfilment — Slices 5 to 9 (completing the feature)

Parent plan: `docs/plans/2026-09-18-order-fulfilment.md`. Slice 4 plan:
`docs/plans/2026-09-24-slice-4-shiprocket-gateway.md`. Paths are relative to `app/`.

## User decisions (2026-09-24)

| # | Question | Decision |
|---|---|---|
| D1 | Manual dispatch with a blank courier name | **A — required.** Mark as Shipped goes through `DispatchService`; `ManualCourier` already refuses a blank carrier. |
| D2 | Shiprocket says a home-delivery parcel is DELIVERED | **A — auto-mark delivered.** This opens the 30-day cooling-off. RTO or lost is recorded and flagged for staff. |
| D3 | Centre operators' own consignment page | **A — build it,** own centre only, behind the R-97 switch `commerce.collection_at_centre_enabled`. |
| D4 | Parcel at a centre past `fulfilment.max_dwell_days` | **A — Action Center alert.** No automatic status change. |

## What exists already

- `DispatchService`, `ManualCourier`, `ShiprocketGateway`, `CourierGatewayResolver`,
  `CollectionHandoverService`, `OrderReadyForCollectionNotification`, the
  `FulfilmentSettings` dwell-days lever, and `markAwaitingCollection` (the admin
  button issues the code and emails the buyer).
- **The gap:** the admin **Mark as Shipped** calls `OrderStateMachine::markShipped()`
  directly. So `DispatchService` has no caller, and there is no route picker, no
  parcel-gap alert, no label link, no webhook, no centre self-service and no dwell
  alert.

## Slice 5 — dispatch through the service + route picker + queue

1. **`AdminOrderController::markShipped`**
   - Validation:
     - `route` is in `manual|shiprocket`, defaulting to `manual`;
     - `ship_carrier` is required when the route is manual, max 120;
     - `ship_tracking_no` is nullable;
     - `confirm_remote_cancelled` is a boolean.
   - Calls `DispatchService::dispatch($order, $route === 'manual' ? null : $route, carrier, awb, actor, confirmedRemoteCancelled: ...)`.
     - Passing null for manual keeps the "refuse if a named route is unavailable" rule meaningful for Shiprocket only.
     - Manual must still be refused while a Shiprocket booking exists (the existing `guardAbandonedBooking`). **Check:** the route passed must resolve to `manual` so that guard fires. Passing `null` resolves to `preferred()`, which could be Shiprocket. So pass `'manual'` explicitly: `route('manual')` resolves to manual, and name equals route, so it is fine.
   - Catches `MissingParcelDetailsException` (lists the products) and `RuntimeException`; the flash goes to `withErrors(['ship' => ...])`.
   - Success message: "Order X shipped via {carrier}, AWB {awb}".
   - After success, when a Shiprocket label exists, shows a link.
2. **`AdminOrderController::show`** passes the view:
   - `$routes = resolver->available()` (names only);
   - `$preferredRoute`;
   - `$parcelGaps` (only when Shiprocket is available and the order is paid or ready to ship);
   - `$pendingBooking`: the shipment's gateway ≠ manual while the order is not yet shipped. It carries the gateway, the remote id or null ("no reply"), and the order no to search for.
3. **`orders-show.blade.php` ship form**
   - **Route radio (Manual / Shiprocket):** shown only when more than one route is available. Zero-trace: with the flag or the setting off, the form is exactly today's plus a required carrier.
   - **Parcel-gap alert:** amber box listing each product, its missing fields and a link to `admin.catalog.products.edit`. The Shiprocket radio is disabled while there are gaps.
   - **Shiprocket choice:** hides the carrier and AWB fields, because the courier assigns them (Alpine `x-show`; follows the existing Alpine usage in the view).
   - **Pending-booking box and checkbox:**
     - When `$pendingBooking` is set, a red box explains it: either "Booked with Shiprocket (shipment N). Cancel it in the Shiprocket panel before dispatching another way" or "An earlier Shiprocket request got no reply. Search the Shiprocket panel for order X".
     - It holds **one checkbox**, `confirm_remote_cancelled`. Its label is route-aware:
       - (a) "I have cancelled that booking in the Shiprocket panel" when dispatching manually;
       - (b) "I have checked the Shiprocket panel and order X is not there" when re-dispatching via Shiprocket with no remote id.
     - These are the "two confirm checkboxes": one input, two meanings.
   - **Shipped orders:** show the gateway, the Shiprocket shipment id and a label link (`label_url`, `target=_blank rel=noopener`).
   - The confirm-modal impact text mentions that Shiprocket books a real pickup.
4. **Dispatch queue:** `GET /admin/fulfilment/dispatch`, route `admin.fulfilment.dispatch`, in a new `AdminDispatchController@index`.
   - **Lists:** paid and `ready_to_ship` orders, oldest first, paginated 50.
   - **Columns:** order no, placed, delivery type (Ship / Collect at centre name), items, packed?, and "Shiprocket-ready" (✓, or the missing fields count, computed with one eager load of `items.variant`), plus a link to the order page.
   - **Permissions:** view is `commerce.order.view` if that permission exists, else whatever gates `orders.index`. Mirror it. Admin-support sees it; the finance role does not, per the matrix. Match the existing orders-index gate exactly.
   - **Nav:** a sidebar link under Commerce, next to Orders.
   - **No actions on the queue.** Dispatch happens on the order page, where the confirm modal and full context live.
5. **`orders-index.blade.php`:** add the delivery-type column if it's missing.
6. **Tests** in `tests/Feature/Fulfilment/AdminDispatchTest.php`:
   - a manual ship with no carrier gets a 422 or an error;
   - a manual ship calls through, and the shipment gets `consigned_at` and an audit `order.dispatched`;
   - Shiprocket appears only when all three gates are on;
   - a gap disables Shiprocket, and a POST with gaps is refused with the product named and the order stays paid;
   - a pending booking blocks manual without the checkbox and passes with it;
   - admin-support can see the queue and gets a 403 on the ship POST;
   - finance gets a 403 on the queue.
   - Also update the existing tests that POST to `orders.ship` without a carrier.

## Slice 6 — webhook + buyer tracking + dispatched email

1. **Route:** `POST /webhooks/courier/tracking` (`webhooks.courier.tracking`), `throttle:600,1`, CSRF-exempt in `bootstrap/app.php` like Razorpay.
   - The URL must not contain "shiprocket", "kartrocket", "sr" or "kr": Shiprocket's panel rejects such addresses.
2. **Config:** `arovolife.fulfilment.shiprocket.webhook_token` ← `SHIPROCKET_WEBHOOK_TOKEN`.
   - `.env` is deny-ruled, so the user adds it; add it blank to `.env.example`.
   - Shiprocket sends the token configured in its panel in the `x-api-key` header.
3. **`ShiprocketWebhookController`**
   - **404** when the flag is off or the token is blank (zero-trace).
   - **`hash_equals`** on `x-api-key`; a mismatch is a 401 and stores nothing.
   - **Parse JSON:** `awb`, `current_status`, `current_status_id`, `sr_order_id`, `order_id`, `current_timestamp`.
   - **Stores a `ShipmentEvent`:**
     - direction webhook, event_type `tracking.{status_id}`;
     - `gateway_event_id` = sha256(awb|current_status_id|current_timestamp), truncated to 64;
     - `signature_verified` true;
     - payload scrubbed with the allow-list (awb, statuses, timestamps, courier_name, sr_order_id, order_id, etd, is_return). Never scans, locations or names.
   - **Links the shipment** by `gateway=shiprocket` and `gateway_shipment_id` = the payload's `shipment_id` if present, else by `awb_no`.
   - **Unique violation:** a 200 no-op.
   - **Queues `ProcessShiprocketWebhookJob`** and returns 200 `{ok:true}`.
   - **Unknown AWB:** still stored with a null shipment, 200.
4. **`ProcessShiprocketWebhookJob`** (queue `default`, tries 3, idempotent via `processed_at`)
   - **Never trusts the payload for state.** It calls `ShiprocketGateway::track($shipment)` (the API) and acts on that answer. This is Razorpay's re-fetch rule: a leaked token cannot start anyone's cooling-off.
   - **DELIVERED:**
     - Home delivery, order `shipped`: calls `markDelivered` with a null actor and audit source webhook, then sets the shipment to delivered.
     - Collection order: the courier delivered **to the centre**. Nothing changes; the centre acknowledges (Slice 7). The event is recorded.
   - **RTO or returned:** shipment status `returned_to_origin`; the order stays; it surfaces in the Action Center (item 6).
   - **Anything else:** updates `shipments.status` only for known forward states, never backwards. Sets `processed_at`, or `processing_error` on failure.
5. **Buyer tracking** (`shop/orders/show.blade.php`)
   - Existing: carrier and AWB are shown already.
   - New: a "Track parcel" link when the shipment gateway is Shiprocket and there's an AWB: `https://shiprocket.co/tracking/{awb}` (URL-encoded). Manual stays text.
6. **Action Center:** `CourierReturnedProvider` for shipments `returned_to_origin` whose order is not cancelled or refunded, following the `ShippedNotDeliveredProvider` pattern.
7. **Dispatched email:** `OrderStatusChangedNotification` already fires on shipped. Check its content.
   - If it lacks carrier, AWB or tracking link, add a new `OrderDispatchedNotification`, sent from `DispatchService` after commit, only on the ship path (not collection; the centre flow has its own), and suppress the generic shipped email for home deliveries to avoid two emails.
   - Decide during build after reading the listener. Default: extend the existing notification's `shipped` branch with carrier, AWB and link rather than add a second mail.
8. **Tests** in `ShiprocketWebhookTest.php`:
   - flag off → 404;
   - bad token → 401 and no row;
   - a replay → one row;
   - DELIVERED with the API confirming → order delivered and cooling-off row created;
   - DELIVERED in the payload with the API saying in-transit → no change;
   - a collection order delivered → no order change;
   - RTO → shipment returned and the provider lists it;
   - PII is not stored.

## Slice 7 remainder — centre self-service + dwell alert

1. **`ShipmentPolicy`**
   - `viewCentreConsignments(User, AreteCenter)` and `actOnConsignment(User, Shipment)`: true when `shipment.areteCenter.assigned_distributor_id === user.distributor.id`.
   - Refused while impersonating (`session('impersonator_id')`, the R-98 precedent from declarations).
   - The docblock states R-24: an owner of several centres gets all of them.
2. **`CentreConsignmentController`** (distributor area)
   - **Routes:**
     - `GET /my/arete-centre/consignments`
     - `POST .../{shipment}/received`
     - `POST .../{shipment}/handover`
   - **404 while** `commerce.collection_at_centre_enabled` is off (R-97, zero-trace), and 404 when the user owns no centre.
   - **Lists** shipments of the user's centres with status dispatched, at the centre, or collected in the last 30 days.
   - **Shows only:** order no, buyer first name, item count, consigned/arrived dates and days waiting. No phone, no address, no amounts: declaration `buyer_data_duty` and minimisation.
   - **`received`** calls `OrderStateMachine::markAwaitingCollection` with actor = the user, then issues the code and sends `OrderReadyForCollectionNotification`.
     - This logic moves out of `AdminOrderController::markAwaitingCollection` into a new `CollectionHandoverService::acknowledgeArrival(Order, actor)`, so admin and centre share one path.
     - The operator never sees the code; the admin override still flashes it once, as today.
   - **`handover`** takes `code` (6 digits) and calls `CollectionHandoverService::recordCollection`.
     - The existing attempt cap applies.
     - Is `markDelivered` inside `recordCollection` already? Check during build; it must end with the order `delivered` so the 30-day clock opens at collection.
   - Throttle `10,1` on the POSTs. Confirm modals per the UI convention.
3. **View** `resources/views/my/arete-centre/consignments.blade.php`, with a link from `my/arete-centre/status.blade.php` when the switch is on and the user owns an active centre.
4. **`AtCentreNotCollectedProvider`** (Action Center, Orders): shipments `at_centre` whose `at_centre_at` is older than `maxDwellDays()`. The message is "arrange return". There is no state change.
5. **Tests** in `CentreConsignmentTest.php`:
   - owner acknowledges and the buyer is emailed; the owner never sees the code;
   - another distributor → 403;
   - switch off → 404;
   - wrong code → refused and counted;
   - correct code → collected and delivered;
   - impersonation → refused;
   - dwell provider lists the old parcel and not the fresh one.

## Slice 9 — docs

- **`resources/help/order-fulfilment.md`** (new), plus a link in the help index if one exists. It covers:
  - the dispatch routes and parcel gaps;
  - the pending-booking checkbox;
  - the webhook;
  - the centre flow.
- **`resources/help/status-reference.md`:** shipment statuses.
- **`docs/runbooks/shiprocket.md`:**
  - credentials, pickup and the three gates;
  - the webhook URL and token setup, including the URL-word restriction;
  - the stacked-box parcel estimate;
  - the triage for "no reply" bookings and sandbox AWB limits.
- **ADR `docs/architecture/adr-0017-courier-gateway.md`:** 0016 is taken. It covers AD-4 and AD-5, plus the claim/search-before-create decision.
- **`docs/compliance/risk-register.md`:**
  - update R-47 (the self-service surface exists, gated by R-97);
  - add a note under the Shiprocket PII allow-list.
- **`docs/roadmap.md`:** mark fulfilment done except the R-97/H4 publish.
- **Parent plan:** append an Outcome section.

## Out of scope

- **`content:publish privacy`:** destructive; needs its own five-part warning.
- **Enabling R-97 anywhere.**
- **Cancelling a Shiprocket booking from our side.**
- **Scheduled tracking polls:** the webhook is primary; the admin can see the status on the order page.
- **Playwright specs** (T14–T22): covered by Pest HTTP tests plus a manual browser pass on staging.

## Sequencing and verification

1. Build in order 5 → 6 → 7 → 9. Each chunk runs Pint, then targeted Pest on `arovolife_test` with the `-e` overrides from `docs/local-dev-environment.md`.
2. Then the full suite, Larastan level 7, `view:cache` with a `php -l` over the compiled views, and `npm run build`.
3. Diff audit by an independent reviewer, plus `compliance-officer`: this touches cooling-off (D2) and buyer-data disclosure (D3).
4. Local browser:
   - manual dispatch with a required carrier;
   - the Shiprocket radio with a gap alert, and a sandbox booking;
   - a webhook via curl with a token;
   - the centre page (switch on locally only).
5. Commit per slice, then ask before merging, pushing or deploying.
6. Staging: deploy, then a browser Shiprocket dispatch on a staging order after adding dimensions to product 6.

## Risks

- **D2 opens cooling-off from an external signal.** Mitigated by the API re-fetch and a token check.
- **Changed behaviour:** existing tests and the admin habit of shipping with a blank carrier.
- **A consignee-less old order** (a collection order whose centre was deleted): `DispatchService` refuses it with a clear message, as today.
- **Shiprocket sandbox AWB limits:** the staging booking may stop at "AWB not assigned". The pending-booking UI is then exercised for real.

## Plan review outcome (2026-09-24) — supersedes the sections above where they differ

Independent review: 9 findings, all accepted.

1. **Packing leniency is preserved.**
   - `DispatchService` packs strictly (`pack()`) only for a courier route.
   - Manual uses `packForShipment()`, today's lenient path while inventory isn't enforced.
   - If that leaves no shipment row (the order ships unpacked, as today), the manual dispatch goes straight to `markShipped` with the typed carrier and AWB, and is audited `order.dispatched` with `unpacked: true`.
2. **Checks before packing.**
   - A new `CourierGateway::preflight(Order)` runs **before** packing. `ShiprocketGateway` throws `MissingParcelDetailsException` there, or when there's no pickup; `ManualCourier` does nothing.
   - So a gap refusal leaves the order `paid` and untouched.
3. **Roles.**
   - There is no `admin-support` role and no `commerce.order.view` permission.
   - The dispatch queue is gated `can:commerce.order.manage`, the same as the dispatch actions.
   - Tests use real roles: admin-operations gets 200 and admin-finance gets 403.
4. **`track()` mapping and raw status.**
   - `str_starts_with('RTO')` maps to returned.
   - A new nullable `shipments.courier_status` (string 40, additive migration) records the courier's raw status on every verified update.
   - The admin order page shows it.
   - `CourierExceptionProvider` lists shipments whose `courier_status` is RTO-anything, LOST, DAMAGED, DESTROYED or CANCELED and whose order isn't cancelled or refunded. This replaces `CourierReturnedProvider`.
5. **Race with a manual "delivered".** The job takes `lockForUpdate()` on the order and re-checks `shipped` before `markDelivered`.
6. **Centre ownership comes from `orders.arete_center_id`**, not `shipments.arete_center_id`, which is null for parcels shipped by the old button.
7. **Route is always passed as a string.** `mergeIfMissing(['route' => 'manual'])`, then `required_if:route,manual` on the carrier.
8. **Webhook dedupe key** = sha256(sr_order_id|shipment_id|awb|current_status_id|current_timestamp).
9. **Smaller fixes.**
   - No redundant shipment update after `markDelivered`.
   - `recordCollection` already delivers.
   - `ShippedNotDeliveredProvider` excludes shipments with an exception `courier_status`.
   - The existing ship test already sends a carrier.
