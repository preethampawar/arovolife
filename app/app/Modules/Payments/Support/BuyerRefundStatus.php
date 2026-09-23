<?php

declare(strict_types=1);

namespace App\Modules\Payments\Support;

use App\Modules\Commerce\Models\Order;
use App\Modules\Payments\Models\RefundIntent;
use App\Modules\Shared\Support\IndianNumber;

/**
 * The one line a buyer sees about their refund on the order page, read from
 * the refund records rather than the order status. A gateway failure is ours
 * to fix (the finance worklist), so the buyer sees it as "being processed",
 * never the gateway's error text.
 */
final class BuyerRefundStatus
{
    public const TONE_DONE = 'done';

    public const TONE_PENDING = 'pending';

    public const TONE_CLOSED = 'closed';

    /**
     * @return array{tone: string, text: string}|null null when the order has no refund to report
     */
    public static function for(Order $order): ?array
    {
        $refunds = RefundIntent::where('order_id', $order->id)->orderBy('id')->get();

        if ($refunds->isEmpty()) {
            $owed = $order->status === Order::STATUS_REFUND_APPROVED
                ? RefundPayable::owedOutsideGateway($order)
                : 0;

            return $owed > 0
                ? ['tone' => self::TONE_PENDING, 'text' => 'Refund of '.IndianNumber::rupees($owed).' is being paid to you by bank transfer by our team.']
                : null;
        }

        $amount = IndianNumber::rupees((int) $refunds->sum('amount_paise'));

        if ($refunds->contains(fn (RefundIntent $r): bool => $r->isForfeited())) {
            return ['tone' => self::TONE_CLOSED, 'text' => 'No refund is due on this order: the returned product was not received. If you did send it back, please raise a grievance.'];
        }

        if ($refunds->every(fn (RefundIntent $r): bool => $r->status === RefundIntent::STATUS_PROCESSED)) {
            $last = $refunds->sortByDesc('processed_at')->first();
            $how = $refunds->contains(fn (RefundIntent $r): bool => $r->settled_via === RefundIntent::SETTLED_VIA_MANUAL_NEFT)
                ? 'by bank transfer'
                : 'to your original payment method';

            $on = $last?->processed_at !== null ? ' on '.$last->processed_at->format('d M Y') : '';

            return ['tone' => self::TONE_DONE, 'text' => "Refund of {$amount} processed {$how}{$on}. Banks can take a few working days to show the credit."];
        }

        if ($refunds->contains(fn (RefundIntent $r): bool => $r->isHeld())) {
            return ['tone' => self::TONE_PENDING, 'text' => "Refund of {$amount} will be sent to your original payment method once we receive the returned product."];
        }

        // A failed send is on the finance worklist and may end up paid by NEFT,
        // so the buyer gets no timing or method promise for it.
        if ($refunds->contains(fn (RefundIntent $r): bool => $r->status === RefundIntent::STATUS_FAILED)) {
            return ['tone' => self::TONE_PENDING, 'text' => "Your refund of {$amount} is being processed. Our team will contact you if we need your bank details."];
        }

        return ['tone' => self::TONE_PENDING, 'text' => "Refund of {$amount} is being processed to your original payment method. It usually reaches you within ".RefundWorklist::PROMISE_BUSINESS_DAYS.' working days.'];
    }
}
