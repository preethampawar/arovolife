# Module: Genealogy

## Scope

Binary placement tree + sponsorship horizontal tree. Placement engine, closure-table maintenance, line-change windowing, team stats aggregation.

## Authoritative architecture decisions

- **ADR-0001 — Closure table**. We do NOT use nested-set or recursive CTEs. The `genealogy_closure` table holds an `(ancestor_id, descendant_id, depth)` row for every pair (including self at depth 0). All "who is under X" / "what is X's path" queries go through this table. Adding a new traversal algorithm without an ADR update is a regression.
- **ADR-0002 — Placement Strategy setting**. Company-wide admin setting decides starting leg for `default_left`, `default_right`, or `custom` (where the sponsor picks). The PlacementEngine reads from `settings.placement_strategy`.

## Hot files

- `app/Modules/Genealogy/Services/PlacementEngine.php` — placement decisions, slot validation, race-safe insert via `lockForUpdate`.
- `app/Modules/Identity/Services/TeamStatsService.php` — **THE single source of truth** for every downline count or list. See [`team-stats-single-source` memory](../../../memory/team_stats_single_source.md).
- `app/Modules/Genealogy/Listeners/SendPlacementCreatedMails.php` — `placement.created` event → sponsor + parent notifications.

## Team-stat scopes (TeamStatsService)

Every "how many people in X's downline grouped by Y" question routes through `scopedQuery(Distributor, string $scope)`. Scopes:

- `total`  — `genealogy_closure WHERE ancestor_id = me AND depth > 0` (excludes self)
- `direct` — `sponsorship WHERE sponsor_id = me`
- `left`  — `genealogy_closure WHERE ancestor_id = leftChildId`  (includes the child via depth=0)
- `right` — `genealogy_closure WHERE ancestor_id = rightChildId` (same)

If counting semantics change (exclude terminated, exclude pending KYC, count spouse rows differently), edit `scopedQuery()` once — every consumer (`counts()`, `full()`, `roster()`, `scopedCount()`, the dashboard CSV download) follows automatically.

## Sponsorship table

- Columns: `id`, `sponsor_id`, `distributor_id`, `created_at`. (Note: NOT `descendant_id` — historical naming gotcha. Direct-referral joins use `distributor_id`.)

## Line-change

- Window: 5 **business days** from `effective_date` (uses `diffInWeekdays`, not calendar days).
- One-shot per distributor per registration. Subsequent requests are rejected by `RequestLineChange` service-side guard.
- Phase 2 will additionally reject line-change for distributors with any commerce activity — see [phase_2_backlog](../../../../.claude/projects/-Users-preetham-Documents-arovolife-arovolife-arovolife-code/memory/phase_2_backlog.md). Don't ship that without the parked memory.

## Tree views

- Two distinct trees, two distinct controllers, two distinct blade partials. Don't conflate them:
  - **Binary** = placement tree. `tree.binary` route, `_binary-node.blade.php`. Has L/R legs.
  - **Sponsorship** = horizontal tree. `tree.sponsorship` route, `_sponsorship-node.blade.php`. Tracks who-recruited-whom.
- The node-card kebab menu order is the SAME for admin + distributor (Show only / Send Message / Details / View profile (admin) / Impersonate (admin)). Don't reorder one without the other.

## Couple registration (hard rule #6)

- One PAN = one ADN; couple = two distributor rows sharing one ADN, distinguished by `is_primary_couple`.
- Login flow surfaces a "Primary account holder" checkbox when an ADN resolves to multiple rows; the controller picks the matching primary, defaulting to the primary holder.

## Tests

- Property-based placement tests at `tests/Modules/Genealogy/`. Use `php artisan test --compact tests/Modules/Genealogy/`.
- Use `/placement-test` slash command for the full regression sweep.
