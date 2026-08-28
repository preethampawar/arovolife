# Purchase Offers

Two offers for distributors who hold **no rank**, specified by KP on
2026-06-26. Both hang entirely off BV the distributor personally purchased —
purchases they made for themselves, not sales to other people. A month's
qualifying volume is filtered to `orders.self_consumption`, so retail sales
attributed to a distributor never count towards it.

> **Only the redeem-points half is live.** The half-price entitlement is
> granted and recorded, but nothing yet applies the offer price at cart or
> checkout, so it cannot be exercised. Its paragraph was removed from the
> published plan page and its card from My Offers rather than promise a
> mechanism that does not exist (R-49).

> **The "joining" trigger is gone.** The original description said the offers
> applied on "joining OR purchase OR repurchase". The Product Owner dropped
> joining on 2026-08-16, and nothing in the schema can express it. An offer
> earned by joining would break hard rule 1 (joining is free of cost) and hard
> rule 2 (value only from product sales). If anyone asks for it back, that is a
> compliance conversation, not a configuration change.

---

## 1. Half-price monthly product

A distributor who has **activated** (3,000 BV lifetime, the Retailer title) and
repurchases **1,000 BV in a month** may buy the company's announced product for
that month at **half the distributor price**.

Three things must all be true, and the third is the one that gets forgotten:

1. lifetime purchases at or above the activation threshold,
2. the month's own purchases at or above the qualifying volume, and
3. **a product actually announced for that month.**

With no product named, the engine grants nothing that month — an entitlement to
an unnamed product is not an entitlement. Announce it at
**Admin → Offers** before the month's run.

Once the month has been granted, the product can no longer be changed. Changing
it afterwards would leave distributors holding an entitlement the register says
was never offered.

A grant stays usable for the month it was earned in plus one more, then lapses.

---

## 2. Redeem points

**1,000 BV in each of six consecutive months** earns **20% of the BV
accumulated across those six months** as redeem points. One point is ₹1 off a
future purchase.

- A second consecutive six months earns another 20%.
- Completing **twelve** consecutive months adds a further **10% of the whole
  year**, on top of that second cycle's award.
- One month below the threshold **resets the streak to zero.** There is no
  partial credit and no carry-over.
- A refund reduces **the month the returned purchase belongs to**, not the
  month of the refund. A reversal is written with today's date, so a month
  defined by that date would leave the earning month still qualifying on a sale
  that came back and would break whichever later month the refund landed in.
  The month is defined by which orders accrued in it.

### Points are not money

They are a discount entitlement earned from a distributor's own purchases.
They live in their own ledger, never in the wallet:

- they are **never paid out** and cannot be withdrawn,
- they attract **no TDS and no admin charge**,
- they can only reduce the **net product value** of a future order — never the
  GST, which the company remits in cash whatever the buyer paid with, and never
  the delivery charge, which is a real third-party cost.

A wallet balance that mixed cash the company owes with a purchase discount
would mean two things at once and reconcile as neither.

If an order paid partly in points is refunded, **the points come back in
points and the cash refund is reduced by their value**. They are not converted
to cash: that would make them a stored value rather than a discount, and it is
exactly what the first implementation did before the compliance review caught
it. The restoration is idempotent, so a retried refund cannot mint them.

---

## Who is eligible

Both offers are **exclusively for distributors who hold no rank**. A distributor
who has ever qualified for a rank is excluded from both, and their My Offers
page says so plainly rather than leaving them wondering why a streak never pays.
Points already earned stay theirs and can still be spent.

---

## Running it

| Command | What it does |
|---|---|
| `offers:monthly-run` | 2nd of the month, 06:00 IST. Evaluates the previous month. `--month=YYYY-MM` to target a specific one. |

It runs early and ahead of every bonus engine: it reads the previous month's BV
and nothing else depends on it, so a distributor sees what they earned before
the payout cycle starts. Idempotent per distributor per month. A flag-off run
reports **skipped**, never succeeded.

**Admin → Offers** shows what was granted for a month and lets you announce the
next product. Distributors see their own balance, streak and history at
**My offers**.

---

## Before this goes live

Ships **flag-off** with no trace: no My Offers page, no points field at
checkout, no admin screens, no settings keys.

1. **DSA §6.2 thirty-day written notice** — the offers change what a distributor
   gets for their purchases and so form part of the plan.
2. **`/p/compensation` §11.2** must carry the effective date from that notice.
3. **KP must confirm the two readings in R-48** — see below.
4. **A decision from counsel on the §15(3)(a) reduction.** The code no longer
   leaves this implicit: it takes the conservative position that a coupon and
   redeemed points reduce the **amount payable**, not the taxable value, so the
   invoice, `orders.gst_paise` and the ledger all carry the same GST figure.
   That means the company remits GST on the full sale value and absorbs the
   discount out of net revenue. Whether to claim the reduction instead is a tax
   question, not a coding one — and it applies to coupons exactly as much as to
   points.
5. **An expiry policy** and the sweeper that writes it. `TYPE_EXPIRY` exists in
   the ledger and nothing ever writes it, so the obligation is open-ended.
6. **An accounting position** recognising the outstanding balance as a
   liability (Ind AS 115).

### The two readings that need KP (R-48)

Both are implemented as literally written, and both change who gets paid and
how much:

- **"do not hold any rank"** is read as *has never qualified for a rank*. Rank
  achievement is permanent in this plan, so "currently unranked" and "never
  ranked" are the same set today — but if KP meant "not ranked in the qualifying
  month", the eligible population is larger and the cost is higher.
- **"20% of total BV"** is read as 20% of the BV accumulated over the streak, at
  one point per rupee of BV. The alternative — 20% of the rupee value spent —
  gives a different and generally larger number.

Do not switch the flag on until both are answered in writing.
