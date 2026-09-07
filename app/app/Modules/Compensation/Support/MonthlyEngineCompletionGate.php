<?php

declare(strict_types=1);

namespace App\Modules\Compensation\Support;

use App\Modules\Compensation\Models\EngineRun;
use Illuminate\Support\Carbon;
use Laravel\Pennant\Feature;
use Throwable;

/**
 * Precondition for the monthly PAYOUT batch: every crediting engine for the
 * month has actually succeeded.
 *
 * The generalisation of {@see RankQualificationsGate}. That gate asks one
 * question — did `rank:check-qualifications` succeed — because the engines
 * behind it read an empty `rank_qualifications` table as a valid answer. The
 * payout batch has the same failure shape one layer out: it sweeps whatever
 * wallet credits happen to exist, so a month in which Fortune crashed pays out
 * cleanly, is marked processed, and the missing credits have nowhere left to go
 * — the batch is idempotent and will not re-sweep a month it has already paid.
 *
 * Hence the week between crediting (the 1st) and payment (the 8th), and hence
 * this gate: the buffer is only worth having if something refuses to pay when
 * the month is incomplete.
 *
 * Three outcomes per engine, and the difference between the last two is the
 * whole point:
 *   • SUCCEEDED (and no unresolved failure) — the engine ran and finished.
 *   • SKIPPED because its feature flag is off — the engine cannot compute
 *     anything and never will while the flag is off, so it can never be the
 *     reason a month is incomplete. It does NOT block.
 *   • FAILED with no later SUCCEEDED run for the same period — the engine
 *     stopped part-way. It DOES block, and the refusal names it.
 */
final class MonthlyEngineCompletionGate
{
    /**
     * The crediting engines `compensation:monthly-close` runs, in the order it
     * runs them — so the refusal names the earliest failure, which is the one
     * to fix first.
     *
     * @var list<string>
     */
    public const ENGINE_KEYS = [
        'rank.check',
        'rank.bonus',
        'gbb.monthly',
        'fortune.enroll',
        'adc.bonus',
        'fortune.payout',
        'offers.monthly',
    ];

    /**
     * Why the month is not ready to be paid, or null when every crediting
     * engine has succeeded (or is flag-off and therefore not owed a run).
     *
     * @return array{engine_key: string, reason: string, message: string}|null
     */
    public static function blockingFailure(Carbon $month): ?array
    {
        $monthStart = $month->copy()->startOfMonth();
        $runs = self::runsForMonth($monthStart);

        foreach (self::ENGINE_KEYS as $key) {
            $definition = EngineRegistry::get($key);

            // A flag-off engine computes nothing, so it can never be the reason
            // a month is incomplete — and blocking on it would deadlock the
            // payout, because there is no way to make it succeed.
            if (self::featureFlagIsOff($definition)) {
                continue;
            }

            $engineRuns = $runs[$key] ?? [];

            $failure = self::unresolvedFailure($engineRuns);

            if ($failure !== null) {
                return self::describe($definition, $monthStart, 'failed', sprintf(
                    '%s FAILED for %s (run #%d, %s) and has not succeeded since.',
                    $definition->label,
                    $definition->displayPeriod($failure->period_start),
                    $failure->id,
                    $failure->started_at->format('d M Y H:i'),
                ));
            }

            foreach ($engineRuns as $run) {
                if ($run->status === EngineRun::STATUS_SUCCEEDED) {
                    continue 2;
                }
            }

            return self::describe($definition, $monthStart, 'never_succeeded', sprintf(
                '%s has no succeeded run for %s.',
                $definition->label,
                $monthStart->format('F Y'),
            ));
        }

        return null;
    }

    /**
     * Operator-facing refusal: what is wrong, the command that fixes it, and
     * the command to re-run afterwards.
     */
    public static function refusalMessage(Carbon $month, string $engineKey, string $detail): string
    {
        $monthStart = $month->copy()->startOfMonth();
        $definition = EngineRegistry::get($engineKey);

        return sprintf(
            "Monthly payout refused for %s — the month's crediting is incomplete.\n%s\n"
            ."Fix it, then re-run the payout close:\n  php artisan %s %s=%s\n  php artisan compensation:monthly-payout-close --month=%s",
            $monthStart->format('F Y'),
            $detail,
            $definition->commandSignature,
            $definition->periodOption,
            $definition->formatPeriod(self::periodFor($definition, $monthStart)),
            $monthStart->format('Y-m'),
        );
    }

    /**
     * The period of month M this engine works on: the month itself for a
     * month-typed engine (every crediting step is one today), or the month's
     * last day were a date-typed step ever added.
     *
     * This must agree with what `RecordEngineRun` writes as `period_start` for
     * the same invocation, or the close's resume check can never match a run it
     * made itself. The now-retired repurchase snapshot was the cautionary case:
     * it was date-typed and handed the month's LAST day here while the scheduler
     * handed it the FIRST, so the close re-ran it on every resume.
     */
    public static function periodFor(EngineDefinition $definition, Carbon $month): Carbon
    {
        return $definition->periodType === EnginePeriodType::Month
            ? $month->copy()->startOfMonth()
            : $month->copy()->endOfMonth()->startOfDay();
    }

    /**
     * Every run of a crediting engine whose period falls inside the month,
     * oldest first, grouped by engine. One query for the whole gate.
     *
     * A range rather than an exact date so a date-typed step — or a legacy run
     * recorded against the month's last day, as the retired repurchase snapshot
     * was before it became month-typed — is still found.
     *
     * @return array<string, list<EngineRun>>
     */
    private static function runsForMonth(Carbon $monthStart): array
    {
        $grouped = [];

        $runs = EngineRun::query()
            ->whereIn('engine_key', self::ENGINE_KEYS)
            // The upper bound carries a time: `period_start` is written through
            // the model's datetime format, so a run dated the 31st is stored as
            // "…-31 00:00:00" and a bare date bound would exclude it.
            ->whereBetween('period_start', [
                $monthStart->toDateString(),
                $monthStart->copy()->endOfMonth()->endOfDay()->toDateTimeString(),
            ])
            ->orderBy('started_at')
            ->orderBy('id')
            ->get();

        foreach ($runs as $run) {
            $grouped[$run->engine_key][] = $run;
        }

        return $grouped;
    }

    /**
     * A failed run with no succeeded run for the SAME period after it. Re-running
     * the engine is exactly what clears this, which is what the refusal asks for.
     *
     * @param  list<EngineRun>  $runs  Oldest first.
     */
    private static function unresolvedFailure(array $runs): ?EngineRun
    {
        $latest = [];

        foreach ($runs as $run) {
            if ($run->status === EngineRun::STATUS_FAILED) {
                $latest[$run->period_start->toDateString()] = $run;

                continue;
            }

            if ($run->status === EngineRun::STATUS_SUCCEEDED) {
                unset($latest[$run->period_start->toDateString()]);
            }
        }

        return $latest === [] ? null : reset($latest);
    }

    /**
     * @return array{engine_key: string, reason: string, message: string}
     */
    private static function describe(EngineDefinition $definition, Carbon $monthStart, string $reason, string $detail): array
    {
        return [
            'engine_key' => $definition->key,
            'reason' => $reason,
            'message' => self::refusalMessage($monthStart, $definition->key, $detail),
        ];
    }

    private static function featureFlagIsOff(EngineDefinition $definition): bool
    {
        if ($definition->featureFlagClass === null) {
            return false;
        }

        try {
            return ! Feature::for(null)->active($definition->featureFlagClass);
        } catch (Throwable) {
            // Pennant unavailable: assume on rather than wave a month through.
            return false;
        }
    }
}
