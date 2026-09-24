# Slice 4 — Shiprocket client + gateway (plan)

Parent plan: `docs/plans/2026-09-18-order-fulfilment.md` (Slice 4 = files #23, #24,
#29, #30, #32, #33; tests T8, T9). Paths below are relative to `app/`.

## Goal and definition of done

An operator who picks "Shiprocket" when dispatching a paid, packed order gets a real
Shiprocket consignment booked: a Shiprocket order, an AWB and a courier, plus a label URL
when Shiprocket has one ready. `DispatchService` records all of this on the shipment and
the order.

Shiprocket appears as a route only when all four of these hold:
- the flag is ON;
- `fulfilment.shiprocket.enabled` is true;
- a pickup location is set;
- the credentials point at the correct host for the environment.

In every other case the route list is manual only (T8). Every API call writes one scrubbed
`shipment_events` row that holds no buyer name, phone or address and no token (T9).

**Done means:**
- the Pest tests pass on `arovolife_test`;
- Pint and Larastan level 7 are clean;
- a real booking against the Shiprocket **sandbox** from the local app returns an AWB, or
  we document exactly why the sandbox refuses one.

## Facts established (not assumptions)

- **Sandbox:** base `https://api-sandbox.shiprocket.in/v1/external`. `POST /auth/login`
  works with the credentials in local and staging `.env`. The sandbox pickup is
  `Arovolife-Hyd-Hub`, and the setting is set on local and staging.
- **Live base:** `https://apiv2.shiprocket.in/v1/external`.
- **Only prepaid orders reach dispatch.** `DispatchService` accepts only `paid` or
  `ready_to_ship`, and `Order` has no COD method (`PAYMENT_ONLINE`, `PAYMENT_OFFLINE`).
  Shiprocket `payment_method` is therefore always `"Prepaid"`. No COD money flow exists.
- **Privacy policy:** `privacy.md` already lists "Logistics partner — customer name;
  mobile; address". Sending them to Shiprocket is covered.
- **Variant weight:** `product_variants.weight_g` exists (default 0). 12 of the 13 dev
  variants have weight 0. There are **no dimension columns**, and Shiprocket requires
  `length`, `breadth` and `height` in cm, plus `weight` in kg (all > 0).
- **DispatchService calls the gateway inside `DB::transaction`.** If Shiprocket is called
  there, a failure rolls back the outbound `shipment_events` rows (evidence lost). A
  booking followed by a failed local write rolls back our record of a real remote
  consignment.
- **ManualCourier and the unique index:** `ManualCourier` writes its idempotency key into
  `gateway_event_id` under unique `(gateway, gateway_event_id)`. Shiprocket outbound rows
  must leave `gateway_event_id` null; NULLs don't collide.
- **Flag idiom:** `Feature::for(null)->active(ShiprocketFulfilmentFeature::class)`.
- **Staging only has the sandbox credentials.** They carry no money, but dispatching via
  Shiprocket on staging books sandbox consignments only.

## Approach

Mirror Payments/Razorpay:
- **`ShiprocketClient`** is a thin audited HTTP client. It handles the token and writes
  one `shipment_events` row per call.
- **`ShiprocketGateway`** implements `CourierGateway`.
- **`ShiprocketPayloadScrubber`** is allow-list based.
- **`ShiprocketApiException`** reports API failures.
- **Credentials** live in `config/arovolife.php`.
- **The resolver** gains Shiprocket behind the four gates above.

Two changes go beyond the original touch list, both forced by the facts above:

1. **The DispatchService transaction is split** so the remote call runs outside a DB
   transaction.
2. **Product variant dimensions are added** (user request, 2026-09-24): the product or
   variant schema must carry the fields Shiprocket needs.

## Touch list

### New files
1. **`app/Modules/Fulfilment/Exceptions/ShiprocketApiException.php`** — `final`,
   extends `RuntimeException`. Carries `?int $httpStatus` and `?string $gatewayMessage`.
   Shape copied from `RazorpayApiException`.

2. **`app/Modules/Fulfilment/Support/ShiprocketPayloadScrubber.php`** — allow-list
   walker, same algorithm as `RazorpayPayloadScrubber`.
   - **Keeps:** `order_id`, `channel_order_id`, `shipment_id`, `status`, `status_code`,
     `awb_code`, `awb_assign_status`, `courier_company_id`, `courier_name`, `label_url`,
     `label_created`, `pickup_status`, `pickup_scheduled_date`, `pickup_token_number`,
     `pickup_location`, `payment_method`, `sub_total`, `weight`, `length`, `breadth`,
     `height`, `order_date`, `name`, `sku`, `units`, `selling_price`, `message`,
     `status_code`, `current_status`, `shipment_status`, `onboarding_completed_now`,
     `count`.
   - **Containers:** `response`, `data`, `order_items`, `errors`, `tracking_data`,
     `shipment_track`, `awb_assign_error`.
   - **Narrower key set:** under `order_items`, only `name`, `sku`, `units` and
     `selling_price` are kept.
   - **Dropped always:** every `billing_*` / `shipping_*` field, `email`, `password`,
     `token`, `phone`, `address`, `pincode`, `city`, `state`, `customer_*`.
   - **`name`:** kept only inside `order_items` (the product name). At the top level
     `name` is **not** allowed, because Shiprocket echoes consignee names under generic
     keys. So `name` is dropped from the global list and kept only via
     `CONTAINER_KEYS['order_items']`.
   - **`errors`:** the value is a map of field → messages. Keep the field keys; replace
     each message with the literal string `"invalid"`, because Laravel-style messages can
     echo input values.

3. **`app/Modules/Fulfilment/Services/ShiprocketClient.php`** — `final`, constructor
   `(ShiprocketPayloadScrubber)`.
   - **Config:** `email()`, `password()` private, `baseUrl()`.
     - `configured()`: email and password are non-empty, and the base URL is one of the
       two known hosts.
     - `hostMatchesEnvironment()`: production requires `apiv2.shiprocket.in`; every other
       environment requires `api-sandbox.shiprocket.in`. This is the Razorpay
       `modeMatchesEnvironment()` equivalent, so staging can never book a real courier.
   - **Token:** `POST /auth/login`, cached in the default cache store.
     - Key: `fulfilment:shiprocket:token:`.`sha1(baseUrl|email)`.
     - The value is a plain string (the cache stores no objects), with TTL 9 days
       (Shiprocket issues 10-day tokens).
     - On HTTP 401 from any call: forget the token, log in again **once**, retry once.
     - The login call writes an event row with `event_type` `auth.login` and a **null**
       payload. The request and response are never recorded, because they hold the
       password and token.
   - **Methods** (each takes `?int $shipmentId, ?int $orderId` for the event row):
     - `createAdhocOrder(array $body)` → `POST /orders/create/adhoc`. Not retried.
     - `assignAwb(int $srShipmentId)` → `POST /courier/assign/awb`.
     - `generatePickup(int $srShipmentId)` → `POST /courier/generate/pickup`.
     - `generateLabel(int $srShipmentId)` → `POST /courier/generate/label`.
     - `showShipment(int $srShipmentId)` → `GET /shipments/{id}`. Retried; used to
       resume a partial booking.
     - `trackShipment(int $srShipmentId)` → `GET /courier/track/shipment/{id}`. Retried.
   - **Transport** is the same as `RazorpayClient::request()`:
     - Bearer token, timeout from config, `connectTimeout(5)`.
     - Retries (3×300 ms, connection error or 5xx only) apply to GETs only. POSTs are
       never auto-retried, because a retried adhoc create could book twice.
     - Each call writes **one** `ShipmentEvent`: direction `outbound`, gateway
       `shiprocket`, `gateway_event_id` null, `gateway_shipment_id` when known, HTTP
       status, duration, and scrubbed `{request, response}` or error. The write is
       wrapped in try/catch, like `RazorpayClient::record()`.
   - **Log mirror:** default channel, metadata only (`event_type`, `http_status`,
     `duration_ms`, `error`). **No payload goes to logs**, to keep PII surfaces minimal.
   - **Errors:** a 2xx response with an application-level failure raises
     `ShiprocketApiException` too. Examples: `awb_assign_status` = 0, or `status_code`
     ≥ 400 in the body.

4. **`app/Modules/Fulfilment/Services/ShiprocketGateway.php`** — `final`, implements
   `CourierGateway`, constructor `(ShiprocketClient, FulfilmentSettings)`.
   - `name()` returns `Shipment::GATEWAY_SHIPROCKET`.
   - `permitted()` = `client->configured() && client->hostMatchesEnvironment() &&
     settings->shiprocketPickupLocation() !== ''`. It is purely technical; the flag and the
     enabled setting are checked by the resolver, matching how `PaymentGatewayResolver`
     separates them.
   - **`dispatch()` is resumable and never books twice for one shipment:**
     1. If `$shipment->gateway === 'shiprocket' && gateway_shipment_id !== null`, skip the
        create. Otherwise build the adhoc body and call `createAdhocOrder`, then
        **immediately** `$shipment->update(['gateway' => 'shiprocket',
        'gateway_shipment_id' => (string) $resp['shipment_id']])`. This runs outside any
        transaction, so it commits and a retry resumes.
        - The adhoc body's `order_id` is `order_no` (see Risks for a retry after a lost
          response).
     2. For the AWB: call `showShipment` first on a resumed shipment. If Shiprocket
        already has an AWB, use it. Otherwise call `assignAwb` and read
        `response.data.awb_code` and `courier_name`. On failure, throw
        `ShiprocketApiException`; the dispatch fails and the order stays
        `ready_to_ship`. Retrying resumes at step 2.
     3. `generatePickup` and `generateLabel` are **best-effort**. Catch
        `ShiprocketApiException` and return a null `labelUrl`; the event row already
        records the failure. The AWB is the booking, and the operator can reprint or
        request pickup in the Shiprocket panel.
     4. Return `CourierShipment(gateway: shiprocket, status: DISPATCHED, carrierCode:
        courier_name truncated to 32, awbNo: awb truncated to 64, gatewayShipmentId,
        labelUrl)`.
   - **Adhoc body:**
     - `order_id`: `order_no`.
     - `order_date`: `placed_at` formatted `Y-m-d H:i`.
     - `pickup_location`: from the setting.
     - Billing fields come from `$instruction->consignee`: `billing_customer_name`,
       `billing_address`, `billing_address_2`, `billing_city`, `billing_state`,
       `billing_pincode`, `billing_country` `India`, `billing_email` (buyer's order email
       if the order has one, else omitted), and `billing_phone` (the consignee phone
       reduced to its last 10 digits).
     - `shipping_is_billing`: true.
     - `order_items` built from `order->items`: `name` = `product_name_snapshot`,
       `sku` = `variant_sku_snapshot`, `units` = `qty`, `selling_price` =
       `unit_price_paise / 100`.
     - `payment_method`: `Prepaid`.
     - `sub_total`: `total_paise / 100`.
     - `length`, `breadth`, `height`, `weight`: from the Parcel estimate below.
     - **For a collection** (`consignee->isCollection()`):
       - the billing fields are the centre's address;
       - `billing_customer_name` is the centre name;
       - `billing_last_name` is omitted;
       - the collector's name goes into `comment` as "Collect for: <name>";
       - the collector's phone is not sent.
     - **If the consignee phone is null or not 10 digits,** throw `RuntimeException` with
       a clear message **before** any API call.
   - **Parcel details: no guessing (user decision 2026-09-24, option A).**
     `public function parcelGaps(Order $order): list<ParcelGap>` loads `order->items`
     with their `productVariant` and lists every line whose variant has `weight_g` = 0
     or a null or zero `length_mm`, `breadth_mm` or `height_mm`. A line whose variant no
     longer exists is also a gap.
     - `ParcelGap` is a new readonly DTO in `Data/ParcelGap.php` holding
       `productVariantId`, `productName`, `sku`, and `missing` (a list of `weight`,
       `length`, `breadth`, `height`).
     - `dispatch()` calls it first and throws `MissingParcelDetailsException` (new, in
       `Exceptions/`, carrying the gaps) **before any API call**.
     - There are no defaults. Slice 5's dispatch screen calls `parcelGaps()` to show the
       alert (each product, its missing fields, and a link to the product edit page) and
       disables the Shiprocket choice for that order; manual stays available.
     - The package sent: weight in kg = Σ(`weight_g` × qty) / 1000; length and breadth
       are the maximum across lines (converted mm to cm); height is Σ(`height` × qty),
       i.e. stacked. The minimum for every value is 0.5. The runbook notes this is a
       stacked-box estimate the operator can correct in the panel.
   - **`track()`:** returns null when `gateway_shipment_id` is null. Otherwise it calls
     `trackShipment` and maps `tracking_data.shipment_track[0].current_status`, upper-cased:
     - `DELIVERED` → `STATUS_DELIVERED`;
     - `RTO DELIVERED` → `STATUS_RETURNED`;
     - anything else non-empty → `STATUS_DISPATCHED`;
     - empty or unknown → null.

     Full status handling is Slice 6, through webhooks.

5. **`app/Modules/Catalog/Database/Migrations/2026_09_24_100000_add_dimensions_to_product_variants.php`**
   - Adds `length_mm`, `breadth_mm` and `height_mm`: `unsignedInteger`, **nullable**,
     placed after `weight_g`.
   - These are millimetres, which keeps them integers like `weight_g`. Null means
     "unknown", which the estimate treats as "use the default".
   - Strictly additive; `down()` drops the three columns.
   - Variant-level, not product-level, because weight already lives on the variant and
     sizes differ per variant.

### Modified files
6. **`config/arovolife.php`** — adds a `fulfilment.shiprocket` block after `payments`:
   - `email` = `env('SHIPROCKET_EMAIL', '')`, `password` = `env('SHIPROCKET_PASSWORD', '')`;
   - `base_url` = `env('SHIPROCKET_BASE_URL', 'https://api-sandbox.shiprocket.in/v1/external')`;
   - `webhook_token` = `env('SHIPROCKET_WEBHOOK_TOKEN', '')`, used by Slice 6;
   - `timeout_seconds` = `env('SHIPROCKET_TIMEOUT_SECONDS', 20)`.

   There are no parcel defaults, per the option-A decision.

   Comment: secrets only; the levers live in `settings`.

7. **`.env.example`** — a Shiprocket block after Razorpay with `SHIPROCKET_EMAIL=`,
   `SHIPROCKET_PASSWORD=`, `SHIPROCKET_BASE_URL=` (with a comment giving the sandbox and
   live hosts and the rule that each environment accepts only its own),
   `SHIPROCKET_WEBHOOK_TOKEN=` and `SHIPROCKET_TIMEOUT_SECONDS=20`.

8. **`app/Modules/Fulfilment/FulfilmentServiceProvider.php`** — registers
   `ShiprocketPayloadScrubber`, `ShiprocketClient` and `ShiprocketGateway` as singletons.

9. **`app/Modules/Fulfilment/Services/CourierGatewayResolver.php`** — injects
   `ShiprocketGateway`. `available()` adds it when all of these hold:
   - `Feature::for(null)->active(ShiprocketFulfilmentFeature::class)`;
   - `$settings->shiprocketEnabled()`;
   - `$shiprocket->permitted()`.

   The "joins here in Slice 4" comment is replaced.

10. **`app/Modules/Fulfilment/Services/DispatchService.php`** — moves the remote call out
    of the transaction:
    1. Take `Cache::lock('fulfilment:dispatch:order:'.$order->id, 120)`. If it is already
       held, throw `RuntimeException` "This order is already being dispatched".
    2. Run `transaction(pack if packed_at null)`.
    3. Load the shipment and call `$gateway->dispatch(...)`, **outside a transaction**.
    4. Run `transaction(...)`:
       - lock the order row (`lockForUpdate` on the order) and re-check its status is
         still paid or ready_to_ship;
       - if it is no longer, throw with a message naming the gateway shipment id so the
         operator cancels it in the panel;
       - `markShipped`, then the shipment update, then the audit.
    5. Release the lock in `finally`.

    The shipment update gains one guard: `gateway_shipment_id` is only overwritten with a
    non-null value. A manual dispatch after an abandoned Shiprocket booking keeps the
    Shiprocket reference, and the audit details gain `abandoned_remote_booking` (the
    Shiprocket shipment id or null). Without this, the reference to a real remote
    consignment would be silently lost.

11. **Catalog, for the new dimensions:**
    - `ProductVariant.php` gets the fillable entries, `int` casts and `@property` docs.
    - `ProductRequest.php` validates each dimension as `nullable|integer|min:1|max:5000`.
    - `AdminProductController.php` passes `length_mm`, `breadth_mm` and `height_mm` through
      as int-or-null, next to `weight_g` at line ~209.
    - `resources/views/admin/catalog/products/form.blade.php` gets three number inputs
      after Weight, labelled "Length (mm)", "Breadth (mm)" and "Height (mm)", each with an
      `x-help-tip`: "Packed size of one unit. Sent to the courier to price the shipment;
      required before the order can be sent through Shiprocket."
    - The help-tip text on Weight changes from "Used to calculate delivery charges" (not
      true: the delivery fee is a flat setting) to "Sent to the courier to price the
      shipment."

12. **`tests/Feature/Fulfilment/CourierGatewayTest.php`** — the existing test "offers
    manual only while there is no courier account" stays. It gets a `beforeEach` that
    blanks the `shiprocket` credentials in config, because docker-compose may inject real
    `SHIPROCKET_*` values into the container env and the tests must never see them.

13. **New test file: `tests/Feature/Fulfilment/ShiprocketGatewayTest.php`** (Pest). It
    calls `Http::preventStrayRequests()`, sets the config credentials explicitly with the
    sandbox URL, and resets the `FulfilmentSettings` singleton.
    - **T8a:** blank credentials → `permitted()` is false; the resolver is manual only
      even with the flag on, the setting enabled and the pickup set.
    - **T8b:** the live host in a non-production environment → `permitted()` is false.
    - **T8c:** everything set → the resolver offers `[manual, shiprocket]`. It is manual
      only when the flag is off, when `enabled` is false, or when the pickup is blank.
    - **T9:**
      - fake login, adhoc, awb, pickup and label, then call `dispatch()`;
      - the result carries the AWB, the courier and the label;
      - exactly one `orders.create` outbound row, one `courier.assign_awb` row and one
        `auth.login` row with a null payload;
      - no event payload JSON contains the buyer phone digits, the buyer name, the
        address line, the pincode, the password or the token.
    - **T9b (resume):** a shipment that already has a Shiprocket `gateway_shipment_id` →
      `assertNotSent` for create; `showShipment` with an existing AWB → no assign call.
    - **T9c:** a 401 on assign → exactly one re-login, then success.
    - **T9d:** the AWB assign fails (`awb_assign_status` 0) → `ShiprocketApiException`;
      `gateway_shipment_id` is persisted; the event rows exist.
    - **T9e:** the label call fails → the dispatch still succeeds with `labelUrl` null.
    - **T9f (collection):** the adhoc body is sent to the centre's address and contains
      no collector phone (checked with `Http::assertSent`).
    - **Parcel gaps:**
     - a zero weight, a null dimension or a deleted variant appears in `parcelGaps()`
       with the right `missing` list;
     - `dispatch()` then throws `MissingParcelDetailsException` with **no** HTTP request
       sent (`Http::assertNothingSent`);
     - `DispatchService` with route `shiprocket` rethrows it rather than falling back to
       manual silently;
     - complete data → the package values sent are the stacked estimate.
    - **Scrubber unit test:** an `errors` value becomes `"invalid"`; a top-level `name`
      is dropped.
    - **DispatchService:**
      - a gateway throwing → the order stays `ready_to_ship` and the outbound failure row
        survives, proving the transaction split;
      - a second concurrent dispatch while the lock is held → refused;
      - a manual dispatch after an abandoned Shiprocket booking keeps
        `gateway_shipment_id`.
    - **Catalog:** the admin product update saves the three dimensions (extends the
      existing product request test if one exists, otherwise a small feature test).

## Sequencing (each step compiles and passes before the next)
1. Exception and scrubber, with the scrubber test.
2. Config and `.env.example`.
3. Client.
4. Variant dimensions: migration, model, request, controller and form.
   `php artisan migrate` (forward-only) on dev.
5. Gateway and provider.
6. Resolver.
7. DispatchService split and its tests.
8. Full Fulfilment, Catalog and Commerce test run on `arovolife_test` with the `-e`
   overrides; Pint `--dirty`; Larastan on the touched paths.
9. **Sandbox verification (Phase 5):** from the local app container, run a tinker script
   that dispatches one seeded local paid order through `DispatchService` with route
   `shiprocket`. Record the AWB or the exact sandbox error, check the `shipment_events`
   rows by eye for PII, then open the product form in the browser to confirm the three new
   inputs save. Setting `fulfilment.shiprocket.enabled` = true on **local only** is needed
   for this; it is an audited settings write.

## Risks and forks
- **Adhoc create with a lost response.** Shiprocket accepted the create, the response
  never reached us, so `gateway_shipment_id` was never persisted. A retry sends the same
  `order_id` (`order_no`). Shiprocket's behaviour on a duplicate `order_id` is
  unverified: it may update or it may duplicate. Step 9 verifies it on the sandbox by
  creating the same `order_no` twice. If it duplicates, a pre-create lookup gets added
  (`GET /orders?search=<order_no>`).
- **The sandbox may refuse AWB assignment**, for example with a zero wallet or no
  serviceable courier. The definition of done then allows "exact error documented"; T9
  covers the logic with fakes.
- **Staging after deploy:** `config:clear` and a queue and scheduler restart.
  `fulfilment.shiprocket.enabled` stays false there until the user decides.
- **Production:** it doesn't exist yet. The live account needs the pickup
  `Arovolife-Hyd-Hub` added before `enabled` is turned on.
- **Out of scope** (later slices): the webhook (Slice 6), the admin dispatch queue and UI
  route picker (Slice 5), the runbook and ADR (Slice 9), and cancelling a remote booking
  from our side.

## Plan review outcome (2026-09-24) — supersedes the sections above where they differ

The plan was reviewed independently (13 findings), and the user decided two forks.

**User decisions**
- **Half-booking (review #1, option A).** `DispatchService` refuses a non-Shiprocket
  dispatch while the shipment has `gateway = shiprocket` and a `gateway_shipment_id`,
  unless the caller passes `confirmedRemoteCancelled: true`.
  - The error names the Shiprocket shipment id and tells the operator to cancel it in
    the Shiprocket panel first.
  - With the confirmation, the manual dispatch proceeds and the audit records
    `remote_booking_cancelled_by_operator` with the id.
  - The shipment row keeps `gateway = shiprocket` and its id. This fixes review #2: the
    row never becomes gateway=manual carrying a Shiprocket id. The manual carrier and AWB
    go on the order and the shipment's `carrier_code`/`awb_no`, as today.
  - Slice 5 renders the checkbox.
- **Email (review #5, option A).** `billing_email` is always
  `config('arovolife.support_email')`. No buyer email goes to Shiprocket, and
  `privacy.md` is unchanged.

**Folded in**
- **#3:** `DispatchService` throws when `$route !== null` and the resolved gateway's
  `name()` differs from `$route` (for example, Shiprocket was asked for but is
  unavailable). `route()`/`preferred()` are unchanged. The resolver docblock is
  updated, and the settings description at `AdminSettingsController.php:676` is updated
  if it claims a silent fallback.
- **#4:** a DB-atomic claim before the adhoc create:
  `Shipment::whereKey($id)->whereNull('gateway_shipment_id')->where('gateway','!=','shiprocket')->update(['gateway'=>'shiprocket'])`.
  - If the update affects 0 rows and there is still no `gateway_shipment_id`, another
    dispatch is in flight: refuse.
  - The `Cache::lock` stays as a UX guard only.
  - If the adhoc create fails with **no** response (connection error), the claim stays,
    and a retry must first try `GET /orders?search=<order_no>` to find a booking before
    creating again. This also covers the lost-response duplicate risk.
- **#6:**
  - pickup and label bodies send `shipment_id: [id]`;
  - `sub_total` = Σ `line_total_paise` / 100, the items only;
  - resuming from `GET /shipments/{id}` reads `data.awb` / `data.courier`;
  - the scrubber allows `awb` and `courier`;
  - `awb_assign_error` is a scalar that goes through the same message sanitiser.
- **#7:** `message` and `awb_assign_error` values are sanitised: runs of 6 or more
  digits become `#`, and the value is truncated to 200 characters.
- **#8:** the token is cached as `Crypt::encryptString(token)` and decrypted on read.
- **#9:** the host check uses `parse_url` strict host equality and `https`.
- **#10:** `phpunit.xml` gets `SHIPROCKET_EMAIL`, `SHIPROCKET_PASSWORD` and
  `SHIPROCKET_BASE_URL` forced empty. The new test files call
  `Http::preventStrayRequests()`. There is no global `TestCase` change, to limit blast
  radius.
- **#11:**
  - the relation is `OrderItem::variant()`;
  - `weight_g` = 0 stays allowed in the form (it means "unknown"), with a hint that
    Shiprocket needs it;
  - the migration comment says "null = not recorded; Shiprocket refuses the order";
  - verification step 9 first fills weight and dimensions on the test product through
    the admin form.
- **#12:** step 4 re-reads the order with `lockForUpdate()` and refreshes the model
  before `markShipped`. The redundant pack-wrapper transaction is dropped.
- **#13:** no exception message contains a phone number, name or address.
