<?php

declare(strict_types=1);

namespace App\Modules\Consent\Services\DTOs;

use Illuminate\Support\Carbon;

/**
 * A plan change a distributor has not yet agreed to: the text they are being
 * asked about (hash + label) and the entries listed for them.
 */
final class PendingPlanChange
{
    /**
     * @param  list<PlanChangeEntry>  $entries  newest first; empty means the page has no readable change list
     */
    public function __construct(
        public readonly string $hashHex,
        public readonly ?string $label,
        public readonly array $entries,
        public readonly ?Carbon $coolingOffEndsAt,
    ) {}

    /** The labels listed, as stored on the notice row for evidence. */
    public function labelsShown(): string
    {
        return implode(',', array_map(fn (PlanChangeEntry $entry): string => $entry->label, $this->entries));
    }
}
