<?php

declare(strict_types=1);

namespace App\Modules\Commerce\Services;

use App\Modules\Commerce\Models\RedeemPointEntry;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * The redeem-point ledger.
 *
 * One point is one rupee off a future purchase. Points are **not money**: they
 * are a discount entitlement earned from a distributor's own purchases. They
 * are never paid out, attract no TDS and no admin charge, and deliberately do
 * not live in the wallet — a wallet balance that mixed cash the company owes
 * with a purchase discount would mean two things at once and reconcile as
 * neither.
 *
 * The balance is a projection of the entries, never a stored integer, for the
 * same reason the wallet works that way (ADR-0004).
 */
final class RedeemPointsService
{
    public function balance(int $distributorId): int
    {
        return (int) RedeemPointEntry::where('distributor_id', $distributorId)->sum('points');
    }

    public function accrue(
        int $distributorId,
        int $points,
        string $referenceType,
        ?int $referenceId,
        string $memo,
    ): RedeemPointEntry {
        if ($points <= 0) {
            throw new RuntimeException('An accrual must be positive; got '.$points.'.');
        }

        return RedeemPointEntry::create([
            'distributor_id' => $distributorId,
            'points' => $points,
            'type' => RedeemPointEntry::TYPE_ACCRUAL,
            'reference_type' => $referenceType,
            'reference_id' => $referenceId,
            'memo' => $memo,
            'created_at' => Carbon::now(),
        ]);
    }

    /**
     * Spend points against an order.
     *
     * Locks the distributor's entries and re-reads the balance inside the
     * transaction. Without that, two checkouts submitted together could each
     * see the same balance and both spend it — the classic double-spend, and
     * one that would be invisible afterwards because the ledger would simply
     * show a negative balance nobody could explain.
     *
     * **This depends on the isolation level, and that dependency is not
     * obvious.** `lockForUpdate()` over an aggregate locks the rows it reads;
     * it does not by itself stop a concurrent INSERT that would change the
     * sum. What stops that here is MySQL's default REPEATABLE READ, whose
     * next-key locks cover the gap in `idx_redeem_points_dist_time`. Under
     * READ COMMITTED there are no gap locks and the double-spend reopens.
     *
     * `config/database.php` sets no isolation override, so the default holds —
     * and `RedeemPointsIsolationTest` fails if that ever changes, because
     * nothing else would notice until the balances stopped adding up
     * (T-6.1 finding L-5).
     */
    public function redeem(int $distributorId, int $points, int $orderId, string $memo): RedeemPointEntry
    {
        if ($points <= 0) {
            throw new RuntimeException('A redemption must be positive; got '.$points.'.');
        }

        return DB::transaction(function () use ($distributorId, $points, $orderId, $memo): RedeemPointEntry {
            $available = (int) RedeemPointEntry::where('distributor_id', $distributorId)
                ->lockForUpdate()
                ->sum('points');

            if ($points > $available) {
                throw new RuntimeException(
                    'Cannot redeem '.$points.' points; the balance is '.$available.'.'
                );
            }

            return RedeemPointEntry::create([
                'distributor_id' => $distributorId,
                'points' => -$points,
                'type' => RedeemPointEntry::TYPE_REDEMPTION,
                'reference_type' => 'order',
                'reference_id' => $orderId,
                'memo' => $memo,
                'created_at' => Carbon::now(),
            ]);
        });
    }

    /**
     * Give points back when the order they paid for is cancelled or refunded.
     *
     * Idempotent: a second call for the same order finds the existing reversal
     * and does nothing, so a retried refund cannot mint points.
     */
    public function refundForOrder(int $orderId, string $memo): ?RedeemPointEntry
    {
        $spent = RedeemPointEntry::where('reference_type', 'order')
            ->where('reference_id', $orderId)
            ->where('type', RedeemPointEntry::TYPE_REDEMPTION)
            ->first();

        if ($spent === null) {
            return null;
        }

        $alreadyRefunded = RedeemPointEntry::where('reference_type', 'order')
            ->where('reference_id', $orderId)
            ->where('type', RedeemPointEntry::TYPE_ADJUSTMENT)
            ->exists();

        if ($alreadyRefunded) {
            return null;
        }

        return RedeemPointEntry::create([
            'distributor_id' => $spent->distributor_id,
            'points' => abs($spent->points),
            'type' => RedeemPointEntry::TYPE_ADJUSTMENT,
            'reference_type' => 'order',
            'reference_id' => $orderId,
            'memo' => $memo,
            'created_at' => Carbon::now(),
        ]);
    }

    /**
     * Rupee value of a point balance, in paise. One point is one rupee.
     */
    public function valueInPaise(int $points): int
    {
        return $points * 100;
    }

    /**
     * The most points that may be spent on an order.
     *
     * Capped at the **net product value** — subtotal less GST less discount —
     * never the total. Prices in this catalogue are GST-inclusive
     * (`Cart::totalPaise()` returns the subtotal unchanged and
     * `Cart::gstPaise()` extracts the tax out of it), so a cap taken from the
     * subtotal alone would let points pay the GST. The company remits that tax
     * in cash whatever the buyer paid with, and delivery is a real third-party
     * cost, so neither may be settled in points.
     */
    public function maxRedeemableForOrder(int $distributorId, int $subtotalPaise, int $gstPaise = 0, int $discountPaise = 0): int
    {
        $netProductPaise = max(0, $subtotalPaise - $gstPaise - $discountPaise);

        return (int) min($this->balance($distributorId), intdiv($netProductPaise, 100));
    }
}
