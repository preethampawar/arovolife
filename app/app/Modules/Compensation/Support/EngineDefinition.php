<?php

declare(strict_types=1);

namespace App\Modules\Compensation\Support;

use Illuminate\Support\Carbon;
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
     */
    public function scheduleText(): string
    {
        return $this->cadence->describe();
    }

    /** The period an operator most likely wants, matching the command's own default. */
    public function defaultPeriodDate(): Carbon
    {
        return $this->periodRelativeTo(Carbon::today());
    }

    /**
     * Which period this engine works on when it fires on a given calendar day.
     *
     * `defaultPeriod` already declares the relationship — "the monthly payout
     * run on the 9th settles the current month, the rank bonus settles the
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
