# Shiprocket courier gateway: configuration and operations

The courier route for home deliveries and consignments to Arete Development
Centres (plan `docs/plans/2026-09-18-order-fulfilment.md`, slices 4–6; ADR-0017).
Manual dispatch always stays available beside it.

> **The one invariant.** An order moves on a Shiprocket signal only after we
> re-read the status from Shiprocket's API. A webhook body is a prompt, never
> evidence. A DELIVERED status opens the buyer's 30-day cooling-off window
> (hard rule 5), so it must not be something anyone who can reach a URL can
> assert.

---

## The three gates

Shiprocket is offered on the order page only when all three are true:

| Gate | Where | Owner |
|---|---|---|
| `ShiprocketFulfilmentFeature` (Pennant) | Admin → Feature flags | developer |
| `fulfilment.shiprocket.enabled` = `true` | Admin → Settings → Fulfilment | admin |
| `permitted()`: credentials set, API host matches the environment, pickup location set | `.env` + `fulfilment.shiprocket.pickup_location` | ops |

**The host check:** production accepts only `apiv2.shiprocket.in`, and every
other environment accepts only `api-sandbox.shiprocket.in`. So a staging build
can never book a real courier against the company wallet.

## Environment

| Variable | Notes |
|---|---|
| `SHIPROCKET_EMAIL` / `SHIPROCKET_PASSWORD` | The API user, not a panel login. Never logged. |
| `SHIPROCKET_BASE_URL` | Defaults to the sandbox. |
| `SHIPROCKET_WEBHOOK_TOKEN` | A random secret, at least 32 characters (`openssl rand -hex 32`). While it is blank the webhook answers 404. |
| `SHIPROCKET_TIMEOUT_SECONDS` | The default is 20. |

`.env` is edited by hand on each server. Run `php artisan config:clear` and
`php artisan queue:restart` afterwards.

## Pickup location

Set `fulfilment.shiprocket.pickup_location` to the **exact nickname** of a
pickup address in the Shiprocket panel (Settings → Pickup addresses).

## Tracking webhook

**Where to set it:** Shiprocket panel → Settings → API → Webhooks.

- **URL:** `https://<host>/webhooks/courier/tracking`
- **Token (`x-api-key`):** the value of `SHIPROCKET_WEBHOOK_TOKEN`

**URL-word restriction.** Shiprocket rejects a webhook URL that contains
"shiprocket", "kartrocket", "sr" or "kr". That is why the path is
`/webhooks/courier/tracking`. Do not rename it to anything with those words in it.

**Responses:**

| Code | Meaning |
|---|---|
| 404 | Flag off or no token. |
| 401 | Wrong token. |
| 400 | Not JSON. |
| 200 `queued` | Stored and a job dispatched. |
| 200 `duplicate` | Seen before. |

**What we keep and how updates are handled:**

- Only an allow-list of fields is stored (`ShiprocketPayloadScrubber`). Scan
  locations and buyer details are never kept.
- Duplicates are keyed on sha256(`sr_order_id|shipment_id|awb|current_status_id|current_timestamp`).
- `ProcessShiprocketWebhookJob` (queue `default`, 3 tries) hands the parcel to `CourierTrackingSync`, which calls `track()` and records `shipments.courier_status`. The staff **Check courier status** button uses the same service. Then:
  - **DELIVERED** on a home delivery that is still `shipped` → `markDelivered`, under an order lock, audited `order.delivered_by_courier`.
  - **A collection order is never delivered by the courier.** It becomes delivered at the handover, against the buyer's code.
  - **RTO…** → the shipment becomes `returned_to_origin`. The order is left alone.
  - **RTO, LOST, DAMAGED, DESTROYED or CANCELED** → the order appears in the Action Center under `orders.courier_exception`.

## Courier choice

Picking Shiprocket on the order page calls
`GET admin/commerce/orders/{order}/courier-quotes` (throttled 20 a minute),
which asks `GET /courier/serviceability/` with the pickup pincode, delivery
pincode (the centre's for a collection order), the parcel size and `cod=0`.
It uses a 10 s timeout with no retry, because a person is waiting.

- **Pickup pincode.** Read from `GET /settings/company/pickup` for the
  nickname in `fulfilment.shiprocket.pickup_location`, cached 24 h under a
  key that includes the nickname and API host. If the nickname isn't in the
  account, quotes are refused with that message; shipping without a choice
  still works.
- **At dispatch** the gateway re-quotes and books with `courier_id` on
  `/courier/assign/awb`. It refuses an id that is no longer offered, before
  anything is booked.
- `shipment_events` keeps courier id, name, rate and days for each quote
  call, and never the pincodes.

## Parcel size: the stacked-box estimate

Every variant needs a weight and a packed length, breadth and height. We
don't run a packing algorithm. The parcel is estimated as the items stacked:

- length = the longest item;
- breadth = the widest item;
- height = the sum of the heights × quantities;
- weight = the sum of the weights.

Couriers re-weigh parcels. If they keep charging for a heavier weight on
multi-item orders, the estimate is too small for how the warehouse actually
packs, so raise the product dimensions rather than changing the code.

## Triage

| Symptom | Cause / action |
|---|---|
| Order page: "already booked with Shiprocket" | A booking started but no AWB came back (timeout, or sandbox AWB limits). Look the order up in the panel. If the booking exists, cancel it there, then dispatch manually with the "I have cancelled it" box ticked. Retrying the Shiprocket route claims the existing booking rather than creating a second one. |
| Sandbox stops at "AWB not assigned" | The sandbox has limited AWB stock. This is expected on staging; it exercises the pending-booking path above. |
| Shiprocket option greyed out | The product parcel details are missing. The order page links each product. |
| Webhook 401 in the panel's test | The token doesn't match `.env`. Did you run `config:clear`? |
| Delivered in the panel but not on our side | Press **Check courier status** on the order page: it re-reads the API through `CourierTrackingSync`, the same path as the webhook job (audited `shipment.tracking_checked`; a delivery is audited `order.delivered_by_courier` with the staff actor and `trigger: staff_check`). If webhooks never arrive at all, check `shipment_events` (direction `webhook`) and the failed jobs. |
