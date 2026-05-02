# Runbook — Change the Placement Strategy

> The Placement Strategy is the single company-wide setting that decides
> the leg under `placement_id` for every new registration. Changes are
> audited; in-flight registrations are not affected.

## Who can change it

- `super-admin` — yes
- `admin-compliance` — yes (with mandatory reason-text)
- Anyone else — no

## Allowed values

- `default_left` — every registration defaults to LEFT leg of `placement_id`.
- `default_right` — every registration defaults to RIGHT leg.
- `custom` — sponsor (or prospect) must pick L or R at registration.

Sub-toggle `placement.allow_sponsor_override` (bool, default `true`):
when strategy is `default_left` or `default_right`, may a sponsor
explicitly choose the opposite side?

## Procedure

1. Open the admin console → Settings → Placement Strategy.
2. Read the current value and the change history (audit log).
3. Click "Change strategy".
4. Pick the new value AND enter a reason-text (≥ 20 chars). The reason
   is stored in `audit_log.details.reason`.
5. Confirm. The setting writes a new row version, the change is audit-logged,
   and the cache is atomically invalidated.

## What happens to in-flight registrations

A registration session captures the strategy at session start. Sessions
already in progress when the strategy changes continue under the
*captured* strategy. Only sessions started AFTER the change use the new
strategy.

This is intentional and prevents a sponsor from "rerouting" a prospect
mid-flow.

## Verifying the change

After saving, run from a developer shell:

```
php artisan tinker --execute="echo \\App\\Modules\\Shared\\Services\\SettingsRepository::class . ' = ' . app(\\App\\Modules\\Shared\\Services\\SettingsRepository::class)->get('placement.default_side');"
```

Or query directly:

```sql
SELECT key, value, version, updated_at
FROM settings
WHERE key IN ('placement.default_side','placement.allow_sponsor_override');
```

## Rollback

Revert by changing the value back. The `version` increments; both
changes are visible in the audit log. Snapshots on existing
`distributors.placement_strategy_snapshot` are immutable — historical
placements stay interpretable regardless of the rollback.

## What can go wrong

| Symptom | Cause | Action |
|---|---|---|
| Setting saved but new registrations still use the old leg | Cache not invalidated | Run `php artisan cache:clear`; investigate cache invalidation hook |
| Audit log row missing | Listener failure | Check queue worker logs; replay the listener from the event id |
| Sponsor reports unexpected side | `allow_sponsor_override` was off without notifying support | Confirm by checking the audit log; communicate policy to sponsors |

## Compliance notes

- A strategy change is an `admin.settings.changed` event of type
  `placement.default_side`. The compliance officer subagent reviews
  these in every weekly compliance report.
- Never change the strategy "informally" via direct SQL. Always go
  through the admin console so the audit log captures the actor and reason.
