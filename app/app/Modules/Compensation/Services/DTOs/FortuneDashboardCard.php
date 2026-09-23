<?php

declare(strict_types=1);

namespace App\Modules\Compensation\Services\DTOs;

use App\Modules\Compensation\Models\FortuneBonusParticipant;
use App\Modules\Compensation\Models\FortuneBonusResult;
use Carbon\CarbonImmutable;

/** Everything the dashboard's Fortune Bonus card shows: this month's gates and last month's outcome. */
final readonly class FortuneDashboardCard
{
    public function __construct(
        public CarbonImmutable $month,                          // current IST month start
        public FortuneQualification $thisMonth,
        public ?FortuneBonusResult $lastMonth,                  // credited / skipped / repurchase_wallet_blocked
        public ?FortuneBonusParticipant $lastMonthEntry,        // enrolled, result not written yet
    ) {}
}
