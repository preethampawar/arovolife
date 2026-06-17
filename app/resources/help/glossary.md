# Glossary — Arovolife Terms

> The vocabulary used across the platform, the admin panel, and our policies.
> When a customer or distributor asks "what does X mean?", this is the answer.

---

## Identity & genealogy

| Term | Meaning |
|---|---|
| **ADN** | **Arovolife Distributor Number** — the permanent unique ID issued to a distributor at the end of registration. One person ever gets one ADN. |
| **PAN** | The government tax ID used to verify a distributor. **One PAN = one ADN** — a person cannot hold two distributorships. PAN is stored only as a secure hash + last 4 digits, never in full. |
| **Distributor / Direct Seller** | A registered member who may sell Arovolife products directly to end-consumers. |
| **End-consumer** | The person who actually uses the product. Direct sellers may only sell to end-consumers — never through shops or online marketplaces. |
| **Sponsor** | The distributor who introduced a new joiner. Recorded in the **sponsorship** tree. Sponsor-tied earnings apply regardless of where the joiner sits in the Genos. |
| **Genos** | The **binary placement tree** — the two-sided (left group / right group) genealogy where every distributor occupies one position. Shown to users as "Genos" / "My Genos". *(Internally the code calls it "binary".)* |
| **Placement** | A distributor's position in the Genos — not necessarily directly under their sponsor. Each distributor has one placement parent and a side (left/right group). |
| **Placement Strategy** | A company-wide admin setting that decides the starting side when a placement is chosen: `default_left`, `default_right`, or `custom`. |
| **Group / Leg** | One side of a distributor's Genos — the left group or the right group. |
| **Line change** | A request to move a distributor's placement. Allowed only within a short window (≤ 5 days) after joining and subject to admin approval. |
| **Couple registration** | A married couple registered under a **single ADN** with a primary and a secondary holder. |
| **PYP** | **Prove Your Position** — a rank-maintenance rule used by the compensation plan in later phases. |

---

## Products & sales

| Term | Meaning |
|---|---|
| **BV** | **Business Volume** — points attached to each product, used by the compensation plan. **BV is a factual point value, not money and never an earnings figure.** |
| **SKU / Variant** | A specific sellable version of a product (e.g. a particular size). |
| **DS Price** | **Direct Seller Price** — the price a distributor pays. Refunds are calculated from this. |
| **Saleable goods** | For buyback/returns: unopened, ≤ 30% consumed, unexpired, non-seasonal, and not under a special promotion. |
| **Buyback** | The company repurchasing goods from a distributor under defined conditions (see the Cooling-off & Cancellation reference for the refund matrix). |

---

## Compliance & accounts

| Term | Meaning |
|---|---|
| **Cooling-off** | The statutory **30-day window** from the Effective Date during which a distributor may cancel with a **full refund**, one click, no questions. |
| **Effective Date** | The date a distributor's registration is finalised — the start of the cooling-off clock. |
| **KYC** | **Know Your Customer** — the identity verification step (PAN, Aadhaar, address proof, etc.) an applicant completes before activation. |
| **AUA / KUA** | The UIDAI-approved partner used to verify Aadhaar. We store only the **reference** it returns plus the last 4 digits — **never the raw Aadhaar number**. |
| **Orientation** | The mandatory onboarding (watch ≥ 95% + micro-quiz) every applicant must complete before registration can be finalised. |
| **Consent** | Versioned, timestamped agreement to the Direct Seller Agreement, Code of Ethics, Compensation Plan, and Privacy Policy. Recorded with the document version + a hash. |
| **Grievance** | A complaint routed into a single tracker with an ID and an SLA clock; both the customer and admin see its status. |

---

## Documents & regulators

| Term | Meaning |
|---|---|
| **T&C / DSA** | The **Direct Seller Agreement & Code of Ethics** — our binding contract with each distributor. |
| **DSR 2021** | **Consumer Protection (Direct Selling) Rules, 2021** — the primary statute we operate under. |
| **DPDP Act 2023** | **Digital Personal Data Protection Act** — governs how we collect, store, and process personal data. |
| **PII** | **Personally Identifiable Information** — PAN, Aadhaar, bank details, contact details. Always encrypted at rest. |

---

## Two things people commonly mix up

- **ADN vs PAN** — PAN is the government ID we verify against; ADN is the membership number we issue. One PAN maps to exactly one ADN.
- **BV vs earnings** — BV is points on a product sale, used by the plan. It is **never** a rupee figure or a promise of income. Do not present BV as money to anyone.

> See also: the **Status Reference** for every account/order status, and the
> **Cooling-off & Cancellation** reference for refund rules.
