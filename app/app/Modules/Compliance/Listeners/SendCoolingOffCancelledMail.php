<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Listeners;

use App\Modules\Compliance\Events\CoolingOffCancelled;
use App\Modules\Compliance\Notifications\CoolingOffCancelledNotification;
use App\Modules\Identity\Models\Distributor;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Notification;

/**
 * Sends the cancellation-confirmation email when a distributor's
 * cooling-off is cancelled. The view at `compliance/cooling-off.blade.php`
 * promises this email; without this listener the promise is broken.
 */
final class SendCoolingOffCancelledMail implements ShouldQueue
{
    public function handle(CoolingOffCancelled $event): void
    {
        $distributor = Distributor::query()->with('user')->find($event->distributorId);
        if ($distributor === null || $distributor->user === null) {
            return;
        }

        // Cascaded vs self: if the actor isn't this distributor's own user,
        // the cancellation reached this row via the spouse cascade (the
        // primary clicked the button, the spouse's row is a cascade target).
        $cascaded = $event->actorUserId !== (int) $distributor->user_id;

        Notification::send($distributor->user, new CoolingOffCancelledNotification(
            adn: $distributor->adn,
            cancelledAtFormatted: $event->cancelledAt->format('d M Y H:i'),
            cascaded: $cascaded,
        ));
    }
}
