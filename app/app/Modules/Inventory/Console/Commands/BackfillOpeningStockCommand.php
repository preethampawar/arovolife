<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Console\Commands;

use App\Modules\Catalog\Models\InventoryLevel;
use App\Modules\Inventory\Models\StockBatch;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Services\StockLedger;
use Illuminate\Console\Command;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Facades\DB;

/**
 * Give the stock that already exists a ledger to stand on.
 *
 * Before this module, `inventory_levels.on_hand` was written directly — by the
 * seeders and by the admin product form — so the numbers in a live database
 * have no movements behind them. Once `on_hand` is a projection, an unexplained
 * number breaks invariant 1 and `inventory:verify` reports it as drift.
 *
 * So for each level holding stock with no movement history: zero the
 * projection, then post an `opening` movement of exactly what was there into an
 * `OPENING` batch. The number is unchanged; it now has a ledger under it.
 *
 * Idempotent by design — a level that already has any movement is skipped
 * entirely, so a second run does nothing. Run it once per environment after
 * deploying the module, before turning the flag on.
 */
final class BackfillOpeningStockCommand extends Command
{
    public const OPENING_BATCH_NO = 'OPENING';

    protected $signature = 'inventory:backfill-opening
        {--dry-run : Report what would be opened without writing anything}';

    protected $description = 'Convert pre-ledger inventory_levels.on_hand values into opening movements (run once per environment).';

    public function __construct(
        private readonly StockLedger $ledger,
        private readonly DatabaseManager $db,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');

        // Every (variant, warehouse) that already has a ledger entry. Those are
        // opened already — or are genuinely at zero — and must not be touched.
        $withMovements = StockMovement::query()
            ->select('product_variant_id', 'warehouse_code')
            ->distinct()
            ->get()
            ->mapWithKeys(static fn (StockMovement $m): array => [$m->product_variant_id.'|'.$m->warehouse_code => true])
            ->all();

        $opened = 0;
        $skipped = 0;
        $units = 0;

        $levels = InventoryLevel::query()
            ->where('on_hand', '>', 0)
            ->orderBy('id')
            ->lazyById(200);

        foreach ($levels as $level) {
            if (isset($withMovements[$level->product_variant_id.'|'.$level->warehouse_code])) {
                $skipped++;

                continue;
            }

            $opened++;
            $units += $level->on_hand;

            if ($dryRun) {
                continue;
            }

            $this->openLevel($level);
        }

        $this->components->info(sprintf(
            '%s %d level(s), %d unit(s). Skipped %d level(s) that already have movements.',
            $dryRun ? 'Would open' : 'Opened',
            $opened,
            $units,
            $skipped,
        ));

        return self::SUCCESS;
    }

    /**
     * Zero the projection and re-post it as a movement, in one transaction.
     *
     * The order matters: `StockLedger::post()` adds to the projection, so
     * posting the opening quantity against the existing number would double it.
     */
    private function openLevel(InventoryLevel $level): void
    {
        $this->db->transaction(function () use ($level): void {
            $quantity = $level->on_hand;

            $unitCost = (int) DB::table('product_variants')
                ->where('id', $level->product_variant_id)
                ->value('cost_paise');

            $batch = StockBatch::query()->firstOrCreate(
                [
                    'product_variant_id' => $level->product_variant_id,
                    'warehouse_code' => $level->warehouse_code,
                    'batch_no' => self::OPENING_BATCH_NO,
                ],
                [
                    'mfg_date' => null,
                    'expiry_date' => null,
                    'unit_cost_paise' => $unitCost,
                    'qty_on_hand' => 0,
                    'received_at' => now(),
                    'source_type' => 'adjustment',
                    'source_id' => null,
                ],
            );

            $level->on_hand = 0;
            $level->save();

            $this->ledger->post([
                'type' => StockMovement::TYPE_OPENING,
                'variant_id' => $level->product_variant_id,
                'warehouse_code' => $level->warehouse_code,
                'batch_id' => $batch->id,
                'qty' => $quantity,
                'unit_cost_paise' => $unitCost,
                'reason' => 'Opening stock',
            ]);
        });
    }
}
