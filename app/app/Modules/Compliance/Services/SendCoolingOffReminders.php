<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Services;

use App\Modules\Compliance\Models\CoolingOffEvent;
use App\Modules\Compliance\Notifications\CoolingOffReminderNotification;
use App\Modules\Identity\Models\Distributor;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;

/**
 * Daily reminder run for the statutory cooling-off window. Three milestones —
 * 20, 7 and 1 day(s) remaining — each fires exactly once per distributor.
 *
 * Idempotency lives on the row, not in this service: the per-milestone
 * `reminder_dN_sent_at` columns are the truth. Re-runs are no-ops once
 * each column is non-NULL, so it's safe to schedule, retry, or invoke
 * manually as many times as needed.
 *
 * A distributor whose cooling-off has been cancelled (`cancelled_at`) is
 * skipped — they're already out, no need to nag them.
 */
final class SendCoolingOffReminders
{
    /** Each entry: [milestone-name, days-remaining, column]. Order high→low. */
    private const MILESTONES = [
        ['d20', 20, 'reminder_d20_sent_at'],
        ['d7',   7, 'reminder_d7_sent_at'],
        ['d1',   1, 'reminder_d1_sent_at'],
    ];

    public function __invoke(): int
    {
        $now = Carbon::now();
        $sent = 0;

        foreach (self::MILESTONES as [$key, $daysRemaining, $column]) {
            // Catch-up semantics: fire if the milestone moment has been
            // reached *or has already passed* AND the column is still NULL.
            // If the cron is offline for a day, the next run picks up every
            // milestone we missed without double-sending (the IS NULL guard).
            // We never fire after the cooling-off window has fully closed —
            // there's nothing to remind about then.
            $threshold = $now->copy()->addDays($daysRemaining)->endOfDay();

            $events = CoolingOffEvent::query()
                ->whereNull('cancelled_at')
                ->whereNull($column)
                ->whereHas('distributor', fn ($q) => $q
                    ->where('cooling_off_end_at', '<=', $threshold)
                    ->where('cooling_off_end_at', '>', $now)
                )
                ->with('distributor.user')
                ->get();

            foreach ($events as $event) {
                $distributor = $event->distributor;
                $user = $distributor->user;
                if ($user === null) {
                    continue;
                }

                Notification::send($user, new CoolingOffReminderNotification(
                    daysRemaining: $daysRemaining,
                    adn: $distributor->adn,
                    coolingOffEndsAt: $distributor->cooling_off_end_at->format('d M Y'),
                ));

                $event->{$column} = $now;
                $event->save();
                $sent++;
            }
        }

        return $sent;
    }
}
