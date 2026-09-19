# ADR-0016 — Three engine cadences replace the nightly chain, and a developer-only rebuild replaces the admin retry

- **Status:** Accepted
- **Date:** 2026-09-18
- **Deciders:** Platform Architect, Product Manager, Compliance Officer, the client
- **Builds on:** ADR-0011 (queue transport), ADR-0014 (test-environment recompute)
- **Supersedes:** ADR-0015's single `compensation:nightly-run` chain (steps 3–5) and the admin "Retry this night" control

## Context

ADR-0015 collapsed five scheduler entries into one: `compensation:nightly-run`
at 00:05 IST ran the repurchase evaluation, the GSB/MSB cut-off, the monthly
crediting close on the 1st, the Tuesday weekly payout batch, and the monthly
payout close from the 8th, aborting at the first non-zero step and recording
one `engine_runs` row whose status was the worst step's.

That shape produced three confusions the client raised on 2026-09-18:

1. **One row hides three cadences.** A refused monthly step marked the
   night's *daily* work failed, even though the cut-off itself had succeeded.
2. **The copy lied about the client's own mental model.** The Engine Runs
   page described the weekly and monthly engines as "inside the nightly
   chain", when the client's own language is "daily closing, weekly payout on
   Tuesdays, monthly on the 1st paid on the 8th."
3. **A pre-trading month can never be closed or paid**, and staging had been
   failing on exactly that since 8 September — the chain aborted every night
   on a monthly refusal that could never resolve on its own.

The same day, the client also decided how a failed run is repaired. Verbatim:
every run gets a **retry-and-heal** that rebuilds *that period only* — a
night's retry clears that day's calculations and recomputes them; a week's
retry removes the stale batch and re-runs that week's payout; a monthly
retry re-runs only the failed month, clearing previous failed attempts' rows;
the 8th's payout retry recalculates every payment and re-freezes the
per-distributor amounts. Once finance freezes a month's payout, every
monthly engine for that month is disabled until the next 1st 00:00 IST. **The
retry belongs to the developer role only** — no admin access — and it must
warn the developer, before and after, which other runs are affected and must
be run next.

Three invariants the platform already holds narrow how literally that ask is
implemented (see *Alternatives rejected* and the plan's own *Deviations from
the ask*): the carry-forward store is rolling (R-91), the wallet ledger is
append-only for money that has moved, and a payout batch finance has signed
off is immutable.

## Decision

**Three orchestrators, three scheduler entries, three run rows — and one
ordering rule between them, answered from the run log rather than a shared
lock.**

| Orchestrator | Command | Scheduler | Waits on | Self-heal | Developer rebuild |
|---|---|---|---|---|---|
| Nightly Run (kept, narrowed) | `compensation:nightly-run` | 00:05 IST | nothing | missed nights backfilled to 31 | `compensation:rebuild-night` |
| Weekly Run (new) | `compensation:weekly-run` | 03:00 IST | tonight's Nightly Run succeeded | a missed Tuesday built the next night it is green, still dated that Tuesday | `compensation:rebuild-week` |
| Monthly Run (new) | `compensation:monthly-run` | 04:00 IST | close phase: tonight's Nightly Run (and the Weekly Run, when a Tuesday batch is owed); payout phase: nothing | re-attempted every night it is due | `compensation:rebuild-month`, `compensation:rebuild-payout` |

The Nightly Run keeps only the repurchase evaluation and the GSB/MSB cut-off —
the two steps with no dependency on anything else that night. The Weekly and
Monthly runs are new commands with their own `engine_runs` root row, their own
failure banner, and their own alert vocabulary. A dependent run that cannot
proceed records a `skipped` row and an alert naming what it is waiting for —
never a `failed` night — and is re-attempted automatically. See
`docs/runbooks/artisan-commands.md` for the full command reference and
`docs/runbooks/engine-failure-triage.md` for the decision tree.

**Why these clocks, and why no lock.** 03:00 is the position the weekly
sweep already declared; the batch dated Tuesday T sweeps entries earned on or
before T−7, which the previous night's cut-off (`earned_on = T−1`) never
touches, so the prerequisite on the Nightly Run is the client's *ordering*
rule, not a data dependency — a deferral costs one night, never a figure.
04:00 is the position the monthly payout close already declared; on the 8th
the weekly and monthly sweeps share the blocking lock
`compensation:payout-sweep` (`PayoutService::withSweepLock()`), which
serialises them against the ₹50L income cap headroom, itself counted from
swept rows only — so a Tuesday landing on the 1st is safe in either order.
**A shared cache lock across the three commands was rejected**: the default
cache is Redis, shared with eight other apps under `allkeys-lfu` (ADR-0011),
which may evict a lock silently. `RunPrerequisites` asks `engine_runs`
directly — "did tonight's Nightly Run succeed?" — which is durable and
already the source of truth for everything else in this design.

**The replay is untouched by construction.** `EngineReplayService` fires
leaf engines only, ordered by declared cadence time; no leaf's position
changed, and the four rebuild commands are unscheduled orchestrators, so a
recompute replay produces byte-identical figures to before this ADR.

### Retry-and-heal

**Retry means rebuild the period from scratch, and only the developer may do
it.** Four commands, one per period kind — `compensation:rebuild-night`,
`-week`, `-month`, `-payout` — each following the same shape: preflight
(recompute gate, stale worker, nothing else in flight) → plan (refusals, row
counts, downstream warnings, a fingerprint) → wipe that period's derived rows
in one transaction → re-run the ordinary command for that period → print the
downstream warnings. Idempotent by construction: every attempt wipes the
previous attempt's rows first, so pressing it again and again lands on the
same figures. Exposed on the Engine Runs page **only** to `developer`
(`role:developer` route middleware plus a controller `abort_unless`, zero
trace for every other role — no banner, no button, no card), and on the CLI
with a mandatory `--actor` naming a developer.

**What a rebuild deletes, un-sweeps, and never touches** (the wipe-scope
tables in the implementation plan are the authoritative reference; summarised
here):

| Period | Deletes | Un-sweeps | Never touches |
|---|---|---|---|
| Night N (cut-off day D=N−1) | D's cut-off results, daily pools (GSB/MSB), mentorship results, personal-BV top-ups, and their wallet credits by reference | — | `engine_runs`, `repurchase_cycles`, group BV, orders, the BV ledger, `audit_log` |
| Week T (Tuesday) | The batch's own debits (`payout_debit`/`admin_charge_debit`/`tds_debit`/`income_cap_forfeit`), its line items, the batch row | The batch's swept credits | a batch with gateway events, or one finance has approved |
| Month M | The month's rank/GBB/Fortune/ADC/purchase-offer rows and their wallet credits; M's unapproved batch if one exists | M's swept credits (via the batch un-build) | consumed purchase-offer grants, delivered award milestones, `is_carry_forward` qualification rows |
| Payout M | M's unapproved batch's own debits and line items | M's swept credits | an approved batch |

**A rebuild never touches money that left the company** (D10). Credits swept
by an unfrozen batch are *un-swept*; credits swept by a frozen batch, or a
batch with gateway events, make the rebuild refuse outright. A rebuild
deletes only rows the re-run re-derives, and re-creates them through the
engines' ordinary product-sale-chained path — no credit is ever written by
hand. The plan's own headline for this ("a rebuild never deletes a credit")
is looser than both §29 and the code, which do delete unswept, unreversed
credit rows on the night and month paths (the table above: night N and month
M both delete "their wallet credits by reference"). The exact rule, as
corrected by `compliance-officer` in the S3 review:

> A rebuild never deletes a credit that has been paid, swept or reversed, and
> never deletes a credit except together with the result row it derives
> from, in the same transaction, in order to re-derive both.

**A never-approved batch's own sweep-time debits are deleted, not reversed**
(DN-1, the client's explicit choice, option A). They are the batch's
projection of a payout that never happened, written in the same transaction
as the line items they sit beside; TDS and admin-charge reports sum these
types directly and would need a new netting rule for reversal rows instead.
`compliance-officer` signed DN-1 in the S3 review specifically, and only
while five conditions hold — see R-102, which carries them as the condition
of that sign-off.

**A night can be rebuilt only while it is the newest night** (D11). The
carry-forward store is rolling; once any cut-off row exists for a later date
— including an idle `no_match` row — the rebuild refuses with the same
message `GsbCutoffService`'s out-of-order guard already gives (R-91), because
rewinding to an earlier before-state would silently erase a later day's
match. Same-day retries, as many as wanted, are honoured.

**A month can be rebuilt only while nothing later has been built on it**
(D12): not frozen (see below), no credit of M swept by a frozen batch, and no
crediting engine or close has succeeded for M+1 (DN-5) — the next month's
engines already read this month's ranks, wallet-zero verdicts and Fortune
roster.

**The rebuild's actor is the batch's maker** (D14). A rebuilt batch records
the developer as `created_by`; the existing self-approval refusal then
refuses their own approval of it (R-81 holds — a second person must approve).

**The latest finished attempt decides whether a period is computed** (D13,
DN-3): one rule in `EngineStatusService`, read by the resume logic of both
closes, the cut-off frontier, the planners and the prerequisites. A failed
re-run un-proves a period, so a rebuild that wipes a period and then fails
does not leave it reading "computed" with no rows behind it — the next
scheduled run or another rebuild attempt lands on the same figures.

**`engine_runs` status of a rebuild:** `skipped` when refused before any
write; `failed` when the wipe landed but the re-run did not (the next
scheduled run and another rebuild attempt both re-derive correctly); `succeeded`
when both did.

### The frozen-payout lock

**A month is frozen the moment finance's hand leaves it pending** (D9): the
monthly batch dated the 1st of M+1 has `approved_at IS NOT NULL`, or a status
in {approved, dispatched, completed, processing}. While frozen, every monthly
engine for M, the close for M, and both month rebuilds for M refuse —
`--force` does not pass it, and there is no override. The lock needs no
un-freeze mechanism: the *next* month has no frozen batch of its own, and
`OpenMonthGuard` already refuses it until it has ended, so the engines are
"enabled again on the 1st 00:00 IST" simply by there being nothing yet to
freeze.

A **pending** batch — built but not yet approved — does not freeze the
month (DN-6), so `compensation:rebuild-payout` can still run against it; but
nothing may be credited into the month while its batch is pending
(`FrozenPayoutGuard::creditingRefusal()`, A10), because the batch would never
pick a late credit up and finance would be approving a batch that no longer
matches the ledger.

## Consequences

- `php artisan schedule:list` shows **three** compensation entries, not one.
- A deferral is always an alert and a `skipped` run — never a `failed`
  night — for every dependency in the design: the weekly wait on the
  nightly run, the monthly close's wait on the nightly (and weekly) run, a
  month with an incomplete cut-off, and a month whose crediting is not yet
  complete for its payout.
- A root orchestrator's failed run resolves on its own next success,
  whatever the period (D5) — the standing alert for a stale failed row
  clears itself rather than needing a human to acknowledge it.
- The rebuild path exists **in production** and is developer-only.
  `compensation:recompute-all` (ADR-0014) stays dev/staging only and
  unconditionally refused in production — the two tools solve different
  problems: a recompute rebuilds the platform's whole history at the
  scheduler's own clock; a rebuild repairs one period's failed run without
  touching anything else.
- Cadence text now names the orchestrator ("in the Weekly Run from
  03:00 IST"), never "inside the nightly chain".
- No admin role retains a retry control of any kind. The Engine Runs page
  shows a failed run to every admin role as information only — what
  happened, what it did not do, and that nothing is lost by waiting — with
  no button and no mention of a retry.
- Domain events are re-emitted on a rebuild's re-run (income notifications
  may be sent twice); suppressing them during a rebuild is out of scope here
  and is tracked as R-102's residual.

## Alternatives rejected

- **One process, three internal stages, still one run row.** Solves none of
  the three confusions: the row still reads one status for three cadences,
  and the copy still cannot say "Weekly Run" truthfully.
- **A queue chain across the three commands.** The compensation queue is one
  worker with `tries 1` (ADR-0011); a chained job buys nothing a scheduled
  command with a run-log-based prerequisite does not already give, and adds
  a transport that can silently drop work.
- **A shared blocking lock instead of reading `engine_runs`.** Rejected for
  the same reason ADR-0015 rejected polling: the default cache is Redis
  shared with eight other apps under `allkeys-lfu` (ADR-0011), which may
  evict a lock silently — a silently dropped compensation lock is worse than
  the ordering it is meant to protect.
- **Dispatch-on-finish** (the Nightly Run enqueues the Weekly Run on
  success). Rejected because a scheduled `when()` predicate that reads the
  run log is simpler, needs no new transport, and degrades the same way a
  missed cron entry always has — the next scheduled tick catches it.
- **Keep the `finance.record` retry, narrowed further.** Rejected because the
  same permission both creates and would re-create a payout batch — the same
  hand makes and would remake what it can also approve. A developer-only
  control with a two-step preview and a mandatory reason is the separation of
  duties the client actually asked for.
- **Reversal rows for a pending batch's own debits, instead of deleting them
  (the DN-1 alternative).** Rejected by the client: TDS and admin-charge
  reports sum these types directly, so a reversal type would need its own
  netting rule everywhere they are read, for rows that were never a real
  payout to begin with.
