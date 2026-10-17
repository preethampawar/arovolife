# Plan: payout batches skip a failing distributor and carry on; failures page with retry

**Status:** draft for review (2026-10-17). **Requested by the user** (2026-10-17): "one distributor's failure should not block the entire batch … skip this one distributor and continue with the other distributor payouts. In the morning or whenever admin retries this distributor after a fix, that payout should work for that distributor for that day. Show a page that lists all the failed distributors with retry option with the actual error log in whole."

Same principle the client chose for the nightly engines (E5, 2026-09-27: skip-and-continue).

## Today (main @ befe9c04)

- `PayoutService::sweepWeeklyBatch()` / `sweepMonthlyBatch()` (`app/app/Modules/Compensation/Services/PayoutService.php` ≈:154 / ≈:300) loop distributors; `processWeeklyDistributor()` (≈:384) / `processMonthlyDistributor()` (≈:567) each run in their own `DB::transaction`, so a throw rolls that distributor back completely — no line, no debit, credits stay unswept. The throw is only logged (`Log::critical`) and counted.
- `finalizeBatchTotals($batch, $failedCount)` (≈:1289) sets `partially_failed` when `$failedCount > 0`. `approve()` (≈:1894) accepts only `pending`, so **nobody** in the batch can be paid. The commands (`GsbWeeklyPayoutCommand`, `MonthlyPayoutCommand`) exit FAILURE; since aa720224 the planners re-own the date and the next Weekly/Monthly Run re-sweeps it, but a distributor that fails every night blocks the batch — and later Tuesdays — indefinitely.
- The error survives only in the log.

## Target behaviour

1. **Skip and continue.** A per-distributor build failure is recorded in a new `payout_build_failures` row (full error) and the batch finalises **`pending`** — approvable for everyone else. The failed distributor's credits stay unswept (already true), so nothing is lost or half-paid.
2. **Systemic guard** (mirrors E5's cap): if failures exceed `max(10, 1% of the distributors the sweep looked at)`, or **10 or more share one exception class**, it is a fault in the run, not in one record → keep today's behaviour (`partially_failed`, command FAILURE, planners re-own the date). Failure rows are still written so the page shows them.
3. **Retry** a failure from the new page (one row or "Retry all open" for a batch):
   - **Batch not yet approved** (`pending`, `approved_at` null) → build that distributor's line into **the same batch** (same earning window → "that payout works for that day"), refresh totals, resolve the failure. If it fails again: attempts+1, error replaced, still open.
   - **Batch already approved** → a signed-off batch is never appended to (standing rule). The retry does not build; the page says: "This batch was approved without them. Their earnings for this week stay in their wallet and are paid by the next weekly batch (dated …)" and the row shows that. When a later batch sweeps that distributor's credits for the window, the failure auto-resolves as `paid_by_later_batch` (with that batch id).
   - **Systemic `partially_failed` batch** → retry works as for a pending batch; when the last open failure resolves and the batch is under the cap, the batch is promoted to `pending`.
   - Retry runs on the `compensation` queue (`RetryPayoutBuildFailureJob`, tries 1), under the sweep lock — never a sweep inside a web request. The page shows *Retrying…* until the job records its outcome.
4. **Approval is the last chance** (like held lines, QA F93): `approveLocked()` first retries the batch's open failures (each in its own try/catch); whoever still fails stays out and the confirm dialog / success message names how many. The approve dialog already shows "₹X to N distributors"; add "K distributor(s) could not be built — retry them first or they are paid next batch".
5. **The page** — Compensation → Payouts → *Build failures* (`admin.compensation.payout-failures.index`), permission `finance.record` (the role that runs batches; retry is making, not checking):
   - Filters: open / resolved / all; batch type; date range; ADN.
   - Columns: batch (type + date, link), ADN + name (link to the distributor's compensation page), first failed, last failed, attempts, exception class, message (first line), status (*Open*, *Retrying*, *Resolved — built into batch #*, *Resolved — paid by batch #*), actions (**Retry**, confirm modal per platform convention).
   - Row expands to the **full error**: exception class, message, file:line, full stack trace and previous exceptions — stored scrubbed (below).
   - Also linked from: the batch page (banner "K distributors could not be built — see Build failures"), the Action Center (new provider `payouts.build_failures`, Money group, CRITICAL, `finance.record`), and the 08:00 engine health digest (count + oldest).
6. **Audit**: `payout.build_failure.recorded` (per row, first time), `payout.build_failure.retry_requested` (actor), `payout.build_failure.resolved` (how), batch `payout.batch.finalised` details gain `skipped_distributor_count`.

## Data

Migration `2026_10_17_140000_create_payout_build_failures_table`:

| column | type |
|---|---|
| id | bigint pk |
| payout_batch_id | fk payout_batches, cascade on delete (un-building a batch removes its failures too — the re-sweep re-records) |
| distributor_id | fk distributors |
| status | string(20): `open`, `retrying`, `resolved` |
| resolution | string(30) null: `built`, `paid_by_later_batch` |
| resolved_line_item_id | fk payout_line_items null |
| resolved_by_batch_id | fk payout_batches null |
| attempts | unsigned int default 1 |
| exception_class | string(255) |
| message | text |
| error_detail | mediumText (full `(string) $e` incl. trace + previous, scrubbed, capped 256 KB) |
| first_failed_at, last_failed_at, resolved_at | datetime(3) |
| retry_requested_by | fk users null, retry_requested_at datetime null |
| timestamps | |

Unique `(payout_batch_id, distributor_id)`; index `(status, payout_batch_id)`.

**Scrubbing:** run the error text through the same rules as `App\Modules\Shared\Logging\PiiScrubberProcessor` (extract its pattern list into a small shared `PiiScrubber::scrub(string): string` if it is not callable directly). PAN, Aadhaar, account numbers, OTPs, tokens never reach the table (CLAUDE.md logging rule).

Add `payout_build_failures` to `DerivedTables` (wiped with payout batches on a recompute; windowed by `payout_batch_id` parent like `payout_line_items`).

## Code changes

- `PayoutService`:
  - in both sweep loops: on throw → `PayoutBuildFailureRecorder::record($batch, $distributorId, $e)`; on success for a distributor with an open row → resolve `built`.
  - `finalizeBatchTotals()`: status = `partially_failed` only when the systemic guard trips; otherwise `pending`. Pass the looked-at count and failures' classes. Audit `skipped_distributor_count`.
  - new `retryBuildFailure(PayoutBuildFailure $failure): PayoutBuildFailure` (under `withSweepLock`): approved → mark message, no build; else process the one distributor, finalise totals, resolve/re-record.
  - `approveLocked()`: retry open failures before `releaseClearedHolds()`.
  - wherever a later batch sweeps a distributor's credits: if that distributor has open failures on an **approved** earlier batch of the same type, resolve them `paid_by_later_batch`.
- Commands: a `pending` batch with skips exits SUCCESS but prints "K distributor(s) skipped — see Build failures" and puts `skipped_distributors` in the engine run summary (digest reads it).
- `EngineStatusService::payoutBatchExists()` / `unfinishedPayoutBatchExists()`: unchanged semantics (pending = built; systemic partially_failed = owed).
- New: model `PayoutBuildFailure`, service `PayoutBuildFailureRecorder`, job `RetryPayoutBuildFailureJob` (queue `compensation`, tries 1, `failed()` writes the error onto the row and sets it back to `open`), controller `AdminPayoutBuildFailureController` (index, retry, retryAllForBatch), views, routes (`can:finance.record`), Action Center provider, digest section, help (`resources/help/payout-operations.md` — replace the "partially failed" paragraph), risk register (amend R-119 or new R-121).

## Tests (Pest, `arovolife_test`)

1. One distributor throws (force via a model event or a stubbed dependency) → batch `pending`, others have lines, failure row with class/message/trace, credits of the failed distributor unswept, command exit 0 with the skipped note.
2. Systemic: 10 throws of one class (or > 1%) → `partially_failed`, command FAILURE, rows written.
3. Retry on a pending batch after the cause is removed → line built in the same batch, totals refreshed, row `resolved/built`, audit rows.
4. Retry still failing → attempts 2, error replaced, `open`.
5. Retry on an approved batch → no line appended, row stays open with the "paid next batch" note; next weekly batch sweeps the credits → row `resolved/paid_by_later_batch`.
6. Approval retries open failures first; a still-failing one is left out; message names it.
7. Error text with a PAN / account number pattern is scrubbed in `error_detail`.
8. Page: lists, filters, full error renders, retry dispatches the job, `finance.record` required (403 for admin-compliance), feature-flag/zero-trace consistent with the payout pages.
9. Un-building a batch removes its failure rows; recompute wipes the table.
10. Monthly batch: tests 1, 3, 5 for `runMonthlyBatch`.

## Rollout

One migration. No data backfill. Staging first; prod with the go-live deploy. No plan-page change (internal operations only; distributors are paid the same amounts, a week later at worst).

## Decisions

- **User decision 2026-10-17:** a distributor still failing when the batch is approved is paid by the **next** batch of the same type — no catch-up batch, no relaxing of the `(batch_type, batch_date)` uniqueness.
- Systemic thresholds: reuse E5's `max(10, 1%)` and "≥10 same class".
- **User decision 2026-10-17 (monthly):** the same "next batch" rule applies to monthly batches, so a monthly distributor still failing at approval waits for the next month's batch. The approve dialog must say so explicitly for a monthly batch ("K distributor(s) could not be built — if you approve now they are paid by the next monthly batch, a month later. Retry them on Build failures first.").
