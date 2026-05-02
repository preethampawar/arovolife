# ADR-0002 — Company-wide Placement Strategy admin setting

- **Status:** Superseded by [ADR-0003](adr-0003-referral-link-placement.md)
  on 2026-05-01.
- **Date:** 2026-04-19
- **Deciders:** Product Owner, Compliance Officer, Laravel Architect
- **Supersedes:** —

> **Superseded.** This ADR introduced a `placement.default_side` enum +
> spine-walk algorithm. ADR-0003 reverses both decisions in favour of a
> referral-link entry point and single-level placement (no auto-walk).
> Read this document for historical context only.

## Context

The original plan document specified an alternating-leg rule
(1st right, 2nd left, 3rd right, 4th left…) per sponsor as the default
placement behaviour. During PRD review, the Product Owner requested an
admin-controlled global setting instead, with three modes:

1. **Default Left** — every new placement defaults to the left leg of
   `placement_id`.
2. **Default Right** — every new placement defaults to the right leg of
   `placement_id`.
3. **Custom** — no default; the sponsor (or prospect) must pick.

Reasons given:

- Centralised control without code releases.
- Auditable change history.
- Flexibility to tune the default during ramp-up phases without
  per-sponsor counter management.

The change replaces the per-sponsor `next_side` counter with a single
company-wide setting and a per-placement snapshot.

## Options considered

### A. Keep the alternating per-sponsor rule

Reject. Inconsistent with PO direction; less auditable; inconsistent
across sponsors; harder to reason about under concurrency.

### B. Per-sponsor configurable default

Reject. Would let sponsors influence company-wide tree shape; harder to
audit; complicates support; PO did not ask for it.

### C. Single company-wide setting with three values, snapshot per placement (chosen)

Accept. Matches PO direction. Single source of truth. Audit log on every
change. Snapshot makes historical placements interpretable regardless of
later flips.

## Decision

We introduce a `settings` table with:

- `key` (unique) — e.g., `placement.default_side`.
- `value` — one of `default_left`, `default_right`, `custom`.
- `version` — incremented on every change.
- `updated_by`, `updated_at` — audit metadata.

Every change writes to `audit_log` with `before/after/reason/ip`.

Each `distributors` row carries:

- `placement_strategy_snapshot` (enum, NOT NULL) — value in effect at
  insert time.
- `side_chosen_by` (enum) — `admin_default`, `sponsor_override`,
  `prospect_custom`.
- `placement_id_at_registration` (nullable) — what the sponsor asked
  for; NULL means "defaulted to sponsor_id".

A sub-toggle `placement.allow_sponsor_override` (bool, default true)
controls whether a sponsor may explicitly choose the opposite side when
strategy is `default_left` or `default_right`.

## Consequences

- The Placement Strategy can be changed at any time by an
  `admin-compliance` or `super-admin` role from the admin console.
- In-flight registrations freeze the strategy at session start so a flip
  mid-flow does not surprise the prospect.
- Every placement is forensically traceable: which strategy, which leg,
  who chose it.
- Engineering must remove any vestigial alternating-counter logic in
  scaffolding examples.
- The `compliance-officer` subagent treats Placement Strategy changes as
  always requiring a Compliance-Review trailer.

## Forward compatibility

If a future requirement adds richer strategies (e.g., "round-robin per
day", "fill weakest leg"), they extend the enum and add a new ADR.
Strategy snapshots ensure historical correctness even across such
extensions.
