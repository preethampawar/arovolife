---
name: arovolife-placement-engine
description: Authoritative reference for the Arovolife binary-tree placement algorithm — referral-link entry, single-level placement (no spine walk), descendant validation, race-safety, and the closure-table data model. Use whenever writing, reviewing, testing or debugging anything that inserts into or reads from the binary genealogy tree.
---

# Arovolife Placement Engine — reference

Single source of truth for how a new distributor is inserted into the binary
tree. Any change requires a new ADR superseding ADR-0003.

## Inputs

The engine receives an already-resolved registration request:

- `sponsor_id` — MANDATORY. The distributor who introduced the prospect.
  Recorded in the horizontal `sponsorship` table for sponsor-tied earnings.
  The sponsor is NOT necessarily the placement parent.
- `placement_id` — MANDATORY. The exact node under which the new joiner
  goes. Must equal `sponsor_id` or be an existing descendant of
  `sponsor_id` (cross-line guard).
- `side_opt` — OPTIONAL. `'L'` or `'R'`. If supplied, the engine will
  *only* place at `placement_id.<side_opt>`; if that slot is full it
  raises `PlacementSlotFullError`. If absent, the engine prefers L and
  falls back to R.

These inputs come from the referral link query string (resolved by
`RegistrationWizardController::start()` before the wizard begins). Walk-up
registrations do not exist — direct visits to `/register` are redirected to
`/contact-us`.

## Algorithm

```
function place(sponsor_id, placement_id, side_opt):
    # 1. Cross-line guard
    if placement_id != sponsor_id and not is_descendant(sponsor_id, placement_id):
        raise CrossLinePlacementError

    # 2. Acquire placement lock + read both child slots
    GET_LOCK('placement:'||placement_id, 5)

    L_taken = exists(distributors where parent=placement_id and side='L')
    R_taken = exists(distributors where parent=placement_id and side='R')

    # 3. Resolve the slot — single level only, no descent
    if side_opt is not null:
        slot_taken = (side_opt == 'L') ? L_taken : R_taken
        if slot_taken: raise PlacementSlotFullError(side_opt)
        side       = side_opt
        chosen_by  = 'referral_explicit'
    else:
        if not L_taken:
            side       = 'L'
            chosen_by  = 'referral_default'
        elif not R_taken:
            side       = 'R'
            chosen_by  = 'referral_fallback_right'
        else:
            raise PlacementSlotsExhaustedError(placement_id)

    # 4. Insert
    parent = placement_id
    depth  = placement_id.depth + 1
    INSERT distributors (..., placement_parent_id=parent, placement_side=side,
                         side_chosen_by=chosen_by, depth)
    INSERT genealogy_closure (ancestor, descendant, depth) for every ancestor of parent + self
    INSERT sponsorship (sponsor_id, distributor_id)

    # 5. Audit + events
    AUDIT genealogy.placement.created with chosen_by, sponsor_id, placement_id
    EMIT  PlacementCreated, DistributorRegistered

    RELEASE_LOCK('placement:'||placement_id)
```

Note: there is no `findFirstEmptySlot()`. The placement target is *always*
the parent. If the targeted slot is full the registration is rejected
upward to the wizard, which redirects to `/contact-us` with reason
`invalid_referral_link`.

### `side_chosen_by` values

| Value                       | When                                                              |
|-----------------------------|-------------------------------------------------------------------|
| `referral_explicit`         | Referral link carried `side=L` or `side=R` and the slot was open. |
| `referral_default`          | No `side` in link; `placement_id.L` was open.                     |
| `referral_fallback_right`   | No `side` in link; `placement_id.L` taken, `placement_id.R` open. |

## Race-safety

Two simultaneous registrations targeting the same `(placement_id, side)`
must not both succeed. Defence in depth:

1. Each `place()` runs inside a SERIALIZABLE transaction.
2. Per-`placement_id` advisory lock (`GET_LOCK('placement:'||N, 5)`)
   acquired *before* the slot reads.
3. Unique index `(placement_parent_id, placement_side)` on `distributors`
   — last-line defence; the second insert hits a
   `UniqueConstraintViolationException`, the transaction rolls back
   cleanly, and the wizard redirects to Contact Us.

## Descendant validation

`is_descendant(ancestor_id, candidate_id)` is one closure-table lookup:

```sql
SELECT 1 FROM genealogy_closure
WHERE ancestor_id = :ancestor AND descendant_id = :candidate
LIMIT 1;
```

If the row exists with any depth ≥ 0, the candidate is a descendant (or
self). A sponsor may place under themselves; they may not place under a
sibling sponsor or upline.

## Invariants (property tests must verify)

- For every distributor D, `depth(D)` in the closure table equals the count
  of rows `(ancestor, D, *)` minus 1.
- Every placement increases `depth` by exactly 1 from the placement target.
- No two distributors share `(placement_parent_id, placement_side)`.
- For every D with `chosen_by != couple_secondary`, the
  `placement_id_at_registration` is in the sponsor's downline (or equals
  the sponsor).
- `side_chosen_by` is non-null for every placed distributor.

## Forbidden changes

- Do NOT reintroduce a spine-walk / multi-level descent — placement is
  single-level by spec.
- Do NOT reintroduce the `placement.default_side` enum — placement is
  invariant.
- Do NOT allow `placement_id` outside the sponsor's downline.
- Do NOT switch to nested-set or parent-only adjacency (ADR-0001).
- Do NOT mutate `side_chosen_by` on existing rows.

## Related files

```
app/app/Modules/Genealogy/Services/PlacementEngine.php
app/app/Modules/Genealogy/Services/Exceptions/CrossLinePlacementError.php
app/app/Modules/Genealogy/Services/Exceptions/PlacementSlotFullError.php
app/app/Modules/Genealogy/Services/Exceptions/PlacementSlotsExhaustedError.php
app/app/Modules/Genealogy/Models/Distributor.php
app/app/Modules/Genealogy/Models/GenealogyClosure.php
app/app/Modules/Identity/Http/Controllers/Registration/RegistrationWizardController.php  (start())
app/tests/Modules/Genealogy/PlacementEngineTest.php
app/tests/Modules/Genealogy/PlacementPropertyTest.php
```

## Related docs

- `docs/architecture/adr-0001-closure-table.md`
- `docs/architecture/adr-0003-referral-link-placement.md` (current)
- `docs/architecture/adr-0002-placement-strategy-setting.md` (superseded)
