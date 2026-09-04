<?php

declare(strict_types=1);

namespace App\Modules\Compensation\Http\Controllers;

use App\Modules\Compensation\Jobs\ProcessRazorpayPayoutWebhookJob;
use App\Modules\Compensation\Models\PayoutGatewayEvent;
use App\Modules\Compensation\Services\RazorpayPayoutGateway;
use App\Modules\Compensation\Support\RazorpayPayoutPayloadScrubber;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Log;
use JsonException;

/**
 * RazorpayX → us. The authority for "this transfer reached the distributor".
 *
 * Four rules, in order:
 *   1. The signature over the RAW body must verify with the payouts webhook
 *      secret. Sender IP and user-agent are not trusted; only the signature.
 *   2. A request that fails verification is answered 200 anyway. Unlike the
 *      inbound checkout webhook, a 4xx here would make Razorpay redeliver the
 *      same unverifiable body every few minutes for a day; nothing is stored
 *      and a warning is logged instead.
 *   3. Every verified event is stored once, keyed on Razorpay's
 *      `x-razorpay-event-id`. Deliveries are retried and re-ordered; the
 *      unique index makes a duplicate a no-op.
 *   4. Storing and applying are separate. This returns 200 as soon as the
 *      event is on disk and a job is queued — applying it happens on the
 *      `default` queue in ProcessRazorpayPayoutWebhookJob.
 */
final class RazorpayPayoutWebhookController extends Controller
{
    public function __construct(
        private readonly RazorpayPayoutGateway $gateway,
        private readonly RazorpayPayoutPayloadScrubber $scrubber,
    ) {}

    public function handle(Request $request): JsonResponse
    {
        $raw = $request->getContent();
        $signature = (string) $request->header('X-Razorpay-Signature', '');

        if (! $this->gateway->verifyWebhookSignature($raw, $signature)) {
            // Only a prefix: the full value is a valid HMAC and logging it
            // would put a working signature in the log file.
            Log::channel('payments')->warning('razorpayx payout webhook rejected: signature did not verify', [
                'ip' => $request->ip(),
                'length' => strlen($raw),
                'signature_prefix' => substr($signature, 0, 8),
            ]);

            return response()->json(['status' => 'ignored']);
        }

        try {
            /** @var array<string, mixed> $body */
            $body = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            Log::channel('payments')->warning('razorpayx payout webhook rejected: body is not valid JSON', [
                'error' => $e->getMessage(),
            ]);

            return response()->json(['status' => 'ignored']);
        }

        $eventType = (string) ($body['event'] ?? 'unknown');
        $eventId = (string) $request->header('X-Razorpay-Event-Id', '');
        if ($eventId === '') {
            // Older deliveries may omit the header; a digest of the body means
            // a redelivery of the same payload still dedupes.
            $eventId = 'sha256:'.hash('sha256', $raw);
        }

        $payoutEntity = $body['payload']['payout']['entity'] ?? null;
        $gatewayPayoutId = is_array($payoutEntity) && isset($payoutEntity['id']) ? (string) $payoutEntity['id'] : null;

        try {
            $event = PayoutGatewayEvent::create([
                'gateway' => PayoutGatewayEvent::GATEWAY_RAZORPAYX,
                'direction' => PayoutGatewayEvent::DIRECTION_WEBHOOK,
                'event_type' => $eventType,
                'gateway_event_id' => $eventId,
                'gateway_payout_id' => $gatewayPayoutId,
                'signature_verified' => true,
                'payload' => $this->scrubber->scrub($body),
            ]);
        } catch (UniqueConstraintViolationException) {
            Log::channel('payments')->info('razorpayx payout webhook duplicate ignored', [
                'event' => $eventType,
                'event_id' => $eventId,
            ]);

            return response()->json(['status' => 'duplicate']);
        }

        ProcessRazorpayPayoutWebhookJob::dispatch((int) $event->id);

        return response()->json(['status' => 'queued', 'id' => $event->id]);
    }
}
