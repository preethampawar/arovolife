# Analytics

**Admin → Analytics.** Two funnels, a retention table and the shape of the
distributor base. Everything on the page is a record of what already happened.

> **Nothing here forecasts.** No trend line, no run-rate, no "at this rate you
> would earn". A chart that projects income is a chart somebody eventually puts
> in front of a prospect, and DSR Rule 5(1)(d) does not care that it was built
> for internal use. If you need a projection for a board pack, build it outside
> the platform and keep it there.

---

## The date window

Everything except **The distributor base** is scoped to the From / To dates.
The default is the last 30 days. The base panel is always "as of today" — it
describes the population now, not during the window.

---

## Registration funnel

Six milestones, in the order a joiner passes them:

| Stage | What it counts |
|---|---|
| Orientation started | Distinct people who opened the mandatory video |
| Orientation passed | …who watched enough of it and passed the micro-quiz |
| Agreements accepted | …who accepted a versioned document |
| Account created | Distributor rows created — an ADN has been issued |
| KYC verified | People with at least one verified identity document |
| Activated | Accounts made live |

Two things to keep in mind when reading it:

- **It counts people, not events.** Somebody who opens the orientation four
  times is one person at that stage.
- **It is not a cohort.** Each stage counts who reached *that* milestone inside
  the window, whenever they started. A short window can therefore show a later
  stage ahead of an earlier one — those are people who began before the window
  opened. For a true cohort, widen the window past the longest registration you
  expect to see.

The **% lost from the step before** is the number to act on. It isolates the
step that is actually leaking. The bar length shows survival from the *first*
stage, which always flatters the later steps and should not be read alone.

There is no per-wizard-step breakdown, because the platform does not record one.
This page measures only the milestones that leave a row behind rather than
inventing a drop-off it cannot see.

---

## Commerce funnel

Carts created → cart has items → order placed → paid → delivered.

- Carts include anonymous ones, so the first two steps are much larger than the
  rest. That gap is normal, not a leak.
- **Order placed** excludes drafts.

---

## Headline numbers

Paid orders, gross value, average order value, BV generated, and cancelled /
refunded counts — all for the window.

Cancelled and refunded orders are **excluded** from the first four and reported
separately. Average order value is rounded down to the rupee: quoting a mean to
the paisa implies a precision the underlying rounding does not have.

---

## The distributor base

| Figure | Meaning |
|---|---|
| Distributors | Every distributor row, whatever their status |
| Active status | Account status is `active` |
| Bought this month | Placed a settled order this calendar month |
| Bought in 90 days | Placed a settled order in the last 90 days |
| **Never bought** | Has never placed a settled order of their own |

**Never bought** is the one to watch. A distributor who has never bought has
nothing the compensation plan can pay on, and twelve months of it makes them
liable to the Agreement §21 dormancy rule — see
[Dormancy & Termination](dormancy-termination).

---

## Monthly buyer retention

Retention here is measured on **purchases, not logins**. A distributor who signs
in every month and never buys is not retained in any sense the business cares
about.

The percentage is the share of **last month's** buyers who bought again this
month. Measuring against *this* month's buyers instead would count brand-new
buyers as retained and would read higher every time recruitment went up.

A dash means nobody bought in the month before, so there was nothing to retain.

---

## Highest volume in the window

The ten distributors with the most BV attributed inside the window, with their
order count and Genos team size. Team sizes come from the same service the
genealogy screens use, so this table can never quietly disagree with them.

Deliberately **no rank, no earnings, no ratio**. This is an operational view of
where volume came from. It is not a leaderboard, and a screenshot of it must not
end up in a recruitment deck.

---

## What this page will not tell you

- Why somebody dropped out — only that they did.
- Anything about an individual's income.
- Anything about the future.

Cancelled and refunded orders never count as purchases anywhere on the page, so
a month propped up by orders that later came back will correct itself here
rather than keep flattering the numbers.
