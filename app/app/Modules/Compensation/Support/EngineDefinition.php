<?php

declare(strict_types=1);

namespace App\Modules\Compensation\Support;

use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Immutable metadata for one compensation engine. Pure description — it holds
 * no state, runs no queries and knows nothing about whether the engine has run.
 *
 * @phpstan-type EngineDependency array{key: string, shift?: string, expand?: string}
 */
final readonly class EngineDefinition
{
    /**
     * @param  string  $key  Stable registry key, e.g. 'gbb.monthly'.
     * @param  class-string  $commandClass  Console command class.
     * @param  string  $commandSignature  Artisan name, e.g. 'gbb:monthly-run'.
     * @param  string  $periodOption  '--month' or '--date'.
     * @param  list<EngineDependency>  $dependencies  shift: 'prev-month'; expand: 'month'|'week'.
     * @param  class-string|null  $featureFlagClass  Pennant feature, or null when always on.
     * @param  string  $defaultPeriod  'today' | 'current-month' | 'prev-month'.
     * @param  bool  $manuallyTriggerable  False for the payout-batch engines: the
     *                                     finance permission that would trigger them is the same one
     *                                     that approves the batch, so allowing a manual trigger would
     *                                     let one admin both create and approve a payout
     *                                     (maker-checker). Those engines stay scheduler-only.
     * @param  bool  $requiresClosedPeriod  True for engines that freeze their period's economics
     *                                      (daily/monthly pools) or pay in arrears. Their period must
     *                                      have ENDED before a manual run may target it: running the
     *                                      GSB cut-off for a day still in flight freezes that day's
     *                                      pool at whatever partial BV exists at that instant, and the
     *                                      later scheduled run then prices the day's real achievers
     *                                      against the stale snapshot (staging incident, 24 Aug 2026).
     *                                      The chain resolver already caps DEPENDENCY cut-offs at
     *                                      yesterday for the same reason — this flag closes the same
     *                                      hole for the directly requested engine.
     * @param  string|null  $orchestratedBy  Registry key of the orchestrator command that fires this
     *                                       engine on schedule. The engine still declares its own
     *                                       cadence — that is when it actually runs — but nothing in
     *                                       routes/console.php registers it directly, so
     *                                       EngineRegistryTest looks for the orchestrator instead.
     * @param  bool  $isOrchestrator  True for the three scheduled runs and the two monthly close
     *                                commands, which run other engines rather than computing anything
     *                                themselves. The recompute replay drives the individual engines
     *                                directly, so it must skip these or every step would be invoked
     *                                twice.
     * @param  bool  $developerOnly  True for the four period rebuilds (ADR-0016). They are registry
     *                               entries so their runs are recorded and their signatures are pinned
     *                               like every other engine's, but they are not engines an admin may see:
     *                               the Engine Runs page skips their cards for EVERY role, and the
     *                               rebuild surface itself is behind `role:developer`. A flag rather
     *                               than a hand-written list of keys in the view, for the reason
     *                               {@see EngineRegistry::rootOrchestratorKeys()} gives.
     * @param  bool  $latestOnly  True for an engine whose output is only ever its LATEST period — the
     *                            rank progress snapshot, a read-model each run replaces and nothing
     *                            downstream reads. A later success therefore resolves an earlier
     *                            failure (like a root orchestrator, see
     *                            EngineStatusService::unresolvedFailureQuery()), and the recompute
     *                            replay skips it: firing it per replayed day would be pure cost.
     */
    public function __construct(
        public string $key,
        public string $label,
        public string $description,
        public EnginePeriodType $periodType,
        public string $commandClass,
        public string $commandSignature,
        public string $periodOption,
        public array $dependencies,
        public ?string $featureFlagClass,
        public ?string $reportRouteName,
        public EngineCadence $cadence,
        public string $defaultPeriod,
        public bool $manuallyTriggerable = true,
        public bool $requiresClosedPeriod = false,
        public ?string $orchestratedBy = null,
        public bool $isOrchestrator = false,
        public bool $developerOnly = false,
        public bool $latestOnly = false,
    ) {}

    /**
     * The latest period a MANUAL run may target: the most recent CLOSED period
     * for economics-freezing engines, otherwise the period in flight (a future
     * period has no sales data for anybody).
     */
    public function latestManualPeriod(): Carbon
    {
        if ($this->periodType === EnginePeriodType::Month) {
            $current = Carbon::today()->startOfMonth();

            return $this->requiresClosedPeriod ? $current->subMonthNoOverflow() : $current;
        }

        return $this->requiresClosedPeriod ? Carbon::yesterday() : Carbon::today();
    }

    /**
     * Human sentence for the admin console. Generated from {@see $cadence} so
     * the schedule is described in exactly one place.
     *
     * An orchestrated engine borrows the clock from the run that fires it — it
     * no longer has one of its own, and printing the minute it used to fire at
     * is the kind of small lie an operator plans a morning around. Since there
     * are three runs (ADR-0016) the sentence names which one, so "Tuesdays, in
     * the Weekly Run from 03:00 IST" reads as the schedule it is.
     *
     * The parenthetical is dropped from the run's label — "Weekly Run (Tuesday
     * payout)" is a card title, not something to read mid-sentence.
     */
    public function scheduleText(): string
    {
        $root = $this->chainRoot();

        return $this->cadence->describe(
            $root?->cadence->time,
            $root === null ? null : Str::before($root->label, ' ('),
        );
    }

    /**
     * The run at the ROOT of this engine's orchestration, or null when the
     * scheduler fires the engine directly.
     *
     * Walks all the way up, because an orchestrator may itself be orchestrated:
     * the monthly close is fired by the monthly run, so the clock a crediting
     * engine answers to is the monthly run's, not the close's.
     */
    public function chainRoot(): ?EngineDefinition
    {
        if ($this->orchestratedBy === null) {
            return null;
        }

        $root = EngineRegistry::get($this->orchestratedBy);

        while ($root->orchestratedBy !== null) {
            $root = EngineRegistry::get($root->orchestratedBy);
        }

        return $root;
    }

    /**
     * The time the run that fires this engine starts, or null when the
     * scheduler fires the engine directly.
     */
    public function chainStartsAt(): ?string
    {
        return $this->chainRoot()?->cadence->time;
    }

    /**
     * The next instant the scheduler fires this engine — the twin of
     * {@see scheduleText()}, and split the same way: the day is this engine's
     * own rule, the clock belongs to the run that fires it.
     *
     * Asking the run's cadence outright instead is wrong in both directions.
     * The three runs are nightly and work out inside themselves whether an
     * engine is owed tonight, so a monthly bonus would claim to run tomorrow at
     * 04:00; and an engine's own declared minute is a POSITION in its run, not
     * a clock ({@see EngineCadence::$time}).
     */
    public function nextRunAfter(Carbon $from): ?Carbon
    {
        return $this->cadence->nextRunAfter($from, $this->chainRoot()?->cadence);
    }

    /**
     * The period an operator most likely wants, matching the command's own
     * default.
     *
     * IST explicitly, not the app timezone. This is what `RecordEngineRun`
     * dates a run row with when the command was invoked without its period
     * option — which is every scheduled run — and what `RunPrerequisites` then
     * looks that row up by. The three runs resolve their own night with
     * `Carbon::today('Asia/Kolkata')`, so an APP_TIMEZONE that ever drifted
     * from IST would have the weekly and monthly runs looking for a row dated
     * one day from the one that was written, and defer for ever with nothing
     * failing anywhere. Same shape as F125.
     */
    public function defaultPeriodDate(): Carbon
    {
        return $this->periodRelativeTo(Carbon::today('Asia/Kolkata'));
    }

    /**
     * Which period this engine works on when it fires on a given calendar day.
     *
     * `defaultPeriod` already declares the relationship — "the monthly payout
     * run on the 1st settles the current month, the rank bonus settles the
     * previous one" — so the replay reads that declaration instead of
     * re-deciding it. Same mapping, two reference points: today for an
     * operator's default, the replayed date for a historical replay.
     */
    public function periodRelativeTo(Carbon $reference): Carbon
    {
        return match ($this->defaultPeriod) {
            'today' => $reference->copy()->startOfDay(),
            'current-month' => $reference->copy()->startOfMonth(),
            'prev-month' => $reference->copy()->startOfMonth()->subMonthNoOverflow(),
            default => throw new InvalidArgumentException("Unknown default period [{$this->defaultPeriod}]."),
        };
    }

    /**
     * Which period a scheduled fire ON this calendar date produces.
     *
     * The twin of {@see periodRelativeTo()}, and the one a replay must use: the
     * GSB cut-off fires at 00:10 on D + 1 and processes D, so a replay that
     * asked periodRelativeTo() fired every cut-off a day early and left the next
     * real scheduled run to trip the carry-forward out-of-order guard (F125).
     */
    public function periodForFireOn(Carbon $fireDay): Carbon
    {
        $period = $this->periodRelativeTo($fireDay);

        return $this->cadence->previousDay ? $period->subDay() : $period;
    }

    /**
     * Parse a user- or job-supplied period string into a normalised Carbon:
     * start of day for date engines, first day of the month for month engines.
     */
    public function parsePeriod(string $period): Carbon
    {
        $trimmed = trim($period);

        if ($this->periodType === EnginePeriodType::Month) {
            if (preg_match('/^\d{4}-\d{2}$/', $trimmed) !== 1) {
                throw new InvalidArgumentException("Period [{$period}] is not a valid YYYY-MM month.");
            }

            return Carbon::createFromFormat('Y-m-d', $trimmed.'-01')->startOfDay();
        }

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $trimmed) !== 1) {
            throw new InvalidArgumentException("Period [{$period}] is not a valid YYYY-MM-DD date.");
        }

        return Carbon::createFromFormat('Y-m-d', $trimmed)->startOfDay();
    }

    /** Render a normalised period back into the string the command option expects. */
    public function formatPeriod(Carbon $period): string
    {
        return $period->format($this->periodType->inputFormat());
    }

    /** Human label for a period, used in flash messages and the run log. */
    public function displayPeriod(Carbon $period): string
    {
        return $this->periodType === EnginePeriodType::Month
            ? $period->format('M Y')
            : $period->format('d M Y');
    }

    /** `period_start` is the date itself, or the first day of the month. */
    public function periodStart(Carbon $period): Carbon
    {
        return $this->periodType === EnginePeriodType::Month
            ? $period->copy()->startOfMonth()
            : $period->copy()->startOfDay();
    }
}
