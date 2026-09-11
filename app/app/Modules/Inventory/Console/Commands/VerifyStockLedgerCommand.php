<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Console\Commands;

use App\Modules\Catalog\Models\InventoryLevel;
use App\Modules\Inventory\Models\StockBatch;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * The safety net under the projections.
 *
 * Invariant 1 says `inventory_levels.on_hand` equals the sum of the movements
 * for that (variant, warehouse), and `stock_batches.qty_on_hand` the sum for
 * that batch. Both are written in the same transaction as the movement, so
 * they should never disagree — but "should never" is what a verifier is for.
 * Anything that writes a projection outside the ledger, or a movement that
 * failed to roll one forward, shows up here.
 *
 * Exits non-zero on any drift so a scheduler or CI step fails loudly.
 */
final class VerifyStockLedgerCommand extends Command
{
    protected $signature = 'inventory:verify
        {--limit=50 : How many drifted rows to list}';

    protected $description = 'Recompute stock projections from the movement ledger and report any drift.';

    public function handle(): int
    {
        $limit = max(1, (int) $this->option('limit'));

        $levelDrift = $this->levelDrift();
        $batchDrift = $this->batchDrift();

        if ($levelDrift === [] && $batchDrift === []) {
            $this->components->info('Stock projections agree with the movement ledger.');

            return self::SUCCESS;
        }

        if ($levelDrift !== []) {
            $this->components->error(count($levelDrift).' inventory level(s) disagree with the ledger:');
            $this->table(
                ['Variant', 'Warehouse', 'on_hand', 'Σ movements', 'Drift'],
                array_slice($levelDrift, 0, $limit),
            );
        }

        if ($batchDrift !== []) {
            $this->components->error(count($batchDrift).' batch(es) disagree with the ledger:');
            $this->table(
                ['Batch', 'Variant', 'Warehouse', 'qty_on_hand', 'Σ movements', 'Drift'],
                array_slice($batchDrift, 0, $limit),
            );
        }

        return self::FAILURE;
    }

    /**
     * Levels whose projection differs from the sum of their movements.
     *
     * A (variant, warehouse) that has movements but no level row counts as
     * drift too — its stock is unreachable to every reader of the projection.
     *
     * @return list<array{int, string, int, int, int}>
     */
    private function levelDrift(): array
    {
        $sums = DB::table('stock_movements')
            ->select('product_variant_id', 'warehouse_code', DB::raw('SUM(qty) as total'))
            ->groupBy('product_variant_id', 'warehouse_code')
            ->get()
            ->mapWithKeys(static fn (object $row): array => [
                $row->product_variant_id.'|'.$row->warehouse_code => (int) $row->total,
            ])
            ->all();

        $drift = [];

        foreach (InventoryLevel::query()->orderBy('id')->lazyById(500) as $level) {
            $key = $level->product_variant_id.'|'.$level->warehouse_code;
            $expected = $sums[$key] ?? 0;
            unset($sums[$key]);

            if ($level->on_hand !== $expected) {
                $drift[] = [
                    $level->product_variant_id,
                    $level->warehouse_code,
                    $level->on_hand,
                    $expected,
                    $expected - $level->on_hand,
                ];
            }
        }

        // Whatever is left has movements but no level row to project onto.
        foreach ($sums as $key => $expected) {
            [$variantId, $warehouseCode] = explode('|', (string) $key, 2);
            $drift[] = [(int) $variantId, $warehouseCode, 0, $expected, $expected];
        }

        return $drift;
    }

    /**
     * Batches whose projection differs from the sum of their movements.
     *
     * @return list<array{string, int, string, int, int, int}>
     */
    private function batchDrift(): array
    {
        $sums = DB::table('stock_movements')
            ->whereNotNull('stock_batch_id')
            ->select('stock_batch_id', DB::raw('SUM(qty) as total'))
            ->groupBy('stock_batch_id')
            ->pluck('total', 'stock_batch_id')
            ->map(static fn (mixed $total): int => (int) $total)
            ->all();

        $drift = [];

        foreach (StockBatch::query()->orderBy('id')->lazyById(500) as $batch) {
            $expected = (int) ($sums[$batch->id] ?? 0);

            if ($batch->qty_on_hand !== $expected) {
                $drift[] = [
                    $batch->batch_no,
                    $batch->product_variant_id,
                    $batch->warehouse_code,
                    $batch->qty_on_hand,
                    $expected,
                    $expected - $batch->qty_on_hand,
                ];
            }
        }

        return $drift;
    }
}
