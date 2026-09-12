# Inventory Management

**Admin → Inventory → Stock.** How stock is recorded, why the on-hand number
on screen is never typed in by hand, and what to do when it looks wrong.

> **Inventory enforcement is a feature flag.** Everything in this page is
> recorded regardless, but checkout only *blocks* an oversell while
> `InventoryFeature` is ON. See [Order Management](order-management) for what
> that means for orders.

---

## The stock ledger

Every unit that moves — a supplier delivery, a sale, a return, a transfer, a
correction — is written as one row in an append-only movement ledger. Nothing
is ever edited or deleted there; a mistake is corrected by writing an
opposite movement, never by changing the original row.

The **on-hand** figure you see on the Stock screen, and the on-hand shown per
batch, are not stored numbers you can overwrite — they are a running total of
that ledger. This is deliberate: if on-hand were a plain column, a single
wrong edit would silently disagree with everything else and nobody would
know until a physical count found the gap. As a projection, on-hand can only
be wrong if a movement is missing or wrong, and that is exactly what
`inventory:verify` checks (below).

This is the same principle the wallet uses for commission money — see
[Compensation Engines](compensation) — applied to stock instead of rupees.

**What you will never find:** a text box on the product form that lets you
type a new on-hand quantity. Stock only changes through a goods receipt, a
transfer, an order being packed or cancelled, a return being restocked, or a
recorded adjustment (below). Every one of those leaves a movement.

---

## Batches

Stock is tracked in batches — a batch number, an optional manufacture date,
and an optional expiry date, held per warehouse. A batch is created the
moment stock enters it (a goods receipt, an opening balance, or a transfer
receipt) and its own on-hand is a projection of the movements against it, the
same way the warehouse total is.

Batches matter for two reasons:

- **Expiry.** A batch past its expiry date is never picked for an order, even
  if it still shows units on hand. See [Order Management](order-management)
  for how picking chooses between batches.
- **Traceability.** Every unit sold, returned or written off can be traced
  back to the batch — and from there to the goods receipt or transfer that
  brought it in.

## Opening stock

A warehouse's very first stock — set on the product form or by a seeder
before the ledger existed — has no movement behind it until the one-time
`inventory:backfill-opening` command runs. It creates an `OPENING` batch (no
expiry) and an opening movement for whatever on-hand was already recorded, so
every level starts with a real ledger entry instead of a number with no
history. This only needs to run once, immediately after the inventory module
is deployed, and it is safe to re-run — it skips anything that already has
movements.

## Adjustments and write-offs

Use **Admin → Inventory → Adjustments** for anything a goods receipt,
transfer or order does not already explain: a physical count that disagrees
with the screen, damage, theft, a sample taken out, or an expired batch being
cleared off the shelf.

Every adjustment needs:

- the warehouse, variant and (usually) the specific batch,
- a signed quantity — positive to add, negative to remove,
- a reason — `count_correction`, `damaged`, `expired`, `theft_loss`, `sample`
  or `other`,
- a non-empty note explaining what happened.

`damaged`, `expired` and `theft_loss` write a **write-off** movement; the
others write a plain adjustment. Both leave the same kind of audited trail as
every other stock movement — there is no quiet way to make a number
disappear.

## `inventory:verify` and what drift means

`inventory:verify` recomputes on-hand for every variant/warehouse pair and
every batch straight from the movement ledger and compares the result to
what the Stock screen shows. It runs automatically every **Monday at
03:00 IST**, and can be run manually any time — after a bulk import, after an
adjustment you're unsure about, or if a number on screen looks wrong.

- **Exit code 0** — everything matches. Nothing to do.
- **Exit code 1** — the command lists exactly which (variant, warehouse,
  batch) keys disagree, and by how much.

> **Drift means a movement was written outside the ledger, or a projected
> number was edited directly in the database.** Both should be impossible in
> normal use — every screen and every command in this platform writes
> through the ledger. If `inventory:verify` reports drift, do not "fix" the
> on-hand number by hand: check `audit_log` for the affected batch or level
> first, and only use a small, reasoned adjustment (≤ 5 units, with a note
> referencing the audit entry) to correct it. Large or unexplained drift
> should be investigated, not silently adjusted away.

## Reorder level

Each product's stock level carries a **reorder level** — set on the product
form, per default warehouse — and it is the only stock figure that screen
still lets you type in directly, because it is a threshold you choose, not a
count of anything. When available stock (on-hand minus reserved) falls to or
below the reorder level, the variant shows as **low stock** on the Stock
screen and in the daily inventory alert email (`inventory:alerts`, sent at
08:30 IST when stock is low or a batch is expiring or expired). A reorder
level of `0` means the variant is never flagged for low stock.

## If something looks wrong

| Symptom | What it usually means |
|---|---|
| Checkout let an order through with more units than are available | `InventoryFeature` is OFF, so availability is not enforced yet. |
| On-hand doesn't match a physical count | Run `inventory:verify` first — if it reports drift, follow the recovery steps above rather than editing anything by hand. |
| A batch shows a past expiry date but still has stock | Expected until someone writes it off — see **Adjustments** above. |
| The product form has no on-hand field to change | Correct — on-hand is a projection now. Use Adjustments, or check the level on the Stock screen. |
