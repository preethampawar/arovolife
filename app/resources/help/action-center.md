# Action Center

**Admin → Action Center** (the first item in the sidebar, with a badge counting
your critical items). One screen that answers "what needs a human right
now" — it aggregates signals that already exist across the platform and
ranks them, rather than being its own source of truth.

Nothing here is stored state. Every count and every row is a live query
against the same tables the owning screens already use (orders, stock,
refunds, KYC, grievances…). The Action Center never re-implements a fix —
every row deep-links to the existing screen that actually resolves it. The
only thing it writes is a **snooze**.

---

## The six groups

| Group | What it watches | Typical permission |
|---|---|---|
| **Orders & fulfilment** | Placement, payment, packing, shipping, delivery tracking, returns. | `commerce.order.manage` |
| **Stock** | On-hand levels, batch expiry, transfers, purchase orders. Hidden entirely when `InventoryFeature` is OFF. | `inventory.view` |
| **Money** | Failed/overdue refunds, payout batch approvals, missing bank details, payment reconciliation gaps. | `finance.record` / `finance.approve` |
| **People** | KYC review, distributor requests, line-change decisions, Arete Development Centre applications, cooling-off expiry, dormant accounts. | `kyc.review`, `distributor.request.handle`, `placement.decide`, `adc.application.review` |
| **Compliance & platform** | Grievance SLA clocks, message moderation, unpublished consent-linked pages, compensation engine health. | `grievance.handle`, `messaging.moderate`, `content.publish` |

You only ever see the groups and rows your permissions allow — a provider is
scoped to its own permission, so Finance never sees a KYC queue they cannot
clear, and vice versa.

## Severity and SLA promotion

Every action type has a **ceiling severity** — INFO, WARNING or CRITICAL —
set by what the condition means. An item that has gone past its published SLA
is **promoted** to the next severity up, so a routine WARNING that's been
sitting for a week reads as more urgent than a fresh one. Nothing here is a
stored priority column; it is computed every time from the age of the item
against its type's clock.

Some items carry a **statutory** SLA rather than an operational one — refund
promise windows, grievance clocks, a missing GST invoice, a return held past
its receipt window. These are commitments to regulators or customers, not
internal targets, and the platform treats them differently (below).

## Fixing something

Every row has a **Fix →** link straight to the screen that resolves it — the
order detail page, the KYC queue, the refunds list, whatever owns that
state. The Action Center does not add new "approve" or "settle" buttons of
its own; it only routes you to the ones that already exist.

## Snooze

A manager can **snooze** a non-statutory item for a chosen number of days
(capped at an admin-configured maximum, 30 by default), with a required
reason of at least ten characters — this is a deliberate, audited statement
that the item is known about and deferred, not a way to make it disappear.
Un-snoozing works the same way and needs no special permission beyond the
provider's own.

> **Statutory items refuse to snooze.** Grievance SLAs, the refund promise
> window, a missing tax invoice, and a return held past its receipt window
> are not a manager's to defer — they must be resolved. The Snooze control
> does not appear for these rows at all, and attempting the request directly
> is rejected outright.

A snooze expires on its own — there is no sweep job to run — and while it is
active the item is hidden from both counts and lists.

## The 60-second summary cache

The counts and severities you see on the main Action Center page are cached
for **60 seconds per user**. That means fixing something on its own screen
(packing an order, for example) will not immediately update the Action
Center count you're looking at — it can take up to a minute, or a browser
refresh, to catch up.

The **item list** for a single type (what you see after clicking into a
group) is never cached — it always runs a live query, so once you're looking
at the actual rows, what you see is current. Snoozing or un-snoozing an item
also invalidates the summary cache immediately, so the count updates right
away rather than waiting out the minute.

## If an item you expect to see isn't there

- **Stock actions need `InventoryFeature` ON.** With the flag off, the whole
  Stock group is hidden, even though the underlying data is unaffected — see
  [Inventory Management](inventory-management).
- **Check your permission.** If you don't hold the permission a group's
  actions are scoped to, you won't see that group at all — not an empty
  group, no group.
- **The condition may simply not be met yet.** Most items only appear once
  an item is past its SLA — an order paid ten minutes ago is not yet a
  `paid_not_packed` action.
