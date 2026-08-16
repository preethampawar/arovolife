# Arovolife Roadmap — phase-wise feature list

> Synthesised from `CLAUDE.md`, `docs/phase-1-prd.md`, the ADRs under
> `docs/architecture/`, the runbooks under `docs/runbooks/`, and the
> project memory notes. This document is the single place that says
> **what belongs to which phase** — when CLAUDE.md or an ADR contradicts
> this, update both.

> **Reconciled 2026-08-16.** Phases 1–7 are shipped. This file had drifted
> — it still described Phases 2–6 as unstarted months after the engines
> went in. Remaining work is now collected under
> **§ Operations build-out**, **§ Commerce completion** and
> **§ Final sign-off gate**.

---

## Status at a glance

| Phase | Scope | Status |
|---|---|---|
| 1 | Registration, auth, genealogy, KYC, consent, admin | ✅ shipped |
| 2 | Commerce — catalog, cart, checkout, payments, returns | ✅ shipped |
| 3 | Wallet — double-entry ledger, payouts | ✅ shipped |
| 4 | Compensation — GSB, Mentorship, Growth Booster, repurchase | ✅ shipped |
| 5 | Compensation — ranks, Rank Bonus, Lifetime Awards | ✅ shipped |
| 6 | Compensation — Fortune Bonus cascade (replaced Auto Pool) | ✅ shipped |
| 7 | Arete Development Center bonus | ✅ shipped |
| — | Operations build-out (grievance, termination, franchise, offers, analytics) | 🔨 in progress |
| 12 | Production hardening (MFA, observability, Redis, couple follow-ups) | ⏸ deferred |
| — | Final sign-off gate | ⏳ batched to launch |

---

## Phase 1 — Foundation ✅

**Modules:** Identity · Genealogy · KYC · Consent · Orientation ·
Compliance · Admin.

### Shipped

**Registration & identity**

- 10-step registration wizard (free joining, mandatory orientation video
  + micro-quiz, electronic consent with versioned hash; DSR Rule 5(1)(a))
- One PAN = one ADN
- Couple registration (US-1.13) — two distributor rows, both with full
  KYC, mutually linked; secondary doesn't take a Genos (binary tree) slot
- Aadhaar via UIDAI-approved AUA/KUA partner — reference + last-4 only,
  raw never stored (DPDP Act 2023; hard rule 8)
- PAN as hash + last-4 (encrypted blob purged after KYC sign-off)
- Password policy: zxcvbn entropy + HIBP NotPwned, 5-attempt
  per (email, IP) throttle, stale-throttle clearing on admin reset
- Login by email **or** 9-digit ADN; couple-ADN reveals a primary/spouse
  selector

**Genealogy**

- Closure-table Genos (binary placement tree) (ADR-0001)
- Single-level placement on the chosen leg (ADR-0003) — no spine walk
- Sponsorship vs binary placement separation
- Line-change request (≤ 5 working days, single use, no-downline guard,
  Phase-2 commerce-activity block)
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
- D-7 / D-1 email + SMS reminders (D-20 dropped 2026-08-16, `ae76256`)
- Self-cancellation cascades to spouse for couples
- ADR-0005: distinct from the per-order cooling-off clock in Phase 2

**Admin console**

- Dark-slate theme with explicit ADMIN pill
- Distributors list, distributor detail page, Direct Legs view
- Distributor create (paper onboarding) + edit
- Direct password reset modal (audit-logged, pending tokens invalidated)
- KYC review queue + per-doc flag UI
- Line-change review
- Tree view + genealogy tree (admin can re-root at any distributor)
- Contact Inbox, Content Pages CRUD, Compliance Documents
- Settings (Placement Strategy + others), feature flags (Pennant),
  audit log, impersonation, DSR Register CSV export

**Distributor dashboard & documents**

- "My Dashboard" — ADN card, placement, cooling-off, messages, team stats
- Documents section (Direct Seller Application, Membership Card,
  TDS Tax Statements)
- Membership card, print-ready; Direct Seller Application page

**Public site & content**

- Hero slider, Why arovolife, How To Register, Our Products (6 categories
  incl. Agri Care), Compliance commitment, About-us, Shop category pills
- `/p/terms` (incl. §17 Nominee Succession — Reserved, not in force),
  `/p/privacy`, `/p/grievance`, `/p/ethics`

**Compliance & security**

- Eight hard rules enforced in code
- PII scrubber middleware on logs
- Audit log on every admin action, KYC change, settings change
- Versioned consent with `document_version + hash_of_doc + ip + ua`
- Idempotency keys on external calls
- DSR-2021 mapping documented in `docs/compliance/`

### Cancelled

- **T-4.4 dry-run placement preview endpoint** — cancelled by the Product
  Owner on 2026-08-16. Redundant under ADR-0003: placement is locked on
  the referral link, so there is nothing to preview.

### Deferred to Phase 12

- **US-1.06 MFA challenge** — TOTP enrolment, recovery codes, challenge step
- **T-5.2 / T-5.3 Observability** — structured JSON logs, OTel, Prometheus

### Still open

- ~~**T-5.9 `/phase-1-status` artisan command**~~ — **DONE 2026-08-16**.
  `php artisan phase:status` reads the backlog, the risk register, the
  security audit checklist and the git log, and reports anything it cannot
  verify from the repository as UNVERIFIED or NEEDS-A-HUMAN rather than
  assuming it green. Run it from the host checkout — `docs/` and `backlog/`
  are not mounted into the container.
- **T-5.5 performance proof** (1M-row tree, p95 placement ≤ 250 ms) and
  **T-5.7 backup/restore drill** — no evidence in the repo; both folded
  into the final sign-off gate
- Sign-offs → see § Final sign-off gate

---

## Phase 2 — Commerce ✅

**Modules:** Commerce · Catalog · Payments · Fulfilment · Returns.

- Storefront — catalog, cart, checkout, payment integration, invoice on
  every order
- BV-only volume model (PV removed), shipping, COD
- **Per-order 30-day cooling-off / return window** — distinct clock from
  the distributor cooling-off (ADR-0005), runs from `delivered_at`
- Buyback / refund matrix from T&C §8 (saleable / non-saleable / damaged /
  dissatisfied) — `OpenReturn`, `RefundOrder`, `InspectReturn`,
  `BuybackMatrix` (ADR-0009)
- Customer entity model (ADR-0003)
- My Orders, order-event notifications
- **Line-change commerce block** — line change rejected for distributors
  with any commerce activity
- Members-only buying (`guest_checkout` default OFF), after-login pricing,
  Easy Purchase `?ref` link, rich product attributes
- Risk-register closures: **R-16** placement TOCTOU full serialisation;
  **R-17** dedicated `admin-operations` role (also closes R-20)
- Catalog-side enforcement of the sales-channel restrictions

### Still open in Commerce

- ~~**R-28 tax invoice**~~ — **DONE 2026-08-17.** The order document now
  renders the canonical Tax `Invoice` record: supplier GSTIN from settings,
  optional recipient GSTIN captured at checkout, consecutive per-financial-year
  numbering (`AL/2026-27/000001`) under a row lock, and tax recomputed from
  what was actually charged so a discounted invoice foots. While the GSTIN
  setting is blank the document is honestly labelled a receipt.
  **Pre-launch: fill in the real GSTIN.**

---

## Phase 3 — Wallet ✅

**Module:** Wallet · Ledger.

- Double-entry ledger (ADR-0004) — balance is a projection of an
  append-only entries table
- `PayoutService` pending→approve NEFT flow, `PayoutBatch` with
  `approved_by` / `approved_at`, admin approve + NEFT CSV export
- `payout.min_threshold_paise` configurable (default ₹500), ₹100 minimum
  payout per KP
- Payout holds — bank release held (`web_only`, `kyc_pending`,
  `no_bank_account`) until BV ≥ 3000 **and** KYC active **and** bank on
  file; income still accrues and is never swept while held
- Refund pipelines for cooling-off and for the Phase-2 buyback cases
- KYC document re-upload flow end-to-end

---

## Phase 4 — Compensation: sales & mentorship bonuses ✅

**Module:** Compensation (1 of 4).

- **Genos Sales Bonus (GSB)** — slab score × per-slab score value; scores
  8/16/32/60/112/184/280 against thresholds 15K/36K/1L/3L/9L/27L/81L;
  conditional personal-BV top-up; equal-sides tie-break Left = power;
  score snapshot + admin daily calculation report
- **GSB daily pool pricing** — slabs 3–7 pro-rated daily from the 45% pool,
  slabs 1–2 fixed
- **Mentorship Bonus (MSB)** — 3% of the day's BV ÷ the day's total MSB
  points = point value; third cut-off pass + MSB Input & Output report
- **Growth Booster Bonus (GBB)** — BV pool + prior-month rank gate, frozen
  `gbb_monthly_pools`, grace hold/release
- **Repurchase / income-eligibility engine** — earning from 600 BV
  (slab-1 / web-only), release at 3000 BV
- **Group BV reversal engine** (ADR-0010) — cancelled/refunded orders
  reverse group BV against the originally-credited upline via the
  `group_bv_credits` snapshot; no clawback; same-side debt consumed by
  future propagation
- Carry over (pre-match) / carry forward (post-match remainder)
- All parameters DB-driven (`gsb_slabs`, `compensation_plan_settings`),
  edited at `/admin/compensation/plan-settings` — no hardcoded constants

---

## Phase 5 — Compensation: ranks & qualification ✅

**Module:** Compensation (2 of 4).

- Nine ranks with product-volume thresholds; Rank-1 pool is points-based
  (10 RAP per achiever, value = pool ÷ points floored to the rupee)
- **AO-GO offer** — replaces the 1+2 carry-forward; max 3 lifetime,
  never consecutive, must re-achieve between uses, a failed month
  consumes no chance
- Prove-Your-Position (PYP) maintenance, requalification gate
  (`requalification_held`, excluded from the denominator)
- Q-Period promotion gate for ranks 3+ — lifetime occurrence count
- R1 2.5L / R2 6L matching BV per side (revised 2026-08-13)
- Rank-up / rank-down events, rank status + AO-GO eligibility surfaced to
  distributors
- **Lifetime Awards & Rewards** — released only after the rank is proven;
  exempt from the admin charge
- Highest-rank-only payment (a distributor draws from one pool, not many)

### Open questions for KP (must be answered before launch)

1. **Envelope 21% vs 20%.** The nine rank `pool_pct` values sum to 21%
   but the envelope is 20% of monthly company BV. Under KP's own ₹14,000
   worked example the percentages are shares *of the envelope*, so all
   nine ranks together consume only 21% of it and ~79% of the rank
   envelope goes unspent (total rank payout = 4.2% of BV). Setting the
   envelope to 21% yields ₹14,700 and contradicts KP's figure.
2. **Incentive inversion.** July's real run paid Silver ₹99,360 each and
   Pearl ₹27,580 each — the higher rank earned less, because exclusive
   (highest-only) pools split the higher pool across more achievers. The
   plan text supports exclusive ("Rank 2 cancels Rank-1 benefit"); KP must
   confirm exclusive vs cumulative.
3. **Title stickiness vs net BV.** KP said personal-BV titles are sticky,
   but `RankQualificationService::buildPersonalBvMap` sums accrual-only
   while `totalPersonalBvPaise` is net of cancellations. Needs one basis.
4. **Rank 2 matching BV — 5L or 6L?** KP's heading said 5 lakh once;
   every detail line said 6 lakh. Implemented 6L per side.

Also unresolved: **R-32 extended** — cancelled BV inside settled-day
slab-1/power carry-forward and inside monthly rank sums is not retro-
adjusted. Matches KP's "only future BV reduced" but wants re-confirmation
at launch sign-off.

---

## Phase 6 — Compensation: Fortune Bonus ✅

**Module:** Compensation (3 of 4).

- **Fortune Bonus level cascade** (KP 2026-08-09) — replaces the Auto Pool
  concept. ₹30 minimum, per-level capped values (30k × 4 / 20k / 10k / 5k),
  shared residual at levels 7–8, flat level 9; depth points 9→1
- 3×9 monthly matrix, participation-based, first-come-first-served
  placement, monthly reset, capped at Rank 5
- Per-level pool snapshot schema; pure allocator regression-locked to KP's
  ₹36 crore worked example
- Distributor-facing page explains the cascade; compliance disclosure and
  guardrails added after compliance-officer review
- Flag OFF in production pending the DSA §6.2 30-day notice

---

## Phase 7 — Arete Development Center ✅

**Module:** Compensation (4 of 4).

- **ADC bonus** on signed net personal BV, with a per-center cap override
  (penalty, min with a ₹1 lakh cap)
- Manual development-phase tracking (phases 1–4), 3-month admin window;
  stop = inactive, transfer = ADN field
- Arete center create/edit with pincode/district/state, audit-logged
- ADC calculation report, flag-gated
- Admin **Engine Runs** page — manual triggers, dependency chains, run log;
  flag-off = skipped; payout engines stay scheduler-only

---

## Operations build-out 🔨 (current work)

Formerly sketched as "Phase 7–8, implied". Now specced and in progress.

### 1. Grievance redressal workflow (DSR Rule 4) — **statutory**

`app/Modules/Grievance/` holds `Ticket` + `TicketEvent` models and one
migration, and nothing else: no services, no controllers, no routes.
`/p/grievance` publishes the officers' names but has no intake behind it.

Required: complaint intake (public + authenticated), unique complaint
number, acknowledgement inside 48 hours, resolution inside 45 days, SLA
clock with breach flagging, escalation to the Nodal Officer, admin queue
with audit trail, and the monthly compliance report.

### 2. Termination workflow beyond cooling-off (T&C §21) — **statutory**

Auto-termination after 12 continuous months with no sales, plus the
re-registration wait by rank (Sales Master = 1 year, Diamond Master and
above = 2 years). Referenced in
`docs/runbooks/cooling-off-cancellation.md` but never built.

### 3. Franchise + 3% payout

**Unparked 2026-08-16 — the Product Owner confirmed the model and that the
3% payout may exist.**

- A franchise is a company-owned pickup/fulfilment point operated by a
  distributor. Stock is company consignment; sales stay online and
  ADN-attributed. Not a walk-in retail shop.
- **Commission base: 3% of the order's sale rupee value** (not 3% of
  lifetime BV) — PO decision 2026-08-16.
- **Attribution: chosen per order at checkout** (not a registration step)
  — PO decision 2026-08-16. `WizardStateService::STEPS` stays at 10.
- Franchise code is separate from the ADN and never enters the Genos.
- Design constraints DC-01..DC-05 and risks R-21..R-25 remain binding.
  R-24 (combined binary-tree + franchise pyramid surface) stays open in
  the risk register as a launch-sign-off item.

**Built 2026-08-16** behind `FranchiseFeature` (default OFF, zero-trace).
Franchise register with an application → approval lifecycle, `FR-XXXXX` codes
that cannot be mistaken for an ADN, a collection-point picker at checkout, and
a monthly commission engine (`franchise:monthly-run`, 8th at 09:45 IST).
Base is the product value of orders **delivered** through the franchise —
subtotal less discount, excluding GST and shipping. Exempt from the admin
charge. 11 tests. Three gates before any real payout: the DSA §6.2 notice,
the effective date on `/p/compensation` §11.1, and R-24's counsel opinion.
Consignment **stock tracking is not built** — see R-46; until it is, a
franchise can only be a handover point, not a stockist.

### 4. Purchase offers (KP 2026-06-26)

For distributors holding **no rank**:

- **50% offer** — after activation (3,000 BV → Retailer), repurchase
  ≥ 1,000 BV in a month → one company-specified product at 50% of DP.
- **20% redeem points** — maintain ≥ 1,000 BV for 6 consecutive months
  (no break) → 20% of total BV as redeem points (1 point = ₹1); another
  consecutive 6 months → another 20%; a full 12 months → +10% extra.
- **The "joining" trigger is DROPPED** — PO decision 2026-08-16. An offer
  tied to joining would break hard rule 1 (joining free of cost) and
  hard rule 2 (value only from product sales). Points attach strictly to
  the distributor's own documented product purchases, framed neutrally
  with no income projection.

### 5. Analytics

Distributor performance, registration/order funnel, retention. Admin-facing
reports reusing `TeamStatsService::scopedQuery()` and existing BV/order
data — never re-implementing closure or sponsorship counting.

### Also in this build-out

- Distributor messaging / mentor calls / FAQ library tooling
- Quarterly internal audit cadence formalised (the agreement promises it)

---

## Phase 12 — Production hardening ⏸ deferred

Explicitly out of scope for the current sprint (PO decision 2026-08-16:
"stop before Phase 12").

- **US-1.06 MFA challenge** — TOTP enrolment, recovery codes, challenge
  step on login
- **T-5.2 / T-5.3 Observability** — structured JSON logs, OpenTelemetry
  traces, Prometheus metrics
- Redis queue swap (currently the database driver)
- Couple-registration follow-ups: withdraw-cascade, 60-day dedup on
  marriage, line-change cascade, spouse-login magic link

---

## Final sign-off gate ⏳

Batched to a single launch of the complete platform — the partners do not
want per-phase sign-offs.

| Item | Owner |
|---|---|
| T-6.1 security-auditor 10-point pass | `security-auditor` |
| T-6.2 compliance-officer sign-off C-01…C-09 | `compliance-officer` |
| T-5.6 Pa11y / WCAG 2.1 AA scan + evidence | engineering |
| T-5.5 performance proof — 1M-row tree, p95 placement ≤ 250 ms | engineering |
| T-5.7 backup + restore drill into staging | engineering |
| T-6.3 UAT with PO sign-off | Product Owner |
| Named officers — real Grievance Officer / DPO / Nodal Officer, real helpline, provisioned `@arovolife.com` mailboxes | company |
| KP's four open compensation questions (§ Phase 5) | KP |
| R-24 legal-counsel opinion on the franchise + binary surface | counsel |
| DSA §6.2 30-day notice before enabling the flagged bonus engines | compliance |

---

## Speculative future — not scheduled

### Nominee / 3-generation succession (full feature)

- **Status:** not mandatory, not confirmed by the legal team, future idea
  only. Do not implement and do not schedule.
- **What exists today:** §17 of the Direct Seller Agreement is a
  *Reserved — not yet in force* placeholder, anchored to §11.5
  (re-parenting on termination) and §16.2 (30-day amendment notice). It
  states intent only and grants no rights.
- **If legal confirms:** counsel-finalised operative clause, a §16.2
  30-day-notice amendment, `distributor.nominee_*` columns + transfer
  workflow, reconciliation with hard rule 6 (a grandchild nominee who
  already holds an ADN would collide), and re-submission of any public
  marketing copy for compliance review.

---

## How to evolve this document

1. Keep this as the canonical phase index. CLAUDE.md and the ADRs should
   reference this file, not invent their own phase numbering.
2. When something ships, move it into the phase's shipped list. Don't
   delete history.
3. Confirmed deferrals stay listed under the **target** phase. The
   distinction matters during exit-gate reviews.
4. Speculative work stays under "Speculative future" until legal, product
   and engineering converge on a real scope.
5. `backlog/phase-1-backlog.md` mirrors the Phase-1 detail — keep the two
   in step. Both drifted badly before the 2026-08-16 reconciliation.
