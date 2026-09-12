# Local Development Environment — Reference for Claude Cloud

This describes **Preetham's local Mac dev setup** for the arovolife platform.
Claude Cloud does not have this Docker stack running — treat this as
background knowledge for reasoning about paths, ports, and gotchas that show
up in code, configs, and test fixtures, not as something to connect to.

## Repository layout

```
arovolife-code/                      ← repo root
├── CLAUDE.md                        ← project instructions (compliance rules, conventions)
├── Makefile                         ← dev task runner (make test/pint/stan/up/down/...)
├── app/                             ← the Laravel 13 / PHP 8.4 application
│   ├── app/Modules/                 ← modular monolith: Identity, Genealogy, Kyc, Consent,
│   │                                   Orientation, Commerce, Wallet, Compensation, Grievance,
│   │                                   Inventory, ActionCenter, Compliance, Admin, Analytics
│   ├── tests/                       ← Pest/PHPUnit, mirrors app/Modules/... structure
│   ├── phpstan-baseline.neon        ← Larastan baseline (must contain paths relative to
│   │                                   the analysis root — regenerate if paths look absolute
│   │                                   from a different environment)
│   ├── resources/help/*.md          ← admin help docs (keep in sync with feature changes)
│   ├── .ai/rules/                   ← Laravel Boost project rules (index.md maps globs → rules)
│   └── CLAUDE.md                    ← Laravel Boost guidelines (auto-generated, do not hand-edit)
├── docker/
│   └── docker-compose.yml           ← the whole local stack (see Services below)
├── docs/                            ← architecture, compliance, runbooks, roadmap
│   ├── roadmap.md                   ← canonical phase index
│   ├── compliance/                  ← DSR 2021 mapping, risk register, T&C
│   ├── architecture/                ← ADRs (closure table, placement strategy, data model, events)
│   └── runbooks/cloudways-deployment.md
├── placement-engine-spec/README.md  ← current sprint's walking-skeleton slice
└── .claude/
    ├── commands/                    ← /bootstrap-laravel, /compliance-check, /placement-test, etc.
    ├── agents/                      ← laravel-architect, compliance-officer, qa-engineer, security-auditor
    └── skills/                      ← arovolife-placement-engine, arovolife-compliance-rules,
                                        arovolife-compensation-plan, arovolife-ux-writing
```

**Working directory convention:** most `make`/`docker exec` commands assume
you're at the repo root (`arovolife-code/`), not inside `app/`. Vite/npm
commands run from `app/` (see Makefile `NPM_DIR := app`).

## Docker stack (`docker/docker-compose.yml`)

| Service | Container name | Host port | Notes |
|---|---|---|---|
| app (PHP-FPM) | `arovolife-app` | — | working_dir `/var/www/html`; all `docker exec arovolife-app ...` commands target this |
| web (nginx) | `arovolife-web` | **8084** | app served at `http://localhost:8084` — **use http, not https** (no TLS cert bound) |
| db (MySQL 8.0) | `arovolife-db` | **3307** | user `arovolife` / pass `secret`; dev DB name `arovolife` |
| redis | `arovolife-redis` | 6379 | cache + sessions only — queues are DB-driven, never Redis (ADR-0011) |
| mailpit | `arovolife-mailpit` | SMTP 1027→1025, UI **8027** | catches all local outbound mail |
| adminer | `arovolife-adminer` | **8083** | DB browser UI |
| queue | (worker container) | — | drains `otp`/`default`/`compensation` queues |

Start/stop from repo root:
```
make up      # docker compose -f docker/docker-compose.yml up -d
make down    # docker compose down (volumes preserved)
make logs    # tail app container logs
make sh      # shell into arovolife-app
```

## Local login credentials (dev-only, not secrets)

| Field | Value |
|---|---|
| URL | `http://localhost:8084/login` |
| Admin email | `admin@arovolife.test` |
| Admin password | `admin12345` |
| Login field | email (admin users have no ADN/distributor record) |

Local bank details for distributor payout testing are seeded via
`php artisan db:seed --class=DevBankAccountSeeder` (never hand-write plaintext
into `bank_account_enc` — it's `PiiCrypter` ciphertext; raw SQL breaks payout
decryption for every row it touches).

## ⚠️ Critical: test database isolation

**The single most important local-dev gotcha.** `docker-compose.yml` injects
`DB_DATABASE=arovolife`, `DB_HOST=db`, `QUEUE_CONNECTION=database`,
`APP_ENV=local` as **container OS-level environment variables**. These
override BOTH `phpunit.xml`'s `force="true"` directives AND `.env.testing`.

This wiped the dev database twice via a bare `php artisan test` +
`RefreshDatabase` before a guard was added. **Never run:**
- `php artisan test` bare inside the container without DB overrides
- `migrate:fresh`, `migrate:refresh`, `db:wipe` against the `arovolife` connection

**Always run tests with explicit overrides**, targeting the separate
`arovolife_test` database:
```
docker exec -e DB_CONNECTION=mysql -e DB_DATABASE=arovolife_test -e DB_HOST=db \
  -e DB_PORT=3306 -e DB_USERNAME=arovolife -e DB_PASSWORD=secret \
  arovolife-app php artisan test --compact
```
Or scoped to one file/filter: add `--filter=TestName tests/Path/To/Test.php`.

Note: the repo-root `Makefile`'s `test` target (`docker exec arovolife-app
php artisan test`) does **not** include these overrides — its comment
("SQLite :memory:, dev DB untouched") is misleading; it actually needs the
`-e` flags above to be safe. Don't rely on bare `make test`.

`tests/TestCase.php::createApplication()` has a hard guard: it throws a
`RuntimeException` unless the resolved database is `:memory:` or ends in
`_test`. Do not remove this guard. It also force-pins `queue.default=sync`
and `app.env=testing` for the same OS-env-override reason (see the file's
own doc comment for why).

Tests run on MySQL (`arovolife_test`), not SQLite — some migrations use
MySQL-specific `ALTER ... MODIFY ... ENUM` syntax that isn't SQLite-portable.
Always verify a new/changed test against `arovolife_test`, never only SQLite,
since SQLite silently tolerates things MySQL enforces (column widths, strict
enum values, DDL-inside-transaction semantics).

## Frontend build (Tailwind v4 + Vite)

No dev server runs by default. Any **new Tailwind utility class combination**
requires a rebuild — `php artisan view:clear` alone is NOT enough for
Tailwind changes (it IS enough for pure Blade markup/structure changes and
plain custom CSS):
```
cd app && npm run build     # or `make build` from repo root
```
`npm run dev` starts Vite with HMR for active frontend work.

## Common make targets (from repo root)

| Target | Command | Notes |
|---|---|---|
| `make pint` | `docker exec arovolife-app ./vendor/bin/pint` | PSR-12 lint/fix |
| `make stan` | `docker exec arovolife-app ./vendor/bin/phpstan analyse --level=7` | Larastan |
| `make test` | `docker exec arovolife-app php artisan test` | ⚠️ missing DB overrides, see above — don't use bare |
| `make build` | `cd app && npm run build` | rebuild Tailwind/Vite assets |
| `make migrate` | `docker exec arovolife-app php artisan migrate` | dev MySQL, forward-only |
| `make reset` | `docker exec arovolife-app php artisan platform:reset` | wipes transactional data, rebuilds 31 reserved distributors — interactive/destructive |
| `make tinker` | `docker exec arovolife-app php artisan tinker` | REPL against dev DB |

## Playwright / browser testing

Browser specs live under `app/tests/Browser/*.spec.js` (Playwright), run via
`npx playwright test tests/Browser/<file>.spec.js` from `app/`. For live UI
verification, `http://localhost:8084/admin` in a real browser — must be
`http://`, not `https://` (port 8084 has no valid TLS cert).

## Staging (Cloudways) — separate from local

Not part of "local dev," but frequently confused with it:
- Staging app: `arovolife-staging` (app_id `6390605`, sys_user `ahdhesuhty`),
  server `1611779` (`139.59.92.229`), FQDN
  `phplaravel-1611779-6390605.cloudwaysapps.com`.
- Staging DB is Cloudways-local MySQL (RDS retired 2026-08-29) — never the
  same database as local `arovolife`/`arovolife_test`.
- Deploy is `git pull` only (via Cloudways MCP `git_pull` or SSH) — does NOT
  run composer/npm/migrate/cache-clear automatically. New routes, schema
  changes, composer/package changes, or baked config caches all need a
  manual follow-up step over SSH. Full detail in
  `docs/runbooks/cloudways-deployment.md`.
- Full details (SSH access, Node/Vite build-on-server steps, Redis ACL
  caveats, `CACHE_STORE` vs `CACHE_DRIVER` naming trap) are out of scope for
  "local" work — see `docs/runbooks/cloudways-deployment.md` before touching
  staging.

## Key non-obvious traps worth knowing before editing code here

- **Never store raw Aadhaar**; PAN is hash + last-4 (`PiiCrypter`). Bank
  account numbers are also `PiiCrypter` ciphertext — never raw SQL.
- **Queues are database-driven everywhere, permanently** — Redis was
  evaluated and explicitly rejected for queues (ADR-0011); don't reintroduce
  Redis queueing.
- **Closure table for the Genos (binary placement tree)** — do not
  reintroduce nested-set/recursive-CTE without a new ADR
  (`docs/architecture/adr-0001-closure-table.md`).
- **All compensation plan parameters are DB-driven** (`gsb_slabs`,
  `compensation_plan_settings`), never hardcoded constants.
- Every admin action / KYC change / settings change needs an `audit_log`
  entry with before/after hashes; `audit_log.digest` is `BINARY(32)` — always
  write raw bytes from `AuditLog::digest()`, never hex (SQLite tests won't
  catch a hex-vs-binary mismatch; verify on MySQL).
- Indian number formatting goes through `IndianNumber::format()` /
  `@bv` Blade directive everywhere in the UI — never `Number::format()`
  (ICU 78 killed `en_IN` lakh grouping).
