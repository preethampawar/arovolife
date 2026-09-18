<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Services;

use App\Modules\Commerce\Models\Order;
use Illuminate\Support\Facades\DB;

/**
 * The truth behind `inventory_levels.reserved`.
 *
 * `reserved` is a counter, not a projection of the movement ledger: checkout
 * raises it at placement, and pack (where the units become a `sale_out`) or
 * cancel lowers it again. Nothing replays it, so the ledger invariant in
 * `inventory:verify` cannot see it drift. This recomputes it from the only
 * thing that can still be holding one — a placed-or-paid order that has not
 * been packed — which makes a stranded reservation visible.
 *
 * Reserve and release both go through the variant's `inventory` relation
 * rather than a named warehouse, so everything here is counted per variant:
 * the unit those writes actually use.
 */
final class ReservationAudit
{
    /**
     * Units that open orders still legitimately hold, per variant.
     *
     * @return array<int, int>
     */
    public function expected(): array
    {
        return DB::table('order_items')
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->join('product_variants', 'product_variants.id', '=', 'order_items.product_variant_id')
            ->whereIn('orders.status', [Order::STATUS_PLACED, Order::STATUS_PAID])
            ->whereNull('orders.packed_at')
            ->where('product_variants.inventory_policy', 'track')
            ->groupBy('order_items.product_variant_id')
            ->select('order_items.product_variant_id', DB::raw('SUM(order_items.qty) as total'))
            ->get()
            ->mapWithKeys(static fn (object $row): array => [(int) $row->product_variant_id => (int) $row->total])
            ->all();
    }

    /**
     * Units the projection currently holds, per variant.
     *
     * @return array<int, int>
     */
    public function held(): array
    {
        return DB::table('inventory_levels')
            ->groupBy('product_variant_id')
            ->select('product_variant_id', DB::raw('SUM(reserved) as total'))
            ->get()
            ->mapWithKeys(static fn (object $row): array => [(int) $row->product_variant_id => (int) $row->total])
            ->all();
    }

    /**
     * Every variant where the two disagree, lowest id first.
     *
     * @return list<array{variant_id: int, reserved: int, expected: int, drift: int}>
     */
    public function drift(): array
    {
        $held = $this->held();
        $expected = $this->expected();

        $drift = [];

        foreach (array_keys($held + $expected) as $variantId) {
            $reserved = $held[$variantId] ?? 0;
            $open = $expected[$variantId] ?? 0;

            if ($reserved !== $open) {
                $drift[] = [
                    'variant_id' => $variantId,
                    'reserved' => $reserved,
                    'expected' => $open,
                    'drift' => $open - $reserved,
                ];
            }
        }

        usort($drift, static fn (array $a, array $b): int => $a['variant_id'] <=> $b['variant_id']);

        return $drift;
    }
}
