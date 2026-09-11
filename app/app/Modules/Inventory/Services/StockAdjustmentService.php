<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Services;

use App\Modules\Compliance\Models\AuditLog;
use App\Modules\Compliance\Support\AuditDigests;
use App\Modules\Inventory\Models\StockAdjustment;
use App\Modules\Inventory\Models\StockMovement;
use Illuminate\Database\DatabaseManager;
use InvalidArgumentException;

/**
 * The catch-all for stock changes that are neither a purchase, a sale, a
 * transfer nor a return: physical counts, damage, expiry write-offs, theft,
 * samples. Every adjustment must carry a reason an auditor can read, so
 * `notes` is required, and the reasons that mean the goods are gone rather
 * than miscounted (plan §3.1, `StockAdjustment::WRITE_OFF_REASONS`) post a
 * `write_off` movement instead of a plain `adjustment_out`.
 */
final class StockAdjustmentService
{
    public function __construct(
        private readonly DatabaseManager $db,
        private readonly InventoryNumbering $numbering,
        private readonly StockLedger $ledger,
    ) {}

    public function adjust(
        string $warehouseCode,
        int $variantId,
        ?int $batchId,
        int $qtyDelta,
        string $reason,
        string $notes,
        int $actorUserId,
    ): StockAdjustment {
        if ($qtyDelta === 0) {
            throw new InvalidArgumentException('An adjustment must have a non-zero quantity.');
        }

        if (! in_array($reason, StockAdjustment::REASONS, true)) {
            throw new InvalidArgumentException("Unknown adjustment reason [{$reason}].");
        }

        if (trim($notes) === '') {
            throw new InvalidArgumentException('An adjustment must have notes explaining why.');
        }

        $type = match (true) {
            $qtyDelta > 0 => StockMovement::TYPE_ADJUSTMENT_IN,
            in_array($reason, StockAdjustment::WRITE_OFF_REASONS, true) => StockMovement::TYPE_WRITE_OFF,
            default => StockMovement::TYPE_ADJUSTMENT_OUT,
        };

        return $this->db->transaction(function () use ($warehouseCode, $variantId, $batchId, $qtyDelta, $reason, $notes, $actorUserId, $type): StockAdjustment {
            $occurredAt = now();

            $adjustment = StockAdjustment::create([
                'adjustment_no' => $this->numbering->next('ADJ'),
                'warehouse_code' => $warehouseCode,
                'product_variant_id' => $variantId,
                'stock_batch_id' => $batchId,
                'qty_delta' => $qtyDelta,
                'reason' => $reason,
                'notes' => trim($notes),
                'actor_user_id' => $actorUserId,
                'occurred_at' => $occurredAt,
            ]);

            $this->ledger->post([
                'type' => $type,
                'variant_id' => $variantId,
                'warehouse_code' => $warehouseCode,
                'batch_id' => $batchId,
                'qty' => $qtyDelta,
                'reference_type' => 'stock_adjustment',
                'reference_id' => $adjustment->id,
                'reason' => $reason,
                'actor_user_id' => $actorUserId,
                'occurred_at' => $occurredAt,
            ]);

            AuditLog::create([
                'actor_id' => $actorUserId,
                'action' => 'inventory.adjustment.created',
                'subject_type' => StockAdjustment::class,
                'subject_id' => $adjustment->id,
                'before_hash' => null,
                'after_hash' => AuditDigests::of($adjustment),
                'details' => [
                    'adjustment_no' => $adjustment->adjustment_no,
                    'warehouse_code' => $warehouseCode,
                    'product_variant_id' => $variantId,
                    'qty_delta' => $qtyDelta,
                    'reason' => $reason,
                ],
            ]);

            return $adjustment;
        });
    }
}
