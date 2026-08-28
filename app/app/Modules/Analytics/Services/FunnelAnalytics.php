<?php

declare(strict_types=1);

namespace App\Modules\Analytics\Services;

use App\Modules\Analytics\DTOs\FunnelStage;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Where people stop.
 *
 * Two funnels — registration and commerce — each built only from events the
 * platform genuinely records. There is no wizard-step table, so this does not
 * pretend to a step-by-step drop-off it cannot see: it measures the six
 * milestones that leave a row behind, and says so.
 *
 * Everything here is admin-facing and historical. No projection, no
 * extrapolation, no "if this rate holds" — a funnel that forecasts is a funnel
 * someone will quote at a distributor (hard rule 3).
 */
final class FunnelAnalytics
{
    /**
     * Registration: orientation → consent → account → KYC → active.
     *
     * @return array<int, FunnelStage>
     */
    public function registration(Carbon $from, Carbon $to): array
    {
        $started = (int) DB::table('orientation_views')
            ->whereBetween('started_at', [$from, $to])
            ->distinct()
            ->count('distributor_id');

        $quizPassed = (int) DB::table('orientation_views')
            ->whereBetween('started_at', [$from, $to])
            ->whereNotNull('quiz_passed_at')
            ->distinct()
            ->count('distributor_id');

        $consented = (int) DB::table('consents')
            ->whereBetween('accepted_at', [$from, $to])
            ->distinct()
            ->count('distributor_id');

        $registered = (int) DB::table('distributors')
            ->whereBetween('created_at', [$from, $to])
            ->count();

        $kycVerified = (int) DB::table('kyc_documents')
            ->whereBetween('verified_at', [$from, $to])
            ->distinct()
            ->count('distributor_id');

        $activated = (int) DB::table('users')
            ->whereBetween('activated_at', [$from, $to])
            ->count();

        return $this->stages([
            ['Orientation started', $started, 'A prospect opened the mandatory orientation video.'],
            ['Orientation passed', $quizPassed, 'Watched enough of it and passed the micro-quiz.'],
            ['Agreements accepted', $consented, 'Accepted the Direct Seller Agreement and the other versioned documents.'],
            ['Account created', $registered, 'A distributor row exists — an ADN has been issued.'],
            ['KYC verified', $kycVerified, 'At least one identity document verified.'],
            ['Activated', $activated, 'The account is live.'],
        ]);
    }

    /**
     * Commerce: cart → order → paid → delivered.
     *
     * @return array<int, FunnelStage>
     */
    public function commerce(Carbon $from, Carbon $to): array
    {
        $cartsCreated = (int) DB::table('carts')
            ->whereBetween('created_at', [$from, $to])
            ->count();

        $cartsWithItems = (int) DB::table('carts')
            ->whereBetween('carts.created_at', [$from, $to])
            ->whereExists(function ($query): void {
                $query->select(DB::raw(1))
                    ->from('cart_items')
                    ->whereColumn('cart_items.cart_id', 'carts.id');
            })
            ->count();

        $placed = (int) DB::table('orders')
            ->whereBetween('created_at', [$from, $to])
            ->where('status', '!=', 'draft')
            ->count();

        $paid = (int) DB::table('orders')
            ->whereBetween('created_at', [$from, $to])
            ->whereNotNull('paid_at')
            ->count();

        $delivered = (int) DB::table('orders')
            ->whereBetween('created_at', [$from, $to])
            ->whereIn('status', ['delivered', 'confirmed'])
            ->count();

        return $this->stages([
            ['Carts created', $cartsCreated, 'Including carts that were never added to.'],
            ['Cart has items', $cartsWithItems, 'At least one product added.'],
            ['Order placed', $placed, 'Checkout completed.'],
            ['Paid', $paid, 'Payment captured.'],
            ['Delivered', $delivered, 'Goods handed over.'],
        ]);
    }

    /**
     * Headline commerce numbers for the window.
     *
     * @return array{orders: int, gross_paise: int, average_order_paise: int, bv_paise: int, cancelled: int, refunded: int}
     */
    public function commerceTotals(Carbon $from, Carbon $to): array
    {
        $paidOrders = DB::table('orders')
            ->whereBetween('created_at', [$from, $to])
            ->whereNotNull('paid_at')
            ->whereNotIn('status', ['cancelled', 'refunded']);

        $orders = (int) $paidOrders->clone()->count();
        $gross = (int) $paidOrders->clone()->sum('total_paise');

        return [
            'orders' => $orders,
            'gross_paise' => $gross,
            // Integer division: a mean order value quoted to the paisa implies
            // a precision the underlying rounding does not have.
            'average_order_paise' => $orders > 0 ? intdiv($gross, $orders) : 0,
            'bv_paise' => (int) DB::table('bv_ledger_entries')
                ->whereBetween('effective_at', [$from, $to])
                ->sum('bv_paise'),
            'cancelled' => (int) DB::table('orders')
                ->whereBetween('created_at', [$from, $to])
                ->where('status', 'cancelled')
                ->count(),
            'refunded' => (int) DB::table('orders')
                ->whereBetween('created_at', [$from, $to])
                ->where('status', 'refunded')
                ->count(),
        ];
    }

    /**
     * Turn raw counts into stages carrying their conversion from the first
     * stage and from the one before.
     *
     * @param  array<int, array{0: string, 1: int, 2: string}>  $rows
     * @return array<int, FunnelStage>
     */
    private function stages(array $rows): array
    {
        $first = $rows[0][1] ?? 0;
        $previous = null;
        $stages = [];

        foreach ($rows as $row) {
            [$label, $count, $note] = $row;

            $stages[] = new FunnelStage(
                label: $label,
                count: $count,
                note: $note,
                shareOfFirst: $first > 0 ? round($count / $first * 100, 1) : null,
                shareOfPrevious: $previous !== null && $previous > 0 ? round($count / $previous * 100, 1) : null,
            );

            $previous = $count;
        }

        return $stages;
    }
}
