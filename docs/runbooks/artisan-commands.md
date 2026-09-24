# Artisan Command Reference

Custom `php artisan` commands for the Arovolife platform.
All examples assume you are running inside Docker — prefix every command with:

```bash
docker compose -f docker/docker-compose.yml exec app php artisan <command>
```

> **Something has already failed?** This file is the reference for what each
> command does. [`engine-failure-triage.md`](engine-failure-triage.md) is the
> decision tree for *which* one to run: symptom → cause → command, covering a
> skipped night, a stuck run, a missing or stranded payout batch, a month that
> will not close, and the payout holds that are not failures at all.

---

## Compensation Engine

### `gsb:daily-cutoff`

Evaluates each active distributor's Genos BV for the day, determines which GSB slab (if any) they hit, and records the result in `gsb_cutoff_results`. Before the slab check, it injects the distributor's own day's personal order BV into their weaker Genos leg (`gsb_personal_bv_topups`).

**Scheduled:** step 2 of the Nightly Run (`compensation:nightly-run`, 00:05 IST) — always processes the *previous* calendar day (the few minutes of buffer let queued BV propagation jobs settle before the cut is made). A night the Nightly Run missed is cut off by the next night that runs, oldest first.

**Options:**

| Option | Description |
|---|---|
| `--date=YYYY-MM-DD` | Override the cut-off date. Default: yesterday (as used by the scheduler). |
| `--distributor=ID` | Run for a single distributor only. Skips the "later dates already advanced" guard — use for admin retries. |

**Examples:**

```bash
# Normal manual run for a CLOSED day (yesterday or earlier)
php artisan gsb:daily-cutoff --date=2026-07-09

# Backfill a single distributor for a past date
php artisan gsb:daily-cutoff --date=2026-07-04 --distributor=59
```

> ⚠️ **Never run the full cut-off for a day still in flight** (today, or the
> bare no-`--date` form, which defaults to today). The run freezes the day's
> GSB and MSB pool economics permanently at that instant — on staging
> (24 Aug 2026) a 23:27 manual run froze the day at ₹0 company BV / cap score
> value before the evening's orders landed, and the next scheduled run then
> paid the day's real achievers out of the empty pool. The admin Engine Runs
> page refuses in-flight dates for exactly this reason; the CLI cannot
> (the recompute replay legitimately runs under a back-dated clock). A
> premature pool that nothing was credited against is self-healed by the next
> full run (`gsb.pool.refrozen` / `msb.pool.refrozen` audit entries); once
> money moved against it, only a windowed recompute repairs the day.

**Admin UI:** `admin/compensation/daily-cutoffs` — view, export, retry failed rows, or manually reverse a credited row.

---

### `gsb:weekly-payout`

Aggregates all `CREDITED` GSB cut-off results since the last payout into a weekly payout batch, deducts admin charge (3 %, capped ₹25,000 per batch across four groups) and TDS (5 %), and credits each distributor's wallet.

**Scheduled:** Tuesdays, by its own Weekly Run (`compensation:weekly-run`, 03:00 IST), once that night's Nightly Run has succeeded. A Tuesday whose batch was never built is retried on the next night, still dated that Tuesday, so the earning week it pays is unchanged.

**Options:**

| Option | Description |
|---|---|
| `--date=YYYY-MM-DD` | Override the batch date. Default: today. Must be a **Tuesday**: the batch dated Tuesday T pays the Wednesday–Tuesday week that closed on T − 7, and a batch dated any other day splits a week. |
| `--force` | Run for a batch date that is not a Tuesday (deliberately off-cycle only). |

**Examples:**

```bash
# Trigger the weekly payout manually (e.g. if the Tuesday scheduler missed)
php artisan gsb:weekly-payout

# Backfill a specific week — the date is the Tuesday the batch was due
php artisan gsb:weekly-payout --date=2026-06-30
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

**Scheduled:** not directly. `compensation:monthly-payout-close` invokes it on the **8th**, as the Monthly Run's payout phase, and only once every crediting engine for the closed month has succeeded. If the 8th is missed the Monthly Run retries on later nights while no batch exists.

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

**Scheduled:** step 1 of the Nightly Run (`compensation:nightly-run`, 00:05 IST) — it runs immediately before the GSB cut-off, which processes yesterday, so a run dated today has seen the whole of the day being cut off.

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

### `compensation:engine-health-digest`

Builds the daily engine-health report and emails it to the mailbox in the `notifications.engine_health_email` setting — **only when something needs a human**. A healthy day sends nothing, so an email arriving is itself the signal. A blank or invalid setting value turns the digest off (the gate and the address are the same setting).

It reports three things, each with the numbered steps that close it:

1. **Failed runs** — `engine_runs` rows with `status = failed` in the last 30 days with no later succeeded run for the same engine and period (the same rule as the admin sidebar badge).
2. **Scheduled runs that did not happen** — for every scheduled, non-orchestrator engine, the period its most recent fire instant should have produced, with no run recorded for it in any status but `failed`.
3. **Stuck runs** — `status = running` for more than 3 hours: the compensation worker died mid-run.

**Scheduled:** Daily at **08:00 IST** — hours after the Nightly Run starts at 00:05, so an ordinary night has long finished.

**Options:**

| Option | Description |
|---|---|
| `--always` | Send even when every engine is healthy. The way to test that the mailbox works. |
| `--dry-run` | Print the report to the console and send nothing. |

**Examples:**

```bash
# What would the digest say right now?
php artisan compensation:engine-health-digest --dry-run

# Prove the mailbox receives mail (sends even on a healthy day)
php artisan compensation:engine-health-digest --always
```

The recipient is set per environment by the settings owner under Settings → Notifications; no address lives in code or in a seeder.

#### Developer actions the digest asks for

The digest is written for an admin, and it deliberately hands three things to a developer instead:

**Restart the compensation worker** (a run stuck in `running`):

```bash
php artisan queue:restart          # workers exit after the current job and are respawned by Supervisor
```

**Create a missed weekly batch** — only when the following Tuesday also produced none:

```bash
php artisan gsb:weekly-payout --date=2026-09-15   # the batch date (a Tuesday)
```

**Re-run the monthly payment close** for a crediting month:

```bash
php artisan compensation:monthly-payout-close --month=2026-08
```

**Reopen a payout batch stranded in `processing`.** A sweep killed outright —
OOM, the queue job's hour-long timeout, SIGKILL — never reaches the catch block
that writes `failed`, so the row keeps saying `processing`, and `processing` is
closed to re-entry. Nothing re-enters it on its own: the Weekly and Monthly
runs prove a Tuesday or a month from the existence of a batch, not from its
status, so they move on. This is the command that unsticks it, and `failed`
is then recoverable by the ordinary re-run above, or by a developer rebuild
(`compensation:rebuild-week` / `compensation:rebuild-payout`).

```bash
php artisan payout:reopen-stuck-batch --type=weekly --date=2026-09-15 --actor=<user id>
php artisan payout:reopen-stuck-batch --type=monthly --date=2026-08-01 --actor=<user id>
```

It refuses on two independent grounds. First, if a payout sweep holds the lock —
taken, never waited for. Second, if anything has been written for the batch in
the last two hours. **That second check reads the batch's line items as well as
the batch row, and takes whichever is later.** The batch row is written exactly twice per sweep, `processing` at
the start and its totals at the end, so `updated_at` says when the sweep
*started*: a `gsb:weekly-payout` you typed by hand has no queue timeout, the lock
self-expires after an hour, and at ten lakh the sweep can run for longer than
both. The per-distributor line item is what proves it is still alive.

`--actor=<user id>` is **required** and must name someone holding `finance.record`, and it is not bookkeeping. Whoever decides a
batch is owed again is its maker, and this is the only place that can be
recorded: the re-run afterwards is a separate process with no session and no
attributed run context, so it stamps `created_by` NULL and the batch comes back
approvable by whoever reopened it. The command records the actor as the batch's
maker when it has none, and never overwrites one it already has — the first hand
on a batch stays the one barred from approving it.

It moves no money and recomputes nothing: one status column and one audit row,
written in a single transaction so a batch can never be reopened without the
record of it.

A batch in this state is surfaced in the Action Center under Money as *Payout
batches stuck mid-sweep* after two hours, so it does not depend on someone
thinking to look.

**Mark a dead run failed.** There is no command for this: a `running` row is only ever closed by the process that opened it, and `RunEngineChainJob::abortStaleWorker()` closes one only when a new chain run finds it stale. If a row must be closed by hand so the engine can be re-triggered, do exactly one row and nothing broader:

```sql
UPDATE engine_runs
   SET status = 'failed',
       error = 'marked failed by developer: worker died mid-run',
       finished_at = NOW()
 WHERE id = <id> AND status = 'running';
```

---

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

Runs the full post-`git pull` deployment pipeline: Composer install, npm build, migrations, production seeder, cache rebuild, queue restart, optional smoke test, a per-step summary, and a closing `app:status` health report. Wrap in `--maintenance` to put the site into maintenance mode during migrations.

**Options:**

| Option | Description |
|---|---|
| `--maintenance` | Wrap migrations in `artisan down` / `artisan up`. |
| `--skip-composer` | Skip `composer install --no-dev`. |
| `--skip-npm` | Skip `npm ci && npm run build`. |
| `--skip-migrate` | Skip `php artisan migrate --force`. |
| `--skip-seed` | Skip `php artisan db:seed ProductionSeeder`. |
| `--skip-status` | Skip the closing `app:status` service health report. |
| `--status-wait=75` | Seconds `app:status` polls for queue workers to come back after `queue:restart`. |
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

### `app:status`

Read-only service health table: release (env / commit / PHP), maintenance mode, database, cache round-trip, pending migrations, per-queue worker health (`otp`, `default`, `compensation` — idle with no process is OK; a job waiting over 2 minutes with no worker fails), queue backlog, failed jobs in the last 24h, and the scheduler heartbeat (stamped every minute by `routes/console.php`). Exits non-zero if any check FAILs. `--wait=<seconds>` polls for missing workers. Details: `docs/runbooks/cloudways-deployment.md` §2.4.

```bash
php artisan app:status
```

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

> **Dev and staging only — never production.** `RecomputeGuard` refuses there
> outright, with no override and no flag. In production the engines stay
> write-once and forward-only: a period already paid can never be re-priced.

On a test environment this command **is** how compensation is computed. It wipes
every row derived from BV and replays every engine **at the instant the
scheduler would have fired it, for the period the scheduler would have handed
it** — the repurchase evaluation dated that day, the GSB cut-off *for the
previous day*, the monthly close on the 1st, the monthly payout batch on the 8th.

That clock is the whole point, not a detail of the implementation. Three of the
engines take a 10 % repurchase deduction at credit time and three others ask
whether a distributor's repurchase wallet was empty at the last instant of the
month; production gets the right answer only because a month's crediting runs on
the 1st of the *following* month. Replaying at any other clock breaks that. See
`docs/architecture/adr-0014-test-environment-recompute.md`.

**What it destroys:** bonus results (GSB, MSB, GBB, Rank, Fortune, ADC), every
frozen pool, carry-forwards, personal-BV top-ups, rank qualifications and AO/GO
grants, lifetime-award milestones, repurchase cycles, the group-BV projection,
wallet credits, payout batches and the engine-run log.

**What it keeps:** orders, the BV ledger, distributors, users, the Genos tree,
sponsorship, KYC, consents, settings and all plan configuration (`gsb_slabs`,
`rank_tiers`, Fortune levels/tiers, lifetime award rewards).

**Four gates, all of which must open.** Never in production;
`COMP_RECOMPUTE_ENABLED`; the connected database named in
`COMP_RECOMPUTE_ALLOWED_DATABASES`; and the typed database name on the
confirmation (skipped by `--force` and by `--no-interaction`, which is how cron
runs the nightly reset). The third exists because the first two describe the
*build* — `APP_ENV` is a label an operator sets, and it cannot tell you the
database behind it holds real cooling-off windows, invoices and a TDS trail.
Staging's does. Naming the permitted databases makes the gate answerable from
the data, so a correctly-flagged build pointed at the wrong database still
refuses, and the refusal prints which database it read.

The name is read off the LIVE connection (`getDatabaseName()`), not from
`DB_DATABASE` — `DB_URL`, if set, silently overrides the configured name at
connect time, and a stale `DB_DATABASE` would otherwise defeat both this gate
and the typed confirmation at once. Staging's value is `ahdhesuhty`; until that
line is in staging's `.env`, the Engine Runs testing cards are hidden and both
endpoints 404, which is the intended fail-closed behaviour.

```bash
# Enable it first — it refuses without BOTH of these, and always in production
COMP_RECOMPUTE_ENABLED=true
COMP_RECOMPUTE_ALLOWED_DATABASES=ahdhesuhty          # comma-separated; empty = nowhere
```

#### The horizon — how far the calendar is replayed

This is the only choice the admin page offers, and the only one most runs need.

| `--horizon` | Stops at | What it means | Marks the environment projected |
|---|---|---|---|
| `now` (default) | this instant | Exactly what the scheduler would have produced by now. Closed months credited on their 1st and paid on their 8th; daily cut-offs through yesterday. **Nothing is simulated.** | No |
| `today` | tomorrow's chain | Also today's repurchase evaluation and GSB cut-off, which really run in tomorrow's chain. No month is closed. | Yes |
| `projection` | the 8th of next month, 04:00 IST | The rest of this month day by day, its monthly close on the 1st, and its payout batch on the 8th — on the orders that exist right now. | Yes |

```bash
php artisan compensation:recompute-all                          # up to now; confirms, naming the target DB
php artisan compensation:recompute-all --horizon=projection     # simulate through next month's payout
php artisan compensation:recompute-all --horizon=now --force    # scripted, no prompt
```

#### A projection is a state the environment is IN

A projection writes ordinary rows at instants that have not arrived: forfeited
cycles, weekly sweeps, the monthly close, the 8th's batch. They change what a
distributor's wallet reads at checkout and what every income page shows. So the
environment declares it:

- **A banner on every page** — admin, distributor, storefront and the
  registration wizard — naming what was simulated and through when, plus the two
  standalone printable pages (ID card, profile stats), outside `.no-print` so it
  survives Save-as-PDF. A projected figure is not a fact, and hard rule 3 does
  not soften on staging.
- **Every report download is marked**: `ReportExport::respond()` renames the file
  `PROJECTED-<date>-…` and writes a first row saying so, because a spreadsheet
  outlives the page it came from and the banner does not travel with it.
- **A payout batch cannot be approved, and no bank NEFT file can be built**,
  while a projection stands. Reading a batch is fine; signing off amounts
  computed on a clock that has not arrived is not.
- **The scheduled compensation engines pause** — all three runs
  (`compensation:nightly-run`, `weekly-run`, `monthly-run`, and therefore
  every engine they fire), the health digest (every paused period would read
  as overdue) and `payout:auto-retry-failed`, which is the only one of them
  that reaches a bank. The check fails CLOSED: if the state cannot be read the
  engines stay paused, because a skipped nightly run is recoverable and a
  corrupted carry-forward store is not. They would otherwise run tonight
  against a carry-forward store the projection has already advanced past, and
  `GsbCutoffService` aborts on exactly that. This also replaces the old
  operational rule about never recomputing between 00:00 and the position the
  runs reach them (F125): a replay in flight pauses them too.
- **A nightly reset at 23:30 IST** runs
  `compensation:recompute-all --horizon=now --if-projected --force`, which puts
  the environment back to production-faithful before the 00:05 engines are due —
  and does nothing at all on an environment that is already faithful.

The state is read from the newest `compensation.recompute_all` /
`compensation.recompute_all.queued` audit row, so a projection whose run died
half-way still counts as standing. Running any `--horizon=now` recompute clears
it.

#### Replay only what you need — `--from`, `--windowed` and `--only`

Command line only: the admin page deliberately offers just the horizon, because
a partial rebuild is a debugging tool rather than a way to run an environment.

```bash
# Rebuild ONLY this month; everything before 1 August is left untouched
php artisan compensation:recompute-all --from=2026-08-01 --windowed --force

# Just the daily cut-off and its prerequisites — no rank, GBB, Fortune or payouts
php artisan compensation:recompute-all --from=2026-08-01 --windowed \
    --only=gsb.daily-cutoff --only=repurchase.evaluate --force
```

`--from` on its own still wipes everything and replays from that date; adding
`--windowed` is what makes the earlier history survive. The two are deliberately
separate: "replay from Tuesday" and "rebuild only Tuesday onwards" are different
operations, and one silently becoming the other would corrupt the
carry-forward chain.

Two engines cannot be replayed on their own while the repurchase engine is on —
`gsb.daily-cutoff` and `rank.check` both refuse to run without a repurchase
evaluation covering their period, and the wipe deletes this window's cycles
whatever is selected. Such a selection is refused **before** anything is
deleted, naming the key to add.

Three rules make a windowed run reproduce a full one exactly:

- **Carry-forward is rewound, not kept.** `gsb_carryforward` holds one rolling
  row per distributor with no history, but every `gsb_cutoff_results` row records
  the `power_cf_before_paise` / `power_side_before` /
  `slab1_weaker_cf_before_paise` it started from. The wiper restores each
  distributor's earliest in-window row's before-state. Rows that never advanced
  the carry-forward (`below_600bv`) are ignored — they record zeros because the
  engine returns before it reads the store.
- **Monthly rows go from their month's first day.** A monthly bonus cannot be
  rebuilt for half a month, so a start date inside a *closed* month is widened to
  that month's 1st (with a warning). A start date in the current month is kept as
  given.
- **A wallet credit survives if its source row does.** Monthly engines run in
  arrears — July's Growth Booster is credited on 1 August — so deleting wallet
  entries purely by date would remove credits whose result row sits before the
  window and is never recomputed. Entries are removed only when the row that
  produced them is being rebuilt.

#### Engines are not triggered one at a time on a test environment

While the gate is open the Engine Runs page renders no per-engine trigger forms
and the trigger endpoint refuses. Firing one engine by hand runs it at the wrong
instant, against a period that has not finished forming — that is the 24 Aug 2026
premature freeze and the 14 Sep 2026 month-end-wallet bug, both of which reached
production-shaped data through that form. Production is unaffected: the gate is
shut there and the buttons stay.

#### Operational notes

The `compensation` worker reads the gate keys at boot, so after editing them run
`php artisan config:clear && php artisan queue:restart`; a worker still holding
the old list refuses the job and the admin page sits on "Queued" (the job's
`failed()` hook surfaces that refusal on the progress bar).

**Stale-worker guard.** A `queue:work` process loads its classes once, so a
worker that booted before the current code was deployed runs the OLD engines —
silently, and with no error anywhere. Local, 5 Sep 2026: the compensation
worker had booted before the repurchase-deduction-at-credit-time change landed,
and an admin-triggered Rank Bonus run credited ₹6,30,644.28 with zero
deduction. `App\Modules\Compensation\Support\WorkerFreshness` now compares the
worker's boot time against the newest mtime under `app/`: a stale worker
refuses both the engine chain and the full recompute rather than moving money.
`app:deploy` already runs `queue:restart`; locally, restart the workers
yourself:

```bash
docker compose -f docker/docker-compose.yml restart queue queue-compensation scheduler
```

Timestamps are travelled during the replay (`Carbon::setTestNow` per engine
firing) so the monthly income cap and the repurchase deduction, which window on
`wallet_ledger_entries.created_at`, fall in the right months. Outbound mail and
notifications are muted for the duration; domain events still fire, because
listeners like `ReleaseHeldRankBonusOnReactivation` are part of a correct
recomputation.

#### What is production code and what is test tooling

There is no longer a "revert at sign-off" checklist: the recompute is how test
environments run, permanently. What still matters is knowing which side of the
line each piece sits on.

| Piece | Side |
|---|---|
| `Services/Recompute/*`, `Jobs/RecomputeAllJob`, `Console/Commands/CompensationRecomputeAllCommand`, the two Engine Runs danger-zone cards | Test tooling — gated by `RecomputeGuard`, inert in production |
| `Support/DerivedTables.php` (with `dateFilter()`), `Support/EngineCadence`, `EngineDefinition::periodForFireOn()` | Production code — single sources of truth the engines and the health digest read |
| `Services/GsbIdleCutoffBatch.php`, the `repurchase:evaluate` narrowing, `engine_runs.duration_ms` | Production code — they speed up and instrument the real engines |
| `RepurchaseWalletGateService` (both modes), `OpenMonthGuard` | Production code — the refusals fire in production too, they are simply unreachable there |

---

## The three cadence orchestrators (ADR-0016)

Since 2026-09-18 compensation runs on **three** scheduler entries instead of
one, each with its own `engine_runs` root row, its own failure banner and its
own self-heal. A dependent run's wait on another is answered by reading
`engine_runs`, not by a shared lock or a wider clock gap — see
`docs/architecture/adr-0016-three-engine-cadences.md`.

### `compensation:nightly-run`

`{--date=} {--force} {--restart}`. Daily **00:05 IST**. Runs, in order:
`repurchase:evaluate --date=<tonight>`, then `gsb:daily-cutoff` for every
owed day (yesterday, preceded by any night that was missed, up to 31 nights
of backfill). No dependency on the other two runs — it is what they wait on.
`--restart` forces the whole night even where a step already reads succeeded
(a night rebuild passes this).

### `compensation:weekly-run`

`{--date=} {--force}`. Daily **03:00 IST**, but it only *starts* work on a
Tuesday, or on any other night when a Tuesday's batch was never built.
**Waits for that night's Nightly Run to have succeeded first** — the batch
dated Tuesday T pays the week that closed on T−7, so the wait is the client's
ordering rule, not a data dependency. When the wait is not met the run
records its own row `skipped` and writes a `weekly_run_deferred` alert
naming which Tuesday(s) waited and why; it is retried the next night the
Nightly Run is green. `--force` skips the wait (the operator's hand only).

### `compensation:monthly-run`

`{--date=} {--force}`. Daily **04:00 IST**, two independent phases in one
process. **Close phase:** closes the month that just ended, once every day of
it has a completed cut-off and that night's Nightly Run — and the Weekly Run
too, on a night a Tuesday batch is owed — has succeeded; deferred (an alert +
a `skipped` row, never a `failed` night) otherwise, and re-attempted every
night it is due. **Payout phase**, evaluated after the close phase and with
**no** dependency on tonight's Nightly/Weekly runs (it pays what the 1st
already credited): builds the monthly payout batch from the 8th while none
exists, plus a bounded one-month lookback. `--force` skips the close phase's
wait.

### Scheduler entries

| Command | Schedule (IST) | Notes |
|---|---|---|
| `compensation:nightly-run` | Daily 00:05 | Repurchase evaluation + GSB/MSB cut-off only. No dependency |
| `compensation:weekly-run` | Daily 03:00 | Builds the Tuesday batch; waits for tonight's Nightly Run |
| `compensation:monthly-run` | Daily 04:00 | Closes the month, then pays from the 8th; waits for tonight's Nightly (and Weekly) Run for the close only |
| `cooling-off:remind` | Daily 09:00 | Statutory D-7/D-1 |
| `compensation:engine-health-digest` | Daily 08:00 | Emails failed / missed / stuck / deferred runs; silent when healthy |
| `compensation:recompute-all --horizon=now --if-projected` | Daily 23:30 | **Dev and staging only** — puts an environment holding simulated figures back to production-faithful before the three runs. A no-op elsewhere, and the whole entry is filtered out in production |

All three orchestrators carry a filter that pauses them on a dev or staging
environment while a projection is standing or a recompute is running; each
command re-checks the same gate itself, so a hand-typed run is paused too. In
production the filters always pass.

**Resume, never restart.** A re-run of any of the three skips every step
already recorded *succeeded* for its period. `--restart` (nightly) forces the
whole night. The repurchase evaluation and the weekly batch are dated
tonight, and a succeeded run never counts while its period is still in
flight, so both are safe to repeat.

**Missed nights heal.** The Nightly Run works forward from the newest day it
can prove was cut off *to completion* (a succeeded run that started after
that day ended — result rows alone are not proof, because the cut-off
commits per distributor and a crash leaves a partial day). Up to 31 nights
are backfilled in order; a longer gap is recorded in `audit_log` and reported
in the health digest rather than replayed unattended.

**A month is never closed short.** If any day of the month has no completed
cut-off, the close is deferred, recorded and reported — every monthly engine
freezes what it prices, so a month closed short stays short.

**A deferral is not a failure.** Both the Weekly and Monthly runs record a
`skipped` root row and an alert — never `failed` — when the run they wait on
has not succeeded, and re-attempt themselves automatically. There is nothing
to retry by hand for a deferral; see
`docs/runbooks/engine-failure-triage.md` §3c.

The seven steps `compensation:monthly-close` runs, in order, as the Monthly
Run's close phase. Each still records its own `engine_runs` row and each is
still individually runnable and individually triggerable from Engine Runs.

| Step | Command | Period |
|---|---|---|
| 1 | `rank:check-qualifications` | `--month` = closed month |
| 2 | `rank:monthly-run` | closed month |
| 3 | `gbb:monthly-run` | closed month |
| 4 | `fortune:enroll-eligible` | closed month |
| 5 | `fortune:monthly-run` | closed month |
| 6 | `adc:monthly-run` | closed month |
| 7 | `offers:monthly-run` | closed month |

Steps 6 and 7 are last because neither is on the crediting critical path: ADC
credits centre owners with no repurchase deduction, and purchase offers move no
cash at all. The five before them share the repurchase cap, which is summed
non-atomically, so they have to stay serial.

**A month still in flight is refused.** `rank:monthly-run`, `gbb:monthly-run`,
`fortune:enroll-eligible`, `fortune:monthly-run`, `adc:monthly-run`, `offers:monthly-run` and both
closes exit non-zero when `--month` names a month that has not ended yet in
IST — a run on the 20th would freeze the month's pool and roster on twenty
days of BV and credit from it, and the 1st-of-month run then keeps that
pricing because money already moved on it (the 24 Aug 2026 premature-freeze
incident, at month scale). `--in-flight` overrides it and is a **testing
option**: the recompute tool passes it for the month in flight at its horizon
(those figures are provisional and discarded by the next recompute), and the
Engine Runs page passes it only behind the developer testing gate. Do not use
it on production by hand.

A flag-off `rank:check-qualifications` records a **skipped** run, and that
satisfies the dependants' prerequisite: with Rank Bonus off nobody can hold a
rank, so the exclusion set they read is legitimately empty and the close
proceeds instead of deadlocking every month.

`payout:monthly-run` is likewise no longer scheduled directly — it is invoked by
`compensation:monthly-payout-close` on the 8th, dated the month the money moves
(the month AFTER the crediting month).

---

## Rebuild commands (developer only — ADR-0016)

> **⚠️ Destructive.** Each of the four commands below deletes rows before it
> re-runs the ordinary engine command for that period, inside one transaction.
> Nothing here is scheduler-only — they exist because a rebuild is exactly
> what a human decides, never a cron. There is no admin retry button any
> more: this is the only repair path for a failed run, and it is reachable
> only by the `developer` role (Engine Runs page, `@developer` panel; or the
> CLI with a mandatory `--actor=<developer user id>`, refused otherwise).
> Every one previews what it would remove and asks for a written reason
> (min 10 characters) before writing anything; refer to
> `docs/runbooks/engine-failure-triage.md` for the decision tree and
> `docs/compliance/risk-register.md` R-102 for the standing risk.

All four: preflight (recompute gate, stale worker, nothing else of the four
or the three runs in flight) → plan (refusals, row counts, downstream
warnings) → wipe that period's derived rows in one transaction → re-run the
ordinary command for the period → print the downstream warnings. A wipe that
succeeds and a re-run that fails leaves the period reading "not computed"
(D13), so the next scheduled run or another rebuild attempt lands on the same
figures — idempotent by construction.

### `compensation:rebuild-night`

`{--date=} {--actor=} {--yes}`. `--date` is the **night**; the cut-off it
rebuilds is the night before. Deletes the previous day's cut-off results,
daily pools, mentorship results, personal-BV top-ups and their wallet
credits, rewinds the carry-forward to the value the day's own rows recorded,
and re-runs `compensation:nightly-run --date=<night> --restart`. **Refuses
once a later day has already been cut off** (R-91 — the carry-forward store
is rolling; the deadline is the next Nightly Run, 00:05 IST), once any of the
night's credits are swept or reversed, or once the month it falls in is
closed and frozen.

```bash
php artisan compensation:rebuild-night --date=2026-09-16 --actor=42
```

### `compensation:rebuild-week`

`{--date=} {--actor=} {--yes}`. `--date` is **the Tuesday** the batch is
dated. Un-sweeps the batch's credits, deletes its own debits
(`payout_debit`/`admin_charge_debit`/`tds_debit`/`income_cap_forfeit`) and
line items, deletes the batch row, and re-runs
`gsb:weekly-payout --date=<Tuesday>`. **Refuses** once the batch has been
approved (finance's hand is on it — retry its failed lines instead), once it
has any gateway events, or if it is stuck `processing` (`payout:reopen-stuck-batch`
first). Refused entirely if no batch exists for that Tuesday — nothing to
remove; the Weekly Run builds a missing one on its own, or run
`compensation:weekly-run --date=<Tuesday>`.

```bash
php artisan compensation:rebuild-week --date=2026-09-16 --actor=42
```

### `compensation:rebuild-month`

`{--month=} {--actor=} {--yes}`. `--month` is the **crediting** month.
Un-builds the month's unapproved payout batch if one exists, deletes the
month's rank, Growth Booster, Fortune, ADC and purchase-offer rows and their
wallet credits, and re-runs `compensation:monthly-close --month=<M> --restart`.
Consumed purchase-offer grants and delivered award milestones are kept, never
re-derived. **Refuses** a frozen month (D9 — no override, not even `--force`)
or one the next month has already been built on top of (D12).

```bash
php artisan compensation:rebuild-month --month=2026-08 --actor=42
```

### `compensation:rebuild-payout`

`{--month=} {--actor=} {--yes}`. `--month` is the **crediting** month (the
batch is dated the 1st of the following month). Un-builds the month's
unapproved payout batch and re-runs
`compensation:monthly-payout-close --month=<M>` so every distributor's amount
is frozen again. Same frozen/approved/gateway/processing refusals as
`rebuild-week`.

```bash
php artisan compensation:rebuild-payout --month=2026-08 --actor=42
```

### What none of the four will ever do

- Delete a credit. A rebuild un-sweeps or deletes only the rows the re-run
  re-derives — it never touches a wallet credit by hand (D10).
- Override the frozen-payout lock (D9), or rebuild a batch finance has
  approved (DN-2) — those go through the ordinary per-line retry or a finance
  decision instead.
- Record a maker other than the developer who ran it — `HandlesPayoutBatchActions::approve()`
  then refuses that developer's own approval of the rebuilt batch (D14, R-81).
- Run unattended. There is no scheduler entry for any of the four.
