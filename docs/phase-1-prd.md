# Phase 1 — Product Requirements Document (Markdown copy)

This is the same PRD that the Product Owner approved (the `.docx`
version is in the project's parent folder as
`Arovolife_Phase_1_PRD.docx`). This Markdown copy is what Claude Code
reads.

---

## 1. Executive summary

Phase 1 is the foundation of the Arovolife platform. It delivers the
identity, KYC, consent, binary-placement and genealogy spine that every
later phase depends on. At the end of Phase 1 a prospect can be invited,
complete mandatory orientation, accept the Direct Seller Agreement,
complete PAN/Aadhaar/bank KYC, be placed in the binary tree under a
chosen downline node, and log in as an active Direct Seller.
Administrators can search the tree, freeze/terminate distributors,
change the Placement Strategy setting (with audit log), and export the
Register of Direct Sellers — all in full compliance with the Consumer
Protection (Direct Selling) Rules, 2021.

## 2. Scope

### In scope
- Multi-role identity with MFA (customer / distributor / admin sub-roles).
- Free registration wizard (T&C 10-step flow).
- KYC: PAN auto-verification, Aadhaar OTP via approved partner, bank penny-drop, address proof + AV scan.
- Versioned legal consent (Agreement, Code of Ethics, Plan, Privacy Notice).
- Mandatory orientation video with watch-gate + micro-quiz.
- Binary-tree placement engine with `sponsor_id` + optional `placement_id` + Placement Strategy admin setting.
- Genealogy views (binary + horizontal sponsor).
- 30-day cooling-off with one-click cancellation.
- Line-change workflow (≤ 5 working days).
- Couple distributorship.
- Admin console with full audit log.
- Docker local stack; CI/CD to staging on Cloudways; Lightsail backups.
- Observability: traces, structured logs, metrics.

### Out of scope (deliberate)
- No product catalogue, cart, orders, invoices, GST.
- No wallet, commission, payouts, withdrawals.
- No rank engine, auto pool, rewards.
- No public income-projection visuals.
- No "invite-and-earn" without a product sale.
- No e-commerce listings, no offline retail.
- No forced purchase during registration.

## 3. User stories

(See `backlog/phase-1-backlog.md` for breakdown.)

US-1.01 to US-1.16 inclusive — including:

- US-1.15: Prospect/Sponsor optionally specifies `placement_id`; the leg is decided by the company-wide Placement Strategy (Default Left / Default Right / Custom).
- US-1.16: Admin configures the global Placement Strategy from the admin console with full audit log.

## 4. Eight hard rules

(Reproduced in `CLAUDE.md` and enforced by the `compliance-officer` subagent.)

## 5. Architecture

- Modular monolith (Laravel 13).
- Closure-table for binary tree (ADR-0001).
- Placement Strategy admin setting (ADR-0002).
- Service Layer + domain events.
- Queue (database driver in Phase 1).

## 6. Data model

See `docs/architecture/data-model.md`.

## 7. APIs

See `backlog/phase-1-backlog.md` and the bootstrapped `routes/api.php`.

## 8. Compliance

See `docs/compliance/dsr-2021-mapping.md` and `docs/compliance/risk-register.md`.

## 9. Security

See `docs/security/threat-model.md` and `docs/security/audit-checklist.md`.

## 10. Test strategy

See `docs/testing/test-strategy.md`.

## 11. Exit criteria

1. All 16 user stories pass UAT with PO sign-off.
2. Compliance Officer signs C-01 … C-09 in writing.
3. Post-development security audit closes all Critical/High findings.
4. p95 placement latency ≤ 250 ms on 1M-row tree.
5. Runbooks `cooling-off-cancellation.md` and `placement-strategy-change.md` exist and are exercised in DR drill.
6. DR drill: previous-night DB restore into staging reaches green.
7. Feature-flag killswitch verified in staging.
8. WCAG 2.1 AA on the registration wizard.
9. p95 page load < 1.5 s on staging with seeded 100k distributors.

## 12. PO decisions captured (D-01 … D-11)

These came back from the PRD sign-off. Confirmed values land in `.env.example`:

- D-01 — Default Placement Strategy at launch: `default_left`.
- D-02 — `allow_sponsor_side_override`: true.
- D-03 — Public marketing site: not in scope; landing page only.
- D-04 — MFA optional for Customers, mandatory for Distributors and Admins.
- D-05 — SMS provider: MSG91 (subject to vendor due-diligence).
- D-06 — PAN gateway: shortlist Karza / IDfy / Signzy.
- D-07 — Aadhaar OTP: only via UIDAI-approved AUA/KUA partner; never store raw Aadhaar.
- D-08 — KYC retention: 8 years post-termination.
- D-09 — Orientation: single 8–10 min video + 3-question micro-quiz.
- D-10 — Couple registration: supported in Phase 1 (primary + secondary); withdraw-cascade in Phase 7.
- D-11 — Team composition assumptions: confirmed as in §17 of PRD.
