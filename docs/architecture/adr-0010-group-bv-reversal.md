# ADR-0010 — Cancelled-order group BV reversal (snapshot + same-side forward debt)

- **Status:** Accepted
- **Date:** 2026-07-05
- **Deciders:** Laravel Architect, Compliance Officer, Product Owner (KP's rules, Q8 2026-06-27 + Round-2/3 follow-ups)
- **Builds on:** ADR-0006 (BV ledger), ADR-0009 (refund pipeline)

## Context

An order's BV propagates on payment to every placement ancestor's
`group_bv_daily` accumulator (left/right, per day) and is consumed by the daily
GSB cut-off. Cancelling or refunding the order reversed only the buyer's
**personal** BV (`BvLedgerService::reverse`) — the propagated **group** BV
stayed in the upline forever, so cancelled sales kept feeding GSB matches.

KP's confirmed rules for cancelled orders:
1. Reverse the BV up the **whole upline chain** to the company root.
2. **No clawback** of GSB already credited/paid; titles and ranks are sticky.
3. Deduct the reversed BV from **future** BV **on the same leg**, carrying a
   negative balance until covered ("negative-carry").

Additional requirement (Product Owner, 2026-07-05): the reversal must debit
**only the distributors the BV was originally credited to** — re-deriving the
upline at reversal time would hit the wrong people after a line change.

## Decision

Three tables + a reversal service, wired to both reversal entry points
(`OrderStateMachine::cancel` and `RefundOrder::execute`, via
`OrderStatusChanged(cancelled)` / `OrderRefundApproved` → queued
`ReverseGroupBvJob`):

1. **`group_bv_credits`** — per-ancestor snapshot `(order_id, ancestor_id,
   side, bv_paise, debt_consumed_paise, date)` written by
   `GroupBvAccumulatorService` in the same transaction as the accumulation.
   The reversal (and the Genos BV ledger display) reads this snapshot, never
   the closure table. Backfilled from `bv_propagation_log` for history.

2. **Reversal = absorb-then-debt** (`GroupBvReversalService`):
   - Marks `bv_propagation_log.reversed_at` under lock (idempotency). If the
     order was never propagated, it inserts the marker pre-reversed so a
     still-queued `PropagateGroupBvJob` no-ops on the unique constraint.
   - Per snapshotted credit: subtract from the **credit's own day** while that
     day is still unsettled for the ancestor (no standing `gsb_cutoff_results`
     row — missing, FAILED, or REVERSED), so a delayed/failed cut-off can
     never match cancelled BV; once the day is settled, subtract from
     **today's** open accumulator instead. Either way, up to the side's
     balance; the remainder is upserted into **`group_bv_debts`**
     `(distributor_id, side, bv_paise)`.
   - Each step is recorded in **`group_bv_reversals`** for the ledgers/audit.
   - Settled days are never reopened and wallets are never debited (rule 2).

3. **Debt consumption at propagation time**: before crediting an ancestor,
   `GroupBvAccumulatorService` pays down any open debt on that side and
   credits only the remainder (recorded as `debt_consumed_paise` on the new
   credit row). This is what makes the debt reduce **future days' fresh BV**
   — which slabs 2–7 match on — not just a one-day negative.

## Options considered

- **A. Post a negative into `group_bv_daily` on the reversal date.** Rejected:
  slabs 2–7 match each day's *fresh* weaker BV, so a negative on day D never
  reduces day D+n — the debt would leak into slab-1/power CF only. Columns are
  also unsigned by design (ADR-0006 fix-up migration).
- **B. Re-derive the upline via the closure table at reversal time.** Rejected:
  wrong targets after a line change; violates the "originally credited only"
  requirement. The snapshot is cheap (one row per ancestor per order) and also
  makes the ledger display line-change-proof.
- **C. Claw back wallets for already-settled days.** Rejected — contradicts
  KP's explicit "no clawback" rule and ADR-0009's Phase-4 note (wallet
  clawback, if ever, is a separate compensation-engine concern).

## Consequences

- Cancelled/refunded orders now leave **zero net group BV** behind (hard rule
  #2), immediately when unsettled, progressively via same-side debt when
  settled.
- Both Genos BV ledgers (admin + distributor) show reversal rows and an
  "adjustment pending" banner while debt is open; credit rows disclose any
  debt consumption, keeping day arithmetic equal to the accumulator.
- `group_bv_credits` grows by (tree depth) rows per paid order — indexed,
  integers only; acceptable and useful (it now backs the ledger display).
- Known edge: a reversal landing between 00:00 and 00:10 IST debits the
  still-unsettled previous day directly (daySettled check), so it cannot race
  the previous day's cut-off into over-paying.
- Compliance review 2026-07-05 (approve-with-notes) dispositions:
  - **R-32** logged in the risk register: paid GSB retained on
    post-settlement cancellation (KP's explicit no-clawback) is a DSR-spirit
    exposure requiring legal opinion at the batched launch sign-off.
  - Backfilled history re-derives ancestors from the current closure table —
    best-effort for pre-migration orders (a pre-migration line change could
    mis-target); all new credits are exact snapshots.
  - Reversal audit rows carry `actor_id = null` when event-driven; the human
    actor is attributed on the upstream `order.cancelled` /
    `order.refund_approved` audit entries for the same order.
  - Ops note: the reversal job queue shares the propagation queue's 00:10
    cut-off buffer; a backlog past midnight delays reversal to the (still
    correct) unsettled-day debit path.
- Audit hardening 2026-07-05 (post-review fixes):
  - A settled day whose status was `below_600bv` is skipped entirely — the
    cut-off discarded that ancestor's whole day, so creating a debt would
    deduct BV they never had.
  - Lock order unified with the accumulator (`group_bv_debts` →
    `group_bv_daily`) and both outer transactions run with `attempts: 3`, so
    a concurrent propagation/reversal deadlock self-heals instead of burning
    job retries.
  - A propagated order with BV but no credit snapshot (stale pre-deploy
    worker) logs `gsb.bv_reversal.no_credit_snapshot` loudly instead of
    silently reversing nothing.
- Accepted per KP's spec (documented in R-32, needs KP/legal re-confirmation
  at launch sign-off): cancelled BV already folded into slab-1/power
  carry-forward by a settled-but-unpaid day, and monthly rank-qualification
  sums, are NOT retro-adjusted — the CF store is only ever mutated by
  cut-offs (rewind invariant), and KP round-3 #4 says ranks/titles are
  sticky with only future BV reduced. The same-side debt self-corrects the
  economics as new volume arrives.
