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

**3% of the net product value of the orders handed over through the franchise**,
paid to the operating distributor.

Net product value is `subtotal − GST − discount`. Catalogue prices in this
system are **GST-inclusive**, so the tax has to be taken out before the rate is
applied — otherwise the company would be paying 3% of money it merely collects
for the government. Delivery is excluded too: it is a real third-party cost, not
revenue.

An order counts in the month its **30-day return window closes**, not the month
it was delivered. That means a returned order never enters the calculation at
all, so nothing ever has to be recovered from an operator after it has been
paid. An order delivered on 20 June is counted in the July run.

Every order that contributed to a month's commission is recorded against that
month's result row, so "which sales was this paid on?" stays answerable years
later, even after those orders have changed state.

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
| **Pending approval** | An application. Earns nothing. |
| **Active** | Earning monthly on the orders handed over through it. |
| **Suspended** | Stops earning. Orders already routed to it still need fulfilling. |
| **Closed** | Permanent. Commission already credited is unaffected. |

Creating a franchise, editing it, approving it and every status change all need
`compliance.discipline`, and all are audit-logged. Editing is included because
it sets the payee and the commission rate — a role that cannot approve a
franchise must not be able to change who it pays and how much. A franchise cannot be approved without either an
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
routes, no settings keys. Five gates before switching it on in production:

1. **DSA §6.2 thirty-day written notice** — the commission is a new earning
   stream in the compensation plan.
2. **`/p/compensation` §11.1** must carry the effective date from that notice.
   It is published today as *not yet operative*.
3. **R-24** — a written legal-counsel opinion on the combined binary-tree plus
   franchise surface. The Product Owner authorised the build on 2026-08-16;
   that authorises writing the code, not paying the money. The brief should also
   cover how the 3% is characterised for TDS (services vs plan commission) and
   whether the operator is making a taxable supply for GST.
4. **A DPDP recipient notice** — a distributor-operated pickup point receives a
   buyer's name, phone and order contents. That recipient category is not yet
   listed in the Privacy Policy §7 and no data-processing agreement is captured
   at approval. Both are required before any buyer data reaches an operator.
5. **R-47 fulfilment wiring**, below — plus an income surface for the operator.
   A wallet credit whose only explanation is a memo string is not a statement
   anyone can check.

**Two things are deliberately not built yet, and both gate the launch:**

**Fulfilment does not route through a franchise (R-47).** `orders.franchise_id`
exists and the commission reads it, but no shipment, admin screen, confirmation
page, invoice or email uses it. The checkout collection-point picker was
therefore **removed** before merge: offering a buyer a choice the pipeline
ignores would be a representation the system does not honour. Until fulfilment
is wired end-to-end — including a handover record as the evidence the commission
is paid against — there is no way for an order to reach a franchise at all.

**Consignment stock is not tracked (R-46).** Nothing records what stock
physically sits at a franchise or reconciles it. A franchise can only ever be a
handover point for centrally despatched orders, never a stockist, and the §6.2
notice must not describe it as one.
