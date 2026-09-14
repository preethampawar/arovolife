<?php

declare(strict_types=1);

namespace App\Modules\Compensation\Services\Recompute;

use Illuminate\Support\Carbon;

/**
 * How far a recompute replays the scheduler's calendar.
 *
 * A recompute fires every engine at the instant the real scheduler would have
 * fired it — 00:05 evaluate, 00:10 cut-off for the day before, the close on the
 * 1st, the payout batch on the 8th. The only question left is where to stop,
 * and that question is the whole difference between a test environment that
 * mirrors production and one that is showing figures nobody has earned yet.
 *
 * {@see self::Now} is the production-faithful answer and the default: stop at
 * this instant, so the database holds exactly what the scheduler would have
 * produced by now and nothing else. The other two SIMULATE — they fire engines
 * at instants that have not arrived, on the orders that exist right now — and a
 * database holding their output is marked projected ({@see RecomputeState}),
 * bannered on every page, has its scheduled engines paused, and is reset to
 * {@see self::Now} by the nightly reset.
 */
enum RecomputeHorizon: string
{
    /** Everything the scheduler would have run by now. Nothing simulated. */
    case Now = 'now';

    /** Also today's own cut-off, which really fires at 00:10 tomorrow. */
    case Today = 'today';

    /** The whole calendar through the monthly payout batch on the 8th of next month. */
    case Projection = 'projection';

    /**
     * The last instant whose scheduled engines are replayed. An engine due
     * later than this is simply not fired — exactly as the real scheduler has
     * not fired it yet.
     */
    public function instantFrom(Carbon $now): Carbon
    {
        $ist = $now->copy()->timezone('Asia/Kolkata');

        return match ($this) {
            self::Now => $now->copy(),
            // Tomorrow's 00:05 evaluation and 00:10 cut-off, which are the runs
            // that settle TODAY. The :59 keeps an engine due exactly at 00:10 on
            // the right side of the comparison.
            self::Today => $ist->copy()->addDay()->startOfDay()->setTime(0, 10, 59),
            // The 8th of next month at 04:00 — the monthly payout batch for the
            // month now in flight, which is the last event of its cycle.
            self::Projection => $ist->copy()->addMonthNoOverflow()->startOfMonth()
                ->addDays(7)->setTime(4, 0, 59),
        };
    }

    /** Does a database holding this run's output contain simulated figures? */
    public function isProjected(): bool
    {
        return $this !== self::Now;
    }

    /** Radio-button label on the Engine Runs card. */
    public function label(Carbon $now): string
    {
        return match ($this) {
            self::Now => 'Up to now',
            self::Today => 'Through today',
            self::Projection => sprintf(
                'Project through the %s payout (%s)',
                $now->copy()->timezone('Asia/Kolkata')->addMonthNoOverflow()->format('F'),
                $this->instantFrom($now)->format('d M Y'),
            ),
        };
    }

    /** One sentence an operator can choose from without reading the runbook. */
    public function describe(Carbon $now): string
    {
        $instant = $this->instantFrom($now);
        $ist = $now->copy()->timezone('Asia/Kolkata');

        return match ($this) {
            self::Now => sprintf(
                'Exactly what the scheduler would have produced by %s — the closed months credited on their 1st, '
                    .'paid on their 8th, and daily cut-offs through %s. Nothing is simulated and the scheduled '
                    .'engines keep running normally.',
                $ist->format('d M Y H:i'),
                $ist->copy()->subDay()->format('d M Y'),
            ),
            self::Today => "Also today's repurchase evaluation and GSB cut-off, which really fire at 00:05 and "
                .'00:10 tomorrow. No month is closed. Use it to see what the orders you have just placed pay.',
            self::Projection => sprintf(
                'The whole calendar through %s: every remaining day of %s, the monthly close on 1 %s and the '
                    .'monthly payout batch on %s — all on the orders that exist right now.',
                $instant->format('d M Y'),
                $ist->format('F'),
                $ist->copy()->addMonthNoOverflow()->format('F Y'),
                $instant->format('d M Y'),
            ),
        };
    }

    public static function fromValue(?string $value): self
    {
        return self::tryFrom((string) $value) ?? self::Now;
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
