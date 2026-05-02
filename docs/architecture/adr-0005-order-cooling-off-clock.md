# ADR-0005 — Per-order cooling-off clock is independent of the distributor-agreement cooling-off

- **Status:** Accepted
- **Date:** 2026-04-24
- **Deciders:** Laravel Architect, Compliance Officer, Legal
- **Supersedes:** —
- **Superseded by:** —

## Context

Phase 1 introduced a 30-day cooling-off window on the *distributor agreement* — runs from `effective_date`, cancellation fully refunds the distributor's joining state, and frees the ADN. Phase 2 introduces a *per-order* 30-day cooling-off window — runs from `delivered_at`, cancellation refunds the purchase per the T&C §8 buyback matrix.

It is tempting to model these as a single "cooling-off" feature. They are **not**. Conflating them creates regulatory exposure.

## Why two clocks

| Aspect | Distributor-agreement clock | Per-order clock |
|---|---|---|
| Trigger | `distributor.effective_date` | `order.delivered_at` |
| Duration | 30 days | 30 days (configurable ≥ statutory floor) |
| Refund subject | Registration state (zero ₹ in Phase 1, may include starter kit in future) | Purchase amount per T&C §8 matrix |
| Termination effect | Frees the ADN | Reverses one order only |
| Phase | 1 | 2 |
| Table | `distributors.cooling_off_end_at` | `order_cooling_off(order_id, opened_at, ends_at, status)` |

A distributor with 10 orders over 6 months has 1 distributor-agreement clock (long ago closed) plus up to 10 per-order clocks (each running 30 days from their own delivery).

## Decision

Model the two clocks as entirely separate concepts with separate tables, separate services, separate event streams, separate SMS templates.

### Schema

```sql
order_cooling_off (
    id, order_id UNIQUE, opened_at, ends_at,
    status ENUM('open','expired','cancelled'),
    refund_trigger_event_id NULL,
    INDEX idx_ends_at (status, ends_at)
)
```

### Lifecycle events

```
order.delivered      → opens order_cooling_off(status='open', ends_at = delivered_at + 30d)
order.refund_requested → order_cooling_off.status becomes irrelevant (refund proceeds regardless)
order.cooling_off_expired → status='expired' (fires when ends_at passes with no refund)
```

### SMS reminders

Per-order reminders at D-20, D-7, D-1 use the **Compliance SMS sender** built in Phase 1 for the distributor-agreement clock — but with a different template:

```
Phase 1: "Dear [name], your Arovolife cooling-off period ends in [N] days. Cancel anytime at [URL]."
Phase 2: "Dear [name], your return window for order [ORD-NNNN] closes in [N] days. Return via [URL] for full refund."
```

## Consequences

- **Positive**
  - Statutory minimum of 30 days can be independently policed on each clock. A setter on `commerce.cooling_off.days` refuses values below 30.
  - Two clocks are independently reconcilable — the daily cron scans `distributors` and `order_cooling_off` separately.
  - Late refunds per order never affect the distributor ADN state.
  - A compensation clawback from a late refund (see "late refund after unlock" in the plan doc) affects only the distributor's `wallets.available_paise` via a `wallet_debt` row — never the distributor's tree position.

- **Negative**
  - More tables, more events, more SMS templates.
  - Developers must carefully choose which clock applies in a given context. A convention: "distributor cooling-off" always refers to the Phase 1 clock; "order cooling-off" or "return window" always refers to the Phase 2 clock.

- **Neutral**
  - Feature flag `commerce.cooling_off.days` controls the order clock; `compliance.distributor_cooling_off.days` (existing) controls the distributor clock. Neither can drop below statutory floor.

## Enforcement

- CI test `CoolingOffReconciliationTest` — scans `orders` with `status=delivered`; for every row where `delivered_at + 30d < NOW()`, asserts either `order_cooling_off.status='expired'` or an explicit reason (e.g., `cancelled` via refund). Any mismatch fails CI.
- Daily ops cron with PagerDuty alarm for any missed expiry.
- `commerce.cooling_off.days` setter guards against values below 30 (raises a `CoolingOffFloorException`).

## References

- T&C §4 (distributor cooling-off, 30 days)
- T&C §8 (buyback matrix — per-order refund amounts)
- Consumer Protection (Direct Selling) Rules 2021 Rule 5(1)(g)
- ADR-0003 (Commerce Customer entity)
- Phase 1 `app/app/Modules/Compliance/` — distributor cooling-off implementation
