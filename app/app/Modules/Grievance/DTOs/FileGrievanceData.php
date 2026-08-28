<?php

declare(strict_types=1);

namespace App\Modules\Grievance\DTOs;

use App\Modules\Grievance\Enums\TicketCategory;
use App\Modules\Grievance\Enums\TicketChannel;
use Illuminate\Support\Carbon;

/**
 * Everything needed to open a grievance, from any intake channel.
 *
 * `reporterEmail` is nullable because policy §6.5 accepts anonymous
 * complaints and because a postal or walk-in complainant may leave only a
 * phone number.
 *
 * `receivedAt` is the date the complaint reached arovolife — NOT the date a
 * staff member keyed it in. Policy §2 measures every SLA "of receipt", so a
 * postal complaint entered on day 10 must still be scored from the day it
 * arrived. Defaults to now, which is correct for the self-service channels.
 */
final readonly class FileGrievanceData
{
    public function __construct(
        public string $subject,
        public string $body,
        public TicketCategory $category,
        public TicketChannel $channel,
        public ?string $reporterName = null,
        public ?string $reporterEmail = null,
        public ?string $reporterPhone = null,
        public bool $isAnonymous = false,
        public ?int $distributorId = null,
        public ?int $customerId = null,
        public ?int $orderId = null,
        public ?int $recordedByUserId = null,
        public string $severity = 'medium',
        public ?Carbon $receivedAt = null,
    ) {}
}
