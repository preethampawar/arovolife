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
| `failed` | The step threw. **The chain stopped here** — nothing after it ran. | No. Retry button, or the command by hand. |
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
| Chain recorded `skipped`, no banner | Stale queue worker after a deploy | [§3](#3-the-night-was-skipped) |
| Chain recorded `skipped` on dev/staging | A recompute projection is standing | [§3](#3-the-night-was-skipped) |
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

The chain refuses to credit money from a process that may be running
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
> cut-offs are (the chain works forward from the last proven day), but the
> weekly batch for a Tuesday inside the gap needs the date naming — see §7.

### 3b. A standing recompute projection (dev/staging only)

A projection pauses the scheduled engines until it is cleared. Clear it:

```bash
php artisan compensation:recompute-all --horizon=now
```

---

## 4. A step `failed`

**The chain stops at its first failing step**, so everything after it did
not run. Fix the cause, then re-run the whole night — a re-run *resumes*,
skipping every step already recorded succeeded for its period.

```bash
# Preferred: the admin retry button on /admin/compensation/engine-runs.
# It binds the clicking admin as the actor, which the CLI cannot do.

# By hand, if the button is unavailable:
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

### What the retry button does and does not cover

| | |
|---|---|
| Re-runs the failed night, resuming | ✅ |
| Rebuilds a weekly batch that was **never started** | ✅ (`--weekly-payouts-only`) |
| Rebuilds the monthly payout close | ❌ — it self-heals from the 8th |
| Re-enters a batch already `failed` or `processing` | ❌ — §8 / §9 |
| Clears a `skipped` night | ❌ — §3 |

---

## 5. A run is stuck `running`

A `running` row is only ever closed by the process that opened it. If that
process is dead, nothing closes it — and it suppresses the retry button,
because the page reads it as a run still in flight.

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

Build a missed weekly batch (the date is **the Tuesday**, and it must be a
Tuesday):

```bash
php artisan gsb:weekly-payout --date=2026-09-15
```

Build a missed monthly payout close:

```bash
php artisan compensation:monthly-payout-close --month=2026-08
```

> ⚠️ **A batch built from a shell has NO MAKER.** There is no `Auth::id()`
> and no attributed run context on the CLI, so `created_by` lands NULL and
> nothing stops the person who ran it from also approving it. **Whoever runs
> this must not approve the batch.** Use the admin retry button instead
> wherever it applies — it records a maker and enforces the bar.

---

## 8. A payout batch is `failed`

The sweep threw and stopped cleanly. This is the ordinary half-finished
case and it is **fully recoverable** — the re-run picks up where it stopped
and skips every distributor who already has a line item.

```bash
php artisan gsb:weekly-payout --date=2026-09-15              # weekly
php artisan compensation:monthly-payout-close --month=2026-08 # monthly
```

Same maker warning as §7: a second person must approve.

---

## 9. A payout batch is stuck in `processing`

The sweep was **killed outright** — OOM, the queue job's hour-long timeout,
SIGKILL — so it never reached the code that writes `failed`. `processing` is
closed to re-entry, so nothing on the platform can re-enter it: not the
nightly chain (it proves a Tuesday from the *existence* of a batch, not its
status), not the retry button, not the sweep itself.

It appears in the **Action Center → Money → "Payout batches stuck
mid-sweep"** once nothing has been written for it for two hours.

```bash
# 1. Confirm no sweep is actually alive. A long sweep is NOT stuck.
ps aux | grep "[g]sb:weekly-payout"
ps aux | grep -c "[q]ueue:work"

# 2. Reopen it. --actor is REQUIRED and must hold `finance.record`.
php artisan payout:reopen-stuck-batch --type=weekly --date=2026-09-15 --actor=<user id>

# 3. Re-run it as in §8
php artisan gsb:weekly-payout --date=2026-09-15
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

**So the deadline is the next nightly chain run — 00:05 IST.** Not "usually":
`GsbIdleCutoffBatch` writes a `no_match` row for every idle distributor each
night and `no_match` advances the store, so one chain run closes the window for
essentially the whole roster. A failed night found the next morning is already
past retry. Treat a failed cut-off as same-day work, and see **R-91** in the
risk register for what is still owed here — there is no rehearsed production
rebuild behind the escalation below, and writing one is a pre-launch gate.

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

**Production** — `compensation:recompute-all` refuses in production
unconditionally (`RecomputeGuard`), by design: it wipes and rebuilds every
BV-derived row. There is no self-service path. Escalate with §"Escalate with
this" below, including the full refusal message and the result-row listing
above. Treat the affected distributor's GSB figures as provisional until it is
resolved; do not re-run the date by hand and do not reverse-and-recredit to
paper over it, because neither touches the carry-forward store the next night
will read.

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
