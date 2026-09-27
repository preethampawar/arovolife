# Payout fail-safety — Batch 1 of the 2026-09-26 code review

Status: approved for implementation 2026-09-27. Planned on Fable; implement on Opus.
Branch: `fix/payout-fail-safety` off `main` (`033a963e` or later). One commit per step, in order.
All paths below are relative to `app/` (the Laravel root) unless they start with `docs/`.

## What this fixes

Four ways a RazorpayX payout can leave the company twice or never leave at all:

| # | Problem | Where |
|---|---|---|
| P1 | A retry sends a **second** bank transfer when the first attempt's response was lost. `RetryRazorpayPayoutJob` bumps `retry_count` first (`Jobs/RetryRazorpayPayoutJob.php:91`), the idempotency key is derived from it (`Services/RazorpayPayoutGateway.php:81`, used at `:248`), and an existing payout is looked up only after a "duplicate" error (`:259`). The retry job's 120 s timeout (`:33`) is shorter than the worst-case gateway time (3 calls × 20 s × 3 transport retries + 14 s backoff ≈ 220 s), so the job can be killed after Razorpay accepted. | dispatch service, gateway, retry job |
| P2 | The batch send job does every line in one 300 s job with tries 1 and no `failed()` (`Jobs/DispatchRazorpayPayoutsJob.php:30-32,70-87`). Killed mid-way, the rest stay `pending` with no payout id; nothing re-sends them (auto-retry and "Send again" take `failed` only; no Action Center item). | dispatch job |
| P3 | A weekly/monthly batch whose sweep threw is marked `failed` (`Console/Commands/GsbWeeklyPayoutCommand.php:77-90`) and is then never owed again: the planners only ask whether a batch row exists (`Support/WeeklyRunPlanner.php:104,129`, `Support/MonthlyRunPlanner.php:60-61,191,199` → `Services/EngineStatusService.php:682`). | planners |
| P4 | A payout whose webhook never arrives stays `pending` with an id for ever; the batch stays `dispatched`. The only remedy is the per-line "Check with Razorpay" button (`Services/PayoutLineSettlementService.php:221`). | new command + Action Center |

Facts to keep in mind while implementing:

- `compensation` queue: database driver, exactly one worker, every job `tries = 1` (ADR-0011). Never add automatic Laravel retries to a job that moves money.
- `RazorpayPayoutDispatchService::dispatch()` (`Services/RazorpayPayoutDispatchService.php:47`) re-reads the line, refuses any status other than `pending`/`failed`, and returns early when a payout id is already set. Every path below goes through it; do not add a second sender.
- The gateway's `retry(3, …)` at `RazorpayPayoutGateway.php:590` replays a `ConnectionException` with the **same** idempotency key. That covers a dropped connection; it does not cover a killed process.
- Tests run only with the isolated DB:
  `docker exec -e DB_CONNECTION=mysql -e DB_DATABASE=arovolife_test -e DB_HOST=db -e DB_PORT=3306 -e DB_USERNAME=arovolife -e DB_PASSWORD=secret arovolife-app php artisan test --compact <path>`
  Never a bare `php artisan test`.
- Pint: run on explicit paths (`--dirty` fails in the container). Larastan level 7 on every touched file.
- Money is in scope, so each commit carries `Compliance-Review: compliance-officer` after running the `compliance-officer` agent on the diff (once, at the end, before the commits are pushed is acceptable — the user is a solo developer).

## Step 1 — P1: ask Razorpay before every send

**Gateway** — `Services/RazorpayPayoutGateway.php`

Add a public method next to `createPayout()`:

```php
/**
 * The payout Razorpay already holds for this line, if any — asked BEFORE
 * every create so a lost response can never become a second transfer.
 *
 * @return array{id: string, status: string, utr: string|null}|null
 */
public function findExistingPayout(PayoutLineItem $line): ?array
```

It calls the existing private `findPayoutByReference(self::referenceFor((int) $line->id))` and normalises the result exactly as `createPayout()` does (`id`, lowercased `status`, `utr` or null). Returns null when nothing is found. A transport or gateway error propagates (the caller decides).

**Dispatch service** — `Services/RazorpayPayoutDispatchService.php`, inside the `try` at `:84-88`, between `ensureFundAccount` and `createPayout`:

```php
$existing = $this->gateway->findExistingPayout($line);
$payout = $existing !== null && ! in_array(strtolower($existing['status']), self::FAILED_STATES, true)
    ? $existing                                   // live or settled: adopt it, never create
    : $this->gateway->createPayout($line, $distributor, $fundAccountId, $attempt);
```

Rules:
- A found payout in a terminal failed state (`rejected`, `cancelled`, `reversed`, `failed`) is dead: create a new one. That is the legitimate retry.
- A lookup that throws is handled by the existing `catch (Throwable $e)` → the line is held `failed` with cause `gateway_error`. Not knowing means not sending. Add a distinct cause: catch `PayoutGatewayException` from the lookup separately and use cause `gateway_lookup_failed`, reason "Razorpay could not confirm whether this transfer already exists; nothing was sent." (keep the existing `Log::critical`).
- Add `'adopted_existing' => $existing !== null` to the audit `details` at `:144-156`.

**Retry job** — `Jobs/RetryRazorpayPayoutJob.php`
- `$timeout` 120 → 300.
- Update the class docblock's last paragraph: the bump-first rule still holds, and the lookup in the dispatch service is what makes a killed attempt safe.

**Tests** — new `tests/Modules/Compensation/PayoutDispatchIdempotencyTest.php` (Pest, `RefreshDatabase`, `Http::fake` + `Http::preventStrayRequests()`; the gateway uses `Http::baseUrl()` so fakes on `api.razorpay.com/v1/*` work — see `tests/Modules/Payments/RazorpayClientTest.php` for the pattern; build a line item the way `tests/Feature/Compensation/PayoutGatewayGuardsTest.php:276` does; set the gateway to Razorpay through `PayoutGatewaySettings` and put the RazorpayX config keys in `config('arovolife.payments.razorpay_payouts')`; give the distributor a contact id and a fund-account id already cached so only `/payouts` calls are made):

1. `PD-01` a retry of a `failed` line finds a live payout by reference and adopts it — `/payouts?reference_id=AROVOPAY-{id}` returns one `processing` item; assert **no** `POST /payouts` was sent, the line has that id and status `pending`, and the audit row says `adopted_existing: true`.
2. `PD-02` a retry whose reference lookup finds a `reversed` payout creates a new one — assert exactly one `POST /payouts`.
3. `PD-03` a first dispatch (`retry_count = 0`) with nothing at Razorpay posts once, and the `X-Payout-Idempotency` header equals `RazorpayPayoutGateway::idempotencyKey($line->id, 0)`.
4. `PD-04` a lookup that fails (`Http::sequence()->pushStatus(502)` ×4, or a `ConnectionException`) leaves the line `failed` with cause `gateway_lookup_failed` and sends nothing.
5. `PD-05` the retry job's timeout is at least 300 (`expect((new RetryRazorpayPayoutJob(1))->timeout)->toBeGreaterThanOrEqual(300)`).

Run also: `tests/Feature/Compensation/PayoutGatewayGuardsTest.php`, `tests/Modules/Compensation/PayoutServiceTest.php`.

Commit: `fix(payouts): look a transfer up at Razorpay before every send so a retry can never pay twice`

## Step 2 — P2: one job per line, and an interrupted job leaves a retryable line

**New job** — `Jobs/DispatchRazorpayPayoutLineJob.php`
- `final`, `ShouldQueue`, `Queueable`, `InteractsWithQueue`; `$tries = 1`, `$timeout = 300`; constructor `(int $lineItemId, ?int $actorId = null)`, `onQueue('compensation')`.
- `handle(RazorpayPayoutDispatchService $dispatcher, PayoutGatewaySettings $settings)`: same two guards as the batch job (gateway still Razorpay; batch still `dispatched`), then `$dispatcher->dispatch($line, $this->actorId, RazorpayPayoutDispatchService::AUDIT_DISPATCHED)`, then `$dispatcher->refreshBatchStatus($batch)`.
- `failed(?Throwable $e)`: re-read the line; if it is still `pending` with no payout id, hold it — write `status = failed`, `failure_reason = 'The transfer job was interrupted before Razorpay answered. The next send checks Razorpay for this transfer first.'`, and an audit row `payout.line_item.dispatch_failed` with cause `job_interrupted` (mirror `RazorpayPayoutDispatchService::hold()`; make `hold()` public or add a public `holdInterrupted(PayoutLineItem $line, ?int $actorId)` on the service so the job does not duplicate the audit shape). Then `refreshBatchStatus`. `Log::critical('RazorpayX payout line job interrupted', [...])`.
- Because Step 1 looks up by reference before every send, a line marked `failed` this way is safe for the 11:00 auto-retry and for "Send again".

**Batch job** — `Jobs/DispatchRazorpayPayoutsJob.php` becomes a fan-out:
- Keep the two guards and the line query (`:70-75`), but iterate with `->chunkById(500)` and `DispatchRazorpayPayoutLineJob::dispatch((int) $line->id, $this->actorId)` per line.
- Audit `payout.batch.dispatched` with `line_items_queued` instead of `sent`/`failed`.
- Drop the final `refreshBatchStatus` (per-line jobs do it). Keep `$timeout = 300`, `$tries = 1`.
- Update the class docblock: the batch job queues; the line job sends; recovery is `payouts:reconcile` (Step 4).

Nothing else calls the batch job (`Services/PayoutService.php:1972` only).

**Tests** — `tests/Modules/Compensation/PayoutDispatchFanOutTest.php`:
1. `PF-01` `Queue::fake()`: the batch job queues one `DispatchRazorpayPayoutLineJob` per payable pending line and none for lines that are `failed`, `transferred`, held, or have a payout id.
2. `PF-02` the line job sends through the dispatch service and settles its batch when it was the last line (reuse the Http fake from Step 1).
3. `PF-03` `failed()` on a still-pending line with no id marks it `failed` with cause `job_interrupted`, and leaves a line that already has an id untouched.
4. `PF-04` the line job does nothing when the batch is no longer `dispatched`.

Run also: `tests/Feature/Compensation/PayoutManualSettlementTest.php`, `tests/Modules/Compensation/PayoutServiceTest.php`.

Commit: `fix(payouts): send each line in its own job so a killed batch job strands nothing`

## Step 3 — P3: a failed sweep is still owed

**`Services/EngineStatusService.php:682`** — `payoutBatchExists()` must answer "was this batch built?". A row with `status = failed` **and** `approved_at IS NULL` is a sweep that threw before anyone could act on it: treat it as not existing.

```php
->where(function ($q): void {
    $q->where('status', '!=', PayoutBatch::STATUS_FAILED)
      ->orWhereNotNull('approved_at');
})
```

Update the docblock at `:676-681`. A batch that was approved and then had every line fail keeps `approved_at`, so it still counts as built — the planners must not rebuild an approved batch.

Verify (read, then pin with a test) that `PayoutService::runWeeklyBatch()` (`:163-185`) and the monthly equivalent re-enter a `failed`/unapproved batch cleanly: existing line items are kept (existence guard), the batch goes back through `processing` to `pending`, and `earnings_through` is unchanged. `failed` is not in `CLOSED_BATCH_STATUSES` (`:70`). If the re-entry does not reset the status, fix that inside `runWeeklyBatch()`/`runMonthlyBatch()` rather than in the planner.

Callers (all keep their meaning): `WeeklyRunPlanner.php:104,129`, `MonthlyRunPlanner.php:60,61,191,199`, `Services/Rebuild/NightRebuilder.php:303`.

**Tests**
- `tests/Modules/Compensation/WeeklyRunPlannerTest.php` — add: `owes a Tuesday whose sweep failed before approval` (batch row `failed`, `approved_at` null → in `owedTuesdays`) and `does not re-owe an approved batch whose every line failed` (`failed`, `approved_at` set → not owed).
- `tests/Modules/Compensation/MonthlyRunPlannerTest.php` — the same pair for the monthly batch.
- `tests/Modules/Compensation/PayoutCommandFailureTest.php` — extend: after a sweep that throws, the next `compensation:weekly-run` builds the same Tuesday and the batch ends `pending` with its lines.

Commit: `fix(payouts): a batch whose sweep failed is owed again by the next run`

## Step 4 — P4: reconcile payouts in flight, and show them

**New command** — `Console/Commands/PayoutsReconcileCommand.php`, signature
`payouts:reconcile {--hours=6 : Only lines dispatched or queued longer ago than this} {--limit=500} {--dry-run}`

Two selections, both skipped with a message when the gateway is not Razorpay or not ready (copy the guards from `AutoRetryFailedPayoutsCommand.php:35-46`):

A. **Waiting on the bank too long** — `payout_line_items` where `status = pending`, `razorpay_payout_id` not null, `dispatched_at < now − hours`. For each: `PayoutLineSettlementService::checkWithRazorpay($line, actorId: null)` — make `actorId` nullable on that method and on the `transition()` helper's audit row (`actor_id` null = the system asked, like the auto-retry sweep). It already applies `processed` → `transferred`, failed states → `failed`, and leaves in-flight states alone. Catch `PayoutLineActionRefused` per line, count it, continue.

B. **Never sent** — `payout_line_items` where `status = pending`, `razorpay_payout_id` null, `net_transferred_paise > 0`, joined to `payout_batches` with `status = dispatched` and `approved_at < now − hours`. For each: `DispatchRazorpayPayoutLineJob::dispatch($id, null)`. The dispatch service's fresh-read guard makes a duplicate queue entry harmless on the single worker.

Print one summary line: `Checked N with Razorpay (T transferred, F failed, I still in flight, E unreachable); re-queued M unsent line(s).` Exit 0 unless the gateway is unreachable for every line in A (then 1, so the failure shows in the scheduler log).

Register the command in `app/Providers/AppServiceProvider.php` next to `AutoRetryFailedPayoutsCommand::class` (`:205`) — unregistered commands fail silently at cron.

**Schedule** — `routes/console.php`, after the auto-retry block (`:178-183`):

```php
Schedule::command(PayoutsReconcileCommand::class)
    ->twiceDailyAt(9, 16, 30)
    ->timezone('Asia/Kolkata')
    ->withoutOverlapping()
    ->when($compensationEnginesMayRun)
    ->runInBackground();
```
with a comment in the same voice as the auto-retry one: the webhook is the primary confirmation; this is the backstop for one that never arrives, and for lines a killed job never sent.

**Action Center** — two providers in `app/Modules/ActionCenter/Providers/Money/`, shaped like `PayoutBatchPartiallyFailedProvider.php`, registered in `ActionCenterServiceProvider.php` beside it (`:92`):

1. `PayoutsAwaitingBankConfirmationProvider` — key `payouts.awaiting_bank_confirmation`, group `MONEY`, permission `finance.record`, severity `WARNING` (check `Support/Severity.php` for the exact constant), subject `payout_line_item`. Query: selection A with a 24-hour threshold. Title `ADN {adn} — ₹{net}`; subtitle `Sent to Razorpay {age} ago, no confirmation yet`; URL: the batch show route for the line's batch type (copy `routeFor()`).
2. `PayoutsUnsentInDispatchedBatchProvider` — key `payouts.unsent_in_dispatched_batch`, severity `CRITICAL`. Query: selection B with a 1-hour threshold. Subtitle `Batch approved {age} ago, this line was never sent — payouts:reconcile re-queues it at 09:30 and 16:30`.

**Help doc** — `resources/help/payout-operations.md`: add a short section "Transfers waiting on the bank" naming the two Action Center items, the twice-daily reconcile, and that "Check with Razorpay" on a line does the same thing on demand.

**Tests** — `tests/Modules/Compensation/PayoutsReconcileCommandTest.php`:
1. `PR-01` a pending line with an id dispatched 7 h ago is checked; Razorpay says `processed` → line `transferred` with the UTR, batch settles.
2. `PR-02` a pending line with an id dispatched 1 h ago is left alone (under `--hours`).
3. `PR-03` a pending line with no id in a `dispatched` batch approved 7 h ago is re-queued (`Queue::fake`, assert `DispatchRazorpayPayoutLineJob` pushed once); the same line in an `approved` (not yet dispatched) batch is not.
4. `PR-04` `--dry-run` changes nothing and queues nothing.
5. `PR-05` manual NEFT mode: exits 0, touches nothing.
6. `tests/Feature/ActionCenter/…` — one test per provider: count and item shape, and that a line under the threshold is not listed (follow the existing provider tests in that folder).

Commit: `feat(payouts): reconcile transfers still with Razorpay and re-queue lines a killed job never sent`

## After the four commits

1. Pint on every touched path; Larastan on every touched PHP file.
2. Full compensation and payment suites:
   `tests/Modules/Compensation tests/Feature/Compensation tests/Modules/Payments tests/Feature/ActionCenter`.
3. `compliance-officer` agent over `git diff main...HEAD`; add `Compliance-Review: compliance-officer` to each commit message if not already present (amend before push — the branch is local).
4. Confirm with the user before pushing (solo-dev rule). Merge to `main` locally, push.
5. Deploy staging then production with the standard recipe (Cloudways `git_pull`, then `app:deploy --maintenance --health-url=…`). New scheduled command and new job classes → the deploy's `queue:restart` covers the worker; verify `app:status` shows the scheduler ticking and the compensation worker running.
6. On staging, run `php artisan payouts:reconcile --dry-run` once and paste the summary line in the hand-back.

## Verification after deploy (required before Batch 2 is planned)

Do all of this on staging, then the read-only parts on production, and report each line with its actual output.

1. `php artisan schedule:list` shows `payouts:reconcile` twice daily and `payouts:auto-retry` (or whatever `AutoRetryFailedPayoutsCommand`'s signature is) at 11:00, both `Asia/Kolkata`.
2. `php artisan payouts:reconcile --dry-run` runs clean and prints the summary line.
3. `php artisan app:status` (or the deploy's status table): scheduler ticking, compensation worker 1 process, no failed jobs since the deploy.
4. Staging end to end, with the payout gateway set to Razorpay and RazorpayX test credentials (if staging has none, say so and do the manual-NEFT path instead):
   - build a weekly batch for a past Tuesday on staging (`gsb:weekly-payout --date=…` or the admin trigger), approve it, and watch `jobs` for one `DispatchRazorpayPayoutLineJob` per payable line, then the lines moving to `pending`+id or `failed` with a reason;
   - kill the worker mid-batch once (`kill -9` the compensation `queue:work` process while lines are in flight), restart it, and confirm: no line was sent twice (one `payouts.create` gateway event per line in `payout_gateway_events`), the interrupted line is `failed` with cause `job_interrupted`, and the 11:00 sweep (run it by hand: `php artisan payouts:auto-retry`) adopts or resends it without a second transfer;
   - on one `failed` line, press "Send again" and confirm the reference lookup happened before the create (two gateway event rows: `payouts.fetch_by_reference` then `payouts.create`, or only the fetch when adopted);
   - force one batch row to `status = failed, approved_at = NULL` and confirm the next `compensation:weekly-run --date=<that Tuesday>` rebuilds it;
   - open Admin → Action Center and confirm both new Money items list what the DB says they should.
5. Next morning: the 08:00 engine health digest arrives and reports nothing new as stuck.
6. Production, read-only: `schedule:list`, `payouts:reconcile --dry-run`, `app:status`. Do not build or approve a batch on production.

Write the results into `docs/testing/payout-fail-safety-verification-2026-09.md` (a short table: check, environment, result). Only when every line is green, hand back with "Batch 1 verified" so Batch 2 planning can start.

## Out of scope here (later batches)

Batch 2 engines (E1–E4), Batch 3 access (S1–S3), Batch 4 one-liners (M1, M3, M4). Client decisions pending: E5, M2, M5.
