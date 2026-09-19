<?php

declare(strict_types=1);

namespace App\Modules\Compensation\Support;

use App\Modules\Compensation\Models\EngineRun;
use App\Modules\Compensation\Services\RunClockService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * When a scheduled run last fired and when it fires next — as dates and times,
 * not as a rule the reader has to apply.
 *
 * Every admin surface that names a run as a deadline said "00:05 IST" and
 * stopped there. Which 00:05 is the whole question an operator has at 09:00
 * with a failed cut-off in front of them: the one that has already been and
 * closed the window, or the one tonight that is about to. This answers it in
 * one place, so the three pages that ask cannot drift into three wordings.
 *
 * Built by {@see RunClockService}, which is
 * what knows the run log and whether the scheduler is running at all.
 */
final readonly class RunClock
{
    /**
     * The scheduler's timezone. Every instant here is rendered with "IST"
     * printed next to it, so it is converted rather than trusted: an
     * APP_TIMEZONE that ever drifted would otherwise make the label a lie.
     */
    private const TIMEZONE = 'Asia/Kolkata';

    public function __construct(
        public EngineDefinition $definition,
        public ?EngineRun $lastRun,
        public ?Carbon $nextRunAt,
        /** True when this environment's scheduler is held — a standing projection, or a replay in flight. */
        public bool $schedulerPaused = false,
    ) {}

    /**
     * "Nightly Run", not "Nightly Run (repurchase + cut-off)" — the
     * parenthetical is a card title, not something to read mid-sentence.
     */
    public function name(): string
    {
        return Str::before($this->definition->label, ' (');
    }

    /** "19 Sep 2026, 00:05 IST", or why there is no instant to show. */
    public function lastRunLabel(): string
    {
        return $this->lastRun === null
            ? 'not yet recorded'
            : self::instant($this->lastRun->started_at);
    }

    /** Stale beats the stored status: a run still `running` hours later is a dead process, not a live one. */
    public function lastRunStatus(): string
    {
        return match (true) {
            $this->lastRun === null => 'never run',
            $this->lastRun->isStale() => 'stale',
            default => $this->lastRun->status,
        };
    }

    public function lastRunPillClasses(): string
    {
        return match ($this->lastRunStatus()) {
            EngineRun::STATUS_SUCCEEDED => 'bg-green-100 text-green-700',
            EngineRun::STATUS_FAILED => 'bg-red-100 text-red-700',
            EngineRun::STATUS_RUNNING => 'bg-blue-100 text-blue-700',
            'stale' => 'bg-amber-100 text-amber-700',
            default => 'bg-gray-100 text-gray-600',
        };
    }

    /** The period that run worked on — for the nightly run, the night it belongs to. */
    public function lastRunPeriodLabel(): ?string
    {
        return $this->lastRun === null
            ? null
            : $this->definition->displayPeriod($this->lastRun->period_start);
    }

    /** "20 Sep 2026, 00:05 IST", or why no instant can be promised. */
    public function nextRunLabel(): string
    {
        if ($this->schedulerPaused) {
            return 'held while this environment is replaying';
        }

        return $this->nextRunAt === null
            ? 'not scheduled — manual only'
            : self::instant($this->nextRunAt);
    }

    /**
     * "15 hours from now" — the distance, for the reader deciding whether to
     * act tonight or leave it.
     *
     * Deliberately argument-less: given another instant Carbon compares the two
     * and says "7 hours after", which reads as a fact about a past pair of
     * times rather than a countdown.
     */
    public function nextRunRelative(): ?string
    {
        return $this->schedulerPaused || $this->nextRunAt === null
            ? null
            : $this->nextRunAt->diffForHumans();
    }

    /**
     * When this next fires AFTER a given instant — "the day now in flight ends
     * at 23:59, so which run works it?".
     */
    public function firesAfter(Carbon $instant): ?Carbon
    {
        return $this->definition->nextRunAfter($instant);
    }

    /** The same instant as a label, for copy that quotes a specific fire. */
    public function firesAfterLabel(Carbon $instant): string
    {
        $at = $this->firesAfter($instant);

        return $at === null ? 'not scheduled — manual only' : self::instant($at);
    }

    /**
     * One plain sentence, for a help tip or a confirm dialog — both of which
     * are HTML attributes and can hold no markup.
     */
    public function sentence(): string
    {
        $last = $this->lastRun === null
            ? sprintf('The %s has no recorded run yet', $this->name())
            : sprintf('The %s last ran on %s (%s)', $this->name(), $this->lastRunLabel(), $this->lastRunStatus());

        return sprintf('%s; the next one is %s.', $last, $this->nextRunLabel());
    }

    private static function instant(Carbon $at): string
    {
        return $at->copy()->timezone(self::TIMEZONE)->format('d M Y, H:i').' IST';
    }
}
