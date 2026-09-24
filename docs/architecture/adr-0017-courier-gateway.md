# ADR-0017: Couriers behind a gateway contract, bookings claimed and searched before created, and webhooks re-verified

- **Status:** Accepted
- **Date:** 2026-09-24
- **Deciders:** Platform Architect, Product Manager, Compliance Officer, the client
- **Builds on:** ADR-0011 (database queue), the Razorpay gateway pattern (R-56)
- **Plans:** `docs/plans/2026-09-18-order-fulfilment.md` (AD-4, AD-5), `docs/plans/2026-09-24-fulfilment-slices-5-9.md`

## Context

Until slice 5, dispatch was a form: staff typed a carrier and an AWB, and
`OrderStateMachine::markShipped()` recorded them. The client wants Shiprocket for
home deliveries and centre consignments, without losing the manual route. Three
properties had to hold:

- The feature must ship and be testable without a live Shiprocket account.
- A courier API that times out must never produce two paid bookings.
- An external signal that opens the buyer's statutory cooling-off window
  (hard rule 5) must not be forgeable.

## Decision

1. **One contract, two couriers.**
   - `CourierGateway` has `permitted()`, `preflight(Order)`, `dispatch(...)` and `track(...)`.
   - `ManualCourier` is always permitted and records what staff typed.
   - `ShiprocketGateway` is gated three ways: the Pennant flag, the
     `fulfilment.shiprocket.enabled` setting, and `permitted()` (credentials,
     an API host that matches the environment, a pickup location).
   - `CourierGatewayResolver` picks between them.
   - **Every dispatch goes through `DispatchService`**, which:
     1. runs `preflight` **before** packing, so a refusal leaves stock untouched;
     2. packs strictly for a courier route, or leniently for manual;
     3. calls the courier's `dispatch`, and records the shipment.
2. **A collection order still has a courier leg (AD-5).**
   - The consignee is the centre, not the buyer.
   - The centre receives a sealed consignment for one identified, already-paid
     buyer, holds no stock and bills nothing.
3. **Claim, then search, then create.**
   - A booking is claimed with a conditional update, so two operators can't
     both reach the API.
   - Before creating, the gateway searches Shiprocket for the order: a timeout
     is not proof that nothing was created.
   - A booking left without an AWB shows as *pending* on the order page. Manual
     dispatch is then refused until the operator confirms the remote booking is
     cancelled; that confirmation is audited.
4. **Webhooks are prompts, not evidence.**
   - The tracking webhook is authenticated by a static token (`x-api-key`,
     compared in constant time), stored after scrubbing to an allow-list, and
     deduplicated on the event tuple.
   - A queued job then re-reads the status from Shiprocket's API. Only an
     API-confirmed DELIVERED can mark a home delivery delivered, and it does so
     under an order lock.
   - *Amended 2026-09-24:* staff can trigger the same re-read from the order
     page (**Check courier status**, `commerce.order.manage`, throttled 10 a
     minute) for when a webhook never arrives. Both paths go through
     `CourierTrackingSync`; staff cannot supply a status. Every check is
     audited `shipment.tracking_checked`, and a delivery it finds is audited
     `order.delivered_by_courier` with the staff actor and `trigger: staff_check`.
   - A collection order is never delivered by the courier. It becomes delivered
     at the handover, against the buyer's collection code.
5. **Exceptions are flagged, never auto-resolved.**
   - RTO, lost and damaged statuses are recorded in `shipments.courier_status`
     and listed in the Action Center.
   - Nothing cancels, refunds or reverses BV on a courier's say-so.

## Consequences

- There is one choke point for dispatch. A second caller of `markShipped` would
  bypass the checks, parcel details and audit, as the unused `DispatchService`
  once proved (see the memory note "DispatchService had no caller").
- Shiprocket's static token is weaker than an HMAC signature, which Shiprocket
  does not offer. The API re-read is what makes a forged body harmless: the
  worst a forger can do is make us ask Shiprocket a question.
- The webhook path must avoid the words Shiprocket rejects ("shiprocket",
  "kartrocket", "sr", "kr"), hence `/webhooks/courier/tracking`.
- Parcel size is a stacked-box estimate. Couriers re-weigh parcels, and a
  persistent discrepancy is fixed in product data, not code.

## Alternatives rejected

- **Trust the webhook body.** It is simpler, but it lets anyone who learns the
  URL and token open cooling-off windows.
- **Poll Shiprocket on a schedule instead of using webhooks.** It costs API
  quota for every shipped order every run. It is kept only as the job's
  fallback retry.
- **Create-then-reconcile bookings.** A retry after a timeout would create a
  second paid booking before reconciliation noticed.
