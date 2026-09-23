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
| `placed` | Buyer has checked out. Payment not yet confirmed. Stock is reserved (a counter), not yet deducted. For an **offline** order this means finance has not yet confirmed the money — see [Offline orders](#offline-orders). |
| `paid` | Razorpay has confirmed the payment — see [Payments & Refunds](payments). GST invoice issued. |
| `ready to ship` (shown as **Packed**) | The order has been packed: stock has actually left the warehouse for these items. |
| `shipped` | Carrier and tracking number recorded. |
| `delivered` | Delivery confirmed. This is what opens the buyer's 30-day cooling-off window — see [Cooling-off & Cancellation](cooling-off). |
| `confirmed` | The buyer (or the system) has confirmed the order after delivery. |
| `cancelled` | Cancelled before or after packing — see below for what happens to stock. |
| `refund_requested` / `refund_inspection` / `refund_approved` / `refunded` | The return and refund path — see [Payments & Refunds](payments). |

## The orders list

The list opens on **today's orders** — the date range is pre-set to today, and
a line above the status chips says so. To look further back, either widen the
**Placed from / Placed to** dates and press **Filter**, or use **Show all
dates** to drop the window entirely.

Above the table sits a row of figures for the set you are currently looking
at, not for the page of 25 and not for all time:

| Tile | What it counts |
|---|---|
| Orders | How many orders match the window, the status chip and the search. |
| Order value | The sum of the Total column — money payable, *after* any repurchase-wallet credit. |
| GST | The tax already included in the value beside it. |
| Repurchase wallet | The part of those orders settled with wallet credit rather than money. |
| BV | Business Volume carried by those orders. |

Every filter moves the figures with the rows: pick the **Cancelled** chip and
the tiles show cancelled orders only. The counts in brackets on the status
chips are likewise counted inside the date window, so they change when you
change the dates.

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

## Offline orders

**Admin → Commerce → Orders → New offline order.** For a distributor who paid
outside the website — cash at the reception counter, a bank deposit, UPI,
NEFT/IMPS/RTGS, a cheque, or another channel. Only shown while the *Offline
orders* feature flag is on. A purchase is never required to join or to stay a
distributor; never suggest one.

| Step | Who | What happens |
|---|---|---|
| Create | Operations (`commerce.order.manage`) or an admin | Enter the ADN, the quantities and the delivery, press **Calculate total**, then record the payment: channel, amount, date received, reference number (UTR, UPI transaction ID, cheque or receipt no. — required for every channel except cash), optional payer name, notes and a proof file (JPG/PNG/PDF, up to 5 MB). The order is created in **Placed** with an **Offline** label. Stock is reserved. Nothing is counted: no BV, no invoice, nothing in the books. |
| Confirm | Finance (`finance.record`) or an admin | On the order page, the Offline payment card → tick "I have checked this money has been received" → **Confirm payment**. The order becomes **Paid** through exactly the same path as a paid shop order: BV is recorded for the buyer, Genos BV goes up the upline, the compensation engines see the sale, the GST invoice is issued and the buyer is emailed. From here it is an ordinary order — pack, ship, deliver, cooling-off, cancel and refund all work as usual. |
| Reject | Finance or an admin | Only when **no money was received** (the deposit never arrived, or the entry was a mistake). Tick the "No money was received" box and give a reason. The payment is marked Rejected and the order is cancelled; the stock is released. Nothing was counted, so nothing is reversed. |

Rules the platform enforces:

- **The amount must match the order total exactly.** Use **Calculate total**; the amount field is pre-filled with it.
- **Cash under ₹2 lakh per person per day** (Income Tax Act s.269ST — the penalty is the whole amount). Take a bank transfer or UPI instead.
- **One reference, one order.** A reference number already recorded on a live offline payment is refused.
- **Not for your own account.** Staff cannot create or confirm an offline order for their own distributor account.
- **Blocked, terminated and rejected accounts** cannot be ordered for.
- **No coupons, redeem points or repurchase-wallet credit** on an offline order — the deposit covers the full order.
- **Payments older than 90 days** cannot be recorded here.

**Money received but the order must be undone?** Do not reject it. Confirm the
payment first, then cancel the order: the refund is then owed on the books and
appears in Payments → Refunds for a manual settlement. A pending offline order
cannot be cancelled from the order page, and the buyer cannot cancel it
themselves — both are sent to finance.

**Where finance finds them:** Payments shows a banner with the number of offline
payments awaiting confirmation; the orders list has a **Payment → Offline**
filter.

**Proof files** are stored encrypted, open only for finance and operations, and
every view is logged. They are deleted automatically — eight years after
confirmation, or 90 days after a rejection or cancellation (Settings →
Commerce). One person recording and confirming the same order is allowed but
flagged in the audit log for the monthly compliance review (R-107).

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

---

## Orders collected from an Arete Development Centre

A buyer can choose, at checkout, to collect their order from an Arete
Development Centre instead of having it delivered. These orders look different
in three ways, and each difference matters.

**They have no delivery address.** Not a blank one — none. The buyer never gave
one, so `ship_line1` and the rest are null and the order page shows the centre
under **Collection** instead. If you are looking for an address to give a
courier, it is the centre's, and it is on the order page. Do not type the
buyer's address in from somewhere else; the parcel is not going to them.

**They are charged a collection fee, not a delivery fee.** It is ₹0 unless
someone has changed *Settings → Commerce → Collection fee*. The free-shipping
threshold does not apply to it: that threshold exists to waive a delivery cost,
and there is no delivery here.

**They have one extra step.** The order goes:

| Step | What it means | What you do |
|---|---|---|
| `shipped` | The parcel has been consigned **to the centre**. | Dispatch as normal; the consignee is the centre. |
| `awaiting_collection` | The centre has it and has acknowledged holding it. | Press **Arrived at centre**. The screen shows the buyer's collection code **once** — pass it to them. It cannot be shown again. |
| `delivered` | The buyer has collected it. | Press **Record collection** and enter the code the buyer presents. |

### The collection code

Six digits, issued to the **buyer**, and the centre cannot produce it. That is
deliberate: the centre earns a commission on parcels it hands over, so the
authentication for a handover must not come from the party being paid for it.

Five wrong attempts locks the parcel and staff have to release it. The code is
stored only as a keyed hash — nobody, including us, can read it back out of the
database, so if the buyer loses it the parcel must be re-issued a new one rather
than looked up.

### How long a parcel may wait at a centre

*Settings → Fulfilment → Maximum days a parcel may wait at a centre* (default
15, matching the DSA §5.4 return window). The centre owner sees this number on
their own centre page, because their declaration binds them to "the period
arovolife publishes to me in writing" — so changing it changes what they have
undertaken. Nothing returns a parcel automatically yet; the obligation is on
the operator, and the number is what they are held to.

### Why the cooling-off clock starts late

The statutory 30-day window opens when the buyer **collects**, not when the
parcel reaches the centre. A parcel can sit at a centre for days; starting the
clock on arrival would burn the buyer's statutory window before they had the
goods.

### If the centre has been deleted

Dispatch will refuse, and it is right to. The order still records that the buyer
chose collection, so the system will not quietly turn it into a home delivery —
there is no address to send it to. Contact the buyer and agree either a
different centre or a delivery address.

### If dispatch says the centre has not accepted its declarations

A centre may not receive parcels until its owner has accepted the current centre
declarations. A centre created directly in the admin console has accepted
nothing, because no application was ever filled in for it. This is a compliance
gate, not a glitch: the declaration is the company's evidence that a centre is
not a retail outlet, and sending parcels to an operator who has undertaken not
to receive them is worse than having no declaration at all.

Route the order another way, then get the declarations accepted:

- **A centre assigned to a distributor** — only that distributor can accept.
  Ask them to open **My Arete Development Centre**, where the outstanding
  declarations appear at the top of the page with an Accept button. Staff
  cannot do this for them: the declaration is their signature, and one an
  admin could produce for them would not be evidence of anything.
- **A company-run centre** (no assigned distributor) — open the centre in
  **Admin → Arete Centres → Edit**. The declarations appear below the centre
  form and you accept them in your own name on arovolife's behalf. Needs the
  compliance-discipline permission, so admin-finance cannot do it.

The registry list shows a **Declarations pending** badge against any centre
that is currently blocked, so you do not have to attempt a dispatch to find
out. Note that a blocked centre is still selectable by a buyer at checkout —
the block is on consigning the parcel, not on choosing the centre.

The wording is versioned. When it changes, every centre owes a fresh
acceptance and is blocked until it gives one — that is intended, not a
regression.
