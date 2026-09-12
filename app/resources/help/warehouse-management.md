# Warehouse Management

**Admin → Inventory → Warehouses / Suppliers / Purchase Orders / Goods
Receipts (GRN) / Transfers.** Where stock physically lives, how it gets in,
and how it moves between locations.

---

## Warehouses

A warehouse is a location that can hold stock: `DEFAULT` (seeded as the
Central Hub) plus whatever you add — a second hub, a regional warehouse, or a
franchise handover point. Each has a **code** (e.g. `HYD-01`), a name, a
type, an address, and a **"fulfils orders"** flag.

**Only warehouses with "fulfils orders" ON are ever picked when an order is
packed.** Turn it off for a warehouse that holds stock but should never be
used to ship a customer order — a franchise location that only receives
transfers, for instance.

The **default warehouse** (`inventory.default_warehouse_code`, an
admin-owned setting) is where a goods receipt or a pack action lands when no
warehouse is chosen explicitly.

> **A warehouse's code cannot be renamed once created.** Every stock movement,
> batch and level is keyed on the code string, not a numeric ID — that's what
> lets a report say `HYD-01` instead of `warehouse #17`. Renaming the code
> after stock exists against it would silently orphan that history from the
> warehouse record. If a warehouse needs a new name, change the display
> **name**; the code stays fixed for its lifetime. A warehouse that is no
> longer used is archived, not deleted or renamed.

## Suppliers

**Admin → Inventory → Suppliers.** The supplier master — name, GSTIN,
contact and address — used on purchase orders and goods receipts. Archive a
supplier you no longer buy from rather than deleting it; existing POs and
GRNs keep their reference.

## Purchase orders

**Admin → Inventory → Purchase Orders.** A purchase order records what you
asked a supplier for. It moves through:

| Status | Meaning |
|---|---|
| `draft` | Being built. Lines can still be edited. |
| `sent` | Sent to the supplier. Lines are fixed. |
| `partially_received` | At least one line has been received in full or in part, via a GRN, but not everything ordered has arrived. |
| `received` | Every line's ordered quantity has arrived. |
| `cancelled` | Cancelled before completion. |

A purchase order **does not move stock by itself** — it is a record of
intent. Stock only enters the ledger when a goods receipt against it is
posted (below); the PO's status then rolls forward automatically based on
what has actually been received.

## Goods receipts (GRN)

**Admin → Inventory → Goods Receipts (GRN).** A GRN is what actually brings
stock in — either against a purchase order (its lines can prefill from the
PO) or as a standalone entry when goods arrive without one. Each line records
the variant, batch number, manufacture and expiry dates, quantity and unit
cost.

A GRN starts as a **draft** you can still edit. **Posting** it is the point
that matters:

- A stock batch is created (or matched, if the same batch number/expiry/cost
  already exists at that warehouse) for every line.
- A **purchase-in** movement is written for each line's quantity, raising
  on-hand for that batch and warehouse.
- If the GRN is against a purchase order, that order's received quantities
  and status are updated.

> **A posted GRN cannot be corrected by editing it.** Posting locks the
> document — the only way to undo it is to cancel it, which writes reversing
> movements rather than silently changing history. If the quantities or cost
> on a posted GRN were wrong, cancel it and enter a fresh one.

### Cancelling a posted GRN

Cancellation is only allowed while **every unit that GRN brought in is still
on hand** — nothing from it can have been sold, transferred out, or written
off. If any of it has moved, cancellation is refused and names the order that
consumed it; you must reverse that order (cancel it, or process a return)
before the GRN can be cancelled. Once cancellation succeeds, a reversing
movement is written for each line and the batch and warehouse totals drop
back down — the original receipt stays visible in the ledger; it is not
erased.

## Stock transfers

**Admin → Inventory → Transfers.** Moving stock between two of your own
warehouses.

| Status | Meaning |
|---|---|
| `draft` | Being built — source, destination and lines (specific batches, with quantities) can still change. |
| `dispatched` | Stock has left the source warehouse. |
| `received` | Stock has arrived at the destination. |
| `cancelled` | A draft that was abandoned before dispatch. |

There is deliberately **no in-transit warehouse**: dispatching a transfer
writes a movement that takes the stock out of the source, and it does not
appear anywhere — including the destination — until someone records receipt.
The Transfer Register report shows dispatched-minus-received as the
in-transit quantity, so a transfer sitting there for several days is visible
without a phantom location to reconcile.

**If the quantity received is less than what was dispatched**, the shortfall
is written as a **write-off** at the destination (reason: transit shortage)
rather than silently vanishing — it shows up in the movement ledger and in
reports the same way any other write-off does. A transfer already dispatched
cannot simply be deleted; if it needs to be undone, receive it and send stock
back the other way so the ledger stays honest about what actually happened.
