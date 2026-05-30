# ADR-0003 — Customer is a first-class entity distinct from Distributor

- **Status:** Accepted
- **Date:** 2026-04-24
- **Deciders:** Laravel Architect, Compliance Officer, Engineering Lead
- **Supersedes:** —
- **Superseded by:** —

## Context

Phase 2 introduces a first-party e-commerce storefront alongside the Phase 1 direct-selling network. A fundamental modelling question arises:

> Does a person buying hand wash from the Arovolife shop need to be a Distributor in our Genos (binary placement tree)?

DSR 2021 Rule 5(1)(a) permits first-party retail sales to end-consumers. DPDP Act §6 requires consent to be purpose-limited. T&C §4 forbids any joining fee. Hard Rule 2 requires every commission row to carry a `product_sale_id` — if buyers must first register as distributors, we risk conflating "purchase" with "joining", which the regulator reads as a scheme.

## Options considered

### A. One entity — every buyer must register as a distributor

- **Pros:** Simpler schema (no separate `customers` table). Every purchase is already tied to a tree node.
- **Cons:**
  - Forces joining to buy → violates Hard Rule 1.
  - Forces PAN/Aadhaar/bank KYC on casual buyers → violates DPDP §6 data-minimisation.
  - Puts the first order on the cooling-off runway of the distributor *agreement* rather than per-order, conflating two statutory clocks.

### B. Single User table with a boolean "is_distributor"

- **Pros:** One login identity, cheaper to glue Commerce and Registration.
- **Cons:** Shared columns become a mess (PAN optional, Aadhaar optional, binary position optional). FK from `orders.customer_id` becomes semantically confused. Cannot enforce "registration never writes orders" at the type-system level.

### C. Two distinct entities — Customer and Distributor — linked optionally

- **Pros:**
  - Registration module has zero imports from Commerce; static analysis rule enforces this permanently.
  - A single person may be a pure Customer, a pure Distributor (never bought anything — just in the tree), or both (linked via `customers.distributor_id`).
  - DPDP consent scope is naturally respected — a Customer's consent to "purchase and shipping" is not inherited into a Distributor's consent to "tree membership".
  - House-distributor anti-pattern is avoided: `orders.attributed_distributor_id` is nullable, so un-attributed sales simply have no referrer (retail margin goes to `revenue.house_margin`, no phantom tree seat).

- **Cons:** Extra table; must reconcile identities when a Customer later becomes a Distributor (handled by an OTP-verified merge flow).

## Decision

Adopt **Option C**.

- New table `customers` (owned by the Commerce module). Minimal PII: name, email (hashed for lookup), phone (hashed), marketing opt-in. Encrypted at rest.
- `customers.distributor_id` is a nullable FK to `distributors.id`. If the buyer happens to be a distributor, we link — `self_consumption=true` purchases count toward BV but not retail-margin commission.
- `customers.user_id` is a nullable FK to `users.id`. NULL = guest checkout.
- When a Distributor registers, a listener on `genealogy.distributor.registered` auto-backfills a matching Customer row. Distributors therefore have a Customer record from day one without duplicating identity state.
- Past orders are immutable — if a Customer later becomes a Distributor under a different sponsor, old orders stay attributed to whoever referred them at the time.

## Consequences

- **Positive**
  - Hard Rule 1 (free joining) cannot be accidentally broken — registration flow literally cannot import from Commerce (Larastan architecture rule blocks it).
  - DPDP purpose-limitation satisfied naturally.
  - Guest checkout is trivial (just leave `user_id` and `distributor_id` NULL).
  - Commerce can launch without Compensation — commission rows are not required for a sale to complete.

- **Negative**
  - Slightly higher storage (one row per Customer) — negligible.
  - Identity merge flow required when a past Customer joins as Distributor — but this is a one-time OTP-gated flow, not a hot path.

- **Neutral**
  - Front-end must show different header states (guest / Customer / Distributor / both) — handled with a single `view_as` helper.

## Enforcement

- A Larastan architecture rule forbids any import from `App\Modules\Commerce\*` into `App\Modules\Identity\Http\Controllers\Registration\*`.
- A CI test `RegistrationHasZeroOrdersTest` runs the full 10-step wizard 100 times and asserts zero rows are created in `orders`, `order_items`, `commissions`.
- A CI test `CustomerDistributorLinkTest` asserts every Distributor has a `customers` row after registration completes.

## References

- DSR 2021 Rule 5(1)(a) — free joining
- DPDP Act 2023 §6 — purpose-limited consent
- CLAUDE.md hard rules 1, 2
- `app/app/Modules/Identity/Models/User.php`
- `app/app/Modules/Genealogy/Services/PlacementEngine.php`
