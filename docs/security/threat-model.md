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

---

## [2026-08-17] Grievance intake and evidence attachments

Scope: `POST /grievance` (unauthenticated), `GET /grievance/track`,
`POST /grievance/reply`, `/my/grievances/*`, `/admin/grievances/*`
Assets: complainant identity and contact details, complaint bodies naming
staff, uploaded evidence files, SLA state
Trust boundaries: anonymous browser → API → MySQL; API → private S3 bucket;
API → mail

### Spoofing
- Filing a complaint as somebody else, to get their ticket into the queue | Medium | Medium | Intake records the channel; the track/reply oracle requires ticket number **and** matching reporter email, so a guessed number alone reveals nothing | Backend | done
- Reading another complainant's ticket by guessing its number | Medium | High | `GRV-YYMMDD-XXXXX` is not sequential within a day; track requires the email; anonymous tickets are excluded from track entirely | Backend | done

### Tampering
- Editing a complaint after the SLA clock started, to hide a breach | Low | High | `TicketEvent` append-only history; audit log is hash-chained (`compliance:verify-audit-log`) | Backend | done
- Uploading a file whose declared type is not its contents | Medium | Medium | `ValidUploadedDocumentBytes` reads the magic bytes on all four upload paths (GRV-020) | Backend | done

### Repudiation
- "I never received an acknowledgement" | Medium | Medium | Acknowledgement is an event row with a timestamp, not only a mail | Backend | done
- Staff denying they saw a complaint naming them | Low | High | `TicketPolicy` forbids handling a grievance filed against you or by your own distributor account; every view and transition is evented | Compliance | done

### Information disclosure
- **Aadhaar or PAN pasted into a complaint body** | High | High | `NoRawGovernmentId` rejects before storage — Verhoeff-checked Aadhaar and PAN regex (GRV-017) | Backend | done
- Attachment served to the wrong reader | Medium | High | Stream route scoped `where('ticket_id')`; RBAC + audit; 15-minute signed URL | Backend | done
- Attachment filename leaking its subject ("aadhaar-card-scan.pdf") into the audit row | Low | Medium | Object key is server-generated; **the audit row still keeps `original_name`** — open, T-6.1 L-3 | Backend | pre-launch
- `admin-finance` reading an ethics complaint about themselves | Medium | High | `can:grievance.handle` excludes admin-finance (SOD-01) | Backend | done

### Denial of service
- Intake flood filling the queue and the bucket | Medium | Medium | 6/hour per IP on intake, 10/10min on track and reply; per-ticket count and size caps | Backend | done
- Large-file upload exhausting storage | Low | Medium | Size cap from `GrievanceSettingsService` | Backend | done

### Elevation of privilege
- Uploaded file executed by the web server | Low | Critical | Private S3 bucket, never web-served; CSP `object-src 'none'`. Extension still taken from the client (L-1) — harmless while the bucket is private | SRE | pre-launch
- **No AV scanning on any upload path** | Medium | High | Not mitigated. Live risk is a malicious file opened on a compliance officer's desktop — R-58 | SRE | pre-launch

---

## [2026-08-17] Analytics

Scope: `GET /admin/analytics`
Assets: a company-wide ranked list of ADN + full name + BV; funnel and
retention aggregates
Trust boundaries: staff browser → API → MySQL

### Information disclosure
- Any admin-family role seeing every distributor's trading ranked | Medium | Medium | Gated on `audit.read`; hidden from the nav without it (ANL-012) | Backend | done
- A funnel or retention figure read as a forecast | Medium | High | Nothing on the page projects or extrapolates; the copy says so (hard rule 3, DSR 5(1)(d)) | Compliance | done

### Denial of service
- Unbounded date window triggering repeated table scans | Medium | Medium | Window clamps at 730 days (ANL-013) | Backend | done

### Tampering
- Injection through the date parameters | Low | High | `^\d{4}-\d{2}-\d{2}$` then `Carbon::createFromFormat`; every raw fragment is literal SQL with bound parameters | Backend | done

---

## [2026-08-17] GST tax invoicing

Scope: `InvoiceGenerator`, `InvoiceNumberSequence`, `GET /shop/orders/{no}/invoice`
Assets: statutory invoice series, buyer GSTIN, taxable values feeding GSTR-1
Trust boundaries: browser → API → MySQL

### Tampering
- Two invoices sharing a number | Low | Critical | Per-financial-year row lock; the first-of-year race is closed with `insertOrIgnore` + re-read under lock (L-4) | Backend | done
- Invoice declaring a tax different from the one charged and remitted | Medium | Critical | `orders.gst_paise`, the `liability.gst_output` credit and the invoice are pinned equal by TAX-010; the §15(3)(a) position is a constant, not a setting, so it cannot be flipped from a form | Backend | done

### Repudiation
- "That invoice was never issued at that value" | Low | High | Invoice and lines are written in one transaction with the reserved number; the document is regenerated from stored values, never recomputed | Backend | done

### Information disclosure
- Reading another buyer's invoice | Medium | High | Scoped `whereHas('customer', user_id)`; no IDOR found in the T-6.1 pass | Backend | done

### Denial of service
- Invoice numbers consumed by orders that later cancel, leaving gaps | Low | Medium | Accepted: Rule 46(b) expects consecutive numbering and a gap needs explaining. Open — T-6.1 L-6 | Finance | pre-launch

---

## [2026-08-17] Redeem points

Scope: `RedeemPointsService`, `PurchaseOfferService`, checkout redemption
Assets: a spendable balance that reduces what a buyer pays
Trust boundaries: browser → API → MySQL

### Tampering
- Spending the same points twice from two concurrent checkouts | Medium | High | `lockForUpdate` over the balance; **verified safe only under MySQL's default REPEATABLE READ** — a switch to READ COMMITTED reopens it and no test pins the isolation level (T-6.1 L-5) | Backend | pre-launch
- Awarding the same monthly grant twice | Medium | Medium | `uniq_offer_grant`; the race now returns 0 instead of aborting the rest of the monthly run (M-7) | Backend | done
- Converting points to cash by buying and cancelling | High | Critical | Refund subtracts `redeem_points_paise` from the cash refund and returns the points in points, idempotently (OFR-016) | Backend | done

### Elevation of privilege
- Granting yourself points through the admin announce screen | Low | High | `can:compliance.discipline` on announce, with before/after in the audit row | Backend | done

---

## [2026-08-17] Franchise commission

Scope: `FranchiseCommissionService`, `/admin/commerce/franchises/*`
Assets: a 3% commission on delivered order value
Trust boundaries: staff browser → API → MySQL

### Tampering
- Paying commission twice on one order | Medium | High | `franchise_commission_result_orders` records every paid order; the settled-and-unpaid predicate excludes them (FRN-015) | Backend | done
- Commission computed on a base that includes GST | Medium | Medium | Base is `subtotal − gst − discount`; including GST paid 3.54% while the plan says 3% | Backend | done

### Elevation of privilege
- Approving your own franchise application | Low | High | Approval is `can:compliance.discipline`; the applicant is a distributor, the approver is staff | Backend | done

---

## [2026-08-17] §21 dormancy termination

Scope: `InactivityTerminationService`, `/admin/dormancy`
Assets: the distributorship itself
Trust boundaries: scheduler → API → MySQL

### Repudiation
- "I was never given notice" | Medium | High | Two-stage notice → terminate; `inactivity_notice_at` and `_expires_at` are stored, and only an expired notice terminates | Compliance | done

### Denial of service
- A sweep terminating a live base on its first scheduled run | Medium | Critical | Master switch ships **off**; the admin screen shows exactly who would be touched before anyone enables it | Compliance | done

### Tampering
- A sale during the notice period not clearing it | Medium | High | The sweep's first pass is the revival check, before it terminates anything | Backend | done

---

## [2026-08-17] Consent withdrawal

Scope: `GET|POST /profile/withdraw-consent`
Assets: the lawful basis for processing, and the ADN itself
Trust boundaries: distributor browser → API → MySQL

### Spoofing
- Withdrawing somebody else's consent | Low | Critical | Resolved from `$request->user()->distributor`; no id parameter exists (CWD-07) | Backend | done

### Denial of service
- Withdrawal triggered by accident or by a click-jacked page | Medium | Critical | Typed `WITHDRAW` confirmation, not a checkbox; `X-Frame-Options: DENY` and CSP `frame-ancestors 'none'`; 5/hour throttle | Backend | done

### Repudiation
- "I never asked for my account to be closed" | Low | High | Audited with the distributor as actor and their own stated reason (CWD-05); acceptance rows retained, not deleted | Compliance | done
