<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Services;

use App\Modules\Compliance\Models\AuditLog;
use App\Modules\Compliance\Support\AuditDigests;
use App\Modules\Inventory\Models\PurchaseInvoice;
use App\Modules\Inventory\Models\PurchaseInvoiceItem;
use App\Modules\Inventory\Models\StockBatch;
use App\Modules\Inventory\Models\StockMovement;
use Illuminate\Database\DatabaseManager;
use RuntimeException;

/**
 * The supplier invoice and the goods receipt note are the same document
 * (plan §10): posting one is the only place stock physically enters a
 * warehouse from outside the ledger. Once posted it is immutable — a mistake
 * is undone by cancelling, which writes reversing movements, never a delete.
 */
final class PurchaseInvoiceService
{
    public function __construct(
        private readonly DatabaseManager $db,
        private readonly InventoryNumbering $numbering,
        private readonly StockLedger $ledger,
        private readonly PurchaseOrderService $purchaseOrders,
    ) {}

    /**
     * @param  array{supplier_id: int, purchase_order_id: int|null, warehouse_code: string,
     *                supplier_invoice_no: string, supplier_invoice_date: string, notes?: string|null}  $header
     * @param  list<array{product_variant_id: int, batch_no: string, mfg_date: string|null, expiry_date: string|null,
     *                     qty: int, unit_cost_paise: int, gst_rate_bp: int}>  $lines
     */
    public function createDraft(array $header, array $lines, int $actorUserId): PurchaseInvoice
    {
        return $this->db->transaction(function () use ($header, $lines, $actorUserId): PurchaseInvoice {
            $pi = PurchaseInvoice::create($header + [
                'grn_no' => $this->numbering->next('GRN'),
                'status' => PurchaseInvoice::STATUS_DRAFT,
                'created_by_user_id' => $actorUserId,
            ]);

            $this->replaceItems($pi, $lines);
            $this->audit('inventory.grn.created', $pi, null, $actorUserId);

            return $pi;
        });
    }

    /**
     * @param  array{supplier_id: int, purchase_order_id: int|null, warehouse_code: string,
     *                supplier_invoice_no: string, supplier_invoice_date: string, notes?: string|null}  $header
     * @param  list<array{product_variant_id: int, batch_no: string, mfg_date: string|null, expiry_date: string|null,
     *                     qty: int, unit_cost_paise: int, gst_rate_bp: int}>  $lines
     */
    public function updateDraft(PurchaseInvoice $pi, array $header, array $lines): PurchaseInvoice
    {
        if (! $pi->isEditable()) {
            throw new RuntimeException("GRN {$pi->grn_no} is no longer a draft and cannot be edited.");
        }

        return $this->db->transaction(function () use ($pi, $header, $lines): PurchaseInvoice {
            $pi->update($header);
            $this->replaceItems($pi, $lines);

            return $pi;
        });
    }

    /**
     * Post the GRN: one batch per line (merged when a line shares an existing
     * batch's variant/warehouse/batch_no), one `purchase_in` movement per
     * line, and — when raised against a PO — the PO's receipt progress.
     */
    public function post(PurchaseInvoice $pi, int $actorUserId): PurchaseInvoice
    {
        if ($pi->status !== PurchaseInvoice::STATUS_DRAFT) {
            throw new RuntimeException("GRN {$pi->grn_no} has already been posted.");
        }

        return $this->db->transaction(function () use ($pi, $actorUserId): PurchaseInvoice {
            $pi->loadMissing('items', 'purchaseOrder.items');
            $allocations = [];

            foreach ($pi->items as $item) {
                $batch = StockBatch::query()->firstOrCreate(
                    [
                        'product_variant_id' => $item->product_variant_id,
                        'warehouse_code' => $pi->warehouse_code,
                        'batch_no' => $item->batch_no,
                    ],
                    [
                        'mfg_date' => $item->mfg_date,
                        'expiry_date' => $item->expiry_date,
                        'unit_cost_paise' => $item->unit_cost_paise,
                        'qty_on_hand' => 0,
                        'received_at' => now(),
                        'source_type' => 'purchase_invoice_item',
                        'source_id' => $item->id,
                    ],
                );

                $this->ledger->post([
                    'type' => StockMovement::TYPE_PURCHASE_IN,
                    'variant_id' => $item->product_variant_id,
                    'warehouse_code' => $pi->warehouse_code,
                    'batch_id' => $batch->id,
                    'qty' => $item->qty,
                    'unit_cost_paise' => $item->unit_cost_paise,
                    'reference_type' => 'purchase_invoice_item',
                    'reference_id' => $item->id,
                    'actor_user_id' => $actorUserId,
                ]);

                $allocations[] = ['item_id' => $item->id, 'batch_no' => $item->batch_no, 'qty' => $item->qty];

                if ($pi->purchaseOrder !== null) {
                    $poItem = $pi->purchaseOrder->items->firstWhere('product_variant_id', $item->product_variant_id);
                    $poItem?->increment('qty_received', $item->qty);
                }
            }

            if ($pi->purchaseOrder !== null) {
                $this->purchaseOrders->rollReceivedStatus($pi->purchaseOrder->fresh('items'));
            }

            $before = AuditDigests::snapshot($pi);
            $pi->update([
                'status' => PurchaseInvoice::STATUS_POSTED,
                'posted_at' => now(),
                'posted_by_user_id' => $actorUserId,
            ]);
            $this->audit('inventory.grn.posted', $pi, $before, $actorUserId, ['allocations' => $allocations]);

            return $pi;
        });
    }

    /**
     * Only legal while every batch this GRN created still holds everything it
     * received — the moment a unit has sold out of one, the receipt is no
     * longer reversible without going negative, so the ledger refuses it
     * rather than silently reversing less than was received.
     */
    public function cancel(PurchaseInvoice $pi, string $reason, int $actorUserId): void
    {
        if (! $pi->isPosted()) {
            throw new RuntimeException("GRN {$pi->grn_no} is not posted and cannot be cancelled.");
        }

        $this->db->transaction(function () use ($pi, $reason, $actorUserId): void {
            $pi->loadMissing('items');
            $batches = [];

            foreach ($pi->items as $item) {
                $batch = StockBatch::query()
                    ->where('product_variant_id', $item->product_variant_id)
                    ->where('warehouse_code', $pi->warehouse_code)
                    ->where('batch_no', $item->batch_no)
                    ->first();

                if ($batch === null) {
                    throw new RuntimeException(
                        "Cannot cancel GRN {$pi->grn_no}: batch {$item->batch_no} has only 0 of the {$item->qty} received still on hand."
                    );
                }

                if ($batch->qty_on_hand < $item->qty) {
                    throw new RuntimeException(
                        "Cannot cancel GRN {$pi->grn_no}: batch {$item->batch_no} has only {$batch->qty_on_hand} of the {$item->qty} received still on hand."
                    );
                }

                $batches[$item->id] = $batch;
            }

            foreach ($pi->items as $item) {
                $this->ledger->post([
                    'type' => StockMovement::TYPE_PURCHASE_REVERSAL,
                    'variant_id' => $item->product_variant_id,
                    'warehouse_code' => $pi->warehouse_code,
                    'batch_id' => $batches[$item->id]->id,
                    'qty' => -$item->qty,
                    'unit_cost_paise' => $item->unit_cost_paise,
                    'reference_type' => 'purchase_invoice_item',
                    'reference_id' => $item->id,
                    'reason' => $reason,
                    'actor_user_id' => $actorUserId,
                ]);
            }

            $before = AuditDigests::snapshot($pi);
            $pi->update(['status' => PurchaseInvoice::STATUS_CANCELLED, 'cancelled_at' => now()]);
            $this->audit('inventory.grn.cancelled', $pi, $before, $actorUserId, ['reason' => $reason]);
        });
    }

    /**
     * @param  list<array{product_variant_id: int, batch_no: string, mfg_date: string|null, expiry_date: string|null,
     *                     qty: int, unit_cost_paise: int, gst_rate_bp: int}>  $lines
     */
    private function replaceItems(PurchaseInvoice $pi, array $lines): void
    {
        $pi->items()->delete();

        $subtotal = 0;
        $gstTotal = 0;

        foreach ($lines as $line) {
            $taxable = $line['qty'] * $line['unit_cost_paise'];
            $gst = intdiv($taxable * $line['gst_rate_bp'], 10_000);

            PurchaseInvoiceItem::create([
                'purchase_invoice_id' => $pi->id,
                'product_variant_id' => $line['product_variant_id'],
                'batch_no' => $line['batch_no'],
                'mfg_date' => $line['mfg_date'] ?? null,
                'expiry_date' => $line['expiry_date'] ?? null,
                'qty' => $line['qty'],
                'unit_cost_paise' => $line['unit_cost_paise'],
                'gst_rate_bp' => $line['gst_rate_bp'],
                'taxable_value_paise' => $taxable,
                'gst_paise' => $gst,
                'line_total_paise' => $taxable + $gst,
            ]);

            $subtotal += $taxable;
            $gstTotal += $gst;
        }

        $pi->update([
            'subtotal_paise' => $subtotal,
            'gst_paise' => $gstTotal,
            'total_paise' => $subtotal + $gstTotal,
        ]);
    }

    /**
     * @param  array<string, mixed>|null  $before
     * @param  array<string, mixed>  $extra
     */
    private function audit(string $action, PurchaseInvoice $pi, ?array $before, int $actorUserId, array $extra = []): void
    {
        AuditLog::create([
            'actor_id' => $actorUserId,
            'action' => $action,
            'subject_type' => PurchaseInvoice::class,
            'subject_id' => $pi->id,
            'before_hash' => $before !== null ? AuditDigests::of($before) : null,
            'after_hash' => AuditDigests::of($pi),
            'details' => ['grn_no' => $pi->grn_no, 'status' => $pi->status] + $extra,
        ]);
    }
}
