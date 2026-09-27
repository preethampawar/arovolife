<?php

declare(strict_types=1);

namespace App\Modules\Compensation\Services;

use App\Modules\Commerce\Models\Order;
use App\Modules\Commerce\Services\BvLedgerService;
use App\Modules\Compensation\Models\RepurchaseCycle;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The one-time message on an order confirmation when THIS order changed the
 * buyer's repurchase standing (client, 2026-09-28). Read-only: it never opens,
 * advances or resolves a cycle — the nightly evaluation does that. Eligibility
 * wording only; no rupee income figures (hard rule 3).
 */
final class RepurchaseOrderNotice
{
    public const RESTORED = 'restored';

    public const BV_MET = 'bv_met';

    public function __construct(
        private readonly RepurchaseCycleService $cycles,
        private readonly BvLedgerService $bvLedger,
        private readonly WalletService $wallet,
    ) {}

    /** @return array{kind: string, message: string}|null */
    public function for(Order $order, ?Carbon $today = null): ?array
    {
        $today ??= Carbon::today('Asia/Kolkata');
        $distributorId = $order->attributed_distributor_id;

        if (! $order->getAttribute('self_consumption') || $order->paid_at === null || $distributorId === null) {
            return null;
        }

        // The engine dates fulfilment on the day the order was PAID, so every
        // date here comes from that day, never from when the page is viewed.
        $paidDay = Carbon::instance($order->paid_at)->setTimezone('Asia/Kolkata')->startOfDay();

        $cycle = $this->cycles->currentCycle((int) $distributorId);
        if ($cycle === null) {
            return null;
        }

        $orderBv = (int) DB::table('bv_ledger_entries')
            ->where('order_id', $order->id)->where('type', 'accrual')->sum('bv_paise');
        if ($orderBv <= 0) {
            return null;
        }

        $after = $this->bvLedger->selfPurchaseBvPaise(
            (int) $distributorId,
            $cycle->cycle_start_date->copy()->startOfDay(),
            $paidDay->copy()->endOfDay(),
        );
        $before = $after - $orderBv;
        $required = (int) $cycle->required_bv_paise;

        if (! ($before < $required && $after >= $required)) {
            return null;
        }

        if ($cycle->status === RepurchaseCycle::STATUS_SUSPENDED) {
            if ($this->wallet->repurchaseWalletBalancePaise((int) $distributorId) > 0) {
                return null;
            }

            return [
                'kind' => self::RESTORED,
                'message' => 'Your repurchase requirement is met. Your bonus eligibility is restored from '
                    .($paidDay->isSameDay($today) ? 'today, ' : '').$paidDay->format('j M Y').'.',
            ];
        }

        if ($cycle->status === RepurchaseCycle::STATUS_COMPLETED) {
            return null;
        }

        return [
            'kind' => self::BV_MET,
            'message' => 'Your repurchase BV for this cycle is met. Keep your repurchase wallet at ₹0 on '
                .$cycle->due_date->format('j M Y').' to complete the cycle.',
        ];
    }
}
