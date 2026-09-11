<?php

declare(strict_types=1);

namespace App\Modules\Compensation\Services;

use App\Modules\Compensation\Models\EngineRun;
use App\Modules\Compensation\Services\DTOs\EngineHealthReport;
use App\Modules\Compensation\Support\EngineDefinition;
use App\Modules\Compensation\Support\EnginePeriodType;
use App\Modules\Compensation\Support\EngineRegistry;
use App\Modules\Compensation\Support\MonthlyEngineCompletionGate;
use App\Modules\Compensation\Support\PrematureFreezeAlert;
use App\Modules\Compliance\Models\AuditLog;
use Illuminate\Support\Carbon;

/**
 * Builds the daily engine-health report: what failed, what never ran, and what
 * is stuck — each with the steps that close it.
 *
 * The Engine Runs page already shows all three, but only to somebody who opens
 * it. A missed scheduled run is worse still: it leaves NO row at all, so even
 * the sidebar failure badge stays silent. This service is what a once-a-day
 * email can be built from, and it answers for a non-developer admin, so every
 * item carries its own numbered instructions rather than a status code.
 *
 * @phpstan-import-type FailureItem from EngineHealthReport
 * @phpstan-import-type MissingItem from EngineHealthReport
 * @phpstan-import-type StuckItem from EngineHealthReport
 * @phpstan-import-type PrematureFreezeItem from EngineHealthReport
 */
final class EngineHealthService
{
    public const int STUCK_AFTER_HOURS = 3;

    public const int FAILURE_WINDOW_DAYS = 30;

    /** Far enough back to cover a monthly engine's previous fire date. */
    private const int FIRE_LOOKBACK_DAYS = 31;

    /**
     * The cadence note that marks an engine whose scheduled run works the day
     * BEFORE it fires. routes/console.php passes the GSB cut-off
     * `--date=yesterday`; nothing else in the registry expresses that, and
     * `periodRelativeTo()` deliberately maps a replayed day to itself.
     */
    private const string PREVIOUS_DAY_NOTE = 'previous day';

    public function __construct(private readonly EngineStatusService $status) {}

    public function report(Carbon $now): EngineHealthReport
    {
        $failures = $this->failures();

        return new EngineHealthReport(
            failures: $failures,
            missing: $this->missing($now, $failures),
            stuck: $this->stuck(),
            prematureFreezes: $this->prematureFreezes($now),
        );
    }

    /**
     * The numbered steps that close ONE item, written for an admin who has
     * never opened a terminal.
     *
     * Which set applies is decided by the engine, not by the reader: the four
     * scheduler-only engines have no button on the Engine Runs page (a payout
     * batch is never created and approved by the same person), so telling their
     * reader to "trigger it" would send them looking for a control that is not
     * there.
     *
     * @param  string  $kind  failed | missing | stuck
     * @return list<string>
     */
    public function remedyFor(EngineDefinition $engine, Carbon $period, string $kind): array
    {
        if ($kind === 'stuck') {
            return $this->stuckSteps($engine, $period);
        }

        return match ($engine->key) {
            'repurchase.evaluate' => $this->repurchaseEvaluateSteps($engine, $period, $kind),
            'gsb.weekly-payout' => $this->weeklyPayoutSteps($period),
            'payout.monthly', 'compensation.monthly-payout-close' => $this->monthlyPayoutSteps($engine, $period),
            'compensation.monthly-close' => $this->monthlyCloseSteps($engine, $period),
            // The button, not a key list, decides: a future scheduler-only
            // engine must never be sent looking for a control that is not there.
            default => $engine->manuallyTriggerable
                ? $this->triggerSteps($engine, $period, $kind)
                : $this->schedulerOnlySteps($engine, $period),
        };
    }

    /**
     * Failed runs the badge would count, as reportable items.
     *
     * @return list<FailureItem>
     */
    private function failures(): array
    {
        $items = [];

        foreach ($this->status->unresolvedFailures(self::FAILURE_WINDOW_DAYS) as $run) {
            $definition = $this->definitionFor($run->engine_key);

            $items[] = [
                'engine' => $definition->label ?? $run->engine_key,
                'key' => $run->engine_key,
                'period' => $this->displayPeriod($definition, $run->period_start),
                'period_value' => $this->periodValue($definition, $run->period_start),
                'started_at' => $run->started_at->format('d M Y H:i'),
                'error' => $this->firstErrorLine($run->error),
                'steps' => $this->stepsFor($definition, $run->period_start, 'failed'),
            ];
        }

        return $items;
    }

    /**
     * Periods the scheduler should have produced and did not.
     *
     * A `skipped` row counts as ran — a flag-off engine computes nothing and is
     * owed no run. A `running` row counts as ran too; if it never finishes it is
     * reported as stuck instead, which has different instructions. A `failed`
     * row does NOT count as ran: the period is still owed. It is only left out
     * here when the same failure is already listed above with its error text,
     * so the reader is never given two remedies for one gap.
     *
     * @param  list<FailureItem>  $failures
     * @return list<MissingItem>
     */
    private function missing(Carbon $now, array $failures): array
    {
        $alreadyReported = [];

        foreach ($failures as $failure) {
            $alreadyReported[$failure['key'].'@'.$failure['period_value']] = true;
        }

        $items = [];

        foreach (EngineRegistry::all() as $definition) {
            if (! $definition->cadence->isScheduled() || $definition->isOrchestrator) {
                continue;
            }

            $firedAt = $this->lastFireInstant($definition, $now);

            if ($firedAt === null) {
                continue;
            }

            $period = $this->periodForFire($definition, $firedAt);

            $ran = EngineRun::query()
                ->where('engine_key', $definition->key)
                ->whereDate('period_start', $period->toDateString())
                ->where('status', '!=', EngineRun::STATUS_FAILED)
                ->exists();

            if ($ran || isset($alreadyReported[$definition->key.'@'.$definition->formatPeriod($period)])) {
                continue;
            }

            $items[] = [
                'engine' => $definition->label,
                'key' => $definition->key,
                'period' => $definition->displayPeriod($period),
                'period_value' => $definition->formatPeriod($period),
                'due_at' => $firedAt->format('d M Y H:i'),
                'steps' => $this->remedyFor($definition, $period, 'missing'),
            ];
        }

        return $items;
    }

    /**
     * Pools the self-heal found frozen too early and had to KEEP, because money
     * had already moved at the wrong price.
     *
     * Read from `audit_log`, not `engine_runs`: the run that detects a kept
     * freeze succeeds — everything else about it is correct — so there is no
     * failed row for this to hang off, and on staging it lived only in a log
     * line nobody read for a month (F30).
     *
     * @return list<PrematureFreezeItem>
     */
    private function prematureFreezes(Carbon $now): array
    {
        $items = [];

        $rows = AuditLog::query()
            ->where('action', PrematureFreezeAlert::ACTION)
            ->where('created_at', '>=', $now->copy()->subDays(self::FAILURE_WINDOW_DAYS))
            ->orderBy('id')
            ->get();

        foreach ($rows as $row) {
            $details = $row->details ?? [];
            $key = is_string($details['engine_key'] ?? null) ? $details['engine_key'] : '';
            $definition = $this->definitionFor($key);
            $periodValue = is_string($details['period'] ?? null) ? $details['period'] : '';

            $period = $periodValue === '' || $definition === null
                ? null
                : $definition->parsePeriod($periodValue);

            $items[] = [
                'engine' => $definition === null ? $key : $definition->label,
                'key' => $key,
                'period' => $period === null ? $periodValue : $definition->displayPeriod($period),
                'period_value' => $periodValue,
                'frozen_at' => is_string($details['frozen_at'] ?? null) ? $details['frozen_at'] : 'unknown',
                'detected_at' => $row->created_at->format('d M Y H:i'),
                'steps' => $this->prematureFreezeSteps($definition === null ? $key : $definition->label, $periodValue),
            ];
        }

        return $items;
    }

    /**
     * There is no button for this one, and there must not be: a re-run prices
     * against the same wrong snapshot, and a second credit is not the fix.
     *
     * @return list<string>
     */
    private function prematureFreezeSteps(string $label, string $period): array
    {
        return [
            'Do NOT re-run the engine: it would price against the same wrong snapshot and credit nobody the difference.',
            "The pool for {$period} was frozen before the period had finished, so {$label} priced that period on partial BV — everybody who earned in it was paid at the wrong rate.",
            'Send this email to the developer today. Correcting it means rebuilding the period from the orders, which only they can do.',
            'Until then, treat that period as provisional on every report; the payout for it may be short.',
        ];
    }

    /**
     * @return list<StuckItem>
     */
    private function stuck(): array
    {
        $items = [];

        foreach ($this->status->stuckRuns(self::STUCK_AFTER_HOURS) as $run) {
            $definition = $this->definitionFor($run->engine_key);

            $items[] = [
                'engine' => $definition->label ?? $run->engine_key,
                'key' => $run->engine_key,
                'period' => $this->displayPeriod($definition, $run->period_start),
                'period_value' => $this->periodValue($definition, $run->period_start),
                'started_at' => $run->started_at->format('d M Y H:i'),
                'steps' => $this->stepsFor($definition, $run->period_start, 'stuck'),
            ];
        }

        return $items;
    }

    /**
     * The most recent instant the scheduler would have fired this engine, or
     * null when it has not fired within the look-back.
     *
     * Today's fire only counts once its clock time has passed: running the
     * digest by hand at 00:03 must not report the 00:05 evaluation as missed.
     */
    private function lastFireInstant(EngineDefinition $definition, Carbon $now): ?Carbon
    {
        $date = $now->copy()->startOfDay();

        for ($day = 0; $day <= self::FIRE_LOOKBACK_DAYS; $day++, $date = $date->copy()->subDay()) {
            if (! $definition->cadence->runsOn($date)) {
                continue;
            }

            $firedAt = $definition->cadence->atOn($date);

            if ($firedAt->lte($now)) {
                return $firedAt;
            }
        }

        return null;
    }

    /** The period a scheduled fire on that date produces. */
    private function periodForFire(EngineDefinition $definition, Carbon $firedAt): Carbon
    {
        $period = $definition->periodRelativeTo($firedAt);

        if (str_contains((string) $definition->cadence->note, self::PREVIOUS_DAY_NOTE)) {
            $period = $period->subDay();
        }

        return $definition->periodStart($period);
    }

    private function definitionFor(string $key): ?EngineDefinition
    {
        return EngineRegistry::has($key) ? EngineRegistry::get($key) : null;
    }

    private function displayPeriod(?EngineDefinition $definition, Carbon $period): string
    {
        return $definition?->displayPeriod($period) ?? $period->format('d M Y');
    }

    private function periodValue(?EngineDefinition $definition, Carbon $period): string
    {
        return $definition?->formatPeriod($period) ?? $period->toDateString();
    }

    /**
     * @return list<string>
     */
    private function stepsFor(?EngineDefinition $definition, Carbon $period, string $kind): array
    {
        if ($definition === null) {
            return ['This engine no longer exists — nothing to re-run; ask the developer to clear the row.'];
        }

        return $this->remedyFor($definition, $definition->periodStart($period), $kind);
    }

    /**
     * The recorded error, trimmed to something an email can carry.
     *
     * This string leaves the platform by SMTP. Engine exception messages must
     * therefore never carry a distributor's name, PAN, bank data or an amount —
     * an ID at most (see BankDecryptionException). Anything richer belongs in
     * the structured log, not in the message.
     */
    private function firstErrorLine(?string $error): string
    {
        $firstLine = trim(strtok((string) $error, "\n") ?: '');

        if ($firstLine === '') {
            return 'No error text was recorded.';
        }

        return mb_strimwidth($firstLine, 0, 200, '…');
    }

    /**
     * Engines with a button on the Engine Runs page.
     *
     * @return list<string>
     */
    private function triggerSteps(EngineDefinition $engine, Carbon $period, string $kind): array
    {
        $field = $engine->periodType === EnginePeriodType::Month ? 'Month' : 'Date';
        $when = $this->periodPhrase($engine, $period);
        $outcome = $kind === 'failed' ? 'failed' : 'did not run';

        return [
            'Open the admin console → Compensation → Engine Runs (button below).',
            "Find the card \"{$engine->label}\".",
            "In its {$field} field select {$when}.",
            "In Reason type why you are running it, e.g. \"Scheduled run for {$engine->displayPeriod($period)} {$outcome} — re-running from the health email\".",
            'Click Preview & Confirm →. The preview lists every engine that will run; a missing prerequisite is added for you — that is expected. Click Confirm.',
            "Wait a minute, then reload Engine Runs: the card's Last run must read succeeded for {$engine->displayPeriod($period)}.",
            'If it reads failed again, do not retry more than once — send the error text shown under Run events → to the developer.',
            'Nothing is ever credited twice: a re-run only fills what the failed run left empty.',
        ];
    }

    /**
     * The repurchase evaluation is the one engine that must NEVER be re-run
     * for a past date: it refreshes every cycle "as at" the date it is given,
     * so a back-dated run stamps today's purchases onto an older cycle. Today's
     * run covers the missed day, because it sees everything up to now.
     *
     * The failed row itself is matched by period, so a run for today does not
     * clear it from this email; it ages out of the 30-day window instead.
     *
     * @return list<string>
     */
    private function repurchaseEvaluateSteps(EngineDefinition $engine, Carbon $period, string $kind): array
    {
        $steps = [
            "Do NOT select {$this->periodPhrase($engine, $period)}: this engine may only ever be run for today. A run for a past date would stamp today's purchases onto an older cycle.",
            'Open the admin console → Compensation → Engine Runs (button below).',
            "Find the card \"{$engine->label}\" and leave its Date field on today's date.",
            'In Reason type why you are running it, e.g. "Scheduled run did not complete — refreshing from the health email".',
            'Click Preview & Confirm →, then Confirm. Today\'s run refreshes every cycle as at today, which covers the day that was missed.',
            'If a GSB Daily Cut-off is also listed in this email, re-run it now with its own steps — it only needs an evaluation dated after its day.',
        ];

        if ($kind === 'failed') {
            $steps[] = 'This failed row stays in the email until it is 30 days old; ask the developer to clear it if it must go quiet sooner.';
        }

        return $steps;
    }

    /**
     * Any scheduler-only engine that no other branch names.
     *
     * @return list<string>
     */
    private function schedulerOnlySteps(EngineDefinition $engine, Carbon $period): array
    {
        return [
            'Nothing to click: this engine has no button on the Engine Runs page — only the scheduler runs it.',
            "Ask the developer to re-run \"{$engine->label}\" for {$this->periodPhrase($engine, $period)} on the server.",
        ];
    }

    /**
     * The weekly GSB batch: scheduler-only, and it heals itself a week later.
     *
     * @return list<string>
     */
    private function weeklyPayoutSteps(Carbon $period): array
    {
        $nextTuesday = $period->copy()->addWeek()->toDateString();

        return [
            'Nothing to click. The weekly batch is created only by the scheduler, so the same person never both creates and approves a batch.',
            "Every unpaid weekly income is still in the distributors' wallets; next Tuesday's batch sweeps it automatically, one week late.",
            "Only if the next Tuesday also produces no batch (this email will say so): ask the developer to run php artisan gsb:weekly-payout --date={$nextTuesday} on the server.",
            'Then approve the batch on Compensation → Weekly Payouts.',
        ];
    }

    /**
     * The monthly payout batch and its close. `payout.monthly` is dated the
     * month the money moves; the close is driven by the CREDITING month, which
     * is the month before it.
     *
     * @return list<string>
     */
    private function monthlyPayoutSteps(EngineDefinition $engine, Carbon $period): array
    {
        $crediting = $this->creditingMonthDate($engine, $period);

        return [
            "First clear every other item in this email for {$engine->displayPeriod($crediting)} — the month being paid. The payout refuses to run while any crediting engine for it is failed or missing.",
            'Then ask the developer to run php artisan compensation:monthly-payout-close --month='.$crediting->format('Y-m').' on the server.',
            'It re-checks that every crediting engine succeeded, then creates the batch.',
            'Approve the batch on Compensation → Monthly Payouts. A batch is never created twice for the same month.',
        ];
    }

    /**
     * The crediting close: an orchestrator, so the step that actually broke is
     * reported separately in the same email with its own instructions.
     *
     * @return list<string>
     */
    private function monthlyCloseSteps(EngineDefinition $engine, Carbon $period): array
    {
        $order = implode(' → ', array_map(
            static fn (string $key): string => EngineRegistry::get($key)->label,
            MonthlyEngineCompletionGate::ENGINE_KEYS,
        ));

        return [
            'The close stopped at one step; that step is listed separately in this email with its own instructions — re-run it first.',
            "Then run the steps after it, in this order: {$order}.",
            "Each one is for {$engine->displayPeriod($period)}; skip any whose card already reads succeeded for that month.",
            'When every step reads succeeded, the payout on the 8th proceeds on its own.',
            'If the 8th has already passed, ask the developer to run php artisan compensation:monthly-payout-close --month='.$this->creditingMonth($engine, $period).' on the server.',
        ];
    }

    /**
     * @return list<string>
     */
    private function stuckSteps(EngineDefinition $engine, Carbon $period): array
    {
        $lastStep = $engine->manuallyTriggerable
            ? "Once the card no longer shows running, re-run \"{$engine->label}\" for {$engine->displayPeriod($period)} from Engine Runs."
            : "Once the card no longer shows running, ask the developer to re-run it for {$engine->displayPeriod($period)} — this engine has no button.";

        return [
            'Do not trigger anything for this engine yet — a second run is refused while one is recorded as running.',
            'A run this long almost always means the compensation queue worker stopped mid-run.',
            'Ask the developer to restart it (php artisan queue:restart) and to mark the run failed if it has not finished within the hour.',
            $lastStep,
        ];
    }

    /** "Aug 2026 (2026-08)" / "07 Sep 2026 (2026-09-07)" — as shown, and as typed. */
    private function periodPhrase(EngineDefinition $engine, Carbon $period): string
    {
        return sprintf('%s (%s)', $engine->displayPeriod($period), $engine->formatPeriod($period));
    }

    /** The month whose credits the batch pays: the batch month minus one. */
    private function creditingMonth(EngineDefinition $engine, Carbon $period): string
    {
        return $this->creditingMonthDate($engine, $period)->format('Y-m');
    }

    private function creditingMonthDate(EngineDefinition $engine, Carbon $period): Carbon
    {
        return $engine->key === 'payout.monthly'
            ? $period->copy()->subMonthNoOverflow()
            : $period->copy();
    }
}
