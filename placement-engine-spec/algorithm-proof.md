# Placement Algorithm — formal statement and invariants

## Inputs

- `sponsor_id` — non-null, references an existing distributor.
- `placement_id_opt` — nullable, references an existing distributor.
- `side_opt` — nullable, ∈ {`L`, `R`}.

## Settings (read once at the start of the registration session and frozen)

- `strategy ∈ {default_left, default_right, custom}`
- `allow_sponsor_override ∈ {true, false}` (only meaningful when strategy ∈ {default_left, default_right})

## Step 1 — Resolve placement_id

```
placement_id = placement_id_opt ?? sponsor_id
```

## Step 2 — Descendant validation

```
assert (placement_id == sponsor_id) OR is_descendant(sponsor_id, placement_id)
```

`is_descendant(a, d)` returns true iff there exists a row
`(ancestor_id=a, descendant_id=d)` in `genealogy_closure` with `depth ≥ 1`.

If the assertion fails, raise `CrossLinePlacementError`. The attempt is
audit-logged.

## Step 3 — Resolve side

```
function resolve_side(strategy, side_opt, allow_sponsor_override):
    if strategy == 'custom':
        if side_opt is null: raise SideRequiredError
        return ('prospect_custom', side_opt)
    if strategy == 'default_left':
        default = 'L'
    if strategy == 'default_right':
        default = 'R'
    if side_opt is not null and side_opt != default:
        if not allow_sponsor_override: raise SideOverrideForbiddenError
        return ('sponsor_override', side_opt)
    return ('admin_default', default)
```

Returns a tuple `(chosen_by, side)`.

## Step 4 — Find first empty slot

A *deterministic* depth-first walk from `placement_id` using the chosen
side as the preferred direction. At each node:

```
if child(side) is empty:
    return (current_node, side)
else:
    descend into child(side); use the SAME side again
```

Termination: the algorithm always terminates because the tree is finite
at any point in time. The first descendant that has an empty `side`
slot is chosen. Worst case is O(depth-of-tree-on-that-side).

This is deliberately the "down the spine" walk — same side all the way
down. Other walks (BFS, opposite-leg fallback) are explicitly NOT used.

## Step 5 — Persist

Inside one SERIALIZABLE DB transaction, with an advisory lock on
`placement:<placement_id>`:

```
INSERT distributors (
    user_id, adn,
    sponsor_id,
    placement_id_at_registration = placement_id_opt,        -- NULL means defaulted
    placement_parent_id = parent_returned_in_step_4,
    placement_side = side_returned_in_step_4,
    placement_strategy_snapshot = strategy,
    side_chosen_by = chosen_by,
    depth = parent.depth + 1,
    ...
)

INSERT genealogy_closure (ancestor_id=self, descendant_id=self, depth=0)
INSERT genealogy_closure (ancestor_id=A, descendant_id=self, depth=A_depth+1)
    for each A in ancestors_of(parent)

INSERT sponsorship (sponsor_id, distributor_id=self)
```

Then emit:

```
event 'genealogy.placement.created' {
    distributor_id, sponsor_id, placement_id, parent_id, side, depth,
    strategy_snapshot, side_chosen_by
}

event 'genealogy.distributor.registered' { ... }
```

## Errors thrown

| Error | When |
|---|---|
| `CrossLinePlacementError` | Step 2 assertion failed |
| `SideRequiredError` | strategy = `custom` and `side_opt` is null |
| `SideOverrideForbiddenError` | strategy = `default_left/right`, override disallowed, side_opt != default |
| `SlotRaceError` | unique-index violation despite the lock — the upper layer should retry once |

## Invariants (property tests must verify)

I-1. **Slot uniqueness.** `(placement_parent_id, placement_side)` is unique across `distributors`.

I-2. **Sponsor in lineage.** For every distributor D with `placement_id_at_registration = P`, `sponsor_id(D) ∈ ancestors(P) ∪ {P}`. (Always true because Step 2 enforces it.)

I-3. **Closure-table integrity.** For every distributor D, `count(rows with descendant_id=D) = depth(D) + 1`.

I-4. **Strategy snapshot is non-null.** `placement_strategy_snapshot IS NOT NULL` for every row.

I-5. **side_chosen_by consistency.** `side_chosen_by = 'admin_default'` ⇒ `placement_side` matches the strategy default. `side_chosen_by = 'sponsor_override'` ⇒ strategy was `default_left|default_right` AND `placement_side` is the opposite. `side_chosen_by = 'prospect_custom'` ⇒ strategy was `custom`.

I-6. **Determinism.** Replaying the same input sequence on a fresh DB yields the same final tree (modulo auto-increment IDs which can be mapped 1:1).

## Concurrency contract

Two concurrent placements under the same `placement_id`:

- Both acquire the advisory lock; one waits; one proceeds.
- The first to commit fills the chosen slot.
- The second resumes, re-runs Step 4, and lands at the next empty slot
  along the same spine.

Two concurrent placements under different `placement_id`:

- They proceed in parallel without contention.
- Both succeed.

If for any reason the lock is missed (lock timeout, split brain), the
unique index `uniq_distributors_slot` raises a duplicate-key error and
the transaction rolls back. The upper layer retries the placement (max 3
retries, exponential back-off).

## Latency budget

- p50 ≤ 50 ms on a 100k tree.
- p95 ≤ 250 ms on a 1M tree.
- No request to a KYC gateway happens inside the placement transaction.
