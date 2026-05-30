---
name: arovolife-kyc-document-flow
description: Canonical map of every KYC document type, the required-set, the six UI/controller surfaces that must stay in lock-step, and the rules for adding/removing a doc type. Activate whenever editing anything KYC-document related — adding a type, changing required vs optional, renaming a label, or wiring an admin review screen.
---

# Arovolife KYC document flow

## When to activate

Use whenever any of the following is in scope:

- adding a new doc type (e.g. "Aadhaar back" was added 2026-05-30)
- promoting an optional doc to required or demoting a required to optional
- changing labels / help text
- adding a new place where docs are shown (admin reports, KYC dashboards)
- writing tests for the upload / re-upload flow

## Canonical doc types

`kyc_documents.type` is a MySQL `ENUM`. Valid values (2026-05-30):

| Type | Source | Notes |
|---|---|---|
| `pan` | wizard + self-service | Required |
| `aadhaar` | wizard + self-service | Required. **Front** side of the physical Aadhaar card |
| `aadhaar_back` | wizard + self-service | Required. Back side with UIDAI-printed address |
| `cheque` | wizard + self-service | Optional (only needed when bank details are on file) |
| `address_proof_front` | wizard + self-service | Required |
| `address_proof_back` | wizard + self-service | Required |
| `photo` | dashboard self-service ONLY | Handled by `IdPhotoController`. NOT in the wizard / admin upload-on-behalf-of. |

## The six surfaces (must stay in lock-step)

When you add/remove/rename a doc type, update **every** one of these. Missing one is a quiet bug.

| # | File | What to update |
|---|---|---|
| 1 | `app/Modules/Identity/Http/Controllers/Registration/RegistrationWizardController.php` | `KYC_DOC_FIELDS` (logical type → form field name), `KYC_DOC_REQUIRED_FIELDS` (the must-upload subset), and the friendly attribute names in `handleDocuments()` |
| 2 | `app/Modules/Identity/Http/Controllers/KycDocumentSelfServiceController.php` | `SELF_SERVICE_TYPES` array |
| 3 | `resources/views/registration/step7-documents.blade.php` | `$fields` array (label / help / required) |
| 4 | `resources/views/dashboard/kyc-documents.blade.php` | `$labels` array |
| 5 | `resources/views/identity/kyc-resubmit.blade.php` | One `<input type="file">` block per type (used after admin rejection) |
| 6 | `resources/views/admin/kyc/show.blade.php` | `<option value="...">` in the admin upload-on-behalf-of dropdown |

The re-upload controller (`KycDocumentReuploadController`) derives the human label as `ucwords(str_replace('_', ' ', $document->type))` — so any new snake_case type renders cleanly without a separate mapping.

## Required migration shape (extending the enum)

For ANY change to the type set, write a new migration. Do NOT edit the historical `create_kyc_documents_table.php`.

```php
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(
            "ALTER TABLE kyc_documents MODIFY COLUMN type ENUM("
            ."'pan','aadhaar','aadhaar_back','cheque','address_proof_front','address_proof_back','photo'"
            .") NOT NULL"
        );
    }

    public function down(): void
    {
        // Always provide a reverse — must list the PREVIOUS valid set.
    }
};
```

## Self-check before declaring "done"

Grep these once after the change — every hit should be intentional:

```bash
grep -rn "'aadhaar_back'\|aadhaar_back" app/app/Modules/ app/resources/views/
```

You should see hits in: the migration, the wizard controller (KYC_DOC_FIELDS + KYC_DOC_REQUIRED_FIELDS + attribute label), the self-service controller (SELF_SERVICE_TYPES), step7-documents.blade.php ($fields), kyc-documents.blade.php ($labels), kyc-resubmit.blade.php (input), admin/kyc/show.blade.php (option). Anything less means a surface was missed.

## Hard rules (compliance, do not break)

- Raw Aadhaar number is **never** stored. Only AUA/KUA reference + last-4. Adding an image scan of the back is image storage — same posture as the existing front image. Do not confuse this with storing the number.
- PAN is hash + last-4 only.
- Every admin-driven mutation of a `kyc_documents` row writes an `audit_log` entry with before/after. New endpoints in this flow must follow that pattern.
- Document images live on the `kyc` private disk — never on the public disk.

## Re-upload flow (admin-flagged single doc)

- Admin flags via `kyc_documents.flagged_at` + `flag_reason` (columns added 2026-05-28).
- Distributor receives a `KycDocumentFlaggedNotification` (mail + database channels).
- Re-upload page uses a **signed URL** scoped to one document — only that one type's input is shown.

## Tests

- Pest feature tests at `tests/Modules/Kyc/`. Run scoped: `docker exec arovolife-app php artisan test --compact tests/Modules/Kyc/`.
- Always add a regression test when you add a new doc type — assert it appears in all six surfaces.
