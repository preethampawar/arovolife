# Stock & Inventory Reports

**Admin → Inventory → Reports.**
Ten reports over the same stock ledger described in
[Inventory Management](inventory-management) — each answers a different
operational question, all filterable by warehouse and, where relevant, by
date, and all downloadable.

Every report supports `?format=xlsx` (the default — a proper workbook) and
`?format=csv` as a fallback for anything that only reads plain text. Both
formats are built from the same rows, so nothing appears in one and not the
other.

---

## The reports

| Report | What it counts | When to use it |
|---|---|---|
| **Stock on hand** | Current on-hand, reserved, available and value per variant and warehouse, with a status (OK / low / out). | Daily check of what you can actually sell right now. |
| **Movement ledger** | Every stock movement — purchase, sale, return, transfer, adjustment, write-off, opening — filterable by type. | Tracing exactly what happened to a specific batch or variant, and when. |
| **Batch & expiry** | Batches by days-to-expiry, bucketed (expired / ≤30 days / ≤90 days / OK), with value at risk. | Planning what to rotate or discount before it expires. |
| **Low stock** | Variants at or under their reorder level. | Deciding what to reorder. Reorder level is set on the product form — see [Inventory Management](inventory-management). |
| **Stock valuation** | On-hand value at cost, per warehouse and per category. | Finance's view of what is sitting in the warehouses. |
| **Purchase register** | Posted goods receipts by supplier and month, with taxable value and GST — the GSTR-2 input-credit helper. | Monthly GST filing prep. |
| **Transfer register** | Transfers with status and in-transit quantity (dispatched minus received). | Following up transfers that have sat dispatched too long. |
| **Order fulfilment** | Orders by stage and age — paid-not-packed, packed-not-shipped, shipped-not-delivered — plus cancellations after pack and returns pending receipt. | The day-to-day fulfilment backlog. Mirrors what the Action Center's Orders group shows, over a live query rather than a cached count. |
| **Returns & restock** | Return requests with their inspection condition and whether/where they restocked. | Confirming a saleable return actually went back on the shelf. |
| **Stock in / out summary** | Opening + purchases + returns + transfers in − sales − transfers out ± adjustments, per variant for a period. | Reconciling that the arithmetic actually lands on the current on-hand. |

## Reading the stock in/out summary

This is the one report worth reading carefully rather than skimming. For each
variant and period it lays out every kind of movement as a running sum:

```
opening + purchases + returns + transfers in
        − sales − transfers out
        ± adjustments
        = closing
```

**The closing figure should always equal the current on-hand** for that
variant and warehouse. If it doesn't, that's the same signal
`inventory:verify` would raise — see the drift section in
[Inventory Management](inventory-management) for what to do about it. Use
this report when you want to see *why* a number moved over a period, not
just that it's currently correct or incorrect.

## Filters

Every report accepts a warehouse filter (leave it blank to see all
warehouses combined); the date-scoped reports (movement ledger, purchase
register, transfer register, order fulfilment, returns & restock, stock
in/out) also take a from/to range. Reports with no date meaning — stock on
hand, low stock, batch & expiry, valuation — always describe the position
**as of now**, the same way the distributor base panel on
[Analytics](analytics) does.

## What these reports are not

Like [Analytics](analytics), these are records of what already happened or
what the ledger currently shows — none of them project future stock needs or
sales. The "suggested reorder quantity" on the low-stock report is exactly
that: a suggestion computed from the reorder level and current availability,
not a forecast, and it is labelled as such on the page.

## If a number looks wrong

Start with **Stock on hand** and the **stock in/out summary** for the
variant and warehouse in question — together they show both the current
position and how it was arrived at. If the two disagree with each other, or
either disagrees with what you counted physically, treat it the same way as
any other drift: see the `inventory:verify` section in
[Inventory Management](inventory-management) before adjusting anything by
hand.
