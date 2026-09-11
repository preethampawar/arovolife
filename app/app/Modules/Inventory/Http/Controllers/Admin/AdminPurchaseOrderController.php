<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Http\Controllers\Admin;

use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Inventory\Http\Requests\PurchaseOrderRequest;
use App\Modules\Inventory\Models\PurchaseOrder;
use App\Modules\Inventory\Models\Supplier;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\PurchaseOrderService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Auth;
use RuntimeException;

final class AdminPurchaseOrderController extends Controller
{
    public function __construct(private readonly PurchaseOrderService $purchaseOrders) {}

    public function index(Request $request): View
    {
        $status = $request->query('status');

        $orders = PurchaseOrder::with('supplier')
            ->when($status, fn ($q) => $q->where('status', $status))
            ->orderByDesc('id')
            ->paginate(25);

        return view('admin.inventory.purchase-orders.index', ['orders' => $orders]);
    }

    public function create(): View
    {
        return view('admin.inventory.purchase-orders.form', [
            'purchaseOrder' => new PurchaseOrder,
            'items' => collect(),
        ] + $this->formOptions());
    }

    public function store(PurchaseOrderRequest $request): RedirectResponse
    {
        $data = $request->validated();

        $po = $this->purchaseOrders->create(
            [
                'supplier_id' => $data['supplier_id'],
                'warehouse_code' => $data['warehouse_code'],
                'expected_at' => $data['expected_at'] ?? null,
                'notes' => $data['notes'] ?? null,
            ],
            $this->linesFromRequest($data['lines']),
            (int) Auth::id(),
        );

        return redirect()->route('admin.inventory.purchase-orders.show', $po)->with('status', "Purchase order {$po->po_no} created.");
    }

    public function edit(PurchaseOrder $purchaseOrder): View|RedirectResponse
    {
        if (! $purchaseOrder->isEditable()) {
            return redirect()->route('admin.inventory.purchase-orders.show', $purchaseOrder);
        }

        return view('admin.inventory.purchase-orders.form', [
            'purchaseOrder' => $purchaseOrder,
            'items' => $purchaseOrder->items()->with('variant.product')->get(),
        ] + $this->formOptions());
    }

    public function update(PurchaseOrderRequest $request, PurchaseOrder $purchaseOrder): RedirectResponse
    {
        $data = $request->validated();

        try {
            $this->purchaseOrders->update(
                $purchaseOrder,
                [
                    'supplier_id' => $data['supplier_id'],
                    'warehouse_code' => $data['warehouse_code'],
                    'expected_at' => $data['expected_at'] ?? null,
                    'notes' => $data['notes'] ?? null,
                ],
                $this->linesFromRequest($data['lines']),
                (int) Auth::id(),
            );
        } catch (RuntimeException $e) {
            return redirect()->route('admin.inventory.purchase-orders.show', $purchaseOrder)->withErrors(['po' => $e->getMessage()]);
        }

        return redirect()->route('admin.inventory.purchase-orders.show', $purchaseOrder)->with('status', 'Purchase order saved.');
    }

    public function show(PurchaseOrder $purchaseOrder): View
    {
        $purchaseOrder->load(['supplier', 'warehouse', 'createdBy', 'items.variant.product']);

        return view('admin.inventory.purchase-orders.show', [
            'purchaseOrder' => $purchaseOrder,
            'invoices' => $purchaseOrder->purchaseInvoices()->orderByDesc('id')->get(),
        ]);
    }

    public function send(PurchaseOrder $purchaseOrder): RedirectResponse
    {
        try {
            $this->purchaseOrders->send($purchaseOrder, (int) Auth::id());
        } catch (RuntimeException $e) {
            return redirect()->route('admin.inventory.purchase-orders.show', $purchaseOrder)->withErrors(['po' => $e->getMessage()]);
        }

        return redirect()->route('admin.inventory.purchase-orders.show', $purchaseOrder)->with('status', "Purchase order {$purchaseOrder->po_no} sent to supplier.");
    }

    public function cancel(Request $request, PurchaseOrder $purchaseOrder): RedirectResponse
    {
        $validated = $request->validate(['reason' => ['nullable', 'string', 'max:255']]);

        try {
            $this->purchaseOrders->cancel($purchaseOrder, $validated['reason'] ?? 'Cancelled by admin', (int) Auth::id());
        } catch (RuntimeException $e) {
            return redirect()->route('admin.inventory.purchase-orders.show', $purchaseOrder)->withErrors(['po' => $e->getMessage()]);
        }

        return redirect()->route('admin.inventory.purchase-orders.show', $purchaseOrder)->with('status', "Purchase order {$purchaseOrder->po_no} cancelled.");
    }

    /** @return array<string, mixed> */
    private function formOptions(): array
    {
        return [
            'suppliers' => Supplier::query()->active()->orderBy('name')->get(['id', 'name']),
            'warehouses' => Warehouse::query()->active()->orderBy('name')->get(['code', 'name']),
            'variants' => ProductVariant::query()->where('status', 'active')->with('product:id,name')
                ->orderBy('variant_sku')->get(['id', 'product_id', 'variant_sku', 'name']),
        ];
    }

    /**
     * @param  list<array{product_variant_id: string|int, qty_ordered: string|int, unit_cost: string}>  $lines
     * @return list<array{product_variant_id: int, qty_ordered: int, unit_cost_paise: int}>
     */
    private function linesFromRequest(array $lines): array
    {
        return array_map(static fn (array $line): array => [
            'product_variant_id' => (int) $line['product_variant_id'],
            'qty_ordered' => (int) $line['qty_ordered'],
            'unit_cost_paise' => (int) round(((float) $line['unit_cost']) * 100),
        ], $lines);
    }
}
