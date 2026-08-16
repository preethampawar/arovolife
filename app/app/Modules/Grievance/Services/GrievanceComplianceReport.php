<?php

declare(strict_types=1);

namespace App\Modules\Grievance\Services;

use App\Modules\Grievance\Enums\TicketCategory;
use App\Modules\Grievance\Enums\TicketChannel;
use App\Modules\Grievance\Models\Ticket;
use Illuminate\Support\Carbon;

/**
 * The monthly compliance report promised by T&C §11 and read by the quarterly
 * internal audit in policy §6.6.
 *
 * Everything here is counted from recorded facts — `*_breached_at` stamps
 * written by the SLA sweep at the moment a clock lapsed — rather than
 * recomputed against today's settings. A report that silently re-scores
 * history every time somebody edits an SLA setting is not evidence.
 */
final class GrievanceComplianceReport
{
    /**
     * @param  array<int, string>  $excludeCategories  category values the viewer may not see
     * @return array{
     *     month: string,
     *     received: int,
     *     resolved: int,
     *     closed: int,
     *     still_open: int,
     *     acknowledgement_owed: int,
     *     acknowledgement_not_owed: int,
     *     acknowledged_in_time: int,
     *     acknowledgement_breaches: int,
     *     first_response_breaches: int,
     *     resolution_breaches: int,
     *     third_party_extensions: int,
     *     anonymous: int,
     *     median_resolution_days: ?float,
     *     by_category: array<string, int>,
     *     by_channel: array<string, int>,
     *     by_escalation_level: array<int, int>
     * }
     */
    public function forMonth(Carbon $month, array $excludeCategories = []): array
    {
        $start = $month->copy()->startOfMonth();
        $end = $month->copy()->endOfMonth();

        // The report is an aggregate, but an aggregate still discloses. A
        // viewer who cannot open a single ethics ticket must not be able to
        // read "Ethics & fraud: 3" here — the count alone tells them such
        // complaints exist and roughly when.
        $scoped = fn () => Ticket::query()->when(
            $excludeCategories !== [],
            fn ($q) => $q->whereNotIn('category', $excludeCategories)
        );

        $received = $scoped()->whereBetween('created_at', [$start, $end])->get();

        $resolvedInMonth = $scoped()->whereBetween('resolved_at', [$start, $end])->get();

        return [
            'month' => $start->format('Y-m'),
            'received' => $received->count(),
            'resolved' => $resolvedInMonth->count(),
            'closed' => $scoped()->whereBetween('closed_at', [$start, $end])->count(),
            'still_open' => $received->filter(fn (Ticket $t) => ! $t->status->isSettled())->count(),
            // Only complaints we actually owed an acknowledgement to count
            // here. An anonymous complainant, or one recorded by phone with no
            // email, receives nothing — folding those into "acknowledged in
            // time" would overstate performance to a regulator by counting
            // silence as delivery.
            'acknowledgement_owed' => $received->filter(
                fn (Ticket $t) => $t->notifiableEmail() !== null
            )->count(),
            'acknowledgement_not_owed' => $received->filter(
                fn (Ticket $t) => $t->notifiableEmail() === null
            )->count(),
            'acknowledged_in_time' => $received->filter(
                fn (Ticket $t) => $t->notifiableEmail() !== null
                    && $t->acknowledged_at !== null
                    && $t->acknowledgement_breached_at === null
            )->count(),
            'acknowledgement_breaches' => $received->filter(
                fn (Ticket $t) => $t->acknowledgement_breached_at !== null
            )->count(),
            'first_response_breaches' => $received->filter(
                fn (Ticket $t) => $t->first_response_breached_at !== null
            )->count(),
            'resolution_breaches' => $received->filter(
                fn (Ticket $t) => $t->resolution_breached_at !== null
            )->count(),
            'third_party_extensions' => $received->filter(
                fn (Ticket $t) => $t->third_party_dependent
            )->count(),
            'anonymous' => $received->filter(fn (Ticket $t) => $t->is_anonymous)->count(),
            'median_resolution_days' => $this->medianResolutionDays($resolvedInMonth->all()),
            'by_category' => $this->countBy($received->all(), fn (Ticket $t) => $t->category->label()),
            'by_channel' => $this->countBy($received->all(), fn (Ticket $t) => $t->channel->label()),
            'by_escalation_level' => $this->countBy($received->all(), fn (Ticket $t) => $t->escalation_level->value),
        ];
    }

    /**
     * The rolling window the quarterly audit reads.
     *
     * @param  array<int, string>  $excludeCategories  category values the viewer may not see
     * @return array<int, array<string, mixed>>
     */
    public function trailing(int $months, ?Carbon $endingAt = null, array $excludeCategories = []): array
    {
        $cursor = ($endingAt ?? Carbon::now())->copy()->startOfMonth();
        $rows = [];

        for ($i = 0; $i < $months; $i++) {
            $rows[] = $this->forMonth($cursor->copy(), $excludeCategories);
            $cursor->subMonth();
        }

        return $rows;
    }

    /**
     * @param  array<int, Ticket>  $tickets
     */
    private function medianResolutionDays(array $tickets): ?float
    {
        $spans = [];

        foreach ($tickets as $ticket) {
            if ($ticket->resolved_at !== null) {
                $spans[] = $ticket->created_at->diffInHours($ticket->resolved_at) / 24;
            }
        }

        if ($spans === []) {
            return null;
        }

        sort($spans);
        $count = count($spans);
        $middle = intdiv($count, 2);

        $median = $count % 2 === 0
            ? ($spans[$middle - 1] + $spans[$middle]) / 2
            : $spans[$middle];

        return round($median, 1);
    }

    /**
     * @param  array<int, Ticket>  $tickets
     * @param  callable(Ticket): (string|int)  $key
     * @return array<array-key, int>
     */
    private function countBy(array $tickets, callable $key): array
    {
        $counts = [];

        foreach ($tickets as $ticket) {
            $bucket = $key($ticket);
            $counts[$bucket] = ($counts[$bucket] ?? 0) + 1;
        }

        ksort($counts);

        return $counts;
    }

    /**
     * Column order for the CSV export. Kept beside the report so the two can
     * never drift.
     *
     * @return array<int, string>
     */
    public static function csvColumns(): array
    {
        return [
            'month', 'received', 'resolved', 'closed', 'still_open',
            'acknowledgement_owed', 'acknowledgement_not_owed',
            'acknowledged_in_time', 'acknowledgement_breaches',
            'first_response_breaches', 'resolution_breaches',
            'third_party_extensions', 'anonymous', 'median_resolution_days',
        ];
    }

    /**
     * @return array<int, string>
     */
    public static function categoryLabels(): array
    {
        return array_map(static fn (TicketCategory $c): string => $c->label(), TicketCategory::cases());
    }

    /**
     * @return array<int, string>
     */
    public static function channelLabels(): array
    {
        return array_map(static fn (TicketChannel $c): string => $c->label(), TicketChannel::cases());
    }
}
