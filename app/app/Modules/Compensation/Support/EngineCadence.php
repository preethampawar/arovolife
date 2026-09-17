<?php

declare(strict_types=1);

namespace App\Modules\Compensation\Support;

use Illuminate\Support\Carbon;

/**
 * When an engine is scheduled to run — declared once, structured, and consumed
 * by everything that needs to know.
 *
 * Before this existed the cadence was written in three unrelated forms: a cron
 * registration in routes/console.php, a prose sentence on EngineDefinition, and
 * a `str_starts_with($scheduleText, 'Not scheduled')` sniff in the admin view.
 * A replay that needed "which engines run on this date" would have been a
 * fourth. Now the prose is generated from the structure and the view asks
 * {@see isScheduled()}.
 *
 * routes/console.php deliberately still registers its own cron expressions —
 * that is live production scheduling and is not worth the regression risk to
 * rewire. EngineRegistryTest asserts the two agree.
 *
 * All times are IST (Asia/Kolkata), matching the scheduler's timezone.
 */
final readonly class EngineCadence
{
    private const DAILY = 'daily';

    private const WEEKLY = 'weekly';

    private const MONTHLY = 'monthly';

    private const NONE = 'none';

    private function __construct(
        public string $type,
        /**
         * The clock time this engine fires at — and, for an engine the nightly
         * chain fires, its POSITION IN THE NIGHT rather than a promise about
         * the clock.
         *
         * The chain starts at 00:05 and runs its steps in sequence, so a step
         * declaring 00:10 does not fire at 00:10; it fires when the step before
         * it has exited 0, which is the whole reason the clock offsets were
         * replaced. The declared times are kept because their ORDER is the
         * chain's order, and two readers depend on exactly that: the recompute
         * replay sorts a day's engines by this value, and any change to it
         * would reorder a replayed day's engines and move figures.
         *
         * Nothing renders it as a time for such an engine —
         * {@see EngineDefinition::scheduleText()} defers to the orchestrator —
         * and {@see EngineHealthService} judges it by the orchestrator's window.
         */
        public ?string $time = null,
        /** ISO-8601 day of week, 1 = Monday .. 7 = Sunday. */
        public ?int $dayOfWeek = null,
        public ?int $dayOfMonth = null,
        /** Parenthetical appended to the description, e.g. "runs the previous day". */
        public ?string $note = null,
        /**
         * True for an engine whose scheduled run works the day BEFORE it fires:
         * routes/console.php passes the GSB cut-off `--date=yesterday`.
         *
         * Structured rather than sniffed out of {@see $note}: the replay and the
         * health digest both have to know which period a fire on a given date
         * produces, and reading it off a prose string is how the replay came to
         * fire every cut-off a day early (F125).
         */
        public bool $previousDay = false,
    ) {}

    public static function daily(string $time, ?string $note = null): self
    {
        return new self(self::DAILY, time: $time, note: $note);
    }

    /** Daily, working the day before the one it fires on. */
    public static function dailyForPreviousDay(string $time): self
    {
        return new self(self::DAILY, time: $time, note: 'runs the previous day', previousDay: true);
    }

    public static function weeklyOn(int $isoDayOfWeek, string $time): self
    {
        return new self(self::WEEKLY, time: $time, dayOfWeek: $isoDayOfWeek);
    }

    public static function monthlyOn(int $dayOfMonth, string $time): self
    {
        return new self(self::MONTHLY, time: $time, dayOfMonth: $dayOfMonth);
    }

    /** Manual-only engines — nothing in the scheduler fires them. */
    public static function unscheduled(): self
    {
        return new self(self::NONE);
    }

    public function isScheduled(): bool
    {
        return $this->type !== self::NONE;
    }

    /** Would the scheduler fire this engine on the given date? */
    public function runsOn(Carbon $date): bool
    {
        return match ($this->type) {
            self::DAILY => true,
            self::WEEKLY => $date->dayOfWeekIso === $this->dayOfWeek,
            self::MONTHLY => $date->day === $this->dayOfMonth,
            default => false,
        };
    }

    /**
     * The clock time on that date, used by the replay so back-dated rows carry
     * the timestamp the real scheduler would have written.
     */
    public function atOn(Carbon $date): Carbon
    {
        if ($this->time === null) {
            return $date->copy()->startOfDay();
        }

        [$hour, $minute] = array_map('intval', explode(':', $this->time));

        return $date->copy()->setTime($hour, $minute);
    }

    /**
     * Human sentence for the admin console — generated, never hand-written.
     *
     * `$chainStartsAt` is passed for an engine the nightly chain fires: the day
     * rule is still this engine's own, but the clock belongs to the chain, and
     * the sentence says when the night starts rather than a minute this engine
     * has not used since the chain replaced the offsets.
     */
    public function describe(?string $chainStartsAt = null): string
    {
        if ($this->type === self::NONE) {
            return 'Not scheduled — manual only';
        }

        $suffix = $this->note !== null ? " ({$this->note})" : '';

        $clock = $chainStartsAt === null
            ? "{$this->time} IST"
            : "in the nightly chain from {$chainStartsAt} IST";

        return match ($this->type) {
            self::DAILY => "Daily, {$clock}{$suffix}",
            self::WEEKLY => sprintf(
                '%ss, %s%s',
                Carbon::now()->startOfWeek()->addDays(($this->dayOfWeek ?? 1) - 1)->format('l'),
                $clock,
                $suffix,
            ),
            self::MONTHLY => sprintf(
                '%s of the month, %s%s',
                self::ordinal($this->dayOfMonth ?? 1),
                $clock,
                $suffix,
            ),
            default => 'Not scheduled — manual only',
        };
    }

    private static function ordinal(int $day): string
    {
        if (in_array($day % 100, [11, 12, 13], true)) {
            return $day.'th';
        }

        return $day.match ($day % 10) {
            1 => 'st',
            2 => 'nd',
            3 => 'rd',
            default => 'th',
        };
    }
}
