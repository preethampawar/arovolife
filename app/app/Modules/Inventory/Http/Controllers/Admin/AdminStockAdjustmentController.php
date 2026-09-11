<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Http\Controllers\Admin;

use App\Modules\Inventory\Http\Requests\StockAdjustmentRequest;
use App\Modules\Inventory\Models\StockAdjustment;
use App\Modules\Inventory\Models\StockBatch;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\StockAdjustmentService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Auth;
use InvalidArgumentException;

final class AdminStockAdjustmentController extends Controller
{
    public function __construct(private readonly StockAdjustmentService $adjustments) {}

    public function index(): View
    {
        $adjustments = StockAdjustment::with(['variant.product', 'warehouse', 'actor'])->orderByDesc('id')->paginate(25);

        return view('admin.inventory.adjustments.index', ['adjustments' => $adjustments]);
    }

    public function create(Request $request): View
    {
        return view('admin.inventory.adjustments.form', [
            'prefill' => [
                'warehouse_code' => $request->query('warehouse_code'),
                'product_variant_id' => $request->query('product_variant_id'),
            ],
        ] + $this->formOptions());
    }

    public function store(StockAdjustmentRequest $request): RedirectResponse
    {
        $data = $request->validated();

        try {
            $adjustment = $this->adjustments->adjust(
                $data['warehouse_code'],
                (int) $data['product_variant_id'],
                isset($data['stock_batch_id']) ? (int) $data['stock_batch_id'] : null,
                (int) $data['qty_delta'],
                $data['reason'],
                $data['notes'],
                (int) Auth::id(),
            );
        } catch (InvalidArgumentException $e) {
            return redirect()->route('admin.inventory.adjustments.create')->withInput()->withErrors(['adjustment' => $e->getMessage()]);
        }

        return redirect()->route('admin.inventory.adjustments.index')->with('status', "Adjustment {$adjustment->adjustment_no} recorded.");
    }

    /** @return array<string, mixed> */
    private function formOptions(): array
    {
        return [
            'warehouses' => Warehouse::query()->active()->orderBy('name')->get(['code', 'name']),
            'batches' => StockBatch::query()
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
