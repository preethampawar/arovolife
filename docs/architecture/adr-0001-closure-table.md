# ADR-0001 — Use a closure table for the Genos (binary genealogy tree)

- **Status:** Accepted
- **Date:** 2026-04-19
- **Deciders:** Laravel Architect, Engineering Lead
- **Supersedes:** —
- **Superseded by:** —

## Context

Arovolife's Genos (binary placement tree) is the backbone of the platform. It has:

- Unlimited depth (millions of nodes projected).
- High write frequency during campaigns (concurrent placements).
- Very high read frequency (genealogy views, binary matching in Phase 4,
  rank qualification in Phase 5, pool matrix in Phase 6).
- A strict correctness requirement: no two nodes may share
  `(placement_parent_id, placement_side)`; descendant checks must be
  O(1); no silent corruption from partial writes.

Three storage models were evaluated.

## Options considered

### A. Nested-set tree

- **Pros:** O(1) read for "all descendants of X".
- **Cons:** Every insert mutates many rows (lft/rgt renumbering). Under
  concurrent registration, lock contention and row-churn become
  pathological. Unsuitable at the expected placement rate.

### B. Adjacency list (parent-only)

- **Pros:** Cheap writes. Simple mental model.
- **Cons:** Recursive reads. MySQL 8 CTEs help but p95 is poor for deep
  trees. Binary matching in Phase 4 would be a nightmare of recursive
  queries against a very hot table.

### C. Closure table (one row per (ancestor, descendant, depth))

- **Pros:** Insert cost is O(depth). Ancestor and descendant queries are
  O(1). No renumbering. Compatible with MySQL 8. Natural fit for
  closure-based aggregate queries (binary matching, rank qualification).
  Property tests can assert invariants directly on the closure rows.
- **Cons:** Storage overhead (closure-row count is proportional to tree
  depth). Insert logic must be transactional and idempotent, but this
  is a solved problem.

## Decision

**We adopt the closure table (Option C).**

Schema: `genealogy_closure(ancestor_id, descendant_id, depth)`, primary
key `(ancestor_id, descendant_id)`, secondary index on `descendant_id`
and on `(ancestor_id, depth)`.

Every placement writes `N + 1` rows: one for every ancestor of the
parent, plus one for self (`ancestor = descendant = self, depth = 0`).
All writes happen inside the transaction that inserts the distributor
row and records the placement event.

Adjacency is preserved by `distributors.placement_parent_id` +
`placement_side`. The closure table is the *index* over ancestry, not
the source of truth for the parent/child relationship.

## Consequences

- Placement latency p95 target of ≤ 250 ms on a 1M-node tree is
  comfortably achievable.
- Binary matching (Phase 4) can be implemented by left-leg and right-leg
  aggregate queries using `ancestor_id` predicates — no recursion.
- Storage grows with depth. At depth 50 with 1M nodes, ≈ 50M closure
  rows. MySQL 8 with proper indexing handles this.
- We must maintain a nightly integrity-check cron comparing
  `genealogy_closure` against `(placement_parent_id, placement_side)`
  adjacency and alerting on drift.
- Any future design that reintroduces recursive CTEs or nested-set
  renumbering must supersede this ADR in writing.

## References

- Joe Celko, *Trees and Hierarchies in SQL for Smarties* (closure-table chapter).
- Laravel 13 docs, "Database Transactions".
- MySQL 8 reference, "Generated Invisible Indexes".
