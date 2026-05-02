# DSR 2021 / T&C → Module Mapping

This is the compliance officer's primary reference. Every statutory
obligation has an owning module and an owning Phase. A change in any of
these clauses requires updating this map.

## Phase 1 obligations (active now)

| Clause | Obligation | Owning module | Test |
|---|---|---|---|
| DSR Rule 5(1)(a) / T&C §4 | Joining is free of cost | Identity, Registration | `RegistrationFreeJoiningTest` |
| T&C §1.5 / Code Step 2 | Mandatory orientation | Orientation | `OrientationGateTest` |
| T&C §1.1 / Code II.a | 18+ (21+ in Maharashtra) | Identity | `AgeGateTest`, `MaharashtraAgeRuleTest` |
| T&C §1.4 | One PAN = one ADN | Identity, Kyc | `PanUniquenessTest` |
| T&C §3.I Step 9 / IT Act §10A | Electronic record is binding | Identity, Consent | `ElectronicRecordTest` |
| T&C §4 / Code V | 30-day cooling-off | Compliance | `CoolingOffEvaluatorTest`, `CoolingOffCancelTest` |
| T&C §7 | Couple distributorship rules | Identity, Genealogy | `CoupleRegistrationTest` |
| T&C §10 | Line-change ≤ 5 working days, no downline, no purchases | Genealogy | `LineChangeWindowTest` |
| T&C §15 / DPDP §6 | Versioned consent, PII protection | Consent, Kyc | `ConsentVersioningTest`, `KycEncryptionTest` |
| T&C §21 | Auto-termination after 1y inactivity | Compliance | (scaffold only in Phase 1) |
| Plan placement spec / ADR-0002 | Placement Strategy admin setting | Genealogy, Admin | `PlacementStrategyResolverTest`, `PlacementStrategyAuditTest` |
| Code IV.XII.a / DSR 5(1)(d) | No mis-selling / income projection | UX writing skill | `PublicCopyAuditTest` |

## Forward obligations (Phases 2–10)

| Clause | Obligation | Owning module | Phase |
|---|---|---|---|
| DSR Rule 5(1)(c) | Commission only on product sales | Compensation | 4 |
| T&C §8 | Buyback / refund policy | Commerce, Compliance | 8 |
| T&C §9 | Prohibited sales channels | Commerce, Compliance | 2, 8 |
| T&C §11 | Grievance redressal | Compliance | 8 |
| Income Tax / GST | TDS, GST, admin charge | Wallet, Compensation | 3, 4 |

## When this file changes

- New T&C version released → diff against this file; raise an ADR if
  obligations change.
- New DSR amendment notified → same.
- Compliance Officer must initial each row that is verified during a
  phase exit gate (in the relevant phase's exit checklist).
