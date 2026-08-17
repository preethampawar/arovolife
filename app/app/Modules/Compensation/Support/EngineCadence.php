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
        public ?string $time = null,
        /** ISO-8601 day of week, 1 = Monday .. 7 = Sunday. */
        public ?int $dayOfWeek = null,
        public ?int $dayOfMonth = null,
        /** Parenthetical appended to the description, e.g. "runs the previous day". */
        public ?string $note = null,
    ) {}

    public static function daily(string $time, ?string $note = null): self
    {
        return new self(self::DAILY, time: $time, note: $note);
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

    /** Human sentence for the admin console — generated, never hand-written. */
    public function describe(): string
    {
        if ($this->type === self::NONE) {
            return 'Not scheduled — manual only';
        }

        $suffix = $this->note !== null ? " ({$this->note})" : '';

        return match ($this->type) {
            self::DAILY => "Daily, {$this->time} IST{$suffix}",
            self::WEEKLY => sprintf(
                '%ss, %s IST%s',
                Carbon::now()->startOfWeek()->addDays(($this->dayOfWeek ?? 1) - 1)->format('l'),
                $this->time,
                $suffix,
            ),
            self::MONTHLY => sprintf(
                '%s of the month, %s IST%s',
                self::ordinal($this->dayOfMonth ?? 1),
                $this->time,
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
