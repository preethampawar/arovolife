# Test Strategy — Phase 1

## Pyramid

```
                                 ┌──────────────┐
                                 │  Accessibility│  ← WCAG 2.1 AA on wizard
                                 └──────────────┘
                           ┌───────────────────────┐
                           │     Security / DAST    │  ← OWASP ZAP, IDOR fuzz
                           └───────────────────────┘
                      ┌───────────────────────────────┐
                      │   End-to-end (Laravel Dusk)   │  ← happy-path registration, login, tree
                      └───────────────────────────────┘
                 ┌───────────────────────────────────────┐
                 │           Property-based tests          │  ← randomised placement invariants
                 └───────────────────────────────────────┘
            ┌────────────────────────────────────────────────┐
            │       Integration / feature (HTTP + DB)         │  ← policies, validators, flow
            └────────────────────────────────────────────────┘
    ┌──────────────────────────────────────────────────────────────┐
    │                       Unit tests                              │  ← services, resolvers, DTOs
    └──────────────────────────────────────────────────────────────┘
```

## Frameworks

- Pest (PHPUnit under the hood) for unit / feature / property.
- Laravel Dusk for E2E.
- axe-core or Pa11y for accessibility.
- OWASP ZAP for DAST baseline.

## Coverage targets

- Unit coverage ≥ 80%.
- Every story US-1.01 … US-1.16 has: 1 happy-path feature test + 2 edge-case tests.
- Every scenario in `placement-engine-spec/test-scenarios.md` has a matching automated test.
- No new file added without tests (the CI gate enforces).

## Property-based tests

Use generators for:

- Random trees up to depth 50.
- Random sequences of placements under random sponsors.
- Random Placement Strategy flips between registrations.

Invariants (see `placement-engine-spec/algorithm-proof.md`):

- Unique `(placement_parent_id, placement_side)` across all rows.
- `depth` in closure matches adjacency walk.
- Every placement's sponsor is an ancestor (or equal) to its
  `placement_id_at_registration`.
- `placement_strategy_snapshot` is non-null.
- Re-registering the exact same sequence on a fresh DB produces the
  same final tree (determinism).

## Golden-master tests

For later phases (4+) only; included here for forward context.

- Seeded network → reference payout output → any code change must not
  change the output unless a compliance-approved delta is recorded.

## Performance tests

- k6 scenario: 100 concurrent registrations; 10,000 placements/hour on a
  1M-row tree; p95 placement latency ≤ 250 ms.
- Load test the admin-export of the Register of Direct Sellers at
  1M distributors.

## Negative-path tests (Phase 1)

- Sponsor not found
- Placement_id outside sponsor downline → reject + audit log row
- PAN already used → reject
- Under-age (18/21 state-aware) → reject
- Orientation not completed → cannot finalise
- Line-change on day 5 (accept) and day 6 (reject)
- Cooling-off on day 30 (accept) and day 31 (reject)
- Strategy change mid-flow (in-flight session unaffected)
- Client-supplied side when strategy is `default_left` and override off → ignored

## CI gates

- Pint, Larastan level 7, PHPStan, Psalm all clean.
- Tests must pass. Coverage must not regress.
- `composer audit` / `npm audit` must be clean for High/Critical.
- gitleaks must pass.
- `/compliance-check` on the diff must not report Critical/High.
