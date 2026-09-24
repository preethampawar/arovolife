# Shiprocket courier choice (rates, delivery days, services)

Date: 2026-09-24 · Status: draft for review · Module: Fulfilment (ADR-0017)

## Goal

When staff pick the **Shiprocket** route on an order, they see every courier
Shiprocket can use for that parcel (DTDC, India Post, Delhivery, …) with its
**rate**, **estimated delivery days / date**, **rating** and service extras
(surface/air, real-time tracking, proof of delivery, call before delivery),
and choose one. Shiprocket's recommended courier is preselected (user decision
A, 2026-09-24). The chosen courier's quoted rate and delivery days are stored
on the shipment (user decision A).

**Done when:** on staging order 81 the list loads, the recommended courier is
ticked, staff can switch, and Mark as Shipped books with that courier's id;
the order page then shows "Courier · quoted ₹X · N days".

Side benefit: the sandbox refuses the automatic AWB pick ("AWB not assigned",
staging 2026-09-24, twice); a named courier id may get past that.

## Decisions

- Customer's shipping fee is **unchanged**. The quote is what the company
  pays Shiprocket; shown to staff only, never to the buyer.
- Rates are fetched **before** any booking, from pincodes + parcel size, via
  `GET /courier/serviceability/` (`pickup_postcode`, `delivery_postcode`,
  `weight` kg, `length`/`breadth`/`height` cm, `cod=0`, `declared_value`).
  No Shiprocket order is created to get a quote.
- The pickup postcode comes from `GET /settings/company/pickup`, matching the
  `fulfilment.shiprocket.pickup_location` nickname; cached 24 h as a **plain
  string** (cache stores carry no objects). Missing → quotes refused with a
  clear message; dispatch still works (auto-assign).
- **Never trust client-sent prices.** The form posts only `courier_id`. At
  dispatch the gateway re-quotes server-side and takes rate/ETD from its own
  answer. A `courier_id` missing from the fresh list → refused **before** the
  booking is created ("no longer serves this route — reload the list").
- `courier_id` is optional. If the list can't load (API down), staff may still
  dispatch and Shiprocket auto-assigns, exactly as today.
- Resumed booking that already has an AWB: the choice is ignored (courier
  already fixed); the form says so.
- Quotes are loaded asynchronously (JSON endpoint) when the Shiprocket radio is
  selected, so the order page never waits on Shiprocket.
- COD is always 0: only prepaid orders reach dispatch (existing rule).

## Touch list

1. **Migration** `app/Modules/Fulfilment/Database/Migrations/2026_09_24_110000_add_courier_quote_to_shipments.php`
   — `courier_company_id` unsigned int null, `quoted_rate_paise` unsigned int
   null, `quoted_etd_days` unsigned tinyint null. Forward-only, additive.
   `Shipment` model: fillable + int casts.
2. **`Data/CourierQuote.php`** (final readonly): `courierId int`, `name string`,
   `ratePaise int`, `etdDays ?int`, `etd ?string`, `rating ?float`,
   `surface bool`, `services list<string>` (from `realtime_tracking`,
   `pod_available`, `call_before_delivery`), `recommended bool`.
   `toArray()` for the JSON endpoint.
3. **`ShiprocketClient`** — `serviceability(array $query, ?int $shipmentId, ?int $orderId): array`
   (GET `/courier/serviceability/`, event `courier.serviceability`) and
   `pickupLocations(): array` (GET `/settings/company/pickup`, event
   `settings.pickup`). `assignAwb(..., ?int $courierId = null)` adds
   `courier_id` to the body when given.
4. **`ShiprocketPayloadScrubber`** — allow the few serviceability keys we keep
   in `shipment_events` (courier id, name, rate, etd days); everything else
   dropped as today.
5. **`ShiprocketGateway`**
   - extract the stacked-box math from `orderBody()` into private
     `parcelMeasures(Order): array{weight,length,breadth,height}` (reused);
   - `quotes(Order $order, Consignee $consignee): list<CourierQuote>` — sorted
     recommended first, then by rate; uses `data.available_courier_companies`
     and `data.recommended_courier_company_id` (fallback
     `shiprocket_recommended_courier_id`); rate from `rate` (fallback
     `freight_charge`) → paise;
   - private `pickupPostcode(): string` (cached string);
   - `dispatch()`: when `$instruction->courierId !== null` and no AWB exists
     yet, re-quote, find the id or throw `RuntimeException` before `book()`;
     pass the id to `assignAwb`; return the quote on the result.
6. **`DispatchInstruction`** — add `?int $courierId = null`.
   **`CourierShipment`** — add `?CourierQuote $quote = null`.
7. **`DispatchService`**
   - `dispatch(..., ?int $courierId = null)` → passes it into the instruction;
     in the final transaction writes `courier_company_id`, `quoted_rate_paise`,
     `quoted_etd_days` from `$result->quote`; audit `order.dispatched` gains
     `courier_id`, `quoted_rate_paise`, `quoted_etd_days`, `chose_recommended`.
   - public `courierQuotes(Order $order): list<CourierQuote>` — builds the
     consignee (existing private `consigneeFor`, so collection orders quote to
     the centre's pincode) and asks the Shiprocket gateway; refuses if the
     route isn't available.
8. **`AdminOrderController`**
   - `courierQuotes(Order $order): JsonResponse` — `{quotes: [...]}` or
     `{error: "..."}` (422) on `RuntimeException|ShiprocketApiException|ConnectionException`;
     only while the order is `paid`/`ready_to_ship`.
   - `markShipped` validates `courier_id` (`nullable|integer|min:1`, only
     meaningful for the shiprocket route) and passes it on; success flash adds
     the quoted rate/days.
9. **Route** in the `can:commerce.order.manage` group:
   `GET /commerce/orders/{order}/courier-quotes` → `courierQuotes`,
   `throttle:20,1`, name `commerce.orders.courier-quotes`.
10. **View `orders-show.blade.php`** — under the route fieldset a
    `data-courier-quotes-url` panel, hidden unless Shiprocket is selected.
    Vanilla `fetch` (pattern: `offline-orders/create.blade.php`), rendered as a
    radio list (`name="courier_id"`): name, ₹rate (IndianNumber on the server —
    the endpoint sends a formatted string), "N days · by <date>", ★rating,
    Surface/Air badge, service chips, "Recommended" badge; recommended checked.
    Loading, error ("Couldn't load couriers — Shiprocket will choose one")
    and empty states. Text via `textContent` only (no innerHTML with API data).
    The confirm modal impact names the chosen courier + rate. Shipment line
    shows "quoted ₹X · N days" when stored. Lucide icons, help tips.
11. **Docs** — `resources/help/order-management.md` (choosing a courier),
    `docs/runbooks/shiprocket.md` (serviceability, pickup postcode cache,
    refusal message), ADR-0017 amendment (courier choice + stored quote).

## Tests (`tests/Feature/Fulfilment/CourierChoiceTest.php`, Http::fake)

- quotes endpoint lists couriers, recommended first and flagged, rate in ₹;
- quotes endpoint: 403 for `admin-finance`; 422 JSON when Shiprocket is down;
  refused for a shipped order;
- dispatch with `courier_id` sends `courier_id` to `/courier/assign/awb` and
  stores rate/ETD/courier id on the shipment + in the audit;
- a `courier_id` not in the fresh list is refused and **no** `orders/create`
  call is made;
- a tampered post can't set the rate (only `courier_id` is read);
- no `courier_id` → assign body has no `courier_id` (today's behaviour);
- collection order quotes to the centre's pincode;
- existing ShiprocketDispatch tests stay green (fixtures updated for the extra
  serviceability call only when a courier id is posted).

Run with the `arovolife_test` `-e` overrides, Fulfilment folder only; Pint;
Larastan on touched files; `php -l` on compiled views.

## Rollout

Commit → compliance-officer review (touches dispatch audit + staff UI, no
hard rule directly) → user confirms push → staging `git_pull`, `migrate
--force`, caches, `queue:restart`, `npm run build` → browser check on order 81
(still `ready_to_ship`, booking 352572422 held — resumes, no second booking).

## Risks

- Serviceability field names differ between sandbox and live → parse
  defensively, skip rows without `courier_company_id`, log unknown shapes.
- Re-quote at dispatch adds ~1 s and one API call; acceptable.
- Rate drift between list and dispatch: stored rate is the dispatch-time
  quote, which is what Shiprocket charges.

## Review findings folded in (Fable review, 2026-09-24)

1. The courier check lives in `awbFor()`, not before `book()`. Resumed booking → `showShipment`; AWB present → choice ignored, quote null; no AWB → re-quote, validate id, `assignAwb(courier_id)`. Fresh booking → re-quote **before** `book()` (refuse before anything is created) → `book()` → `assignAwb(courier_id)`.
2. `realtime_tracking` / `pod_available` / `call_before_delivery` are "Yes"/"No" strings → `strcasecmp($v,'yes')===0`. `estimated_delivery_days` is a string → `ctype_digit ? int : null`.
3. The pickup-postcode cache key is `sha1(baseUrl|nickname)`, so a nickname change never serves a stale origin.
4. `quotes()` runs `parcelGaps()` first → `MissingParcelDetailsException`.
5. Scrubber: add the `available_courier_companies` container (courier_company_id, courier_name, rate, estimated_delivery_days) plus the `recommended_courier_company_id` key. Never pincodes. Each quote fetch writes one `shipment_events` row (shipment_id may be null).
6. The interactive quote call uses a 10 s timeout and no retry; the dispatch-time re-quote keeps the defaults.
7. Held-booking copy is conditional: "may already have a courier; your choice applies only if none was assigned".
8. `markShipped` passes `$manual ? null : courier_id`.
