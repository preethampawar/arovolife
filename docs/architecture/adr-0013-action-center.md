# ADR-0013 — Action Center: read-only provider registry with snooze-only writes

- **Status:** Accepted
- **Date:** 2026-09-12
- **Deciders:** Product Manager, Operations, Platform Architect
- **Builds on:** ADR-0004 (double-entry ledger)
- **Supersedes:** —

## Context

Operations and admin teams manage dozens of concurrent tasks: orders awaiting fulfillment, stock expiring, refunds overdue, KYC pending review, grievances past SLA. These signals are scattered across separate worklists — a stock-movement ledger, a refund worklist, a KYC queue, a grievance ticket board. A manager must visit many screens to know "what needs a human right now".

The Action Center consolidates those signals into one screen, ranked by severity and age, so a manager can triage urgency in one place. The design principle is simple: **aggregate, never re-derive**. Every signal already exists as a row or a query result elsewhere; the Action Center is a registry of read-only providers that surfaces them with a single write — snooze — for deferral.

## Options considered

### A. Materialized alert table + sync job

Create an `action_center_items` table that the system populates and keeps in sync via a scheduled job (on every stock movement, refund state change, KYC update, etc.).

- **Pros:** Fast queries; no recalculation needed.
- **Cons:** Drift is inevitable (a missed trigger, a skipped job, an unhandled code path). A sync failure silently leaves items stale. Debugging requires checking both the source and the materialized copy, and they disagree. Nothing to clean up if the source is deleted — the action row lingers.

### B. Live queries via providers + 60-second cache

Each action type (e.g. orders not packed, stock expiring, refunds past due) is implemented as a provider class that runs the relevant query live. The summary counts are cached for 60 seconds per user; item lists are never cached (always current). Snooze is the only write — the Action Center owns no state besides that one row.

- **Pros:** No sync, no drift, no deleted orphans. Live queries are authoritative. Snooze is simple and isolated. Feature flagging per module means inventory being OFF hides its providers. Permission-scoped so finance never sees a queue they cannot clear.
- **Cons:** Higher query load on the Action Center screen. Mitigated by the 60-second summary cache, which covers the typical 1–5 minute span a manager browses before clicking into a specific action type.

## Decision

Adopt **Option B** — `ActionCenterRegistry` with **permission-scoped, feature-flagged providers**, **60-second summary cache per user**, and **snooze as the sole write**.

### Schema essentials

**One new table (snooze only):**

```sql
action_center_snoozes (
    id, action_key VARCHAR(64), subject_type VARCHAR(64), 
    subject_id BIGINT UNSIGNED, snoozed_until DATETIME(3),
    reason TEXT NOT NULL, actor_user_id FK nullable,
    created_at/updated_at DATETIME(3)
)

CREATE UNIQUE INDEX idx_ac_key_subject (action_key, subject_type, subject_id);
CREATE INDEX idx_ac_key_until (action_key, snoozed_until);
```

No item table, no sync job, nothing else.

### Contract and provider pattern

Each provider implements `ActionProvider`:

- `key()` — stable identifier (e.g. `orders.paid_not_packed`), used in routes and snooze rows.
- `group()` — enum: `ORDERS`, `STOCK`, `MONEY`, `PEOPLE`, `COMPLIANCE`, `PLATFORM`.
- `label()` / `description()` — user-facing strings for list and detail pages.
- `permission()` — the capability required to act on this item (e.g. `commerce.order.manage`).
- `severity()` — derived, based on SLA: the type's ceiling is raised to WARNING or CRITICAL if past due.
- `statutory()` — if true, snooze requests return 422; the item stays visible until resolved.
- `slaHours()` — optional; null means backlog (no clock).
- `count()` — one indexed query, no model hydration, no N+1.
- `items(limit)` — list of `ActionItem` DTOs, oldest first.
- `targetRoute()` — deep link to the fixing screen.

Every provider:

1. Runs a single indexed query with no eager loads.
2. Excludes snoozed subjects via a `whereNotExists` on the snooze table (using the composite index).
3. Is pure read; never writes.
4. Degrades quietly if its feature flag is OFF (returns 0, is hidden).

### Severity derivation

Severity is not stored; it is computed:

- A type has a ceiling (INFO, WARNING, or CRITICAL).
- An item past its SLA is promoted one level up (INFO → WARNING, WARNING → CRITICAL).
- Statutory items that are CRITICAL stay CRITICAL (they do not "escalate").

### Caching strategy

- **Summary** — `action_center.summary.{user_id}` cached 60 seconds. Includes counts and the worst (oldest/soonest-due) item's severity and age per group, so the manager knows which group to check first.
- **Items** — never cached. Always fresh.
- **Snooze side-effect** — invalidates the user's summary cache; the list refreshes on demand.

### Permission and feature-flag scoping

- Every provider names its own permission. The registry filters providers per user (only those the user can `->can()` pass through).
- Feature flags (e.g. `InventoryFeature`) hide providers entirely when OFF. An OFF flag returns no items and no count.
- The result: finance never sees a KYC queue; an ops user without `inventory.manage` cannot snooze a stock alert.

## Consequences

### Positive

- **No sync risk.** Queries run live; the source of truth is always authoritative.
- **Simple snooze.** One table, one write, one audit entry. The deferral is transparent — it tells admins "we know about this, deal with it later" without hiding the actual work.
- **Statutory items stay visible.** A refund past its promise window, an invoice missing since payment, a grievance past SLA — these cannot be snoozed away. The manager must resolve them.
- **Deep links.** Every action routes to the existing screen that already works (admin order show, refund list, KYC queue, etc.), so no new fixing UI is needed.
- **Permission-scoped.** RBAC is enforced per provider; a user without the permission gets a 403, not a censored view.

### Negative

- **Query load.** The summary queries run live, even cached at 60 seconds. A very high-traffic action type (e.g. low-stock alerts) on every page load could become visible. Mitigated by the cache and by careful indexing (each provider has a single indexed count query).
- **Consistency burden.** Developers must use the `ActionProvider` contract; a custom provider with an unindexed query or N+1 loads will slow the page. Code review and tests catch this.

### Neutral

- **No email digest yet.** Section 8 of the plan deferred the daily digest (intended as a notification, one command, one email template). The settings key `action_center.digest_recipients` is therefore not added yet — it will join the registry when the digest is scheduled.

## Alternatives rejected

### Alt 1: Sync with eventual consistency

Materialize the action items in a table and sync via a job.

**Rejected because:**

- Drift (missed triggers, failed jobs, skipped code paths) is hard to debug and can go unnoticed for days.
- Orphaned rows (deleted subjects) stay in the alert table.
- Multiple sources of truth (the source + the materialized copy) complicate reasoning.
- Testing is harder; the sync job must be exercised, mocked, or deferred.

### Alt 2: Role-scoped access instead of permission-scoped

Group providers by role (admin sees all, ops sees only orders/stock, finance sees only refunds/payouts) instead of naming a capability per provider.

**Rejected because:**

- Roles change; permissions are the contract. A role can be granted and revoked; a capability names exactly what access means.
- Separation of duties requires permission-level control (finance.approve ≠ finance.record), which roles cannot express.
- Cross-cutting permissions (e.g. a distributor.freeze permission shared by compliance and admin-operations) are easier to reason about per provider than per role.

### Alt 3: Automatic escalation (no snooze)

Omit snooze; force the manager to fix everything they see.

**Rejected because:**

- Knowing "I cannot fix this now; I will revisit tomorrow" is a valid admin workflow. Without snooze, items pile up and the screen becomes noise (a fixed-cost alert tax).
- Statutory items are already unsnoozable (grievances, refunds, invoices), so the action center naturally prioritizes what must be done today.

## References

- `docs/plans/2026-09-12-action-center.md` — full design and build plan (sections 0–10)
- `app/Modules/ActionCenter/Contracts/ActionProvider.php` — the contract every provider implements
- `app/Modules/ActionCenter/Services/ActionCenterRegistry.php` — permission filtering and tagged registration
- `app/Modules/ActionCenter/Services/ActionCenterService.php` — summary caching and snooze/unsnooze
- Providers: `app/Modules/ActionCenter/Providers/{Orders,Stock,Money,People,Compliance,Platform}/*.php`
- `docs/runbooks/action-center.md` — operational guide for the six action groups
