# Post-Development Security Audit — 10-Point Checklist

Executed after development, before UAT, by the `security-auditor`
subagent. Blocking: no phase exits with any Critical or High finding
open.

## Checklist

| # | Item | Phase 1 status | Notes |
|---|---|---|---|
| 1 | Threat model — STRIDE walkthrough of every new endpoint | Draft (`docs/security/threat-model.md`) | |
| 2 | Authentication — login brute-force, MFA bypass, password-reset abuse | Not started | |
| 3 | Authorization — vertical (role) + horizontal (IDOR) on every new endpoint | Not started | |
| 4 | Input/output — SQL-i, XSS, SSRF, file uploads, open-redirect, HPP, mass-assignment | Not started | |
| 5 | Secrets & crypto — gitleaks, AES-256 at rest, TLS 1.2+, key rotation runbook | Not started | |
| 6 | Dependencies — `composer audit`, `npm audit`, SBOM, no Critical/High CVEs | Not started | |
| 7 | Logging & privacy — PII scrubbed, audit-log tamper-evident, retention configured | Not started | |
| 8 | Compliance cross-check — hand-off to `compliance-officer` for money/KYC/consent features | Not started | |
| 9 | File uploads — magic-byte + MIME + AV + size cap + path-traversal-safe | Not started | |
| 10 | PII at rest — column encryption verified; key custody for PAN/Aadhaar | Not started | |

## How to run

1. `/threat-model` — refresh entries for the phase's new features.
2. Manual pen-test pass on the running staging environment.
3. `composer audit && npm audit` — capture output as artefact.
4. Run `gitleaks detect --redact -v` over the repo.
5. Run the `security-auditor` subagent with the staged diff as context.
6. For each item, set status to one of:
   - **PASS** (default target)
   - **PASS WITH ACCEPTED RISK** (link to risk-acceptance record)
   - **FAIL** (list blocking findings with file:line)

## External pen-test gate

Phases requiring external CERT-In empanelled pen-test (per Master Plan):
3 (wallet + payouts), 6 (auto pool), 10 (production hardening).
Phase 1 does NOT require external pen-test but must pass internal.
