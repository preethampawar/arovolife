# Cooling-off & Cancellation

> **Statutory and sacred.** A distributor may cancel within **30 days** of their
> Effective Date and receive a **full refund** of product purchases — one click,
> no questions asked (Direct Seller Agreement §4; DSR 2021). Never obstruct,
> delay, or discourage a cooling-off cancellation.

---

## The window

- The clock starts on the **Effective Date** (when registration is finalised).
- It runs for **30 days** (`cooling_off_end_at = Effective Date + 30 days`).
- During the window, cancellation is a right — it is **always** honoured in full.

### Reminders
The platform sends cancellation-window reminders at **D-7 and D-1** so no
one misses the window unaware. These are factual reminders, not a sales push.

---

## How a cancellation happens

A cancellation can be triggered three ways — all reach the same one-click flow:

1. The distributor clicks **"Cancel my registration"** on their dashboard.
2. They call support, and support invokes the admin cancellation tool.
3. They email support, and support invokes the admin cancellation tool.

**Pre-conditions:** it must still be within the window (`now ≤ cooling_off_end_at`)
and the account must be **Active** (not already Blocked/Terminated).

---

## What the cancellation does

- The account status becomes **Cancelled** (a cooling-off self-cancellation — a
  distinct, gentler closure than an admin **Terminated**).
- The distributor can no longer sign in.
- The event is recorded (`cooling_off_events`) and written to the **audit log**,
  with the actor being the distributor themselves for a self-cancel.
- A **full refund** of product purchases is queued back to the original payment
  source.
- The Genos position is preserved as a "ghost" slot — the tree is **not**
  altered at cancellation time.
- A confirmation is sent by email and SMS.

> The cancellation itself is recorded immediately (within ~2 seconds of the
> request). The **refund** — the cash, and any points or repurchase credit used
> on the order — is released once the returned product is **received** (Admin →
> Returns → *Mark received*), and the cash target is **7 working days from
> receipt**, back to the original payment method (T&C §8).

> **Note:** joining is free, so there's nothing to refund for registration
> itself. A cooling-off refund applies only where the distributor has made
> **product purchases** — i.e. once commerce is live (Phase 2+). Cancelling
> always still closes the account and is always honoured within the window.

---

## Refund amount

For a **cooling-off cancellation** of saleable goods within 30 days, the refund
is the **full amount the customer paid** (DS Price incl. GST + shipping — full
`total_paise`). A GST credit note is issued (the sale is reversed). Other return
cases (general buyback, damage, dissatisfaction) follow a different matrix — see
**Compliance Do's & Don'ts → Returns, buyback & refunds**.

### Ledger treatment (Phase 2)

When the refund is approved, the platform posts a **double-entry ledger reversal**
immediately (ADR-0009):
- Dr `revenue.sales` · Dr `liability.gst_output` · Dr `revenue.shipping` (if paid)
- Cr `revenue.discounts` (if a coupon was used — reversal of the contra-revenue)
- Cr `liability.refund_payable` (what we now owe the customer)

The `refund_payable` liability is settled only when Razorpay confirms the
refund **processed** (webhook, or the reconciler), or when finance records a
manual NEFT settlement. Until then the order status is `refund_approved`. For a
cooling-off return the gateway refund is **held** until an operations user marks
the return received; the buyer is told the clock starts at receipt. The order
does **not** show `refunded` until the gateway confirms the money has moved.
Unsettled refunds — held, failed, or ageing — are listed at Admin → Payments →
Unsettled refunds, with alerts at 10 days without receipt and an escalation to
the Grievance Officer at 21.

> **Admin note:** "refund_approved" in the ledger ≠ "money back in the customer's
> bank." The order moves to `refunded` only when Razorpay confirms the refund
> processed, or finance records a manual NEFT. Communicate to customers:
> *"Refund initiated — credited within 7 working days."*

### BV treatment

Alongside the money reversal, the order's **personal BV** is reversed and its
**Genos BV** is reversed from exactly the upline distributors it was originally
credited to, on the same side, up to the company root — with **no clawback** of
GSB already credited or paid (what the unsettled day can't absorb becomes a
same-side adjustment against future Genos BV). See **Compensation → Cancelled /
refunded orders — Genos BV reversal**.

---

## Edge cases

| Situation | What to do |
|---|---|
| The "Cancel" click does nothing | Likely a browser error or a rate limit. Process the cancellation manually via the admin tool so the distributor isn't blocked from their right. |
| Distributor says they cancelled but status is still Active | Check `cooling_off_events`. If a record exists, the status update lagged — force it and check the worker logs. |
| Confirmation email/SMS didn't arrive | The cancellation still stands. Provide a written acknowledgement and log the delivery failure. |
| Distributor asks to cancel **after** day 30 | The cooling-off right has ended. Explain clearly and offer the alternative termination/buyback path — don't pretend the window is still open. |

---

## Do / Don't

- **Do** process every in-window cancellation immediately and in full.
- **Do** make the refund traceable (audit log + ledger).
- **Don't** add friction, "retention" steps, or fees to a cancellation.
- **Don't** convert a cooling-off cancellation into an admin **Terminate** — they
  are different closures with different meaning and refund treatment.

> Related: **Status Reference** (Cancelled vs Terminated), **Admin Actions**
> (cancellation vs termination), **Compliance Do's & Don'ts** (refund matrix).
