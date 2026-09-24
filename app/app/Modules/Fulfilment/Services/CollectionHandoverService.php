<?php

declare(strict_types=1);

namespace App\Modules\Fulfilment\Services;

use App\Modules\Commerce\Models\Order;
use App\Modules\Commerce\Notifications\OrderReadyForCollectionNotification;
use App\Modules\Commerce\Services\OrderStateMachine;
use App\Modules\Commerce\Support\OrderBuyerNotifier;
use App\Modules\Compliance\Models\AuditLog;
use App\Modules\Fulfilment\Models\Shipment;
use Illuminate\Database\DatabaseManager;
use RuntimeException;

/**
 * The record that a centre handed a parcel to the person entitled to it.
 *
 * This is what R-47 means by "a centre-acknowledged handover record as the
 * evidence the commission is paid against", and since H5 the ADC bonus reads
 * it: a centre earns on orders it actually handed over, not on every order that
 * named it at checkout.
 *
 * The authenticator comes from the BUYER, never from the centre. The collection
 * code is issued to the buyer when the parcel arrives and the operator cannot
 * produce it — which is what keeps the handover surface honest even though
 * R-24 leaves the number of centres one distributor may hold uncapped.
 *
 * Deliberately not `Shared\Otp\OtpService`, and the reasoning is in the
 * migration that adds `handover_code_hash`: that service is cache-backed with a
 * ten-minute TTL, and a collection code has to survive until the buyer walks
 * into the centre.
 */
final class CollectionHandoverService
{
    /** Five wrong codes and the operator has to fall back to staff. */
    public const MAX_ATTEMPTS = 5;

    public function __construct(
        private readonly DatabaseManager $db,
        private readonly OrderStateMachine $orders,
        private readonly OrderBuyerNotifier $buyerNotifier,
    ) {}

    /**
     * The centre (or staff, on its behalf) confirms the parcel has arrived:
     * the order moves to awaiting collection, and the buyer is emailed a fresh
     * collection code. One path for the centre's own page and the admin
     * override, so neither can skip the code.
     *
     * Returns the code in the clear, once, for the admin flash; null when the
     * order has no shipment row (shipped by the old button) and so no code can
     * be issued. The centre page never shows what this returns.
     */
    public function acknowledgeArrival(Order $order, ?int $actorUserId = null): ?string
    {
        $this->orders->markAwaitingCollection($order, $actorUserId);

        $shipment = Shipment::where('order_id', $order->id)->first();

        if ($shipment === null) {
            return null;
        }

        $code = $this->issueCode($shipment);
        $centre = $order->areteCenter;

        if ($centre !== null) {
            $buyerName = $order->ship_name;
            if ($buyerName === null || $buyerName === '') {
                $buyerName = $order->customer !== null ? $order->customer->display_name : 'there';
            }

            $this->buyerNotifier->send($order, new OrderReadyForCollectionNotification(
                orderNo: $order->order_no,
                buyerName: (string) $buyerName,
                centreName: $centre->name,
                centreAddress: $centre->displayAddress(),
                centrePhone: $centre->contact_number,
                collectionCode: $code,
            ));
        }

        return $code;
    }

    /**
     * Issue the buyer's collection code and return it in the clear, once, for
     * the notification. It is never stored in the clear and cannot be read back.
     */
    public function issueCode(Shipment $shipment): string
    {
        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);

        $shipment->update([
            'handover_code_hash' => $this->hash($code),
            'handover_attempts' => 0,
        ]);

        return $code;
    }

    /**
     * Record that the buyer collected, against the code they presented.
     *
     * Writes `collected_at` (which the ADC bonus reads) and `pod_hash_sha256`
     * as the receipt that the handover happened, then closes the order through
     * the ordinary delivered transition so the 30-day cooling-off clock starts
     * from the moment the buyer actually took possession.
     */
    public function recordCollection(Order $order, string $code, ?int $actorUserId = null): Shipment
    {
        if ($order->status !== Order::STATUS_AWAITING_COLLECTION) {
            throw new RuntimeException(
                "Order {$order->order_no} is not waiting at a centre, so there is nothing to hand over."
            );
        }

        $shipment = Shipment::where('order_id', $order->id)->firstOrFail();

        if ($shipment->handover_code_hash === null) {
            throw new RuntimeException(
                "Order {$order->order_no} has no collection code on file. Re-issue one before handing the parcel over."
            );
        }

        if ($shipment->handover_attempts >= self::MAX_ATTEMPTS) {
            throw new RuntimeException(
                'Too many incorrect collection codes for this parcel. It has been locked; staff must release it.'
            );
        }

        // Constant-time: a timing difference here leaks the code one digit at
        // a time to anyone who can stand at the counter and guess.
        if (! hash_equals($shipment->handover_code_hash, $this->hash(trim($code)))) {
            $shipment->increment('handover_attempts');

            AuditLog::create([
                'actor_id' => $actorUserId,
                'action' => 'order.collection_code_refused',
                'subject_type' => 'order',
                'subject_id' => $order->id,
                'details' => [
                    'order_no' => $order->order_no,
                    'arete_center_id' => $shipment->arete_center_id,
                    'attempt' => $shipment->handover_attempts,
                ],
            ]);

            throw new RuntimeException('That collection code is not correct.');
        }

        return $this->db->transaction(function () use ($order, $shipment, $actorUserId): Shipment {
            $collectedAt = now();

            $shipment->update([
                'collected_at' => $collectedAt,
                'collected_by_user_id' => $actorUserId,
                // The receipt that it happened, distinct from the verifier
                // above. Bound to this order, this centre and this moment, so
                // it cannot be lifted onto another parcel.
                'pod_hash_sha256' => hash('sha256', implode('|', [
                    $order->order_no,
                    (string) $shipment->arete_center_id,
                    $collectedAt->toIso8601String(),
                ])),
                // Spent. A code that still verified after collection would let
                // a second parcel be released on the same authority.
                'handover_code_hash' => null,
            ]);

            AuditLog::create([
                'actor_id' => $actorUserId,
                'action' => 'order.collected',
                'subject_type' => 'order',
                'subject_id' => $order->id,
                'details' => [
                    'order_no' => $order->order_no,
                    'arete_center_id' => $shipment->arete_center_id,
                    'collected_at' => $collectedAt->toIso8601String(),
                ],
            ]);

            $this->orders->markDelivered($order->fresh(), $actorUserId);

            return $shipment->refresh();
        });
    }

    /** HMAC, not a bare hash: a bare sha256 over six digits is both brute-forceable and forgeable. */
    private function hash(string $code): string
    {
        return hash_hmac('sha256', $code, (string) config('app.key'));
    }
}
