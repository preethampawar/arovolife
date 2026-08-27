# Dormancy & Termination (§21)

Section 21 of the Direct Seller Agreement closes an account that has made **no
sale for twelve continuous months**, after **seven days of written notice**.
This page covers how that runs, and what it deliberately does not do.

---

## The clock

It starts at the **effective date** or the **last sale**, whichever is later —
so a distributor who has never sold is measured from the day they joined, and
one sale resets it completely.

A sale means an order attributed to that ADN in a `paid`, `ready_to_ship`,
`shipped`, `delivered` or `confirmed` state. **Cancelled and refunded orders do
not count** — the money went back, so no sale happened.

---

## Two stages, never one

| Stage | What happens |
|---|---|
| **Notice** | At twelve months the distributor is emailed a seven-day notice naming the exact date the account will close and stating that a single sale withdraws it. Nothing is frozen; nothing is lost. |
| **Termination** | If the notice expires with still no sale, the account is closed and the re-registration clock starts. |

The gap between them is the point. §21 promises notice *before* termination, so
a sweep that closed the account directly would breach the agreement even though
it ended in the same place.

**A sale inside the notice window withdraws the notice entirely** — no residue,
no shortened window next time. The nightly sweep clears revived notices *before*
it terminates anything, so a distributor who sold yesterday is never closed
today by a sweep that had not caught up.

---

## The master switch is OFF

`termination.inactivity_sweep_enabled` ships **off**. While it is off the
nightly sweep reports what it would do and writes nothing — no notices, no
closures.

Leave it off until someone has read the dormancy list end to end. There is no
path back from a terminated account, and the first automated run is also the
only chance to catch a sales-attribution bug before it closes distributors who
were in fact selling.

Before turning it on:

```
php artisan distributors:inactivity-sweep --dry-run
```

Read every line. Spot-check a few ADNs against their order history. Then flip
the switch in **Settings → Termination (dormancy)**.

---

## The admin page

**Admin → Dormancy (§21)**, gated on `compliance.discipline` — the same
permission as freeze and terminate.

Three views: **under notice**, **dormant with no notice yet**, and
**terminated for dormancy**. Each row shows the last sale, what the clock is
running from, and when the account becomes (or became) liable.

You can **withdraw a notice**, with a reason. That exists for one real failure
mode: the distributor did sell, but the order was attributed to the wrong ADN.
Stop the clock, then fix the attribution. The withdrawal is audit-logged with
your reason.

---

## Re-registration afterwards

On termination the platform records the date that PAN may hold an account
again: **one year** for a distributor who reached ranks 1–4, **two years** for
Diamond Partner (rank 5) and above, and **no wait at all** for someone who never
held a rank.

⚠ **Two caveats, both open.**

First, §21 names "Sales Master" and "Diamond Master" — ranks from an older
ladder that no longer exists. The mapping above is the closest faithful reading
and needs Product Owner confirmation before launch. It is configurable at
**Settings → Termination**.

Second, **the date is recorded but nothing consumes it yet.** The
`distributors.pan_hash` unique index means a terminated PAN cannot register
again at all, ever — which is stricter than §21 promises. Making re-registration
actually work is a decision about hard rule 6 (one PAN = one ADN): either the
old row is reused and the distributor keeps their ADN, or the index has to allow
one terminated row alongside one live one. That decision is with the Product
Owner; until it is made, a terminated distributor who wants back in must be
handled by hand.

---

## Commands

| Command | What it does |
|---|---|
| `distributors:inactivity-sweep` | Daily at 09:30 IST. Clears revived notices, terminates expired ones, issues new ones. `--dry-run` reports without writing; `--limit` caps how many accounts one run acts on. |

---

## What this is not

This is **not** the cooling-off cancellation — that is the statutory 30-day
window a new distributor uses to walk away with a full refund, and it lives in
**Cooling-off & Cancellation**. Nor is it admin termination for cause, which
stays a manual action on the distributor page and needs a written reason.
