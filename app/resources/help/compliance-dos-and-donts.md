# Compliance Do's & Don'ts

> Arovolife is a direct-selling company regulated under the **Consumer
> Protection (Direct Selling) Rules, 2021 (DSR 2021)**, the **Direct Seller
> Agreement & Code of Ethics**, and the **DPDP Act 2023**. A mistake here isn't
> just a bug — it can be a regulatory violation. When in doubt, **stop and ask
> the Compliance Officer.**

---

## The eight hard rules (never negotiable)

These are the rules the entire platform is built to protect. Everyone with
admin access must know them.

| # | Rule | What it means in practice |
|---|---|---|
| 1 | **Joining is free** | Registration never adds a product to a cart or charges a fee. Never create a "joining fee" or "registration kit" SKU. |
| 2 | **Commissions only on product sales** | No bonus, payout, or reward may exist without a real product sale behind it. Recruiting is never an income source. |
| 3 | **No income projections** | Never show or imply future earnings — no "earn ₹X/month", no calculators, no targets. Only **historical facts**, and only to the distributor about their own data. |
| 4 | **Mandatory orientation** | An applicant must complete orientation (watch ≥ 95% + quiz) before registration can be finalised. |
| 5 | **30-day cooling-off** | A distributor may cancel within 30 days with a one-click, full refund. This is sacred. (See the Cooling-off reference.) |
| 6 | **One PAN = one ADN** | One person, one distributorship. Couples register under a single ADN (primary + secondary). |
| 7 | **No e-commerce listings, no offline retail** | Direct sellers sell **directly to end-consumers** only — never on Amazon/Flipkart/any marketplace, never through retail shops. |
| 8 | **PII encrypted at rest** | PAN is stored as a hash + last-4. **Raw Aadhaar is never stored** — only the AUA/KUA reference + last-4. |

---

## Do

- **Do** keep all member-facing language factual and historical. A distributor may see their own past activity — nothing predictive.
- **Do** honour every cooling-off cancellation immediately and in full, even on day 30.
- **Do** treat PAN, Aadhaar, bank and contact details as confidential. Access them only when a task requires it.
- **Do** record a reason on every account action — it goes into the audit log.
- **Do** route every complaint into the grievance tracker with an ID and SLA clock.
- **Do** escalate anything you're unsure about to the Compliance Officer **before** acting.

## Don't

- **Don't** promise, project, or imply income to anyone — verbally, in writing, or in any screen or document.
- **Don't** present **BV** as money. BV is points on a product sale, never a rupee figure or an earnings promise.
- **Don't** charge anything at the point of joining, or describe any starter purchase as a condition of joining.
- **Don't** treat recruitment as earning — there is no payout without a product sale behind it.
- **Don't** approve a second distributorship for a PAN that already has an ADN.
- **Don't** facilitate or condone selling on marketplaces or in retail shops.
- **Don't** read out, type, screenshot, log, or store a full Aadhaar or PAN number anywhere.

---

## Returns, buyback & refunds (T&C §8)

Refund amount depends on the case and whether goods are **saleable** (unopened,
≤ 30% consumed, unexpired, non-seasonal, not on special promotion):

| Case | Window | Eligible | Refund | GST credit note |
|---|---|---|---|---|
| **Cooling-off** (saleable) | 30 days | ✅ | Full total paid (incl. shipping + GST) | Yes |
| **Cooling-off** (non-saleable) | 30 days | ❌ | — | — |
| Damage (saleable) | 10 days | ✅ | DS Price incl. GST | Yes |
| Damage (non-saleable) | 10 days | ✅ | DS Price **less GST** | No |
| Dissatisfaction (saleable) | 30 days | ✅ | DS Price incl. GST | Yes |
| Dissatisfaction (non-saleable) | 30 days | ✅ | DS Price **less GST** | No |
| General buyback (saleable) | None | ✅ | DS Price **less GST** | No |
| General buyback (non-saleable) | None | ❌ | — | — |
| Termination buyback (saleable) | None | ✅ | DS Price **less GST** | No |
| Termination buyback (non-saleable) | None | ❌ | — | — |

### How refunds are processed (Phase 2)

1. **Customer opens return** via My Orders → Return this order (storefront).
2. **Cooling-off** is processed **immediately, one-click** (non-discretionary — hard rule #5). No admin gate.
3. **All other reasons**: admin receives a refund review task, records the physical inspection (saleable/non-saleable/damaged), and approves or rejects.
4. On approval: the platform posts a double-entry ledger reversal and reverses BV. Order status → `refund_approved`.
5. Customer receives the refund within **7 working days** to the original payment method. (Phase-2 stub — gateway settlement wires in Phase 3.)

**Admin permissions:**
- Viewing return requests: any admin role.
- Recording inspection + approving/rejecting: `finance.record` (admin-finance) only. (R-17.)

**Do's and Don'ts:**
- **Do** honour every in-window cooling-off cancellation immediately and unconditionally.
- **Do** verify physical condition before approving a damage/dissatisfaction/buyback return.
- **Don't** use an inspection requirement to delay or block a cooling-off refund — inspection is post-facto only for cooling-off.
- **Don't** approve a refund for non-eligible cases (e.g. cooling-off on non-saleable goods).
- **Don't** tell a customer "refund complete" until Phase 3 confirms the gateway settlement. Use the phrase *"Refund initiated — credited within 7 working days."*

---

## Data protection (DPDP 2023)

- Collect only what the stated purpose needs (data minimisation).
- Consent is purpose-limited and can be withdrawn.
- **DSR-required records** are retained for **8 years**; data beyond that statutory need is minimised, and a distributor may request erasure of it.
- No PII leaves the country without explicit consent and a legal basis.
- Raw Aadhaar is **never** stored — only the AUA/KUA reference + last-4.

---

## If a request would break a rule

Stop. Don't do it. Tell the requester which rule it conflicts with, offer the
compliant alternative, and escalate to the Compliance Officer. "The customer
asked for it" or "a partner told me to" is **not** a valid reason to override a
hard rule.

> Statutory sources: DSR 2021 (G.S.R. 889(E), 28 Dec 2021); Direct Seller
> Agreement & Code of Ethics; DPDP Act 2023. This page is an operational
> summary, not legal advice — the agreements and statute govern.
