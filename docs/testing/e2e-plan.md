# End-to-end test plan — isolated E2E stack, full UI + money-correctness coverage

Status: v2 APPROVED 2026-11-10 (D1 yes, D2 A, D3 yes, D4 yes). S1 built
2026-11-10 (uncommitted); S2 next.
Out of scope: Lifetime Awards & Rewards payout (on hold — client questions open).

## Goal and definition of done

Playwright proves, against a throwaway database, that every form, validation
message, flash (success / error / notice), confirmation modal and navigation
path works — and that every rupee figure (bonuses, ranks, wallet, reports,
CSV/PDF downloads, payout pages, distributor payout views) matches a
hand-computed expected value and agrees across every page that shows it.

Done when:
1. `npm run test:e2e` from `app/` runs the whole suite against the E2E stack
   (`http://localhost:8085`, database `arovolife_e2e`) and never touches
   `arovolife` (dev), dev storage, dev Redis, or any external service.
2. Every project runs at the default single worker (`workers: 1`); `money` runs after `ui`.
3. `docs/testing/e2e-coverage.md` lists every mutating route and form view with
   the spec that covers it; a coverage check fails on any uncovered
   POST/PUT/PATCH/DELETE route that is not explicitly waived with a reason.
4. Money fixtures give the same result on any calendar day (frozen clock).
5. The suite is green twice in a row, and dev DB row counts are unchanged before/after.

## Architecture

### E2E stack (docker compose profile `e2e`, off by default)
- `e2e-app`: same image and code mount as `app`, but:
  - **Identity:** `ARV_E2E=1`, `DB_DATABASE=arovolife_e2e` (env label per decision D2).
  - **Own caches outside the mount:** `APP_CONFIG_CACHE=/tmp/e2e/config.php`,
    `APP_ROUTES_CACHE`, `APP_EVENTS_CACHE`, `APP_SERVICES_CACHE`, `APP_PACKAGES_CACHE`
    under `/tmp/e2e/` — a `config:cache` run in the dev container can never be read here.
  - **Own Redis DBs:** `REDIS_DB=5`, `REDIS_CACHE_DB=6`, `SESSION_CONNECTION=default`
    (sessions in DB 5, cache in DB 6, so a reset can flush the cache without logging everyone out).
  - `QUEUE_CONNECTION=sync`, `MAIL_HOST=mailpit`, `SESSION_SECURE_COOKIE=false`,
    `SESSION_EXPIRE_ON_CLOSE=true` (frozen past clock must not expire cookies).
  - **Dummy, non-empty, unroutable credentials** (the boot-time S3 check needs
    non-empty values): `AWS_ACCESS_KEY_ID=e2e-invalid`, `AWS_SECRET_ACCESS_KEY=e2e-invalid`,
    `AWS_BUCKET=e2e-none`, `AWS_ENDPOINT=http://127.0.0.1:1`; Razorpay / Shiprocket blank.
  - **No internet:** on a Docker `internal: true` network shared only with `db`,
    `redis`, `mailpit`; `e2e-web` bridges to the host for port 8085.
- `e2e-web`: nginx on host port **8085**, `fastcgi_pass e2e-app:9000`.
- No queue / scheduler containers.
- One-off setup (root, with your confirmation): `CREATE DATABASE arovolife_e2e` +
  `GRANT ALL ON arovolife_e2e.* TO 'arovolife'@'%'`.

### Code guards (env-gated, registered from an existing provider's `register()`)
`App\Modules\Shared\Testing\E2eServiceProvider`, only when `ARV_E2E=1`:
- **Fail-closed DB guard** in `register()`: throws unless the live connection's
  database name ends in `_e2e`.
- **All disks remapped** (s3 *and* `local`, `public`, `catalog`) to
  `storage/framework/e2e/disks/{name}`; `catalog` gets an e2e web root + URL.
  Boot asserts no disk still has driver `s3`. Separate log file
  `storage/logs/e2e.log`.
- `Http::preventStrayRequests()` — any server-side HTTP call throws.
- Payments: stub gateway permitted, Razorpay and Shiprocket settings OFF, manual
  courier. Razorpay / Shiprocket stay covered by the existing Pest `Http::fake` tests.
- Upload scanner allowed (the `UnconfiguredScanner` allow-list), per decision D2.
- **Frozen clock** (decision D3): reads the as-of instant written by
  `e2e:engines` / `e2e:scenario` and calls `Carbon::setTestNow()` per request.
- A Pest test asserts the provider is never registered without `ARV_E2E=1` or on
  a database not ending in `_e2e`.

### E2E commands (all refuse unless `ARV_E2E=1` and live DB `*_e2e`)
- `e2e:whoami` — prints env flag + live DB name. The Node helper calls it first
  on every run and aborts on anything but `arovolife_e2e`.
- `e2e:snapshot` — migrate + base seed under a **pinned clock** (fixed platform
  start date), NotPwned flag OFF, then dump to `storage/framework/e2e/base.sql`.
  S1 first proves dump/restore works with the container's MariaDB client against
  MySQL 8; fallback is a template database copied table by table.
- `e2e:reset` — restore the snapshot, flush the cache DB only, clear rate-limiter keys.
- `e2e:scenario {name} --as-of=<instant> --json` — builds a deterministic
  scenario on fixed dates through the real services (built on the existing
  `SeedPayingPeriodCommand`), prints the IDs/ADNs.
- `e2e:engines --as-of=<instant> [--settle-repurchase]` — pins the clock and
  calls `CompensationRecomputeRunner` in-process (two passes where repurchase
  cycles must settle). **Playwright never calls `compensation:recompute-all`.**
- Node helper `tests/Browser/support/artisan.js`: hard-coded container
  `arovolife-e2e-app`, whoami check before every command.
- OTP: read from Mailpit's API (`localhost:8027/api/v1/messages`, by recipient) —
  no product code.

### Running the suite
- Playwright `workers: 1`; projects `ui` then `money` (`dependencies: ['ui']`).
- `globalSetup`: whoami preflight → reset → saved storageState per role.
- Every outbound browser request to a non-localhost host is blocked with
  `context.route` (covers Razorpay checkout JS, GA).
- `ui`: each spec creates its own partners via `e2e:scenario`; specs that change
  global settings run last in `ui` and are followed by `e2e:reset`.
- Throttles: limiter keys cleared between spec files; one dedicated spec per
  throttle proves it still fires (registration, password reset, checkout,
  contact, public grievance, find-my-id, login).
- `money`: reset → scenario → engines → assert, a fresh reset each scenario.

### Expected values (the oracle)
Each money scenario has `tests/Browser/money/fixtures/<scenario>.json` with
hand-worked figures and the arithmetic written out (pool, points, value per
point, floor, caps, repurchase 10%, admin 3% per group with the ₹25,000 caps,
TDS 5% of payable, net), cross-checked against the plan doc and
`docs/compensation/*`. Specs assert the same figure everywhere it appears:
distributor income page, dashboard card, wallet ledger, admin results page,
admin report, CSV download (parsed), PDF download (`pdf-parse`), payout
batch/line page, bank file, TDS/GST reports.

## Slices (each one ends green before the next starts)

| # | Slice | Built by | Verify |
|---|---|---|---|
| S1 | DB + grants, compose profile, internal network, nginx, cache paths, `E2eServiceProvider` (guards, disks, Http block, stub gateway, clock), `e2e:whoami/snapshot/reset`, Playwright config + globalSetup + helper | Opus | guard refuses a dev DB; whoami preflight aborts on the wrong container; `curl` from e2e-app to the internet fails; a dev `config:cache` doesn't change e2e; reset < 10 s; dev row counts unchanged |
| S2 | `e2e:scenario`, `e2e:engines --as-of`, Mailpit OTP helper; port the 26 existing specs to :8085 (drop `A11Y_ADN`) | Opus | a GSB scenario gives identical figures with the host clock moved a month; existing specs green |
| S3 | Coverage inventory + coverage check | Sonnet | check lists every gap |
| S4 | UI — public, auth, registration wizard (orientation, consent, KYC upload, cooling-off cancel), profile + OTP, KYC re-upload, grievances, messages | Sonnet | green ×2 |
| S5 | UI — shop: cart, checkout (stub gateway), COD, addresses, orders, returns, invoices | Sonnet | green ×2 |
| S6 | UI — distributor: income pages, Genos, ADC/Arete centre, requests, action centre | Sonnet | green ×2 |
| S7 | UI — admin: distributors, catalog, commerce, inventory, payments, grievances, content, staff/RBAC (separation of duties), settings, reports, exports | Sonnet | green ×2 |
| S8 | UI — admin compensation (31 forms): plan settings, slabs, engine runs, reversals (maker-checker, two admins) | Sonnet | green ×2 |
| S9 | Money — GSB, MSB | Opus fixtures, Sonnet specs | green ×2, matches fixture |
| S10 | Money — GBB, Rank (R1 points, AO-GO), Fortune (levels 0–9 caps), ADC | same | same |
| S11 | Money — repurchase wallet (two-pass), ₹1 crore cap, payouts (admin groups, TDS, KYC/bank holds, paid/failed/returned, skip-and-retry, bank file), distributor payout pages | same | same |
| S12 | Money — TDS, GST, profit on sales, company snapshot; every CSV/PDF reconciled | same | same |
| S13 | Full run ×2, flake triage, `docs/local-dev-environment.md` E2E section, memory | Opus | definition of done 1–5 |

Sonnet agents: at most 2 at a time, medium effort, specs only (no product
code). Product bugs they find are reported; the main session decides the fix.

## S1 as built (2026-11-10)
- Databases `arovolife_e2e` + template `arovolife_e2e_base` (created as root, granted to `arovolife`).
- Dump/restore replaced by an in-server table copy (`E2eDatabaseTemplate`): the
  container's MariaDB client cannot log in to MySQL 8. Reset ≈ 1.1–1.5 s, snapshot ≈ 12 s.
- **MySQL follows the frozen clock.** Freezing Carbon alone left the server on the
  real date: no app query calls `NOW()`/`CURDATE()` (searched), but 66 tables fill
  `created_at`-style columns from `CURRENT_TIMESTAMP` defaults (ledger_entries,
  wallet_ledger_entries, orders, group_bv_credits, distributors…). The provider sends
  `SET TIMESTAMP=<app clock>` before a query whenever the app clock has moved, so
  defaults and SQL date functions match the fixture date (`SYSDATE()` is not covered;
  unused in app code).
- `e2e:clock {instant?}` added to step the clock from a spec (`setClock()` in the helper).
- db / redis / mailpit join the internal `arovolife-e2e` network via `docker network
  connect` in `make e2e-up`, so compose never recreates them.
- Verified: guard refuses the dev DB from both containers; e2e commands absent on dev;
  no DNS/internet from e2e-app; config/route caches at `/tmp/e2e/*`; all disks local
  under `storage/framework/e2e/disks`; smoke spec 3/3; `E2eModeTest` 4/4; dev DB row
  counts identical before/after (146 tables, 72,166 rows).
- Diff review (independent, 2026-11-10) — all fixed and re-verified:
  template copy skipped every `DEFAULT_GENERATED` timestamp column (now copied);
  e2e-app used the dev DB user (now user `arovolife_e2e`, grants on the two e2e
  schemas only — `SELECT arovolife.users` is denied); browser blocking covered one
  spec and allowed :8084 (now an auto fixture in the shared base, only
  `http://localhost:8085`, WebSockets too, service workers blocked); cache-flush
  guard was a denylist (now only Redis DB 6, refuses when `REDIS_URL` is set); a
  stale template is refused before anything is truncated; the admin log viewer
  reads `storage/logs/e2e` on the e2e stack; KYC provider keys blanked.
  The `SET TIMESTAMP` hook was verified by hand, not by the reviewer.
- Until S2 ports them, the 26 older specs (written for :8084 dev data) fail on :8085.

## S2 as built (2026-11-10, spec porting in progress)
- `e2e:scenario {name} --json` — named scenarios in
  `app/Modules/Shared/Testing/Scenarios/E2eScenarios.php`, written by
  `E2eScenarioBuilder` (partners placed in the Genos with closure rows, paid
  orders + personal-BV ledger rows; nothing derived). Deterministic ADNs
  (`9000000NN`), e-mails (`<key>@e2e.test`), one password for every partner.
  Each scenario states its own dates (no `--as-of` on the scenario: the dates are
  part of the fixture). Scenarios: `partner`, `gsb-basic`.
- `e2e:engines --as-of=<instant> [--settle-repurchase=YYYY-MM,…]` — calls
  `CompensationRecomputeRunner::run()` in-process at the frozen instant, then
  leaves the clock there; the two-pass flow settles through
  `compensation:seed-paying-period --settle-repurchase`.
- `e2e:reset --cache-only` — flush the rate-limiter cache only.
- **Feature flags pinned in the snapshot.** The fresh seed left every engine flag
  off, so the replay paid nothing. `E2eSnapshotCommand::FEATURES` now pins every
  Pennant flag (dev's set as of 2026-11-10, with Shiprocket and HIBP off) and the
  snapshot refuses if a flag class is missing from the list.
- Node: `scenario()`, `runEngines()`, `ensureWorld()` (reset → scenario → engines,
  cached until the next reset), `clearThrottles()`; `support/mailpit.js` reads mail
  by recipient and time (never deletes: Mailpit is shared with dev); admin login
  saved once by globalSetup to `tests/Browser/.auth/admin.json` (git-ignored);
  `distributorPage` logs in as `partnerKey` of the `world` option.
- **Verify (determinism):** `gsb-basic` → engines as of 2026-06-05 06:00, run twice —
  once from the base clock, once with the ambient clock moved to 2026-09-15 14:30.
  Checksums identical for gsb_cutoff_results, gsb_daily_pools, gsb_carryforward,
  wallet_ledger_entries, group_bv_daily/credits, bv_ledger_entries, orders,
  order_items, distributors, genealogy_closure, customers, repurchase_cycles;
  engine_runs identical apart from `duration_ms`; users identical apart from the
  bcrypt salt. The check caught one drift in the builder itself (the root's
  self-reference update took the ambient clock through `ON UPDATE
  CURRENT_TIMESTAMP`), fixed by pinning `updated_at`.
- Result of that run: root credited GSB slab 1 on the 3 June cut-off — gross
  ₹2,000, repurchase ₹200, net ₹1,800 (admin charge and TDS at payout).
- Known: Laravel log lines carry the real time (Monolog's own clock), not the
  frozen one. Harmless for assertions; noted for anyone reading e2e logs.
- Tests: `E2eScenarioBuilderTest` (3) + `E2eModeTest` (4) + `AdminLogTest` — 15 passed;
  Playwright `e2e-smoke` + `e2e-fixtures` 7/7; Pint + Larastan clean.

## Decisions (resolved 2026-11-10)
- **D1 — Standing consent for the e2e database. → YES.** `e2e:snapshot` (migrate + seed)
  and `e2e:reset` (restore) are destructive-class and run unattended before
  every money scenario. Consent would be scoped strictly to `arovolife_e2e`,
  with the target read from the live connection.
- **D2 — How the e2e app identifies itself. → A.** (A, recommended) keep `APP_ENV=local`
  and gate everything on `ARV_E2E=1` + `*_e2e` DB — no security allow-list changes,
  and the upload scanner, stub gateway and bank seeder work as on dev. (B) a new
  `APP_ENV=e2e` added to five allow-lists (scanner, stub gateway, secure
  cookies, bank seeder, console) — each a security-gate edit.
- **D3 — Frozen clock in shipped code. → YES.** Money fixtures only stay valid if the
  e2e app runs on a fixed date. That is a time-travel hook, active only under
  `ARV_E2E=1` + `*_e2e` DB.
- **D4 — Dummy AWS values instead of changing the S3 boot check. → YES.** Recommended:
  leave the PII check untouched and give the e2e container non-empty,
  unroutable values.

## Risks
- **Isolation (high):** shared code mount, `.env` with real staging keys, shared
  `bootstrap/cache` and `storage`. Covered by the cache paths, all-disk remap,
  internal network, `preventStrayRequests`, whoami preflight and the DB guard.
- **Recompute on dev (high):** dev `.env` allows `compensation:recompute-all` on
  `arovolife`, so Playwright must never call it — only `e2e:engines`.
- **Production code touched:** the env-gated provider and `e2e:*` commands
  (plus D2/D3 outcomes). Compliance-officer + security review before commit.
- **Oracle errors:** each fixture records its arithmetic; a mismatch is triaged
  against the plan doc before engine code is touched.
- **Duration:** a serial run of several hundred specs plus money scenarios will
  take a while; slices can run alone with `--project` / file filters.
- **Plan in flux:** the 2026-11-04 client updates are mid-build on this branch;
  fixtures follow the live engine and are versioned with it.

## Not in this plan
Awards & Rewards payout (on hold), staging/prod runs, CI wiring, visual regression.

### S2 additions (2026-11-10, later)

- **Base snapshot** now also runs `PublicPagesSeeder`, `AreteCenterSeeder` (company default centre) and `content:publish compensation`, as every real env has them. Without them `/p/management-team` 404'd, registration step 11 had no centre, and finalise refused to record consent.
- **Builder `staff(key, role)`**: a users-only back-office login (`<key>@e2e.test`, scenario password). `gsb-basic` and `many-partners` include `finance`, `operations`, `compliance`, `developer`. Fixture `loginAsStaff(page, builtWorld, key)` / `staffPage` + `staffKey` option.
- **Partner bank accounts** are real `PiiCrypter` ciphertext of `5000` + 8-digit seq (e.g. root `500000000001`), so payout lines release and the NEFT file prints them. Ciphertext is salted (random IV); it always decrypts to the same number.
- **`e2e:engines --weekly-batch=YYYY-MM-DD[,…] --approve-as=<email>`** builds weekly batches through the real `PayoutService::runWeeklyBatch` after the replay, then approves them. World option: `{ weeklyBatch, approveAs }`.
- Worked figure (exports T12): gsb-basic, batch Tue 16 Jun 2026 (earning week 3–9 Jun), root gross ₹2,000 − ₹200 repurchase (held at credit) − ₹60 admin (3%) − ₹87 TDS (5% of ₹1,740) = **₹1,653.00** net in the NEFT file.
- **`e2e:feature <FeatureClass> on|off`** (guarded like every e2e command) switches one Pennant flag globally on `arovolife_e2e`, including flags with no admin toggle (ActionCenterFeature). Specs use `withFeature('InventoryFeature', fn)` from `support/artisan.js`, which switches it back OFF afterwards so later tests keep the pinned set. Action Center (developer login), inventory dashboard panel and `/admin/announcements` filters now run instead of `fixme`.
- **Data guards tightened:** the legacy `test.skip(<no data>)` guards in admin-navigation, inventory-warehouse, order-fulfilment, policy-pages and profit-on-sales are assertions now — on a deterministic world missing data is a failure. `/p/compensation` is asserted published (it is on every env, and registration needs it).
- **Scenario data skips the validators.** `E2eScenarioBuilder` writes partners and paid orders with raw inserts (foreign-key checks off, no payment row), so the registration and order guards (free joining, cooling-off, one PAN per ADN) do not run when a world is built. Those guards are covered by the Pest suite, not by e2e. Commissions and payouts still come from the real engine replay.
