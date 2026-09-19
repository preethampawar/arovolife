# The nightly chain's first-month hole — Implementation Plan

**Superseded 2026-09-18 by `2026-09-18-engine-cadence-split.md`** — D1/D2 carried
over unchanged; D3 ("every deferral is an alert and a `skipped` run, never a
failed night") is replaced by the three-cadence design (ADR-0016), where the
Weekly and Monthly runs are separate orchestrators from the Nightly Run rather
than later steps of one chain. Kept for history; superseded, not deleted.

*Written 2026-09-18. Diagnosed from a failure standing on staging since 8 September.*

## Goal

When this is done, the nightly compensation chain stops failing over months
that owe nothing and months that could not have been closed, and the
information that a payout is being deferred reaches the same durable place as
every other thing the chain cannot do — instead of an `ERROR` line repeated
every night that nobody can clear.

Three concrete outcomes:

1. A crediting month in which the platform recorded **no product sales** no
   longer blocks its payout batch. Hard rule 2 makes a commission without a
   product sale impossible, so such a month can hold no credits, and the gate
   that exists to stop an incomplete month being paid has nothing to protect.
2. A month is owed daily cut-offs only for the days **the platform had any
   distributor**. A platform that launches on the 20th can close its launch
   month.
3. A payout the chain defers is recorded as a `NightlyRunAlert`, surfaced in
   Engine Health and the dashboard's Engine panel, and does **not** fail the
   night.

## Non-goals

- **No change to what a payout batch pays, or to who may approve it.** The
  sweep, the holds, maker-checker and the income cap are untouched.
- **No relaxation of the cut-off requirement for days the platform was
  trading.** A month that missed 13 days on which distributors existed is still
  refused — that refusal is correct and stays a human decision (`--force`).
- **No automatic backfill of a monthly crediting close.** Crediting on the 1st
  and payment on the 8th exist so that a week separates them; closing a month
  automatically on the 8th would collapse that week to nothing.
- **No change to the hand-typed commands.** `compensation:monthly-payout-close`
  and `payout:monthly-run` keep their own gate and their own exit codes.
- Not fixing staging's September (see *Rollout*): that month is genuinely
  incomplete and is an operator decision, not a bug.

## What is actually broken

**The evidence, from staging on 2026-09-18:**

```
[2026-09-18 00:05:04] staging.ERROR: compensation.monthly_payout_close.refused
    {"month":"2026-08","reason":"never_succeeded","engine_key":"rank.check", …}
[2026-09-18 00:05:04] staging.ERROR: compensation.nightly_run.aborted
    {"date":"2026-09-18","stage":"compensation.monthly-payout-close", …}
```

`engine_runs` on staging starts at **id 1, dated 2026-09-14** — the chain was
deployed on the 15th. August's crediting close therefore never ran,
`rank_qualifications` holds 0 rows, and `MonthlyEngineCompletionGate` refuses to
pay a month whose crediting never happened. It is right to refuse. What is wrong
is everything that follows from it:

- `NightlyRunCommand::monthlyPayoutMonths()` (line 452) asks for the previous
  month's payout on **every night from the 8th** while no batch exists for the
  current month. August will be refused every one of those nights.
- The refusal returns `FAILURE`, so the chain aborts the night
  (`NightlyRunCommand::handle()`, line 155) and records `compensation.nightly-run`
  as `failed` — although the payout close itself correctly recorded its own run
  as `skipped`, "a decision, not a breakage"
  (`MonthlyPayoutCloseCommand::refuse()`, line 126).
- Nothing an operator has can clear it. The Engine Runs retry button passes
  `--weekly-payouts-only`, which excludes the payout close by design — so the
  banner clears while the cause stands. That is exactly what happened at
  00:13 on 18 September (run 14, `manual`, actor 242).

**Staging's August owes nothing at all:** 0 orders, 0 BV ledger entries. The
first order on the platform is dated 2026-09-14.

**The same shape will hit production at launch.** A platform that opens on the
20th has no cut-offs for the 1st–19th, so `monthIsWhole()` (line 587) refuses to
close the launch month, and from the 8th of the following month every night
fails on a month that can never be completed. This is the
**backfill-anchor class** again — the *first* period is the one the logic cannot
reach.

## Architecture decisions

### D1 — The gate waves through a month with no product sales, rather than the chain skipping the batch

**Decision.** `MonthlyEngineCompletionGate::blockingFailure()` returns `null`
for a crediting month with no BV ledger entries. The payout close then runs
normally and builds its batch.

**Alternative rejected: skip the payout batch for such a month.** This looked
equivalent and is not. `PayoutService::sweepMonthlyBatch()` selects
`earnedForMonthOrBefore($month)` (line 308) — a credit earned in an earlier
month that was **held** (KYC pending, no bank account, below 3,000 BV) is swept
by a later batch once the hold clears. Skipping a month's batch would therefore
delay a released hold by a month. The batch must always run; only the refusal
is wrong.

**Why no sales is the right test, and not "no credits".** A month where every
engine crashed also has no credits, and that month must still be refused. The
cause — no product sales — is the only signal that distinguishes "nothing was
owed" from "everything broke". Hard rule 2 (`DSR 2021 Rule 5(1)(c)`) makes the
inference exact: no product sale, no credit, nothing to strand.

**Belt.** If a month somehow holds a wallet credit stamped with that
`bonus_month` while having no BV, something upstream is wrong and the gate must
keep guarding. Both conditions are checked.

### D1b — The wave-through unblocks the batch that was due; it never manufactures one

**Decision.** The chain's **main** branch (the payment due from the 8th) builds
its batch whether or not the crediting month had sales. The **lookback** branch —
"the previous batch was never built, build it tonight" — additionally requires
that the month was one the platform traded in.

**Why the asymmetry is not arbitrary.** The main branch is a payment run that is
due; it must happen on time, because it is what sweeps a credit from an earlier
month whose hold has since cleared (`earnedForMonthOrBefore()`). The lookback
branch only catches up a batch nobody built, and on any night from the 8th the
main branch's own batch is later-dated and sweeps everything the lookback's
would have. So bounding the lookback costs nothing.

**What it prevents.** Without it, a platform that launches in October would, on
its first nights, build a payout batch dated September for an August crediting
month — an empty `pending` batch for a month in which the company did not exist,
sitting in front of finance. On staging it would build an empty August-dated
batch for July 2026. Neither is money; both are noise in the one place noise is
expensive.

### D2 — A month is owed cut-offs only from the platform's first distributor

**Decision.** `monthIsWhole()` and `MonthlyCloseCommand::preflight()` count the
days owed from `max(month start, the first distributor's effective date)`.

**Why that anchor.** A day with no distributors has nobody to hold BV, nobody to
match, no carry-forward to advance and no credit to compute. It is provably
vacuous. Any later anchor would be a real relaxation: a day on which
distributors existed but bought nothing still belongs in the month's record, and
a run of such days is also exactly what a broken order feed looks like.

**Alternatives rejected.** Anchoring on the first cut-off is circular (it is the
thing being measured). Anchoring on the first BV entry would have waved through
staging's September, which is genuinely missing 13 days on which 317
distributors existed — the system is right to refuse that, and a human should
decide it with `--force`.

### D3 — A deferred payout is an alert, not a failed night

**Decision.** `NightlyRunCommand::monthlyPayoutMonths()` consults the gate
*before* adding the step — as its own lookback branch already does (line 440) —
and records a `NightlyRunAlert` for a month it will not ask for. The chain does
not invoke a payout close it knows will refuse, so the night no longer fails.

**Why this is the smallest correct shape.** It needs no new exit code, no
inspection of the child command's run row, and **no change to
`MonthlyPayoutCloseCommand` at all** — the command keeps its gate, its refusal,
its `skipped` run row and its `FAILURE` exit for anyone who types it. The blast
radius on the money path is one method in the chain.

**Why the night must stop failing.** The chain aborts at the first non-zero
step, so a permanently-refused month would also stop any step added after it.
The alarm is not lost: the blocking engine is already a `failed` run in its own
right, the refusal is already an `engine_runs` row and an audit row, and
`NightlyRunAlert` is the platform's established home for "nothing failed, and
yet a month of commission has not been computed" — its own docblock says
*refusing is correct; refusing silently is not*.

**Alternative offered to review: fail the first night per month, then warn.**
Keeps one loud failure and drops the repetition. Rejected as the recommendation
because a night that fails once and succeeds afterwards while the same condition
holds is harder to read than a standing alert, and because the retry button
still cannot clear it.

## Permission matrix

No new route, no new UI control, no new capability. The surfaces the new alert
reaches are the ones that already carry `chainAlerts`:

| Capability | admin-operations | admin-finance | admin-compliance | admin / developer |
|---|---|---|---|---|
| See the deferred-payout alert on Engine Health (`finance.record`) | ✗ | ✓ | ✗ | ✓ |
| See it on the dashboard Engine panel (`finance.record`) | ✗ | ✓ | ✗ | ✓ |
| Receive it in the engine health digest | — | — | — | digest recipient setting |
| Clear it (run the crediting close, or `--force`) | ✗ | ✗ | ✗ | CLI only, as today |

## File changes

| # | Path | New/Modified | Change summary |
|---|---|---|---|
| 1 | `app/app/Modules/Compensation/Support/MonthlyEngineCompletionGate.php` | Modified | Early return for a month with no product sales; new `monthOwedNoCrediting()` |
| 2 | `app/app/Modules/Compensation/Support/PlatformStart.php` | **New** | `firstDay()` — the earliest date the platform had a distributor |
| 3 | `app/app/Modules/Compensation/Console/Commands/NightlyRunCommand.php` | Modified | `monthIsWhole()` counts from the platform's first day; `monthlyPayoutMonths()` consults the gate and alerts |
| 4 | `app/app/Modules/Compensation/Console/Commands/MonthlyCloseCommand.php` | Modified | `preflight()` counts owed days from the platform's first day |
| 5 | `app/app/Modules/Compensation/Support/NightlyRunAlert.php` | Modified | New `ACTION_PAYOUT_DEFERRED` + `payoutDeferred()`; `record()` gains a dedupe key |
| 6 | `app/app/Modules/Compensation/Services/EngineHealthService.php` | Modified | Headline and triage steps for the new action |
| 7 | `app/tests/Modules/Compensation/MonthlyPayoutCloseCommandTest.php` | Modified | Gate: sales-free month pays; month with sales still refuses |
| 8 | `app/tests/Modules/Compensation/NightlyRunCommandTest.php` | Modified | Chain: no failed night, alert written, launch month closes |
| 9 | `app/tests/Modules/Compensation/MonthlyCloseCommandTest.php` | Modified | Preflight: launch month is closable, mid-month gap still refused |
| 10 | `app/tests/Modules/Compensation/EngineHealthDigestTest.php` | Modified | The new alert reaches the digest |
| 11 | `docs/runbooks/engine-failure-triage.md` | Modified | New §7a: a payout the chain deferred |
| 12 | `app/resources/help/payout-operations.md` | Modified | What a deferred monthly payout means to an admin |
| 13 | `docs/compliance/risk-register.md` | Modified | R-101 — the launch month, and what the gate now waves through |

---

### 1. `MonthlyEngineCompletionGate.php`

Add to the imports: `App\Modules\Commerce\Models\BvLedgerEntry`,
`App\Modules\Compensation\Models\WalletLedgerEntry`.

At the top of `blockingFailure()`, immediately after `$monthStart` is computed
and **before** `runsForMonth()`:

```php
        // A month in which nothing was sold owes no crediting, so there is
        // nothing for this gate to protect. Hard rule 2 makes that exact: no
        // credit may exist without a product sale, so a month with no BV can
        // hold no commission, and no engine's absence can be stranding one.
        //
        // This is the platform's pre-trading months — and, on staging, every
        // month before the first order on 14 Sep 2026. Without it the chain
        // asks for a payout for such a month every night from the 8th, is
        // refused every night, and no operator action can ever clear it.
        if (self::owedNoCrediting($monthStart)) {
            return null;
        }
```

New public static, placed beside `featureFlagIsOff()`:

```php
    /**
     * Could this month have produced a commission at all?
     *
     * The product-sale question, not the "are there credits" question: a month
     * in which every engine crashed also has no credits, and that month must
     * still be refused. Sales are the cause; credits are an effect shared by
     * both cases.
     *
     * Public because the chain asks it directly too — see D1b: it bounds the
     * lookback branch, which must not manufacture a batch for a month the
     * platform never traded in.
     */
    public static function owedNoCrediting(Carbon $month): bool
    {
        $monthStart = $month->copy()->startOfMonth();

        $hasSales = BvLedgerEntry::query()
            ->dateRange($monthStart, $monthStart->copy()->endOfMonth()->endOfDay())
            ->exists();

        if ($hasSales) {
            return false;
        }

        // Belt. A credit stamped to a month with no sales should be impossible
        // (hard rule 2, CommissionHasProductSaleTest). If one exists anyway,
        // something upstream is wrong and this is precisely the month the gate
        // must keep guarding.
        return ! WalletLedgerEntry::query()
            ->whereDate('bonus_month', $monthStart->toDateString())
            ->exists();
    }
```

Extend the class docblock's list of outcomes with a fourth bullet:

```
 *   • NO PRODUCT SALES in the month — nothing could have been credited, so no
 *     engine's absence can be stranding a credit. It does NOT block.
```

### 2. `PlatformStart.php` (new)

```php
<?php

declare(strict_types=1);

namespace App\Modules\Compensation\Support;

use App\Modules\Identity\Models\Distributor;
use Illuminate\Support\Carbon;

/**
 * The first day the platform had anybody in it.
 *
 * Days before it are not owed a daily cut-off: with no distributor there is
 * nobody to hold BV, no group to match, no carry-forward to advance and no
 * credit to compute. That is what lets a platform close the month it launched
 * in — otherwise the launch month is short by however many days preceded the
 * launch, every monthly engine is refused for it, and from the 8th of the next
 * month the nightly chain fails on a month that can never be completed.
 *
 * Deliberately NOT "the first day with a sale". A day on which distributors
 * existed and bought nothing is still a day the engines owe a cut-off for, and
 * a stretch of such days is also what a broken order feed looks like.
 */
final class PlatformStart
{
    public static function firstDay(): ?Carbon
    {
        $earliest = Distributor::query()->min('effective_date');

        return $earliest === null ? null : Carbon::parse($earliest)->startOfDay();
    }

    /**
     * The first day of $month that is owed a cut-off — the month's own start,
     * unless the platform began part-way through it.
     */
    public static function owedFrom(Carbon $monthStart): Carbon
    {
        $first = self::firstDay();

        return $first !== null && $first->greaterThan($monthStart)
            ? $first
            : $monthStart->copy();
    }
}
```

> **Not cached.** `distributors.effective_date` carries no index today, so this
> is a scan; it runs at most twice a night. See *Follow-ups* for the additive
> index, which the dashboard's "Joined this month" tile also wants.

### 3. `NightlyRunCommand.php`

**(a) `monthIsWhole()`** — replace the coverage arithmetic (lines 589–599):

```php
        $monthStart = $lastDay->copy()->startOfMonth();
        $owedFrom = PlatformStart::owedFrom($monthStart);

        // Days before the platform had a distributor are not owed a cut-off,
        // which is what makes the launch month closable.
        $owedDays = $lastDay->day - $owedFrom->day + 1;

        if ($owedDays <= 0) {
            return true;
        }

        $covered = $this->status->completedCutoffDatesBetween($owedFrom, $lastDay);

        foreach ($steps as [$key, $period]) {
            if ($key === 'gsb.daily-cutoff' && $period->betweenIncluded($owedFrom, $lastDay)) {
                $covered[] = $period->toDateString();
            }
        }

        $missing = $owedDays - count(array_unique($covered));
```

The deferral message that follows keeps its wording; `$missing` and `$lastDay->day`
in it become `$missing` and `$owedDays`.

**(b) `monthlyPayoutMonths()`** — both branches consult the gate and say so:

```php
    private function monthlyPayoutMonths(Carbon $night): array
    {
        $months = [];
        $thisBatchMonth = $night->copy()->startOfMonth();
        $lastBatchMonth = $thisBatchMonth->copy()->subMonthNoOverflow();

        if (! $this->status->payoutBatchExists(PayoutBatch::TYPE_MONTHLY, $lastBatchMonth)) {
            $olderCrediting = $lastBatchMonth->copy()->subMonthNoOverflow();

            // D1b: catch up a missed batch, never invent one. A month the
            // platform did not trade in owes no batch of its own, and any older
            // held credit it would have swept is swept by the batch the main
            // branch builds below on the same night.
            if (! MonthlyEngineCompletionGate::owedNoCrediting($olderCrediting)
                && $this->mayPayOut($night, $olderCrediting)) {
                $this->warn(sprintf(
                    "The %s payout batch was never built. Building it tonight rather than leaving %s's credits to "
                    .'next month.',
                    $lastBatchMonth->format('F Y'),
                    $olderCrediting->format('F Y'),
                ));

                $months[] = $olderCrediting;
            }
        }

        if ($night->day >= 8
            && ! $this->status->payoutBatchExists(PayoutBatch::TYPE_MONTHLY, $thisBatchMonth)
            && $this->mayPayOut($night, $thisBatchMonth->copy()->subMonthNoOverflow())) {
            $months[] = $thisBatchMonth->copy()->subMonthNoOverflow();
        }

        return $months;
    }

    /**
     * Ask the payout gate here rather than letting the step ask it and exit 1.
     *
     * A month whose crediting is incomplete is refused however it is reached,
     * and that refusal is correct — but it is a standing condition, not
     * tonight's breakage, and re-running the chain cannot clear it. Invoking
     * the close anyway would abort the night, every night, on something no
     * operator can fix from the console: the Engine Runs retry deliberately
     * passes --weekly-payouts-only and never reaches this step.
     *
     * So the chain declines to ask, and records WHY where the other things it
     * could not do are recorded. The blocking engine is already a failure in
     * its own right; this is the record that a month's payment is waiting on it.
     */
    private function mayPayOut(Carbon $night, Carbon $creditingMonth): bool
    {
        $blocker = MonthlyEngineCompletionGate::blockingFailure($creditingMonth);

        if ($blocker === null) {
            return true;
        }

        $this->warn(sprintf(
            "The %s payout was not built: %s\nThe chain will build it on any night after that is fixed.",
            $creditingMonth->format('F Y'),
            $blocker['message'],
        ));

        NightlyRunAlert::payoutDeferred($night, $creditingMonth, $blocker['engine_key'], $blocker['message']);

        return false;
    }
```

Imports to add: `App\Modules\Compensation\Support\PlatformStart` (already imports
`MonthlyEngineCompletionGate` and `NightlyRunAlert`).

### 4. `MonthlyCloseCommand.php`

In `preflight()`, replace the coverage block:

```php
        $lastDay = $month->copy()->endOfMonth()->startOfDay();
        $owedFrom = PlatformStart::owedFrom($month->copy()->startOfMonth());
        $owedDays = $lastDay->day - $owedFrom->day + 1;
        $covered = $this->status->completedCutoffDatesBetween($owedFrom, $lastDay);
        $missing = max(0, $owedDays - count(array_unique($covered)));

        if ($missing > 0) {
            return sprintf(
                '%d of the %d days in %s have no completed cut-off. …',
                $missing,
                $owedDays,
                …
```

Add to the `preflight()` docblock, under check 3:

```
 *    Days before the platform's first distributor are not owed one: there was
 *    nobody to compute for. That is what lets the launch month close.
```

### 5. `NightlyRunAlert.php`

```php
    public const ACTION_PAYOUT_DEFERRED = 'compensation.nightly_run_payout_deferred';

    public const ACTIONS = [
        self::ACTION_SKIPPED_NIGHT,
        self::ACTION_BACKFILL_GAP,
        self::ACTION_MONTH_DEFERRED,
        self::ACTION_PAYOUT_DEFERRED,
    ];

    /** A month's payout batch the chain did not build, because its crediting is incomplete. */
    public static function payoutDeferred(Carbon $night, Carbon $creditingMonth, ?string $engineKey, string $reason): void
    {
        self::record(self::ACTION_PAYOUT_DEFERRED, $night, $reason, [
            'date' => $night->toDateString(),
            'month' => $creditingMonth->format('Y-m'),
            'engine_key' => $engineKey,
        ], ['date', 'month']);
    }
```

`record()` gains a fifth parameter so two months deferred on one night both get
a row:

```php
    /**
     * @param  array<string, mixed>  $details
     * @param  list<string>  $uniqueBy  Detail keys that make a row distinct. The
     *                                  night alone, unless one night can carry
     *                                  more than one of these — two months can
     *                                  be deferred on the same night.
     */
    private static function record(string $action, Carbon $night, string $reason, array $details, array $uniqueBy = ['date']): void
    {
        try {
            $query = AuditLog::query()->where('action', $action);

            foreach ($uniqueBy as $key) {
                $query->where('details->'.$key, $details[$key] ?? null);
            }

            if ($query->exists()) {
                return;
            }
            …
```

Add the fourth bullet to the class docblock:

```
 *   • PAYOUT DEFERRED — a month whose crediting is incomplete cannot be paid,
 *     and re-running the chain will not change that. The chain declines to ask
 *     rather than aborting the night on a standing condition; this row is the
 *     record that a month's payment is waiting on a named engine.
```

### 6. `EngineHealthService.php`

`chainAlertHeadline()` gains:

```php
            NightlyRunAlert::ACTION_PAYOUT_DEFERRED => sprintf(
                '%s has not been paid — its crediting is incomplete',
                is_string($details['month'] ?? null)
                    ? Carbon::parse($details['month'].'-01')->format('F Y')
                    : 'A month',
            ),
```

`chainAlertSteps()` gains:

```php
            NightlyRunAlert::ACTION_PAYOUT_DEFERRED => [
                'Nothing is lost and nothing is paid twice: the month\'s credits stay in the wallet, and the chain builds the batch on the first night after the crediting is complete.',
                'Find the engine named in the alert on the Engine Runs page and fix that — the payout is waiting on it, not on the payout.',
                'A month that can never be completed is a decision, not a fix: closing it short is permanent, and only php artisan compensation:monthly-close --month=<YYYY-MM> --force does it.',
            ],
```

### 7–10. Tests

See *Test plan*.

### 11. `docs/runbooks/engine-failure-triage.md`

New **§7a — A payout the chain deferred**, after §7: what the alert means, how
to read `engine_key` out of it, the two ways it clears (fix the engine and wait
for the next night, or decide the month with `--force`), and the standing
warning from §7 that a batch built from a shell has no maker.

### 12. `app/resources/help/payout-operations.md`

One short section: a monthly payout can be deferred, deferred is not lost, the
credits stay in the wallet, and the alert names the engine it is waiting on.

### 13. `docs/compliance/risk-register.md`

**R-101 — the launch month, and the months the gate now waves through.** Records
what changed, the hard-rule-2 inference the wave-through rests on, the belt
check, and that the one case it deliberately does not cover (a month with sales
and missing cut-offs) remains a human decision.

## Slices

| Slice | Title | Files (# refs) | Depends on | Model |
|---|---|---|---|---|
| S1 | A month with no sales owes no crediting | 1, 7 | — | Opus |
| S2 | A month is owed cut-offs from the platform's first day | 2, 3a, 4, 8, 9 | — | Opus |
| S3 | A deferred payout is an alert, not a failed night | 3b, 5, 6, 8, 10 | S1 | Opus |
| S4 | Runbook, help and risk register | 11, 12, 13 | S1–S3 | Sonnet |

S1 and S2 are independent and may run together. S3 needs S1 because its chain
test asserts a sales-free month is *not* deferred.

Every slice is money-path code: `compliance-officer` reviews the branch before
any commit, and each commit carries a `Compliance-Review:` trailer.

## Existing tests this change breaks, and why that is the point

**Read this before writing a line of D1.** `MonthlyEngineCompletionGate` has
never looked at sales, so no test that exercises it creates any. The moment it
does, every test that asserts a *refusal* is asserting it over a month with no
BV — which the new rule waves through. They will not error; they will quietly
stop testing what they claim to.

Affected files, all in `app/tests/Modules/Compensation/`:

| File | Tests | What to do |
|---|---|---|
| `MonthlyPayoutCloseCommandTest.php` | 13, most of them refusals | seed one BV entry for the month under test |
| `NightlyRunCommandTest.php` | the payout-gate ones — "adds the monthly payout close on the eighth", "re-queues the monthly payout the next night when the 8th was missed", "leaves the monthly payout alone once its batch exists", "does not re-queue a previous month the payout gate would refuse" | same |
| `OpenMonthGuardTest.php`, `EngineHealthDigestTest.php`, `RecomputeStateTest.php` | reference the close; check each | same where a refusal is asserted |

Add one helper to each of the two main files and call it from the arrange step
of every refusal test — never from a global `beforeEach`, because T1 and T4 need
a month with *no* sales:

```php
/**
 * One product sale in the month, which is what makes the completion gate
 * applicable at all: a month with no sales owes no crediting and is waved
 * through (hard rule 2 — no credit without a product sale).
 */
function seedMonthSales(Carbon $month): void
{
    BvLedgerEntry::create([
        'distributor_id' => 1,
        'order_id' => 950_000 + (int) $month->format('Ym'),
        'bv_paise' => 300_000,
        'type' => 'accrual',
        'effective_at' => $month->copy()->startOfMonth()->addDays(3),
    ]);
}
```

A test that forgets it is not a failing test, it is a silently weakened one — so
T2 exists specifically to fail if the rule ever swallows a month that had sales.

## Test plan

Pest, not Playwright — this change has no new UI surface. The alert renders
through `chainAlerts`, which the dashboard Engine panel and the health digest
already display and already test.

| # | Test | File | Asserts |
|---|---|---|---|
| T1 | a month with no product sales does not block its payout | MonthlyPayoutCloseCommandTest | `blockingFailure()` is null; the batch is built |
| T2 | a month with one sale and a failed engine still refuses | MonthlyPayoutCloseCommandTest | the existing refusal is untouched by T1's rule |
| T3 | a month with no sales but a stray credit still refuses | MonthlyPayoutCloseCommandTest | the belt check fires |
| T4 | a month whose engines all failed still refuses | MonthlyPayoutCloseCommandTest | "no credits" is not mistaken for "no sales" |
| T5 | the launch month closes although it began mid-month | MonthlyCloseCommandTest | preflight passes with cut-offs only from the first distributor |
| T6 | a month missing days on which distributors existed still refuses | MonthlyCloseCommandTest | D2 is not a general relaxation |
| T7 | the chain closes a launch month on the 1st | NightlyRunCommandTest | `monthIsWhole()` via the real step list |
| T8 | the chain does not fail a night over an incomplete month | NightlyRunCommandTest | exit 0; no `compensation.monthly-payout-close` step |
| T9 | it records the deferral, once per month per night | NightlyRunCommandTest | one `ACTION_PAYOUT_DEFERRED` row; a second night writes a second |
| T10 | two months deferred on one night write two rows | NightlyRunCommandTest | the `uniqueBy` change |
| T11 | it builds the payout on the first night after the month completes | NightlyRunCommandTest | no alert, batch built, night green |
| T12 | the deferral reaches the health digest | EngineHealthDigestTest | headline and steps |

Run with the project's explicit overrides — never a bare `php artisan test`:

```bash
docker exec -e DB_CONNECTION=mysql -e DB_DATABASE=arovolife_test -e DB_HOST=db \
  -e DB_PORT=3306 -e DB_USERNAME=arovolife -e DB_PASSWORD=secret \
  arovolife-app php artisan test --compact tests/Modules/Compensation
```

## Acceptance criteria

- [ ] T1–T12 pass, and the whole `tests/Modules/Compensation` suite is green.
- [ ] `vendor/bin/pint` clean; Larastan level 7 clean on every changed file.
- [ ] `compliance-officer` PASS on the branch; trailers on the commits.
- [ ] On a checkout with staging's data shape (no sales before 14 Sep),
      `php artisan compensation:nightly-run --date=<today>` exits **0**, builds
      the September monthly batch, and writes no `nightly_run.aborted`.
- [ ] `docs/runbooks/engine-failure-triage.md` §7a and
      `resources/help/payout-operations.md` describe the same behaviour the code
      has.

## Rollout

1. Merge to `main`, deploy to staging by `git_pull`, then `route:clear`,
   `config:clear`, `view:clear`, `event:clear` and `queue:restart`. **Not**
   `cache:clear` — `RedisStore::flush()` is a `flushdb()` on a Redis shared with
   eight other apps, two of them production. No migration, no asset change.
2. Verify on the next night's run, or immediately with
   `php artisan compensation:nightly-run --date=<today>`: expect exit 0, a
   `payout_batches` row for 2026-09 and no `nightly_run.aborted` in the log.
   That batch will hold **0 distributors** and sit `pending`: August had no
   sales, so it has nothing of its own to pay, and its only job is to exist so
   the chain stops asking. Exactly one batch is expected — the lookback branch
   is bounded by D1b and will not also build one for July.
3. **Staging's September stays refused, and that is correct** — 13 of its days
   have no cut-off and 317 distributors existed on them. From 8 October the
   chain will defer the September payout and record the alert instead of failing.
   Deciding it is an operator call:
   `php artisan compensation:monthly-close --month=2026-09 --force`, which
   permanently prices September on the 17 days that have cut-offs. Present the
   impact and get explicit confirmation before running it — it is a money-path
   command on a shared environment.

## Follow-ups (not in this plan)

- **An additive index on `distributors.effective_date`.** It carries none today.
  `PlatformStart::firstDay()` scans for a `MIN`, and so does the dashboard's
  "Joined this month" tile — which now re-runs once a minute per open dashboard
  since auto-refresh shipped. Harmless at 317 rows; a full scan at 10 lakh.
- The chain's `--weekly-payouts-only` retry still cannot reach a monthly payout.
  After this change it no longer needs to, because a blocked month is a standing
  alert rather than a failed night — worth re-reading if that ever changes.
