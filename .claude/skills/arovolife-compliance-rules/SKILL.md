---
name: arovolife-compliance-rules
description: Statutory obligations of the Arovolife platform under the Consumer Protection (Direct Selling) Rules, 2021; the Direct Seller Agreement and Code of Ethics; and adjacent laws (DPDP 2023, IT Act 2000, Income Tax Act). Use whenever writing or reviewing code that touches registration, KYC, consent, commissions, products, refunds, grievances, or anything user-facing.
---

# Arovolife Compliance Rules — reference

## The eight hard rules (repeat of CLAUDE.md; authoritative)

1. Joining is free of cost.
2. Commissions only on product sales.
3. No income projections anywhere in the public site or the registration UI.
4. Mandatory orientation before registration finalisation.
5. 30-day cooling-off, one-click cancellation, full refund.
6. One PAN = one ADN.
7. No e-commerce listings, no offline retail.
8. PAN encrypted at rest; raw Aadhaar never stored (reference + last-4 only).

## Key statutory sources

- **DSR 2021** — Consumer Protection (Direct Selling) Rules, 2021 (FG.S.R. 889(E) dated 28 Dec 2021).
- **T&C** — the Arovolife Direct Seller Agreement and Code of Ethics.
- **DPDP Act 2023** — Digital Personal Data Protection Act.
- **IT Act 2000** §10A — electronic records as binding contracts.
- **Indian Contract Act 1872** — minors, unsound mind, undue influence.
- **CGST Rules 46** — GST invoicing (later phases).

## Registration (Phase 1)

| Obligation | Source | Where it lands in code |
|---|---|---|
| Free joining | T&C §4; DSR Rule 5(1)(a) | Registration wizard rejects any SKU in cart; unit test asserts |
| Orientation (10-step onboarding) | T&C §3.I | `OrientationViewWatchedEvent` + activation gate |
| Versioned consent (Agreement, Ethics, Plan, Privacy) | T&C §3.I, DPDP §6 | `consents` table rows with `document_version + hash_of_doc + ip + ua` |
| Electronic record is binding | IT Act §10A; T&C §3.I Step 9 | Generate PDF with deterministic hash; email to user; store hash |
| 18+ / 21+ in Maharashtra | T&C §1.1; Code II.a | `state`-aware age validator |
| Single ID per PAN | T&C §1.4 | Unique index on PAN hash; PAN verification step |
| Couple rule | T&C §7 | Primary/secondary columns on `distributors` |
| ADN issued | T&C §3.I Step 10 | `adn` unique column, sequence generator |
| Cooling-off timer | T&C §4; Code V | `cooling_off_end_at = effective_date + 30 days` |
| Line-change ≤ 5 days | T&C §10 | `LineChangeRequest` service + window enforcement |

## Prohibited behaviours (must refuse in code)

| Prohibition | Source | Expected code response |
|---|---|---|
| Charging any fee at joining | T&C §4, Code III.V.a | Registration endpoint rejects; finance role cannot add a "joining fee" SKU |
| Showing income projections | DSR Rule 5(1)(d); Code IV.XII.a | No calculator; dashboards show historical facts only |
| Selling on Amazon / Flipkart / any marketplace | T&C §9 | Periodic crawler detects DS listings; takedown workflow |
| Selling in offline retail stores | T&C §9 | Ship-to address blocklist; terms acceptance |
| Recruiting as an income source | DSR Rule 5(1)(c) | Every commission row has `product_sale_id NOT NULL` |
| Duplicate couple IDs | T&C §7.VII | 60-day dedup scan |
| Minor as distributor | T&C §1.1; Code II.a | DOB gate + state-wise threshold |
| Operating through multiple IDs | T&C §1.4 | PAN unique; monthly duplicate report |

## Data protection (DPDP 2023 + T&C §15)

- Collect only what the stated purpose requires (data minimisation).
- Consent is purpose-limited and revocable.
- Publish Data Fiduciary contact on the site (`/privacy#contact`).
- Data retention: 8 years for DSR-required records; distributor may
  request erasure for data beyond statutory retention.
- No cross-border transfer of PII without explicit consent and legal basis.
- **Raw Aadhaar is never stored.** Integrate only via UIDAI-approved
  AUA/KUA partner; store the returned reference ID and the last 4 digits.
- PAN stored as hash + last 4. Name-matched server-side.

## Buy-back / cooling-off refund matrix (T&C §8)

| Case | Condition | Period | Invoice | Refund amount |
|---|---|---|---|---|
| Cooling-off | Saleable | 30 days | Yes | Direct Seller Price (full) |
| General buyback during routine business | Saleable | n/a | No | DS Price less GST |
| Termination buyback | Saleable | n/a | No | DS Price less GST |
| Damaged goods | Saleable | 10 days | Yes | DS Price |
| Damaged goods | Non-saleable | 10 days | No | DS Price less GST |
| Dissatisfaction | Saleable | 30 days | Yes | DS Price |
| Dissatisfaction | Non-saleable | 30 days | No | DS Price less GST |

"Saleable" means unopened, ≤ 30% consumed, unexpired, non-seasonal, not
under special promotion (T&C §j).

## Grievance redressal (T&C §11)

- Complaint captured via site/phone/email/post/walk-in — all routed to one tracker.
- Each complaint has an ID and an SLA clock.
- Customer and admin both see status transitions.
- Monthly compliance report summarises SLA performance.

## Auto-termination (T&C §21)

- No sale for continuous 12 months from agreement or last sale.
- 7-day written notice.
- ADN frozen → terminated.
- Re-registration: Sales Master 1-year wait; Diamond Master+ 2-year wait.

## Income-tax / GST (hooks only in Phase 1)

- TDS @ 5% on commissions above applicable threshold.
- GST threshold monitoring per distributor on monthly purchases.
- Admin charge: 3% or cap ₹30,000 on GSB/MB/RB payouts.
- Franchise bonus, awards and rewards are exempt from admin charge.
- Any payout event must emit a signed ledger entry with `tds_deducted`
  and `admin_charge_deducted` — even if in Phase 1 we only emit
  placeholder zero-value entries to rehearse the plumbing.

## Grep checklists (suggested)

```bash
# suspicious copy
grep -RIn -E 'guaranteed|assured income|earn .*per (day|week|month)' resources/views/

# stored secrets
grep -RIn -E '(AADHAAR|AADHAR)[A-Z0-9_]*\s*=\s*"?[0-9]{12}' app/

# non-PII-safe logs
grep -RIn 'Log::' app/ | grep -iE 'pan|aadhaar|otp|password'

# mass-assignment risk
grep -RIn 'protected \$guarded\s*=\s*\[\]' app/
```
