<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Http\Controllers\Admin;

use App\Modules\Catalog\Models\InventoryLevel;
use App\Modules\Commerce\Models\Order;
use App\Modules\Identity\Models\User;
use App\Modules\Inventory\Models\PurchaseInvoice;
use App\Modules\Inventory\Models\StockBatch;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Models\StockTransfer;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\InventoryAlertService;
use App\Modules\Returns\Models\ReturnRequest;
use App\Modules\Shared\Support\Csv;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Plan §7.2 — ten stock/finance/ops reports, one method each, all filterable
 * by warehouse (and date range where the report has a time dimension) and
 * CSV-exportable via `?export=csv`.
 *
 * Every report renders through the same generic table view: the reports
 * differ in what they query, not in how a filtered table is drawn, and a
 * dedicated Blade file per report would be ten near-identical copies of the
 * same markup. Simpler option per plan §0 — noted in the commit body.
 */
final class AdminInventoryReportController extends Controller
{
    public function index(): View
    {
        return view('admin.inventory.reports.index');
    }

    public function stockOnHand(Request $request): View|StreamedResponse
    {
        $warehouseCode = (string) $request->query('warehouse_code', '');

        $levels = InventoryLevel::query()
            ->join('product_variants', 'product_variants.id', '=', 'inventory_levels.product_variant_id')
            ->join('products', 'products.id', '=', 'product_variants.product_id')
            ->when($warehouseCode !== '', fn ($q) => $q->where('inventory_levels.warehouse_code', $warehouseCode))
            ->orderBy('products.name')
            ->get([
                'inventory_levels.id', 'inventory_levels.product_variant_id', 'inventory_levels.warehouse_code',
                'inventory_levels.on_hand', 'inventory_levels.reserved', 'inventory_levels.reorder_level',
                'product_variants.variant_sku', 'products.name as product_name',
            ]);

        $values = $this->batchValueByVariantWarehouse($levels->pluck('product_variant_id')->unique(), $warehouseCode);

        $rows = $levels->map(function (InventoryLevel $level) use ($values): array {
            $available = max(0, $level->on_hand - $level->reserved);
            $reorderLevel = (int) $level->reorder_level;
            $status = $available <= 0 ? 'OUT' : ($reorderLevel > 0 && $available <= $reorderLevel ? 'LOW' : 'OK');
            $value = $values[$level->product_variant_id.'|'.$level->warehouse_code] ?? 0;

            return [
                'sku' => $level->getAttribute('variant_sku'),
                'product' => $level->getAttribute('product_name'),
                'warehouse' => $level->warehouse_code,
                'on_hand' => $level->on_hand,
                'reserved' => $level->reserved,
                'available' => $available,
                'reorder_level' => $reorderLevel,
                'value' => $this->money($value),
                'status' => $status,
            ];
        });

        return $this->render($request, 'Stock on hand', 'stock-on-hand', [
            ['key' => 'sku', 'label' => 'SKU'],
            ['key' => 'product', 'label' => 'Product'],
            ['key' => 'warehouse', 'label' => 'Warehouse'],
            ['key' => 'on_hand', 'label' => 'On hand', 'align' => 'right'],
            ['key' => 'reserved', 'label' => 'Reserved', 'align' => 'right'],
            ['key' => 'available', 'label' => 'Available', 'align' => 'right'],
            ['key' => 'reorder_level', 'label' => 'Reorder level', 'align' => 'right'],
            ['key' => 'value', 'label' => 'Value', 'align' => 'right'],
            ['key' => 'status', 'label' => 'Status'],
        ], $rows);
    }

    public function movements(Request $request): View|StreamedResponse
    {
        $warehouseCode = (string) $request->query('warehouse_code', '');
        [$from, $to] = $this->dateRange($request);

        $movements = StockMovement::query()
            ->join('product_variants', 'product_variants.id', '=', 'stock_movements.product_variant_id')
            ->when($warehouseCode !== '', fn ($q) => $q->where('stock_movements.warehouse_code', $warehouseCode))
            ->when($from !== null, fn ($q) => $q->where('stock_movements.occurred_at', '>=', $from))
            ->when($to !== null, fn ($q) => $q->where('stock_movements.occurred_at', '<=', $to))
            ->orderByDesc('stock_movements.occurred_at')
            ->limit(1000)
            ->get([
                'stock_movements.occurred_at', 'stock_movements.type', 'stock_movements.qty',
                'stock_movements.warehouse_code', 'stock_movements.reference_type', 'stock_movements.reference_id',
                'stock_movements.actor_user_id', 'stock_movements.stock_batch_id',
                'product_variants.variant_sku',
            ]);

        $batchNos = StockBatch::query()
            ->whereIn('id', $movements->pluck('stock_batch_id')->filter()->unique())
            ->pluck('batch_no', 'id');

        $actorNames = User::query()
            ->whereIn('id', $movements->pluck('actor_user_id')->filter()->unique())
            ->pluck('full_name', 'id');

        $rows = $movements->map(fn (StockMovement $m): array => [
            'occurred_at' => $m->occurred_at,
            'sku' => $m->getAttribute('variant_sku'),
            'warehouse' => $m->warehouse_code,
            'type' => $m->type,
            'qty' => $m->qty,
            'batch' => $m->stock_batch_id !== null ? ($batchNos[$m->stock_batch_id] ?? '') : '',
            'reference' => $m->reference_type !== null ? "{$m->reference_type}#{$m->reference_id}" : '',
            'actor' => $m->actor_user_id !== null ? ($actorNames[$m->actor_user_id] ?? "user#{$m->actor_user_id}") : '',
        ]);

        return $this->render($request, 'Stock movement ledger', 'movement-ledger', [
            ['key' => 'occurred_at', 'label' => 'Date'],
            ['key' => 'sku', 'label' => 'SKU'],
            ['key' => 'warehouse', 'label' => 'Warehouse'],
            ['key' => 'type', 'label' => 'Type'],
            ['key' => 'qty', 'label' => 'Qty', 'align' => 'right'],
            ['key' => 'batch', 'label' => 'Batch'],
            ['key' => 'reference', 'label' => 'Reference'],
            ['key' => 'actor', 'label' => 'Actor'],
        ], $rows, dated: true);
    }

    public function batchExpiry(Request $request): View|StreamedResponse
    {
        $warehouseCode = (string) $request->query('warehouse_code', '');
        $today = now()->startOfDay();

        $batches = StockBatch::query()
            ->join('product_variants', 'product_variants.id', '=', 'stock_batches.product_variant_id')
            ->when($warehouseCode !== '', fn ($q) => $q->where('stock_batches.warehouse_code', $warehouseCode))
            ->orderByRaw('stock_batches.expiry_date IS NULL, stock_batches.expiry_date ASC')
            ->get([
                'stock_batches.batch_no', 'stock_batches.warehouse_code', 'stock_batches.expiry_date',
                'stock_batches.qty_on_hand', 'stock_batches.unit_cost_paise', 'product_variants.variant_sku',
            ]);

        $rows = $batches->map(function (StockBatch $b) use ($today): array {
            $days = $b->expiry_date !== null ? $today->diffInDays(Carbon::parse($b->expiry_date), false) : null;
            $bucket = match (true) {
                $days === null => 'OK',
                $days < 0 => 'expired',
                $days <= 30 => '≤30',
                $days <= 90 => '≤90',
                default => 'OK',
            };

            return [
                'sku' => $b->getAttribute('variant_sku'),
                'warehouse' => $b->warehouse_code,
                'batch' => $b->batch_no,
                'expiry_date' => $b->expiry_date !== null ? Carbon::parse($b->expiry_date)->format('Y-m-d') : '',
                'days_to_expiry' => $days ?? '',
                'bucket' => $bucket,
                'qty_on_hand' => $b->qty_on_hand,
                'value' => $this->money($b->qty_on_hand * $b->unit_cost_paise),
            ];
        });

        return $this->render($request, 'Batch & expiry', 'batch-expiry', [
            ['key' => 'sku', 'label' => 'SKU'],
            ['key' => 'warehouse', 'label' => 'Warehouse'],
            ['key' => 'batch', 'label' => 'Batch'],
            ['key' => 'expiry_date', 'label' => 'Expiry'],
            ['key' => 'days_to_expiry', 'label' => 'Days to expiry', 'align' => 'right'],
            ['key' => 'bucket', 'label' => 'Bucket'],
            ['key' => 'qty_on_hand', 'label' => 'Qty on hand', 'align' => 'right'],
            ['key' => 'value', 'label' => 'Value at risk', 'align' => 'right'],
        ], $rows);
    }

    public function lowStock(Request $request, InventoryAlertService $alerts): View|StreamedResponse
    {
        $warehouseCode = (string) $request->query('warehouse_code', '');

        $rows = $alerts->lowStock()
            ->when($warehouseCode !== '', fn (EloquentCollection $c): EloquentCollection => $c->where('warehouse_code', $warehouseCode))
            ->map(function (InventoryLevel $level): array {
                $available = max(0, $level->on_hand - $level->reserved);
                $suggested = max(0, $level->reorder_level * 2 - $available);

                return [
                    'sku' => $level->variant->variant_sku,
                    'product' => $level->variant->product->name,
                    'warehouse' => $level->warehouse_code,
                    'on_hand' => $level->on_hand,
                    'reserved' => $level->reserved,
                    'available' => $available,
                    'reorder_level' => $level->reorder_level,
                    'suggested_reorder_qty' => $suggested,
                ];
            })->values();

        return $this->render($request, 'Low stock', 'low-stock', [
            ['key' => 'sku', 'label' => 'SKU'],
            ['key' => 'product', 'label' => 'Product'],
            ['key' => 'warehouse', 'label' => 'Warehouse'],
            ['key' => 'on_hand', 'label' => 'On hand', 'align' => 'right'],
            ['key' => 'reserved', 'label' => 'Reserved', 'align' => 'right'],
            ['key' => 'available', 'label' => 'Available', 'align' => 'right'],
            ['key' => 'reorder_level', 'label' => 'Reorder level', 'align' => 'right'],
            ['key' => 'suggested_reorder_qty', 'label' => 'Suggested reorder qty (estimate only)', 'align' => 'right'],
        ], $rows);
    }

    public function valuation(Request $request): View|StreamedResponse
    {
        $warehouseCode = (string) $request->query('warehouse_code', '');

        $batches = StockBatch::query()
            ->join('product_variants', 'product_variants.id', '=', 'stock_batches.product_variant_id')
            ->join('products', 'products.id', '=', 'product_variants.product_id')
            ->leftJoin('product_categories', 'product_categories.id', '=', 'products.category_id')
            ->when($warehouseCode !== '', fn ($q) => $q->where('stock_batches.warehouse_code', $warehouseCode))
            ->where('stock_batches.qty_on_hand', '>', 0)
            ->get([
                'stock_batches.warehouse_code', 'stock_batches.qty_on_hand', 'stock_batches.unit_cost_paise',
                'product_categories.name as category_name',
            ]);

        $grouped = $batches->groupBy(fn (StockBatch $b): string => $b->warehouse_code.'|'.($b->getAttribute('category_name') ?? 'Uncategorised'));

        $rows = $grouped->map(function (EloquentCollection $group, string $key): array {
            [$warehouse, $category] = explode('|', $key, 2);

            return [
                'warehouse' => $warehouse,
                'category' => $category,
                'qty_on_hand' => $group->sum('qty_on_hand'),
                'value' => $this->money((int) $group->sum(fn (StockBatch $b): int => $b->qty_on_hand * $b->unit_cost_paise)),
            ];
        })->values();

        return $this->render($request, 'Stock valuation', 'valuation', [
            ['key' => 'warehouse', 'label' => 'Warehouse'],
            ['key' => 'category', 'label' => 'Category'],
            ['key' => 'qty_on_hand', 'label' => 'Qty on hand', 'align' => 'right'],
            ['key' => 'value', 'label' => 'Value (FIFO by batch cost)', 'align' => 'right'],
        ], $rows);
    }

    public function purchaseRegister(Request $request): View|StreamedResponse
    {
        $warehouseCode = (string) $request->query('warehouse_code', '');
        [$from, $to] = $this->dateRange($request);

        $invoices = PurchaseInvoice::query()
            ->with('supplier')
            ->where('status', PurchaseInvoice::STATUS_POSTED)
            ->when($warehouseCode !== '', fn ($q) => $q->where('warehouse_code', $warehouseCode))
            ->when($from !== null, fn ($q) => $q->where('posted_at', '>=', $from))
            ->when($to !== null, fn ($q) => $q->where('posted_at', '<=', $to))
            ->orderByDesc('posted_at')
            ->get();

        $rows = $invoices->map(fn (PurchaseInvoice $pi): array => [
            'grn_no' => $pi->grn_no,
            'supplier' => $pi->supplier->name,
            'posted_at' => $pi->posted_at,
            'warehouse' => $pi->warehouse_code,
            'taxable' => $this->money($pi->subtotal_paise),
            'gst' => $this->money($pi->gst_paise),
            'total' => $this->money($pi->total_paise),
        ]);

        return $this->render($request, 'Purchase register', 'purchase-register', [
            ['key' => 'grn_no', 'label' => 'GRN No'],
            ['key' => 'supplier', 'label' => 'Supplier'],
            ['key' => 'posted_at', 'label' => 'Posted at'],
            ['key' => 'warehouse', 'label' => 'Warehouse'],
            ['key' => 'taxable', 'label' => 'Taxable', 'align' => 'right'],
            ['key' => 'gst', 'label' => 'GST (input credit)', 'align' => 'right'],
            ['key' => 'total', 'label' => 'Total', 'align' => 'right'],
        ], $rows, dated: true);
    }

    public function transferRegister(Request $request): View|StreamedResponse
    {
        $warehouseCode = (string) $request->query('warehouse_code', '');
        [$from, $to] = $this->dateRange($request);

        $transfers = StockTransfer::query()
            ->withSum('items', 'qty')
            ->when($warehouseCode !== '', fn ($q) => $q->where(function ($q2) use ($warehouseCode): void {
                $q2->where('from_warehouse_code', $warehouseCode)->orWhere('to_warehouse_code', $warehouseCode);
            }))
            ->when($from !== null, fn ($q) => $q->where('created_at', '>=', $from))
            ->when($to !== null, fn ($q) => $q->where('created_at', '<=', $to))
            ->orderByDesc('created_at')
            ->get();

        $rows = $transfers->map(fn (StockTransfer $t): array => [
            'transfer_no' => $t->transfer_no,
            'from' => $t->from_warehouse_code,
            'to' => $t->to_warehouse_code,
            'status' => $t->status,
            'qty' => (int) $t->items_sum_qty,
            'in_transit_qty' => $t->status === StockTransfer::STATUS_DISPATCHED ? (int) $t->items_sum_qty : 0,
            'dispatched_at' => $t->dispatched_at,
            'received_at' => $t->received_at,
        ]);

        return $this->render($request, 'Transfer register', 'transfer-register', [
            ['key' => 'transfer_no', 'label' => 'Transfer No'],
            ['key' => 'from', 'label' => 'From'],
            ['key' => 'to', 'label' => 'To'],
            ['key' => 'status', 'label' => 'Status'],
            ['key' => 'qty', 'label' => 'Qty', 'align' => 'right'],
            ['key' => 'in_transit_qty', 'label' => 'In-transit qty', 'align' => 'right'],
            ['key' => 'dispatched_at', 'label' => 'Dispatched at'],
            ['key' => 'received_at', 'label' => 'Received at'],
        ], $rows, dated: true);
    }

    public function orderFulfilment(Request $request): View|StreamedResponse
    {
        $warehouseCode = (string) $request->query('warehouse_code', '');
        $now = Carbon::now();

        $orders = Order::query()
            ->when($warehouseCode !== '', fn (Builder $q): Builder => $q->where('warehouse_code', $warehouseCode))
            ->where(function (Builder $q): void {
                $q->where(function (Builder $q2): void {
                    $q2->where('status', 'paid')->whereNull('packed_at');
                })->orWhere(function (Builder $q2): void {
                    $q2->whereNotNull('packed_at')->whereNull('shipped_at')->where('status', '!=', 'cancelled');
                })->orWhere(function (Builder $q2): void {
                    $q2->whereNotNull('shipped_at')->whereNull('delivered_at');
                })->orWhere(function (Builder $q2): void {
                    $q2->where('status', 'cancelled')->whereNotNull('packed_at');
                });
            })
            ->orderByDesc('placed_at')
            ->limit(500)
            ->get();

        $rows = $orders->map(function (Order $order) use ($now): array {
            $stage = match (true) {
                $order->status === 'cancelled' && $order->packed_at !== null => 'cancelled_after_pack',
                $order->shipped_at !== null && $order->delivered_at === null => 'shipped_not_delivered',
                $order->packed_at !== null && $order->shipped_at === null => 'packed_not_shipped',
                default => 'paid_not_packed',
            };
            $since = $order->packed_at ?? $order->paid_at ?? $order->placed_at ?? $now;

            return [
                'order_no' => $order->order_no,
                'stage' => $stage,
                'age_days' => $since->diffInDays($now),
                'paid_at' => $order->paid_at,
                'packed_at' => $order->packed_at,
                'shipped_at' => $order->shipped_at,
            ];
        });

        $returnsPending = ReturnRequest::query()
            ->with('order')
            ->whereNotNull('entitlements_held_at')
            ->whereNull('received_at')
            ->whereNull('receipt_outcome')
            ->get()
            ->map(fn (ReturnRequest $rr): array => [
                'order_no' => $rr->rma_no,
                'stage' => 'returns_pending_receipt',
                'age_days' => $rr->created_at->diffInDays($now),
                'paid_at' => null,
                'packed_at' => null,
                'shipped_at' => null,
            ]);

        return $this->render($request, 'Order fulfilment', 'order-fulfilment', [
            ['key' => 'order_no', 'label' => 'Order / RMA'],
            ['key' => 'stage', 'label' => 'Stage'],
            ['key' => 'age_days', 'label' => 'Age (days)', 'align' => 'right'],
            ['key' => 'paid_at', 'label' => 'Paid at'],
            ['key' => 'packed_at', 'label' => 'Packed at'],
            ['key' => 'shipped_at', 'label' => 'Shipped at'],
        ], $rows->concat($returnsPending));
    }

    public function returnsRestock(Request $request): View|StreamedResponse
    {
        [$from, $to] = $this->dateRange($request);

        $requests = ReturnRequest::query()
            ->with(['order', 'inspection'])
            ->when($from !== null, fn ($q) => $q->where('created_at', '>=', $from))
            ->when($to !== null, fn ($q) => $q->where('created_at', '<=', $to))
            ->orderByDesc('created_at')
            ->limit(500)
            ->get();

        $restocked = StockMovement::query()
            ->where('reference_type', 'return_request')
            ->where('type', StockMovement::TYPE_RETURN_IN)
            ->whereIn('reference_id', $requests->pluck('id'))
            ->get(['reference_id', 'warehouse_code'])
            ->keyBy('reference_id');

        $rows = $requests->map(function (ReturnRequest $rr) use ($restocked): array {
            $restock = $restocked->get($rr->id);

            return [
                'rma_no' => $rr->rma_no,
                'order_no' => $rr->order->order_no,
                'reason' => $rr->reason,
                'status' => $rr->status,
                'condition' => optional($rr->inspection)->condition ?? '',
                'restocked' => $restock !== null ? 'yes' : 'no',
                'warehouse' => $restock !== null ? $restock->warehouse_code : '',
            ];
        });

        return $this->render($request, 'Returns & restock', 'returns-restock', [
            ['key' => 'rma_no', 'label' => 'RMA No'],
            ['key' => 'order_no', 'label' => 'Order No'],
            ['key' => 'reason', 'label' => 'Reason'],
            ['key' => 'status', 'label' => 'Status'],
            ['key' => 'condition', 'label' => 'Inspection condition'],
            ['key' => 'restocked', 'label' => 'Restocked'],
            ['key' => 'warehouse', 'label' => 'Warehouse'],
        ], $rows, dated: true);
    }

    public function stockInOut(Request $request): View|StreamedResponse
    {
        $warehouseCode = (string) $request->query('warehouse_code', '');
        [$from, $to] = $this->dateRange($request);

        $opening = [];

        if ($from !== null) {
            $openingRows = StockMovement::query()
                ->join('product_variants', 'product_variants.id', '=', 'stock_movements.product_variant_id')
                ->when($warehouseCode !== '', fn ($q) => $q->where('stock_movements.warehouse_code', $warehouseCode))
                ->where('stock_movements.occurred_at', '<', $from)
                ->groupBy('stock_movements.product_variant_id', 'stock_movements.warehouse_code', 'product_variants.variant_sku')
                ->get([
                    'stock_movements.product_variant_id', 'stock_movements.warehouse_code', 'product_variants.variant_sku',
                    DB::raw('SUM(stock_movements.qty) as total'),
                ]);

            foreach ($openingRows as $row) {
                $opening[$row->product_variant_id.'|'.$row->warehouse_code] = (int) $row->getAttribute('total');
            }
        }

        $periodRows = StockMovement::query()
            ->join('product_variants', 'product_variants.id', '=', 'stock_movements.product_variant_id')
            ->when($warehouseCode !== '', fn ($q) => $q->where('stock_movements.warehouse_code', $warehouseCode))
            ->when($from !== null, fn ($q) => $q->where('stock_movements.occurred_at', '>=', $from))
            ->when($to !== null, fn ($q) => $q->where('stock_movements.occurred_at', '<=', $to))
            ->get([
                'stock_movements.product_variant_id', 'stock_movements.warehouse_code', 'stock_movements.type',
                'stock_movements.qty', 'product_variants.variant_sku',
            ]);

        $groups = $periodRows->groupBy(fn (StockMovement $m): string => $m->product_variant_id.'|'.$m->warehouse_code);

        $rows = $groups->map(function (EloquentCollection $moves, string $key) use ($opening): array {
            [, $warehouseCode] = explode('|', $key, 2);
            $sku = $moves->first()?->getAttribute('variant_sku') ?? '';
            $sum = fn (array $types): int => (int) $moves->whereIn('type', $types)->sum('qty');

            $purchases = $sum([StockMovement::TYPE_PURCHASE_IN]);
            $returns = $sum([StockMovement::TYPE_RETURN_IN]);
            $transfersIn = $sum([StockMovement::TYPE_TRANSFER_IN]);
            $sales = $sum([StockMovement::TYPE_SALE_OUT, StockMovement::TYPE_SALE_REVERSAL]);
            $transfersOut = $sum([StockMovement::TYPE_TRANSFER_OUT]);
            $adjustments = $sum([
                StockMovement::TYPE_ADJUSTMENT_IN, StockMovement::TYPE_ADJUSTMENT_OUT, StockMovement::TYPE_WRITE_OFF,
                StockMovement::TYPE_PURCHASE_REVERSAL, StockMovement::TYPE_OPENING,
            ]);
            $openingQty = $opening[$key] ?? 0;
            $closing = $openingQty + $moves->sum('qty');

            return [
                'sku' => $sku,
                'warehouse' => $warehouseCode,
                'opening' => $openingQty,
                'purchases' => $purchases,
                'returns' => $returns,
                'transfers_in' => $transfersIn,
                'sales' => $sales,
                'transfers_out' => $transfersOut,
                'adjustments' => $adjustments,
                'closing' => $closing,
            ];
        })->values();

        return $this->render($request, 'Stock in / out summary', 'stock-in-out', [
            ['key' => 'sku', 'label' => 'SKU'],
            ['key' => 'warehouse', 'label' => 'Warehouse'],
            ['key' => 'opening', 'label' => 'Opening', 'align' => 'right'],
            ['key' => 'purchases', 'label' => '+ Purchases', 'align' => 'right'],
            ['key' => 'returns', 'label' => '+ Returns', 'align' => 'right'],
            ['key' => 'transfers_in', 'label' => '+ Transfers in', 'align' => 'right'],
            ['key' => 'sales', 'label' => '− Sales', 'align' => 'right'],
            ['key' => 'transfers_out', 'label' => '− Transfers out', 'align' => 'right'],
            ['key' => 'adjustments', 'label' => '± Adjustments', 'align' => 'right'],
            ['key' => 'closing', 'label' => 'Closing', 'align' => 'right'],
        ], $rows, dated: true);
    }

    /**
     * Total batch value per (variant, warehouse) key, for the stock-on-hand
     * report — one query for every level on the page rather than N+1.
     *
     * @param  Collection<int, int>  $variantIds
     * @return array<string, int>
     */
    private function batchValueByVariantWarehouse(Collection $variantIds, string $warehouseCode): array
    {
        $batches = StockBatch::query()
            ->whereIn('product_variant_id', $variantIds)
            ->when($warehouseCode !== '', fn ($q) => $q->where('warehouse_code', $warehouseCode))
            ->get(['product_variant_id', 'warehouse_code', 'qty_on_hand', 'unit_cost_paise']);

        $values = [];

        foreach ($batches as $batch) {
            $key = $batch->product_variant_id.'|'.$batch->warehouse_code;
            $values[$key] = ($values[$key] ?? 0) + ($batch->qty_on_hand * $batch->unit_cost_paise);
        }

        return $values;
    }

    /** @return array{0: Carbon|null, 1: Carbon|null} */
    private function dateRange(Request $request): array
    {
        $from = (string) $request->query('date_from', '');
        $to = (string) $request->query('date_to', '');

        return [
            $from !== '' ? Carbon::parse($from)->startOfDay() : null,
            $to !== '' ? Carbon::parse($to)->endOfDay() : null,
        ];
    }

    private function money(int $paise): string
    {
        return '₹'.number_format($paise / 100, 2);
    }

    /**
     * @param  list<array{key: string, label: string, align?: string}>  $columns
     * @param  iterable<int, array<string, mixed>>  $rows
     */
    private function render(Request $request, string $title, string $slug, array $columns, iterable $rows, bool $dated = false): View|StreamedResponse
    {
        if ($request->query('export') === 'csv') {
            return $this->exportCsv($slug, $columns, $rows);
        }

        return view('admin.inventory.reports.show', [
            'title' => $title,
            'slug' => $slug,
            'columns' => $columns,
            'rows' => $rows,
            'warehouses' => Warehouse::query()->orderBy('name')->get(['code', 'name']),
            'warehouseCode' => (string) $request->query('warehouse_code', ''),
            'dated' => $dated,
            'dateFrom' => (string) $request->query('date_from', ''),
            'dateTo' => (string) $request->query('date_to', ''),
        ]);
    }

    /**
     * @param  list<array{key: string, label: string, align?: string}>  $columns
     * @param  iterable<int, array<string, mixed>>  $rows
     */
    private function exportCsv(string $slug, array $columns, iterable $rows): StreamedResponse
    {
        return response()->streamDownload(function () use ($columns, $rows): void {
            $handle = fopen('php://output', 'wb');

            if ($handle === false) {
                return;
            }

            fputcsv($handle, array_map(fn (array $c): string => $c['label'], $columns));

            foreach ($rows as $row) {
                fputcsv($handle, array_map(function (array $c) use ($row): string {
                    $value = $row[$c['key']] ?? '';

                    if ($value instanceof Carbon) {
                        $value = $value->toDateTimeString();
                    } elseif (is_bool($value)) {
                        $value = $value ? '1' : '0';
                    } elseif (! is_scalar($value)) {
                        $value = (string) $value;
                    }

                    return Csv::safe($value);
                }, $columns));
            }

            fclose($handle);
        }, "inventory-{$slug}.csv", ['Content-Type' => 'text/csv']);
    }
}
