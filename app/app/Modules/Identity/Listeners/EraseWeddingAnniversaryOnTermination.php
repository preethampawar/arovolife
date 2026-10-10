<?php

declare(strict_types=1);

namespace App\Modules\Identity\Listeners;

use App\Modules\Admin\Events\DistributorTerminated;
use App\Modules\Identity\Models\DistributorProfile;

/**
 * Privacy Policy §5: the wedding anniversary date is kept only for anniversary
 * greetings and is deleted when the ADN is terminated. Synchronous so the
 * erasure lands with the termination, not whenever a worker gets to it.
 */
final class EraseWeddingAnniversaryOnTermination
{
    public function handle(DistributorTerminated $event): void
    {
        DistributorProfile::eraseWeddingAnniversary($event->distributorId);
    }
}
