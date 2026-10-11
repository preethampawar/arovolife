<?php

declare(strict_types=1);

namespace App\Modules\Identity\Listeners;

use App\Modules\Admin\Events\DistributorTerminated;
use App\Modules\Identity\Models\DistributorFamilyMember;

/**
 * Privacy Policy §5: family members registered for the arovolife Family Card
 * are deleted when the ADN is terminated (admin, inactivity, or consent
 * withdrawal). Synchronous so the erasure lands with the termination. Not
 * flag-gated — erasure runs whether the Family Card is on or off.
 */
final class EraseFamilyMembersOnTermination
{
    public function handle(DistributorTerminated $event): void
    {
        DistributorFamilyMember::eraseFor($event->distributorId);
    }
}
