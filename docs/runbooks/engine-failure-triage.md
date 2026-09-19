# Runbook — an engine or nightly job has failed

For the developer on the end of a red banner, a health digest, or "nobody
got paid last night". Every step is a command you can paste.

Read [`docs/local-dev-environment.md`](../local-dev-environment.md) before
running anything locally, and
[`docs/runbooks/artisan-commands.md`](artisan-commands.md) for what each
command does in full. This file is the decision tree; that one is the
reference.

---

## 0. Where you are running

Everything below is written as `php artisan …`. Prefix it:

```bash
# Local (Docker)
docker exec arovolife-app php artisan <command>

# Staging / production (Cloudways)
ssh master@<server-ip>
cd applications/<app>/public_html && php artisan <command>
```

**Never** run the test suite without the DB overrides — a bare
`php artisan test` runs against the real dev database:

```bash
docker exec -e DB_CONNECTION=mysql -e DB_DATABASE=arovolife_test -e DB_HOST=db \
  -e DB_PORT=3306 -e DB_USERNAME=arovolife -e DB_PASSWORD=secret \
  arovolife-app php artisan test --compact
```

---

## 1. Look before you touch

The Engine Runs page (`/admin/compensation/engine-runs`) is the same data,
but these three commands are faster and work over SSH.

```bash
# What happened last night, newest first
php artisan tinker --execute '
  App\Modules\Compensation\Models\EngineRun::latest("id")->limit(20)
    ->get(["id","engine_key","period_start","status","trigger","actor_id","started_at","finished_at"])
    ->each(fn($r) => print("{$r->id}\t{$r->engine_key}\t{$r->period_start}\t{$r->status}\t{$r->trigger}\n"));'

# Anything still claiming to be running
php artisan tinker --execute '
  echo App\Modules\Compensation\Models\EngineRun::where("status","running")->count();'

# The failing step's own message
php artisan tinker --execute '
  $r = App\Modules\Compensation\Models\EngineRun::find(<id>);
  echo $r->error, PHP_EOL, PHP_EOL, $r->summary, PHP_EOL;'
```

Force a health digest to your inbox without waiting for the schedule:

```bash
php artisan compensation:engine-health-digest --always
```

### The five statuses, and which ones lie to you

| `engine_runs.status` | Means | Clears itself? |
|---|---|---|
| `succeeded` | The step finished and its period is closed. | — |
| `failed` | The step threw. **The run stopped here** — nothing after it ran. | No. A developer rebuild (`compensation:rebuild-*`), or the command by hand. |
| `running` | A process opened this row and never closed it. If no worker is alive, it is a lie. | Only when a later chain run finds it stale. |
| `skipped` | The step *declined* to run. | **No button clears this.** See §2. |
| *(no row at all)* | The chain never started. Scheduler or cron problem, not an engine problem. | No. |

> **`skipped` is the one that catches people out.** It is written both for a
> flag-off engine (correct, nothing owed) *and* for a preflight refusal — a
> stale worker or a standing recompute projection. A `skipped` night looks
> calm on the Engine Runs page and raises no retry banner, so "nothing
> happened at all" is exactly what a stale worker looks like.

---

## 2. Triage table

| Symptom | Almost always | Go to |
|---|---|---|
| Nightly run recorded `skipped`, no banner | Stale queue worker after a deploy | [§3](#3-the-night-was-skipped) |
| Nightly run recorded `skipped` on dev/staging | A recompute projection is standing | [§3](#3-the-night-was-skipped) |
| Weekly run deferred: alert says tonight's nightly run has not succeeded | The night's Nightly Run has not succeeded yet | [§3c](#3c-a-run-was-deferred-on-a-prerequisite) |
| Month not closed on the 1st: alert names a prerequisite, not a missing cut-off | Tonight's Nightly Run (or the Tuesday's Weekly Run) has not succeeded | [§3c](#3c-a-run-was-deferred-on-a-prerequisite) |
| One step `failed`, steps after it never ran | The engine threw | [§4](#4-a-step-failed) |
| A run stuck `running` for hours | Worker died mid-run (OOM/SIGKILL) | [§5](#5-a-run-is-stuck-running) |
| No `engine_runs` row for last night at all | Scheduler/cron not firing | [§6](#6-no-run-row-at-all) |
| Tuesday came and went, no payout batch | Weekly sweep never ran, or its flag is off | [§7](#7-a-payout-batch-is-missing) |
| Payout batch sitting in `failed` | Sweep threw part-way | [§8](#8-a-payout-batch-is-failed) |
| Payout batch sitting in `processing` | Sweep was **killed outright** | [§9](#9-a-payout-batch-is-stuck-in-processing) |
| Month will not close | A day inside it has no completed cut-off | [§10](#10-the-month-will-not-close) |
| "N Tuesday batch(es) were never built" every night | GSB flag off — *fixed 2026-09-17, should not recur* | [§7](#7-a-payout-batch-is-missing) |
| Distributors unpaid with money in the wallet | A payout hold, not a failure | [§11](#11-distributors-are-held-not-failed) |
| `repurchase:evaluate` partially failed | One distributor's data; the cut-off now refuses | [§12](#12-repurchase-evaluation-failed-for-some-distributors) |
| A pool frozen at ₹0 | An engine ran for a period still in flight | [§13](#13-a-pool-froze-at-zero) |
| "a later cut-off already advanced the carry-forward store" | A past date is being replayed after a newer one ran | [§14](#14-a-cut-off-refuses-because-a-later-one-already-ran) |

---

## 3. The night was `skipped`

Two causes, and the command tells you which:

```bash
php artisan tinker --execute '
  $s = app(App\Modules\Compensation\Services\Recompute\RecomputeState::class);
  echo "worker stale:               ", App\Modules\Compensation\Support\WorkerFreshness::staleReason() ?? "no (fresh)", PHP_EOL;
  echo "projection standing:        ", $s->isProjected() ? "YES" : "no", PHP_EOL;
  echo "scheduler engines allowed:  ", $s->schedulerEnginesAllowed() ? "yes" : "NO", PHP_EOL;'
```

A stale worker sends you to §3a; a standing projection to §3b. Both `no` and
the skip was the engine's own feature flag — correct, and nothing is owed.

### 3a. Stale worker (the usual one, always after a deploy)

The engines refuse to credit money from a process that may be running
pre-deploy code. **Restart the worker and the scheduler — every deploy,
every time:**

```bash
php artisan queue:restart     # workers exit after the current job; Supervisor respawns them
php artisan schedule:clear-cache
```

Then re-run the night that was skipped:

```bash
php artisan compensation:nightly-run --date=2026-09-16
```

> A skipped night is **not** backfilled automatically for payouts. The
> cut-offs are (the Nightly Run works forward from the last proven day), but
> the weekly batch for a Tuesday inside the gap needs the date naming — see §7.

### 3b. A standing recompute projection (dev/staging only)

A projection pauses the scheduled engines until it is cleared. Clear it:

```bash
php artisan compensation:recompute-all --horizon=now
```

### 3c. A run was deferred on a prerequisite

Since ADR-0016 the nightly, weekly and monthly runs are three separate
orchestrators with one ordering rule between them: the Weekly Run (03:00 IST)
waits for that night's Nightly Run to have succeeded, and the Monthly Run's
close phase (04:00 IST) waits for both. When the run it waits on has not
succeeded, the dependent run records its own row as `skipped` — never
`failed` — and writes an alert naming what it is waiting for
(`compensation.weekly_run_deferred` / `compensation.month_deferred` with
`cause: prerequisite`).

**This is not a failure and there is nothing to rebuild.** Read the alert or
the banner — it names the run whose success is missing. Fix that run using
§3–§5 above, whatever caused it to fail or never start. The deferred run
re-attempts itself automatically the next night it is due: a deferred Tuesday
batch is still dated that Tuesday when it is eventually built, and a deferred
monthly close is not backdated either. Nothing to type unless the blocking
run itself needs help.

---

## 4. A step `failed`

**Each of the three runs stops at its first failing step**, so everything
after it did not run. Fix the cause, then re-run the whole night — a re-run
*resumes*, skipping every step already recorded succeeded for its period.
There is no admin retry button any more (ADR-0016): the Engine Runs page
shows the failure to every admin role as information only. Repairing it is
either the ordinary command by hand, below, or — for a night a later cut-off
has already passed, or a payout batch that needs to be un-built and re-swept
— a developer rebuild (`compensation:rebuild-*`, see the table below and
`docs/runbooks/artisan-commands.md`).

```bash
php artisan compensation:nightly-run --date=2026-09-16

# Force every step to re-run, ignoring the resume:
php artisan compensation:nightly-run --date=2026-09-16 --restart
```

Individual engines, when you want just one:

```bash
php artisan repurchase:evaluate            --date=2026-09-16
php artisan gsb:daily-cutoff               --date=2026-09-15
php artisan compensation:monthly-close     --month=2026-08
php artisan rank:check-qualifications      --month=2026-08
php artisan rank:monthly-run               --month=2026-08
php artisan gbb:monthly-run                --month=2026-08
php artisan fortune:enroll-eligible        --month=2026-08
php artisan fortune:monthly-run            --month=2026-08
php artisan adc:monthly-run                --month=2026-08
php artisan offers:monthly-run             --month=2026-08
```

### What a rebuild does and does not cover

Developer only, from the Engine Runs page or the CLI with `--actor=<developer
user id>`: `compensation:rebuild-night`, `compensation:rebuild-week`,
`compensation:rebuild-month`, `compensation:rebuild-payout`. Each previews
what it would delete and un-sweep before it touches anything, and re-runs the
ordinary command afterwards.

| | |
|---|---|
| Rebuilds the newest night | ✅ |
| A night a later cut-off has already passed | ❌ — R-91, [§14](#14-a-cut-off-refuses-because-a-later-one-already-ran) |
| An unapproved (pending/failed) batch | ✅ |
| An approved batch | ❌ — retry its failed lines instead ([§8](#8-a-payout-batch-is-failed)) |
| A frozen month (its payout was approved) | ❌ — no override |
| A month the next month was built on | ❌ |
| Clears a `skipped` night | ❌ — [§3](#3-the-night-was-skipped) / [§3c](#3c-a-run-was-deferred-on-a-prerequisite) |

---

## 5. A run is stuck `running`

A `running` row is only ever closed by the process that opened it. If that
process is dead, nothing closes it — and a developer rebuild's preflight
refuses beside it, because `RebuildPreflight` reads it as a run still in
flight.

```bash
# 1. Is anything actually alive?
ps aux | grep -c "[q]ueue:work"
php artisan queue:monitor compensation

# 2. Restart the worker (this alone clears a chain row on the next run)
php artisan queue:restart

# 3. If a row must be closed by hand — ONE row, never broader:
```
```sql
UPDATE engine_runs
   SET status = 'failed',
       error = 'marked failed by developer: worker died mid-run',
       finished_at = NOW()
 WHERE id = <id> AND status = 'running';
```

Then re-run the night as in §4.

---

## 6. No run row at all

The chain never started. This is a scheduler problem.

```bash
php artisan schedule:list                 # is compensation:nightly-run there?
php artisan schedule:run                  # fire due tasks now, in the foreground
crontab -l | grep schedule:run            # is the cron entry present on the server?
tail -100 storage/logs/laravel.log
```

Catch up the missed nights once the scheduler is fixed — oldest first:

```bash
php artisan compensation:nightly-run --date=2026-09-14
php artisan compensation:nightly-run --date=2026-09-15
php artisan compensation:nightly-run --date=2026-09-16
```

> The chain backfills at most **31** nights on its own. A longer gap is
> recorded as `compensation.nightly_run_backfill_gap` in `audit_log` and
> reported in the digest for seven days, and is left to a human on purpose.

---

## 7. A payout batch is missing

```bash
# What exists, newest first
php artisan tinker --execute '
  App\Modules\Compensation\Models\PayoutBatch::latest("batch_date")->limit(10)
    ->get(["id","batch_type","batch_date","status","distributor_count","created_by"])
    ->each(fn($b) => print("{$b->id}\t{$b->batch_type}\t{$b->batch_date->toDateString()}\t{$b->status}\n"));'
```

**First check the feature flag.** With the GSB flag off the sweep records
`skipped` and builds nothing — that is correct, and nothing is owed.
Distributors lose nothing either way: unswept income stays in the wallet and
the first batch after the flag returns sweeps every older earning week too.

**This is now usually not needed by hand.** A missing Tuesday batch or a
missing monthly payout is exactly what the Weekly Run (03:00 IST) and the
Monthly Run (04:00 IST) self-heal on their own next night — see
[§3c](#3c-a-run-was-deferred-on-a-prerequisite) if the alert names a
prerequisite rather than a missing build. If it needs to happen sooner than
the next night, run the orchestrator directly — it carries the same ordering
guards a hand-typed engine command does not:

```bash
php artisan compensation:weekly-run --date=2026-09-16    # tonight; builds any Tuesday still owed
php artisan compensation:monthly-run --date=2026-09-16   # tonight; closes/pays whatever is owed
```

The bare engine commands still work and are what the orchestrators call
underneath — the date for the weekly one is **the Tuesday itself**, and it
must be a Tuesday:

```bash
php artisan gsb:weekly-payout --date=2026-09-15
php artisan compensation:monthly-payout-close --month=2026-08
```

> ⚠️ **A batch built from any of these commands typed by hand has NO MAKER.**
> There is no `Auth::id()` and no attributed run context on the CLI, so
> `created_by` lands NULL and nothing stops the person who ran it from also
> approving it. **Whoever runs this must not approve the batch.**

---

## 8. A payout batch is `failed`

The sweep threw and stopped cleanly. This is the ordinary half-finished
case, and while the plain re-run below still resumes it (skipping every
distributor who already has a line item), the sanctioned developer path is
now a **rebuild**: it un-sweeps the batch's credits, deletes its own debits
and line items, deletes the batch row, and re-runs the sweep from scratch —
audited, with a maker recorded by `--actor`, in one step:

```bash
php artisan compensation:rebuild-week --date=2026-09-15 --actor=<developer user id>    # weekly
php artisan compensation:rebuild-payout --month=2026-08 --actor=<developer user id>    # monthly
```

It refuses (and says why) once the batch has been approved — DN-2: money
instructions that already left the company are corrected line by line, never
rebuilt.

The plain re-run still works for a pending/failed batch and carries the same
maker warning as §7:

```bash
php artisan gsb:weekly-payout --date=2026-09-15              # weekly
php artisan compensation:monthly-payout-close --month=2026-08 # monthly
```

---

## 9. A payout batch is stuck in `processing`

The sweep was **killed outright** — OOM, the queue job's hour-long timeout,
SIGKILL — so it never reached the code that writes `failed`. `processing` is
closed to re-entry, so nothing on the platform can re-enter it: not the
Weekly or Monthly run (each proves a Tuesday or a month from the *existence*
of a batch, not its status), not a rebuild, not the sweep itself.

It appears in the **Action Center → Money → "Payout batches stuck
mid-sweep"** once nothing has been written for it for two hours.

```bash
# 1. Confirm no sweep is actually alive. A long sweep is NOT stuck.
ps aux | grep "[g]sb:weekly-payout"
ps aux | grep -c "[q]ueue:work"

# 2. Reopen it. --actor is REQUIRED and must hold `finance.record`.
php artisan payout:reopen-stuck-batch --type=weekly --date=2026-09-15 --actor=<user id>

# 3. Rebuild it (records the developer as maker) or re-run it as in §8
php artisan compensation:rebuild-week --date=2026-09-15 --actor=<developer user id>
```

The command refuses if a payout sweep holds the lock, or if **anything has
been written for the batch in the last two hours**. That second check reads
the batch's line items as well as the batch row and takes whichever is
later — the batch row alone is written only twice per sweep, at the start
and at the end, so on its own it dates when the sweep *started*.

`--actor` becomes the batch's **maker**, because the re-run afterwards
cannot record one. It is stamped only when the batch has none; an existing
maker is never overwritten.

> Distributors are never at risk here. Held and unswept income is not
> debited, so the following Tuesday's batch collects the missed week as well
> as its own. What is at stake is *when* they are paid and whether the payout
> report tells the truth in the meantime.

---

## 10. The month will not close

A month is never closed short: if any day inside it has no **completed**
cut-off, the close is refused, recorded as
`compensation.nightly_run_month_deferred`, and reported. That is deliberate —
every monthly engine freezes what it prices, so a month closed short stays
short.

> **If the refusal instead names a frozen month, that is not this section.**
> Once finance approves that month's payout batch, `FrozenPayoutGuard` refuses
> every monthly engine, the close and both month rebuilds for it — with no
> override, not even `--force`. That is D9, not a bug: the money has already
> moved on that month's figures. The engines run again for the *next* month
> from its own 1st, 00:00 IST; there is nothing to fix here.

```bash
# Which days of the month have no completed cut-off
php artisan tinker --execute '
  $s = app(App\Modules\Compensation\Services\EngineStatusService::class);
  $from = Illuminate\Support\Carbon::parse("2026-08-01");
  $to   = $from->copy()->endOfMonth();
  $done = $s->completedCutoffDatesBetween($from, $to);
  for ($d = $from->copy(); $d->lte($to); $d->addDay()) {
    if (! in_array($d->toDateString(), $done, true)) { echo $d->toDateString(), PHP_EOL; }
  }'
```

Cut off each missing day, oldest first, then close:

```bash
php artisan gsb:daily-cutoff --date=2026-08-17
php artisan compensation:monthly-close --month=2026-08
```

---

## 11. Distributors are held, not failed

A hold is not an engine failure. Income accrues, the wallet is **never**
debited or swept, and the first batch after the block clears pays the full
balance. The ladder, in the order it is applied:

| Line status | Meaning | Who fixes it |
|---|---|---|
| `web_only` | Personal BV below the NEFT minimum | The distributor, by purchasing |
| `kyc_pending` | KYC not verified | Admin, by approving KYC |
| `no_bank_account` | No bank record on file | The distributor (profile → bank) |
| `bank_decrypt_failed` | Bank record on file that **will not decrypt** | **You.** See below |

```bash
# Who is held, and why
php artisan tinker --execute '
  App\Modules\Compensation\Models\PayoutLineItem::whereIn("status",
    App\Modules\Compensation\Models\PayoutLineItem::HELD_STATUSES)
    ->selectRaw("status, count(*) c")->groupBy("status")->get()
    ->each(fn($r) => print("{$r->status}\t{$r->c}\n"));'
```

`bank_decrypt_failed` is a platform fault, not the distributor's — they gave
us the account and we cannot read what we stored. It appears in **Action
Center → Money → "Distributors whose bank details will not decrypt"**. Two
causes:

1. **Plaintext written into the ciphertext column** (a seeder, a raw SQL
   fix-up). Every real ciphertext starts `eyJpdiI6`. Find them:

   ```bash
   php artisan tinker --execute '
     echo App\Modules\Identity\Models\Distributor::whereNotNull("bank_account_enc")
       ->where("bank_account_enc","!=","")->where("bank_account_enc","!=","stub")
       ->where("bank_account_enc","not like","eyJpdiI6%")->count(), PHP_EOL;'
   ```

   Locally, re-seed properly — never write raw SQL into this column:

   ```bash
   php artisan db:seed --class=DevBankAccountSeeder
   ```

2. **`PII_ENCRYPTION_KEY` does not match the ciphertext** (a key rotated
   without re-encrypting, a row copied in from another environment). This
   hits *every* row at once:

   ```bash
   php artisan pii:reencrypt
   ```

In production the fix is to re-capture the distributor's bank details from
their admin screen. The next batch pays the full balance.

---

## 12. Repurchase evaluation failed for some distributors

`repurchase:evaluate` isolates a throwing distributor and carries on, so a
partial failure leaves the rest correctly evaluated — and then **the GSB
cut-off refuses**, because the verdict it reads is not trustworthy for
everyone.

```bash
# The failed ADNs are in the run summary
php artisan tinker --execute '
  $r = App\Modules\Compensation\Models\EngineRun::where("engine_key","repurchase.evaluate")
        ->latest("id")->first();
  echo $r->summary, PHP_EOL;'

# Fix the underlying data, then re-run just that date
php artisan repurchase:evaluate --date=2026-09-16

# Only once every one of them is fixed, or with eyes open:
php artisan gsb:daily-cutoff --date=2026-09-15 --force
```

---

## 13. A pool froze at zero

An engine that freezes pool economics was run for a period still in flight —
the classic case is a cut-off fired at 23:27, before the evening's orders
landed. GSB and GBB self-heal from a premature freeze; **Rank Bonus, Fortune
and ADC do not.**

The commands refuse an in-flight period now, so this should not recur. If it
already happened, the only honest repair is a full replay on a test
environment:

```bash
php artisan compensation:recompute-all --horizon=now
```

On production, stop and escalate — do not hand-edit a frozen pool.

---

## 14. A cut-off refuses because a later one already ran

```
Cannot re-run the 2026-09-14 cut-off for distributor 412: a later cut-off
already advanced the carry-forward store, and replaying one date in isolation
cannot rewind it.
```

This is a refusal, not a crash, and the guard is right. Carry-forward is a
**rolling** store: each night's cut-off rewinds to the before-state its own
result row recorded, recomputes, and writes the result forward. Once a newer
date has advanced the store, that row's before-state no longer describes it —
rewinding to it would erase the newer day's BV silently, and nobody would be
credited for it.

**The commonest way to meet this refusal is the Retry button**, and from
2026-09-17 it fires more often than it used to. Manual Controls → Retry Daily
Cut-off is now refused for any night the chain has already run past — including
a night whose row says *failed*, and including the retry's own deletion of that
row. Before that date the guard only inspected the row it was replacing, so a
failed night retried a day late was recomputed against a store that had already
absorbed the following night: no error, wrong figures, nobody told. If the night
you are retrying is the most recent one and nothing has run since, the button
behaves exactly as it always did.

**Since ADR-0016, `compensation:rebuild-night` (developer only) is the
sanctioned same-day remedy for a whole failed night**, not just one
distributor: it wipes the previous day's cut-off results, pools, mentorship
results and their wallet credits, rewinds the carry-forward, and re-runs the
night — refusing with this same R-91 message once a later day has already
been cut off. Manual Controls → Retry Daily Cut-off (above) remains the
one-distributor fix; reach for the rebuild when the whole night needs it.

**After a night rebuild inside a closed month, these three steps are
mandatory — not the last of them advice (R-102, condition of the S3
compliance sign-off):**

1. Confirm the month the rebuilt night falls in is closed but **not yet
   frozen** (`FrozenPayoutGuard::frozenBatchFor()` — a frozen month refuses
   the rebuild outright, so if you got this far it is not frozen).
2. Check `docs/compliance/risk-register.md` R-102 item (3): a night rebuilt
   inside a closed month changes that night's GSB figures, but the month's
   Rank, Growth Booster, Fortune and ADC results were already computed on
   the **pre-rebuild** numbers. Nothing alerts on this by itself.
3. **Run `php artisan compensation:rebuild-month --month=<that month>
   --actor=<developer user id>` before you consider the incident closed.**
   Skipping this step leaves the month's non-GSB bonuses standing on stale
   figures with no record that they are stale.

**So the deadline is the next Nightly Run — 00:05 IST.** Not "usually":
`GsbIdleCutoffBatch` writes a `no_match` row for every idle distributor each
night and `no_match` advances the store, so one Nightly Run closes the window
for essentially the whole roster. A failed night found the next morning is
already past retry — and past rebuilding. Treat a failed cut-off as same-day
work.

**What is lost is not only that day's bonus.** A cut-off reads exactly one
day's `group_bv_daily` row (`GsbCutoffService.php:162`) and nothing else ever
re-reads it — the next night reads its own date plus the carry-forward store.
So a night that never settled successfully strands that day's group BV outside
the store permanently: it is not matched, its remainder never reaches the
carry-forward, and no later night picks it up. The distributor loses the day's
match *and* the BV that would have fed every match after it.

**And the chain will not come back for it.**
`EngineStatusService::computedCutoffDatesBetween()` treats a date as done if
*any* `gsb_cutoff_results` row exists for it. A `failed` row counts. So does a
`no_match` row written for a completely different distributor by the idle
batch. That is the difference between §6 — a night the chain never started,
which the next backfill genuinely does fix — and this section: a night the
chain *ran* and failed part-way through already looks computed, is never
proposed again, and is walled off by the guard the following morning. When you
read "a failed night is not a lost night" on the admin Engine Runs help, it
means §6. It does not mean this.

The admin sees the refusal message verbatim on the Manual Controls page (a
`compensation.cutoff.manual_retry_refused` audit row is written with it, and
the failed cut-off row survives the refused retry untouched). Before
2026-09-17 the same refusal rendered as a blank *"Something went wrong"* page
and recorded nothing — if you are reading a report from an older incident, the
absence of an audit row does not mean nobody tried.

**There is no admin control for rebuilding a carry-forward.** Manual Controls
once carried a "Recalculate CF" button that only wrote an audit row; it was
deleted on 2026-09-17 rather than left to look like a fix. Nothing on the admin
side rebuilds a carry-forward.

First, find how far back the damage goes — the earliest date whose figures are
wrong, not the date you happened to re-run:

```bash
php artisan tinker --execute '
  App\Modules\Compensation\Models\GsbCutoffResult::where("distributor_id", 412)
    ->orderBy("cutoff_date")
    ->get(["cutoff_date","status","power_cf_before_paise","power_cf_after_paise","net_gsb_paise"])
    ->each(fn($r) => print("{$r->cutoff_date->toDateString()}\t{$r->status}\t{$r->power_cf_before_paise}\t{$r->power_cf_after_paise}\t{$r->net_gsb_paise}\n"));'
```

**Dev / staging** — replay every engine from that date forward, in order, at the
scheduler's own clock (ADR-0014). This is population-wide, not per-distributor;
there is no per-distributor rebuild:

```bash
# Everything from the first wrong day forward, earlier history kept
php artisan compensation:recompute-all --horizon=now --from=2026-09-14 --windowed

# Whole history, when the store is wrong further back than you can pin down
php artisan compensation:recompute-all --horizon=now
```

Then confirm the store and the results agree, and that no run is left failed:

```bash
php artisan tinker --execute '
  $cf = App\Modules\Compensation\Models\GsbCarryforward::where("distributor_id",412)->first();
  $last = App\Modules\Compensation\Models\GsbCutoffResult::where("distributor_id",412)
    ->orderByDesc("cutoff_date")->first();
  echo "store: {$cf->power_side} {$cf->power_side_bv_paise}", PHP_EOL;
  echo "last result forward: {$last->power_side_after} {$last->power_cf_after_paise} on {$last->cutoff_date->toDateString()}", PHP_EOL;'

php artisan tinker --execute '
  echo App\Modules\Compensation\Models\EngineRun::where("status","failed")->count();'
```

### 14a. Production: decide whether to rebuild at all

`compensation:recompute-all` refuses in production unconditionally
(`RecomputeGuard`), by design: it wipes and rebuilds every BV-derived row.
There is no override — not a flag, not a `--force`, not an environment
variable. The procedure below is the only sanctioned remedy, and it restates
production data, so it is justified by the size of what is owed and by nothing
else. **Do not** re-run the date by hand and **do not** reverse-and-recredit to
paper over it: neither touches the carry-forward store the next night reads.

Measure the loss before waking anyone. This lists who failed on the date and
the group BV each of them had stranded:

```bash
php artisan tinker --execute '
  $d = "2026-09-14";
  App\Modules\Compensation\Models\GsbCutoffResult::whereDate("cutoff_date", $d)
    ->where("status", "failed")
    ->get(["distributor_id", "failure_reason"])
    ->each(function ($r) use ($d) {
      $bv = App\Modules\Compensation\Models\GroupBvDaily::where("distributor_id", $r->distributor_id)
        ->whereDate("date", $d)->first();
      printf("%d\tL=%d\tR=%d\t%s\n", $r->distributor_id,
        $bv->left_bv_paise ?? 0, $bv->right_bv_paise ?? 0, $r->failure_reason);
    });'
```

A distributor with zero BV on both sides that day lost nothing — a cut-off that
would have written `no_match` strands nothing, and the guard refusing it is
harmless. Take them off the list before going further. What remains is the
population the rebuild is for.

**Rebuilding is not automatically the right answer.** It restates rows on a
live money system to recover a known amount. If the whole recoverable sum is
small enough that the client would rather carry it as a goodwill correction,
say so and let them choose — but the choice has to be made with the figure in
hand, recorded, and communicated to the distributor. Silently leaving it is not
one of the options: the day was earned against product sales (hard rule 2), and
DSA §6 undertakes to pay the plan as published.

### 14b. Who authorises it

Two people, and never the same person twice:

| Role | What they are signing |
|---|---|
| **Platform owner** (engineering) | that the diff in step 4 is the whole of the change, and that the rollback in step 7 has been tested on the clone first |
| **Finance authoriser** (`finance.approve` holder, and not the operator running it) | that the money the rebuild moves matches the figure measured in §14a |

The names behind those two roles are a pre-launch fill-in, tracked with the
other named-officer placeholders (Grievance Officer, DPO, Nodal Officer) — the
rebuild is not authorised until they are real names in this table. Separation
of duties is not satisfied by one engineer with both credentials, and that is
not only a matter of discipline: `Gate::before` answers true for every super
staff account (`AppServiceProvider.php:140`), so a platform running on a single
shared super-staff login has **no** second pair of eyes anywhere — not here, and
not on the R-92 reversal approval either. Two distinct super-staff accounts held
by two different people is a launch prerequisite, not a nicety.

The operator records the authorisation in `audit_log` before touching anything,
and again after step 6, with the distributor list and the measured figure.

### 14c. The procedure

It runs against a **clone**; nothing touches production until step 6. Budget one
working day, and do not let step 6 straddle any of these:

- **00:05 IST**, the nightly chain — it would advance the store underneath you
  mid-procedure.
- **Tuesday 03:00**, the weekly payout sweep — it can sweep a credit between
  6.0's check and 6.3's delete, which silently invalidates the gate that 6.0
  exists to be.
- **The 1st and the 8th**, the monthly close and monthly payout.

If the window is too tight, stop after step 5 and resume in the next one. The
clone keeps.

1. **Snapshot production.** This is both the input to the clone and the only
   rollback. Take it with the platform's own backup, verify it restores, and do
   not proceed on an unverified snapshot.
2. **Restore into the rebuild database.** `RecomputeGuard` has three locks and
   all of them must open: the build must not be `APP_ENV=production`,
   `arovolife.recompute.enabled` must be on, and the **connected database name**
   must be in `arovolife.recompute.allowed_databases`. Lock 3 reads the live
   connection, not config, so a stale `DB_DATABASE` will not fool it.
   **This step copies real distributor PII into a lower environment**, which is
   a DPDP processing event in its own right. Flagging it is not a control; these
   are:
   - **Restore without the PII key.** The rebuild reads BV, orders, wallet and
     cut-off rows and never reads the KYC document vault, the bank ciphertext or
     a PAN. So restore the clone with the `APP_KEY`/PII key **absent**, and
     exclude `kyc_document_vault` from the dump. Everything hard rule 8 protects
     is then unreadable by construction rather than by restraint, and the
     rebuild still runs.
   - **48 hours, not "time-boxed".** If it is not finished by then, stop and
     re-authorise rather than letting the copy age.
   - **Its own machine.** Never a staging environment anyone else is using, and
     never one reachable from the public internet.
   - **Same jurisdiction.** The clone stays where production is (DPDP §16).
   - **Open a processing record** naming the purpose, the dataset, the operator,
     the start and end times, and the destruction confirmation from step 8. Tell
     the DPO once that role is a named person.
   - **A leak from the clone is a reportable personal data breach** (§8(6)),
     exactly as it would be from production. It is the same data.
3. **Rebuild on the clone**, from the first wrong day forward:
   ```bash
   php artisan compensation:recompute-all --horizon=now --from=2026-09-14 --windowed
   ```
4. **Diff clone against production** for the affected distributors — every
   `gsb_cutoff_results` row from the first wrong day forward, and the
   `gsb_carryforwards` row. The diff is the change set. If it touches a
   distributor who was not on the §14a list, stop: the rebuild reached further
   than the incident, and nobody has authorised that.

   **Diff the monthly engines too, and read §14d before going on.** Step 3
   rebuilt *every* engine on the clone, not just GSB, and a GSB day carries a
   `repurchase_deduction_paise` — and a zero repurchase wallet is a hard
   eligibility gate for Fortune, Growth Booster, Rank requalification and AO-GO.
   Restoring a missing GSB day into a month that has already closed changes the
   history those engines read without changing what they paid. So compare
   `rank_bonus_results`, the Growth Booster and Fortune tables and ADC for the
   affected distributors as well. **Steps 5–8 restate GSB only.** If the monthly
   diff is non-empty, the rebuild crosses a closed month and needs its own
   decision from §14b before you touch production: re-run that month's close, or
   settle the difference as a correction. Do not proceed on the assumption that
   GSB is self-contained; it is not.

5. **Rehearse steps 6 and 7 on a second restore.** This is what makes the
   procedure rehearsed rather than written down: you are proving the walls in
   §14d behave the way this page says they do, on this release, before doing it
   for real. It has to be a **fresh restore of the step-1 snapshot**, not the
   clone step 3 rebuilt — that clone holds the corrected rows, so rehearsing on
   it never exercises production's actual failed state, and reversing and
   deleting on it would destroy the reference step 7 compares against. Re-run
   the step-4 diff afterwards to confirm the reference clone is intact.

6. **Apply to production**, per affected distributor, in one transaction each,
   in this order — the order is not a preference, it is the only one that works:

   0. **Check what has already been paid, and stop if anything has.** For every
      credit in scope, read `swept_by_payout_batch_id` on its `gsb_credit`
      entry. The weekly sweep selects by *entry* — unswept, not-reversed, earned
      on or before the window end, with no lower date bound
      (`PayoutService.php:404`) — and `scopeNotReversed()` masks a credit only
      by matching `(reference_type, reference_id)`
      (`WalletLedgerEntry.php:110`). Step 6.2 deletes the result row, so the
      replay in 6.4 writes a **new** row id, and the new `gsb_credit` carries a
      reference the old reversal does not mask. It is unswept, it is dated
      inside the next batch's window, and **it will be paid a second time on top
      of the transfer that already went out.** Nothing downstream catches that;
      the scheduler decides it days before anyone looks. So: if every credit in
      scope is unswept, carry on. If any is swept, **that distributor comes out of
      scope entirely** — no reversal, no delete, no replay for them. Their case
      is a finance decision under §14d, and there is no version of this
      procedure that handles it.
   1. **Record the carry-forward the rebuild will restore, before deleting
      anything.** `gsb_carryforwards` holds one rolling row per distributor with
      no history, so once step 6.2 has run, the day-before state exists nowhere
      in production. It lives on the first wrong day's result row, in
      `power_cf_before_paise`, `slab1_weaker_cf_before_paise` and
      `power_side_before` — the columns `computeForDistributor()` rewinds from
      (`GsbCutoffService.php:178`). Read those three from production, reconcile
      them against the same row on the clone, and write both down.
   2. `reverseBonusCredit()` every `credited` **or** `reversed` row from the
      first wrong day forward. Both, not just credited: a row an admin reversed
      earlier in the range is still terminal to the engine, so leaving it in
      place means step 6.4 short-circuits it as `ALREADY_SETTLED` and every
      later date computes off a stale carry-forward. Reversing is idempotent, so
      a row already reversed is a no-op here. The money is neutralised, the
      original credit stays visible on the statement, and `scopeNotReversed()`
      keeps both out of any payout sweep. This is the service call, not the
      admin control: Manual Controls → Request GSB Reversal raises a request for
      a second admin (R-92) and would also flip the row to `reversed`, which
      step 6.3 then has to delete anyway. **Using the service bypasses R-92's
      maker-checker** — §14b's two-role authorisation is what stands in for it,
      and the reversals here are not per-row audited by the controller, so write
      one `audit_log` row per distributor by hand with the before/after digests.
   3. **Delete** those `gsb_cutoff_results` rows. Reversing is not enough:
      `computeForDistributor()` short-circuits both `credited` and `reversed` as
      `ALREADY_SETTLED` (`GsbCutoffService.php:128`), so a reversed row can
      never be replayed. The reversal entries are left pointing at deleted row
      ids — dangling, deliberate, and harmless to every sweep, because the
      reversal already excluded them. The `gsb_reversal_requests` rows for those
      results survive with their id nulled, so who asked and who signed is not
      lost with the row. **Reject any still-pending reversal request for a row
      you are about to delete, first.** Once the row is gone the request can
      never be approved — the approval looks the credit up and will not find it
      — but it stays `pending`, so it sits in the Action Center and on the
      Manual Controls card until somebody rejects it. **Never restore a deleted result row under its original
      id**: the replay would then write a `gsb_credit` whose
      `(type, reference_type, reference_id)` collides with the original on
      `uniq_wallet_ledger_source` and fail mid-range.
   4. Restate the `gsb_carryforwards` row to the figures recorded in 6.1.
   5. Replay the dates in order, oldest first, through the ordinary Engine Runs
      GSB trigger. With no later row present the guard opens, and the engine
      does the crediting through its own normal path — `gsb_credit`, swept by
      the weekly batch, carrying the product-sale chain. **Nothing here writes a
      credit by hand.** That is the point of doing it this way.

7. **Verify, or roll back.** Two checks, and both have to pass.
   - Production `gsb_cutoff_results` and `gsb_carryforwards` for the authorised
     distributors match the clone row-for-row.
   - **Nothing outside the authorised set moved.** A replay runs the whole date,
     and `computeForDistributor()` short-circuits only `credited` and
     `reversed` — a `no_match` row is recomputed, against *today's* running
     personal-BV total (`GsbCutoffService.php:146` reads
     `totalPersonalBvPaise()`, not an as-of-date figure) and today's repurchase
     verdict. A distributor who was idle on that date can therefore flip
     `no_match` → `credited`: a real credit for someone nobody authorised. Take
     a row count and a status checksum for each replayed date before step 6 and
     again after, and stop if they differ outside the §14a list.

   If either check fails, restore the step-1 snapshot; do not iterate on a
   half-applied rebuild.
8. **Destroy every copy**, record the completion in `audit_log`, and tell the
   affected distributors what changed and why. "Every copy" is literal: the
   restored clone database, the dump file it came from, any container volume or
   provider snapshot holding it, the rehearsal restore from step 5, and any copy
   on the operator's own machine. The step-1 production snapshot is itself a
   full PII copy — give it a stated retention and destroy it on that date.
   Record the destruction against the processing record opened in step 2, and
   tell the DPO once that role is a named person.

### 14d. The three things this cannot fix

- **Money already paid to a bank.** Reversal writes a debit; it does not recall
  a transfer. If the affected week's payout batch has already settled, the
  rebuild can restate the ledger but not the bank. This is not a footnote to the
  procedure — it is a **gate before step 6**, because the replay would pay the
  day a second time (see 6.0). Those distributors come out of scope.

  **And there is nowhere for them to go yet.** Neutralising a day that has
  already been paid would mean a new offsetting ledger entry, and no such entry
  type exists — the ledger is append-only, so hand-setting
  `swept_by_payout_batch_id` or editing a line item is not an alternative (see
  "Never do these"), and a payout *hold* cannot be used either:
  `PayoutLineItem::HELD_STATUSES` is derived from distributor state at sweep
  time — web-only, KYC-pending, no bank account, bank decrypt failed — not
  something an admin can set on one entry. This is the same missing capability
  R-92 records as its open gap: a credit paid out against a fraudulent or
  cancelled order has no authorised recovery path today, and the DSA does not
  publish whether netting a negative wallet against future bonuses is permitted
  at all. Until both are settled, an already-paid day is escalated, not fixed.
- **A rebuild range that crosses a closed month.** GSB feeds the repurchase
  wallet, and a zero repurchase wallet gates Fortune, Growth Booster, Rank
  requalification and AO-GO. Steps 5–8 restate GSB only, so restoring a day into
  a closed month leaves those engines' outcomes computed from a history that no
  longer exists. Step 4's monthly diff is what detects it; the decision is
  §14b's.
- **A wrong `group_bv_daily` row.** The rebuild replays the cut-off against the
  BV on record. If the BV itself is wrong, this recovers the same wrong answer
  faithfully. Fix the BV first, then rebuild.

Escalate with §"Escalate with this" below, including the full refusal message,
the §14a listing and the result-row listing above. Until it is resolved, treat
the affected distributors' GSB figures as provisional and say so if they ask.

---

## Never do these

- **Never** run a pool-freezing engine for a period that has not ended
  (`gsb:daily-cutoff` with no `--date` defaults to *today*).
- **Never** approve a payout batch you created or reopened.
- **Never** run `php artisan test` without the `-e` DB overrides, and never
  two test processes at once.
- **Never** run `migrate:fresh`, `db:wipe` or a bulk delete to "clear" a
  failure. Ask first, always.
- **Never** hand-edit `payout_line_items` or wallet ledger rows. The ledger
  is append-only; a correction is a new entry, not an edit.

---

## Escalate with this

If none of the above applies, collect before asking:

```bash
php artisan --version && git rev-parse --short HEAD
php artisan tinker --execute '
  App\Modules\Compensation\Models\EngineRun::latest("id")->limit(10)
    ->get(["id","engine_key","period_start","status","error"])->each(fn($r) =>
      print("{$r->id}\t{$r->engine_key}\t{$r->period_start}\t{$r->status}\t{$r->error}\n"));'
tail -200 storage/logs/laravel.log | grep -i "compensation\|payout\|critical"
```

Plus the batch row if money is involved, and the `audit_log` rows for the
window (`payout.batch.*`, `compensation.nightly_run_*`).

---

## Standing constraints for anyone changing this machinery

- **The per-distributor `PayoutLineItem::create()` is a liveness signal.**
  It is the only thing distinguishing a dead sweep from a slow one (§9). If
  it is ever batched or deferred, the stuck-batch guard must be replaced in
  the same change.
- **`payout_batches.updated_at` is not a heartbeat.** It is written twice per
  sweep, so it dates the start.
- **A backfill anchored on "the newest one that exists" can never reach the
  first one.** Look for that shape in any catch-up loop.
- **A queue worker has no `Auth::id()`.** Any path that becomes
  admin-reachable must bind `EngineRunContext`, or a money-moving run is
  recorded as a cron job.
