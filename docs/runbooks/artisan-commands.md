# Artisan Command Reference

Custom `php artisan` commands for the Arovolife platform.
All examples assume you are running inside Docker — prefix every command with:

```bash
docker compose -f docker/docker-compose.yml exec app php artisan <command>
```

---

## Compensation Engine

### `gsb:daily-cutoff`

Evaluates each active distributor's Genos BV for the day, determines which GSB slab (if any) they hit, and records the result in `gsb_cutoff_results`. Before the slab check, it injects the distributor's own day's personal order BV into their weaker Genos leg (`gsb_personal_bv_topups`).

**Scheduled:** Daily at **00:10 IST** — always processes the *previous* calendar day (the 10-minute buffer lets queued BV propagation jobs settle before the cut is made).

**Options:**

| Option | Description |
|---|---|
| `--date=YYYY-MM-DD` | Override the cut-off date. Default: yesterday (as used by the scheduler). |
| `--distributor=ID` | Run for a single distributor only. Skips the "later dates already advanced" guard — use for admin retries. |

**Examples:**

```bash
# Normal manual run for today (what a human ops trigger looks like)
php artisan gsb:daily-cutoff --date=2026-07-09

# Backfill a single distributor for a past date
php artisan gsb:daily-cutoff --date=2026-07-04 --distributor=59

# Re-run today for all distributors (idempotent: CREDITED rows are skipped)
php artisan gsb:daily-cutoff
```

**Admin UI:** `admin/compensation/daily-cutoffs` — view, export, retry failed rows, or manually reverse a credited row.

---

### `gsb:weekly-payout`

Aggregates all `CREDITED` GSB cut-off results since the last payout into a weekly payout batch, deducts admin charge (3 %, capped ₹25,000 per batch across four groups) and TDS (5 %), and credits each distributor's wallet.

**Scheduled:** Every **Tuesday at 09:00 IST**.

**Options:**

| Option | Description |
|---|---|
| `--date=YYYY-MM-DD` | Override the batch date. Default: today. |

**Examples:**

```bash
# Trigger the weekly payout manually (e.g. if the Tuesday scheduler missed)
php artisan gsb:weekly-payout

# Backfill a specific week
php artisan gsb:weekly-payout --date=2026-07-01
```

**Admin UI:** `admin/compensation/weekly-payouts` — approve pending batches, download NEFT file.

---

### `payout:monthly-run`

Runs all Group B/C/D monthly bonus engines in sequence: Growth Booster Bonus (GBB), Rank Bonus, Fortune Bonus, and ADC Bonus — then credits results to wallets. Each engine is idempotent; re-running for the same month skips already-processed rows.

**Scheduled:** 9th of each month at **10:30 IST** (after individual engines have run earlier on the 2nd, 8th, and 9th).

**Options:**

| Option | Description |
|---|---|
| `--month=YYYY-MM` | Override the target month. Default: current month. |

**Examples:**

```bash
# Trigger the monthly payout manually
php artisan payout:monthly-run

# Backfill a prior month
php artisan payout:monthly-run --month=2026-06
```

**Admin UI:** `admin/compensation/gbb`, `rank-bonus`, `fortune-bonus`, `adc-bonus` — view per-engine results.

---

### `repurchase:evaluate`

Evaluates each distributor's repurchase cycle for the current month and updates their income-eligibility flag (`income_eligible` on the distributor). Distributors who have not met their monthly repurchase BV threshold have GSB payouts held until the requirement is met.

**Scheduled:** Daily at **00:30 IST** — runs before the GSB cut-off (00:10 runs for yesterday, so this updates today's eligibility for use in tonight's cut-off).

**Options:**

| Option | Description |
|---|---|
| `--date=YYYY-MM-DD` | Override the as-of date. Default: today. |
| `--distributor=ID` | Evaluate a single distributor only. |

**Examples:**

```bash
# Re-evaluate eligibility for all distributors as of today
php artisan repurchase:evaluate

# Check a single distributor (useful after a manual order entry)
php artisan repurchase:evaluate --distributor=59

# Backfill to a past date
php artisan repurchase:evaluate --date=2026-07-01
```

---

## Compliance & Cooling-off

### `cooling-off:remind`

Sends the statutory D-20 / D-7 / D-1 cooling-off reminder emails (and SMS when configured) to every distributor within their 30-day cancellation window. Idempotent — safe to re-run; a reminder is never sent twice for the same distributor-day combination.

**Scheduled:** Daily at **09:00 IST**.

**No options.** Run it directly to trigger today's batch:

```bash
php artisan cooling-off:remind
```

**What it checks:** For every active distributor, computes days since their registration effective date. If that value equals 10 (D-20 from the D-30 deadline), 23 (D-7), or 29 (D-1) it queues the reminder notification.

---

### `contact-inquiries:purge`

Deletes old contact form submissions in compliance with DPDP Act §8(3) data-minimisation requirements.

**Options:**

| Option | Default | Description |
|---|---|---|
| `--unhandled-days=N` | 90 | Delete unhandled inquiries older than N days. |
| `--handled-days=N` | 365 | Delete handled inquiries older than N days. |

**Examples:**

```bash
# Standard retention cleanup (90 / 365 day defaults)
php artisan contact-inquiries:purge

# Stricter retention — keep handled inquiries for only 180 days
php artisan contact-inquiries:purge --handled-days=180
```

---

## Security & PII

### `pii:reencrypt`

Re-encrypts all distributor PII (PAN hash, Aadhaar reference, bank details) onto the current `PII_ENCRYPTION_KEY`. Run this after rotating `PII_ENCRYPTION_KEY` in `.env` to migrate existing ciphertext to the new key.

**Options:**

| Option | Description |
|---|---|
| `--force` | Run even when `PII_ENCRYPTION_KEY` is not set (rotates under `APP_KEY` — useful for testing). |

**Example:**

```bash
# After updating PII_ENCRYPTION_KEY in .env:
php artisan pii:reencrypt
```

> **Warning:** Run this once per key rotation. Running it multiple times is safe (idempotent) but unnecessary.

---

## Deployment

### `app:deploy`

Runs the full post-`git pull` deployment pipeline: Composer install, npm build, migrations, production seeder, cache rebuild, and queue restart. Wrap in `--maintenance` to put the site into maintenance mode during migrations.

**Options:**

| Option | Description |
|---|---|
| `--maintenance` | Wrap migrations in `artisan down` / `artisan up`. |
| `--skip-composer` | Skip `composer install --no-dev`. |
| `--skip-npm` | Skip `npm ci && npm run build`. |
| `--skip-migrate` | Skip `php artisan migrate --force`. |
| `--skip-seed` | Skip `php artisan db:seed ProductionSeeder`. |
| `--skip-cache` | Skip config/route/view/event cache rebuild. |
| `--skip-queue` | Skip `php artisan queue:restart`. |
| `--health-url=URL` | URL to GET as a final smoke test (200/30x = pass). |

**Examples:**

```bash
# Standard deployment after a git pull
php artisan app:deploy

# With maintenance mode + smoke test
php artisan app:deploy --maintenance --health-url=https://arovolife.in/health

# Assets-only redeployment (CSS/JS change, no migrations)
php artisan app:deploy --skip-migrate --skip-seed --skip-composer
```

See also: `docs/runbooks/cloudways-deployment.md` for the full Cloudways workflow.

---

## Admin / RBAC

### `permission:assign-role`

Assigns a Spatie permission role to a user by email. Use to grant or change admin access.

**Example:**

```bash
# Assign the admin-finance role
php artisan permission:assign-role admin@arovolife.in admin-finance

# View all roles and permissions
php artisan permission:show
```

Available roles: `admin-super`, `admin-finance`, `admin-compliance`, `admin-operations`.

---

## Development / Data Management

### `platform:reset`

**⚠️ Dev/staging only.** Wipes all transactional data (distributors, orders, BV, bonuses, wallet, KYC, consent, OTPs) and S3 KYC files, then re-seeds roles, the admin user, settings, content pages, feature flags, and the 31 reserved company-blocked ADNs.

Use this to start fresh after a test run or before demoing a clean registration flow.

```bash
php artisan platform:reset

# Skip the confirmation prompt (CI / scripted usage)
php artisan platform:reset --force
```

---

### `platform:reset-purchases`

**⚠️ Dev/staging only.** Wipes only purchase-derived data: orders, BV ledger, GSB/MB cut-offs, wallet ledger, payouts, and returns. Keeps users, distributors, the Genos tree, KYC, settings, plan configuration, and the product catalog intact.

Use this to replay compensation scenarios without losing the distributor tree.

```bash
php artisan platform:reset-purchases

# Skip confirmation
php artisan platform:reset-purchases --force
```

---

## Scheduled command summary

| Command | Schedule (IST) | Notes |
|---|---|---|
| `repurchase:evaluate` | Daily 00:30 | Must run before the GSB cut-off |
| `gsb:daily-cutoff` | Daily 00:10 (processes yesterday) | Core GSB engine |
| `cooling-off:remind` | Daily 09:00 | Statutory D-20/D-7/D-1 |
| `gsb:weekly-payout` | Tuesday 09:00 | Aggregates credited cut-offs |
| `payout:monthly-run` (GBB) | 2nd of month 08:00 | Growth Booster Bonus |
| `payout:monthly-run` (Rank) | 8th of month 08:00 | Rank Bonus |
| `payout:monthly-run` (ADC) | 8th of month 09:30 | ADC Bonus |
| `payout:monthly-run` (Fortune) | 9th of month 09:00 | Fortune Bonus |
| `payout:monthly-run` | 9th of month 10:30 | Credits wallet for B/C/D bonuses |
