# Payments & Refunds

How money comes in and goes back, and what each screen and button actually does.

## The one rule

**An order is never marked paid on anyone's word — only on Razorpay's.** The
browser tells us a payment happened; a webhook tells us a payment happened; an
admin can press *Sync*. In every case the platform then **fetches the payment
from Razorpay's API** and checks it: right gateway order, captured, in INR, for
the order's exact payable, nothing refunded, order still waiting. Only then is
the order paid, BV accrued and the compensation engines run. Marking an order
paid without verified consideration would manufacture commission liability
(hard rule 2), so there is deliberately no "mark paid" button.

## How a payment flows

| Step | What happens | Where you see it |
|---|---|---|
| Buyer places the order | Order is `placed`, stock reserved, prepayment posted to the ledger. Nothing charged yet. | Orders |
| Buyer pays in the Razorpay window | Razorpay captures the money. The browser posts a signed callback; Razorpay also sends a webhook. | Payments → Timeline |
| Confirmation | Either path fetches the payment from the API and confirms it. The order becomes `paid`; the GST invoice is issued. | Orders / Payments |
| Nothing happened within the expiry window (default 30 min) | The sweeper asks the gateway one last time, then cancels the order and releases the stock. A payment that lands after that is refunded in full automatically and alerted. | Payments (status *cancelled · payment expired*) |
| Callback lost, webhook delayed | The reconciler asks Razorpay about every open intent every five minutes. Nobody needs to notice. | Payments (*Last synced*) |

**Sync now** (Payments → a payment; `finance.record`) asks the gateway
immediately and applies its answer through the same checks. It is logged
against your user because, if the gateway reports a capture, BV accrues.

## Statuses on the Payments screen

| Status | Meaning |
|---|---|
| `created` | Waiting for the buyer, or for the gateway's confirmation. |
| `authorised` | The bank approved it; capture is automatic and follows within seconds. Not paid yet. |
| `captured` | Confirmed from the gateway; the order is paid. *via* shows which path confirmed it. |
| `failed` | Only ever set by the gateway's own report; the order stays placed and the buyer may try again. |
| `cancelled` | The order was cancelled or expired. If money landed afterwards it is refunded automatically (*late capture*). |

The **Timeline** on each payment lists every call we made and every callback
and webhook we received, with the gateway's response. Payloads are stored
already scrubbed — no contact, email, VPA or cardholder name is ever kept —
and dropped after 180 days; the transactional record stays.

## Refunds

A refund is owed the moment a return is approved or a paid order is
cancelled. It is **settled** — the payable in the ledger discharged, the order
marked `refunded` — only when **Razorpay confirms the refund processed**, or
when finance records a manual NEFT. Approving, cancelling, or marking a return
received never settles anything by itself.

The amount sent to the gateway is always the **cash the buyer paid**: points
come back as points, repurchase credit as credit, and a coupon discount does
not come back at all (it was never paid). This is the R-60 rule and it is
enforced in code.

### The cooling-off return-receipt gate

For a **cooling-off cancellation** the buyer's cancellation is instant and
unconditional — ledger reversed, BV reversed, clock closed. But the cash, the
points and the repurchase credit are **held together until the returned
product is marked received** (T&C §8: "within seven business days of
arovolife receiving the returned product"). The buyer is told this on their
order page.

Marking receipt is on the return (Admin → Returns → the return) and needs the
`returns.receive` permission (operations). Three outcomes, all audited:

| Outcome | Effect |
|---|---|
| **Received** | Points and credit restored; the cash refund released to the gateway. |
| **Lost by our courier** | Treated as received — our reverse pickup collected it. |
| **Not returned** | Refund forfeited (written back to revenue), entitlements stay withheld, order back to `delivered`. Only after the buyer has been told and given a reasonable chance. |

Two clocks run on a held return. **10 days** without receipt: ops are alerted
and the return is flagged on the worklist. **21 days**: a grievance ticket is
opened automatically for the Grievance Officer, so the case is on the
statutory register. Nothing auto-pays and nothing silently expires.

### The unsettled-refunds worklist

Payments → *Unsettled refunds* lists every refund not yet in the buyer's hands:

- **Awaiting return receipt** — held; release it from the return.
- **Queued / Sent** — on its way; the gateway usually confirms within minutes,
  the money reaches the buyer in up to 7 working days (Normal speed) or
  instantly (Optimum, where the bank supports it).
- **Needs manual settlement** — the gateway refused (insufficient balance after
  settlement, a payment older than the gateway's refund window, a rejected
  method). Two `finance.record` actions: **Retry** re-drives the *same* refund
  (it cannot create a duplicate), and **Settle by NEFT** records a bank
  transfer you have already made, with its UTR, and discharges the payable.

- **Owed outside the gateway** — a refund on an order that was never paid
  through the gateway (cash on delivery, or a payment recorded outside the
  platform). There is no gateway refund to send; finance makes the NEFT and
  records it against the *order* with its UTR. Same 7-working-day promise.

A refund closed as **not returned** (the buyer never sent the goods back) is
forfeited: the original refund entry is written back line for line, nothing is
owed, and it leaves the worklist. It cannot be retried or settled by NEFT —
that would pay a buyer who kept the goods.

### Paid orders without an invoice

The GST invoice is issued after a payment is confirmed. If that step fails the
order stays paid but has no invoice — a gap the Payments screen lists at the
top in red. Finance presses **Issue invoice**: the next consecutive number is
allocated, never a duplicate, and the action is audit-logged.

## When something looks wrong

- *Order says placed, buyer says paid.* Open the payment and press **Sync
  now**. If Razorpay has the capture, the order is confirmed on the spot.
- *Payment captured for the wrong amount, or for a different order.* The
  confirmation refuses it, logs a critical alert, and the money stays where it
  is until finance looks. It is never applied.
- *A paid order has no invoice.* It is listed on the Payments screen; issue it
  there. The `payments:reconcile` sweep also counts these every five minutes.
- *Checkout shows "temporarily unavailable".* The Razorpay gateway is switched
  on but its keys are missing, malformed, or the wrong mode for this host
  (test keys on production, live keys anywhere else). Nobody can order until
  it is fixed; existing orders, returns and cancellations are unaffected.

## Going live — the checklist that gates real money

Razorpay stays off (`payments.gateway.razorpay.enabled`) until: live keys are
in the environment on production only; the webhook is subscribed to
`payment.authorized`, `payment.captured`, `payment.failed`, `order.paid`,
`refund.processed`, `refund.failed` with its secret configured;
duplicate-receipt validation is enabled on the Razorpay account; and one live
smoke test has passed end to end (pay, confirm, refund, settle). See
`docs/runbooks/razorpay.md`.
