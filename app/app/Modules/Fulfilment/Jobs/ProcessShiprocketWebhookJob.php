<?php

declare(strict_types=1);

namespace App\Modules\Fulfilment\Jobs;

use App\Modules\Fulfilment\Models\Shipment;
use App\Modules\Fulfilment\Models\ShipmentEvent;
use App\Modules\Fulfilment\Services\CourierTrackingSync;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Throwable;

/**
 * Applies a stored Shiprocket tracking webhook.
 *
 * The webhook body is only a prompt to look. What the parcel's status IS comes
 * from Shiprocket's tracking API (`ShiprocketGateway::track()`), because the
 * webhook's only authentication is a static token. The rules for what a
 * status does live in `CourierTrackingSync`, shared with the staff button.
 */
final class ProcessShiprocketWebhookJob implements ShouldQueue
{
    use InteractsWithQueue, Queueable;

    public int $tries = 3;

    /** @var array<int, int> */
    public array $backoff = [60, 300, 900];

    public function __construct(private readonly int $eventId)
    {
        $this->onQueue('default');
    }

    public function handle(CourierTrackingSync $sync): void
    {
        $event = ShipmentEvent::find($this->eventId);
        if ($event === null || $event->processed_at !== null) {
            return;
        }

        $shipment = $event->shipment_id === null ? null : Shipment::find($event->shipment_id);
        if ($shipment === null) {
            $event->update(['processed_at' => now(), 'processing_error' => 'no matching shipment']);

            return;
        }

        try {
            $outcome = $sync->sync($shipment, null, ['shipment_event_id' => $event->id]);
        } catch (Throwable $e) {
            $event->update(['processing_error' => mb_substr($e->getMessage(), 0, 500)]);

            throw $e;
        }

        if ($outcome === CourierTrackingSync::OUTCOME_NO_STATUS) {
            $event->update(['processed_at' => now(), 'processing_error' => 'Shiprocket returned no tracking status']);

            return;
        }

        $event->update(['processed_at' => now(), 'processing_error' => null]);

        logger()->info('shiprocket webhook applied', ['event_id' => $event->id, 'outcome' => $outcome]);
    }
}
