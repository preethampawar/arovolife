<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Services;

use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Catalog\Services\LandingPriceService;
use App\Modules\Compliance\Models\AuditLog;
use App\Modules\Compliance\Support\AuditDigests;
use App\Modules\Inventory\Models\PurchaseInvoice;
use App\Modules\Inventory\Models\PurchaseInvoiceItem;
use App\Modules\Inventory\Models\StockBatch;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Services\DTOs\LandedCostLine;
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
        private readonly LandedCostAllocator $landedCost,
        private readonly LandingPriceService $landingPrices,
    ) {}

    /**
     * @param  array{supplier_id: int, purchase_order_id: int|null, warehouse_code: string,
     *                supplier_invoice_no: string, supplier_invoice_date: string, notes?: string|null,
     *                freight_paise?: int, insurance_paise?: int, handling_paise?: int,
     *                other_charges_paise?: int, allocation_basis?: string}  $header
     * @param  list<array{product_variant_id: int, batch_no: string, mfg_date: string|null, expiry_date: string|null,
     *                     qty: int, unit_cost_paise: int, gst_rate_bp: int, weight_g?: int}>  $lines
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
     *                supplier_invoice_no: string, supplier_invoice_date: string, notes?: string|null,
     *                freight_paise?: int, insurance_paise?: int, handling_paise?: int,
     *                other_charges_paise?: int, allocation_basis?: string}  $header
     * @param  list<array{product_variant_id: int, batch_no: string, mfg_date: string|null, expiry_date: string|null,
     *                     qty: int, unit_cost_paise: int, gst_rate_bp: int, weight_g?: int}>  $lines
     */
    public function updateDraft(PurchaseInvoice $pi, array $header, array $lines, int $actorUserId): PurchaseInvoice
    {
        if (! $pi->isEditable()) {
            throw new RuntimeException("GRN {$pi->grn_no} is no longer a draft and cannot be edited.");
        }

        // A draft's charges decide the batch cost, the stock valuation, COGS
        // and the derived landing price the moment it is posted. Posting
        // records only the end state, so without a before/after here the
        // freight on a receipt could be edited to any number and leave no
        // trace of what it was.
        $before = AuditDigests::snapshot($pi);

        return $this->db->transaction(function () use ($pi, $header, $lines, $before, $actorUserId): PurchaseInvoice {
            $pi->update($header);
            $this->replaceItems($pi, $lines);
            $this->audit('inventory.grn.updated', $pi, $before, $actorUserId);

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
                        'unit_cost_paise' => $item->landed_unit_cost_paise,
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
                    'unit_cost_paise' => $item->landed_unit_cost_paise,
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

            // Inside the transaction on purpose: if the landing price cannot be
            // written, the receipt that justifies it must not stand either.
            $this->landingPrices->recomputeForInvoice($pi, $actorUserId, "GRN {$pi->grn_no} posted");

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
                    'unit_cost_paise' => $item->landed_unit_cost_paise,
                    'reference_type' => 'purchase_invoice_item',
                    'reference_id' => $item->id,
                    'reason' => $reason,
                    'actor_user_id' => $actorUserId,
                ]);
            }

            $before = AuditDigests::snapshot($pi);
            $pi->update(['status' => PurchaseInvoice::STATUS_CANCELLED, 'cancelled_at' => now()]);
            $this->audit('inventory.grn.cancelled', $pi, $before, $actorUserId, ['reason' => $reason]);

            $this->landingPrices->recomputeForInvoice($pi, $actorUserId, "GRN {$pi->grn_no} cancelled");
        });
    }

    /**
     * Rebuild the GRN's lines and re-derive every money column on the header.
     *
     * Two passes, deliberately. The first computes each line's taxable value,
     * because charges are allocated in proportion to it; the second writes the
     * rows once the allocation is known. Trying to do it in one pass means
     * guessing a line's share before the total exists.
     *
     * @param  list<array{product_variant_id: int, batch_no: string, mfg_date: string|null, expiry_date: string|null,
     *                     qty: int, unit_cost_paise: int, gst_rate_bp: int, weight_g?: int}>  $lines
     */
    private function replaceItems(PurchaseInvoice $pi, array $lines): void
    {
        $pi->items()->delete();

        $basis = $pi->allocation_basis !== '' ? $pi->allocation_basis : LandedCostAllocator::BASIS_VALUE;
        $charges = $pi->freight_paise + $pi->insurance_paise + $pi->handling_paise + $pi->other_charges_paise;

        $measured = [];
        $gstByLine = [];
        $weights = $basis === LandedCostAllocator::BASIS_WEIGHT
            ? $this->variantWeights($lines)
            : [];

        foreach ($lines as $i => $line) {
            $taxable = $line['qty'] * $line['unit_cost_paise'];
            $measured[] = [
                'qty' => $line['qty'],
                'taxable_value_paise' => $taxable,
                'weight_g' => $line['weight_g'] ?? ($weights[$line['product_variant_id']] ?? 0),
            ];
            $gstByLine[$i] = intdiv($taxable * $line['gst_rate_bp'], 10_000);
        }

        $allocation = $this->landedCost->allocate($measured, $charges, $basis);

        $subtotal = 0;
        $gstTotal = 0;

        foreach ($lines as $i => $line) {
            /** @var LandedCostLine $allocated */
            $allocated = $allocation->lines[$i];
            $gst = $gstByLine[$i];

            PurchaseInvoiceItem::create([
                'purchase_invoice_id' => $pi->id,
                'product_variant_id' => $line['product_variant_id'],
                'batch_no' => $line['batch_no'],
                'mfg_date' => $line['mfg_date'] ?? null,
                'expiry_date' => $line['expiry_date'] ?? null,
                'qty' => $line['qty'],
                'unit_cost_paise' => $line['unit_cost_paise'],
                'gst_rate_bp' => $line['gst_rate_bp'],
                'taxable_value_paise' => $allocated->taxableValuePaise,
                'allocated_charges_paise' => $allocated->allocatedChargesPaise,
                'landed_unit_cost_paise' => $allocated->landedUnitCostPaise,
                'gst_paise' => $gst,
                'line_total_paise' => $allocated->taxableValuePaise + $gst,
            ]);

            $subtotal += $allocated->taxableValuePaise;
            $gstTotal += $gst;
        }

        $pi->update([
            'subtotal_paise' => $subtotal,
            'gst_paise' => $gstTotal,
            // GST is recoverable input credit, so it is not part of landed cost.
            'landed_total_paise' => $subtotal + $charges,
            'total_paise' => $subtotal + $charges + $gstTotal,
            'allocation_basis' => $basis,
        ]);
    }

    /**
     * Per-gram freight only means something if we know the grams. Looked up
     * here rather than in LandedCostAllocator, which stays pure so its
     * exactness property can be tested over thousands of generated cases.
     *
     * @param  list<array{product_variant_id: int, ...}>  $lines
     * @return array<int, int>
     */
    private function variantWeights(array $lines): array
    {
        return ProductVariant::query()
            ->whereIn('id', array_column($lines, 'product_variant_id'))
            ->pluck('weight_g', 'id')
            ->map(static fn ($g): int => (int) $g)
            ->all();
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
