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

## Audit run — 2026-06-17 (security-auditor subagent, T-6.1)

| # | Item | Verdict | Notes |
|---|---|---|---|
| 1 | Threat model | PASS | New endpoints reviewed; threat-model.md present. |
| 2 | Authentication | PASS | Login lockout 5/15-min per email+IP, generic errors, session regenerate/migrate, reset throttled + HIBP. |
| 3 | Authorization | PASS | Admin behind `auth`+`role:admin`; KYC/doc endpoints enforce `distributor_id` ownership; confirmation pages IDOR-safe. |
| 4 | Input/output | PASS w/ Medium | No SQLi/XSS/mass-assignment. **F2**: CSV formula-injection in exports — **FIXED** (`Shared\Support\Csv::safe`). |
| 5 | Secrets & crypto | PASS | No committed secrets; AES-256-CBC at rest. Run gitleaks in CI before launch. |
| 6 | Dependencies | **PASS** (was FAIL) | **F1 FIXED:** laravel/framework → 13.16.1 (CVE-2026-48019 patched); npm vite fixed + `shell-quote` pinned to 1.8.4 via `overrides`. `composer audit` + `npm audit` both clean. |
| 7 | Logging & privacy | PASS | PiiScrubber redacts PAN/Aadhaar/OTP/etc.; audit-log with before/after hashes. |
| 8 | Compliance cross-check | PASS | Money/KYC/consent surfaces flagged; compliance-officer reviewed separately. |
| 9 | File uploads | PASS w/ Low | Magic-byte + MIME + size cap + GD re-encode + private `kyc` disk + signed URLs; no traversal. Low: no AV scan (backlog). |
| 10 | PII at rest | **OPEN** | **F3**: full PAN + full Aadhaar held encrypted pre-KYC (PO-requested), then nulled to last-4 on approval. Deviates from hard rule #8 and is **not yet logged** in the risk register — see **R-31**. Needs Compliance Officer + PO sign-off. |

**Recently-added surfaces audited clean:** shared-cart guest checkout (non-enumerable code, scoped/cleared session pass, ADN+name-only PII), Admin Help & Reference (allow-listed slug, HTML stripped — no traversal/XSS), Find My ID (dual-match, rate-limited, no PAN oracle), OTP service (SHA-256, hash_equals, 5-attempt cap), PII crypter.

**Verdict (updated 2026-06-17 after remediation): no open Critical/High.** F1 dependency CVEs **fixed** (commit `56a6150`); F2 CSV injection **fixed** (`a98c220`); F4 already handled. **Remaining before phase exit:** F3 — the hard-rule-#8 deviation (full PAN/Aadhaar pre-KYC, R-31) needs a formal Compliance Officer + PO sign-off (record in `docs/security/risk-acceptances.md`). F5 (no AV scan) is Low/backlog. Once F3 is signed: **READY FOR UAT.**

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
