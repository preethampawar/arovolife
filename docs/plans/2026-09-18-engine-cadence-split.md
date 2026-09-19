# Three cadences, three orchestrators, and a developer-only retry-and-heal — split the nightly chain into daily, weekly and monthly runs, each rebuildable from scratch

*Planned 2026-09-18 (Fable), revised the same day for the client's retry-and-heal decisions. Implement with Opus per slice; `compliance-officer` reviews before any commit; every commit carries `Compliance-Review:`. Folds in and supersedes the untracked `docs/plans/2026-09-18-nightly-chain-first-month.md`.*

## Context

Since ADR-0015 (2026-09-15) one command, `compensation:nightly-run` at 00:05 IST, runs everything: the repurchase evaluation, the GSB/MSB cut-off, the monthly crediting close on the 1st, the Tuesday weekly payout batch, and the monthly payout close from the 8th. It aborts at the first non-zero step and records one `engine_runs` row whose status is the worst step's.

That shape produced three confusions (answers 2026-09-18): one night row hides three cadences (a refused monthly step marks the daily work failed); the Engines page describes the weekly and monthly engines as "inside the nightly chain" rather than the client's "daily closing, weekly payout on Tuesdays, monthly on the 1st paid on the 8th"; and the staging failures since 8 Sep (a pre-trading month can never be closed or paid). The user chose **three schedules, three orchestrators**, each with its own run row, alerts and self-healing.

The same day the user decided how a failed run is repaired (verbatim decisions, 2026-09-18): every run gets a **retry-and-heal** that rebuilds *that period only* — a night's retry clears that day's calculations and recomputes them; a week's retry removes the stale batch and re-runs that week's payout; a monthly retry re-runs only the failed month, clearing previous failed attempts' rows; the 8th's payout retry recalculates every payment and re-freezes the per-distributor amounts. Once finance freezes a month's payout, every monthly engine for that month is disabled until the next 1st 00:00 IST. On the 1st the weekly run (Tuesday) waits for the nightly run to be green, and the monthly close waits for both; the 8th's payout is independent. **The retry belongs to the developer role only** — no admin access — and it must warn the developer, before and after, which other runs are affected and must be run next.

**Not a new rule (confirmed 2026-09-18):** "if the 1st falls inside the repurchase cycle the bonus is calculated on the next 1st" is *not* a client rule. Nothing in the engines' arithmetic changes in this plan — only who fires them, when, and how a period is rebuilt.

**Where this plan is deliberately narrower than the ask, it says so** — see *Deviations from the ask*. The narrowings all come from three invariants the platform already holds: the carry-forward store is rolling (R-91), the wallet ledger is append-only for money that moved, and a payout batch that finance signed off is immutable.

## Decisions taken with the user (2026-09-18, all resolved — nothing here is open for the implementer)

| # | Decision | Resolution |
|---|---|---|
| DN-0 | Does the weekly run wait for tonight's nightly run on **every** Tuesday, or only when the 1st is a Tuesday? | **Every Tuesday** (user, option A). One rule every week: daily green first, then the batch; a deferred Tuesday is built on the first night the nightly run is green, or by hand. A payout may slip one day; never a figure. |
| DN-1 | A never-approved batch's own sweep-time debits (`payout_debit`, `admin_charge_debit`, `tds_debit`, `income_cap_forfeit`) on rebuild: **delete** them, or write offsetting `reversal` rows? | **Delete** (user, option A). They are the batch's projection of a payout that never happened (written in the same transaction as the line item), exactly like the line items; TDS and admin-charge reports sum these types and would need a new netting rule for reversal rows; the audit row carries their ids, sums and digest. Credits are never deleted — they are un-swept. `compliance-officer` must sign this specifically. |
| DN-2 | A batch in `failed`/`partially_failed` that was **already approved** (bank rejected transfers) is *frozen* and cannot be rebuilt; the remedy stays the per-line retry. | Accepted — money instructions left the company; the discriminator is `approved_at IS NOT NULL`, not the status alone. |
| DN-3 | `EngineStatusService::hasSucceededRun()` and `completedCutoffDatesBetween()` change to "the **latest finished attempt** decides" (a failed re-run un-proves a period; `skipped`/`running` ignored). Platform-wide effect: after a failed re-run of any step the orchestrators re-run that step once more (idempotent). | Accepted — it is the rule `MonthlyEngineCompletionGate::unresolvedFailure()` already applies, and without it a rebuild that wiped a period and then failed leaves the period reading "computed" with no rows. |
| DN-4 | Developer gate on the rebuild routes: Spatie `role:developer` middleware (403 for every other role, as `plan-settings` and `payout-settings` already do) plus a controller `abort_unless(hasRole('developer'), 404)`. | Accepted; the pre-existing 403 nuance stands; no new permission is invented. |
| DN-5 | A month rebuild is refused once the **next** month has closed (its engines read this month's ranks, wallet-zero verdicts and Fortune roster). Beyond that the only path is the client's correction decision (R-91 open question). | Accepted. |
| DN-6 | Which moment is "the admin freezes the payout" for the frozen-month lock (D9)? | **Finance approves the batch** (user, option A): `approved_at IS NOT NULL`, or status ∈ {approved, dispatched, completed, processing}. A pending batch does not freeze the month, so `compensation:rebuild-payout` can still run; after approval nothing touches the month's figures again. |

## Cross-cutting requirements (user, 2026-09-18 — apply in every slice)

**Fail-safe.** Every guard refuses rather than guesses: a rebuild that cannot prove its preconditions (newest night, unfrozen batch, no gateway events, no swept credit, rewind readable) writes nothing and says why; every wipe is one transaction that rolls back whole; a wipe followed by a failed re-run leaves the period reading "not computed" (D13) so the scheduled runs re-attempt it; a deferral is a `skipped` row and an alert, never a failed night; the frozen lock has no override; the two-step preview compares a fingerprint so a confirm never acts on state that changed. No new code path may credit a wallet by hand or delete a credit.

**Single source of truth.** One class answers each question, and every caller asks that class — never a second copy of the predicate:
- "is this period computed?" → `EngineStatusService` (latest-attempt rule, D13) — the closes' resume logic, the cut-off frontier, the planners, the prerequisites and the rebuild refusals all call it.
- "is every owed day cut off?" → `MonthCutoffCoverage` (used by `MonthlyCloseCommand::preflight()` AND `MonthlyRunPlanner`).
- "did tonight's runs succeed?" → `RunPrerequisites`.
- "is this month frozen?" → `FrozenPayoutGuard` (the seven engines, the close, both month rebuilds, the manual trigger's period parser).
- "which Tuesdays are owed?" → `WeeklyRunPlanner` (the scheduler predicate, the command, `RunPrerequisites`).
- "which engines exist, which are roots, which are rebuilds?" → `EngineRegistry` (`rootOrchestratorKeys()`, `rebuildKeys()`); the scheduler, the skipped-run listener, the health service and the controller derive from it, never from a hand-written list.
- "what does a rebuild remove?" → the rebuilder's `plan()`; `wipe()` executes the same scopes (share the query builders — a plan/wipe drift is a bug).
- "how is a batch un-built?" → `PayoutService::unbuildBatch()` only (week, month and payout rebuilds all call it).
- Copy: schedule wording comes from `EngineDefinition::scheduleText()`; help and runbooks describe, they do not restate times the registry owns.

**No stale leftovers.** When the last slice lands, nothing of the old design remains in code, config or copy. Sweep (grep the whole repo, `docs/` included) and remove: `retry-chain`, `retryChain`, `RetryNightlyChainJob`, `failedChainPayload`, `payouts_in_scope`, `--with-payouts`, `--without-payouts`, `--weekly-payouts-only`, `payoutOption`, `RecordSkippedNightlyRun`, `weeklyPayoutDays`, `monthlyPayoutMonths`, `monthIsWhole`, the command-level `MAX_BACKFILL_WEEKS`, "in the nightly chain", "inside the nightly chain", "Retry this night", `compensation.nightly_chain.retried` (code references only — historical `audit_log` rows are facts and stay). The stale scheduler comments in `routes/console.php` (digest "after every overnight engine — monthly payout close is the last"), the `--with-payouts` sentence in `help/compensation.md` and the runbook's retry-button section are rewritten, not appended to. Superseded plan docs are marked superseded, not deleted (they are history). Staging's stale rows (failed `compensation.nightly-run` runs and `compensation.nightly_run.aborted` audit rows since 8 Sep) are not deleted by code: D5 resolves the failed root row on the first successful run and the digest's seven-day window drops the alerts; any manual clean-up on staging is a rollout step with the user's explicit yes.

## Review amendments (S1 review, 2026-09-18 — binding on every later slice)

- **A1 — an out-of-order cut-off refusal is `skipped`, not `failed`.** `GsbCutoffService` throws `CutoffReplayedOutOfOrder` when a passed day is re-run under a later advancing row (R-91). Under D13 a `failed` row for that day would un-prove a correctly completed cut-off and make the month unclosable except by `--force`. `GsbDailyCutoffCommand` therefore catches that exception class separately and records the run as `skipped` (an ordering decision, not an attempt on the period); `completedCutoffDatesBetween()` keeps listing the day. Test: "an out-of-order refusal records skipped and the day stays proven". **S4 belt:** `AdminEngineRunsController::parsePeriodOrFail()` refuses a manual `gsb.daily-cutoff` trigger for a date that has a later advancing cut-off row, with the R-91 sentence.
- **A2 — a frozen month owes no close.** `MonthlyRunPlanner::closeOwed()` and `closePhase()` return "nothing owed" when `FrozenPayoutGuard::frozenBatchFor($month)` is not null, before any other check. The §27 assumption that a frozen month always has a succeeded close row is false (engines run by hand; a close that failed mid-way and was finished by hand; every month after the rollout recompute, which truncates `engine_runs` and replays leaf engines only). Without A2 the monthly run would abort nightly on the frozen refusal with no way to clear it. Test: "a frozen month with no close row is not owed a close".
- **A3 — one anchor for "the platform's first owed day".** `CompensationRecomputeRunner::resolveFrom()`'s default replay start becomes the earlier of `PlatformStart::firstDay()` and the first BV day, so a full recompute writes cut-off proof for every day `MonthCutoffCoverage` will later ask about. Otherwise the month containing the first sale could be undeferrable after rollout step 5 when a distributor pre-dates the first sale inside it. Test in the recompute suite: with a distributor on the 5th and the first sale on the 20th the replay starts on the 5th.
- **A4 — `RunPrerequisites::nightlyRunRefusal()` diagnoses tonight's attempt only**: the "last attempt" fragment reads the latest finished run whose `period_start` is `$night`, and says "it has not run at all" when there is none — never another night's row.
- **A6 (compliance F1, High) — the cut-off frontier rule.** In `EngineStatusService::completedCutoffDatesBetween()` a failed latest attempt un-proves a day ONLY when no later day in the range is proven (the day is the frontier). Behind the frontier the day cannot be rebuilt (R-91), its rows are intact, and the close must proceed on them; otherwise a `finance.record` holder re-running a past day from the Engine Runs page could block a month's crediting for ever, with `--force` as the only remedy. `hasSucceededRun()` keeps the general latest-attempt rule (its callers are idempotent resume loops). A1 (skipped on out-of-order) stays as well. Tests: a failed re-run behind the frontier leaves the day listed; a failed latest attempt on the newest day drops it (D13's real purpose: a wiped-then-failed newest night reads "not computed").
- **A7 (compliance, Medium) — the payout phase pays oldest first, never past a deferral.** The monthly sweep window is `earnedForMonthOrBefore($batchMonth)`, so a batch for month M also collects every unswept monthly credit of earlier months. `MonthlyRunPlanner::payoutPhase()` therefore does NOT step the main month while the lookback month produced a `payout` deferral; the main month is recorded as its own deferral ("{M} waits on {M−1}: …") so the alert names both. Test: lookback deferred ⇒ main month not stepped and deferred by name; lookback runnable ⇒ both stepped, oldest first.
- **A8 (compliance, Medium) — the frozen refusal is audited.** `MonthlyCloseCommand` routes the frozen / closed-to-credits refusal through `abort($month, 'frozen', $reason)` with `'frozen'` among the stages that record `noteSkipped` (not `noteFailed`), so `compensation.monthly_close.aborted` is written with the reason like every other refusal. Test asserts the audit row.
- **A9 (compliance, Medium) — deferred-close alerts do not go silent.** `NightlyRunAlert::monthCloseDeferred()` dedupes on `['date', 'month', 'cause']` (one row per night per cause), not `['month', 'cause']`, because the digest reads a seven-day window and a month deferred on the 1st must still be in the 9th's email.
- **A10 (compliance, Medium) — a built-but-unapproved month is closed to new credits.** Between the 8th's sweep (`payout_batches.status = pending`, `processed_at` set) and finance's approval the month is not frozen (DN-6 keeps it rebuildable) but nothing may be credited into it: the batch would never pick the credit up, and finance would approve a batch that no longer matches the ledger. `FrozenPayoutGuard` gains `isClosedToCredits(PayoutBatch): bool` (pending with `processed_at !== null`) and `creditingRefusal(Carbon $creditingMonth): ?string` = the frozen refusal, else "…batch #{id} was built on {date} and awaits approval; nothing may be credited into {M} now — after this engine is fixed, rebuild the payout (`compensation:rebuild-payout --month={M}`) so the batch matches the ledger". Callers of `creditingRefusal()`: the seven monthly engine commands (S2, §28), `MonthlyCloseCommand`, and `AdminEngineRunsController::parsePeriodOrFail()` for manual engine triggers. The rebuilders keep `isFrozen()` / `refusal()` (a rebuild un-builds the pending batch first, so the window does not apply to them). Both questions live in the one class.
- **A11 (compliance, Low) — `FrozenPayoutGuard::batchFor()` orders by `id` desc** so a violated one-batch-per-date invariant can never make the guard read an arbitrary row.
- **A12 (compliance) — R-101 ships with the S1 commit, not S5**, because D1/D2/D13 go live with it. Content: the launch-month relaxation (D2) and its bound (days with distributors are still owed); the sales-free wave-through (D1) and the exact statement that the resulting batch is pending, pays nobody UNLESS earlier months left unswept credits, and (before A7) could carry a deferred earlier month's partial credits; D13's coverage semantics with A1/A6. The S1 commit body states: the launch-month fix is not yet live on the scheduled path (`NightlyRunCommand::monthIsWhole()` still counts calendar days until S2), and the frozen lock guards the close only until S2. R-102 stays an S3/S5 obligation.
- **A13 (S2 obligation)** — the weekly run must exit 0 with a `succeeded` root row when the GSB flag is off on a Tuesday (its leaf `skipped`), and `WeeklyRunCommandTest` must prove it; otherwise a flag-off Tuesday blocks every monthly close through `RunPrerequisites::weeklyRunRefusal()`.
- **A14 (S3 obligation)** — DN-1 (deleting a never-approved batch's own debit rows) needs its own explicit `compliance-officer` sign-off in the S3 review; the S1 review does not grant it. The S5 runbook notes that an alert's reason line may embed an engine error naming an ADN (business identifier, admin-only audit log; no PII field can reach it).
- **A5 (note)** — the D1b bound the S1 implementer added to `NightlyRunCommand::monthlyPayoutMonths()` is untested live code on the payout path; S2 deletes the method. S2 must land.

## The three cadences (IST)

| Orchestrator | Command | Scheduler entry | Prerequisite (ordering guard) | Steps, in order | Self-heal | Developer rebuild |
|---|---|---|---|---|---|---|
| **Nightly run** (kept, narrowed) | `compensation:nightly-run --date=<tonight>` | daily 00:05, `withoutOverlapping`, `when(engines may run)`, background | none | 1. `repurchase:evaluate --date=<tonight>` 2. `gsb:daily-cutoff --date=<each owed day>` (backfill unchanged) | missed nights backfilled up to 31 days | `compensation:rebuild-night --date=<night N>`: wipes cut-off day D = N−1, rewinds carry-forward, re-runs the night with `--restart` |
| **Weekly run** (new) | `compensation:weekly-run --date=<tonight>` | daily 03:00, `when(engines may run && WeeklyRunPlanner::isDue)`, background | tonight's `compensation.nightly-run` **succeeded** (a succeeded row dated tonight, started after 00:00 tonight); otherwise deferred: `skipped` row + `NightlyRunAlert::weeklyDeferred()` | `gsb:weekly-payout --date=<each owed Tuesday>`, oldest first | a Tuesday whose batch was never built is built the next night, still dated that Tuesday | `compensation:rebuild-week --date=<Tuesday T>`: un-builds T's unapproved batch (un-sweeps its credits, deletes its debits/lines/row), re-runs `gsb:weekly-payout --date=T` |
| **Monthly run** (new) | `compensation:monthly-run --date=<tonight>` | daily 04:00, `when(engines may run && MonthlyRunPlanner::isDue)`, background | **close phase:** tonight's nightly run succeeded AND, if a Tuesday batch is owed tonight, tonight's `compensation.weekly-run` succeeded; **payout phase:** no dependency on tonight's runs (the 8th is independent, it pays what the 1st credited) | phase 1: `compensation:monthly-close --month=<month that just ended>` when unclosed, every owed day cut off, prerequisites met; phase 2 (planned *after* phase 1): `compensation:monthly-payout-close --month=<crediting month>` from the 8th while no batch exists and the completion gate is open, plus the one-month lookback | re-attempted every night it is due; a deferral is an alert and a `skipped` row, never a failed night | `compensation:rebuild-month --month=M`: un-builds M's unapproved batch if one exists, wipes M's seven engines' rows, re-runs the close with `--restart`; `compensation:rebuild-payout --month=M`: un-builds M's unapproved batch and re-runs the payout close, re-freezing per-distributor amounts |

Why these clocks, and why no lock:

- **03:00 for the weekly run** is the position `gsb.weekly-payout` already declares. The batch dated Tuesday T sweeps only Group A entries with `earned_on ≤ T−7` (`PayoutBatch::weeklyEarningWindow()`), so it never depends on tonight's cut-off, which writes `earned_on = T−1`. The prerequisite on tonight's nightly run is therefore the client's *ordering* rule, not a data dependency — which is why a deferral costs one night and never a figure.
- **04:00 for the monthly run** is the position `payout.monthly` already declares. On the 8th this keeps today's sweep order (weekly 03:00, then the monthly payout); the two sweeps share the blocking lock `compensation:payout-sweep` (`PayoutService::withSweepLock()`, 120 s wait), which serialises them against the ₹50L income cap. On a Tuesday that is also the 1st the weekly sweep runs *before* the crediting close; safe because the cap headroom counts **swept rows only** (`PayoutService::monthToDateCappedGrossPaise()`) and the close writes unswept Group B credits.
- **The ordering guard is the run log, not a clock.** `RunPrerequisites` (new, §26) asks "did tonight's nightly run succeed?" from `engine_runs`; `MonthlyRunPlanner` also asks `MonthCutoffCoverage` whether every owed day is cut off. A shared cache lock was rejected: the default cache is Redis shared with eight other apps under `allkeys-lfu` (ADR-0011).
- **The replay is untouched by construction.** `EngineReplayService` fires leaf engines only (`! $definition->isOrchestrator`) ordered by declared `cadence->time`; no leaf position changes and the four rebuild commands are orchestrators with `EngineCadence::unscheduled()`, so replayed figures stay byte-identical. Do not re-run the A/B figure diff.

## Decisions

**D1 — A month with no product sales owes no crediting** (folded, unchanged): `MonthlyEngineCompletionGate::blockingFailure()` returns `null` when the crediting month has no `bv_ledger_entries` and no wallet credit stamped with that `bonus_month` (belt). D1b: the lookback branch is bounded by the same test.

**D2 — A month is owed cut-offs only from the platform's first distributor** (folded, one refinement): `PlatformStart::owedFrom()` returns **null** when the platform has no distributor at all.

**D3 — Every deferral is an alert and a `skipped` run, never a failed night.** Now including the two prerequisite deferrals (weekly waits on nightly; close waits on nightly and weekly).

**D4 (replaced) — Retry means rebuild the period from scratch, and only the developer may do it.** Four commands, one per period kind, each: preflight → plan (refusals, row counts, downstream warnings) → wipe that period's derived rows in one transaction → re-run the ordinary command for that period → print the downstream warnings. Idempotent by construction: every attempt wipes the previous attempt's rows first, so "run again and again" lands on the same figures. Exposed on the Engine Runs page **only** to `developer` (zero trace for every other role), and on the CLI with a mandatory `--actor` naming a developer. `--with-payouts`, `--without-payouts`, `--weekly-payouts-only`, `RetryNightlyChainJob` and the `retry-chain` route are deleted.

**D5 — A root orchestrator's failed run resolves on its next success, whatever the period.** `unresolvedFailureQuery()` drops `period_start` equality for root orchestrators.

**D6 — Cadence text names the orchestrator.** "Tuesdays, in the Weekly Run from 03:00 IST", etc.

**D7 — Registry positions do not move.** Only `orchestratedBy` changes for three leaves; four unscheduled rebuild orchestrators are added.

**D8 — Scope of the monthly self-heal is one month back.**

**D9 — The frozen-payout lock is per crediting month and has no override.** A month M is *frozen* the moment its monthly batch (dated the 1st of M+1) leaves `pending` under finance's hand: `approved_at IS NOT NULL`, or status ∈ {`approved`, `dispatched`, `completed`, `processing`}. While frozen, every monthly engine for M, the close for M and both month rebuilds for M refuse — `--force` does not pass it. "Enabled again on the 1st 00:00 IST for the next month" needs no mechanism: the next month has no frozen batch and `OpenMonthGuard` already refuses it until it has ended.

**D10 — A rebuild never deletes a credit and never touches money that left.** Credits swept by an unfrozen batch are *un-swept*; credits swept by a frozen batch make the rebuild refuse. A rebuild deletes only rows the re-run re-derives (result rows, pools, rosters, line items, the batch row, a pending batch's own debits — DN-1) and re-creates them through the engines' ordinary product-sale-chained path; no credit is ever written by hand (R-91 procedure, kept).

**D11 — A night can be rebuilt only while it is the newest night.** The carry-forward store is rolling; once any cut-off row exists for a later date (idle `no_match` rows included) the rebuild refuses, exactly as `GsbCutoffService`'s out-of-order guard does. This is R-91's window, made a first-class refusal with a message. Same-day work, deadline 00:05 IST.

**D12 — A month can be rebuilt only while nothing later has been built on it**: not frozen (D9), no credit of M swept by a frozen batch, and no crediting engine or close has succeeded for M+1.

**D13 — The latest finished attempt decides whether a period is computed** (DN-3). One rule in `EngineStatusService`, read by the resume logic of both closes, the cut-off frontier, the planners and the prerequisites.

**D14 — The rebuild's actor is the batch's maker.** A rebuilt batch records the developer as `created_by`; `HandlesPayoutBatchActions::approve()` then refuses their approval (R-81 holds).

## Permission matrix

| Capability | Who | Change |
|---|---|---|
| See the three runs' failure banners (information, no control) | whole admin family | new (was: nightly only, with a button for `finance.record`) |
| Rebuild night / week / month / payout (preview + confirm) | `developer` only — `role:developer` route middleware + controller 404 + `@developer` in Blade | new; replaces the `finance.record` retry |
| Rebuild from the CLI | shell operator naming `--actor=<developer user id>`; refused otherwise | new |
| Approve a rebuilt batch | `finance.approve` holder who is not the maker (the developer who rebuilt it cannot) | unchanged (R-81) |
| Per-engine manual trigger (production) | `finance.record`, unchanged; **refuses a frozen month** | narrowed |
| Recompute / purchase reset (test envs) | unchanged | — |

Nothing an `admin-finance`, `admin-compliance`, `admin-operations` or `admin` holder could do before becomes possible now; the retry that `finance.record` had is removed from them.

## File changes

| # | Path (repo root) | New/Mod | Change |
|---|---|---|---|
| 1 | `app/app/Modules/Compensation/Support/PlatformStart.php` | **New** | `firstDay()`, `owedFrom()` (null when no distributor) |
| 2 | `app/app/Modules/Compensation/Support/MonthCutoffCoverage.php` | **New** | owed days vs completed cut-offs; `isWhole()`, `missing()`, `refusal()` |
| 3 | `app/app/Modules/Compensation/Support/MonthlyEngineCompletionGate.php` | Mod | D1 via `owedNoCrediting()` |
| 4 | `app/app/Modules/Compensation/Support/NightlyRunAlert.php` | Mod | `ACTION_PAYOUT_DEFERRED` + `payoutDeferred()`; **`ACTION_WEEKLY_DEFERRED` + `weeklyDeferred()`**; `skippedNight()` names the orchestrator; `monthCloseDeferred(…, ?int $missingDays, string $cause)`; `record()` gains `$uniqueBy` |
| 5 | `app/app/Modules/Compensation/Support/WeeklyRunPlanner.php` | **New** | `owedTuesdays()`, `isDue()`, `flagIsOff()` moved from `NightlyRunCommand` |
| 6 | `app/app/Modules/Compensation/Support/MonthlyRunPlanner.php` + `MonthlyRunPhase.php` + `MonthlyRunDeferral.php` | **New** | `closePhase()` (now applies `RunPrerequisites`), `payoutPhase()`, `isDue()` |
| 7 | `app/app/Modules/Compensation/Console/Commands/Concerns/OrchestratesEngineSteps.php` | **New** | trait shared by the three date-typed orchestrators |
| 8 | `app/app/Modules/Compensation/Console/Commands/NightlyRunCommand.php` | Mod | steps 1–2 only; payout switches deleted; keeps `--restart` (the night rebuild passes it) |
| 9 | `app/app/Modules/Compensation/Console/Commands/WeeklyRunCommand.php` | **New** | `compensation:weekly-run {--date=} {--force}`; **prerequisite deferral** |
| 10 | `app/app/Modules/Compensation/Console/Commands/MonthlyRunCommand.php` | **New** | `compensation:monthly-run {--date=} {--force}` |
| 11 | `app/app/Modules/Compensation/Console/Commands/MonthlyCloseCommand.php` | Mod | coverage via `MonthCutoffCoverage`; **`FrozenPayoutGuard` refusal before the preflight, not overridable by `--force`** |
| 12 | `app/app/Modules/Compensation/Support/EngineRegistry.php` | Mod | two run orchestrators; **four rebuild orchestrators** (`unscheduled`, `developerOnly: true`); `rootOrchestratorKeys()` = orchestrator, un-orchestrated **and scheduled**; `rebuildKeys()` |
| 13 | `app/app/Modules/Compensation/Support/EngineCadence.php` + `EngineDefinition.php` | Mod | `describe(?string $chainStartsAt, ?string $chainLabel)`; `chainRoot()`; **`EngineDefinition::$developerOnly = false`** |
| 14 | `app/routes/console.php` | Mod | three compensation entries |
| 15 | `app/app/Modules/Compensation/Listeners/RecordSkippedOrchestratorRun.php` (renamed) + `app/app/Providers/AppServiceProvider.php` | Mod | any scheduled root; **register the four rebuild commands in the console command list** (memory: module commands must be listed) |
| 16 | `app/app/Modules/Compensation/Services/EngineStatusService.php` | Mod | `chainStepOutcomes()` scoped; D5; **D13 latest-attempt semantics in `hasSucceededRun()` / `completedCutoffDatesBetween()`**; **`hasSucceededRunTonight()`**, **`failedRootRun(string $key)`** (`failedChainRun()` delegates), **`latestFinishedRun()`** |
| 17 | `app/app/Modules/Compensation/Jobs/RetryNightlyChainJob.php` | **Delete** | replaced by 30 |
| 18 | `app/app/Modules/Compensation/Http/Controllers/Admin/AdminEngineRunsController.php` | Mod | `retryChain()` deleted; `index()` gains `failedRuns` (three roots) and the developer `rebuildPanel`; **`rebuildPreview()`, `rebuild()`**; `parsePeriodOrFail()` refuses a frozen month |
| 19 | `app/resources/views/admin/compensation/engine-runs/index.blade.php` | Mod | informational banners for the three runs (no control); **developer-only rebuild panel + preview card** under `@developer` |
| 20 | `app/app/Modules/Compensation/Services/EngineHealthService.php` | Mod | headlines/steps for payout-deferred, weekly-deferred, prerequisite-deferred close, orchestrator-named skip; remedy copy points at "the platform team" never at a control |
| 21 | `app/tests/...` | Mod/New | see *Tests* |
| 22 | `docs/architecture/adr-0016-three-engine-cadences.md` (**New**), `adr-0015-nightly-engine-chain.md` (status line) | Docs | ADR-0016 gains "Retry-and-heal", "Ordering prerequisites", "Frozen-payout lock" sections |
| 23 | `docs/runbooks/engine-failure-triage.md`, `docs/runbooks/artisan-commands.md`, `app/resources/help/compensation.md`, `app/resources/help/payout-operations.md` | Docs | see §22–25 |
| 24 | `docs/compliance/risk-register.md` | Docs | R-101, **R-102 (the production rebuild path)**; addenda R-81, R-89, R-90, **R-91** |
| 25 | `docs/plans/2026-09-18-nightly-chain-first-month.md` (superseded), `docs/plans/2026-09-18-engine-cadence-split.md` (copy of this plan) | Docs | |
| 26 | `app/app/Modules/Compensation/Support/RunPrerequisites.php` | **New** | `nightlyRunRefusal(Carbon $night): ?string`, `weeklyRunRefusal(Carbon $night): ?string` |
| 27 | `app/app/Modules/Compensation/Support/FrozenPayoutGuard.php` | **New** | `frozenBatchFor(Carbon $creditingMonth): ?PayoutBatch`, `isFrozen(PayoutBatch)`, `refusal(Carbon $creditingMonth): ?string` |
| 28 | `app/app/Modules/Compensation/Console/Commands/{RankCheckCommand,RankBonusRunCommand,GbbMonthlyRunCommand,FortuneBonusEnrollCommand,FortuneBonusRunCommand,AdcBonusRunCommand}.php`, `app/app/Modules/Commerce/Console/Commands/PurchaseOffersMonthlyRunCommand.php` | Mod | one `FrozenPayoutGuard::refusal($month)` check each, immediately after the `OpenMonthGuard` check; `noteSkipped`, exit FAILURE |
| 29 | `app/app/Modules/Compensation/Services/Recompute/CarryforwardRewind.php` (**New**) + `WindowedStateWiper.php` (Mod) | Refactor | `readFrom(Carbon $dayStart)` / `apply(array, Closure)` extracted verbatim from the wiper's two private methods; the wiper delegates. Behaviour unchanged; `WindowedRecomputeTest` stays green |
| 30 | `app/app/Modules/Compensation/Jobs/RebuildPeriodJob.php` | **New** | `(string $kind, string $period, int $actorId, string $chainId)`, queue `compensation`, `tries 1`, timeout 3600 (7200 for `month`), binds `EngineRunContext`, `Artisan::call(<rebuild signature>, [periodOption => period, '--yes' => true])`, `failed()` closes `running` rows by `chain_id` |
| 31 | `app/app/Modules/Compensation/Services/Rebuild/RebuildKind.php` (enum), `RebuildPlan.php` (readonly DTO), `RebuildPreflight.php`, `NightRebuilder.php`, `MonthRebuilder.php`, `RebuildPlanner.php` | **New** | the plan/wipe logic (§28–§31) |
| 32 | `app/app/Modules/Compensation/Services/PayoutService.php` | Mod | **`unbuildBatch(PayoutBatch $batch, int $actorId, string $reason): array`** under the sweep lock; `laterBatchesAfter(PayoutBatch): Collection` |
| 33 | `app/app/Modules/Compensation/Console/Commands/{RebuildNightCommand,RebuildWeekCommand,RebuildMonthCommand,RebuildPayoutCommand}.php` + `Concerns/RebuildsPeriod.php` | **New** | `compensation:rebuild-night {--date=} {--actor=} {--yes}`, `compensation:rebuild-week {--date=} {--actor=} {--yes}`, `compensation:rebuild-month {--month=} {--actor=} {--yes}`, `compensation:rebuild-payout {--month=} {--actor=} {--yes}` |
| 34 | `app/routes/web.php` | Mod | delete `retry-chain`; add `Route::middleware('role:developer')->group(...)` with `POST engine-runs/rebuild/preview` and `POST engine-runs/rebuild` |
| 35 | `app/app/Modules/Compensation/Support/EngineRunContext.php` | — | unchanged; used as today |

Not touched: `PayoutService` sweep bodies, `EngineReplayService`, `RecomputeGuard`/`RecomputeState` (ADR-0014 stands: `compensation:recompute-all` stays refused in production), `MonthlyPayoutCloseCommand`, `GsbCutoffService`, `RepurchaseCycleService`, `WalletService`, the dashboard (its Engine panel renders `chainAlerts`; the uncommitted dashboard work in the tree is left exactly as it is).

---

## Specifics

### 1. `PlatformStart` (new)

As in the first-month plan §2, with `owedFrom()` returning `?Carbon`:

```php
public static function owedFrom(Carbon $monthStart): ?Carbon
{
    $first = self::firstDay();               // Distributor::query()->min('effective_date')
    if ($first === null) { return null; }
    $lastDay = $monthStart->copy()->endOfMonth()->startOfDay();
    if ($first->greaterThan($lastDay)) { return null; }
    return $first->greaterThan($monthStart) ? $first : $monthStart->copy();
}
```

Not cached; `distributors.effective_date` has no index (follow-up).

### 2. `MonthCutoffCoverage` (new)

```php
final readonly class MonthCutoffCoverage
{
    private function __construct(public Carbon $month, public ?Carbon $owedFrom, public Carbon $lastDay, public int $owedDays, public int $coveredDays) {}

    public static function for(Carbon $month, EngineStatusService $status): self
    {
        $monthStart = $month->copy()->startOfMonth();
        $lastDay = $monthStart->copy()->endOfMonth()->startOfDay();
        $owedFrom = PlatformStart::owedFrom($monthStart);
        if ($owedFrom === null) { return new self($monthStart, null, $lastDay, 0, 0); }
        $owedDays = $lastDay->day - $owedFrom->day + 1;
        $covered = count(array_unique($status->completedCutoffDatesBetween($owedFrom, $lastDay)));
        return new self($monthStart, $owedFrom, $lastDay, $owedDays, min($covered, $owedDays));
    }
    public function missing(): int { return max(0, $this->owedDays - $this->coveredDays); }
    public function isWhole(): bool { return $this->missing() === 0; }
    public function refusal(): string { /* '%d of the %d days in %s have no completed cut-off. …' — keep the phrase '1 of the 31 days in August 2026' intact (MonthlyCloseCommandTest) */ }
}
```

### 3. `MonthlyEngineCompletionGate` (D1)

Add `public static function owedNoCrediting(Carbon $month): bool` — no `bv_ledger_entries` with `effective_at` inside the month (query directly; there is no `dateRange` scope) and no wallet credit with `bonus_month = month`. `blockingFailure()` returns null early when true. Docblock gains the fourth outcome bullet.

### 4. `NightlyRunAlert`

```php
public const ACTION_PAYOUT_DEFERRED = 'compensation.nightly_run_payout_deferred';
public const ACTION_WEEKLY_DEFERRED = 'compensation.weekly_run_deferred';
public const ACTIONS = [ …existing three…, self::ACTION_PAYOUT_DEFERRED, self::ACTION_WEEKLY_DEFERRED ];

public static function skippedNight(Carbon $night, string $reason, string $orchestratorKey): void
    → record(ACTION_SKIPPED_NIGHT, $night, $reason, ['date', 'orchestrator'], ['date', 'orchestrator']);

/** Once per MONTH per cause. $cause ∈ 'coverage' | 'cutoff_in_flight' | 'prerequisite'. */
public static function monthCloseDeferred(Carbon $night, Carbon $month, ?int $missingDays, string $cause, string $reason): void
    → record(ACTION_MONTH_DEFERRED, $night, $reason, ['date', 'month', 'missing_days', 'cause'], ['month', 'cause']);

/** Once per night per month — the standing alert. */
public static function payoutDeferred(Carbon $night, Carbon $creditingMonth, ?string $engineKey, string $reason): void
    → record(ACTION_PAYOUT_DEFERRED, …, ['date', 'month', 'engine_key'], ['date', 'month']);

/** Once per night: the Tuesday batch(es) that waited on tonight's nightly run. @param list<Carbon> $tuesdays */
public static function weeklyDeferred(Carbon $night, array $tuesdays, string $reason): void
    → record(ACTION_WEEKLY_DEFERRED, …, ['date', 'tuesdays' => [...Y-m-d]], ['date']);

private static function record(string $action, Carbon $night, string $reason, array $details, array $uniqueBy = ['date']): void
    // dedupe: where('action') + where("details->{$key}", $details[$key]) for each $uniqueBy key
```

`backfillGap()` unchanged.

### 5. `WeeklyRunPlanner` (new)

```php
final class WeeklyRunPlanner
{
    public const MAX_BACKFILL_WEEKS = 4;
    public function __construct(private readonly EngineStatusService $status) {}
    public function isDue(Carbon $night): bool { return $this->owedTuesdays($night) !== []; }
    /** Moved verbatim from NightlyRunCommand::weeklyPayoutDays(): same $latest, same flag-off rule, same frontier search, same null-frontier branch. No console output. @return list<Carbon> */
    public function owedTuesdays(Carbon $night): array { … }
    public function flagIsOff(): bool { … }   // EngineRegistry::get('gsb.weekly-payout')->featureFlagClass
}
```

### 6. `MonthlyRunPlanner` (new) + DTOs

`MonthlyRunDeferral(kind, month, reason, ?engineKey, ?missingDays, ?cause)`; `MonthlyRunPhase(months, deferrals)`.

```php
final class MonthlyRunPlanner
{
    public function __construct(private readonly EngineStatusService $status, private readonly RunPrerequisites $prerequisites) {}

    /** True on the 1st, on any night a closable month is unclosed (self-heal), from the 8th while this month's batch is missing, plus the lookback. Uses closeOwed(), NOT closePhase(): a prerequisite deferral must still start the run so the skipped row and the alert are written. */
    public function isDue(Carbon $night): bool
    {
        $thisBatchMonth = $night->copy()->startOfMonth();
        $lastBatchMonth = $thisBatchMonth->copy()->subMonthNoOverflow();
        return $night->day === 1
            || $this->closeOwed($night)
            || ($night->day >= 8 && ! $this->status->payoutBatchExists(PayoutBatch::TYPE_MONTHLY, $thisBatchMonth))
            || (! $this->status->payoutBatchExists(PayoutBatch::TYPE_MONTHLY, $lastBatchMonth)
                && ! MonthlyEngineCompletionGate::owedNoCrediting($lastBatchMonth->copy()->subMonthNoOverflow()));
    }

    /** The month that ended most recently is unclosed and (GSB off, or whole). Pure. */
    private function closeOwed(Carbon $night): bool { … }

    /**
     * Phase 1. Order of checks: already closed → []; frozen (cannot be — a frozen month has a close) → [];
     * PREREQUISITES (client 2026-09-18): tonight's nightly run green, and the weekly run green when a Tuesday is owed tonight;
     * GSB off → step; cut-off in flight → deferral(cause cutoff_in_flight); coverage → step or deferral(cause coverage).
     * A sales-free month is STILL closed when whole (R-76 prior-month gate).
     */
    public function closePhase(Carbon $night): MonthlyRunPhase
    {
        $month = $night->copy()->startOfMonth()->subMonthNoOverflow();
        if ($this->status->hasSucceededRun('compensation.monthly-close', $month)) { return new MonthlyRunPhase([], []); }

        $refusal = $this->prerequisites->nightlyRunRefusal($night) ?? $this->prerequisites->weeklyRunRefusal($night);
        if ($refusal !== null) {
            return new MonthlyRunPhase([], [new MonthlyRunDeferral('close', $month, sprintf(
                "%s was not closed tonight: %s\nThe monthly run closes it on the first night both are green; nothing to type.",
                $month->format('F Y'), $refusal), null, null, 'prerequisite')]);
        }
        if (! Feature::for(null)->active(GenosSalesBonusFeature::class)) { return new MonthlyRunPhase([$month], []); }
        if ($this->status->hasRunInFlight('gsb.daily-cutoff')) { return new MonthlyRunPhase([], [new MonthlyRunDeferral('close', $month, '…cut-off still running…', null, null, 'cutoff_in_flight')]); }
        $coverage = MonthCutoffCoverage::for($month, $this->status);
        return $coverage->isWhole()
            ? new MonthlyRunPhase([$month], [])
            : new MonthlyRunPhase([], [new MonthlyRunDeferral('close', $month, $coverage->refusal(), null, $coverage->missing(), 'coverage')]);
    }

    /** Phase 2 — NO prerequisite on tonight's runs (the 8th is independent). Called AFTER phase 1. D1b lookback + the main month, each through consider(). */
    public function payoutPhase(Carbon $night): MonthlyRunPhase { …as before… }
}
```

`payoutPhase()` and `consider()` bodies (unchanged from the first version of this plan):

```php
public function payoutPhase(Carbon $night): MonthlyRunPhase
{
    $months = []; $deferrals = [];
    $thisBatchMonth = $night->copy()->startOfMonth();
    $lastBatchMonth = $thisBatchMonth->copy()->subMonthNoOverflow();

    if (! $this->status->payoutBatchExists(PayoutBatch::TYPE_MONTHLY, $lastBatchMonth)) {
        $older = $lastBatchMonth->copy()->subMonthNoOverflow();
        if (! MonthlyEngineCompletionGate::owedNoCrediting($older)) {   // D1b: catch up, never invent
            $this->consider($older, $months, $deferrals);
        }
    }
    if ($night->day >= 8 && ! $this->status->payoutBatchExists(PayoutBatch::TYPE_MONTHLY, $thisBatchMonth)) {
        $this->consider($thisBatchMonth->copy()->subMonthNoOverflow(), $months, $deferrals);
    }
    return new MonthlyRunPhase($months, $deferrals);
}

private function consider(Carbon $crediting, array &$months, array &$deferrals): void
{
    $blocker = MonthlyEngineCompletionGate::blockingFailure($crediting);
    if ($blocker === null) { $months[] = $crediting; return; }
    $deferrals[] = new MonthlyRunDeferral('payout', $crediting, sprintf(
        "The %s payout was not built: %s\nThe monthly run builds it on any night after that is fixed.",
        $crediting->format('F Y'), $blocker['message'],
    ), $blocker['engine_key']);
}
```

### 7. Trait `OrchestratesEngineSteps` (new)

Lifted from `NightlyRunCommand`: `resolveNight(string $registryKey): ?Carbon`, `orchestratorPreflight(): ?string` (recompute gate + `WorkerFreshness::staleReason()`), `runStep(EngineDefinition, Carbon, array $extraOptions = []): int`, `abortRun(Carbon $night, string $stage, string $reason): int` (`noteSkipped` for `preflight` else `noteFailed`; audit action `<auditPrefix>.aborted`). Prefixes: nightly `compensation.nightly_run` (byte-identical action names), weekly `compensation.weekly_run`, monthly `compensation.monthly_run`.

### 8. `NightlyRunCommand` (narrowed)

Signature `compensation:nightly-run {--date=} {--force} {--restart}`. `stepsFor()` = evaluate + owed cut-off days. Delete every payout/month method and `MAX_BACKFILL_WEEKS`. `--restart` stays: the night rebuild passes it so the cut-off for D re-runs although D has an earlier succeeded row (D13 makes the pre-wipe success still count until the re-run finishes). Docblock rewritten: two steps, backfill, "the weekly and monthly runs are their own commands (ADR-0016) and wait on this one — not the other way round".

### 9. `WeeklyRunCommand` (new)

```
compensation:weekly-run {--date= : The night to run (YYYY-MM-DD, defaults to tonight)} {--force : Run even when the preflight refuses}
```
`handle()`: resolve night → preflight → `$tuesdays = $planner->owedTuesdays($night)`; `[]` → `noteSkipped('No Tuesday batch is owed tonight.')`, exit 0. **Then the prerequisite**: `$refusal = $prerequisites->nightlyRunRefusal($night)`; when not null and not `--force`: `warn()`, `NightlyRunAlert::weeklyDeferred($night, $tuesdays, $refusal)`, `noteSkipped($refusal)`, exit 0 (a deferral, D3). Otherwise run `gsb.weekly-payout` per Tuesday, oldest first, abort at the first non-zero exit. Docblock: the batch dated T pays T−13..T−7 so the wait on tonight's cut-off is the client's ordering rule, not a data dependency; the sweep lock serialises it against the monthly payout; a batch built by hand has no maker.

Deferral message: `"Tonight's nightly run has not succeeded (%s), so the %s weekly batch was not built. It is built on the first night after the nightly run is green — or run php artisan compensation:weekly-run --date=%s once it is."` with `%s` = the nightly run's status and error first line, the Tuesday list, and tonight.

### 10. `MonthlyRunCommand` (new)

```
compensation:monthly-run {--date= : The night to run (YYYY-MM-DD, defaults to tonight)} {--force : Run even when the preflight refuses}
```
1. resolve → preflight. 2. `$close = $planner->closePhase($night)`: each deferral → `warn()` + `NightlyRunAlert::monthCloseDeferred($night, $d->month, $d->missingDays, $d->cause, $d->reason)`; each month → `runStep(compensation.monthly-close)`; non-zero → `abortRun`. 3. `$payout = $planner->payoutPhase($night)` (**after** step 2): deferrals → `NightlyRunAlert::payoutDeferred(...)`; months → `runStep(compensation.monthly-payout-close)`; non-zero → abort. 4. Nothing stepped → `noteSkipped(<reasons joined | 'Nothing owed tonight.'>)`, exit 0. `--force` skips the prerequisites too (it is the operator's hand). Docblock: phase order; deferral = `skipped`; the payout phase deliberately has no prerequisite on tonight's runs; no admin button.

### 11. `MonthlyCloseCommand`

- **Before** `preflight()` and independent of `--force`: `if (($frozen = FrozenPayoutGuard::refusal($month)) !== null) { $this->error($frozen); app(EngineRunContext::class)->noteSkipped($frozen); return self::FAILURE; }`.
- `preflight()` coverage block → `MonthCutoffCoverage`. Docblock check 3 gains the platform-start sentence; class docblock: "a step of `compensation:monthly-run`; `compensation:rebuild-month` runs it with `--restart` after wiping the month".

### 12. `EngineRegistry`

- `gsb.weekly-payout`: `orchestratedBy: 'compensation.weekly-run'`. `compensation.monthly-close`, `compensation.monthly-payout-close`: `orchestratedBy: 'compensation.monthly-run'`.
- `compensation.nightly-run`: label `'Nightly Run (repurchase + cut-off)'`; description: two steps, backfill, the other runs are separate and wait on it.
- New `compensation.weekly-run` (label `'Weekly Run (Tuesday payout)'`, `EngineCadence::daily('03:00', 'the Tuesday batch; on other nights only a Tuesday that was never built')`, `isOrchestrator: true`, `manuallyTriggerable: false`, report route `admin.compensation.weekly-payouts.index`) and `compensation.monthly-run` (label `'Monthly Run (close + payout)'`, `EngineCadence::daily('04:00', 'closes the month once its last day is cut off; pays from the 8th')`, report route `admin.compensation.engine-runs.events`). Descriptions as in the previous plan, plus one sentence each on the prerequisite ("waits for tonight's nightly run to succeed; …and for the Tuesday batch when one is owed").

Definitions as first drafted:

```php
new EngineDefinition(
    key: 'compensation.weekly-run',
    label: 'Weekly Run (Tuesday payout)',
    description: 'Builds the Tuesday weekly payout batch — and, on any other night, only a Tuesday whose batch was never built, still dated that Tuesday. Waits for tonight\'s nightly run to succeed first. Impact: writes nothing of its own; the batch and its line items are written by GSB Weekly Payout. Runs at 03:00 IST: the batch dated Tuesday T pays the week that closed on T−7, so it never needs tonight\'s cut-off figures.',
    periodType: EnginePeriodType::Date,
    commandClass: WeeklyRunCommand::class,
    commandSignature: 'compensation:weekly-run',
    periodOption: '--date',
    dependencies: [],
    featureFlagClass: null,
    reportRouteName: 'admin.compensation.weekly-payouts.index',
    cadence: EngineCadence::daily('03:00', 'the Tuesday batch; on other nights only a Tuesday that was never built'),
    defaultPeriod: 'today',
    manuallyTriggerable: false,
    isOrchestrator: true,
),
new EngineDefinition(
    key: 'compensation.monthly-run',
    label: 'Monthly Run (close + payout)',
    description: 'Closes the month that has just ended — the seven crediting engines, in order — the first night every one of its days has a completed cut-off and tonight\'s nightly run (and the Tuesday batch, when one is owed) has succeeded; and from the 8th builds the monthly payout batch once every crediting engine for the month has succeeded. Impact: writes nothing of its own. A month it cannot close or pay is recorded as a deferral (an alert and a skipped run), never a failed night, and is re-attempted the next night.',
    periodType: EnginePeriodType::Date,
    commandClass: MonthlyRunCommand::class,
    commandSignature: 'compensation:monthly-run',
    periodOption: '--date',
    dependencies: [],
    featureFlagClass: null,
    reportRouteName: 'admin.compensation.engine-runs.events',
    cadence: EngineCadence::daily('04:00', 'closes the month once its last day is cut off; pays from the 8th'),
    defaultPeriod: 'today',
    manuallyTriggerable: false,
    isOrchestrator: true,
),
```

- **Four rebuild orchestrators**, `cadence: EngineCadence::unscheduled()`, `manuallyTriggerable: false`, `isOrchestrator: true`, `developerOnly: true`, `dependencies: []`, `featureFlagClass: null`:

| key | label | periodType / option | commandClass / signature | defaultPeriod | description (impact) |
|---|---|---|---|---|---|
| `compensation.rebuild-night` | Rebuild — night | Date `--date` | `RebuildNightCommand` / `compensation:rebuild-night` | `today` | "Deletes the previous day's cut-off results, daily pools, mentorship results and their wallet credits, rewinds the carry-forward, and re-runs the night. Refuses once a later day has been cut off." |
| `compensation.rebuild-week` | Rebuild — weekly payout | Date `--date` | `RebuildWeekCommand` / `compensation:rebuild-week` | `today` | "Removes an unapproved Tuesday batch — its lines and its own debits — un-sweeps the credits it took, and builds the batch again. Refuses a batch finance has approved." |
| `compensation.rebuild-month` | Rebuild — monthly close | Month `--month` | `RebuildMonthCommand` / `compensation:rebuild-month` | `prev-month` | "Deletes the month's rank, Growth Booster, Fortune, ADC and purchase-offer rows and their wallet credits, removes the month's unapproved payout batch if one exists, and re-runs the close. Refuses a frozen month or one the next month was built on." |
| `compensation.rebuild-payout` | Rebuild — monthly payout | Month `--month` (crediting month) | `RebuildPayoutCommand` / `compensation:rebuild-payout` | `prev-month` | "Removes the month's unapproved payout batch, un-sweeps its credits, deletes its own debits, and re-runs the payout close so every distributor's amount is frozen again." |

- `rootOrchestratorKeys()`: `isOrchestrator && orchestratedBy === null && cadence->isScheduled()`. `rebuildKeys()`: the four.
- `EngineRegistryTest`: "registers fourteen engines" → **twenty**; the cadence pin passes (unscheduled entries are asserted absent from the scheduler); the "one registry entry per console command" test passes because the four commands are registry entries. `RecordEngineRun` writes their rows through the existing listener.

### 13. `EngineCadence::describe()` / `EngineDefinition`

`describe(?string $chainStartsAt = null, ?string $chainLabel = null)` → `"in the {$chainLabel} from {$chainStartsAt} IST"`. `EngineDefinition::chainRoot(): ?EngineDefinition`; `scheduleText()` passes `Str::before($root->label, ' (')`. New constructor arg `public bool $developerOnly = false`.

### 14. `routes/console.php`

Replace the single chain entry with three (keep the comment block, rewritten for three entries; keep the 00:05 rationale); update the stale digest and auto-retry comments:

```php
use App\Modules\Compensation\Console\Commands\MonthlyRunCommand;
use App\Modules\Compensation\Console\Commands\WeeklyRunCommand;
use App\Modules\Compensation\Support\MonthlyRunPlanner;
use App\Modules\Compensation\Support\WeeklyRunPlanner;
use Illuminate\Support\Carbon;

Schedule::command(NightlyRunCommand::class)->dailyAt('00:05')->timezone('Asia/Kolkata')
    ->withoutOverlapping()->when($compensationEnginesMayRun)->runInBackground();

// Weekly run — 03:00 every night, but it STARTS only when a Tuesday batch is owed:
// on Tuesdays, and on any other night only when a Tuesday was never built.
// The predicate is evaluated once, at 03:00, in the scheduler process; a false
// answer starts no process and writes no row. The prerequisite on tonight's
// nightly run is checked INSIDE the command, so a deferral is recorded.
Schedule::command(WeeklyRunCommand::class)->dailyAt('03:00')->timezone('Asia/Kolkata')
    ->withoutOverlapping()
    ->when(static fn (): bool => $compensationEnginesMayRun()
        && app(WeeklyRunPlanner::class)->isDue(Carbon::today('Asia/Kolkata')))
    ->runInBackground();

// Monthly run — 04:00: the 1st, any night a closable month is still open, and
// from the 8th while the month's payout batch is missing. See MonthlyRunPlanner::isDue().
Schedule::command(MonthlyRunCommand::class)->dailyAt('04:00')->timezone('Asia/Kolkata')
    ->withoutOverlapping()
    ->when(static fn (): bool => $compensationEnginesMayRun()
        && app(MonthlyRunPlanner::class)->isDue(Carbon::today('Asia/Kolkata')))
    ->runInBackground();
```

### 15. `RecordSkippedOrchestratorRun` (renamed)

Match `$event->task->command` against `EngineRegistry::rootOrchestratorKeys()` signatures; per-orchestrator reasons (nightly existing sentence; weekly "…skipped at 03:00; the next night builds any Tuesday this one would have"; monthly "…skipped at 04:00; the next night re-evaluates what the month owes"). Register under the new name. Also add the four rebuild commands to the console command list in `AppServiceProvider` (module commands are not auto-discovered).

### 16. `EngineStatusService`

- `chainStepOutcomes()`: `whereIn('engine_key', <keys with orchestratedBy === CHAIN_KEY>)`.
- `unresolvedFailureQuery()` (D5): period equality dropped for `rootOrchestratorKeys()`.
- **D13.** New private `latestFinishedRun(string $key, Carbon $period): ?EngineRun` — `whereDate('period_start', …)`, `whereIn('status', [succeeded, failed])`, `orderByDesc('started_at')->orderByDesc('id')->first()`. `hasSucceededRun()` becomes: latest finished run exists, is `succeeded`, and `started_at >= periodEndsAt()`. `completedCutoffDatesBetween()` becomes: group the range's finished cut-off runs by date, take the latest per date, count the date only if that run is `succeeded` and `started_at >= period_start + 1 day`. Docblocks: "the latest finished attempt decides — a failed re-run un-proves the period (a rebuild that wiped it and then failed must not read as done); `skipped` and `running` rows are not attempts". `hasSucceededRunOnOrAfter()` and `hasSucceededRunAfterDay()` are **unchanged** (any-later-success is the right question for the evaluate proof).
- New `hasSucceededRunTonight(string $key, Carbon $night): bool` — latest finished run for period `$night` is `succeeded` and `started_at >= $night->copy()->startOfDay()` (app timezone). Used by `RunPrerequisites`.
- New `failedRootRun(string $key): ?EngineRun` (the body of today's `failedChainRun()` parameterised); `failedChainRun()` = `failedRootRun(self::CHAIN_KEY)`.

### 17–19. Retry path → rebuild path

- **Delete** `RetryNightlyChainJob`, `AdminEngineRunsController::retryChain()`, the `retry-chain` route, the `failedChainPayload()` retry button.
- `index()`:
  - `failedRuns`: for each of the three roots, `failedRootRun($key)` → payload `{label, night, failedAt, error (Str::limit 1000), steps (nightly only), inFlight}` or null. Rendered for everyone as information only.
  - `rebuildPanel` (only when `$request->user()?->hasRole('developer') === true`; null otherwise — the view checks `@developer` too): `{ nights: last 7 nights with any nightly-run or cut-off row, newest first; weeklyBatches: unfrozen weekly batches (last 8 weeks); months: last 3 ended months; monthlyBatches: unfrozen monthly batches; preview: session('rebuild_preview') }`.
- `rebuildPreview(Request)`: `abort_unless(developer, 404)`; validate `kind` ∈ `RebuildKind::values()`, `period` (`date_format:Y-m-d` for night/week, `Y-m` for month/payout); `$plan = $planner->plan($kind, $period)`; flash `rebuild_preview` = `$plan->toArray()` (kind, period, label, refusals, warnings, rowsToRemove, unsweeps, fingerprint); redirect to index.
- `rebuild(Request)`: `abort_unless(developer, 404)`; validate kind, period, `fingerprint` (string), `reason` (required, min 10, max 500); re-plan; refusals → `ValidationException` naming them; `fingerprint !== $plan->fingerprint` → `ValidationException('The state changed since the preview — preview again.')`; `$chainId = Str::uuid()`; audit `compensation.rebuild.queued` (`subject_type` 'engine', `before_hash` `AuditDigests::of($plan->rowsToRemove)`, `after_hash` null, details: kind, period, reason, warnings, rows_to_remove, unsweeps, chain_id, ip); `RebuildPeriodJob::dispatch($kind, $period, $actorId, $chainId)`; `Log::info('compensation.rebuild.queued', …)`; forget the preview; flash status = "Queued the {label} rebuild for {period}. {N} row(s) are removed first, then the {command} runs again for that period." + each warning prefixed "After it succeeds: ".
- `parsePeriodOrFail()`: for month-typed engines add `if (($frozen = FrozenPayoutGuard::refusal($period)) !== null) throw ValidationException::withMessages(['period' => $frozen]);`.

Routes (`app/routes/web.php`, inside the `engine-runs` group):

```php
// Developer-only. `role:developer` is a gate the Gate::before super-staff bypass
// cannot open (AppServiceProvider); the controller 404s as well (F84: never reveal the role).
Route::middleware('role:developer')->group(function (): void {
    Route::post('rebuild/preview', [AdminEngineRunsController::class, 'rebuildPreview'])->name('rebuild.preview');
    Route::post('rebuild', [AdminEngineRunsController::class, 'rebuild'])->name('rebuild');
});
```

View (`engine-runs/index.blade.php`):
- Replace the retry banner with a loop over `$failedRuns` (three possible rose banners: "The nightly run / weekly run / monthly run failed on {night}", the error box, the step list for the nightly run, and the paragraph "Nothing is lost by waiting: the next nightly run backfills the cut-offs this night missed; the weekly and monthly runs re-attempt what they owe every night. The platform team has the failure." — **no button, no mention of a retry**).
- `@developer … @enddeveloper` block "Rebuild a period (platform team)": form-purpose note ("Rebuild wipes one period's computed rows and runs that period's command again from scratch. It is the repair for a run that failed part-way. Every rebuild is audit-logged under your user id and previewed before anything is written."), four small forms posting to `rebuild.preview` — kind hidden, period input (`type=date` max today for night; `<select>` of unfrozen weekly batches for week; `type=month` max previous month for month; `<select>` of unfrozen monthly batches for payout), each with `<x-help-tip>` explaining the period ("The NIGHT the run belongs to — the cut-off it rebuilds is the day before."), button "Preview rebuild →".
- Preview card (when `session('rebuild_preview')`): label + period; refusals in a rose list (then only a "Dismiss" link back to index); else a table of `rowsToRemove` (table → count) + "wallet entries un-swept: N", the warnings in an amber list headed "Run these after it succeeds:", the reason input, and the confirm form posting to `rebuild` with hidden kind/period/fingerprint, `data-confirm="Wipe {label} for {period} and run it again?"`, `data-confirm-title="Rebuild {label} — {period}"`, `data-confirm-impact="{N} row(s) across {k} table(s) are deleted and rebuilt through the ordinary engines. {warnings joined}. Runs in the background; refresh to follow it."` Button: "Rebuild now".
- Hide `developerOnly` definitions from the cards loop (`@continue` when `$definition->developerOnly`) — for everyone; the Run events page still lists their rows (an audit fact, not a control).

### 20. `EngineHealthService`

- `chainAlertHeadline()`: `ACTION_SKIPPED_NIGHT` → "The {root label} never started — the previous one was still running"; `ACTION_MONTH_DEFERRED` by `cause`: `coverage` → existing sentence; `cutoff_in_flight` → "{Month} was not closed on the 1st — the cut-off was still running; it closes the next night"; `prerequisite` → "{Month} was not closed on the 1st — tonight's nightly run (or the Tuesday batch) had not succeeded; it closes the next night both are green"; `ACTION_PAYOUT_DEFERRED` → "{Month} has not been paid — its crediting is incomplete"; `ACTION_WEEKLY_DEFERRED` → "The {Tuesday} weekly payout was not built — tonight's nightly run had not succeeded".
- `chainAlertSteps()`: steps for the two new actions ("Nothing to click: the weekly run builds it on the first night the nightly run is green." / "If tomorrow's email still lists it, send the nightly run's error to the platform team."); `ACTION_SKIPPED_NIGHT`, `ACTION_MONTH_DEFERRED` steps as in the previous plan. `weeklyPayoutSteps()`/`monthlyPayoutSteps()`/`monthlyCloseSteps()`: as in the previous plan, with every "ask the developer to run …" sentence pointing at the ordinary command (never at the rebuild control).

Previous-plan copy carried forward: `ACTION_MONTH_DEFERRED` step 2 → "…then the monthly run closes the month the next night; nothing to type."; `ACTION_SKIPPED_NIGHT` step 1 → "…the next nightly run cuts off the days this night would have; the weekly and monthly runs are separate and were not affected."; `weeklyPayoutSteps()` steps 2–3 → "The weekly run builds a Tuesday it missed on its next night, still dated that Tuesday; distributors do not wait a week." / "Only if tomorrow's email still lists it: ask the platform team to run php artisan compensation:weekly-run --date=<that Tuesday> on the server (a batch built from a shell has no maker — a second person must approve it)."; `monthlyPayoutSteps()` step 2 → "The monthly run re-attempts the payout every night from the 8th once every crediting engine has succeeded; nothing to type unless the month has to be forced."; `monthlyCloseSteps()` final step → "Once the missing days are cut off, the monthly run closes the month the next night on its own."

### 26. `RunPrerequisites` (new, `Support/`)

```php
final class RunPrerequisites
{
    public function __construct(private readonly EngineStatusService $status, private readonly WeeklyRunPlanner $weekly) {}

    /** Null when tonight's nightly run has a succeeded row dated $night that started after the night began. */
    public function nightlyRunRefusal(Carbon $night): ?string
    {
        if ($this->status->hasSucceededRunTonight('compensation.nightly-run', $night)) { return null; }
        $latest = $this->status->lastRun('compensation.nightly-run');
        return sprintf(
            "tonight's nightly run (%s) has not succeeded%s",
            $night->format('d M Y'),
            $latest === null ? ' — it has not run at all' : sprintf(' — its last attempt is %s%s', $latest->status,
                is_string($latest->error) && $latest->error !== '' ? ': '.Str::limit(strtok($latest->error, "\n"), 200) : ''),
        );
    }

    /** Null when no Tuesday batch is owed tonight, or tonight's weekly run has succeeded. */
    public function weeklyRunRefusal(Carbon $night): ?string
    {
        if (! $this->weekly->isDue($night) || $this->status->hasSucceededRunTonight('compensation.weekly-run', $night)) { return null; }
        return sprintf("tonight's weekly run has not succeeded and a Tuesday batch (%s) is owed",
            implode(', ', array_map(fn (Carbon $t) => $t->toDateString(), $this->weekly->owedTuesdays($night))));
    }
}
```

`isDue()` with the GSB flag off returns `[tonight]` on a Tuesday; the weekly run then exits 0 with a `skipped` leaf and a `succeeded` root row, so the monthly close is not blocked by a flag-off Tuesday.

### 27. `FrozenPayoutGuard` (new, `Support/`)

```php
final class FrozenPayoutGuard
{
    /** Statuses that mean finance's hand is on the batch or a sweep is in flight. */
    public const FROZEN_STATUSES = [PayoutBatch::STATUS_APPROVED, PayoutBatch::STATUS_DISPATCHED, PayoutBatch::STATUS_COMPLETED, PayoutBatch::STATUS_PROCESSING];

    public static function isFrozen(PayoutBatch $batch): bool
    {
        return $batch->approved_at !== null || in_array($batch->status, self::FROZEN_STATUSES, true);
    }

    /** The monthly batch that pays crediting month $month: dated the 1st of the following month. */
    public static function batchFor(Carbon $creditingMonth): ?PayoutBatch
    {
        return PayoutBatch::query()->where('batch_type', PayoutBatch::TYPE_MONTHLY)
            ->whereDate('batch_date', $creditingMonth->copy()->startOfMonth()->addMonthNoOverflow()->toDateString())->first();
    }

    public static function frozenBatchFor(Carbon $creditingMonth): ?PayoutBatch
    {
        $batch = self::batchFor($creditingMonth);
        return $batch !== null && self::isFrozen($batch) ? $batch : null;
    }

    public static function refusal(Carbon $creditingMonth): ?string
    {
        $batch = self::frozenBatchFor($creditingMonth);
        if ($batch === null) { return null; }
        return sprintf(
            "%s is frozen: its payout batch #%d is %s%s. No monthly engine, close or rebuild may run for %s again — money has moved on its figures. "
            .'The monthly engines run next for %s, from %s 00:00 IST.',
            $creditingMonth->format('F Y'), $batch->id, $batch->status,
            $batch->approved_at !== null ? ' (approved '.$batch->approved_at->format('d M Y H:i').')' : '',
            $creditingMonth->format('F Y'),
            $creditingMonth->copy()->addMonthNoOverflow()->format('F Y'),
            $creditingMonth->copy()->addMonthsNoOverflow(2)->format('d M Y'),
        );
    }
}
```

Asserted (each not overridable by `--force` or `--in-flight`; per A10 the engines, the close and the manual-trigger parser call `creditingRefusal()`, the rebuilders call `refusal()`): the seven monthly engine commands (#28) right after their `OpenMonthGuard` check — `$this->error($refusal); app(EngineRunContext::class)->noteSkipped($refusal); return self::FAILURE;`; `MonthlyCloseCommand` (§11); `RebuildMonthCommand` and `RebuildPayoutCommand` (§31–33); `AdminEngineRunsController::parsePeriodOrFail()` (§18). `MonthlyRunPlanner::closePhase()` needs nothing: a frozen month has a succeeded close and returns `[]` first.

`processing` is included so an engine cannot credit into a month whose batch is mid-sweep; for the *rebuild* commands a `processing` batch gets its own message ("stuck in processing — `payout:reopen-stuck-batch` first, then rebuild").

### 28. `RebuildKind`, `RebuildPlan`, `RebuildPreflight` (new, `Services/Rebuild/`)

```php
enum RebuildKind: string { case Night = 'night'; case Week = 'week'; case Month = 'month'; case Payout = 'payout';
    public function registryKey(): string { … 'compensation.rebuild-'.$this->value … }  public function label(): string { … } }

final readonly class RebuildPlan
{
    /** @param list<string> $refusals @param list<string> $warnings @param array<string,int> $rowsToRemove */
    public function __construct(public RebuildKind $kind, public Carbon $period, public array $refusals, public array $warnings, public array $rowsToRemove, public int $unsweeps, public string $rerunCommand) {}
    public function isRefused(): bool { return $this->refusals !== []; }
    /** sha256 of kind|period|refusals|rowsToRemove|unsweeps — what the two-step confirm compares. */
    public function fingerprint(): string { … }
    public function toArray(): array { … }
}

final class RebuildPreflight
{
    public function __construct(private readonly RecomputeState $state, private readonly EngineStatusService $status) {}
    /** Shared refusals, in order: projection/replay standing; stale worker; any root orchestrator or rebuild in flight. @return list<string> */
    public function refusals(): array
    {
        $r = [];
        if (! $this->state->schedulerEnginesAllowed()) { $r[] = 'A recompute projection is standing (or a replay is in flight) on this environment …'; }
        if (($stale = WorkerFreshness::staleReason()) !== null) { $r[] = $stale; }
        foreach ([...EngineRegistry::rootOrchestratorKeys(), ...EngineRegistry::rebuildKeys()] as $key) {
            if ($this->status->hasRunInFlight($key)) { $r[] = sprintf('%s is running right now; a rebuild beside it would race the scheduler. Wait for it to finish.', EngineRegistry::get($key)->label); }
        }
        return $r;
    }
}
```

`RebuildPreflight` is **not** an environment gate: production is allowed. `RecomputeGuard` and `compensation:recompute-all` are untouched (ADR-0014).

### 29. Wipe scopes — the authoritative tables

Legend: *delete* = hard delete; *un-sweep* = `UPDATE … SET swept_by_payout_batch_id = NULL`; *rewind* = in-place restore. Every wipe runs inside one `DB::transaction()`; id lists are chunked at 5,000 for `whereIn`.

**Night N (cut-off day D = N−1)** — `NightRebuilder::wipe(N)`:

| Table | Period column / scope | Action | Note |
|---|---|---|---|
| `gsb_cutoff_results` | `cutoff_date = D` | delete | `*_before` columns read into the rewind **before** the delete (`CarryforwardRewind::readFrom(D)`; with the newest-night guard "≥ D" equals D) |
| `gsb_carryforward` | per distributor with a D row in `CARRY_FORWARD_ADVANCING_STATUSES` | rewind | `CarryforwardRewind::apply()` after the deletes; a legacy null-side row with a non-zero power CF throws → surfaced as a refusal in the plan, before any write |
| `gsb_daily_pools` | `cutoff_date = D` | delete | else `freezePoolForDate()` reuses the stale pool |
| `msb_daily_pools` | `cutoff_date = D` | delete | same |
| `mentorship_bonus_results` | `cutoff_date = D` | delete | |
| `gsb_personal_bv_topups` | `date = D` | delete | the consumed top-up orders become pending again and re-apply |
| `wallet_ledger_entries` | `(reference_type = 'gsb_cutoff_result' AND reference_id IN D's result ids) OR (reference_type = 'mentorship_bonus_result' AND reference_id IN D's MB ids)`, types ∈ {`gsb_credit`, `mb_credit`, `repurchase_transfer`, `repurchase_deduction`} | delete | by reference, never by `created_at` (a retried night's rows carry another day's timestamp); refused if any is swept (see §30) |
| `engine_runs` | — | **never** | new rows are written by the re-run; D13 makes the latest attempt decisive |
| `repurchase_cycles` | — | **not touched** | verdicts are frozen once; the verdicts night N took (cycles due D) read the wallet as at D 23:59, which D's cut-off credits (written N 00:10) never entered — re-running the evaluate is idempotent (see Deviations) |
| NOT: `group_bv_daily`, `group_bv_credits`, `group_bv_reversals`, `group_bv_debts`, `bv_propagation_log`, `orders`, `bv_ledger_entries`, `repurchase_wallet_used` entries, `distributors.gsb_frozen_at`, `audit_log`, `payout_*` | | | source data / other periods |

Re-run: `compensation:nightly-run --date=N --restart`.

**Week T (Tuesday)** — `PayoutService::unbuildBatch(T's weekly batch)`, then re-run `gsb:weekly-payout --date=T`:

| Table | Scope | Action |
|---|---|---|
| `wallet_ledger_entries` (the swept credits and their `repurchase_transfer`s) | `swept_by_payout_batch_id = batch.id` | un-sweep (count reported) |
| `wallet_ledger_entries` (the batch's own debits) | `reference_type = 'payout_line_item' AND reference_id IN line ids AND type IN ('payout_debit','admin_charge_debit','tds_debit')`; `type = 'income_cap_forfeit' AND reference_id IN line ids AND reference_type LIKE 'payout_line_item_%'` | delete (DN-1); ids and paise sums go into the audit row |
| `payout_gateway_events` | `payout_batch_id = batch.id OR payout_line_item_id IN line ids` | must be **0** (belt: nothing reached a gateway) else the transaction aborts and the rebuild is refused |
| `payout_line_items` | `payout_batch_id = batch.id` (held lines included — they carry no debit) | delete |
| `payout_batches` | `id` | delete |
| `audit_log` | — | append `payout.batch.unbuilt` |

**Month M** — `MonthRebuilder::wipe(M)`; first `unbuildBatch()` for M's unfrozen batch if one exists (its own transaction and audit row), then in one transaction:

| Table | Period column | Action | Note |
|---|---|---|---|
| `wallet_ledger_entries` | `reference_type IN ('gbb_monthly_result','rank_bonus_result','fortune_bonus_result','adc_bonus_result') AND reference_id IN M's result ids`, types ∈ {`gbb_credit`,`rank_credit`,`fortune_credit`,`adc_credit`,`repurchase_transfer`,`repurchase_deduction`} | delete | by reference; refused if any is swept by a frozen batch |
| `rank_aogo_grants` | `month_start = M` | delete | AO-GO grants are credited through Rank Bonus, never order-consumed (verified: no order column on the model) |
| `rank_bonus_results` | `month_start = M` | delete | |
| `rank_monthly_pools` | `month_start = M` | delete | frozen pool |
| `rank_qualifications` | `month_start = M AND is_carry_forward = false` | delete | legacy carry-forward rows written by an earlier month's check are left |
| `lifetime_award_milestones` | `triggered_month = M AND status = 'pending'` | delete | delivered/cancelled milestones are hand-released awards and stay; `syncLifetimeAward()` recreates pending ones from the rebuilt roster |
| `gbb_monthly_results` | `year_month = M` (whereDate) | delete | |
| `gbb_monthly_pools` | `month_start = M` | delete | |
| `fortune_bonus_results` | `month_start = M` | delete | |
| `fortune_monthly_pool_levels` | parent pool `month_start = M` | delete | children first (FK) |
| `fortune_monthly_pools` | `month_start = M` | delete | |
| `fortune_bonus_participants` | `month_start = M` | delete | the roster is re-enrolled by `fortune:enroll-eligible` |
| `adc_bonus_results` | `month_start = M` | delete | |
| `redeem_point_entries` | `reference_type = 'purchase_offer_grant' AND reference_id IN` the grants deleted below | delete | |
| `purchase_offer_grants` | `month_start = M AND consumed_order_id IS NULL` | delete | consumed grants stay (F124); `alreadyGranted()` then skips them on re-run — reported as a warning with the count |
| `awards_credit` wallet entries, `engine_runs`, `repurchase_cycles`, GSB tables, group BV | — | **not touched** | |

Re-run: `compensation:monthly-close --month=M --restart`.

**Payout M** (batch dated the 1st of M+1) — `unbuildBatch()` as for the week; re-run `compensation:monthly-payout-close --month=M`.

"Freeze per distributor" in this codebase = the `payout_batches` row (totals, `distributor_count`, `processed_at`) + one `payout_line_items` row per distributor (`gross_paise`, `repurchase_deduction_paise`, `admin_charge_paise`, `tds_paise`, `net_transferred_paise`, `status`) + the `swept_by_payout_batch_id` stamps on the credits + the three sweep debits and any `income_cap_forfeit`. The rebuild recreates all of them through `PayoutService::sweep*Batch()`; the new line items are the re-frozen amounts.

### 30. Refusals and downstream warnings — per kind

**Refusals** (any one refuses; all are listed in the plan and recorded as `compensation.rebuild.refused` when attempted anyway):

| Kind | Refusal | Message shape |
|---|---|---|
| all | `RebuildPreflight::refusals()` | as §28 |
| all | actor missing or not `developer` (CLI `--actor`, or the job's actor) | "A rebuild records who decided it; pass --actor=<developer user id>." |
| night | N in the future; N older than the newest cut-off: any `gsb_cutoff_results` row with `cutoff_date > D` (any status) or a succeeded `repurchase.evaluate` run dated > N | "Cannot rebuild the {D} cut-off: {newest date} has already been cut off and the carry-forward store has moved past {D} (R-91). A day can be rebuilt only while it is the newest one; the deadline is the next nightly run at 00:05 IST. Replaying history from {D} forward is a windowed recompute, which runs on dev and staging only." |
| night | any D-referenced wallet entry with `swept_by_payout_batch_id` not null | "{n} of {D}'s credits were paid by batch #{id} ({status}); money that left cannot be recomputed." |
| night | any D row `status = reversed`, or a `gsb_reversal_requests` row `pending` for a D row | "{n} of {D}'s credits were reversed by an admin decision (or have a pending reversal request); resolve those first." |
| night | month(D) has a succeeded `compensation.monthly-close` **and** `FrozenPayoutGuard::frozenBatchFor(month(D))` | "{Month} was closed and its payout is frozen; {D} is inside it." |
| week | T's weekly batch missing | "No weekly batch is dated {T}; nothing to remove — the weekly run builds a missing Tuesday on its own (or `compensation:weekly-run --date=`)." |
| week / payout | batch `processing` | "Batch #{id} is stuck in processing — run `payout:reopen-stuck-batch --type={t} --date={d} --actor=…` first, then rebuild." |
| week / payout | `FrozenPayoutGuard::isFrozen(batch)` | "Batch #{id} is {status}{approved at…}; a batch finance has signed off is not rebuilt — retry its failed lines from the Payouts page." |
| week / payout | `payout_gateway_events` exist for the batch | "Batch #{id} has gateway events; it reached Razorpay." |
| month | `OpenMonthGuard::refusal(M)` | existing text |
| month | `FrozenPayoutGuard::refusal(M)` | §27 |
| month | any `ENGINE_KEYS` engine or `compensation.monthly-close` has a succeeded run for M+1 | "{M+1} was closed on {M}'s ranks, wallet verdicts and Fortune roster; {M} can no longer be rebuilt underneath it (DN-5)." |
| month | any M-referenced wallet entry swept by a **frozen** batch | as for night |
| month | any `reversal` wallet row referencing M's result rows | "{n} of {M}'s credits were reversed by an admin decision; resolve those first." |

**Downstream warnings** (computed from state, shown in the preview and printed/audited after completion; only the ones that apply):

| Kind | Condition | Warning (exact) |
|---|---|---|
| night | N is a Tuesday and no weekly batch dated N exists | "The weekly run for {N} (Tuesday) was deferred: run `php artisan compensation:weekly-run --date={N}` after this succeeds, or let 03:00 tomorrow build it." |
| night | N is the 1st and month(D) has no succeeded close | "The monthly close for {month(D)} was deferred: run `php artisan compensation:monthly-run --date={N}` after this succeeds, or let 04:00 tomorrow close it." |
| night | month(D) closed, not frozen | "{month(D)} was closed on {D}'s earlier figures. Rebuild the month next: `compensation:rebuild-month --month={M}`." |
| week / payout | later batches (any type, `batch_date >` this batch's) that are unfrozen | "The income-cap headroom of later batches changes. Rebuild these too, oldest first: #{id} {type} {date} → `compensation:rebuild-{week|payout} …`." |
| week / payout | later batches that are frozen | "Batch #{id} ({type} {date}) was approved after this one; its cap allocation stands and this batch is measured against it." |
| week / month / payout | the rebuilt batch's maker | "The rebuilt batch records you as its maker; a second person must approve it." |
| month | M's unfrozen batch existed | "{M}'s payout batch #{id} was removed with the stale credits. It is rebuilt by the monthly run from the 8th once every engine is green — or run `compensation:rebuild-payout --month={M}` (`compensation:monthly-payout-close --month={M}` from a shell)." |
| month | consumed purchase-offer grants for M | "{n} purchase-offer grant(s) already used on an order are kept and not re-derived (F124)." |
| month | always | "Repurchase cycle verdicts taken between the original close and now are not re-taken." |
| payout | `MonthlyEngineCompletionGate::blockingFailure(M)` not null | "The payout close will refuse ({engine}); the batch is removed and the monthly run rebuilds it once the crediting is green." |

### 31. `NightRebuilder`, `MonthRebuilder`, `RebuildPlanner`, `PayoutService::unbuildBatch()`

`RebuildPlanner::plan(RebuildKind $kind, Carbon $period): RebuildPlan` — dispatches to the kind's rebuilder `plan()`; merges `RebuildPreflight::refusals()` first. `RebuildPlanner::execute(RebuildKind, Carbon, int $actorId, Closure $log): array` — `wipe()` then returns counts; the command does the re-run (so the console capture of the nested command lands on its own row).

`NightRebuilder` sketch:

```php
final class NightRebuilder
{
    public function __construct(private readonly DatabaseManager $db, private readonly EngineStatusService $status, private readonly CarryforwardRewind $rewind) {}

    public function plan(Carbon $night): RebuildPlan
    {
        $day = $night->copy()->subDay()->startOfDay();
        $refusals = []; $warnings = []; $rows = [];
        // …refusals per §30, each a query; rewind validated with $this->rewind->readFrom($day) inside try/catch(RuntimeException) → refusal…
        $resultIds = GsbCutoffResult::whereDate('cutoff_date', $day)->pluck('id');
        $mbIds = MentorshipBonusResult::whereDate('cutoff_date', $day)->pluck('id');
        $rows['gsb_cutoff_results'] = $resultIds->count(); /* … pools, msb pools, mb results, topups, wallet entries by reference … */
        return new RebuildPlan(RebuildKind::Night, $night, $refusals, $warnings, array_filter($rows), 0, "compensation:nightly-run --date={$night->toDateString()} --restart");
    }

    /** @return array<string,int> */
    public function wipe(Carbon $night, Closure $log): array
    {
        $day = $night->copy()->subDay()->startOfDay();
        $rewind = $this->rewind->readFrom($day);                       // BEFORE any delete
        return $this->db->transaction(function () use ($day, $rewind, $log): array {
            $resultIds = /* lockForUpdate pluck */; $mbIds = /* … */;
            $removed['wallet_ledger_entries'] = $this->deleteByReference('gsb_cutoff_result', $resultIds, self::NIGHT_WALLET_TYPES)
                                              + $this->deleteByReference('mentorship_bonus_result', $mbIds, self::NIGHT_WALLET_TYPES);
            $removed['mentorship_bonus_results'] = …; $removed['gsb_cutoff_results'] = …; $removed['gsb_daily_pools'] = …; $removed['msb_daily_pools'] = …; $removed['gsb_personal_bv_topups'] = …;
            $this->rewind->apply($rewind, $log);
            return array_filter($removed);
        });
    }
}
```

`PayoutService::unbuildBatch()` sketch (same file as the sweeps; uses `withSweepLock()`):

```php
/** @return array{entries_unswept:int, debits_deleted:int, debits_paise:int, forfeits_deleted:int, forfeits_paise:int, line_items:int} */
public function unbuildBatch(PayoutBatch $batch, int $actorId, string $reason): array
{
    return $this->withSweepLock(function () use ($batch, $actorId, $reason): array {
        $batch->refresh();
        if (FrozenPayoutGuard::isFrozen($batch)) { throw new BatchIsFrozen($batch); }          // new exception under Exceptions/
        if (PayoutGatewayEvent::where('payout_batch_id', $batch->id)->exists()) { throw new BatchIsFrozen($batch, 'gateway events'); }
        $before = AuditDigests::of($batch);
        return DB::transaction(function () use ($batch, $actorId, $reason, $before): array {
            $lineIds = PayoutLineItem::where('payout_batch_id', $batch->id)->lockForUpdate()->pluck('id');
            $unswept = WalletLedgerEntry::where('swept_by_payout_batch_id', $batch->id)->update(['swept_by_payout_batch_id' => null]);
            $debits = WalletLedgerEntry::where('reference_type', 'payout_line_item')->whereIn('reference_id', $lineIds)
                ->whereIn('type', ['payout_debit', 'admin_charge_debit', 'tds_debit']);
            $forfeits = WalletLedgerEntry::where('type', 'income_cap_forfeit')->whereIn('reference_id', $lineIds)->where('reference_type', 'like', 'payout_line_item_%');
            $summary = [ 'entries_unswept' => $unswept, 'debits_deleted' => (clone $debits)->count(), 'debits_paise' => (int) (clone $debits)->sum('amount_paise'),
                         'forfeits_deleted' => (clone $forfeits)->count(), 'forfeits_paise' => (int) (clone $forfeits)->sum('amount_paise'), 'line_items' => $lineIds->count() ];
            $debits->delete(); $forfeits->delete();
            PayoutLineItem::where('payout_batch_id', $batch->id)->delete();
            $batch->delete();
            AuditLog::create([ 'actor_id' => $actorId, 'action' => 'payout.batch.unbuilt', 'subject_type' => 'payout_batch', 'subject_id' => $batch->id,
                'before_hash' => $before, 'after_hash' => null,
                'details' => ['batch_type' => …, 'batch_date' => …, 'status' => …, 'created_by' => …, 'reason' => $reason, ...$summary],
                'ip' => app()->runningInConsole() ? null : request()->ip() ]);
            return $summary;
        });
    });
}
```

`laterBatchesAfter(PayoutBatch $batch): Collection` — batches of any type with `batch_date > $batch->batch_date`, for the warnings.

`MonthRebuilder::plan()/wipe()` per the §29 month table; `wipe()` calls `unbuildBatch()` first (outside its own transaction), then the month transaction.

### 32. The four commands + trait `RebuildsPeriod`

Signature pattern: `compensation:rebuild-night {--date= : The NIGHT to rebuild (YYYY-MM-DD); the cut-off rebuilt is the day before} {--actor= : REQUIRED from a shell — user id of the developer deciding the rebuild} {--yes : Skip the interactive confirmation (the queued job passes it)}`. Week: `--date=` "the Tuesday the batch is dated". Month/payout: `--month=` "crediting month (YYYY-MM)".

`handle()` outline (shared by the trait, kind-specific bits injected):

```php
public function handle(RebuildPlanner $planner, EngineRunContext $context): int
{
    $period = $this->resolvePeriod();                       // parse via EngineRegistry::get($kind->registryKey())->parsePeriod(); null → FAILURE
    $actorId = $this->option('actor') !== null ? (int) $this->option('actor') : $context->actorId();
    if ($actorId === null || User::find($actorId)?->hasRole('developer') !== true) { return $this->refuse($period, 'A rebuild records who decided it; pass --actor=<developer user id>.'); }

    $plan = $planner->plan($kind, $period);
    $this->printPlan($plan);                                 // rows table, unsweeps, warnings, the re-run command
    if ($plan->isRefused()) { return $this->refuse($period, implode("\n", $plan->refusals)); }   // noteSkipped + audit compensation.rebuild.refused, FAILURE
    if (! $this->option('yes') && ! $this->confirm('Wipe these rows and run the period again?', false)) { $this->info('Left as it is.'); return self::SUCCESS; }

    $this->audit('compensation.rebuild.started', $actorId, $plan, before: AuditDigests::of($plan->rowsToRemove));
    $removed = $planner->execute($kind, $period, $actorId, fn (string $m) => $this->line($m));      // the wipe, one transaction
    $this->audit('compensation.rebuild.wiped', $actorId, $plan, details: ['removed' => $removed]);

    $exit = $this->rerun($period);                           // Artisan::call($plan->rerunCommand) with output echoed; Throwable → FAILURE
    foreach ($plan->warnings as $w) { $this->warn('After this: '.$w); }

    if ($exit !== 0) {
        $reason = sprintf('%s was wiped (%d rows) but the re-run exited %d. Run this rebuild again once the cause is fixed; the next scheduled run also re-attempts the period.', $plan->kind->label(), array_sum($removed), $exit);
        $context->noteFailed($reason); $this->audit('compensation.rebuild.rerun_failed', …); return self::FAILURE;
    }
    $this->audit('compensation.rebuild.completed', $actorId, $plan, details: ['removed' => $removed, 'warnings' => $plan->warnings]);
    return self::SUCCESS;
}
```

`engine_runs` status of a rebuild: `skipped` when refused before any write; `failed` (with the reason above) when the wipe landed and the re-run did not; `succeeded` when both did. Nested engine rows are written as usual with `trigger = manual` and the actor from the context (the job binds it; from a shell the trait calls `$context->attribute(EngineRun::TRIGGER_MANUAL, $actorId, (string) Str::uuid())` before the re-run and `reset()` in `finally`).

**What happens if the wipe succeeds and the re-run fails:** the period's newest attempt is `failed` (D13), so the nightly backfill / monthly self-heal re-attempts it through the ordinary idempotent path on their next night; pressing the rebuild again is also safe — the wipe re-reads the (now partial) rows, rewinds from whatever advancing rows exist, and the re-run recomputes everything. A wipe that dies mid-way rolls back (one transaction).

### 33. `RebuildPeriodJob`

Mirror of the deleted `RetryNightlyChainJob`: `onQueue('compensation')`, `tries 1`, `timeout` 3600 (7200 for `month`), `handle()` binds `EngineRunContext::attribute(TRIGGER_MANUAL, $actorId, $chainId)`, calls `Artisan::call($kind->signature(), [$periodOption => $period, '--yes' => true, '--actor' => $actorId])`, logs `compensation.rebuild.job_{started,succeeded,failed}` with the trimmed output, `finally` resets the context; `failed()` marks `running` rows with this `chain_id` failed ("Rebuild job died: …").

---

## Deviations from the ask

| Ask | What the plan does | Why |
|---|---|---|
| "if the daily job is run again and again for a day … recalculated, clearing that day's data only" | Allowed only while that night is the newest one; refused once any later day has been cut off (D11) | The carry-forward store is rolling (R-91); replaying one day under a later one silently corrupts every later match. Same-day retries, as many as wanted, are honoured. |
| Daily retry "clears that day's data" | Repurchase cycle verdicts are not reset | They are frozen once by design and are computed from rows the night rebuild never touches (wallet as at D 23:59; BV ledger). The evaluate is re-run and is idempotent. |
| Weekly / monthly payout retry "removes any stale data" | Credits are un-swept, never deleted; a never-approved batch's own debits, lines and row are deleted (DN-1) | The ledger is append-only for earned commission and for money that moved. |
| Monthly retry "even if tried multiple times" | Refused once the month is frozen (D9) or the next month has been closed on it (D12); consumed purchase-offer grants and delivered award milestones are kept | Money moved / decisions taken on those figures; F124. |
| Payout retry "recalculate all the payments" | Only while the batch is unapproved; an approved batch with bank-rejected lines is not rebuilt (DN-2) | An approved batch is a payment instruction that left the company. |
| Weekly job "green" before the monthly close | On the 1st: tonight's `compensation.weekly-run` row succeeded (leaf `skipped` for a flag-off engine still counts); on later nights: no Tuesday owed, or tonight's weekly run succeeded | Makes "green" answerable from the run log; a flag-off Tuesday cannot block the close for ever. |
| Prerequisites on the hand-typed `compensation:monthly-close` | Not added there — only the orchestrators check them | The runbook's `--month --force` repair flows must keep working; the substance (every day cut off) is already the close's own preflight. |
| Re-run side effects | A rebuild re-emits the engines' domain events (income notifications may be sent twice) | Suppressing events is a separate change; noted in R-102. |

## Tests (Pest; run with the project's `-e` overrides, never bare)

```bash
docker exec -e DB_CONNECTION=mysql -e DB_DATABASE=arovolife_test -e DB_HOST=db -e DB_PORT=3306 \
  -e DB_USERNAME=arovolife -e DB_PASSWORD=secret arovolife-app \
  php artisan test --compact tests/Modules/Compensation tests/Feature/EngineRegistryTest.php
```

Read the first-month plan's "Existing tests this change breaks" before touching D1: every gate refusal test needs one BV entry in the month (`seedMonthSales()` helper, called per test, never in a global `beforeEach`). D13 will also move a few expectations: run `EngineStatusServiceTest`, `NightlyRunCommandTest`, `MonthlyCloseCommandTest`, `RunEngineChainJobTest`, `EngineChainResolverTest` first and fix any assertion that relied on "any earlier success counts".

| File | Change |
|---|---|
| `tests/Modules/Compensation/NightlyRunCommandTest.php` | Stub list shrinks to `repurchase.evaluate`, `gsb.daily-cutoff`. Delete/move every weekly/monthly/payout-switch/retry-scope test (homes below). Keep: ordinary night, dating, resume, in-flight re-run, first failing step, future night, `--restart`, backfill ×3, skipped night (asserts `details.orchestrator`), backfill-gap alert, cut-off proof ×2, projection refusal. Add: "runs only evaluate and cut-off on the 1st and on a Tuesday"; "`--restart` re-runs a cut-off whose latest attempt succeeded". |
| `tests/Modules/Compensation/WeeklyRunPlannerTest.php` (**new**) | Tuesday owed; frontier backfill; null-frontier one Tuesday only; flag-off Tuesday still owed / non-Tuesday nothing; `isDue` false on a healthy Wednesday. |
| `tests/Modules/Compensation/WeeklyRunCommandTest.php` (**new**) | builds the Tuesday; rebuilds a missed Tuesday the next night still dated Tuesday; leaves an existing batch alone; skipped when nothing owed; **defers with `skipped` + `weekly_run_deferred` alert when tonight's nightly run is failed / missing / dated tonight but started yesterday**; **runs when tonight's nightly run succeeded**; `--force` ignores the prerequisite; aborts on a failing step and audits `compensation.weekly_run.aborted`; future night; projection; records its own row. |
| `tests/Modules/Compensation/RunPrerequisitesTest.php` (**new**) | nightly: succeeded tonight → null; succeeded but started before tonight → refusal; failed latest → refusal naming the error's first line; never ran → refusal. weekly: not due → null; due and succeeded tonight → null; due and skipped → refusal; due and never ran → refusal. |
| `tests/Modules/Compensation/MonthlyRunPlannerTest.php` (**new**) | closePhase: whole → step; missing day → deferral(coverage, missingDays); cut-off in flight → deferral(cutoff_in_flight); GSB off → step; closed → empty; launch month closes mid-month (D2); missing days with distributors still defers; no distributor → whole; **nightly not green → deferral(prerequisite), no step**; **Tuesday-1st with weekly not green → deferral(prerequisite)**; **Tuesday-1st with weekly green → step**; **flag-off Tuesday-1st does not block**. payoutPhase: 8th gate open → step; gate shut → deferral naming the engine; lookback bounded (D1b); sales-free not deferred; **payout phase ignores tonight's nightly/weekly state**. isDue: 1st true; 5th false when closed; 5th true when unclosed and whole even if tonight's nightly failed; 8th true while no batch; 20th false once built. |
| `tests/Modules/Compensation/MonthlyRunCommandTest.php` (**new**) | closes on the 1st (nightly green stub row); pays a month it closed the same night; records `monthCloseDeferred` once per month per cause and exits 0 `skipped`; **prerequisite deferral recorded with cause `prerequisite`**; records `payoutDeferred` once per night per month; sales-free month not deferred; close failure → `failed`, payout not attempted, `compensation.monthly_run.aborted`; next night resumes; one payout per lookback + main. |
| `tests/Modules/Compensation/MonthlyPayoutCloseCommandTest.php` | `seedMonthSales()` on every refusal test; T1–T4 from the first-month plan. |
| `tests/Modules/Compensation/MonthlyCloseCommandTest.php` | T5, T6; existing refusal text unchanged; **refuses a frozen month even with `--force`, recording `skipped`**. |
| `tests/Modules/Compensation/FrozenPayoutGuardTest.php` (**new**) | pending batch → not frozen; `approved_at` set with status `failed` → frozen; each of approved/dispatched/completed/processing → frozen; no batch → null; message names batch, status, next enabled date. |
| `tests/Modules/Compensation/MonthlyEnginesFrozenMonthTest.php` (**new**) | dataset over the seven monthly commands: with a frozen batch for M each exits FAILURE with a `skipped` row and writes no result rows / wallet entries; with a pending batch each runs. Use a stub-free approach: flags on, empty month, assert the refusal text and `engine_runs.status`. |
| `tests/Modules/Compensation/EngineStatusServiceTest.php` | **D13**: `hasSucceededRun` false when a later `failed` attempt follows a success; true when a later success follows a failure; `skipped`/`running` after a success do not un-prove; `completedCutoffDatesBetween` drops a date whose latest attempt failed; `hasSucceededRunTonight` true only for a succeeded row dated tonight started ≥ 00:00 tonight; `failedRootRun` for each root. |
| `tests/Modules/Compensation/CarryforwardRewindTest.php` (**new**) | earliest in-window row per distributor wins; `below_600bv` / `repurchase_forfeited` rows ignored; legacy null side with power > 0 and no stored side throws; `apply()` keeps the stored side for legacy rows. (`WindowedRecomputeTest` must stay green untouched.) |
| `tests/Modules/Compensation/NightRebuildTest.php` (**new**) | uses real `gsb:daily-cutoff` over a 3-distributor tree for day D with GSB flag on: plan lists the rows; wipe deletes D's results/pools/MB/top-ups/wallet entries by reference and rewinds CF to the `*_before` values; re-run through the command yields identical `net_gsb_paise` and CF; refuses when a D+1 row exists (idle `no_match` included); refuses when a D credit is swept; refuses when a D row is reversed; refuses while a nightly run is in flight; run twice → same figures, one `gsb_credit` per result; failed re-run (stub cut-off exit 1) → rebuild row `failed`, D un-proven (`completedCutoffDatesBetween` excludes D); warnings: Tuesday → weekly deferred text; 1st → monthly close text; closed month → rebuild-month text; frozen month → refusal. |
| `tests/Modules/Compensation/PayoutUnbuildBatchTest.php` (**new**) | pending weekly batch: un-sweeps its credits and transfers, deletes its three debits and forfeits (sums audited), lines and row; `payout.batch.unbuilt` audit row with before digest; refuses approved / `approved_at` set / dispatched / completed / processing; refuses with gateway events; holds lines deleted (no debits); takes the sweep lock (second caller waits, as `PayoutServiceTest` "holds one sweep lock" does); re-running `runWeeklyBatch()` afterwards produces the same line items with the developer as `created_by`. |
| `tests/Modules/Compensation/WeekRebuildTest.php` (**new**) | plan/warnings: later pending batch → "rebuild these too"; later approved batch → "allocation stands"; missing batch → refusal; command end-to-end with `--actor` + `--yes`: batch rebuilt, maker = actor, actor cannot approve (reuse `HandlesPayoutBatchActions` self-approval refusal test shape). |
| `tests/Modules/Compensation/MonthRebuildTest.php` (**new**) | with stubbed close (as `MonthlyCloseCommandTest` stubs): wipe deletes every month table row (each asserted) and the referenced wallet entries, keeps `awards_credit`, consumed grants, delivered milestones and `is_carry_forward` qualifications; un-builds M's pending batch first with a warning; refuses frozen M; refuses when M+1 closed; refuses when an M credit is swept by a frozen batch; refuses when an M credit is reversed; re-run is called with `--restart`; failed re-run → rebuild `failed`, `hasSucceededRun('compensation.monthly-close', M)` false, planner re-attempts next night. |
| `tests/Modules/Compensation/PayoutRebuildTest.php` (**new**) | un-builds and re-runs `compensation:monthly-payout-close`; gate shut → batch removed, rebuild `failed` with the gate's text, warning present; later weekly batch warning. |
| `tests/Modules/Compensation/RebuildPeriodJobTest.php` (**new**) | binds the context (rows carry `manual` + actor + chain id); passes `--yes` and `--actor`; `failed()` closes running rows by chain id; queue name `compensation`; month kind timeout 7200. |
| `tests/Modules/Compensation/AdminEngineRunsControllerTest.php` | Retry tests → rebuild tests: **admin / admin-finance / admin-compliance / admin-operations get 403 on both POSTs and see neither the panel copy ("Rebuild a period") nor the preview**; developer sees the panel; preview stores the plan and renders counts + warnings; preview with refusals renders them and no confirm form; confirm with a stale fingerprint is refused; confirm queues `RebuildPeriodJob` and writes `compensation.rebuild.queued` with reason, warnings and chain id; reason min 10; the three failure banners render for admin without any button ("Retry this night" and "Rebuild" absent); rebuild engine cards are hidden from the index for everyone; `parsePeriodOrFail` refuses a frozen month for a manual trigger. Delete the seven `retry-chain` tests. |
| `tests/Modules/Compensation/EngineHealthDigestTest.php` | payout-deferred alert; weekly-deferred alert; prerequisite-deferred close headline; skipped-night names the weekly run; D5. |
| `tests/Feature/EngineRegistryTest.php` | fourteen → **twenty**; "every scheduled root orchestrator is registered by the scheduler and nothing else is" (three roots, three entries; the four rebuild orchestrators are unscheduled and `developerOnly`). |
| `tests/Modules/Compensation/PayoutServiceTest.php` | unchanged; `unbuildBatch` has its own file. |

## Slices

| Slice | Title | Files | Depends on | Model |
|---|---|---|---|---|
| S1 | Support: coverage, planners, prerequisites, frozen guard, alerts, D13 status semantics | 1–6, 16 (D13 + `hasSucceededRunTonight` + `failedRootRun`), 26, 27; tests: planners ×2, `RunPrerequisitesTest`, `FrozenPayoutGuardTest`, `EngineStatusServiceTest`, MonthlyPayoutClose T1–T4, MonthlyClose T5–T6 + frozen | — | Opus |
| S2 | The three runs and the end of the admin retry: commands, registry (the two run orchestrators only — the four rebuild definitions, `developerOnly`, `rebuildKeys()` move to S3 so no definition ever names a command class that does not exist), scheduler, listener, `chainStepOutcomes()` + D5, frozen guard in the seven engines (A10 `creditingRefusal()`), delete `RetryNightlyChainJob` + the `retry-chain` route + `retryChain()` + the retry button, replace the chain banner with the three informational `failedRuns` banners | 7–15, 16 (rest), 17, 18 (delete `retryChain()`; add `failedRuns`), 19 (banners only), 28, 34 (delete the route only); tests: Nightly/Weekly/Monthly command tests, `MonthlyEnginesFrozenMonthTest`, `EngineRegistryTest` (sixteen), digest D5, controller retry tests deleted + banner tests, `ScheduledCommandsAreRegisteredTest` | S1 (committed 55567a42) | Opus |
| S3 | Rebuild core: `CarryforwardRewind`, `PayoutService::unbuildBatch`, rebuilders, planner, the four commands, the job | 29–33; tests: `CarryforwardRewindTest`, `PayoutUnbuildBatchTest`, `NightRebuildTest`, `WeekRebuildTest`, `MonthRebuildTest`, `PayoutRebuildTest`, `RebuildPeriodJobTest` | S2 | Opus |
| S4 | Developer rebuild surface + admin copy: `rebuildPreview()`/`rebuild()`, `parsePeriodOrFail()` guards (frozen/closed-to-credits via `creditingRefusal()`, and the A1 belt for a past-dated cut-off), the `role:developer` routes, the `@developer` panel + preview card, health-service copy | 18 (rest), 19 (panel), 20, 34 (add the routes); tests: controller, digest copy | S3 | Opus |
| S5 | ADR, runbooks, help, risk register, plan bookkeeping | 22–25 | S1–S4 | Sonnet |

Run S1 alone first (money-adjacent logic, D13 in particular), then S2, then S3 alone (the wipers — review hardest), then S4 and S5 together. Never more than two implementers at once. Work on a branch off `main`; the uncommitted dashboard files in the tree belong to another slice — do not stage them. `compliance-officer` reviews every slice; S3's review must explicitly cover DN-1, D10, D11, D12.

**Most likely to break something:** S1's D13 (resume semantics everywhere) and S3's `NightRebuilder` (a wrong rewind corrupts every later match — its test must assert CF equality against the pre-wipe `*_before` values and figure equality after the re-run). Write the `NightRebuildTest` before the rebuilder.

## Verification

1. Pest (command above) — all of `tests/Modules/Compensation` and `tests/Feature/EngineRegistryTest.php` green.
2. `vendor/bin/pint --dirty --format agent`; `docker exec arovolife-app ./vendor/bin/phpstan analyse --level=7` clean on every changed file.
3. `docker exec arovolife-app php artisan schedule:list` shows exactly three compensation entries (`5 0 * * *`, `0 3 * * *`, `0 4 * * *`, Asia/Kolkata); `php artisan list compensation` shows the three runs and the four rebuild commands.
4. On dev (no projection standing): `compensation:nightly-run --date=<today>` exits 0 (two engines); `compensation:weekly-run --date=<today>` on a non-Tuesday exits 0 `skipped`; `compensation:monthly-run --date=<today>` exits 0 and records its row or a deferral alert; `compensation:rebuild-night --date=<today> --actor=<dev id>` prints the plan, confirms, wipes yesterday, re-runs, prints warnings, and a second run lands on identical `gsb_cutoff_results` figures and `gsb_carryforward` values (compare `SELECT distributor_id, net_gsb_paise, power_cf_after_paise` before/after); `compensation:rebuild-night --date=<yesterday>` refuses with the R-91 message.
5. Engine Runs page as `admin`: three banners possible, no retry/rebuild control anywhere in the HTML (`grep -c "rebuild" page.html` = 0); as `developer` (`staff:create`): the panel, a preview with counts and warnings, a confirm modal, a queued job, `compensation.rebuild.queued` in Audit Log. As `admin-finance`: POST to `engine-runs/rebuild` → 403.
6. `compliance-officer` PASS on the branch (payout gate, batch creation and un-build paths, maker-checker, DN-1, the frozen lock).

## Rollout (staging)

1. Merge to `main`; Cloudways `git_pull`; `app:deploy --skip-composer --skip-npm --skip-migrate --skip-seed` (no migration, no asset change). **Restart the scheduler and all three `queue:work` cron entries** (`WorkerFreshness` refuses a stale worker; the rebuild job runs on `compensation`). Confirm `DB_QUEUE_RETRY_AFTER` on the box exceeds 7200 (`config/queue.php` defaults to 90; `RecomputeAllJob` already needs this) — otherwise the database driver re-releases a long rebuild mid-run. `php artisan schedule:list`: three entries.
2. First monthly run (04:00 on the next night ≥ 8th): builds the September-dated monthly batch for August with 0 lines (August is sales-free → D1) and the standing `nightly_run.aborted` stops.
3. Staging's August and September need NO `--force` decisions any more: step 5's full recompute replays every day and every month from the first sale (see below). Do not run `compensation:monthly-close --month=… --force` on staging before it.
4. Rehearse one rebuild on staging with the client's knowledge: `compensation:rebuild-night` for the newest night, then `compensation:rebuild-week` for a pending weekly batch; confirm the figures match and the audit rows read as expected. Record the rehearsal in R-102.
5. **Fresh start (user decision 2026-09-18, options A/A): after the new code is deployed and the scheduler and workers are restarted, wipe every derived compensation row on dev and on staging and replay from the first sale, keeping orders, BV, distributors and KYC.** The tool is the existing ADR-0014 runner — `php artisan compensation:recompute-all --horizon=now` (full, NOT `--windowed`) — on dev inside `arovolife-app` against the `arovolife` database, and on staging over SSH against the Cloudways-local MySQL. It truncates every `DerivedTables` table (group BV, wallet ledger except `repurchase_wallet_used`, payout batches and lines, every engine result/pool/roster, repurchase cycles, `engine_runs`) and replays every leaf engine at the scheduler's own clock, so the replay itself cuts off September's 13 missing days and runs August's engines over nothing — rollout step 3's `--force` decisions become moot and must NOT be run beforehand. `audit_log` is never deleted (the old `compensation.nightly_run_*` alert rows age out of the digest's seven-day window; the new D5 rule resolves the stale failed root row on the first successful run). Each environment gets the five-part destructive-action warning (exact command, target host/container/database, `count(*)` per table, the non-destructive alternative, "Proceed? yes/no") and its own explicit yes immediately before the run — never batched, never assumed from this plan.
6. Production does not exist yet; nothing to do there.

## §22–25. Docs (Sonnet, S5)

- **ADR-0016 — Three engine cadences** (`docs/architecture/adr-0016-three-engine-cadences.md`): context (the three confusions; staging since 8 Sep); decision (the cadence table above, prerequisites included); the ordering argument; **"Retry-and-heal"** (D4, D10–D14, the wipe-scope tables, the refusal tables, the `engine_runs` statuses, "wipe succeeded, re-run failed"); **"The frozen-payout lock"** (D9); consequences (`schedule:list` three entries; deferrals are `skipped` + alerts; D5; the replay untouched; the rebuild path exists in production and is developer-only; `compensation:recompute-all` stays dev/staging only — ADR-0014 unchanged); alternatives rejected (one process/three stages; a shared blocking lock; dispatch-on-finish; a `finance.record` retry — same hand makes and approves; reversal rows for a pending batch's debits — DN-1). ADR-0015 status → "Accepted; amended by ADR-0016 (steps 3–5 moved to their own runs; the retry became a developer rebuild, 2026-09-18)".
- **`docs/runbooks/engine-failure-triage.md`**: §2 rows for the weekly/monthly deferrals and "a run was deferred on a prerequisite"; §4 "What the retry button does and does not cover" → **"What a rebuild does and does not cover"**: rebuilds the newest night ✅ / a night a later cut-off has passed ❌ (R-91, §14) / an unapproved batch ✅ / an approved batch ❌ (line retry) / a frozen month ❌ / a month the next month was built on ❌ / clears a `skipped` night ❌; the four commands with `--actor`; §7/§8: `compensation:rebuild-week`/`rebuild-payout` replace the hand `gsb:weekly-payout` re-entry for a *pending* batch; §9 keeps `payout:reopen-stuck-batch` then rebuild; §10 the frozen lock; §14: the rebuild-night command is the sanctioned same-day remedy; §14a–d unchanged for a passed day (the clone procedure stands, drafted not rehearsed). Standing constraints: "the monthly run plans its payout phase after its close phase", "a rebuild wipes then re-runs through the ordinary command — never write a credit by hand", "`FrozenPayoutGuard` has no override; do not add one".
- **`docs/runbooks/artisan-commands.md`**: the three runs, the four rebuild commands (options, refusals, the `--actor` rule), the flag mentions, the schedule table (three entries).
- **`app/resources/help/compensation.md`**: every "inside the nightly chain" sentence (weekly ~110, Rank ~152, Fortune ~222, digest ~257–281, close/payout ~328–329, CLI ~333); the retry paragraphs (~319–326) become: the page shows a failed run and what each step did; a failed run is repaired by the platform team's rebuild, which wipes that period and runs it again from scratch — nothing is paid twice because the period's rows are removed first; the deadline for a night is still 00:05 the next morning; a rebuilt batch names its maker and needs a second approver; once finance approves a month's payout the month is frozen and its engines will not run again until the next month's 1st. **No sentence may describe a control admins cannot see.**
- **`app/resources/help/payout-operations.md`**: §2 and "Monthly close": weekly run Tuesdays 03:00 IST after the nightly run is green; monthly run 04:00 IST, the 1st waits on the nightly (and Tuesday) runs, the 8th is independent; "Approving a monthly batch freezes the month"; a pending batch that must be recomputed is rebuilt by the platform team, an approved one is corrected line by line.
- **`docs/compliance/risk-register.md`**: **R-101** (launch month and the sales-free wave-through; the empty pending batch); **R-102 — A developer-driven single-period rebuild now exists in production** (Statutory/Operational — High): what it can delete and un-sweep, the refusals (D9–D12), DN-1's ledger exception, that it is a one-role control with no maker-checker of its own (mitigants: the two-step preview, mandatory reason, three audit rows per rebuild, the rebuilt batch's maker bar, `RebuildPreflight`), that events are re-emitted, and the open item "rehearsed on staging: no". Addenda: **R-81** (the developer who rebuilds a batch is its maker and cannot approve it; the `finance.record` retry is gone; a hand-typed weekly/monthly run still has no maker); **R-89** (backfill caps); **R-90** (three processes; the ordering guard is the run log); **R-91** ("Delivered 2026-09-18: `compensation:rebuild-night` is the same-day remedy for a failed night, with the R-91 window as a first-class refusal; a night a later cut-off has passed is still §14's clone procedure; the stranded-BV and never-comes-back mechanisms are unchanged for that case").
- `docs/plans/2026-09-18-nightly-chain-first-month.md`: prepend "**Superseded 2026-09-18 by `2026-09-18-engine-cadence-split.md`** — D1/D2 carried over, D3 replaced by the monthly run." Copy this plan to `docs/plans/2026-09-18-engine-cadence-split.md`.

## Could not verify in the code (implementer: check in S1/S3 before relying on it)

- Whether `RankQualificationService` / `RankRequalificationGateService` write anything **outside** `rank_qualifications` for month M (grep `->update(` / `Distributor::` there; the only in-place write found is the legacy `voidRank1CarryForwardsForRank2Qualifiers()` on `is_carry_forward` rows, which the month wipe leaves alone). If a distributor-level rank column is written, add it to the month wipe as a "restate from the rebuilt rows" step.
- Whether any report sums `payout_debit`/`tds_debit` across deleted line-item ids in a way that caches (none found; DN-1 assumes live sums).
- `WalletLedgerEntry::typeLabels()` has no `reversal` label; not needed by this plan (no reversal rows are written) — noted in case DN-1 is decided the other way.
- The Spatie `role:` middleware's 403 rendering (`bootstrap/app.php:130` maps 403 → "Access denied."); DN-4 accepts it.

## Out of scope — worth raising

- An additive index on `distributors.effective_date`.
- A weekly-run preflight that refuses when a day inside the earning window has no completed cut-off (neither the chain nor the sweep checks today).
- Suppressing re-emitted domain events/notifications during a rebuild (R-102 notes it).
- Stamping a maker on a hand-typed `gsb:weekly-payout` / `compensation:monthly-payout-close` (pre-existing R-81 residual).
- R-92 (`reverseCredit` maker-checker follow-ups) and the two open client questions in R-85 — untouched.
- A `superseded` payout batch status instead of hard-deleting an unapproved batch (would need a MySQL enum migration and a change to the `batch_type + batch_date` unique index).
