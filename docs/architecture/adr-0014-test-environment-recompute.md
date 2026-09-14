# ADR-0014 — Test environments run compensation as one recompute at the scheduler's clock

- **Status:** Accepted
- **Date:** 2026-09-14
- **Deciders:** Platform Architect, Product Manager, Compliance Officer
- **Builds on:** ADR-0011 (queue transport), the recompute scaffold (2026-08-17)
- **Supersedes:** the "testing-only, delete at sign-off" framing of `compensation:recompute-all`

## Context

Compensation is computed by eleven engines on a calendar. The calendar is not
decoration: every engine judges a period **after** that period has ended, and the
rows it writes carry the timestamp of the run, not of the period.

- `repurchase:evaluate` runs at 00:05 and judges cycles as at that morning.
- `gsb:daily-cutoff` runs at 00:10 **for the previous day**.
- The monthly close runs on the **1st** and credits the month that just ended.
- The monthly payout batch runs on the **8th**, a week later.

Three of those engines take a 10 % repurchase deduction **at credit time**, and
three others ask whether a distributor's repurchase wallet was empty **at the
last instant of the month**. Production is correct because of the calendar and
nothing else: Rank Bonus's deductions are dated the 1st of the following month,
so the month they belong to cannot see them.

Two tools had drifted away from that calendar.

1. **The recompute's catch-up pass** computed the period "in flight at the
   horizon" at the real clock — i.e. *inside* the period. On staging, 14 Sep
   2026, a tester zeroed a repurchase wallet, ran GSB, MSB and Rank Bonus, and
   Growth Booster and Fortune then refused to pay September: Rank Bonus's
   deductions, dated 14 September, were counted against September's own
   month-end wallet gate.
2. **The replay fired the daily cut-off a day early** (F125, 12 Sep 2026): the
   cut-off for day D at D 00:10 instead of D + 1 00:10. The visible symptom was
   the next real scheduled run aborting on the carry-forward out-of-order guard.
   The invisible one was the same as (1) at day scale — the month's last day's
   deductions landed inside the month being judged.

Separately, the admin Engine Runs page **lifted the closed-period rule** whenever
the recompute gate was open, so on a test environment any admin could freeze the
live month from a form — the 24 Aug 2026 incident at month scale.

The question this ADR settles is what a dev or staging environment should do
instead, given that its whole purpose is to answer "what will the plan pay?"
before production has to.

## Options considered

### A. Freeze the month-end wallet verdict in its own table

Add `repurchase_wallet_month_end_freezes` / `_blocks`, write them once per month
as step 1 of the close, and have every engine read the frozen verdict instead of
the ledger.

- **Pros:** the verdict is explicit, inspectable and immune to whatever clock the
  engines run at.
- **Cons:** two tables, a new engine, a new report and a new failure mode
  (unfrozen month) to carry forever — all to restate something the calendar
  already guarantees. It treats the symptom: the engines would still be running
  at the wrong instants, so the cut-off would still be a day early and the
  monthly pools would still be priced on partial BV. And a frozen verdict is one
  more thing that can disagree with the ledger it was derived from.

### B. Defer every repurchase deduction to the end of the monthly close

Credit gross, then take all the deductions once the close has finished.

- **Cons:** breaks the credit-time three-entry ledger invariant the whole
  repurchase-wallet design rests on; the weekly payout batch would sweep
  un-deducted gross for the days between; the daily engines (GSB, MSB) have no
  batch end to defer to. It also changes what distributors are paid, which is a
  plan change, not a bug fix.

### C. Replay the scheduler's own clock, and make that the only way a test environment computes anything

Fire every engine at the instant the scheduler would have fired it, for the
period the scheduler would have handed it. Stop at a chosen **horizon**. Remove
the per-engine manual triggers from test environments entirely.

- **Pros:** the bug disappears by construction rather than by a new mechanism —
  a month's crediting engines run on the 1st of the next month, so nothing they
  write can reach the month they judge. It fixes F125 and the premature-freeze
  class in the same move, because both were "an engine ran at the wrong
  instant". It needs no new table, no migration and no new concept in the domain.
  And a test environment then produces figures that are reproducible from the
  orders alone.
- **Cons:** a historical test window can no longer be asked for as an arbitrary
  `to` date — a test has to pin the clock, which is more honest but less
  convenient. Simulating the future requires an explicit mode with real
  consequences (below).

## Decision

**Option C.**

1. **The replay is an instant loop.** For each day from the window start to the
   horizon, each due engine fires at `cadence->atOn($day)` for
   `definition->periodForFireOn($day)`, and is skipped if that instant is after
   the horizon. The catch-up pass, the "in flight" special case and every
   open-month override except the monthly batch's are deleted. `EngineCadence`
   gained a structured `previousDay` flag so the cut-off's "fires for the day
   before" is read rather than sniffed out of a prose note.

2. **Three horizons** (`RecomputeHorizon`), and only `now` is production-faithful:

   | Horizon | Stops at | Simulates |
   |---|---|---|
   | `now` | this instant | nothing |
   | `today` | tomorrow 00:10 IST | today's evaluation and cut-off |
   | `projection` | the 8th of next month, 04:00 IST | the rest of the month, its close, its payout |

3. **A projection is a declared state, not a side effect.** The audit row a
   recompute writes carries its horizon — written before the wipe starts, and
   naming the shell account and host when it came from the command line;
   `RecomputeState` reads the newest one. While a projection stands:

   - every page (admin, distributor, storefront, wizard) and the two standalone
     printable pages carry a banner saying the figures are simulated and through
     when — on the printables outside `.no-print`, so it survives Save-as-PDF;
   - every report download through `ReportExport::respond()` is renamed
     `PROJECTED-<date>-…` and carries a notice row, because a spreadsheet
     outlives the page it came from;
   - **approving a payout batch and building a bank NEFT file are refused** — a
     forecast must not become an instruction to move money;
   - **the scheduled compensation engines are paused**, including the health
     digest and `payout:auto-retry-failed` (the only one that reaches a bank).
     They would otherwise run against a carry-forward store the projection has
     already advanced past. That check fails CLOSED: a skipped nightly run on a
     test environment is recoverable, a corrupted carry-forward chain is not.

   A nightly `compensation:recompute-all --horizon=now --if-projected` at 23:30
   IST puts the environment back before the 00:05 engines are due; its schedule
   entry carries a positive environment allow-list (`local`, `testing`,
   `staging`) on top of `RecomputeGuard`, because it is the only unattended
   destructive command on the platform.

4. **Engines are not triggered individually on a test environment.** The Engine
   Runs page renders no trigger forms there and the endpoint refuses. In
   production the page is unchanged: `RecomputeGuard` is shut, so every
   behaviour above is inert.

5. **The month-end wallet gate refuses a month that has not ended.** Asked
   mid-month the ledger sum silently answers a different question, so
   `clearedAtMonthEnd()` throws `RepurchaseWalletVerdictNotAvailable`; a separate
   `standingAtMonthEnd()` serves the dashboard and the AO-GO card, never throws
   and never decides anything. `compensation:monthly-close` loses `--in-flight`
   altogether — closing an unfinished month is exactly what let one engine's
   deductions land inside the month the next was judging.

6. **`compensation:recompute-all` is no longer scaffold to delete.** It is how a
   test environment runs compensation. It stays permanently forbidden in
   production by the same three locks (never production, `COMP_RECOMPUTE_ENABLED`,
   the connected database named in `COMP_RECOMPUTE_ALLOWED_DATABASES`) plus the
   typed confirmation.

## Consequences

- Production behaviour is unchanged in every respect. The only production-visible
  code changes are a refusal that nothing in production can reach, one fewer
  command option, and a banner that renders nothing there.
- A recompute between 00:00 and 00:10 IST is no longer dangerous: the pause
  covers the window that the old operational rule covered by hand.
- Test figures are reproducible: same orders plus same horizon gives the same
  database, because nothing depends on the hour the operator clicked.
- A projection is visibly provisional everywhere it can be read or downloaded,
  and cannot be approved or sent to a bank at all — which is what hard rule 3
  requires of a figure nobody has earned yet, staging included. The residual is
  someone quoting a number off a bannered page.
- The two open client questions about the month-end wallet rule (late-month
  deductions with little time to spend; one balance judged against both the
  cycle due date and the calendar month end) are **untouched** by this decision
  and remain open as R-85.
