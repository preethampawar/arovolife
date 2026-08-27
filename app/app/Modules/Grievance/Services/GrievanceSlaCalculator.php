<?php

declare(strict_types=1);

namespace App\Modules\Grievance\Services;

use App\Modules\Grievance\DTOs\SlaSchedule;
use Illuminate\Support\Carbon;

/**
 * Turns the SLA table published at `/p/grievance` §2 into concrete due dates.
 *
 * Working days count Monday–Saturday and exclude Sunday only. The published
 * policy says "working days" and the helpline runs Monday to Saturday, so
 * treating Saturday as a working day is the reading that favours the
 * complainant. National holidays are deliberately not modelled: a holiday
 * calendar would only ever push a due date later, and we would rather keep the
 * stricter promise than build a mechanism for missing it lawfully.
 */
final class GrievanceSlaCalculator
{
    public function __construct(private readonly GrievanceSettingsService $settings) {}

    public function scheduleFor(Carbon $receivedAt, bool $thirdPartyDependent = false): SlaSchedule
    {
        return new SlaSchedule(
            acknowledgementDueAt: $receivedAt->copy()->addHours($this->settings->acknowledgementHours()),
            firstResponseDueAt: $this->addWorkingDays($receivedAt, $this->settings->firstResponseWorkingDays()),
            resolutionDueAt: $receivedAt->copy()->addDays($this->settings->resolutionDays($thirdPartyDependent)),
        );
    }

    /**
     * The next date a third-party-dependent ticket owes the complainant a
     * progress update. Policy §2 promises one every 15 days while the longer
     * resolution window runs.
     */
    public function nextStatusUpdateDueAt(Carbon $lastUpdateAt): Carbon
    {
        return $lastUpdateAt->copy()->addDays($this->settings->statusUpdateIntervalDays());
    }

    /**
     * DSR 2021 Rule 12 retention — seven years from final closure.
     */
    public function retentionUntil(Carbon $closedAt): Carbon
    {
        return $closedAt->copy()->addYears($this->settings->retentionYears())->startOfDay();
    }

    private function addWorkingDays(Carbon $from, int $days): Carbon
    {
        $cursor = $from->copy();

        for ($added = 0; $added < $days;) {
            $cursor->addDay();

            if (! $cursor->isSunday()) {
                $added++;
            }
        }

        return $cursor;
    }
}
