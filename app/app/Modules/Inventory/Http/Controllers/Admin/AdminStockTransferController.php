<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Http\Controllers\Admin;

use App\Modules\Inventory\Http\Requests\StockTransferRequest;
use App\Modules\Inventory\Models\StockBatch;
use App\Modules\Inventory\Models\StockTransfer;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\StockTransferService;
use App\Modules\Shared\Support\FilterField;
use App\Modules\Shared\Support\ListFilters;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Auth;
use RuntimeException;

final class AdminStockTransferController extends Controller
{
    public function __construct(private readonly StockTransferService $transfers) {}

    public function index(Request $request): View
    {
        $filters = ListFilters::make($request, [
            FilterField::select('status', 'Status', [
                StockTransfer::STATUS_DRAFT => 'Draft',
                StockTransfer::STATUS_DISPATCHED => 'Dispatched',
                StockTransfer::STATUS_RECEIVED => 'Received',
                StockTransfer::STATUS_CANCELLED => 'Cancelled',
            ], column: 'stock_transfers.status', placeholder: 'All statuses'),
            FilterField::select('warehouse_code', 'Warehouse', Warehouse::query()->orderBy('name')->get(['code', 'name'])->mapWithKeys(fn (Warehouse $w): array => [$w->code => $w->name])->all(), placeholder: 'All warehouses'),
            FilterField::dateRange('created', 'Created', dateColumn: 'stock_transfers.created_at'),
        ]);

        $warehouseCode = $filters->value('warehouse_code');

        $transfers = $filters->apply(StockTransfer::query())
            ->when($warehouseCode !== null, fn ($q) => $q->where(function ($inner) use ($warehouseCode): void {
                $inner->where('stock_transfers.from_warehouse_code', $warehouseCode)
                    ->orWhere('stock_transfers.to_warehouse_code', $warehouseCode);
            }))
            ->orderByDesc('id')
            ->paginate(25)
            ->withQueryString();

        return view('admin.inventory.transfers.index', ['transfers' => $transfers, 'filters' => $filters]);
    }

    public function create(): View
    {
        return view('admin.inventory.transfers.form', $this->formOptions());
    }

    public function store(StockTransferRequest $request): RedirectResponse
    {
        $data = $request->validated();

        try {
            $transfer = $this->transfers->createDraft(
                $data['from_warehouse_code'],
                $data['to_warehouse_code'],
                array_values(array_map(static fn (array $line): array => [
                    'product_variant_id' => (int) $line['product_variant_id'],
                    'stock_batch_id' => (int) $line['stock_batch_id'],
                    'qty' => (int) $line['qty'],
                ], $data['lines'])),
                (int) Auth::id(),
            );
        } catch (RuntimeException $e) {
            return redirect()->route('admin.inventory.transfers.create')->withInput()->withErrors(['transfer' => $e->getMessage()]);
        }

        return redirect()->route('admin.inventory.transfers.show', $transfer)->with('status', "Transfer {$transfer->transfer_no} created.");
    }

    public function show(StockTransfer $stockTransfer): View
    {
        $stockTransfer->load(['items.variant.product', 'items.batch', 'fromWarehouse', 'toWarehouse', 'dispatchedBy', 'receivedBy']);

        return view('admin.inventory.transfers.show', ['transfer' => $stockTransfer]);
    }

    public function dispatch(StockTransfer $stockTransfer): RedirectResponse
    {
        try {
            $this->transfers->dispatch($stockTransfer, (int) Auth::id());
        } catch (RuntimeException $e) {
            return redirect()->route('admin.inventory.transfers.show', $stockTransfer)->withErrors(['transfer' => $e->getMessage()]);
        }

        return redirect()->route('admin.inventory.transfers.show', $stockTransfer)->with('status', "Transfer {$stockTransfer->transfer_no} dispatched.");
    }

    public function receive(Request $request, StockTransfer $stockTransfer): RedirectResponse
    {
        $validated = $request->validate(['received_qty' => ['nullable', 'array'], 'received_qty.*' => ['integer', 'min:0']]);

        $receivedQty = null;

        if (! empty($validated['received_qty'])) {
            $receivedQty = array_map('intval', $validated['received_qty']);
        }

        try {
            $this->transfers->receive($stockTransfer, (int) Auth::id(), $receivedQty);
        } catch (RuntimeException $e) {
            return redirect()->route('admin.inventory.transfers.show', $stockTransfer)->withErrors(['transfer' => $e->getMessage()]);
        }

        return redirect()->route('admin.inventory.transfers.show', $stockTransfer)->with('status', "Transfer {$stockTransfer->transfer_no} received.");
    }

    public function cancel(StockTransfer $stockTransfer): RedirectResponse
    {
        try {
            $this->transfers->cancel($stockTransfer, (int) Auth::id());
        } catch (RuntimeException $e) {
            return redirect()->route('admin.inventory.transfers.show', $stockTransfer)->withErrors(['transfer' => $e->getMessage()]);
        }

        return redirect()->route('admin.inventory.transfers.index')->with('status', "Transfer {$stockTransfer->transfer_no} cancelled.");
    }

    /** @return array<string, mixed> */
    private function formOptions(): array
    {
        return [
            'warehouses' => Warehouse::query()->active()->orderBy('name')->get(['code', 'name']),
            'batches' => StockBatch::query()
                ->where('qty_on_hand', '>', 0)
                ->with('variant:id,product_id,variant_sku', 'variant.product:id,name')
                ->orderBy('batch_no')
                ->get()
                ->map(static fn (StockBatch $batch): array => [
                    'id' => $batch->id,
                    'warehouse_code' => $batch->warehouse_code,
                    'product_variant_id' => $batch->product_variant_id,
                    'sku' => $batch->variant?->variant_sku,
                    'name' => $batch->variant?->product?->name,
                    'batch_no' => $batch->batch_no,
                    'qty_on_hand' => $batch->qty_on_hand,
                ])
                ->values(),
        ];
    }
}
