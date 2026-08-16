# Franchise Programme

A **franchise** is an arovolife-owned pickup and despatch point operated by a
distributor. It is fulfilment infrastructure, not a shop — and that distinction
is the entire basis on which the compliance review permitted the feature.

Three things follow from it, and none of them is negotiable:

- **Stock is company consignment.** The franchise does not buy it, own it or
  sell it.
- **Sales stay online and ADN-attributed.** Choosing a franchise changes where
  the goods are handed over. It changes nothing about who the sale belongs to.
- **A franchise has no position in the Genos.** The code (`FR-XXXXX`) is
  deliberately unlike a nine-digit ADN. There is no parent, no side, no depth.
  Operating a franchise does not move the operator in the tree or change what
  their team earns.

If anyone proposes a walk-in counter sale, a franchise "buying stock", or a
franchise appearing anywhere in a genealogy view — stop and escalate. Those are
the things the design constraints exist to prevent.

---

## The commission

**3% of the product value of the orders the franchise fulfils in a month**,
paid to the operating distributor.

| Included | Excluded |
|---|---|
| Order subtotal | GST — tax collected for the government, never company revenue |
| less any discount | Delivery charges — a pass-through cost |

An order counts in the month it is **delivered**, not the month it is placed:
the franchise is paid for handing goods over, so an order still on the shelf has
earned nothing yet. Cancelled and refunded orders never count.

The rate lives at **Settings → Compensation plan → Franchise commission rate**.
An individual franchise can carry its own rate on its register entry, capped at
10% — use that only where the signed franchise agreement genuinely says
something different, not to reward a good month.

The rate is **snapshotted on each month's result row**, so changing the plan
rate never restates a month already paid.

Franchise commission is **exempt from the admin charge**, on the same footing as
awards: it pays for work performed, not for a position in the plan.

---

## Lifecycle

| State | What it means |
|---|---|
| **Pending approval** | An application. Earns nothing, invisible at checkout. |
| **Active** | Selectable as a collection point, earning monthly. |
| **Suspended** | Off the checkout picker immediately. Orders already routed to it still need fulfilling. |
| **Closed** | Permanent. Commission already credited is unaffected. |

Approval and every status change need `compliance.discipline` and a written
reason, and are audit-logged. A franchise cannot be approved without either an
operating distributor or the company-primary flag — an active franchise that
nobody operates would accrue a commission with nobody to pay it to.

The **company's own primary franchise** has no operator and earns nothing. The
company does not pay itself a commission out of its own revenue.

---

## Running the commission

| Command | What it does |
|---|---|
| `franchise:monthly-run` | 8th of the month, 09:45 IST. Credits the previous month. `--month=YYYY-MM` to target a specific month. |

Idempotent per franchise per month: a re-run skips anything already credited, so
it can be run again safely after a partial failure. A flag-off run reports
**skipped**, never succeeded.

**Admin → Franchises → Commission report** shows the workings per month. Rows
marked *not run* are a live projection of what the month currently holds —
useful for a mid-month view, but nothing is owed until the row says *credited*.

---

## Before this goes live

The feature ships **flag-off** and leaves no trace while off: no menu item, no
routes, no checkout step, no settings keys. Three gates before switching it on
in production:

1. **DSA §6.2 thirty-day written notice** — the commission is a new earning
   stream in the compensation plan.
2. **`/p/compensation` §11.1** must carry the effective date from that notice.
   It is published today as *not yet operative*.
3. **R-24** — a written legal-counsel opinion on the combined binary-tree plus
   franchise surface. The Product Owner authorised the build on 2026-08-16;
   that authorises writing the code, not paying the money.

**Consignment stock is not tracked yet** (R-46). The entity, the attribution and
the commission exist; nothing records what stock physically sits at a franchise
or reconciles it. Until that is built a franchise can only be a handover point
for centrally despatched orders — not a stockist — and the §6.2 notice must not
describe it as one.
