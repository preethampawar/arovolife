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
# Normal manual run for a CLOSED day (yesterday or earlier)
php artisan gsb:daily-cutoff --date=2026-07-09

# Backfill a single distributor for a past date
php artisan gsb:daily-cutoff --date=2026-07-04 --distributor=59
```

> ⚠️ **Never run the full cut-off for a day still in flight** (today, or the
> bare no-`--date` form, which defaults to today). The run freezes the day's
> GSB and MSB pool economics permanently at that instant — on staging
> (24 Aug 2026) a 23:27 manual run froze the day at ₹0 company BV / cap score
> value before the evening's orders landed, and the scheduled 00:10 run then
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

**Scheduled:** Every **Tuesday at 03:00 IST**.

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

**Scheduled:** not directly. `compensation:monthly-payout-close` invokes it on the **8th at 04:00 IST**, and only once every crediting engine for the closed month has succeeded.

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

**Scheduled:** Daily at **00:05 IST** — runs before the GSB cut-off (00:10 runs for yesterday, so this updates today's eligibility for use in tonight's cut-off).

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

**Scheduled:** Daily at **08:00 IST** — after every overnight engine (the monthly payout close at 04:00 on the 8th is the last).

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
date up to right now, in the order the scheduler would have run them.

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

**Four gates, all of which must open.** Never in production;
`COMP_RECOMPUTE_ENABLED`; the connected database named in
`COMP_RECOMPUTE_ALLOWED_DATABASES`; and the typed database name on the
confirmation. The third exists because the first two describe the *build* —
`APP_ENV` is a label an operator sets, and it cannot tell you the database
behind it holds real cooling-off windows, invoices and a TDS trail. Staging's
does. Naming the permitted databases makes the gate answerable from the data, so
a correctly-flagged build pointed at the wrong database still refuses, and the
refusal prints which database it read.

The name is read off the LIVE connection (`getDatabaseName()`), not from
`DB_DATABASE` — `DB_URL`, if set, silently overrides the configured name at
connect time, and a stale `DB_DATABASE` would otherwise defeat both this gate
and the typed confirmation at once. Staging's value is `arovolife_staging`;
until that line is in staging's `.env`, the Engine Runs testing cards are
hidden and both endpoints 404, which is the intended fail-closed behaviour.

```bash
# Enable it first — it refuses without BOTH of these, and always in production
COMP_RECOMPUTE_ENABLED=true
COMP_RECOMPUTE_ALLOWED_DATABASES=ahdhesuhty          # comma-separated; empty = nowhere

php artisan compensation:recompute-all              # confirms, naming the target DB
php artisan compensation:recompute-all --force      # scripted, no prompt
php artisan compensation:recompute-all --from=2026-07-01 --to=2026-07-31
```

The name must match the *connected* `DB_DATABASE` exactly — when the staging
database moved off RDS (2026-08-29) the stale RDS name here hid the tool. The
`compensation` worker reads these keys at boot, so after editing them run
`php artisan config:clear && php artisan queue:restart`; a worker still holding
the old list refuses the job and the admin page sits on "Queued" (the job's
`failed()` hook now surfaces that refusal on the progress bar instead).

**Stale-worker guard.** A `queue:work` process loads its classes once, so a
worker that booted before the current code was deployed runs the OLD engines —
silently, and with no error anywhere. Local, 5 Sep 2026: the compensation
worker had booted before the repurchase-deduction-at-credit-time change landed,
and an admin-triggered Rank Bonus run credited ₹6,30,644.28 with zero
deduction. `App\Modules\Compensation\Support\WorkerFreshness` now compares the
worker's boot time against the newest mtime under `app/`: a stale worker
refuses both the engine chain (recorded as a skipped run with reason
`stale_worker`) and the full recompute (surfaced on the progress bar) rather
than moving money. `app:deploy` already runs `queue:restart`; locally, restart
the workers yourself:

```bash
docker compose -f docker/docker-compose.yml restart queue queue-compensation scheduler
```

#### Replay only what you need — `--windowed` and `--only`

A full replay costs the same whatever you actually want to look at, and on a
remote database (staging talks to RDS) that is dominated by round trips rather
than computation. Two knobs cut it down; both are also on the admin panel as
date inputs, `Today` / `This month` presets and engine checkboxes.

```bash
# Rebuild ONLY this month; everything before 1 August is left untouched
php artisan compensation:recompute-all --from=2026-08-01 --windowed --force

# Just the daily cut-off and its prerequisites — no rank, GBB, Fortune or payouts
php artisan compensation:recompute-all --from=2026-08-01 --windowed \
    --only=gsb.daily-cutoff --force
```

`--from` on its own still wipes everything and replays from that date; adding
`--windowed` is what makes the earlier history survive. The two are deliberately
separate: "replay from Tuesday" and "rebuild only Tuesday onwards" are different
operations, and one silently becoming the other would corrupt the
carry-forward chain.

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
  given: the replay's catch-up pass recomputes the month in flight anyway.
- **A wallet credit survives if its source row does.** Monthly engines run in
  arrears — July's Growth Booster is credited on 2 August — so deleting wallet
  entries purely by date would remove credits whose result row sits before the
  window and is never recomputed. Entries are removed only when the row that
  produced them is being rebuilt.

Verified on the reference dataset (288 distributors, 53 days): a windowed
`--from` = 1st-of-month run produces a byte-identical database to a full replay —
same 15,264 cut-off rows, same ₹67,62,369.54 of wallet credits, same
carry-forwards, payout lines, mentorship results and group-BV projection.

Engines you leave out with `--only` are **not replayed at all**, so their results
for the window are missing rather than stale. The run report names every one it
skipped.

#### Why a replay is no longer slow

The nightly cut-off is O(distributors × days) but its outcome is O(distributors
with business). On the reference dataset a full replay wrote 13,248 cut-off rows
to produce 187 credits — 5,796 of them for 126 distributors who have never
purchased anything, each costing a `firstOrCreate` + `update` + `insert`.

{@see \App\Modules\Compensation\Services\GsbIdleCutoffBatch} now partitions the
day's distributors first and writes the provably-inert ones in one batched
`INSERT` per day: below the personal-BV minimum, or eligible with no group BV, no
carry-forward and no existing row. It is a filter, never a second copy of the
matching rules — anything with any state goes down the normal compute/settle
path. `repurchase:evaluate` is narrowed the same way, to distributors who have a
BV row or an open cycle.

`engine_runs.duration_ms` records the real wall-clock time of each run.
`started_at`/`finished_at` deliberately carry the *replayed* instant (the replay
travels the clock so rows land on the date the scheduler would have written
them), which made every replayed run read as 0 seconds — there was no way to see
where a slow replay spent its time. The Engine Runs page shows the measured
duration per engine.

#### The admin button needs a worker that tolerates a long job

The Engine Runs page queues `RecomputeAllJob` instead of running it inline — a
request timeout halfway through leaves the database wiped and half-rebuilt. The
replay takes minutes, so the worker draining that queue has to allow it:

```bash
php artisan queue:work --queue=compensation --timeout=7200 --tries=1 --memory=512
```

`--queue=compensation` is not optional: every compensation job tags itself onto
that queue and a bare `queue:work` drains only `default`, so the job would sit
untouched. On Cloudways this is Supervisord **Job 3** (`docs/runbooks/cloudways-deployment.md`
§1.9): 1 process, timeout 7200, tries 1 — the single process is what stops a
long job being re-reserved by a second worker, and tries 1 is what stops a
half-finished replay being re-run on top of itself.

**`php artisan queue:listen` cannot run this job.** It does not execute jobs
itself; it spawns a child `queue:work --once` wrapped in a process with the
listener's own `--timeout`, default **60 seconds**
(`Illuminate\Queue\Listener::makeProcess()`). The child is killed from outside
at 60s regardless of the job's `$timeout = 7200`, mid-replay, with the wipe
already committed. That is how staging was left empty on 2026-08-24.

A killed worker never reaches `fail()`, so the progress cache would keep saying
"running" until its two-hour TTL. Every published update now carries a
heartbeat, and a run silent for more than 15 minutes reads as failed — the
console shows the error and the button unblocks, rather than refusing the retry
that is the only way to finish the rebuild.

On a box with no long-running worker at all, skip the queue and run the command
under `nohup` so a dropped SSH session does not take the replay with it. It
publishes the same progress, so the admin page's bar still tracks it:

```bash
nohup php artisan compensation:recompute-all --force > storage/logs/recompute.log 2>&1 &
```

#### Catch-up pass — testing only

The day loop fires each engine on the calendar day the scheduler would have fired
it. That leaves two gaps:

1. **The period still in flight at the horizon** — the cut-off for today, the
   payout for this week, this month's bonuses for the monthly engines.
2. **Closed months whose scheduled run day has not arrived yet** — all monthly
   engines run on the 1st. If you click Recompute at 00:20 on 1 September, the
   Rank Bonus at 00:30 has not fired yet; August's Rank Bonus would be missing.
   If you click on 5 September, August's Rank Bonus ran on 1 September so the day
   loop covered it — but any month-engine moved back to the 1st of a later month
   in the future would still fall into this gap.

So after the day loop, **whenever the window reaches today or later**, a catch-up
pass runs for both categories:

| Engine | In-flight period | Arrears (next firing is still ahead) |
|---|---|---|
| GSB daily cut-off (incl. MSB), Repurchase evaluation | today | — (daily: never an arrears gap) |
| GSB weekly payout | this week (the Tuesday batch) | — (weekly: same) |
| GBB, Rank Bonus, ADC, Fortune (enrol + payout), Monthly payout | this month | the closed month the next 1st-of-month run would process, if that run is still ahead |

Skipped when the day loop already covered that exact period (`rank.bonus @
2026-08-01` flagged as invoked when the loop hit 1 Sep 00:30). Unscheduled
prerequisites (the rank qualification check) are pulled in first.

**Stamp time:** catch-up rows whose window ends today are stamped at the real
clock (existing behaviour). A future-horizon catch-up is stamped at 23:59 of the
last simulated day so the rows sort after every loop-stamped row (PayoutService
windows on `wallet_ledger_entries.created_at`).

#### Future horizon — testing only

`--to` / the **To** field may extend **up to the last day of next calendar month**.
The loop simulates future days on a future fake clock — each engine fires at its
scheduled instant (e.g. Rank Bonus at 00:30 on 1 Oct for September's data) —
then the catch-up pass fills any remaining gaps. Use this to preview "how the
next 1st-of-month will look on the orders that exist right now" without waiting
for the scheduler.

Three consequences:

- **Future rows carry future timestamps.** PayoutService windows on
  `created_at`, so a payout batch dated 1 Oct exists in the wallet before that
  date. On a testing database that is fine.
- **The real scheduler will skip those periods as already computed.** After a
  future-horizon replay, the 1 Oct run finds Rank Bonus already done and moves on.
  Recompute again with **To empty** (defaults to today) to return to the live
  picture.
- **Repurchase state reflects the simulated horizon.** `repurchase:evaluate`
  runs at the real clock at the end of every recompute, so the dashboard shows
  today's eligibility — but the wallet ledger carries future-dated rows.

A historical `--to` (before today) turns the catch-up pass off entirely, so
`--from=2026-07-01 --to=2026-07-31` replays July exactly as the scheduler ran
it and touches nothing in the current period.

**None of this changes the schedule.** `routes/console.php` is untouched: the
00:10 cut-off still processes the previous day, the payout still lands on
Tuesday, the monthly engines still fire on the 1st for the month that closed,
and every production run still freezes its period permanently. The catch-up and
future simulation exist inside the replay and nowhere else.

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
listeners like `ReleaseHeldRankBonusOnReactivation` are part of a correct
recomputation.

#### Reverting it after sign-off

1. Delete `app/Modules/Compensation/Services/Recompute/` (including
   `WindowedStateWiper.php`), `Services/DTOs/RecomputeReport.php`,
   `Jobs/RecomputeAllJob.php`,
   `Console/Commands/CompensationRecomputeAllCommand.php`,
   `tests/Modules/Compensation/CompensationRecomputeTest.php` and
   `tests/Modules/Compensation/WindowedRecomputeTest.php`
2. Remove the `recompute` block from `config/arovolife.php`, the
   `COMP_RECOMPUTE_ENABLED` and `COMP_RECOMPUTE_ALLOWED_DATABASES` lines from
   `.env.example` and every `.env`, and the
   command from `AppServiceProvider`
3. Remove the `recompute-all` and `reset-purchase-data` routes,
   `AdminEngineRunsController::recomputeAll()` and `::resetPurchaseData()`, both
   danger-zone cards in the Engine Runs view, and the recompute + reset tests in
   `AdminEngineRunsControllerTest`
4. Remove the `notEngines` exclusion in `EngineRegistryTest`
5. **Keep** `Support/DerivedTables.php` (including its `dateFilter()` map) and
   the `EngineCadence` work — genuine single-source fixes, not scaffold
6. **Keep** `Services/GsbIdleCutoffBatch.php` + its test, the
   `repurchase:evaluate` narrowing, and `engine_runs.duration_ms`: they speed up
   and instrument the *production* engines and have nothing to do with replaying
7. **Keep** `PurchaseDataResetAction::preview()` — the CLI confirmation uses it
8. Delete this runbook section

---

## Scheduled command summary

| Command | Schedule (IST) | Notes |
|---|---|---|
| `repurchase:evaluate` | Daily 00:05 | Must run before the GSB cut-off |
| `gsb:daily-cutoff` | Daily 00:10 (processes yesterday) | Core GSB engine |
| `cooling-off:remind` | Daily 09:00 | Statutory D-7/D-1 |
| `compensation:monthly-close` | 1st of month 00:20 | **The only monthly crediting entry.** Runs the seven engines below, in order, in one process; resumes at the first step that has not succeeded |
| `gsb:weekly-payout` | Tuesday 03:00 | Aggregates credited cut-offs |
| `compensation:monthly-payout-close` | 8th of month 04:00 | Runs `payout:monthly-run`, but only if every crediting engine for the month succeeded |
| `compensation:engine-health-digest` | Daily 08:00 | Emails failed / missed / stuck runs; silent when healthy |

The seven steps `compensation:monthly-close` runs, in order. **None of these has
its own scheduler entry any more** — clock offsets do not serialise commands
(`withoutOverlapping()` is per-command), so the ordering now lives in one
process. Each still records its own `engine_runs` row and each is still
individually runnable and individually triggerable from Engine Runs.

| Step | Command | Period |
|---|---|---|
| 1 | `rank:check-qualifications` | `--month` = closed month |
| 2 | `rank:monthly-run` | closed month |
| 3 | `gbb:monthly-run` | closed month |
| 4 | `fortune:enroll-eligible` | closed month |
| 5 | `adc:monthly-run` | closed month |
| 6 | `fortune:monthly-run` | closed month |
| 7 | `offers:monthly-run` | closed month |

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
