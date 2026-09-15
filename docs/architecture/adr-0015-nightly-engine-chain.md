# ADR-0015 — One nightly chain replaces the compensation clock offsets

- **Status:** Accepted
- **Date:** 2026-09-15
- **Deciders:** Platform Architect, Product Manager, Compliance Officer
- **Builds on:** ADR-0011 (queue transport), ADR-0014 (test-environment recompute), the monthly close (2026-09-08)
- **Supersedes:** the five `Schedule::command` entries that fired the compensation engines

## Context

Compensation ran on five scheduler entries, sequenced by nothing but the clock:

| Entry | Time (IST) | Depends on |
|---|---|---|
| `repurchase:evaluate` | 00:05 | — |
| `gsb:daily-cutoff` | 00:10 | the evaluation above |
| `compensation:monthly-close` | 1st, 00:20 | the whole closed month's cut-offs |
| `gsb:weekly-payout` | Tue 03:00 | the week's cut-offs |
| `compensation:monthly-payout-close` | 8th, 04:00 | the month's crediting engines |

`withoutOverlapping()` is per-command and does **not** serialise across
commands, so the offsets were a hope, not a guarantee. An evaluation that
overran five minutes, failed, or never started still let the cut-off proceed on
yesterday's repurchase verdicts — and a day credited that the rules forfeit (or
forfeited that the distributor fulfilled) is never corrected afterwards. The
1st is the heaviest night of the month, when the slack was thinnest.

The monthly close had already been given a guard for this: it polled for up to
ten minutes for the closed month's last cut-off and then aborted. That is the
shape of the problem — a process with no way to observe another process can only
wait and give up.

Two further failures had no guard at all. A night the scheduler skipped, because
the previous night's work was still running, started no command: no run row, no
exit code, nothing for the failure badge, the missing-period check or the health
digest to see. And because the cut-off entry was hard-wired to
`--date=yesterday`, the day that night would have cut off was never computed —
a day nobody is credited for, with no record that it happened.

## Decision

**One entry. `compensation:nightly-run`, daily at 00:05 IST, `withoutOverlapping()`,
paused by the same recompute filter as before.** It runs, in one process, every
engine the night is due, and a step runs only after the step before it exited 0.

1. `repurchase:evaluate --date=<tonight>`
2. `gsb:daily-cutoff --date=<yesterday>`, preceded by any night that was missed
3. `compensation:monthly-close` — the moment the closed month's last day is cut off
4. `gsb:weekly-payout` — Tuesdays, and the next night if a Tuesday's batch was never built
5. `compensation:monthly-payout-close` — the 8th, and later nights while no batch exists

The engines themselves are unchanged. Each still records its own `engine_runs`
row, each is still individually runnable from the CLI, and the two closes remain
orchestrators in their own right — the chain simply nests them.

Four properties carry the design:

- **A period is judged only after it has ended.** The evaluation is dated
  tonight precisely so it has seen the whole of every day the chain is about to
  cut off; the closes are given closed months.
- **Resume, never restart.** A re-run skips every step already recorded
  *succeeded* for its period, using the same period-end rule the monthly close
  uses: a run stamped inside its own period does not count.
- **Completeness is proven from the run log, not from result rows.** The cut-off
  commits per distributor, so a crash leaves a partial day whose result rows look
  finished. A day counts as cut off only when a succeeded run started after that
  day had ended.
- **Nothing silent.** A skipped night, a gap too wide to heal, and a month the
  chain would not close are each written to `audit_log` and reported in the
  health digest, because none of them produces a failed run for anything else to
  find.

**The monthly close's ten-minute poll is deleted**, and its two assertions are
kept as single-query checks: refuse while a cut-off is in flight, and refuse a
month whose days are not all cut off. The chain satisfies both by construction;
the command is still runnable by hand, which is exactly when they matter.

**ADC and purchase offers move to the end of the crediting close.** They are not
on the critical path — ADC takes no repurchase deduction and purchase offers move
no cash — so the money is credited before the close spends its time on the most
expensive step of the month.

## The structural limit

The five crediting engines before them stay **serial**, and this is the
constraint that bounds any further parallelism: they all credit through
`WalletService::creditWithRepurchaseDeduction()`, and the shared monthly
repurchase cap is summed by `repurchaseDeductionForMonthPaise()` **non-atomically**.
Two engines crediting the same distributor concurrently would each read the cap
before the other's write and deduct twice from a balance that can only pay once.

Parallelising the crediting tail therefore requires making that cap atomic
first — a database-level reservation, not a faster loop. Until then the chain is
deliberately one process, and the win available is ordering, not concurrency.

## Consequences

- `php artisan schedule:list` shows **one** compensation entry, not five.
- Cadence stops meaning "fires at 00:10". For an orchestrated engine the
  declared time is its **position in the night**: the admin console renders "in
  the nightly chain from 00:05 IST", and the health digest judges the engine by
  the chain's window rather than by a minute production has not used since.
  The recompute replay still sorts a day's engines by that value — the order is
  the chain's order — so replayed figures are unchanged.
- A lost night now heals itself for cut-offs, weekly batches and the monthly
  batch alike. What does not heal is recorded and reported.
- A month is never closed short. That is a refusal, not a repair: the missing
  days have to be cut off before the month can be closed, and until then nobody
  receives Growth Booster, Rank Bonus, Fortune or ADC for it.
- Catching up by hand (`--date=<past night>`) replays cut-offs but builds no
  payout batch unless `--with-payouts` is passed; the command also re-checks the
  recompute gate itself, so a hand-typed run on a projected environment is paused
  like the scheduled one.

## Alternatives rejected

- **Retune the offsets.** Wider gaps make the failure rarer, not impossible, and
  they cost the night the time they buy.
- **A queue chain.** The compensation queue is one worker with `tries 1`
  (ADR-0011); a chained job buys nothing a single process does not already give,
  and adds a transport that can drop work.
- **Keep the poll and add more of them.** Polling is what a process does when it
  cannot observe the thing it is waiting for. Inside one process there is
  nothing to observe.
