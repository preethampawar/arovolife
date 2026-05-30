# Arovolife Roadmap — phase-wise feature list

> Synthesised from `CLAUDE.md`, `docs/phase-1-prd.md`, the ADRs under
> `docs/architecture/`, the runbooks under `docs/runbooks/`, and the
> project memory notes (`phase_1_remaining_items`, `phase_1_deferrals`,
> `phase_2_backlog`, `launch_blocker_named_officers`,
> `nominee_succession_copy_blocked`). This document is the single
> place that says **what belongs to which phase** — when CLAUDE.md or
> an ADR contradicts this, update both.

> Phases 7–11 are sketched in passing in the docs but not formally
> specced. They're grouped here for visibility, not commitment.

---

## Phase 1 — Foundation (active)

**Modules:** Identity · Genealogy · KYC · Consent · Orientation ·
Compliance · Admin.

### Shipped

**Registration & identity**

- 10-step registration wizard (free joining, mandatory orientation video
  + micro-quiz, electronic consent with versioned hash; DSR Rule 5(1)(a))
- One PAN = one ADN
- Couple registration (US-1.13) — two distributor rows, both with full
  KYC, mutually linked; secondary doesn't take a binary tree slot
- Aadhaar via UIDAI-approved AUA/KUA partner — reference + last-4 only,
  raw never stored (DPDP Act 2023; hard rule 8)
- PAN as hash + last-4 (encrypted blob purged after KYC sign-off)
- Password policy: zxcvbn entropy + HIBP NotPwned, 12-char min, 5-attempt
  per (email, IP) throttle, stale-throttle clearing on admin reset
- Login by email **or** 9-digit ADN; couple-ADN reveals a primary/spouse
  selector

**Genealogy**

- Closure-table binary tree (ADR-0001)
- Single-level placement on the chosen leg (ADR-0003) — no spine walk
- Sponsorship vs binary placement separation
- Line-change request (≤ 5 working days, single use, no-downline guard)
- Tree view (dynamic default depth, depth-scaled padding, zoom-to-cursor,
  status-dot hover popover, search by ADN/name/email/phone, minimap,
  fullscreen)

**KYC**

- Wizard upload (PAN, Aadhaar, bank, address proof, photo)
- Admin review (approve / reject the whole submission / terminate)
- **Per-document flag-for-reupload** — admin flags a single doc,
  applicant gets email + in-app notification with a 14-day signed-URL
  link to re-upload only that document; flag clears automatically on
  re-upload; audit-logged at both ends

**Cooling-off & buyback (distributor)**

- 30-day cooling-off from `effective_date`, one-click cancellation,
  full refund
- D-20 / D-7 / D-1 email + SMS reminders
- Self-cancellation cascades to spouse for couples
- ADR-0005: distinct from the per-order cooling-off clock that lands in
  Phase 2

**Admin console**

- Dark-slate theme with explicit ADMIN pill (visually unmistakable vs.
  the bright blue distributor chrome, even mid-impersonation)
- Distributors list (#, ADN, name/contact, state, depth, effective date,
  cooling-off, status filters, search), distributor detail page,
  Direct Legs view
- Distributor create (paper onboarding) + edit (profile, identity,
  ID photo)
- **Direct password reset** modal (modal validation, audit-logged,
  pending reset tokens invalidated, stale rate-limit cleared)
- KYC review queue + per-doc flag UI
- Line-change review (requester + current/requested placement parent
  names + ADNs)
- Tree view (Compress All / Expand All / dynamic depth)
- Genealogy tree (admin can re-root at any distributor)
- Contact Inbox (unhandled badge)
- Orders index (Phase 2 wiring scaffolded)
- Content Pages CRUD
- **Compliance Documents** — admin upload/publish/delete, public listing
  at `/compliance-documents`, streamed download from private disk
- Settings (Placement Strategy + others)
- Feature flags (Laravel Pennant + `RegistrationKillswitch`,
  `HibpPasswordCheck`)
- Audit log
- Impersonation (admin → distributor), audit-logged, orange banner +
  one-click stop
- DSR Register CSV export

**Distributor dashboard & documents**

- "My Office" — ADN card, placement, cooling-off, messages, team stats,
  ID-card placeholders (Personal Sales Position, ranks, BV, withdrawal
  income — wired for Phase 4+)
- **Documents section** with three cards (Direct Seller Application,
  Membership Card, TDS Tax Statements)
- **Membership card** — front (logo, ID, name, join date, tagline) +
  back (instructions, registered office, helpline). Print-ready with
  page-1/page-2 break, wave + watermark survive Save-as-PDF
- **Direct Seller Application** page — tabular distributor details +
  full agreement verbatim + auto-filled declaration block
- **TDS (Tax Statements)** — empty-state placeholder until Phase 4+

**Public site & content**

- Hero slider (3 slides), Why arovolife pillars, How To Register,
  Our Products (6 categories incl. Agri Care), Compliance commitment,
  Customer Care + Compliance Documents in footers
- About-us: story, philosophy ("Customer first. Always.") with the
  Our-Products card treatment, six product categories, compliance &
  trust list, "fairest direct selling company" CTA, growth pathway,
  six Our Values cards with subtle pastel tints
- Shop categories pills (canonical slugs + product-derived)
- Public Compliance Documents page (empty-state graceful)
- `/p/terms` — Direct Seller Agreement & Terms of Service incl. **§17
  Nominee Succession (Reserved — not yet in force)**
- `/p/privacy` — Privacy Policy, named DPO + Grievance Officer
- `/p/grievance` — Grievance Redressal, G. Shankar (Grievance Officer
  + DPO + Compliance Committee chair), L. Rajender (Nodal Officer),
  helpline +91 88866 62949
- `/p/ethics` — Code of Ethics
- All four content pages use the `@arovolife.com` mailbox domain

**Compliance & security**

- Eight hard rules enforced in code (free join · sales-only commissions
  · no income projections · mandatory orientation · 30-day cooling-off ·
  one PAN = one ADN · no marketplace listings · PII encrypted at rest)
- PII scrubber middleware on logs (PAN / Aadhaar / OTP / password
  redacted before reaching the log channel)
- Audit log on every admin action, KYC change, settings change
- Versioned consent (Agreement + Ethics + Plan + Privacy) with
  `document_version + hash_of_doc + ip + ua`
- Idempotency keys on external calls (payment, SMS, email)
- DSR-2021 mapping documented in `docs/compliance/`
- Compliance officer agent + skills (`arovolife-compliance-rules`,
  `arovolife-ux-writing`) wired into the dev loop

**Tests & docs**

- PHPUnit / Pest suite — Couple Registration, Password Policy
  (incl. throttle + admin-reset stale-throttle regression), Tree View,
  Line Change, Contact Form, KYC, etc.
- Property tests for placement edge cases
- Runbooks: cooling-off cancellation, placement-strategy change,
  Cloudways deployment, fresh install & reset
- ADRs 0001 closure table, 0002 placement strategy, 0003 referral-link
  placement, 0003 commerce customer entity, 0004 double-entry ledger,
  0005 order cooling-off clock

### Open in Phase 1

| ID | Item |
|----|------|
| T-5.9 | `/phase-1-status` artisan command (PHP impl; the slash-command skeleton exists) |
| T-4.4 | Dry-run placement preview endpoint (pending PO decision per ADR-0003) |
| T-6.1 | Security auditor 10-point sign-off |
| T-6.2 | Compliance officer sign-off C-01…C-09 |
| T-6.3 | UAT with PO sign-off |
| T-5.6 | Pa11y / WCAG 2.1 AA scan + evidence |
| Launch gate | Real customer-care/helpline phone (currently +91 88866 62949 is configured but verify the mailbox is provisioned on the mail server) |
| Launch gate | Real mailbox provisioning for the `@arovolife.com` addresses (addresses are configured in copy) |

### Confirmed deferrals — NOT Phase 1 blockers

- **US-1.06 MFA challenge** → Phase 12 (login + password policy +
  5-attempt lockout are shipped; TOTP enrolment, recovery codes, and
  challenge step are deferred)
- **T-5.2 / T-5.3 Observability** (structured JSON logs + OpenTelemetry
  traces + Prometheus metrics) → Phase 12 §15 (production hardening)

---

## Phase 2 — Commerce

**Module:** Commerce.

- E-commerce-grade storefront — catalog, cart, checkout, payment
  integration, GST invoice on every order
- **Per-order 30-day cooling-off / return window** — distinct clock
  from the distributor cooling-off (ADR-0005). Runs from `delivered_at`;
  cancellation refunds per the buyback matrix
- Buyback / refund matrix from T&C §8 (saleable / non-saleable /
  damaged / dissatisfied)
- Customer entity model (ADR-0003 — separation from distributor)
- **Line-change commerce block** — reject line-change for distributors
  with any commerce activity (orders, invoices, BV, payouts)
- Risk-register closures:
  - R-16 — placement TOCTOU full serialisation under the target-parent
    advisory lock during approval
  - R-17 — dedicated `admin-operations` role (Phase 1 ships with a
    single `admin` role)
- Catalog-side enforcement of the agreement's sales-channel
  restrictions (no marketplace listings, no offline retail)

---

## Phase 3 — Wallet

**Module:** Wallet.

- Double-entry ledger (ADR-0004) — wallet balance is a projection of an
  append-only entries table, never a mutable integer
- Real money flows: customer payments, COGS, GST output, GST ITC,
  commission hold/unlock states, TDS, admin charges, refunds,
  chargebacks
- Refund pipelines for cooling-off (today queues placeholder events) and
  for the order-buyback cases from Phase 2
- KYC document re-upload flow lands fully end-to-end; Wallet completion
  gates payouts

---

## Phase 4 — Compensation: binary matching

**Module:** Compensation (1 of 3).

- Binary BV/PV matching using left-leg / right-leg subtree volumes
  (closure-table reads — ADR-0001 §70)
- Carry-forward of unmatched leg volume
- Weekly / monthly payout windows interacting with Wallet
- Caps: 3% or ₹30,000 max on GSB/MB/RB payouts; franchise/awards exempt
  (per the agreement)
- Distributor dashboard placeholder fields (Total Personal BV, Total
  Withdrawal Income) get wired up

---

## Phase 5 — Compensation: ranks & qualification

**Module:** Compensation (2 of 3).

- Nine published ranks with product-volume thresholds
- Prove-Your-Position (PYP) maintenance rule
- Rank-up / rank-down events, current-rank vs highest-rank surfaces
  (the dashboard ID-card placeholders already render `—` waiting for
  this data)
- Auto-termination after 12 continuous months of no sales (T&C §21);
  re-registration wait per rank (Sales Master = 1 year,
  Diamond Master+ = 2 years)

---

## Phase 6 — Compensation: Auto Pool & mentorship

**Module:** Compensation (3 of 3).

- Auto Pool matrix calculations
- Mentorship bonus, franchise bonus
- Awards & rewards (exempt from the admin charge)
- Final compensation-plan operationalisation. Until this lands, every
  rupee remains in placeholder state per hard rules 2 and 3.

---

## Phase 7–8 — Operations build-out *(implied, not formally specced)*

- `admin-operations` role split (closes R-17 if not already done in
  Phase 2)
- Termination workflow beyond cooling-off (referenced in
  `docs/runbooks/cooling-off-cancellation.md`)
- Distributor messaging / mentor calls / FAQ library tooling
- Grievance redressal workflow tracker (DSR Rule 4 — complaint number,
  SLA clock, monthly compliance report)
- Quarterly internal audit cadence formalised (the agreement promises
  this)

---

## Phase 9–11 — Growth surfaces *(implied)*

- **Analytics module** — distributor performance, funnel, retention
- Distributor-facing dashboards beyond the Phase-1 ID-card placeholders
- Likely **TDS / tax-statements pipeline** (Form 26AS reconciliation +
  the quarterly TDS certificate the dashboard card already points to)
- Public marketing iterations
- Likely commerce promotions, bundles, scheduled flash drops

---

## Phase 12 — Production hardening

- **US-1.06 MFA challenge** — TOTP enrolment, recovery codes, challenge
  step on login
- **T-5.2 / T-5.3 Observability** — structured JSON logs, OpenTelemetry
  traces, Prometheus metrics dashboards
- Redis queue swap (Phase 1 uses the database driver)
- Couple-registration follow-ups: withdraw-cascade, 60-day dedup on
  marriage, line-change cascade, spouse-login magic link

---

## Speculative future — NOT in Phase 2

### Nominee / 3-generation succession (full feature)

- **Status:** Not mandatory. Not confirmed by the legal team. Future
  idea only. Do **not** implement in Phase 2 and do not schedule.
- **What exists today:** §17 of the Direct Seller Agreement is a
  Reserved — not yet in force placeholder. Anchored to §11.5
  (re-parenting on termination) and §16.2 (30-day notice for
  amendments). States intent only; grants no rights today.
- **If/when legal confirms:** work required would include a
  counsel-finalised operative clause, a §16.2 30-day-notice amendment,
  `distributor.nominee_*` columns + transfer workflow, reconciliation
  with hard rule 6 (One PAN = One ADN — a grandchild nominee already
  holding an ADN would collide), and re-submission of any public
  marketing copy for compliance review.

---

## How to evolve this document

1. Keep this as the canonical phase index. CLAUDE.md and the ADRs
   should reference this file, not invent their own phase numbering.
2. When something ships, move it from the phase's "Open" list to its
   "Shipped" list. Don't delete history.
3. Confirmed deferrals stay listed under the **target** phase
   (e.g. MFA is listed under Phase 12, not Phase 1 "Open"). The
   distinction matters during exit-gate reviews.
4. Speculative work stays under "Speculative future" until legal /
   product / engineering converge on a real scope. The memory note
   [[phase-2-backlog]] mirrors this section — keep them in sync.
