# Phase 1 — Sprint Backlog

4–6 weeks, 6 sprints of 1 week. Each sprint has a headline goal.
Stories are `US-1.xx`; tasks below each story are the engineering units.
Acceptance = tests + compliance review + (where applicable) security review.

---

## Sprint 1 — Foundation

Headline: *The repository boots, the Docker stack works, the database
exists, CI is green.*

- [ ] T-1.1 `composer create-project laravel/laravel app` (Laravel 13 target)
- [ ] T-1.2 Wire `.env` from root `.env.example`
- [ ] T-1.3 Bring up `docker compose` stack (app + db + redis + mailpit + adminer)
- [ ] T-1.4 Module skeleton: `Identity`, `Genealogy`, `Kyc`, `Consent`, `Orientation`, `Compliance`, `Admin`, `Shared`
- [ ] T-1.5 ADR-0001 + ADR-0002 referenced from `config/arovolife.php`
- [ ] T-1.6 Translate `migrations-blueprint/*.sql` into Laravel migrations
- [ ] T-1.7 Eloquent models for all 13 Phase-1 tables
- [ ] T-1.8 Settings repository + seeded `placement.default_side=default_left`, `placement.allow_sponsor_override=true`
- [ ] T-1.9 CI: Pint, Larastan L7, Pest, coverage
- [ ] T-1.10 GitHub Actions workflow `.github/workflows/ci.yml`

Exit: `php artisan migrate` and `php artisan test` both green in CI.

---

## Sprint 2 — Identity, MFA, RBAC, Admin console shell

Headline: *A user can be created, MFA-enrolled, assigned a role, and
access the admin shell with policy enforcement.*

Stories: **US-1.06** (Distributor login with MFA), **US-1.10** (Admin
search), **US-1.11** (Admin freeze/unfreeze), **US-1.12** (Admin
configures state-wise age rule), **US-1.16** (Admin configures Placement
Strategy).

- [ ] T-2.1 `users` / `roles` / `permissions` wired via spatie/laravel-permission
- [ ] T-2.2 MFA (TOTP) via `pragmarx/google2fa-laravel`; mandatory for Distributor / Admin
- [x] T-2.3 Password policy — HIBP, zxcvbn ≥ 3, **8-char minimum** (relaxed from the master plan's 12 per 2026-04-29 project decision; entropy + breach checks still apply), 5-attempt login lockout
- [ ] T-2.4 Admin console shell (Blade + Tailwind, RBAC-gated)
- [ ] T-2.5 Settings admin page: read/edit `placement.default_side`, `placement.allow_sponsor_override`, with reason-text + audit log
- [ ] T-2.6 Admin search (ADN / PAN-last4 / email / phone)
- [ ] T-2.7 Admin freeze / unfreeze + event `admin.distributor.frozen|.unfrozen`
- [ ] T-2.8 State-wise age rule configurable; seed with Maharashtra=21
- [ ] T-2.9 Policies + IDOR tests

Exit: the `security-auditor` subagent passes the authn/authz portion of the audit.

---

## Sprint 3 — KYC, consent, orientation, Steps 1–7 of registration

Headline: *A prospect can start a registration session, watch
orientation, accept agreements, complete KYC, and reach Step 7.*

Stories: **US-1.01** (invitation), **US-1.02** (register free with
KYC), **US-1.03** (orientation), **US-1.04** (accept agreement),
**US-1.13** (couple primary+secondary).

- [ ] T-3.1 Signed invite URLs with sponsor_id
- [ ] T-3.2 Orientation video player + watch-percentage + quiz
- [ ] T-3.3 Agreement reader + per-document versioned acceptance rows
- [ ] T-3.4 PAN gateway adapter (stub + real vendor integration behind a feature flag) + hash/last4 storage
- [ ] T-3.5 Aadhaar OTP flow via approved partner; store reference + last4
- [ ] T-3.6 Bank penny-drop
- [ ] T-3.7 Address proof upload + AV scan + MIME / magic-byte
- [ ] T-3.8 Couple primary + secondary UI + rule engine
- [ ] T-3.9 Content audit for all registration copy by `compliance-officer`

Exit: registration reaches Step 7 end-to-end in E2E tests.

---

## Sprint 4 — Placement engine, Steps 8-10, cooling-off, genealogy views

Headline: *A prospect becomes an active Direct Seller with a valid
placement, cooling-off timer runs, downline views work.*

Stories: **US-1.05** (placement), **US-1.07** (genealogy view), **US-1.08**
(cooling-off cancel), **US-1.09** (line-change), **US-1.15** (optional
placement_id), **US-1.16** (Placement Strategy setting fully wired).

- [ ] T-4.1 `PlacementStrategyResolver` service (unit tests green)
- [ ] T-4.2 `PlacementEngine` service with SERIALIZABLE tx + advisory lock
- [ ] T-4.3 Closure-table writer + sponsorship writer
- [ ] T-4.4 Dry-run placement preview endpoint
- [ ] T-4.5 Descendant validation (reject `placement_id` outside downline + audit log)
- [ ] T-4.6 ADN generator (unique, URL-safe, monotonic)
- [ ] T-4.7 Steps 8-10 of the wizard (preview, finalize, ADN issuance)
- [ ] T-4.8 Cooling-off timer + reminders (D-20 / D-7 / D-1) via SMS + email
- [ ] T-4.9 One-click cooling-off cancellation (runbook live)
- [ ] T-4.10 Line-change workflow (≤ 5 working days window)
- [ ] T-4.11 Binary + horizontal genealogy views (lazy-loaded)

Exit: Every scenario in `placement-engine-spec/test-scenarios.md` has a
passing test. `/placement-test` green.

---

## Sprint 5 — Hardening

Headline: *Performance, accessibility, observability, feature flags,
runbooks, DR drill.*

- [x] T-5.1 PII-scrubbing log middleware
- [ ] **T-5.2 Structured JSON logs + OTel traces — deferred to Phase 12** (bundled with the production-hardening pass that already lives in §15 of the master plan).
- [ ] **T-5.3 Prometheus metrics — deferred to Phase 12** (same bundle as T-5.2).
- [ ] T-5.4 Feature flags for registration (killswitch) and Placement Strategy toggles
- [ ] T-5.5 Performance: seeded 1M-row tree; p95 placement ≤ 250 ms
- [ ] T-5.6 Accessibility scan (Pa11y) on wizard; WCAG 2.1 AA
- [ ] T-5.7 Backup + restore drill into a staging DB
- [ ] T-5.8 Runbooks reviewed and exercised
- [ ] T-5.9 `/phase-1-status` dry-run shows readiness

Exit: staging environment matches performance budget; DR drill green.

---

## Sprint 6 — Security audit, UAT, exit gate

Headline: *Security audit passes, UAT green, PO signs off.*

- [ ] T-6.1 `security-auditor` 10-point pass on the full phase
- [ ] T-6.2 `compliance-officer` sign-off on C-01 … C-09
- [ ] T-6.3 UAT with PO on seeded personas (happy path, edge cases, couple, minor-attempt, state rule)
- [ ] T-6.4 `/phase-1-status` green
- [ ] T-6.5 Retrospective + postmortem-of-the-phase doc
- [ ] T-6.6 Exit: tag `v0.1.0-phase1`, production freeze until Phase 2 kickoff

Exit: Product Owner signs the Appendix B of the PRD.

---

## Story-to-sprint map

| Story | Sprint |
|---|---|
| US-1.01 Invitation URL | 3 |
| US-1.02 Free registration + KYC | 3 |
| US-1.03 Orientation | 3 |
| US-1.04 Accept agreement | 3 |
| US-1.05 Placement | 4 |
| US-1.06 Login + MFA | Login: ✓ Sprint 2 (incl. password policy + lockout). **MFA enrolment/challenge deferred to Phase 12** alongside the rest of session hardening. |
| US-1.07 Genealogy view | 4 |
| US-1.08 Cooling-off | 4 |
| US-1.09 Line-change | 4 |
| US-1.10 Admin search | 2 |
| US-1.11 Admin freeze/terminate | 2 |
| US-1.12 State-aware age rule | 2 |
| US-1.13 Couple registration | Phase 1: two distributor rows mutually linked, secondary's ADN derived as `<primary>-S` (internal-only), full KYC for each, both sign agreements, single tree slot. **Phase 12 still owns**: withdraw-cascade, 60-day dedup on marriage, inheritance, line-change cascade. |
| US-1.14 Register of DS export | 5 |
| US-1.15 Optional placement_id | 4 |
| US-1.16 Placement Strategy setting | 2 + 4 |
