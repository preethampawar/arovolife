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

**⚠️ Month-end batch dates.** The repurchase deduction is a percentage of the
**prior** month's bonus credits, so the batch date decides which window is
summed. A batch dated on a day that does not exist one month earlier used to
overflow into the current month and deduct repurchase against the very credits
being paid out — a silent under-payment, no error. Fixed 2026-07-31 (`41ce71d`,
`PayoutService::repurchaseDeductionPaise()`), with a regression test pinned to a
month-end. The dates that exercise this path:

| Day of month | Overflows in | Why |
|---|---|---|
| 31st | Mar, May, Jul, Oct, Dec | The preceding month has 30 or fewer days. Jan 31 and Aug 31 are safe — Dec and Jul both have 31. |
| 30th | Mar only | February is the only month shorter than 30 days. |
| 29th | Mar, non-leap years only | Safe in leap years (2028, 2032, …). |

The scheduler runs `weeklyOn(2)`, so this only lands when a **Tuesday** falls on
one of those dates. Verified clean on dev and staging as of 2026-07-31 (no batch
ever ran on an affected date; total repurchase deducted across all line items was
₹0). Re-run the check against production before go-live, and after any batch
dated one of the above:

```sql
SELECT b.batch_date, COUNT(li.id) AS line_count,
       COALESCE(SUM(li.repurchase_deduction_paise), 0) AS repurchase_paise
FROM payout_batches b
LEFT JOIN payout_line_items li ON li.payout_batch_id = b.id
WHERE DAY(b.batch_date) > DAY(LAST_DAY(DATE_SUB(DATE_FORMAT(b.batch_date, '%Y-%m-01'), INTERVAL 1 MONTH)))
GROUP BY b.id, b.batch_date;
```

Zero rows means no batch ever ran on an affected date. Any row returned predates
the fix and needs a finance decision on remediation — the fix is forward-only and
does not correct historical line items.

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

**Note.** The GBB, Rank, Fortune and ADC commands derived their target month the
same overflow-prone way and were fixed in the same commit. They are scheduled on
the 2nd/8th/9th so the scheduler never hit it, but a **manual** run on an
affected date (see the table under `gsb:weekly-payout`) would have processed the
wrong month. Always pass `--month=` explicitly when running these by hand.

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

Sends the statutory D-7 / D-1 cooling-off reminder emails (and SMS when configured) to every distributor within their 30-day cancellation window. Idempotent — safe to re-run; a reminder is never sent twice for the same distributor-day combination.

**Scheduled:** Daily at **09:00 IST**.

**No options.** Run it directly to trigger today's batch:

```bash
php artisan cooling-off:remind
```

**What it checks:** For every active distributor, computes days remaining until `cooling_off_end_at`. At 7 days remaining (D-7) and 1 day remaining (D-1) it queues the reminder notification. A milestone reached during a cron outage is caught up on the next run. The earlier D-20 milestone was retired on 2026-08-16 — two reminders, not three.

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

**Not the same as [`compensation:recompute-all`](#compensationrecompute-all).**
This one deletes the orders too — you start selling again from nothing. That one
keeps every order and BV row and recomputes the same history. Both take their
compensation table list from the single `DerivedTables` registry, so neither can
drift out of date when a new bonus table is added.

---

### `compensation:recompute-all`

> **⚠️ TESTING ONLY — scheduled for deletion.** This exists so the compensation
> plan can be validated end to end against live data. Once the client signs off,
> it is removed and the engines go back to freeze-once for good. See the revert
> checklist at the end of this section.

Wipes **every row computed from BV** and replays all engines from the first BV
date to yesterday, in the order the scheduler would have run them.

**What it destroys:** bonus results (GSB, MSB, GBB, Rank, Fortune, ADC), every
frozen pool, carry-forwards, personal-BV top-ups, rank qualifications and AO/GO
grants, lifetime-award milestones, repurchase cycles, the group-BV projection,
wallet credits, payout batches and the engine-run log.

**What it keeps:** orders, the BV ledger, distributors, users, the Genos tree,
sponsorship, KYC, consents, settings and all plan configuration (`gsb_slabs`,
`rank_tiers`, Fortune levels/tiers, lifetime award rewards).

**Why it has to exist.** Every engine is write-once by design: a period's pool,
denominator and point value are frozen before the first credit and never
recomputed, so nobody's rate can move after they were paid. That is right for
production and wrong for testing — it means a single mistaken run permanently
fixes a month's economics.

```bash
# Enable it first — it refuses without this, and always refuses in production
COMP_RECOMPUTE_ENABLED=true

php artisan compensation:recompute-all              # confirms, naming the target DB
php artisan compensation:recompute-all --force      # scripted, no prompt
php artisan compensation:recompute-all --from=2026-07-01 --to=2026-07-31
```

There is also a button on **Admin → Compensation → Engine Runs**, visible to
`admin` and `developer`, which queues the same work in the background. It is
rendered only when the gate is open, so on any environment where the flag is
unset there is no trace of it. The `queue` container must be running or the
click appears to do nothing.

**Live progress.** Once a run starts, the page shows a progress bar that polls
every two seconds: the current phase, percentage, which date is being replayed,
the engines firing on that date, days done / total, orders re-propagated and a
running engine-run count. It ends on a green summary or a red failure with the
error. The bar is driven by a single cache key
(`compensation:recompute:progress`), not the database — the replay truncates
`engine_runs` as its first act, so progress cannot be stored anywhere it would
wipe.

Reading it from the CLI while a run is in flight:

```bash
php artisan tinker --execute 'echo json_encode(Cache::get("compensation:recompute:progress"), JSON_PRETTY_PRINT);'
```

**Restart the queue worker after touching this code.** The worker holds the
application in memory, so a running worker replays with whatever version of the
runner it booted with. The symptom is specific and misleading: the bar sits on
"Queued", the worker log shows `RecomputeAllJob ... DONE`, and the data *is*
rebuilt — only the progress writes came from stale code.

```bash
docker compose -f docker/docker-compose.yml restart queue
```

If the bar sits on "Queued" and the worker log shows nothing at all, the worker
is simply down — start it, and the queued job runs.

**The schedulers are unaffected.** `routes/console.php` is untouched: the daily
00:10 cut-off, the Tuesday payout and the 2nd/8th/9th monthly runs keep running
normally and each still freezes its period. This command is the only thing that
throws those snapshots away.

#### What it cannot reproduce faithfully

1. **Today's tree, today's plan.** Placement and every plan setting are read
   live, not as of the historical date. The replay answers "what would the
   *current* plan pay over this history" — it will not reproduce the original
   runs if the plan or the tree has changed since.
2. **Cut-offs with no BV behind them vanish.** The replay window starts at the
   first BV date; any older cut-off rows are deleted and not regenerated. On the
   dev database this removed a month of June rows that had no BV behind them.
3. **Repurchase history is obligation-periods, not calendar months** — the
   rebuild rolls forward through completed cycles and parks on the first missed
   one.
4. **Wallet credits are deleted, not reversed.** Any figure a distributor has
   already seen can change.

Timestamps are back-dated during the replay (`Carbon::setTestNow` per replayed
day) so the monthly income cap and repurchase deduction, which window on
`wallet_ledger_entries.created_at`, fall in the right months. Outbound mail and
notifications are muted for the duration; domain events still fire, because
listeners like `ReleaseHeldGsbOnReactivation` are part of a correct
recomputation.

#### Reverting it after sign-off

1. Delete `app/Modules/Compensation/Services/Recompute/`,
   `Services/DTOs/RecomputeReport.php`, `Jobs/RecomputeAllJob.php`,
   `Console/Commands/CompensationRecomputeAllCommand.php` and
   `tests/Modules/Compensation/CompensationRecomputeTest.php`
2. Remove the `recompute` block from `config/arovolife.php`, the
   `COMP_RECOMPUTE_ENABLED` line from `.env.example` and every `.env`, and the
   command from `AppServiceProvider`
3. Remove the `recompute-all` route, `AdminEngineRunsController::recomputeAll()`,
   the danger-zone card in the Engine Runs view, and the recompute tests in
   `AdminEngineRunsControllerTest`
4. Remove the `notEngines` exclusion in `EngineRegistryTest`
5. **Keep** `Support/DerivedTables.php` and the `EngineCadence` work — those are
   genuine single-source fixes, not part of the testing scaffold
6. Delete this runbook section

---

## Scheduled command summary

| Command | Schedule (IST) | Notes |
|---|---|---|
| `repurchase:evaluate` | Daily 00:30 | Must run before the GSB cut-off |
| `gsb:daily-cutoff` | Daily 00:10 (processes yesterday) | Core GSB engine |
| `cooling-off:remind` | Daily 09:00 | Statutory D-7/D-1 |
| `gsb:weekly-payout` | Tuesday 09:00 | Aggregates credited cut-offs |
| `payout:monthly-run` (GBB) | 2nd of month 08:00 | Growth Booster Bonus |
| `payout:monthly-run` (Rank) | 8th of month 08:00 | Rank Bonus |
| `payout:monthly-run` (ADC) | 8th of month 09:30 | ADC Bonus |
| `payout:monthly-run` (Fortune) | 9th of month 09:00 | Fortune Bonus |
| `payout:monthly-run` | 9th of month 10:30 | Credits wallet for B/C/D bonuses |
