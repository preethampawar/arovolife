# Fresh install & reset — arovolife platform

This runbook covers two operations:

1. **Fresh install** — bringing the platform up from a clean clone (local
   Docker dev, or any new environment).
2. **Reset to defaults** — wiping all test data and rebuilding the canonical
   bootstrap state (admin, roles, settings, content, ledger, feature flags,
   31 reserved company-blocked distributors).

For Cloudways-specific production deployment see
[`cloudways-deployment.md`](./cloudways-deployment.md).

---

## Part 1 — Fresh install (local Docker dev)

### Prerequisites

| Tool | Purpose | Check |
|---|---|---|
| Docker Desktop 4.30+ (or Docker Engine + Compose v2) | Runs the full stack (app, db, redis, queue, web, scheduler, mailpit, adminer) | `docker version` |
| Git | Cloning the repo | `git --version` |
| Node 20+ & npm 10+ | Builds the Vite/Tailwind assets (host-side, so `make build` doesn't need a node container) | `node -v && npm -v` |
| Make | Convenience wrapper around the most-used commands | `make --version` |
| ~6 GB free RAM | MySQL + Redis + PHP-FPM + queue + scheduler | `free -h` (Linux) or Docker Desktop's resource setting |

### Step-by-step

```bash
# 1. Clone
git clone git@github.com:preethampawar/arovolife.git
cd arovolife

# 2. Environment file
cp app/.env.example app/.env
# Edit app/.env if you need to override APP_KEY, mail driver, S3 bucket,
# etc. The defaults work for Docker dev (db host = "db", redis host =
# "redis", mailpit on port 1027/8027).

# 3. Start the stack (app, db, redis, queue, scheduler, web, mailpit, adminer)
make up
# Equivalent to: docker compose -f docker/docker-compose.yml up -d
# Wait ~20 seconds for MySQL's healthcheck to pass on first run.

# 4. Install PHP dependencies (inside the app container)
docker exec arovolife-app composer install --no-interaction --prefer-dist

# 5. Generate an APP_KEY if you didn't paste one into .env
docker exec arovolife-app php artisan key:generate

# 6. Run migrations (creates ~40 tables across all modules)
make migrate
# Equivalent to: docker exec arovolife-app php artisan migrate

# 7. Install Node deps + build front-end assets
cd app && npm install && cd ..
make build
# Equivalent to: (cd app && npm run build)

# 8. Seed the canonical bootstrap state and the 31 reserved distributors
make reset-force
# Equivalent to: docker exec arovolife-app php artisan platform:reset --force
# This wipes any pre-existing test data and re-seeds everything in one shot.
```

### What you have after step 8

| Tab | URL | Purpose |
|---|---|---|
| App | `http://localhost:8084` | Public landing + distributor view |
| Admin | `http://localhost:8084/admin` | Admin console — login below |
| Mailpit | `http://localhost:8027` | Captures all outbound mail in dev |
| Adminer (DB) | `http://localhost:8083` | server: `db`, user: `arovolife`, password: `secret`, db: `arovolife` |

**Default admin credentials** (dev only):
- Email: `admin@arovolife.test`
- Password: `admin12345`

The admin user is re-created by `AdminUserSeeder` on every `platform:reset`,
so the password above is always the canonical dev credential.

**Reserved distributor tree**: 31 nodes (root `100000000` + 30 fixed
deterministic ADNs from `App\Modules\Genealogy\Support\ReservedAdns`),
all owned by 31 users with `full_name = "Arovolife Private Limited"` and
`password_set_at = NULL` (locked out — they exist only to block tree slots).

### Verifying the install

```bash
# Tests should pass on SQLite :memory: without touching the dev DB
make test                # expect: 187 passed, 1 skipped, 0 failed

# Larastan at level 7 must be clean
make stan                # expect: [OK] No errors

# Distributor count sanity check
docker exec arovolife-app php artisan tinker --execute=\
"echo 'distributors: '.DB::table('distributors')->count().PHP_EOL;"
# expect: distributors: 31
```

### Common first-run snags

| Symptom | Fix |
|---|---|
| `Cannot connect to MySQL server` on first migrate | Wait 20 s for the `db` container healthcheck. `docker compose -f docker/docker-compose.yml ps` should show `arovolife-db (healthy)`. |
| `APP_KEY` errors | `docker exec arovolife-app php artisan key:generate` |
| Sidebar overlaps content on `/admin/*` | You haven't run `make build` yet — Tailwind classes used by recent commits aren't in `public/build/`. Run `make build` and hard-refresh. |
| `Class "Laravel\\Pennant\\Feature" not found` | `docker exec arovolife-app composer install` (Pennant is a real dependency; missing if step 4 was skipped) |
| Login shows "These credentials do not match our records" with admin12345 | `password_set_at` is null on the admin user — run `make reset-force` to re-seed via `AdminUserSeeder` (which sets `password_set_at = now()`). |

---

## Part 2 — Reset DB to defaults

The `platform:reset` Artisan command is the single source of truth for the
canonical bootstrap state. It is **idempotent** — running it twice yields the
identical post-state because every step truncates first and the reserved
ADNs are deterministic.

### What it does (in order)

1. **S3 cleanup** — enumerates every `kyc_documents.object_storage_key`,
   extracts distinct `user_<id>/` prefixes, and calls
   `Storage::disk('s3')->deleteDirectory()` on each. **Allowlisted to
   `^user_\d+$`** so a corrupt key cannot trigger a deletion outside the
   expected namespace.
2. **Table truncation** (FK checks toggled off and back on around the loop):
   - Transactional leaves: `consents`, `orientation_views`,
     `cooling_off_events`, `kyc_documents`, `registration_drafts`,
     `line_change_requests`
   - Tree + main: `genealogy_closure`, `distributors`, `sponsorship`
   - Audit: `audit_log`
   - Spatie role/permission assignments: `model_has_roles`,
     `model_has_permissions`, `role_has_permissions`, `roles`, `permissions`
   - Users: `password_reset_tokens`, `sessions`, `users`
3. **Re-seed platform metadata** — runs (idempotently) `AdminUserSeeder`,
   `SettingsSeeder`, `ContentPageSeeder`, `LedgerAccountSeeder`,
   `CommerceFeatureFlagSeeder`, `ProductCatalogSeeder`.
4. **Reserved distributor tree** — inserts 31 users + 31 distributor rows in
   a complete 5-level binary tree (1 + 2 + 4 + 8 + 16 = 31), populates
   `genealogy_closure` (129 rows = 31 self + 98 ancestor edges), sets the
   root's self-references, and copies each parent's id into its children's
   `sponsor_id` and `placement_parent_id`.
5. **Audit log** — writes a single `platform.reset` row recording the
   action.

### How to run

```bash
# Interactive (asks for y/n confirmation)
make reset

# Skip the prompt — use in scripts / CI
make reset-force
```

Both are wrappers around:
```bash
docker exec arovolife-app php artisan platform:reset [--force]
```

### Verifying the reset

```bash
docker exec arovolife-app php artisan tinker --execute="
echo 'distributors:        '.DB::table('distributors')->count().PHP_EOL;
echo 'users (incl admin):  '.DB::table('users')->count().PHP_EOL;
echo 'genealogy_closure:   '.DB::table('genealogy_closure')->count().PHP_EOL;
echo 'audit_log:           '.DB::table('audit_log')->count().PHP_EOL;
echo 'root adn:            '.DB::table('distributors')->where('depth',0)->value('adn').PHP_EOL;
"
```

Expected output:
```
distributors:        31
users (incl admin):  32
genealogy_closure:   129
audit_log:           1
root adn:            100000000
```

### When to run it

| Scenario | Use `platform:reset` |
|---|---|
| New developer joining the team | Yes — after `make up` + `make migrate` + `make build` |
| Demo / UAT environment, want a clean slate | Yes |
| Local DB feels broken or has weird test data | Yes |
| Production | **No.** Production uses `ProductionSeeder` and never wipes users / distributors. |
| Running automated tests | No — tests use SQLite `:memory:` and don't touch the dev DB |

### What it does NOT touch

- **Schema migrations** (`schema_migrations` table) — schema is durable; only
  data rows are wiped.
- **Pennant `features` table** — feature-flag overrides persist across
  resets. If you want to also clear them, run
  `docker exec arovolife-app php artisan tinker --execute="DB::table('features')->truncate();"` after the reset.
- **The 31 reserved ADNs themselves** — they are hard-coded in
  `App\Modules\Genealogy\Support\ReservedAdns` and stable across resets so
  external docs / dashboards that reference these IDs stay valid.

---

## Part 3 — Production deployment

This runbook covers the local-dev and reset paths only. For production:

1. **Cloudways prod**: follow [`cloudways-deployment.md`](./cloudways-deployment.md). Critically:
   - Use `ProductionSeeder` (not `platform:reset`) on first deploy. It reads
     `PROD_ADMIN_EMAIL` and `PROD_ADMIN_PASSWORD` from env so credentials
     never live in the repo.
   - **Never** run `platform:reset` in production — it wipes `audit_log`,
     `consents`, `cooling_off_events`, and `distributors`, all of which are
     statutorily required under DSR 2021 Rule 5.
2. **Asset builds** are gitignored under `app/public/build/`. Run `make build`
   on the deployment host (or build artifacts in CI and ship them as part
   of the release).
3. **Pennant flags** carry over per environment. Use
   `php artisan pennant:activate <key>` / `:deactivate` or the admin UI at
   `/admin/feature-flags` to flip them.

---

## Reference — the Makefile cheat sheet

Run `make help` from the project root for the full list. Most common:

```
make build         Rebuild Tailwind/Vite assets (run after Blade class changes)
make dev           Start Vite dev server with HMR
make reset         Interactive platform:reset
make reset-force   platform:reset --force (no prompt)
make migrate       Run pending Laravel migrations
make test          Pest suite on SQLite :memory:
make stan          Larastan level 7
make up / down     Start / stop docker stack
make logs          Tail app container logs
make sh            Shell inside arovolife-app
make tinker        Laravel REPL against the dev DB
```
