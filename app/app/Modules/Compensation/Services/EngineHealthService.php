<?php

declare(strict_types=1);

namespace App\Modules\Compensation\Services;

use App\Modules\Compensation\Models\EngineRun;
use App\Modules\Compensation\Services\DTOs\EngineHealthReport;
use App\Modules\Compensation\Support\EngineDefinition;
use App\Modules\Compensation\Support\EnginePeriodType;
use App\Modules\Compensation\Support\EngineRegistry;
use App\Modules\Compensation\Support\MonthlyEngineCompletionGate;
use App\Modules\Compensation\Support\NightlyRunAlert;
use App\Modules\Compensation\Support\PrematureFreezeAlert;
use App\Modules\Compliance\Models\AuditLog;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

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
 * @phpstan-import-type ChainAlertItem from EngineHealthReport
 */
final class EngineHealthService
{
    public const int STUCK_AFTER_HOURS = 3;

    public const int FAILURE_WINDOW_DAYS = 30;

    /** Far enough back to cover a monthly engine's previous fire date. */
    private const int FIRE_LOOKBACK_DAYS = 31;

    /**
     * How long the chain's own alerts stay in the digest.
     *
     * Shorter than the failure window because the chain re-records an unhealed
     * condition every night it still holds, so a week is enough to keep it in
     * front of a reader without repeating a night that was made good the
     * following morning for thirty days.
     */
    private const int CHAIN_ALERT_WINDOW_DAYS = 7;

    public function __construct(private readonly EngineStatusService $status) {}

    public function report(Carbon $now): EngineHealthReport
    {
        $failures = $this->failures();

        return new EngineHealthReport(
            failures: $failures,
            missing: $this->missing($now, $failures),
            stuck: $this->stuck(),
            prematureFreezes: $this->prematureFreezes($now),
            chainAlerts: $this->chainAlerts($now),
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
     * What the three scheduled runs could not do — and nothing else can see.
     *
     * Read from `audit_log` because there is nothing else to read: a night the
     * scheduler skipped starts no command, so it writes no `engine_runs` row;
     * a backfill gap and every deferral end in a run that exits 0 and records
     * itself `skipped`. The failure badge, the missing-period check and the
     * stuck check are all silent on every one of them (see
     * {@see NightlyRunAlert}).
     *
     * @return list<ChainAlertItem>
     */
    private function chainAlerts(Carbon $now): array
    {
        $rows = AuditLog::query()
            ->whereIn('action', NightlyRunAlert::ACTIONS)
            ->where('created_at', '>=', $now->copy()->subDays(self::CHAIN_ALERT_WINDOW_DAYS))
            ->orderBy('id')
            ->get();

        $items = [];

        foreach ($rows as $row) {
            $details = $row->details ?? [];
            $date = is_string($details['date'] ?? null) ? $details['date'] : $row->created_at->toDateString();

            $items[] = [
                'kind' => $row->action,
                'headline' => $this->chainAlertHeadline($row->action, $details),
                'date' => Carbon::parse($date)->format('d M Y'),
                'recorded_at' => $row->created_at->format('d M Y H:i'),
                'steps' => $this->chainAlertSteps($row->action, $details),
            ];
        }

        return $items;
    }

    /**
     * The one line that has to say which run, which month and WHY.
     *
     * Every deferred close used to read as a coverage gap, because coverage was
     * the only cause there was. There are three now — the days are not all cut
     * off, a cut-off is still running, or tonight's runs are not green — and
     * they call for three different actions, so a month deferred on a red
     * nightly run must not be reported to the ops mailbox as missing days that
     * somebody then goes looking for.
     *
     * @param  array<string, mixed>  $details
     */
    private function chainAlertHeadline(string $action, array $details): string
    {
        $month = is_string($details['month'] ?? null)
            ? Carbon::parse($details['month'].'-01')->format('F Y')
            : 'A month';

        return match ($action) {
            NightlyRunAlert::ACTION_SKIPPED_NIGHT => sprintf(
                '%s never started — the previous one was still running',
                $this->runLabel($details),
            ),
            NightlyRunAlert::ACTION_BACKFILL_GAP => 'More nights are missing than the nightly run may heal on its own',
            NightlyRunAlert::ACTION_MONTH_DEFERRED => match ($details['cause'] ?? 'coverage') {
                'cutoff_in_flight' => $month.' was not closed — the cut-off was still running; it closes the next night',
                'prerequisite' => $month." was not closed — tonight's nightly run (or the Tuesday batch) had not succeeded; it closes the next night both are green",
                // `missing_days` is null for every cause but this one.
                default => sprintf(
                    '%s was not closed — %s of its days have no completed cut-off',
                    $month,
                    is_int($details['missing_days'] ?? null) ? (string) $details['missing_days'] : 'some',
                ),
            },
            NightlyRunAlert::ACTION_PAYOUT_DEFERRED => $month.' has not been paid — its crediting is incomplete',
            NightlyRunAlert::ACTION_WEEKLY_DEFERRED => sprintf(
                "The %s weekly payout was not built — tonight's nightly run had not succeeded",
                $this->weeklyDeferredTuesdays($details),
            ),
            default => 'A scheduled run reported something it could not do',
        };
    }

    /**
     * What to do about it — and for every deferral the answer is "nothing", so
     * the steps say what is being waited for instead.
     *
     * No step here names a control: there is none. A failed or deferred run is
     * repaired by the platform team from the server, and every remedy that
     * names a command names the ordinary one, never a rebuild.
     *
     * @param  array<string, mixed>  $details
     * @return list<string>
     */
    private function chainAlertSteps(string $action, array $details): array
    {
        return match ($action) {
            NightlyRunAlert::ACTION_SKIPPED_NIGHT => [
                $this->skippedRunFirstStep($details),
                'What this says is that the run is taking longer than a day to finish, which the self-heal hides rather than fixes.',
                'Check the Engine Runs page for how long the previous one took, and send that duration to the platform team if it keeps happening.',
            ],
            NightlyRunAlert::ACTION_BACKFILL_GAP => [
                'This one does not heal itself. Last night ran; the days before it did not, and nobody has been credited for them.',
                'Ask the platform team to cut off each missing day in order (php artisan gsb:daily-cutoff --date=<day>), oldest first, before the month it belongs to is closed.',
                'Do not close that month until every one of its days is done — a month closed short stays short.',
            ],
            NightlyRunAlert::ACTION_MONTH_DEFERRED => match ($details['cause'] ?? 'coverage') {
                'cutoff_in_flight' => [
                    'Nothing to click, and nothing is wrong: the cut-off was still running when the monthly run reached the month, and it commits per distributor.',
                    'The monthly run closes the month on the next night; nothing to type.',
                    "If tomorrow's email still lists it, send the cut-off's duration to the platform team — it is taking longer than the gap between the two runs.",
                ],
                'prerequisite' => [
                    'Nobody receives Growth Booster, Rank Bonus, Fortune or ADC for this month until it is closed.',
                    'Nothing to click: the monthly run closes it on the first night the nightly run — and the Tuesday batch, when one is owed — has succeeded.',
                    'What needs fixing is the run named elsewhere in this email, not the month.',
                ],
                default => [
                    'Nobody receives Growth Booster, Rank Bonus, Fortune or ADC for this month until it is closed, and the monthly run will not close it while days are missing.',
                    'Ask the platform team to cut off the missing days (php artisan gsb:daily-cutoff --date=<day>); then the monthly run closes the month the next night; nothing to type.',
                    'The payout on the 8th refuses a month whose crediting is incomplete, so this cannot reach a bank half-done.',
                ],
            },
            NightlyRunAlert::ACTION_PAYOUT_DEFERRED => [
                'Distributors are not paid for this month until the engine the audit entry names has succeeded.',
                'Clear the other items in this email for that month first — the payout refuses a month whose crediting is incomplete.',
                'The monthly run re-attempts the payout every night from the 8th once every crediting engine has succeeded; nothing to type unless the month has to be forced.',
            ],
            NightlyRunAlert::ACTION_WEEKLY_DEFERRED => [
                'Nothing to click: the weekly run builds it on the first night the nightly run is green.',
                'The batch is still dated that Tuesday when it is built, so the earning week it pays is unchanged and nobody waits a week.',
                "If tomorrow's email still lists it, send the nightly run's error to the platform team.",
            ],
            default => ['Ask the platform team to read the audit log entry for this date.'],
        };
    }

    /**
     * "The Nightly Run" / "The Weekly Run" / "The Monthly Run" — the run the
     * skipped-night alert belongs to, read from the key the alert recorded
     * rather than assumed, because three runs fire on their own clocks and an
     * overlap on one says nothing about the others.
     *
     * @param  array<string, mixed>  $details
     */
    private function runLabel(array $details): string
    {
        $key = $details['orchestrator'] ?? null;

        if (! is_string($key) || ! EngineRegistry::has($key)) {
            return 'A scheduled run';
        }

        return 'The '.Str::before(EngineRegistry::get($key)->label, ' (');
    }

    /**
     * The first step of a skipped-run alert: what the next night picks up, and
     * it differs per run.
     *
     * @param  array<string, mixed>  $details
     */
    private function skippedRunFirstStep(array $details): string
    {
        return match ($details['orchestrator'] ?? null) {
            'compensation.weekly-run' => 'Nothing to click: the next night builds any Tuesday this one would have, still dated that Tuesday, so the week paid is unchanged.',
            'compensation.monthly-run' => 'Nothing to click: the next night re-evaluates what the month owes — a close, a payout, or neither.',
            default => 'Nothing to click, and nothing is owed twice: the next nightly run cuts off the days this night would have; the weekly and monthly runs are separate and were not affected.',
        };
    }

    /**
     * The Tuesday (or Tuesdays) a weekly deferral names, as a date the reader
     * can match against the Weekly Payouts page.
     *
     * @param  array<string, mixed>  $details
     */
    private function weeklyDeferredTuesdays(array $details): string
    {
        $tuesdays = $details['tuesdays'] ?? null;

        if (! is_array($tuesdays) || $tuesdays === []) {
            return 'week\'s';
        }

        return implode(', ', array_map(
            static fn (mixed $day): string => Carbon::parse((string) $day)->format('d M Y'),
            $tuesdays,
        ));
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

        // An orchestrated engine has no clock of its own: it runs when the
        // chain reaches it, so the instant it was DUE is the instant the chain
        // started. Judging it by the minute it used to fire at would report a
        // heavy month-end chain as a missing engine while it is still running.
        $chainStartsAt = $definition->chainStartsAt();

        for ($day = 0; $day <= self::FIRE_LOOKBACK_DAYS; $day++, $date = $date->copy()->subDay()) {
            if (! $definition->cadence->runsOn($date)) {
                continue;
            }

            $firedAt = $chainStartsAt === null
                ? $definition->cadence->atOn($date)
                : self::atTimeOn($date, $chainStartsAt);

            if ($firedAt->lte($now)) {
                return $firedAt;
            }
        }

        return null;
    }

    /** `$time` is 'HH:MM' in the app timezone, as every cadence declares it. */
    private static function atTimeOn(Carbon $date, string $time): Carbon
    {
        [$hour, $minute] = array_map('intval', explode(':', $time));

        return $date->copy()->setTime($hour, $minute);
    }

    /** The period a scheduled fire on that date produces. */
    private function periodForFire(EngineDefinition $definition, Carbon $firedAt): Carbon
    {
        return $definition->periodStart($definition->periodForFireOn($firedAt));
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
        $tuesday = $period->toDateString();

        return [
            'Nothing to click. The weekly batch is created only by the scheduler, so the same person never both creates and approves a batch.',
            'The weekly run builds a Tuesday it missed on its next night, still dated that Tuesday; distributors do not wait a week.',
            "Only if tomorrow's email still lists it: ask the platform team to run php artisan compensation:weekly-run --date={$tuesday} on the server.",
            'A batch built from a shell has no maker — a second person must approve it on Compensation → Weekly Payouts.',
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
            'The monthly run re-attempts the payout every night from the 8th once every crediting engine has succeeded; nothing to type unless the month has to be forced.',
            'Only then, and only if it still has not been built: ask the platform team to run php artisan compensation:monthly-run --date=<tonight> on the server.',
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
            'When every step reads succeeded, the monthly run closes the month and pays it from the 8th on its own.',
            'If the 8th has already passed, the monthly run builds the payout on its next night — nothing to type.',
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
    private function creditingMonthDate(EngineDefinition $engine, Carbon $period): Carbon
    {
        return $engine->key === 'payout.monthly'
            ? $period->copy()->subMonthNoOverflow()
            : $period->copy();
    }
}
