# Threat Model — Phase 1

Living document. Appended to — never rewritten silently. Use
`/threat-model <feature>` to add entries.

## Entry template

```
## [YYYY-MM-DD] <feature / endpoint>
Scope: <short>
Assets: <PII / money / identity / settings>
Trust boundaries crossed: <client→API / API→DB / API→external gateway>
```

Then STRIDE:

```
### Spoofing
- <threat> | Likelihood | Impact | Mitigation | Owner | Due
```

(Repeat for Tampering, Repudiation, Information disclosure, Denial of
service, Elevation of privilege.)

---

## [2026-04-19] Registration endpoint family (initial model)

Scope: `/api/v1/registration/*`
Assets: PAN, Aadhaar reference, bank account, DOB, address — all PII
Trust boundaries: Browser → API → MySQL; API → PAN gateway; API →
Aadhaar AUA/KUA partner; API → SMS vendor

### Spoofing
- Forged sponsor invite link | Medium | Medium | HMAC-signed URLs with short TTL; session binds to sponsor only after verification | Backend | pre-UAT
- Bot-generated registrations | High | Low | reCAPTCHA Enterprise / hCaptcha; rate limits | Backend | pre-UAT

### Tampering
- Client-supplied "side" overriding Placement Strategy | Medium | Medium | Server-authoritative resolution; ignore client side unless strategy = `custom` | Backend | now
- Client-supplied `placement_id` outside sponsor's downline | High | High | Server descendant validation; reject + audit | Backend | now
- PAN name-match bypass | Low | High | Name-match threshold ≥ 90% fuzzy; gateway is the authority | Backend | now

### Repudiation
- Dispute over consent acceptance | Medium | High | Versioned `consents` with hash + ip + ua; PDF emailed | Backend | now

### Information disclosure
- PAN leakage via logs | Medium | Critical | PII scrubber middleware; logs reviewed on release | SRE | before-release
- Aadhaar leakage if raw 12-digit ever accepted | Low | Critical | Pre-commit hook blocks; server rejects raw Aadhaar payloads | Backend | now
- Error messages revealing whether PAN exists | Medium | Medium | Constant-time responses for PAN lookup | Backend | before-release

### Denial of service
- KYC gateway flood of PAN lookups | Medium | Medium | Per-IP rate limit; circuit breaker; exponential back-off | Backend | before-release
- Orientation video hotlink abuse | Low | Low | Signed playback URLs | SRE | before-release

### Elevation of privilege
- IDOR across `/distributor/{id}/*` | High | High | Policies; authorise by user → distributor linkage; IDOR fuzz tests | Backend | now
- Registration → Admin pivot via role-injection in form | Low | Critical | Roles never accepted from client payload; role-assignment server-controlled | Backend | now
