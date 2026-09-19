# Admin Dashboard

**Admin → Dashboard** (`/admin`, the console landing page). A snapshot of the
business as it stands right now: what sold, what is stuck, what stock is at
risk, what the plan cost, whether the engines ran, and who joined.

The page holds no state of its own and stores nothing. Every figure is read
from the service that already owns it, so the dashboard and the report it
summarises can never drift apart.

---

## How the page loads

The dashboard itself runs **no database query**. It paints immediately as a set
of titled placeholders, and each panel then fetches its own content in a
separate request — the top two as soon as the page opens, the rest when you
scroll near them.

That is why panels arrive a moment apart rather than all at once, and why
opening the console is cheap even when you only wanted the sidebar.

## How fresh the numbers are

Each panel carries an **as of HH:MM** stamp in its header. That is when the
figures were computed, not when the page was drawn.

Panel content is cached for **60 seconds**. Within that minute every admin who
opens the dashboard is served the same computed numbers. The refresh control
beside the stamp re-fetches that one panel; if the cache is still warm you will
get the same figures back, which is correct — the number has not changed, only
your patience has.

Each panel then refreshes itself about once a minute, so a dashboard left open
on a wall display stays current without anyone touching it. Two limits keep that
cheap. A panel still below the fold that you have never scrolled to is not
refreshed — it has nothing to refresh yet. And a dashboard in a background tab
refreshes nothing at all; it catches up the moment you switch back to it.

Because the refresh interval and the cache window are both a minute, an
automatic refresh usually costs one cached read per panel, not a recomputation.
If a refresh fails — the network drops, the session expires — the panel keeps
showing the figures it already had rather than replacing them with an error, and
tries again on the next turn.

## What you can see

Each panel is gated on the permission that already gates the screen it
summarises — you see a summary exactly when you could open the page behind it.
A panel you may not see is never sent to your browser at all.

| Panel | What it shows | Permission |
|---|---|---|
| **Needs attention** | The Action Center summary, grouped. Same counts, same snooze rules, same 60-second cache as the Action Center screen itself. | `action.center.view` |
| **Sales** | Orders, revenue, amount collected and BV for today, the last 7 days and the month so far. | `sales.report.view` |
| **Order pipeline** | How many orders sit at each stage from placed through to confirmed, plus cancellations and refunds. | admin console access |
| **Stock & warehouses** | Low stock, expiring and expired batches and what the stock is worth. Active warehouses, open purchase orders and transfers in transit appear only for staff who hold `inventory.manage`, because those are the screens they link to. | `inventory.view` |
| **Payouts & commission** | The latest payout batch, batches awaiting approval, money held in wallets that nothing can pay out, and what each bonus has cost this month. | `finance.record` |
| **Engine health** | Whether the compensation engines ran: failures, missed periods, stuck runs, premature freezes and nightly-chain alerts. | `finance.record` |
| **Network** | Active, pending and blocked distributors, cooling-off windows, and who joined this month. | admin console access |

Stock & warehouses disappears entirely when `InventoryFeature` is off, and Needs
attention when `ActionCenterFeature` is off — not greyed out, absent.

---

## Reading the numbers correctly

**Sales counts on the order date.** "Today" means orders *placed* today, not
orders shipped today. The Profit report deliberately uses the ship date
instead, because cost is stamped when an order is packed — so the two will not
agree, and neither is wrong. Use this panel for "what came in", the Profit
report for "what we earned on it".

**Revenue is net of GST. "Collected" is not.** Revenue excludes the tax you are
holding on the government's behalf. Collected is the full amount the buyer
handed over, GST, shipping and any collection fee included. They are different
numbers on purpose.

**Refunds are never netted into the figures above them.** They appear on their
own line. A month with strong sales and heavy refunds should look like exactly
that, not like a quiet month.

**The order pipeline is not a work queue.** It counts every order at each stage,
including ones that arrived a minute ago. Needs attention counts only the ones
that have been waiting past their SLA. The pipeline number is always the larger
of the two, and that is not a disagreement.

**Held money has not been paid and has not been lost.** It is income that
accrued to distributors the payout run could not pay — no bank account on file,
web-only, KYC still pending, or bank details that would not decrypt. It sits in
their wallets until the blocker clears.

**Network counts are distributors, not user accounts.** Every figure joins the
distributor record, so staff logins and abandoned half-finished signups are
excluded. An earlier version of this page counted user rows and reported eleven
pending registrations while the KYC queue held one.

## What this page will not tell you

**It does not forecast.** No run-rates, no projections, no "on track for"
figures anywhere on this page or anywhere else in the platform. Every number is
something that has already happened or is true right now. (Hard rule 3 — see
*Compliance Do's & Don'ts*.)

**It does not show trends.** There are no charts and no date-range picker. For
funnels, retention and totals over a window you choose, use **Admin →
Analytics**.

**It does not show grievances.** There is no grievance panel. The monthly
grievance report loads whole tickets for a month into memory, which is far too
heavy for a page that reloads this often, and a landing page is the wrong place
to put complaint volumes in front of everyone who opens the console. Breached
grievance SLAs still reach the people who can act on them, through the **Needs
attention** panel and the Action Center screen.

The grievance rows in **Needs attention** are split by category, the same way
the grievance queue is. *Grievances past an SLA clock* counts everything except
ethics, conduct and privacy, and goes to every holder of `grievance.handle`.
*Ethics & privacy grievances past an SLA clock* counts exactly the categories
the first one leaves out, and goes only to staff holding
`compliance.discipline` — the permission that already gates those tickets on
the grievance queue, the ticket page and the monthly report. The third-party
overdue rows are split the same way.

So the count you see is a count you are cleared to see the detail behind, and
no statutory clock is hidden from everyone: it moves to the compliance desk
rather than disappearing. This closed R-100 in the risk register.

## If a panel says it could not load

A panel that fails shows a short message and a **Try again** link, and the rest
of the page keeps working. If one keeps failing, the underlying report page for
that panel will show the same error with more detail.
