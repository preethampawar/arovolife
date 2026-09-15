<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Services;

use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Services\DTOs\LineCost;
use Illuminate\Support\Facades\DB;

/**
 * The single owner of "what did this sale cost us" (plan D5).
 *
 * ## Where the real answer comes from
 *
 * `OrderFulfilmentService::pack()` allocates FEFO batches and writes a
 * `sale_out` movement per (order line, batch) stamped with that batch's
 * `unit_cost_paise`. Since landed cost now flows into the batch, that stamp is
 * a genuine historical COGS — frozen at the moment of sale, immune to anyone
 * editing a product afterwards. Netting `sale_out` against `sale_reversal`
 * handles returns and cancellations without a second code path.
 *
 * ## Why there is a fallback ladder at all
 *
 * Three real conditions produce a sale with no movement behind it, and all
 * three are silent:
 *
 *  1. `packForShipment()` logs a warning and ships anyway when the inventory
 *     feature is off or stock is short.
 *  2. Variants on `inventory_policy = 'no_track'` are never packed.
 *  3. Orders that predate the stock ledger have no movements at all.
 *
 * Reporting those as zero cost would show them at 100% margin and flatter the
 * whole report. Reporting them at a guessed cost without saying so would be
 * worse. So each line carries the basis it was resolved on, and the caller is
 * expected to show it.
 */
final class CogsResolver
{
    /**
     * Cost per order line.
     *
     * @param  list<int>  $orderIds
     * @return array<int, LineCost> keyed by order_item id
     */
    public function forOrders(array $orderIds): array
    {
        if ($orderIds === []) {
            return [];
        }

        $lines = DB::table('order_items')
            ->join('product_variants', 'product_variants.id', '=', 'order_items.product_variant_id')
            ->whereIn('order_items.order_id', $orderIds)
            ->get([
                'order_items.id',
                'order_items.order_id',
                'order_items.product_variant_id',
                'order_items.qty',
                'product_variants.landing_price_paise',
                'product_variants.cost_paise',
            ]);

        $actual = $this->actualCostByOrderItem(array_map('intval', array_values($lines->pluck('id')->all())));
        $landedBatches = $this->landedBatchIds($actual);

        $out = [];

        foreach ($lines as $line) {
            $itemId = (int) $line->id;

            $out[$itemId] = $this->resolve(
                itemId: $itemId,
                orderId: (int) $line->order_id,
                variantId: (int) $line->product_variant_id,
                qty: (int) $line->qty,
                landingPricePaise: (int) $line->landing_price_paise,
                costPaise: (int) $line->cost_paise,
                actual: $actual[$itemId] ?? null,
                landedBatches: $landedBatches,
            );
        }

        return $out;
    }

    /**
     * Total cost per order, for views that do not need line detail.
     *
     * @param  list<int>  $orderIds
     * @return array<int, array{cogs_paise: int, units: int, actual: bool}>
     */
    public function totalsByOrder(array $orderIds): array
    {
        $totals = [];

        foreach ($this->forOrders($orderIds) as $line) {
            $current = $totals[$line->orderId] ?? ['cogs_paise' => 0, 'units' => 0, 'actual' => true];

            $totals[$line->orderId] = [
                'cogs_paise' => $current['cogs_paise'] + $line->cogsPaise,
                'units' => $current['units'] + $line->units,
                // One guessed line makes the whole order's cost a guess.
                'actual' => $current['actual'] && $line->isActual(),
            ];
        }

        return $totals;
    }

    /**
     * Actual movement cost per order line, netting reversals against sales.
     *
     * `sale_out.qty` is negative and `sale_reversal.qty` positive, so negating
     * the sum gives units still sold and cost still incurred. A fully returned
     * line nets to zero and correctly contributes no cost.
     *
     * @param  list<int>  $orderItemIds
     * @return array<int, array{units: int, cogs_paise: int, batch_ids: list<int>}>
     */
    private function actualCostByOrderItem(array $orderItemIds): array
    {
        if ($orderItemIds === []) {
            return [];
        }

        $rows = DB::table('stock_movements')
            ->where('reference_type', 'order_item')
            ->whereIn('type', [StockMovement::TYPE_SALE_OUT, StockMovement::TYPE_SALE_REVERSAL])
            ->whereIn('reference_id', $orderItemIds)
            ->groupBy('reference_id', 'stock_batch_id')
            ->get([
                DB::raw('reference_id as order_item_id'),
                DB::raw('stock_batch_id'),
                DB::raw('COALESCE(SUM(-qty), 0) as units'),
                DB::raw('COALESCE(SUM(-qty * unit_cost_paise), 0) as cogs_paise'),
            ]);

        $out = [];

        foreach ($rows as $row) {
            $id = (int) $row->order_item_id;
            $current = $out[$id] ?? ['units' => 0, 'cogs_paise' => 0, 'batch_ids' => []];

            $current['units'] += (int) $row->units;
            $current['cogs_paise'] += (int) $row->cogs_paise;

            if ($row->stock_batch_id !== null) {
                $current['batch_ids'][] = (int) $row->stock_batch_id;
            }

            $out[$id] = $current;
        }

        return $out;
    }

    /**
     * Which of the batches involved came from a receipt that actually carried
     * freight and other charges — i.e. which costs are genuinely landed rather
     * than bare supplier price.
     *
     * @param  array<int, array{units: int, cogs_paise: int, batch_ids: list<int>}>  $actual
     * @return array<int, true>
     */
    private function landedBatchIds(array $actual): array
    {
        $lists = array_column($actual, 'batch_ids');
        $batchIds = array_values(array_unique($lists === [] ? [] : array_merge(...$lists)));

        if ($batchIds === []) {
            return [];
        }

        /** @var array<int, true> $landed */
        $landed = DB::table('stock_batches')
            ->join('purchase_invoice_items', function ($join): void {
                $join->on('purchase_invoice_items.id', '=', 'stock_batches.source_id')
                    ->where('stock_batches.source_type', '=', 'purchase_invoice_item');
            })
            ->join('purchase_invoices', 'purchase_invoices.id', '=', 'purchase_invoice_items.purchase_invoice_id')
            ->whereIn('stock_batches.id', $batchIds)
            ->whereRaw('(purchase_invoices.freight_paise + purchase_invoices.insurance_paise '.
                '+ purchase_invoices.handling_paise + purchase_invoices.other_charges_paise) > 0')
            ->pluck('stock_batches.id')
            ->flip()
            ->map(static fn (): bool => true)
            ->all();

        return $landed;
    }

    /**
     * @param  array{units: int, cogs_paise: int, batch_ids: list<int>}|null  $actual
     * @param  array<int, true>  $landedBatches
     */
    private function resolve(
        int $itemId,
        int $orderId,
        int $variantId,
        int $qty,
        int $landingPricePaise,
        int $costPaise,
        ?array $actual,
        array $landedBatches,
    ): LineCost {
        if ($actual !== null && $actual['units'] > 0) {
            // A line drawn from several batches is landed only if every batch
            // behind it was: half a landed cost is not a landed cost.
            $allLanded = $actual['batch_ids'] !== []
                && count(array_filter($actual['batch_ids'], static fn (int $b): bool => isset($landedBatches[$b])))
                    === count(array_unique($actual['batch_ids']));

            return new LineCost(
                orderItemId: $itemId,
                orderId: $orderId,
                productVariantId: $variantId,
                units: $actual['units'],
                cogsPaise: $actual['cogs_paise'],
                basis: $allLanded ? LineCost::BASIS_LANDED : LineCost::BASIS_SUPPLIER,
            );
        }

        if ($landingPricePaise > 0) {
            return new LineCost($itemId, $orderId, $variantId, $qty, $qty * $landingPricePaise, LineCost::BASIS_DERIVED);
        }

        if ($costPaise > 0) {
            return new LineCost($itemId, $orderId, $variantId, $qty, $qty * $costPaise, LineCost::BASIS_ESTIMATED);
        }

        return new LineCost($itemId, $orderId, $variantId, $qty, 0, LineCost::BASIS_UNKNOWN);
    }
}
