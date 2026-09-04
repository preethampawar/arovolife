# Status Reference — Arovolife Platform

> A catalogue of every status/lifecycle enum on the platform, grouped by module.
> Values shown are the **internal enum values** stored in the database; where a
> distinct **user-facing label** exists, it is called out.
>
> **Source of truth is the code, not this file.** When a status is added or renamed, this doc is updated in the same change.

---

## Conventions

- "Phase 1 — live" = shipped and in use now. "Phase 2 — live" = commerce/catalog
  sprint, in use. "Scaffolded — later phases" = schema exists but the feature is
  not yet wired into day-to-day flows.
- A distributor carries **two independent status axes** — do not conflate them:
  - `users.status` — can they sign in / account lifecycle.
  - `distributors.status` — is the distributor *record* active or inactive.

---

## Identity (Phase 1 — live)

### `users.status` — the account lifecycle (canonical, single-sourced)

Default: `pending`. The master account state for every member. All user-facing
surfaces (distributor dashboard, Genos tree legend, admin, reports) render these
through one shared label map, so the
same status never reads differently in two places.

| Value | Label (canonical) | Meaning |
|---|---|---|
| `pending` | **Pending** | Registered but not fully activated (KYC / orientation / cooling-off not cleared). Default on creation. |
| `active` | **Active** | Fully onboarded, KYC-approved; can sign in and operate normally. |
| `frozen` | **Blocked** | Compliance/admin hold — cannot sign in until unblocked. Reversible (admin Block / Unblock). |
| `terminated` | **Terminated** / **Cancelled** | Permanently closed; can never sign in. Split by `closure_type` (below). |
| `rejected` | **Rejected** | KYC application rejected; the applicant can re-upload and resubmit. |

> **`frozen` vs "Blocked":** the stored value stays `frozen` and the audit-log
> action keys stay `admin.distributor.frozen` / `unfrozen` for traceability —
> only the user-facing word is "Blocked".

### `users.closure_type` — *why* a `terminated` account closed

| Value | Resulting label | Meaning |
|---|---|---|
| `cooling_off_cancellation` | **Cancelled** | The distributor exercised their statutory 30-day cooling-off right to cancel (self-initiated). |
| `admin_termination` | **Terminated** | An admin permanently closed the account (fraud / repeat offender / policy). |

### `distributors.status` — the distributor *record* (separate axis)

Default: `active`. Governs the distributor position/record, not login.

| Value | Meaning |
|---|---|
| `active` | The distributor record is active (admin "Activate Distributor"). |
| `inactive` | The record is deactivated (admin "Deactivate Distributor"); the user account may still exist independently. |

---

## Kyc (Phase 1 — live)

KYC review **piggybacks on `users.status`** — approval flips `pending` → `active`,
rejection sets `rejected`, and a compliance hold is `frozen` (Blocked). Documents
themselves carry **flag columns** rather than a status enum:

- `kyc_documents.flagged_reason` / `flagged_at` / `flagged_by` — when set, the
  document is "flagged for re-upload" (the re-upload flow); otherwise unflagged.

---

## Genealogy (Phase 1 — live)

### `line_change_requests.status` — placement / line-move approvals

| Value | Meaning |
|---|---|
| `pending` | Request submitted, awaiting admin decision. |
| `approved` | Admin approved the placement / line change. |
| `rejected` | Admin declined it. |
| `expired` | The request lapsed without a decision. |

---

## Catalog (Phase 2 — live)

| Field | Values | Default | Meaning |
|---|---|---|---|
| `products.status` | `draft` · `active` · `archived` | `draft` | Draft = not listed; active = on the storefront; archived = pulled. |
| `product_variants.status` | `active` · `archived` | `active` | Whether the specific SKU/variant is sellable. |
| `product_categories.status` | `active` · `archived` | `active` | Whether the category shows in storefront nav/pills. |
| `banners.status` | `active` · `archived` | `active` | Whether the banner is eligible to display (combined with its schedule). |

---

## Commerce (Phase 2 — live)

### `orders.status` — order lifecycle

| Value | Meaning |
|---|---|
| `draft` | Being assembled (pre-placement). |
| `placed` | Order placed (COD / unpaid sits here until collected). |
| `paid` | Payment captured. |
| `ready_to_ship` | Picked / packed, awaiting dispatch. |
| `shipped` | Handed to courier. |
| `delivered` | Delivered to the customer. |
| `confirmed` | Delivery confirmed / cooling-off window running. |
| `cancelled` | Cancelled before fulfilment. |
| `refund_requested` | Customer has opened a return request; awaiting admin inspection (non-cooling-off) or auto-processing (cooling-off). |
| `refund_inspection` | Admin has recorded the physical inspection; awaiting approve/reject decision. |
| `refund_approved` | Ledger reversed, BV reversed, refund created. Sent to Razorpay at once after an inspection; for a cooling-off return it is **held** until the return is marked received, and the points / repurchase credit are returned at the same moment. Customer copy: *"Refund approved — once we receive the returned product, credited within 7 working days."* |
| `refunded` | Razorpay confirmed the refund processed (or finance recorded a manual NEFT). Customer copy: *"Refund complete."* |

| Other field | Values | Default | Meaning |
|---|---|---|---|
| `carts.status` | `open` · `expired` · `cancelled` | `open` | Shopping-cart lifecycle; expires after its TTL. |
| `coupons.status` | `active` · `archived` | `active` | Whether a promo code can be applied. |

---

## Payments (Phase 2 — stub gateway live)

| Field | Values | Default | Meaning |
|---|---|---|---|
| `payments.status` | `created` · `authorised` · `captured` · `failed` · `cancelled` | `created` | Gateway intent lifecycle; `captured` = money taken (the Phase-2 stub auto-captures). |
| webhook / refund events | `created` · `processed` · `failed` | `created` | Idempotent processing state of an inbound gateway event. |

---

## Content (Phase 1 — live)

| Field | Values | Default | Meaning |
|---|---|---|---|
| `content_pages.status` | `draft` · `published` · `archived` | `draft` | CMS pages (Terms, Privacy, Grievance, etc.); only `published` is publicly visible. |

---

## Fulfilment / Returns / Grievance

| Module · field | Values | Default | Meaning |
|---|---|---|---|
| Fulfilment · `shipments.status` | `created` · `picked` · `dispatched` · `delivered` · `returned_to_origin` | `created` | Courier-side movement of a shipment. (Scaffolded — later phases.) |
| Returns · `return_requests.reason` | `cooling_off` · `damage` · `dissatisfaction` · `general_buyback` · `termination_buyback` | — | T&C §8 / BuybackMatrix return reason. (Phase 2 — live.) |
| Returns · `return_requests.status` | `opened` · `approved` · `rejected` | `opened` | Return request lifecycle. `opened` = awaiting review; `approved` = refund executed; `rejected` = admin rejected. (Phase 2 — live.) |
| Grievance · `grievances.status` | `open` · `acknowledged` · `in_progress` · `resolved` · `closed` | `open` | DSR-2021 grievance-redressal SLA workflow. (Scaffolded.) |

---

## Compliance (Phase 1 — live)

Cooling-off is tracked as **events with reminder timestamps** (`cooling_off_events`),
not a status enum — the D-7 / D-1 reminder columns drive the statutory
30-day window.
