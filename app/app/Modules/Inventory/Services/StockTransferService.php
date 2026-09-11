<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Services;

use App\Modules\Compliance\Models\AuditLog;
use App\Modules\Compliance\Support\AuditDigests;
use App\Modules\Inventory\Models\StockBatch;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Models\StockTransfer;
use App\Modules\Inventory\Models\StockTransferItem;
use Illuminate\Database\DatabaseManager;
use RuntimeException;

/**
 * Moves stock between two warehouses in two steps, matching how it actually
 * happens: dispatch takes it out of the source the moment it leaves, receive
 * puts it into the destination once someone there has counted it in. Between
 * the two there is deliberately no "in transit" warehouse (plan §10) — the
 * stock is simply not in any location's on_hand until it is received.
 */
final class StockTransferService
{
    public function __construct(
        private readonly DatabaseManager $db,
        private readonly InventoryNumbering $numbering,
        private readonly StockLedger $ledger,
        private readonly WarehouseService $warehouses,
    ) {}

    /**
     * @param  list<array{product_variant_id: int, stock_batch_id: int, qty: int}>  $lines
     */
    public function createDraft(string $fromWarehouseCode, string $toWarehouseCode, array $lines, int $actorUserId): StockTransfer
    {
        if ($fromWarehouseCode === $toWarehouseCode) {
            throw new RuntimeException('A transfer must move stock between two different warehouses.');
        }

        $this->warehouses->assertActive($fromWarehouseCode);
        $this->warehouses->assertActive($toWarehouseCode);

        if ($lines === []) {
            throw new RuntimeException('Add at least one line item.');
        }

        return $this->db->transaction(function () use ($fromWarehouseCode, $toWarehouseCode, $lines, $actorUserId): StockTransfer {
            $transfer = StockTransfer::create([
                'transfer_no' => $this->numbering->next('TRF'),
                'from_warehouse_code' => $fromWarehouseCode,
                'to_warehouse_code' => $toWarehouseCode,
                'status' => StockTransfer::STATUS_DRAFT,
                'created_by_user_id' => $actorUserId,
            ]);

            foreach ($lines as $line) {
                $batch = StockBatch::query()->find($line['stock_batch_id']);

                if ($batch === null || $batch->warehouse_code !== $fromWarehouseCode || $batch->product_variant_id !== $line['product_variant_id']) {
                    throw new RuntimeException("Batch #{$line['stock_batch_id']} does not belong to the source warehouse for that product.");
                }

                $available = $this->ledger->available($line['product_variant_id'], $fromWarehouseCode);

                if ($line['qty'] > $available) {
                    throw new RuntimeException(
                        "Cannot transfer {$line['qty']} of variant #{$line['product_variant_id']}: only {$available} available at {$fromWarehouseCode}."
                    );
                }

                StockTransferItem::create([
                    'stock_transfer_id' => $transfer->id,
                    'product_variant_id' => $line['product_variant_id'],
                    'stock_batch_id' => $batch->id,
                    'qty' => $line['qty'],
                ]);
            }

            $this->audit('inventory.transfer.created', $transfer, null, $actorUserId);

            return $transfer;
        });
    }

    /**
     * Nothing is added anywhere until `receive()` — this only takes stock out
     * of the source, from the exact batches chosen at draft time.
     */
    public function dispatch(StockTransfer $transfer, int $actorUserId): StockTransfer
    {
        if ($transfer->status !== StockTransfer::STATUS_DRAFT) {
            throw new RuntimeException("Transfer {$transfer->transfer_no} has already been dispatched.");
        }

        return $this->db->transaction(function () use ($transfer, $actorUserId): StockTransfer {
            $transfer->loadMissing('items');

            foreach ($transfer->items as $item) {
                $this->ledger->post([
                    'type' => StockMovement::TYPE_TRANSFER_OUT,
                    'variant_id' => $item->product_variant_id,
                    'warehouse_code' => $transfer->from_warehouse_code,
                    'batch_id' => $item->stock_batch_id,
                    'qty' => -$item->qty,
                    'reference_type' => 'stock_transfer_item',
                    'reference_id' => $item->id,
                    'actor_user_id' => $actorUserId,
                ]);
            }

            $before = AuditDigests::snapshot($transfer);
            $transfer->update([
                'status' => StockTransfer::STATUS_DISPATCHED,
                'dispatched_at' => now(),
                'dispatched_by_user_id' => $actorUserId,
            ]);
            $this->audit('inventory.transfer.dispatched', $transfer, $before, $actorUserId);

            return $transfer;
        });
    }

    /**
     * Receives the dispatched quantity in full and, when the destination
     * counted less than that, writes the shortfall off explicitly rather than
     * quietly receiving less — the loss must show up in a report somewhere.
     *
     * @param  array<int, int>|null  $receivedQtyByItemId  keyed by stock_transfer_items.id; defaults to the dispatched qty
     */
    public function receive(StockTransfer $transfer, int $actorUserId, ?array $receivedQtyByItemId = null): StockTransfer
    {
        if ($transfer->status !== StockTransfer::STATUS_DISPATCHED) {
            throw new RuntimeException("Transfer {$transfer->transfer_no} is not awaiting receipt.");
        }

        return $this->db->transaction(function () use ($transfer, $actorUserId, $receivedQtyByItemId): StockTransfer {
            $transfer->loadMissing('items.batch');

            foreach ($transfer->items as $item) {
                $sourceBatch = $item->batch;
                $dispatchedQty = $item->qty;
                $receivedQty = $receivedQtyByItemId[$item->id] ?? $dispatchedQty;

                if ($receivedQty < 0 || $receivedQty > $dispatchedQty) {
                    throw new RuntimeException(
                        "Received quantity for item #{$item->id} must be between 0 and the dispatched {$dispatchedQty}."
                    );
                }

                $destBatch = StockBatch::query()->firstOrCreate(
                    [
                        'product_variant_id' => $item->product_variant_id,
                        'warehouse_code' => $transfer->to_warehouse_code,
                        'batch_no' => $sourceBatch->batch_no,
                    ],
                    [
                        'mfg_date' => $sourceBatch->mfg_date,
                        'expiry_date' => $sourceBatch->expiry_date,
                        'unit_cost_paise' => $sourceBatch->unit_cost_paise,
                        'qty_on_hand' => 0,
                        'received_at' => now(),
                        'source_type' => 'stock_transfer_item',
                        'source_id' => $item->id,
                    ],
                );

                $this->ledger->post([
                    'type' => StockMovement::TYPE_TRANSFER_IN,
                    'variant_id' => $item->product_variant_id,
                    'warehouse_code' => $transfer->to_warehouse_code,
                    'batch_id' => $destBatch->id,
                    'qty' => $dispatchedQty,
                    'unit_cost_paise' => $sourceBatch->unit_cost_paise,
                    'reference_type' => 'stock_transfer_item',
                    'reference_id' => $item->id,
                    'actor_user_id' => $actorUserId,
                ]);

                if ($receivedQty < $dispatchedQty) {
                    $this->ledger->post([
                        'type' => StockMovement::TYPE_WRITE_OFF,
                        'variant_id' => $item->product_variant_id,
                        'warehouse_code' => $transfer->to_warehouse_code,
                        'batch_id' => $destBatch->id,
                        'qty' => -($dispatchedQty - $receivedQty),
                        'unit_cost_paise' => $sourceBatch->unit_cost_paise,
                        'reference_type' => 'stock_transfer_item',
                        'reference_id' => $item->id,
                        'reason' => 'transit_shortage',
                        'actor_user_id' => $actorUserId,
                    ]);
                }
            }

            $before = AuditDigests::snapshot($transfer);
            $transfer->update([
                'status' => StockTransfer::STATUS_RECEIVED,
                'received_at' => now(),
                'received_by_user_id' => $actorUserId,
            ]);
            $this->audit('inventory.transfer.received', $transfer, $before, $actorUserId);

            return $transfer;
        });
    }

    public function cancel(StockTransfer $transfer, int $actorUserId): void
    {
        if ($transfer->status !== StockTransfer::STATUS_DRAFT) {
            throw new RuntimeException(
                "Transfer {$transfer->transfer_no} has already been dispatched; receive it back at the source instead of cancelling."
            );
        }

        $before = AuditDigests::snapshot($transfer);
        $transfer->update(['status' => StockTransfer::STATUS_CANCELLED]);
        $this->audit('inventory.transfer.cancelled', $transfer, $before, $actorUserId);
    }

    /** @param  array<string, mixed>|null  $before */
    private function audit(string $action, StockTransfer $transfer, ?array $before, int $actorUserId): void
    {
        AuditLog::create([
            'actor_id' => $actorUserId,
            'action' => $action,
            'subject_type' => StockTransfer::class,
            'subject_id' => $transfer->id,
            'before_hash' => $before !== null ? AuditDigests::of($before) : null,
            'after_hash' => AuditDigests::of($transfer),
            'details' => [
                'transfer_no' => $transfer->transfer_no,
                'status' => $transfer->status,
                'from' => $transfer->from_warehouse_code,
                'to' => $transfer->to_warehouse_code,
            ],
        ]);
    }
}
