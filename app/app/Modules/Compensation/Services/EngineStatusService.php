<?php

declare(strict_types=1);

namespace App\Modules\Compensation\Services;

use App\Modules\Compensation\Models\AdcBonusResult;
use App\Modules\Compensation\Models\EngineRun;
use App\Modules\Compensation\Models\FortuneMonthlyPool;
use App\Modules\Compensation\Models\GbbMonthlyPool;
use App\Modules\Compensation\Models\GsbCutoffResult;
use App\Modules\Compensation\Models\PayoutBatch;
use App\Modules\Compensation\Models\RankBonusResult;
use App\Modules\Compensation\Models\RankQualification;
use App\Modules\Compensation\Support\EnginePeriodType;
use App\Modules\Compensation\Support\EngineRegistry;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Answers "has this engine already run for this period?".
 *
 * The primary proof is a succeeded `engine_runs` row. That table only starts
 * accumulating history from the day this feature ships, so every engine also
 * has a data-derived fallback — the result rows it would have written. The
 * fallback is what stops the dependency resolver from re-running months of
 * cut-offs on day one; once the run log has history it dominates, because it
 * can also tell "ran and produced nothing" from "never ran".
 */
final class EngineStatusService
{
    /**
     * The daily run — one of the three scheduled orchestrators
     * ({@see EngineRegistry::rootOrchestratorKeys()}), and the only one whose
     * steps are dated the night it belongs to.
     */
    public const CHAIN_KEY = 'compensation.nightly-run';

    /**
     * @param  Carbon  $period  Normalised by the caller: the date, or the first of the month.
     */
    public function isPeriodComputed(string $key, Carbon $period): bool
    {
        if ($this->hasSucceededRun($key, $period)) {
            return true;
        }

        return $this->hasDerivedProof($key, $period);
    }

    /**
     * The nightly run's last finished attempt, when that attempt FAILED.
     *
     * Deliberately "the latest attempt", not "the oldest unhealed failure". A
     * night that fails is not a night that is lost: the next nightly run
     * backfills the cut-offs it missed, so once any later night has exited 0
     * the gap is closed and a banner pointing at the old failure would be
     * telling an operator about something that has already healed itself.
     *
     * RUNNING rows are excluded — a run in flight is not a failure — and so are
     * SKIPPED ones: a preflight refusal or a deferral is a decision (a stale
     * worker, a standing projection, a month waiting on tonight's runs), and
     * nothing would be repaired by reporting it as a breakage. Those surface
     * through {@see EngineHealthService} run alerts, which say what is actually
     * being waited for.
     */
    public function failedChainRun(): ?EngineRun
    {
        return $this->failedRootRun(self::CHAIN_KEY);
    }

    /**
     * The same question for any root orchestrator: the nightly, weekly or
     * monthly run's last finished attempt, when that attempt FAILED.
     *
     * Parameterised rather than copied per run, because the rule that makes the
     * answer honest — latest attempt, `running` and `skipped` excluded — is the
     * part that would drift if it were written three times.
     */
    public function failedRootRun(string $key): ?EngineRun
    {
        $latest = EngineRun::query()
            ->where('engine_key', $key)
            ->whereIn('status', [EngineRun::STATUS_SUCCEEDED, EngineRun::STATUS_FAILED])
            ->orderByDesc('id')
            ->first();

        return $latest?->status === EngineRun::STATUS_FAILED ? $latest : null;
    }

    /**
     * The steps of a failed nightly run, in the order it invoked them, with
     * what each one did.
     *
     * Read from the step engines' own `engine_runs` rows rather than from the
     * run's summary: the run aborts at the first non-zero exit and never
     * reaches the steps after it, so the only honest account of which engines
     * ran is the rows they wrote themselves.
     *
     * Scoped two ways, and both are needed. By ENGINE, to the nightly run's own
     * steps — the weekly and monthly runs write rows on the same night, and
     * listing those under "what each step did" would credit the nightly run
     * with work it never touched. And by the failed run's own WINDOW rather
     * than by period: a cut-off is dated night − 1, and every backfilled day is
     * dated earlier still, so a period filter could never show the one step
     * whose failure the banner exists to explain. The window also excludes a
     * hand-typed re-run of the same engine an hour later, which is a different
     * attempt and belongs to no run row here.
     *
     * Each label carries its step's own period, so a night that backfilled four
     * days reads as four dated lines rather than four identical ones.
     *
     * @return list<array{label: string, status: string, error: string|null}>
     */
    public function chainStepOutcomes(EngineRun $run): array
    {
        $stepKeys = [];

        foreach (EngineRegistry::all() as $key => $definition) {
            if ($definition->orchestratedBy === self::CHAIN_KEY) {
                $stepKeys[] = $key;
            }
        }

        if ($stepKeys === [] || $run->started_at === null) {
            return [];
        }

        $rows = EngineRun::query()
            ->whereIn('engine_key', $stepKeys)
            ->where('started_at', '>=', $run->started_at)
            ->where('started_at', '<=', $run->finished_at ?? Carbon::now())
            ->orderBy('id')
            ->get();

        $steps = [];

        foreach ($rows as $step) {
            $key = (string) $step->engine_key;

            if (! EngineRegistry::has($key)) {
                continue;
            }

            $definition = EngineRegistry::get($key);

            $steps[] = [
                'label' => sprintf(
                    '%s — %s',
                    $definition->label,
                    $definition->displayPeriod($step->period_start),
                ),
                'status' => (string) $step->status,
                'error' => is_string($step->error) ? $step->error : null,
            ];
        }

        return $steps;
    }

    /**
     * A succeeded run recorded in the run log that is proven to have seen the
     * WHOLE period — i.e. one that started after the period had ended.
     *
     * The date alone is not a sufficient key. Every engine here freezes its
     * period's economics the first time it runs and credits from that snapshot,
     * so a run stamped INSIDE its own period priced a partial period: September
     * frozen on the 5th is priced on five days of BV. Accepting such a row as
     * "this period is done" is worse than not having it — the monthly close
     * then resumes past the step and never reprices the month, and the payout
     * gate a week later reads the same row as a completed month and pays on it
     * (staging, 05 Sep 2026: all seven September steps recorded succeeded by a
     * mid-month recompute, 12.3 % short and silently permanent).
     *
     * Same rule as {@see hasSucceededRunAfterDay()}, one period type wider: the
     * boundary is the app-timezone midnight that opens the next day for a
     * date-typed engine and the next month for a month-typed one.
     *
     * THE LATEST FINISHED ATTEMPT DECIDES (D13). A failed re-run un-proves the
     * period: a rebuild that wiped the period's rows and then failed must not
     * read as done, or the resume logic skips past a step whose results no
     * longer exist and the payout gate a week later pays on nothing. `skipped`
     * and `running` rows are not attempts and are ignored — a preflight refusal
     * decided nothing about the period, and a run in flight has not finished
     * deciding.
     */
    public function hasSucceededRun(string $key, Carbon $period): bool
    {
        $latest = $this->latestFinishedRun($key, $period);

        return $latest !== null
            && $latest->status === EngineRun::STATUS_SUCCEEDED
            && $latest->started_at !== null
            && $latest->started_at->greaterThanOrEqualTo(self::periodEndsAt($key, $period));
    }

    /**
     * The most recent run of $key for $period that actually finished — the one
     * row D13's rule is read from.
     *
     * Ordered by `started_at` and then `id`, so two attempts inside the same
     * second still resolve to the later one.
     *
     * Public because {@see RunPrerequisites} has to name the attempt it
     * refused on, and that is this row and no other: a diagnosis read from
     * {@see self::lastRun()} would reach back to another period's row and tell
     * an operator about a night nobody asked about. One query, one rule.
     */
    public function latestFinishedRun(string $key, Carbon $period): ?EngineRun
    {
        return EngineRun::query()
            ->where('engine_key', $key)
            ->whereDate('period_start', $period->toDateString())
            ->whereIn('status', [EngineRun::STATUS_SUCCEEDED, EngineRun::STATUS_FAILED])
            ->orderByDesc('started_at')
            ->orderByDesc('id')
            ->first();
    }

    /**
     * Did $key succeed for TONIGHT — a run dated $night whose latest finished
     * attempt succeeded and started after the night began?
     *
     * The ordering guard between the three cadences reads this
     * ({@see RunPrerequisites}). "Started after the night began" is what
     * separates tonight's process from a replay of that date typed by hand a
     * week later: the client's rule is about the run that has just finished,
     * not about the date being covered.
     */
    public function hasSucceededRunTonight(string $key, Carbon $night): bool
    {
        $latest = $this->latestFinishedRun($key, $night);

        return $latest !== null
            && $latest->status === EngineRun::STATUS_SUCCEEDED
            && $latest->started_at !== null
            && $latest->started_at->greaterThanOrEqualTo($night->copy()->startOfDay());
    }

    /**
     * The first instant AFTER the period $key works on — the next day for a
     * date engine, the first of the next month for a month engine.
     *
     * An unregistered key is treated as date-typed: the only callers are
     * registry keys, and a day boundary is the narrower assumption.
     */
    public static function periodEndsAt(string $key, Carbon $period): Carbon
    {
        return EngineRegistry::has($key)
            && EngineRegistry::get($key)->periodType === EnginePeriodType::Month
                ? $period->copy()->startOfMonth()->addMonthNoOverflow()
                : $period->copy()->startOfDay()->addDay();
    }

    /**
     * A succeeded run for this engine whose period is $period or later.
     *
     * "Or later" is what makes this the right question for a dependency that
     * stamps state forward rather than per-period: `repurchase:evaluate --date=D`
     * resolves every cycle due up to D, so a run for D + 1 has already answered
     * everything day D can ask. Compared by DATE — the hour a run started is
     * irrelevant to which day it evaluated.
     */
    public function hasSucceededRunOnOrAfter(string $key, Carbon $period): bool
    {
        return EngineRun::query()
            ->where('engine_key', $key)
            ->whereDate('period_start', '>=', $period->toDateString())
            ->where('status', EngineRun::STATUS_SUCCEEDED)
            ->exists();
    }

    /**
     * A succeeded run for this engine that is proven to have SEEN all of $day.
     *
     * `hasSucceededRunOnOrAfter()` is not enough for the GSB cut-off. The
     * scheduled `repurchase:evaluate --date=D` runs at 00:05 ON D, so it cannot
     * have seen a fulfilment purchase made later that same day; accepting it
     * would let the cut-off for D forfeit a day the distributor actually
     * fulfilled — permanently, since the forfeit is never corrected. Accepted
     * proof is therefore either a run for a LATER date, or a run dated D that
     * started after D had ended (a re-run, a manual trigger, or the Engine Runs
     * chain filling the gap the next morning).
     *
     * `started_at` is stamped with `Carbon::now()` in the app timezone, so the
     * boundary is the app-timezone midnight opening D + 1.
     */
    public function hasSucceededRunAfterDay(string $key, Carbon $day): bool
    {
        return EngineRun::query()
            ->where('engine_key', $key)
            ->where('status', EngineRun::STATUS_SUCCEEDED)
            ->where(self::sawWholeDay($day))
            ->exists();
    }

    /**
     * The most recent run of $key that could have seen all of $day, whatever
     * its status — the diagnostic companion to
     * {@see hasSucceededRunAfterDay()}.
     *
     * When that gate says no, the operator's next question is always "why" and
     * the answer is on the run itself: a `repurchase.evaluate` run that threw
     * on two distributors names them in its summary, and that is the difference
     * between a five-minute fix and a platform-wide cut-off stalled overnight.
     */
    public function latestRunAfterDay(string $key, Carbon $day): ?EngineRun
    {
        return EngineRun::query()
            ->where('engine_key', $key)
            ->where(self::sawWholeDay($day))
            ->orderByDesc('started_at')
            ->orderByDesc('id')
            ->first();
    }

    /**
     * A run for a LATER date, or a run dated $day that started after $day had
     * ended — the two shapes that prove the whole day was visible to it.
     */
    private static function sawWholeDay(Carbon $day): \Closure
    {
        $dayEnds = $day->copy()->startOfDay()->addDay();

        return function (Builder $query) use ($day, $dayEnds): void {
            $query->whereDate('period_start', '>', $day->toDateString())
                ->orWhere(function (Builder $sameDay) use ($day, $dayEnds): void {
                    $sameDay->whereDate('period_start', '>=', $day->toDateString())
                        ->where('started_at', '>=', $dayEnds->toDateTimeString());
                });
        };
    }

    /**
     * True when the engine has a live run in flight — either one this process
     * knows about or a cron run that started moments ago. `running` rows older
     * than the staleness cutoff are treated as abandoned, not live.
     *
     * `$exceptRunId` excludes ONE row: a command that is itself a registry entry
     * already has its own `running` row by the time it asks this, and a rebuild
     * refusing because it can see itself would refuse every time
     * ({@see RebuildPreflight}). Every other caller asks about a different
     * engine and leaves it null.
     */
    public function hasRunInFlight(string $key, ?int $exceptRunId = null): bool
    {
        return EngineRun::query()
            ->where('engine_key', $key)
            ->where('status', EngineRun::STATUS_RUNNING)
            ->where('started_at', '>=', Carbon::now()->subMinutes(EngineRun::STALE_AFTER_MINUTES))
            ->when($exceptRunId !== null, fn (Builder $query): Builder => $query->whereKeyNot($exceptRunId))
            ->exists();
    }

    /**
     * Failed runs whose period has not since been rebuilt — a failed run with no
     * succeeded run for the same engine and period after it.
     *
     * Until this existed nothing in the platform read STATUS_FAILED at all: a
     * month in which an engine crashed looked exactly like a month in which one
     * did not, until somebody happened to open the Engine Runs page. It feeds
     * the admin sidebar badge, and it clears itself the moment the engine is
     * re-run successfully.
     */
    public function unresolvedFailureCount(int $withinDays = 30): int
    {
        return $this->unresolvedFailureQuery(Carbon::now()->subDays($withinDays))->count();
    }

    /**
     * The same failures the badge counts, as rows — newest first.
     *
     * The daily health digest needs the engine, the period and the recorded
     * error, not a number, and the two must never disagree about what counts as
     * unresolved: both read {@see unresolvedFailureQuery()}.
     *
     * @return Collection<int, EngineRun>
     */
    public function unresolvedFailures(int $withinDays = 30): Collection
    {
        return $this->unresolvedFailureQuery(Carbon::now()->subDays($withinDays))
            ->orderByDesc('started_at')
            ->orderByDesc('id')
            ->get();
    }

    /**
     * Runs still marked `running` long after they started — the process died
     * without ever writing an outcome, so nothing else in the platform reports
     * them: they are neither a failure nor a success.
     *
     * @return Collection<int, EngineRun>
     */
    public function stuckRuns(int $olderThanHours = 3): Collection
    {
        return EngineRun::query()
            ->where('status', EngineRun::STATUS_RUNNING)
            ->where('started_at', '<', Carbon::now()->subHours($olderThanHours))
            ->orderBy('started_at')
            ->orderBy('id')
            ->get();
    }

    /**
     * A failure is unresolved until a LATER SUCCESS of the same engine covers
     * it — for a leaf engine, a later success for the same period.
     *
     * D5: for a root orchestrator the period equality is dropped, because the
     * period of one of those runs is a NIGHT, and a night is never re-run. The
     * nightly run backfills the cut-offs a failed night missed; the weekly and
     * monthly runs re-attempt what they owe every night. So the first night any
     * of them exits 0 is the night the earlier failure stopped being anything
     * to act on — while the per-period rule would keep 8 September's failed run
     * in the digest for thirty days, with no run of that date ever coming to
     * resolve it (staging, since 8 Sep 2026).
     *
     * A leaf is the opposite case and keeps the period: a cut-off that failed
     * for the 12th is still owed however many later days succeed.
     *
     * @return Builder<EngineRun>
     */
    private function unresolvedFailureQuery(Carbon $since): Builder
    {
        $rootKeys = EngineRegistry::rootOrchestratorKeys();

        return EngineRun::query()
            ->where('status', EngineRun::STATUS_FAILED)
            ->where('started_at', '>=', $since)
            ->whereNotExists(function ($query) use ($since, $rootKeys): void {
                $query->select(DB::raw(1))
                    ->from('engine_runs as later')
                    ->whereColumn('later.engine_key', 'engine_runs.engine_key')
                    ->where(function ($scope) use ($rootKeys): void {
                        $scope->whereColumn('later.period_start', 'engine_runs.period_start')
                            ->orWhereIn('engine_runs.engine_key', $rootKeys);
                    })
                    ->whereColumn('later.started_at', '>=', 'engine_runs.started_at')
                    ->where('later.status', EngineRun::STATUS_SUCCEEDED)
                    ->where('later.started_at', '>=', $since);
            });
    }

    public function lastRun(string $key): ?EngineRun
    {
        return EngineRun::query()
            ->where('engine_key', $key)
            ->orderByDesc('started_at')
            ->orderByDesc('id')
            ->first();
    }

    /**
     * Latest run per engine in ONE query — the Engine Runs index lists all ten
     * and must not fan out into ten (or, with the actor, twenty) round trips.
     *
     * @param  list<string>  $keys
     * @return array<string, EngineRun>
     */
    public function lastRunPerEngine(array $keys): array
    {
        if ($keys === []) {
            return [];
        }

        return EngineRun::query()
            ->with('actor:id,full_name,email')
            ->whereIn('engine_key', $keys)
            ->orderByDesc('started_at')
            ->orderByDesc('id')
            ->get()
            ->reduce(function (array $carry, EngineRun $run): array {
                $carry[$run->engine_key] ??= $run;

                return $carry;
            }, []);
    }

    /**
     * Bootstrap fallback for the index page: the newest period for which the
     * engine's own result tables prove it ran, when the run log has no history.
     */
    public function lastComputedPeriod(string $key): ?Carbon
    {
        $latest = match ($key) {
            'gsb.daily-cutoff' => GsbCutoffResult::query()->max('cutoff_date'),
            'gsb.weekly-payout' => PayoutBatch::query()
                ->where('batch_type', PayoutBatch::TYPE_WEEKLY)->max('batch_date'),
            'payout.monthly' => PayoutBatch::query()
                ->where('batch_type', PayoutBatch::TYPE_MONTHLY)->max('batch_date'),
            'gbb.monthly' => GbbMonthlyPool::query()->max('month_start'),
            'rank.check' => RankQualification::query()->max('month_start'),
            'rank.bonus' => RankBonusResult::query()->max('month_start'),
            'fortune.enroll', 'fortune.payout' => FortuneMonthlyPool::query()->max('month_start'),
            'adc.bonus' => AdcBonusResult::query()->max('month_start'),
            // repurchase:evaluate mutates cycle rows in place and leaves no
            // per-run trace, so there is nothing to derive — the run log is the
            // only evidence it ever ran.
            default => null,
        };

        return is_string($latest) && $latest !== ''
            ? EngineRegistry::get($key)->periodStart(Carbon::parse($latest))
            : null;
    }

    /**
     * Every day in the inclusive range whose cut-off is proven COMPLETE.
     *
     * The stricter twin of {@see computedCutoffDatesBetween()}, and the one to
     * ask when the answer decides whether money may be frozen. That method
     * counts a day as done if ANY `gsb_cutoff_results` row exists for it — the
     * right question for "has this day been started, so a dependency can
     * proceed", and the wrong one here, because the cut-off commits per
     * distributor: a run that died half way through leaves a partial day that
     * looks finished. It also counts a succeeded run stamped INSIDE its own day
     * (an admin retry at noon, a recompute), which cannot have seen the
     * evening's sales.
     *
     * This one reads the run log alone and applies the same period-end rule as
     * {@see hasSucceededRun()}: a succeeded run that started after the day it
     * cut off had ended. Filtered in PHP rather than in SQL because the
     * comparison is between two columns a day apart, and the range is capped at
     * about a month by every caller.
     *
     * THE LATEST FINISHED ATTEMPT DECIDES AT THE FRONTIER ONLY (D13 + A6).
     *
     * D13 exists for one shape: a night that was wiped and then failed to
     * rebuild must read "not computed", or the month is closed over rows that
     * no longer exist. That shape can only occur on the NEWEST day — the
     * rebuild refuses a day any later cut-off has passed (D11/R-91), because
     * the carry-forward store is rolling and cannot be rewound one day at a
     * time. So behind the frontier a failed latest attempt destroyed nothing:
     * the day's rows are intact, the earlier success still describes them, and
     * the day stays listed.
     *
     * Applying the latest-attempt rule everywhere would hand anyone who can
     * trigger an engine (`finance.record`, from the Engine Runs page) a way to
     * block a month's crediting for ever by re-running a past day, whose
     * re-run cannot succeed — the same R-91 guard refuses it — leaving
     * `--force` as the only way to pay a month nothing was wrong with. Refusal
     * is the fail-safe answer only where a refusal can still be cleared.
     *
     * A day is therefore listed when its cut-off was ever proven AND either its
     * latest attempt still proves it, or some LATER day is proven — inside the
     * range or beyond it. The invariant is about the carry-forward store, not
     * about the window the caller asked about: a later proven day means the
     * store has advanced past this one, which means R-91 forbids rebuilding it,
     * which means its rows are intact. Bounding the frontier by `$to` would
     * make a genuinely failed re-run of a month's LAST day unclearable — its
     * proven successor is in the next month, so the month would read short, and
     * re-running that day only yields `skipped` (A1), which never displaces the
     * `failed` row. `--force` would be the only way out of a month nothing was
     * wrong with.
     *
     * `skipped` and `running` rows are not attempts: a preflight or ordering
     * refusal (A1) decided nothing about the period, and a run in flight has
     * not finished deciding.
     *
     * @return list<string> Y-m-d
     */
    public function completedCutoffDatesBetween(Carbon $from, Carbon $to): array
    {
        $runs = EngineRun::query()
            ->where('engine_key', 'gsb.daily-cutoff')
            ->whereIn('status', [EngineRun::STATUS_SUCCEEDED, EngineRun::STATUS_FAILED])
            ->whereBetween('period_start', [$from->toDateString(), $to->copy()->endOfDay()->toDateTimeString()])
            ->orderBy('started_at')
            ->orderBy('id')
            ->get(['id', 'period_start', 'status', 'started_at']);

        /** @var array<string, bool> $everProven day => some attempt proved it */
        $everProven = [];
        /** @var array<string, bool> $latestProves day => its latest attempt proves it */
        $latestProves = [];

        // Ascending, so the last row read for a day is that day's latest attempt.
        foreach ($runs as $run) {
            $day = $run->period_start->toDateString();
            $proves = self::runProvesDay($run);

            $everProven[$day] = ($everProven[$day] ?? false) || $proves;
            $latestProves[$day] = $proves;
        }

        $frontier = null;

        foreach ($latestProves as $day => $proves) {
            if ($proves && ($frontier === null || $day > $frontier)) {
                $frontier = (string) $day;
            }
        }

        $completed = [];
        $provenBeyondRange = null;

        foreach ($everProven as $day => $proven) {
            if (! $proven) {
                continue;
            }

            $day = (string) $day;

            if ($latestProves[$day] || ($frontier !== null && $day < $frontier)) {
                $completed[] = $day;

                continue;
            }

            // The day is the newest proven one INSIDE the range, and its latest
            // attempt failed. Asked once, lazily, because the answer is the same
            // for every such day and most ranges never need it at all.
            $provenBeyondRange ??= $this->provenCutoffExistsAfter($to);

            if ($provenBeyondRange) {
                $completed[] = $day;
            }
        }

        sort($completed);

        return $completed;
    }

    /**
     * Is any cut-off day after $day proven, by the same latest-attempt rule?
     *
     * What makes the frontier a frontier is the carry-forward store, which is
     * platform-wide and knows nothing about the range a caller asked about. A
     * month's last day whose successor — the 1st of the next month — is cut off
     * is behind the frontier exactly as any mid-month day is.
     *
     * Bounded by the calendar rather than by a limit: the days after $day are
     * the days since the period the caller is asking about ended, which for
     * every caller is a closed month plus however long ago it closed.
     */
    private function provenCutoffExistsAfter(Carbon $day): bool
    {
        $latestPerDay = EngineRun::query()
            ->where('engine_key', 'gsb.daily-cutoff')
            ->whereIn('status', [EngineRun::STATUS_SUCCEEDED, EngineRun::STATUS_FAILED])
            ->whereDate('period_start', '>', $day->toDateString())
            ->orderBy('started_at')
            ->orderBy('id')
            ->get(['id', 'period_start', 'status', 'started_at'])
            // Ascending, so the last row read for a day is that day's latest attempt.
            ->keyBy(fn (EngineRun $run): string => $run->period_start->toDateString());

        return $latestPerDay->contains(fn (EngineRun $run): bool => self::runProvesDay($run));
    }

    /**
     * Does this run prove its own day — a success that started after the day it
     * cut off had ended?
     *
     * The period-end rule of {@see hasSucceededRun()}, applied per row: a
     * succeeded run stamped inside its own day (an admin retry at noon, a
     * recompute) cannot have seen the evening's sales.
     */
    private static function runProvesDay(EngineRun $run): bool
    {
        return $run->status === EngineRun::STATUS_SUCCEEDED
            && $run->started_at !== null
            && $run->started_at->greaterThanOrEqualTo($run->period_start->copy()->startOfDay()->addDay());
    }

    /**
     * Has a payout batch already been built for this date?
     *
     * Derived proof, deliberately: a batch is the thing that exists, and the
     * run log is not the record of it. The batch runners are idempotent per
     * date, but only up to a point — once finance has APPROVED a batch, a
     * re-run returns it untouched and the command reports FAILURE, because a
     * batch waiting for the bank is not a batch anyone may add lines to. An
     * orchestrator that re-invokes it would abort its own chain over a batch
     * that is not merely fine but already signed off.
     */
    public function payoutBatchExists(string $batchType, Carbon $batchDate): bool
    {
        return PayoutBatch::query()
            ->where('batch_type', $batchType)
            ->whereDate('batch_date', $batchDate->toDateString())
            ->exists();
    }

    /**
     * Every day in the inclusive range for which a GSB cut-off is proven done.
     * One query per source, so a month-wide dependency check stays at two
     * queries regardless of how many days are missing.
     *
     * @return list<string> Y-m-d
     */
    public function computedCutoffDatesBetween(Carbon $from, Carbon $to): array
    {
        $fromDate = $from->toDateString();
        $toDate = $to->toDateString();

        $fromRunLog = EngineRun::query()
            ->where('engine_key', 'gsb.daily-cutoff')
            ->where('status', EngineRun::STATUS_SUCCEEDED)
            ->whereBetween('period_start', [$fromDate, $to->copy()->endOfDay()->toDateTimeString()])
            ->pluck('period_start')
            ->map(fn (Carbon $date): string => $date->toDateString());

        $fromResults = GsbCutoffResult::query()
            ->whereBetween('cutoff_date', [$fromDate, $toDate])
            ->distinct()
            ->pluck('cutoff_date')
            ->map(fn (Carbon $date): string => $date->toDateString());

        return array_values($fromRunLog->merge($fromResults)->unique()->sort()->all());
    }

    /** The engine's own result rows for a period — the pre-run-log fallback. */
    private function hasDerivedProof(string $key, Carbon $period): bool
    {
        $date = $period->toDateString();

        return match ($key) {
            // Authoritative day-level source: AdminDailyCutoffController lists a
            // day by `cutoff_date` on gsb_cutoff_results, so a row for the day
            // is exactly what "this day was cut off" means everywhere else.
            'gsb.daily-cutoff' => GsbCutoffResult::query()
                ->whereDate('cutoff_date', $date)->exists(),

            // Any status counts: a pending batch still means the engine ran.
            'gsb.weekly-payout' => $this->batchExists(PayoutBatch::TYPE_WEEKLY, $period, monthWide: false),
            'payout.monthly' => $this->batchExists(PayoutBatch::TYPE_MONTHLY, $period, monthWide: true),

            'gbb.monthly' => GbbMonthlyPool::query()->whereDate('month_start', $date)->exists(),
            'rank.check' => RankQualification::query()->whereDate('month_start', $date)->exists(),
            'rank.bonus' => RankBonusResult::query()->whereDate('month_start', $date)->exists(),
            'adc.bonus' => AdcBonusResult::query()->whereDate('month_start', $date)->exists(),

            // A frozen Fortune pool means enrolment is closed for the month —
            // FortuneBonusService::enrollEligible() refuses with
            // `refused_pool_frozen` — so "pool exists" reads as "enrolment done",
            // never as a failure to chase.
            'fortune.enroll', 'fortune.payout' => FortuneMonthlyPool::query()
                ->whereDate('month_start', $date)->exists(),

            default => false,
        };
    }

    private function batchExists(string $type, Carbon $period, bool $monthWide): bool
    {
        return PayoutBatch::query()
            ->where('batch_type', $type)
            ->when(
                $monthWide,
                fn (Builder $query): Builder => $query->whereBetween('batch_date', [
                    $period->copy()->startOfMonth()->toDateString(),
                    $period->copy()->endOfMonth()->toDateString(),
                ]),
                fn (Builder $query): Builder => $query->whereDate('batch_date', $period->toDateString()),
            )
            ->exists();
    }
}
