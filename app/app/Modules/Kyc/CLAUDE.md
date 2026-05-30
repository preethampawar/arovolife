# Module: Kyc

## Scope

KYC document storage, review, flagging and re-upload — for distributor onboarding (registration wizard step 7) and post-registration self-service.

## Document type enum (`kyc_documents.type`)

This is a MySQL `ENUM` column. Current valid values:

- `pan`
- `aadhaar`              (Aadhaar front — image of the front side of the physical card)
- `aadhaar_back`         (added 2026-05-30 via migration `add_aadhaar_back_to_kyc_documents_type`)
- `cheque`
- `address_proof_front`
- `address_proof_back`
- `photo`                (id-card photo; handled by `IdPhotoController`, NOT by the wizard)

To add or remove a value you MUST write a new migration that runs `ALTER TABLE kyc_documents MODIFY COLUMN type ENUM(...)` with the full new list. Do NOT edit the historical create-table migration.

## Where each doc type is wired (the "six surfaces")

Every type that's collected from a distributor appears in ALL of these — keep them in sync or the UI will quietly drop the new field:

1. **`app/Modules/Identity/Http/Controllers/Registration/RegistrationWizardController.php`**
   - `KYC_DOC_FIELDS` — logical type → form field name (handler-side)
   - `KYC_DOC_REQUIRED_FIELDS` — subset that must be uploaded to finalise
   - Attribute names in `handleDocuments()` for friendly error messages
2. **`app/Modules/Identity/Http/Controllers/KycDocumentSelfServiceController.php`**
   - `SELF_SERVICE_TYPES` — types the distributor can manage post-registration
3. **`resources/views/registration/step7-documents.blade.php`** — `$fields` array (label / help / required)
4. **`resources/views/dashboard/kyc-documents.blade.php`** — `$labels` array
5. **`resources/views/identity/kyc-resubmit.blade.php`** — file input markup per type (used after admin rejection)
6. **`resources/views/admin/kyc/show.blade.php`** — admin upload-on-behalf-of dropdown options

> If you skip any one of these, the admin can't see/upload the new doc OR the distributor can't replace it later — both are quiet, frustrating bugs.

## Re-upload flow (single-doc, admin-flagged)

- Admin flags a doc via `kyc_documents.flagged_at` + `flag_reason` (see migration `2026_05_28_000001_add_flag_columns_to_kyc_documents_table.php`).
- Distributor receives a `KycDocumentFlaggedNotification` (mail + database).
- Re-upload page is a **signed URL** scoped to the single flagged document → only that one type is shown.
- `KycDocumentReuploadController` derives the human label as `ucwords(str_replace('_', ' ', $document->type))` — so `aadhaar_back` renders as "Aadhaar Back" with no extra mapping.

## PII handling (hard rule #8)

- Raw Aadhaar number must NOT be stored — only AUA/KUA reference + last-4.
- PAN stored as hash + last-4.
- Image scans (front/back) ARE stored on the `kyc` private disk — same as the existing Aadhaar front. Adding the back was a posture-equivalent change, not a new PII class.
- Logs scrub PAN / Aadhaar via the PII scrubber middleware — do not log raw doc paths that could leak the distributor ID either if you can avoid it.

## Audit trail

- Every admin action that mutates a `kyc_documents` row (approve, reject, flag, replace) emits an `audit_log` entry. New endpoints in this module MUST follow that pattern.

## Tests

- Pest tests live at `tests/Modules/Kyc/`. Use the existing factories rather than seeding rows manually.
- Run with `docker exec arovolife-app php artisan test --compact tests/Modules/Kyc/`.
