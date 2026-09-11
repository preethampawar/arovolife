# Risk Acceptances

> Formal, signed records of security/compliance risks the business has chosen to
> **accept** rather than remediate. A risk is not "accepted" until the named
> owners have signed below. Until then it remains **Open** in
> `docs/compliance/risk-register.md`. Referenced by the post-development
> security audit (`docs/security/audit-checklist.md`).

---

## RA-01 — Full PAN + full Aadhaar held encrypted at rest, pre-KYC (R-31)

**Status:** ☐ PROPOSED — awaiting signatures (do not treat as accepted until signed)
**Risk register:** R-31 (High) · **Audit finding:** T-6.1 / F3 · **Hard rule affected:** #8

### What the system does
During registration, the **full** PAN and **full** Aadhaar number are stored in
`distributors.pan_encrypted` / `aadhaar_encrypted` (migration
`2026_05_12_000001`). On KYC approval, `ApproveKycSubmission` **nulls both
columns**, leaving only `pan_last4` / `aadhaar_last4`. The full values exist at
rest only for the window between submission and admin approval.

*Amended 2026-09-11 (client decision, QA fix run Q10/Q22; R-31):* the uploaded
scans — PAN, Aadhaar front and back, cancelled cheque, address proof, photo —
are **kept after approval**. They are written as `PiiCrypter` ciphertext by
`KycDocumentVault`, served only through the audited admin route, and erased by
`kyc:purge-expired-documents` once the admin-owned setting
`kyc.document_retention_days` (default eight years, the published period) has
run. An Aadhaar image carries the Aadhaar number, so this acceptance now covers
the retained scans as well as the number columns.

### Why this is a risk
Hard rule #8 (CLAUDE.md; T&C §15; DPDP 2023) states raw Aadhaar is **never**
stored — only a UIDAI AUA/KUA reference + last-4. Holding the full Aadhaar
number at rest, even transiently and encrypted, is a deliberate deviation from
that rule.

### Business justification (to be confirmed by the PO)
The full values were requested by the Product Owner to support manual KYC
verification before an AUA/KUA integration is live. _(PO to confirm/expand.)_

### Controls in place
- Encrypted with `PiiEncrypted` cast → `PiiCrypter` (AES-256-CBC) on the
  **dedicated** `PII_ENCRYPTION_KEY` (ADR-0008), isolated from the rotated `APP_KEY`.
- Columns are `$hidden`; only masked last-4 accessors are ever exposed.
- Number columns nulled on KYC approval; scans retained encrypted (see amendment above) and deleted by the nightly retention sweep, each deletion audit-logged.
- All admin KYC actions are audit-logged with before/after.
- Document images stored on the private `kyc` disk as ciphertext on the PII key; served only through `admin.kyc.document`, which logs every view — no signed URLs (F106).

### Residual risk & conditions of acceptance
- A data breach during the pre-approval window would expose full PAN/Aadhaar of
  not-yet-approved applicants.
- **Required conditions** (proposed): (a) a bounded retention — auto-purge
  full values for applications not approved within **N days**; (b) `APP_KEY` /
  `PII_ENCRYPTION_KEY` held only in a secret store, never in the repo image;
  (c) migrate to **AUA/KUA reference + last-4 only** when that integration ships
  (target phase: ___), at which point this acceptance is retired.

### Decision
Choose one (the audit recommends re-architecture; the business chose to keep
with formal sign-off):

- ☐ **Accept** with the conditions above.
- ☐ **Re-architect** to store only the AUA/KUA reference + last-4 (retire this record).

### Sign-off
| Role | Name | Decision | Date | Signature |
|---|---|---|---|---|
| Product Owner | _________________ | Accept / Re-architect | __________ | __________ |
| Compliance Officer | _________________ | Accept / Re-architect | __________ | __________ |

> On signature: update R-31 in `docs/compliance/risk-register.md` to
> "Accepted (date)" with the conditions, and tick item 10 in
> `docs/security/audit-checklist.md`.
