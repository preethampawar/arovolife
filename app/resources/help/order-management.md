# Order Management

**Admin → Commerce → Orders → an order.** The lifecycle of a customer order
from placement to delivery, and what each action along the way actually
does to stock and money.

> **Stock enforcement only blocks an oversell while `InventoryFeature` is
> ON.** With the flag off, orders can still be placed for more units than are
> physically available — every movement is still recorded correctly, but
> nothing stops the order at checkout. See
> [Inventory Management](inventory-management).

---

## The order lifecycle

| Status | What it means |
|---|---|
| `placed` | Buyer has checked out. Payment not yet confirmed. Stock is reserved (a counter), not yet deducted. |
| `paid` | Razorpay has confirmed the payment — see [Payments & Refunds](payments). GST invoice issued. |
| `ready to ship` (shown as **Packed**) | The order has been packed: stock has actually left the warehouse for these items. |
| `shipped` | Carrier and tracking number recorded. |
| `delivered` | Delivery confirmed. This is what opens the buyer's 30-day cooling-off window — see [Cooling-off & Cancellation](cooling-off). |
| `confirmed` | The buyer (or the system) has confirmed the order after delivery. |
| `cancelled` | Cancelled before or after packing — see below for what happens to stock. |
| `refund_requested` / `refund_inspection` / `refund_approved` / `refunded` | The return and refund path — see [Payments & Refunds](payments). |

## Packing an order

**Pack order**, on the order's detail page, is available once an order is
`paid` and not yet packed. You choose the warehouse to pack from (defaulting
to the inventory default warehouse); packing then:

- Builds a **pick list** — the exact SKUs, quantities, batch numbers and
  expiry dates the picker should collect — using **FEFO** (First-Expiry,
  First-Out): the batch with the nearest expiry date is allocated first, and
  a line can split across more than one batch if one alone doesn't cover the
  quantity.
- **Never allocates an expired batch**, even if it is the only stock showing
  on hand for that variant in that warehouse. If nothing unexpired is
  available, packing fails rather than shipping expired stock.
- Writes a sale movement for every unit taken, releases the reservation, and
  moves the order to **Packed** (ready to ship).

Packing an order cannot be undone by re-packing it — it is a one-way action
that consumes real stock. If the pack turns out to be wrong, cancel the order
(below) rather than trying to correct it in place.

## Shipping and delivery

**Mark as Shipped** records the carrier and tracking number and moves the
order to `shipped`. If an order is shipped directly from `paid` without an
explicit pack step, packing happens automatically first, so a sale movement
and pick list still exist behind it.

**Mark as Delivered** — labelled on the order screen as *"opens
cooling-off"* — is exactly that: delivery is the event the 30-day statutory
cooling-off clock starts from, not the order date or the ship date. See
[Cooling-off & Cancellation](cooling-off) for the full window and refund
mechanics.

## Cancelling an order

**Cancel Order** is only available before shipment (i.e. while the order is
`placed`, `paid`, or `packed` but not yet `shipped`).

- **Cancelling before packing** simply releases the stock reservation — no
  units ever left the warehouse, so nothing needs to be put back.
- **Cancelling after packing** reverses the pack: the same units that were
  taken out for this order are put back into the same batches they came
  from, and the shipment record is marked returned to origin. This is a real
  stock movement (a reversal), not a silent undo — it shows up in the
  movement ledger like any other stock change.

Once an order has shipped, it can no longer be cancelled outright — from
there the path is a return.

## Returns and restocking

A return that is inspected and found **saleable** restocks automatically:
the returned quantity is written back into stock — into the original batch
where that's unambiguous, or a return-specific batch otherwise. A return
found **damaged** or **non-saleable** does **not** restock — nothing
questionable goes back on the shelf for resale. Either way, the inspection
outcome and restock decision are visible on the Returns & Restock report.

Restocking is a consequence of the inspection outcome; it is never a
condition of the refund itself. See [Payments & Refunds](payments) for how
the refund and the return-receipt gate interact, and for what happens if the
returned goods never come back.

## Where to look when something looks wrong

- *Packing failed with "insufficient stock" even though the report shows
  units on hand.* Every unexpired batch may already be allocated elsewhere,
  or the only stock left is expired — packing will not touch it.
- *An order is stuck at paid, not packed.* Nobody has packed it yet — this
  shows up on the Action Center's Orders group (`orders.paid_not_packed`)
  and on the Order Fulfilment report.
- *Stock still shows a cancelled order's units as gone.* Check the Stock
  Reports "stock in/out" reconciliation and confirm the cancellation reversal
  was written — see the Action Center's `orders.restock_not_reconciled`
  alert.
