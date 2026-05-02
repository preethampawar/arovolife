# ADR-0003 — Referral-link entry + single-level binary placement

- **Status:** Proposed (PO + Compliance sign-off pending)
- **Date:** 2026-05-01
- **Deciders:** Product Owner, Compliance Officer, Laravel Architect
- **Supersedes:** ADR-0002

## Context

Two prior placement decisions are being reverted in a single PRD-review pass:

1. ADR-0002 introduced a company-wide `placement.default_side` enum
   (`default_left | default_right | custom`) and a `PlacementStrategyResolver`
   that walks down a single chosen leg until an empty slot is found.
2. The original brand document (image shared by the user on 2026-05-01,
   *"Registration Method"*) actually specified an alternating R/L rule per
   sponsor. Implementation skipped this in favour of (1).

After internal-team review the user reset the model entirely:

- Walk-up registrations no longer exist. Joining requires a **referral link**
  shared by the sponsor (admin or distributor) that carries `sponsor_id` +
  `placement_id` + an optional `side` (`L`/`R`) in the URL.
- The placement engine no longer descends. The new joiner is placed
  *exactly* at `placement_id.<side>`. If the targeted slot is full, the
  registration is rejected; the sponsor must pick a different placement
  target.
- The `placement.default_side` setting and the strategy snapshot column are
  deleted. Placement is now an invariant deterministic rule.

Direct visits to `/register` redirect to `/contact-us` with a contextual
banner inviting the visitor to leave their details so the support team can
help them obtain a referral link.

## Decision

### Algorithm

```
place(sponsor_id, placement_id, side_opt):
    if placement_id != sponsor_id and not is_descendant(sponsor_id, placement_id):
        raise CrossLinePlacementError

    L_taken = exists(distributors where parent=placement_id and side='L')
    R_taken = exists(distributors where parent=placement_id and side='R')

    if side_opt is not null:
        slot_taken = (side_opt == 'L') ? L_taken : R_taken
        if slot_taken: raise PlacementSlotFullError(side_opt)
        side = side_opt
        chosen_by = 'referral_explicit'
    else:
        if not L_taken:
            side       = 'L'
            chosen_by  = 'referral_default'
        elif not R_taken:
            side       = 'R'
            chosen_by  = 'referral_fallback_right'
        else:
            raise PlacementSlotsExhaustedError(placement_id)

    # advisory lock + closure rows + sponsorship + audit log + events: unchanged
```

The `placement_strategy_snapshot` column on `distributors` is dropped (no
historical reinterpretation needed — the rule is now invariant). The
`side_chosen_by` enum is replaced with three new values:

| Value                       | When                                                              |
|-----------------------------|-------------------------------------------------------------------|
| `referral_explicit`         | Referral link carried `side=L` or `side=R` and the slot was open. |
| `referral_default`          | No `side` in link; `placement_id.L` was open.                     |
| `referral_fallback_right`   | No `side` in link; `placement_id.L` taken, `placement_id.R` open. |

### Cross-line guard (re-affirmed)

`PlacementEngine::isSelfOrDescendant()` continues to enforce that a sponsor
may place under themselves or anywhere in their own downline, never under a
sibling sponsor or upline. Cross-line attempts are surfaced to the user as a
generic "invalid referral link" Contact Us redirect (avoids stack-trace
exposure).

### Race-safety

Unchanged. Each `place()` call:

- Acquires `GET_LOCK('placement:'||placement_id, 5)` before any read.
- Inserts inside a SERIALIZABLE transaction.
- Falls back on the `(placement_parent_id, placement_side)` unique index as
  the last-line defence.

The previous walk-down loop is removed, so the lock window is shorter — a
strict improvement under concurrency.

### Settings

Both `placement.default_side` and `placement.allow_sponsor_override` rows
are deleted from `settings`. Admin UI loses the Placement Strategy panel.
Admin keeps the State age-rules panel.

## Consequences

- **Sponsor-controlled growth.** Tree shape is now driven by the sponsor's
  conscious choice of `placement_id` for each invite link. There is no
  auto-spreading.
- **Hard error surface.** A sponsor who tries to invite someone under a
  full node gets an error at link generation / use time, not silently
  redirected. UX must guide sponsors to pick a node with open slots.
- **Simpler audit story.** Every placement records an explicit
  `side_chosen_by` value tied to the link's input.
- **Demo seed must vary `placement_id`.** Sequential registrations under
  one root can fit at most 2 distributors. Demo data has to walk the
  placement target down the tree manually.
- **No production migration risk.** Phase 1 has no production users; the
  enum + column changes are internal only.

## Forward compatibility

If a future requirement reintroduces auto-walking placement (e.g. "fill the
weakest leg automatically"), it must:

1. Supersede this ADR.
2. Reintroduce `side_chosen_by` enum values for the new rule.
3. Reintroduce a strategy snapshot column to keep historical placements
   interpretable.

The closure-table data model (ADR-0001) is unaffected and remains the
source of truth for cross-line validation.

## Related changes

- `app/Modules/Genealogy/Services/PlacementEngine.php` — rewrite
- `app/Modules/Genealogy/Services/PlacementStrategyResolver.php` — delete
- `app/Modules/Genealogy/Services/DTOs/StrategySnapshot.php` — delete
- `app/Modules/Genealogy/Services/Exceptions/{SideRequired,SideOverrideForbidden}Error.php` — delete
- `app/Modules/Genealogy/Services/Exceptions/{PlacementSlotFull,PlacementSlotsExhausted}Error.php` — new
- `app/Modules/Identity/Database/Migrations/2026_05_01_000001_replace_placement_strategy_columns.php` — new
- `app/Modules/Identity/Http/Controllers/Registration/RegistrationWizardController.php` — substantial
- `app/Modules/Public/*` — new `ContactController`, model, migration, notification, view
- `database/seeders/SettingsSeeder.php` — delete placement rows
- `database/seeders/DemoDownlineSeeder.php` — full rewrite
- `routes/web.php` — `/register` requires query params; new `/contact-us`
- `.claude/skills/arovolife-placement-engine/SKILL.md` — full rewrite of
  algorithm + settings sections
