# Razorpay payment gateway — configuration, go-live and operations

The online payment path (R-56). Built 2026-09-04 against Razorpay Standard
Checkout; ships **off** (`payments.gateway.razorpay.enabled = false`) until
the go-live checklist below is complete on the target environment.

> **The one invariant.** An order is marked paid only by
> `PaymentConfirmationService`, and only from a payment **fetched from
> Razorpay's API** and checked against the intent — never from a browser
> post, never from a webhook body, never by an admin's say-so.
> `tests/Feature/Compliance/MarkPaidChokePointTest.php` fails the build if a
> second caller of `OrderStateMachine::markPaid()` appears.

---

## Environment

| Variable | Value | Notes |
|---|---|---|
| `RAZORPAY_KEY_ID` | `rzp_test_…` / `rzp_live_…` | The prefix decides the mode. **Production refuses test keys; every other environment refuses live keys.** A mismatch closes checkout with a critical alert. |
| `RAZORPAY_KEY_SECRET` | | Never logged, never stored, never shown. |
| `RAZORPAY_WEBHOOK_SECRET` | | The secret set on the webhook in the Razorpay dashboard. Required — without it the gateway counts as misconfigured, because the primary confirmation authority would be dead. |
| `RAZORPAY_TIMEOUT_SECONDS` | `15` | API call timeout. |
| `PAYMENT_STUB_ENVIRONMENTS` | `local,testing` | The dev stub's allow-list. Ignored the moment the Razorpay flag is on or a live key is present. |

All three secrets are read from config (`arovolife.payments.razorpay`), so
run `php artisan config:cache` after changing them and restart the queue
workers (`queue:restart`).

## Gateway selection

| razorpay flag | credentials | environment | result |
|---|---|---|---|
| ON | valid, mode matches env | any | **Razorpay** |
| ON | absent / malformed / wrong mode | any | **checkout closed** (503 page; shop, cart, My Orders and cooling-off cancellation stay up); `Log::critical` + Slack once an hour |
| OFF | — | non-prod, allow-listed, no `rzp_live_` key present | **stub** (dev only; captures without money) |
| OFF | — | production | no online payment |

There is deliberately no fallback from Razorpay to the stub.

## Razorpay dashboard — one-time setup

1. **Webhook** → `https://<host>/webhooks/razorpay`, secret = `RAZORPAY_WEBHOOK_SECRET`, events:
   `payment.authorized`, `payment.captured`, `payment.failed`, `order.paid`,
   `refund.processed`, `refund.failed`.
2. **Settings → Payment capture**: automatic (the Orders API also asks for it per order).
3. **Enable duplicate-receipt validation** on the account, so two Razorpay orders can never share an `order_no`. Our intent key is the primary guard; this is the net.
4. **Refund speed**: our setting `payments.razorpay.refund_speed` (default `optimum`) is sent per refund; instant refunds carry a Razorpay fee.

## Go-live checklist (production)

- [ ] Live keys in the Cloudways env (production only), `config:cache`, `queue:restart`.
- [ ] Webhook subscribed with the six events and the secret; a test delivery returns `{"status":"queued"}`.
- [ ] Duplicate-receipt validation enabled.
- [ ] `payments.gateway.razorpay.enabled` → true in Settings (developer-owned); checkout renders "You will be taken to a secure payment page" and the stub is gone.
- [ ] **Live smoke test**: place a ₹1 order → pay → Payments timeline shows callback, webhook and `payment.confirmed` → order paid, invoice issued → open a return → refund sent → `refund.processed` webhook → order `refunded`, `liability.refund_payable` back to zero for that order.
- [ ] `payments:reconcile`, `orders:expire-unpaid`, `payments:redact-events` visible in `schedule:list`; the scheduler cron is running.
- [ ] R-56 row in the risk register updated with the smoke-test evidence; only then may it close.

## How it works, briefly

1. **Checkout** places the order (stock reserved, prepayment posted), creates a
   Razorpay Order (`receipt = order_no`, amount = exact payable) and redirects
   to `/shop/pay/{orderNo}`, which opens the Checkout modal. A zero-cash order
   (fully settled in points + repurchase credit) never reaches the gateway; a
   1–99 paise payable is taken to ₹1 at checkout.
2. **Confirmation** comes from three server-side paths, all through the same
   service: the signed browser callback (then re-fetched from the API), the
   webhook (stored first, applied by a queued job, re-fetched from the API),
   and the reconciler (every 5 min, every open intent older than 3 min). The
   status poll on the pay page also asks the gateway, at most every 5 s.
3. **Expiry**: `orders:expire-unpaid` cancels an online order left unpaid past
   `payments.unpaid_order_expiry_minutes` (default 30, floor 15) — after one
   more check with the gateway. A payment landing after cancellation is
   auto-refunded in full and alerted (`payment.late_capture`).
4. **Refunds**: `RazorpayRefundService` sends the **cash** portion only
   (points, credit and coupons never come back as cash — R-60). Held for
   cooling-off returns until `returns.receive` marks the goods received;
   settled only on `refund.processed` (or a manual NEFT by finance).
   Two clocks on a held return: alert at 10 days, grievance ticket at 21.

## Operations

| Symptom | Where to look | What to do |
|---|---|---|
| Order placed, buyer says paid | Admin → Payments → the payment | **Sync now** (finance). The gateway's answer is applied through the normal checks. |
| Checkout shows "temporarily unavailable" | `storage/logs/payments-*.log`, Slack | Keys missing / malformed / wrong mode. Fix env, `config:cache`. Nothing else is down. |
| Webhook 400s | `payments.log` "signature did not verify" | Secret mismatch between dashboard and env. |
| Refund failed at the gateway | Payments → Unsettled refunds | **Retry** re-drives the same refund; if Razorpay refuses (balance settled out, payment > 6 months old), pay by NEFT and **Settle by NEFT** with the UTR. |
| Cooling-off return not coming back | Unsettled refunds → awaiting receipt | Chase at 10 days (alert); at 21 a grievance ticket exists. Decide: received / courier lost / not returned — on the return page. |
| `payment_events` growing | daily `payments:redact-events` | Payloads are dropped after 180 days; the record stays. |

Logs: `storage/logs/payments-YYYY-MM-DD.log` (180 days), every call/callback/webhook, scrubbed.

## Standing constraints

- **PCI-DSS SAQ-A**: `checkout.js` is loaded from Razorpay's origin, the card form renders in Razorpay's iframe, no card field ever posts to our origin, and the payload scrubber keeps only `card.last4`/`network`/`issuer`. Do not self-host or proxy `checkout.js`, and do not build custom card fields.
- **DPDP**: contact, email, VPA, cardholder name, bank account, tokens and customer ids are dropped on write; acquirer references (RRN, ARN, UTR, UPI txn id) are kept for refund tracing (Rule 12 grievances). The Privacy Policy's processor table already names the gateway; sending the ADN in `notes` was deliberately **not** done.
- **Enum-free schema**: `payment_intents.status` and `refund_intents.status` keep their original enums; "expired" is `cancelled` + `cancel_reason`, "held" is `held_at` without `released_at`.

## Not built (next increments)

- Dispute / chargeback webhooks.
- Buyer notifications on refund settled and on a held return (the order page shows the state).
- COD refunds remain manual (see R-68).
- Coupon value on a non-cooling-off refund is not returned in any form (see R-69).
