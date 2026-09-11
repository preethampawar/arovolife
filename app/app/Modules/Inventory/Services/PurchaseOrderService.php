<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Services;

use App\Modules\Compliance\Models\AuditLog;
use App\Modules\Compliance\Support\AuditDigests;
use App\Modules\Inventory\Models\PurchaseOrder;
use App\Modules\Inventory\Models\PurchaseOrderItem;
use Illuminate\Database\DatabaseManager;
use RuntimeException;

/**
 * The intent to buy. A purchase order moves no stock on its own; `qty_received`
 * is written by `PurchaseInvoiceService::post()` when a GRN is posted against
 * it, which also calls `rollReceivedStatus()` here to roll the status forward.
 */
final class PurchaseOrderService
{
    public function __construct(
        private readonly DatabaseManager $db,
        private readonly InventoryNumbering $numbering,
    ) {}

    /**
     * @param  array{supplier_id: int, warehouse_code: string, expected_at?: string|null, notes?: string|null}  $header
     * @param  list<array{product_variant_id: int, qty_ordered: int, unit_cost_paise: int}>  $lines
     */
    public function create(array $header, array $lines, int $actorUserId): PurchaseOrder
    {
        return $this->db->transaction(function () use ($header, $lines, $actorUserId): PurchaseOrder {
            $po = PurchaseOrder::create($header + [
                'po_no' => $this->numbering->next('PO'),
                'status' => PurchaseOrder::STATUS_DRAFT,
                'created_by_user_id' => $actorUserId,
            ]);

            $this->replaceItems($po, $lines);
            $this->audit('inventory.purchase_order.created', $po, null, $actorUserId);

            return $po;
        });
    }

    /**
     * @param  array{supplier_id: int, warehouse_code: string, expected_at?: string|null, notes?: string|null}  $header
     * @param  list<array{product_variant_id: int, qty_ordered: int, unit_cost_paise: int}>  $lines
     */
    public function update(PurchaseOrder $po, array $header, array $lines, int $actorUserId): PurchaseOrder
    {
        if (! $po->isEditable()) {
            throw new RuntimeException("Purchase order {$po->po_no} is no longer a draft and cannot be edited.");
        }

        return $this->db->transaction(function () use ($po, $header, $lines, $actorUserId): PurchaseOrder {
            $before = AuditDigests::snapshot($po);
            $po->update($header);
            $this->replaceItems($po, $lines);
            $this->audit('inventory.purchase_order.updated', $po, $before, $actorUserId);

            return $po;
        });
    }

    public function send(PurchaseOrder $po, int $actorUserId): PurchaseOrder
    {
        if ($po->status !== PurchaseOrder::STATUS_DRAFT) {
            throw new RuntimeException("Purchase order {$po->po_no} has already been sent.");
        }

        $before = AuditDigests::snapshot($po);
        $po->update(['status' => PurchaseOrder::STATUS_SENT, 'sent_at' => now()]);
        $this->audit('inventory.purchase_order.sent', $po, $before, $actorUserId);

        return $po;
    }

    public function cancel(PurchaseOrder $po, string $reason, int $actorUserId): void
    {
        if (in_array($po->status, [PurchaseOrder::STATUS_RECEIVED, PurchaseOrder::STATUS_CANCELLED], true)) {
            throw new RuntimeException("Purchase order {$po->po_no} cannot be cancelled from status [{$po->status}].");
        }

        $before = AuditDigests::snapshot($po);
        $po->update([
            'status' => PurchaseOrder::STATUS_CANCELLED,
            'closed_at' => now(),
            'notes' => trim(($po->notes ?? '')."\nCancelled: {$reason}"),
        ]);
        $this->audit('inventory.purchase_order.cancelled', $po, $before, $actorUserId, ['reason' => $reason]);
    }

    /**
     * Recompute the PO's status from its items' receipt progress. Called by
     * `PurchaseInvoiceService::post()` after it bumps `qty_received` — never
     * called directly against stock, so it never itself writes a movement.
     */
    public function rollReceivedStatus(PurchaseOrder $po): void
    {
        $po->loadMissing('items');
        $items = $po->items;

        if ($items->isEmpty()) {
            return;
        }

        $fullyReceived = $items->every(fn (PurchaseOrderItem $item): bool => $item->outstandingQty() === 0);
        $anyReceived = $items->contains(fn (PurchaseOrderItem $item): bool => $item->qty_received > 0);

        $status = match (true) {
            $fullyReceived => PurchaseOrder::STATUS_RECEIVED,
            $anyReceived => PurchaseOrder::STATUS_PARTIALLY_RECEIVED,
            default => $po->status,
        };

        if ($status !== $po->status) {
            $po->update([
                'status' => $status,
                'closed_at' => $status === PurchaseOrder::STATUS_RECEIVED ? now() : $po->closed_at,
            ]);
        }
    }

    /** @param  list<array{product_variant_id: int, qty_ordered: int, unit_cost_paise: int}>  $lines */
    private function replaceItems(PurchaseOrder $po, array $lines): void
    {
        $po->items()->delete();

        foreach ($lines as $line) {
            PurchaseOrderItem::create([
                'purchase_order_id' => $po->id,
                'product_variant_id' => $line['product_variant_id'],
                'qty_ordered' => $line['qty_ordered'],
                'qty_received' => 0,
                'unit_cost_paise' => $line['unit_cost_paise'],
            ]);
        }
    }

    /**
     * @param  array<string, mixed>|null  $before
     * @param  array<string, mixed>  $extra
     */
    private function audit(string $action, PurchaseOrder $po, ?array $before, int $actorUserId, array $extra = []): void
    {
        AuditLog::create([
            'actor_id' => $actorUserId,
            'action' => $action,
            'subject_type' => PurchaseOrder::class,
            'subject_id' => $po->id,
            'before_hash' => $before !== null ? AuditDigests::of($before) : null,
            'after_hash' => AuditDigests::of($po),
            'details' => ['po_no' => $po->po_no, 'status' => $po->status] + $extra,
        ]);
    }
}
