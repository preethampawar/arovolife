# ADC (Arete Development Centre) registry + application — field spec

**Status:** spec agreed 2026-08-30; **built 2026-08-30** behind the `compensation.arete_center_applications` flag (registry columns/filters/status actions ship ungated under the existing ADC flag; Step-11 picker is a grouped dropdown). Source: the client's four reference
screenshots (a competitor's outlet application form + branch list) and the client's
six decisions of 2026-08-30. Builds on the shipped manual ADC phase-tracking workflow
(`arete_centers`, commit e79c39e) — extend that table, do not create a parallel one.

## Decisions (2026-08-30)

| # | Question raised | Decision |
|---|---|---|
| 1 | Admin-only registry vs self-service | **Distributor applies → admin approves.** Admin can still create/edit centres directly (company centres). |
| 2 | "Distance from nearest ADC (km)" is unverifiable | **Keep it**, plain number, entered/updated manually by the applicant. |
| 3 | Minimum premises size | **Number input; minimum comes from an admin-configured setting** and is pre-filled as the field's initial value. |
| 4 | List filters | **Add filters now** (more centres coming). **State is always a dropdown** (fixed Indian states/UT list) — on the application form, on admin filters, on the Step-11 picker. |
| 5 | Missing 4th page (declarations/uploads) | **Add declaration + document-upload fields**, modelled on the reference form. |
| 6 | Hard-rule-7 wording | Agreed: an ADC is a **development / training centre**, never described as a shop, outlet, retail point or store in any user-facing copy. |

## Workflow

```
draft → submitted → under_review → approved (centre status=active) | rejected
                                  ↘ needs_changes → (applicant edits) → submitted
```

- One open application per distributor at a time; an approved applicant becomes the
  centre's `assigned_distributor_id`.
- Approval creates/activates the `arete_centers` row with `development_phase = 1`.
- Every admin transition is audit-logged (before/after) and notifies the applicant.
- Rejection / needs-changes require an admin reason shown to the applicant.
- Feature-flag gated (zero-trace when OFF, per project convention); the shipped
  admin phase-tracking stays available regardless.

## A. Application form (distributor side)

### A1. Applicant — all **read-only**, pulled from the distributor record (never re-entered)

Name · ADN · date of joining (effective date) · current level (rank) · registered address ·
mobile · email · sponsor name + ADN, with the sponsor's date of joining and current level
**masked behind an eye toggle** · PAN, bank account number and IFSC **shown masked
(last 4) with the same eye toggle** — read-only, pulled from KYC / bank details, never
re-entered. The only applicant-side input is an optional **alternate mobile number**
(client form, 30-08-2026). Revealed PAN shows the full number only while the platform
still holds it (it is purged to its last 4 after KYC verification).

### A2. Centre identity

| Field | Type | Rules |
|---|---|---|
| Proposed centre name | text | required, unique among active centres |
| Contact person for the centre | text | optional, defaults to applicant |
| Alternate contact number | phone | optional |

### A3. Premises

| Field | Type | Rules |
|---|---|---|
| Address line 1 | text | required |
| Address line 2 | text | optional |
| Landmark | text | required |
| Pincode | 6-digit | required |
| City / district | text | required |
| State | **dropdown** (states + UTs) | required |
| Property type | radio: Commercial / Residential | required |
| Size (sq. ft) | number | required; `min` = admin setting `adc.min_premises_sqft`; pre-filled with that value; server-side validation against the same setting |
| Distance from nearest active ADC (km) | number (1 decimal) | required, self-declared, editable by applicant later |
| Operating hours | time From / time To | required |
| Weekly off | dropdown (Mon–Sun / None) | required |

### A4. Documents (uploads; private disk; admin-only viewing)

| Document | Required |
|---|---|
| Premises ownership proof **or** rent/lease agreement | yes |
| Address proof of premises (electricity bill / property tax receipt) | yes |
| Site photos — five named slots: **inside, front side, right side, left side, approach to the location** (client form, 30-08-2026) | yes, one image each |
| Trade / shop-establishment registration, if any | optional |

Documents: PDF/JPG/PNG, ≤ 5 MB each. Photos: JPG/PNG only, ≤ `adc.max_photo_kb`
(admin setting, default 500 KB per the client's form); the form shrinks larger
phone photos in the browser before upload. Stored under the application id, never
under the distributor's KYC folder.

### A5. Declarations (checkboxes, each individually required; text is versioned content)

1. I will use the centre only for training, product demonstration and distributor
   support; not as a retail store or e-commerce fulfilment point (T&C §9).
2. The premises details and documents are true and complete.
3. I understand the centre may be inspected, and that the development-phase
   requirements (400/600/900/1,200 sq ft) are enforced per the plan.
4. I agree the centre can be deactivated or transferred if it is not developed
   within the notified period.
5. I consent to the company contacting me on the numbers given about this centre.

Store: declaration version id, accepted-at timestamp, IP, per checkbox.

## B. Admin — centre registry

### Columns (list)

Sr · Centre name · City · State · Owner (ADN + name) · Type (company / distributor) ·
Phase · Status · Contact person · Contact number · Weekly off · Address (truncated)

### Filters

Status (all/active/inactive) · **State (dropdown)** · City (type-ahead) · Type ·
Phase · Owner ADN search · free-text name search

### Admin actions

Create (company centre) · Edit · Activate / Deactivate (with reason) · Set/clear
company default · Transfer to another ADN · Phase + cap override (already shipped) ·
Application review queue: approve / reject / request changes, view uploaded documents.

## C. Registration wizard — Step 11 picker

- Dropdown of **active** centres, grouped by state (alphabetical); company-default
  centre pre-selected; searchable when > ~20 entries.
- Displays: centre name — city, state. No owner details, no counts, no earnings.
- Inactive centres never listed; a centre that goes inactive after selection keeps
  the historical link (no rewrite of past registrations).

## D. Schema additions (extend, don't replace)

`arete_centers` add: `centre_type` (company|distributor), `address_line_1`,
`address_line_2`, `landmark`, `city`, `property_type`, `premises_sqft`,
`distance_to_nearest_adc_km`, `opening_time`, `closing_time`, `weekly_off`,
`contact_person`, `contact_number`, `alternate_contact_number`,
`deactivated_at`, `deactivation_reason`. Existing `location` / `district` become
legacy → migrate into `city` / `address_line_1` in the same migration set.

New: `arete_center_applications` (status machine above, snapshot of A2–A3, admin
notes, reviewed_by/at), `arete_center_application_documents`,
`arete_center_application_declarations`.

Settings registry: `adc.min_premises_sqft` (int, default 350, admin-owned — the client's
30-08-2026 form says 800; operations sets the floor) · `adc.max_photo_kb` (int, default 500, admin-owned).

## F. Members-only centre directory (client item 2, 30-08-2026)

`/arete-centres` for signed-in distributors: filters centre (dropdown), state (dropdown),
city (dropdown, narrowed by state); list S.No · City · Centre name · Weekly off ·
Contact person · Contact number · Address. Active centres only; no owner ADN, counts or
earnings. Not public: distributor-owned centres carry personal contact numbers (DPDP).

## E. Compliance notes

- Hard rule 7: copy audit on every string — "centre", never "shop / outlet / store".
- Hard rule 3: the application and Step-11 UI show no income, projections or
  phase-income figures to the applicant; the ₹20K–₹80K phase ladder is admin-only.
- Hard rule 8: PAN / bank shown masked and read-only to the applicant only; never collected
  or re-entered; documents on a private disk.
- Application is free; approval creates no purchase obligation (hard rule 1).
