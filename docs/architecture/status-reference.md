# Status Reference — Arovolife Platform

> A catalogue of every status/lifecycle enum on the platform, grouped by module.
> Values shown are the **internal enum values** stored in the database; where a
> distinct **user-facing label** exists, it is called out.
>
> **Source of truth is the code, not this file.** When you add or rename a
> status, update the migration/model *and* this doc in the same change. Verify
> against the cited file before relying on a value.

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
through `App\Modules\Identity\Models\User::STATUS_LABELS` / `statusLabel()` so the
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

Defined in:
- `app/app/Modules/Identity/Database/Migrations/2026_04_19_000001_create_users_table.php`
  (enum), `..._2026_05_24_000001_add_rejected_status_to_users.php` (added `rejected`).
- Labels: `app/app/Modules/Identity/Models/User.php` (`STATUS_LABELS`, `statusLabel()`,
  `statusTheme()`, `accountStatusLabel()`, `treeLegend()`).

### `users.closure_type` — *why* a `terminated` account closed

| Value | Resulting label | Meaning |
|---|---|---|
| `cooling_off_cancellation` | **Cancelled** | The distributor exercised their statutory 30-day cooling-off right to cancel (self-initiated). |
| `admin_termination` | **Terminated** | An admin permanently closed the account (fraud / repeat offender / policy). |

Defined in: `app/app/Modules/Identity/Database/Migrations/2026_05_25_000002_add_closure_type_to_users.php`.

### `distributors.status` — the distributor *record* (separate axis)

Default: `active`. Governs the distributor position/record, not login.

| Value | Meaning |
|---|---|
| `active` | The distributor record is active (admin "Activate Distributor"). |
| `inactive` | The record is deactivated (admin "Deactivate Distributor"); the user account may still exist independently. |

Defined in: `app/app/Modules/Identity/Database/Migrations/..._add_status_to_distributors_table.php`.

---

## Kyc (Phase 1 — live)

KYC review **piggybacks on `users.status`** — approval flips `pending` → `active`,
rejection sets `rejected`, and a compliance hold is `frozen` (Blocked). Documents
themselves carry **flag columns** rather than a status enum:

- `kyc_documents.flagged_reason` / `flagged_at` / `flagged_by` — when set, the
  document is "flagged for re-upload" (the re-upload flow); otherwise unflagged.

Defined in: `app/app/Modules/Kyc/Database/Migrations/2026_05_28_000001_add_flag_columns_to_kyc_documents_table.php`.

---

## Genealogy (Phase 1 — live)

### `line_change_requests.status` — placement / line-move approvals

| Value | Meaning |
|---|---|
| `pending` | Request submitted, awaiting admin decision. |
| `approved` | Admin approved the placement / line change. |
| `rejected` | Admin declined it. |
| `expired` | The request lapsed without a decision. |

Defined in: `app/app/Modules/Genealogy/Database/Migrations/..._create_line_change_requests_table.php`.

---

## Catalog (Phase 2 — live)

| Field | Values | Default | Meaning |
|---|---|---|---|
| `products.status` | `draft` · `active` · `archived` | `draft` | Draft = not listed; active = on the storefront; archived = pulled. |
| `product_variants.status` | `active` · `archived` | `active` | Whether the specific SKU/variant is sellable. |
| `product_categories.status` | `active` · `archived` | `active` | Whether the category shows in storefront nav/pills. |
| `banners.status` | `active` · `archived` | `active` | Whether the banner is eligible to display (combined with its schedule). |

Defined in: `app/app/Modules/Catalog/Database/Migrations/..._create_catalog_tables.php`,
`..._create_product_categories_table.php`, `..._create_banners_table.php`.

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
| `refund_requested` → `refund_inspection` → `refunded` | The return / refund pipeline (cooling-off returns). |

Defined in: `app/app/Modules/Commerce/Models/Order.php` (`STATUS_*` constants).

| Other field | Values | Default | Meaning |
|---|---|---|---|
| `carts.status` | `open` · `expired` · `cancelled` | `open` | Shopping-cart lifecycle; expires after its TTL. |
| `coupons.status` | `active` · `archived` | `active` | Whether a promo code can be applied. |

Defined in: `app/app/Modules/Commerce/Database/Migrations/..._create_commerce_tables.php`,
`..._create_coupons_table.php`.

---

## Payments (Phase 2 — stub gateway live)

| Field | Values | Default | Meaning |
|---|---|---|---|
| `payments.status` | `created` · `authorised` · `captured` · `failed` · `cancelled` | `created` | Gateway intent lifecycle; `captured` = money taken (the Phase-2 stub auto-captures). |
| webhook / refund events | `created` · `processed` · `failed` | `created` | Idempotent processing state of an inbound gateway event. |

Defined in: `app/app/Modules/Payments/Database/Migrations/..._create_payments_tables.php`.

---

## Content (Phase 1 — live)

| Field | Values | Default | Meaning |
|---|---|---|---|
| `content_pages.status` | `draft` · `published` · `archived` | `draft` | CMS pages (Terms, Privacy, Grievance, etc.); only `published` is publicly visible. |

Defined in: `app/app/Modules/Content/Database/Migrations/..._create_content_pages_table.php`.

---

## Fulfilment / Returns / Grievance (scaffolded — later phases)

Schema exists; these flows are not yet wired into day-to-day operations.

| Module · field | Values | Default | Meaning |
|---|---|---|---|
| Fulfilment · `shipments.status` | `created` · `picked` · `dispatched` · `delivered` · `returned_to_origin` | `created` | Courier-side movement of a shipment. |
| Returns · `returns.status` | `opened` · `pickup_scheduled` · `received` · `inspected` · `approved` · `rejected` · `refunded` | `opened` | Return-merchandise pipeline (buyback / cooling-off returns). |
| Grievance · `grievances.status` | `open` · `acknowledged` · `in_progress` · `resolved` · `closed` | `open` | DSR-2021 grievance-redressal SLA workflow. |

Defined in: `app/app/Modules/Fulfilment/Database/Migrations/..._create_shipments_table.php`,
`app/app/Modules/Returns/Database/Migrations/..._create_returns_tables.php`,
`app/app/Modules/Grievance/Database/Migrations/..._create_grievance_tables.php`.

---

## Compliance (Phase 1 — live)

Cooling-off is tracked as **events with reminder timestamps** (`cooling_off_events`),
not a status enum — the D-20 / D-7 / D-1 reminder columns drive the statutory
30-day window. See `app/app/Modules/Compliance/Database/Migrations/2026_04_29_000001_add_reminder_columns_to_cooling_off_events.php`
and `docs/architecture/adr-0005-order-cooling-off-clock.md`.
