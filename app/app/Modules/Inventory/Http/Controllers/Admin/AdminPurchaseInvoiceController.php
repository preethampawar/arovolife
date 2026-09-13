<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Http\Controllers\Admin;

use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Inventory\Http\Requests\PurchaseInvoiceRequest;
use App\Modules\Inventory\Models\PurchaseInvoice;
use App\Modules\Inventory\Models\PurchaseOrder;
use App\Modules\Inventory\Models\Supplier;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\PurchaseInvoiceService;
use App\Modules\Shared\Support\FilterField;
use App\Modules\Shared\Support\ListFilters;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Auth;
use RuntimeException;

final class AdminPurchaseInvoiceController extends Controller
{
    public function __construct(private readonly PurchaseInvoiceService $purchaseInvoices) {}

    public function index(Request $request): View
    {
        $filters = ListFilters::make($request, [
            FilterField::text('q', 'Search', 'GRN no. or supplier invoice no.', columns: ['purchase_invoices.grn_no', 'purchase_invoices.supplier_invoice_no']),
            FilterField::select('status', 'Status', [
                PurchaseInvoice::STATUS_DRAFT => 'Draft',
                PurchaseInvoice::STATUS_POSTED => 'Posted',
                PurchaseInvoice::STATUS_CANCELLED => 'Cancelled',
            ], column: 'purchase_invoices.status', placeholder: 'All statuses'),
            FilterField::select('supplier_id', 'Supplier', Supplier::query()->orderBy('name')->get(['id', 'name'])->mapWithKeys(fn (Supplier $s): array => [(string) $s->id => $s->name])->all(), column: 'purchase_invoices.supplier_id', placeholder: 'All suppliers'),
            FilterField::dateRange('supplier_invoice_date', 'Supplier invoice date', dateColumn: 'purchase_invoices.supplier_invoice_date'),
        ]);

        $invoices = $filters->apply(PurchaseInvoice::with('supplier'))
            ->orderByDesc('id')
            ->paginate(25)
            ->withQueryString();

        return view('admin.inventory.grns.index', ['invoices' => $invoices, 'filters' => $filters]);
    }

    public function create(Request $request): View
    {
        $fromPo = $request->filled('purchase_order_id')
            ? PurchaseOrder::with('items.variant.product')->where('id', $request->integer('purchase_order_id'))->first()
            : null;

        $items = $fromPo?->items->map(fn ($item) => [
            'product_variant_id' => $item->product_variant_id,
            'variant_label' => $item->variant->variant_sku.' — '.$item->variant->product->name,
            'batch_no' => '',
            'mfg_date' => null,
            'expiry_date' => null,
            'qty' => $item->outstandingQty(),
            'unit_cost' => number_format($item->unit_cost_paise / 100, 2, '.', ''),
            'gst_rate' => number_format($item->variant->gst_rate_bp / 100, 2, '.', ''),
        ])->filter(fn (array $line): bool => $line['qty'] > 0)->values() ?? collect();

        return view('admin.inventory.grns.form', [
            'invoice' => new PurchaseInvoice([
                'supplier_id' => $fromPo?->supplier_id,
                'purchase_order_id' => $fromPo?->id,
                'warehouse_code' => $fromPo?->warehouse_code,
                'supplier_invoice_date' => now()->toDateString(),
            ]),
            'items' => $items,
        ] + $this->formOptions());
    }

    public function store(PurchaseInvoiceRequest $request): RedirectResponse
    {
        $data = $request->validated();

        $pi = $this->purchaseInvoices->createDraft(
            [
                'supplier_id' => $data['supplier_id'],
                'purchase_order_id' => $data['purchase_order_id'] ?? null,
                'warehouse_code' => $data['warehouse_code'],
                'supplier_invoice_no' => $data['supplier_invoice_no'],
                'supplier_invoice_date' => $data['supplier_invoice_date'],
                'notes' => $data['notes'] ?? null,
            ],
            $this->linesFromRequest($data['lines']),
            (int) Auth::id(),
        );

        return redirect()->route('admin.inventory.grns.show', $pi)->with('status', "GRN {$pi->grn_no} created as a draft.");
    }

    public function edit(PurchaseInvoice $purchaseInvoice): View|RedirectResponse
    {
        if (! $purchaseInvoice->isEditable()) {
            return redirect()->route('admin.inventory.grns.show', $purchaseInvoice);
        }

        $items = $purchaseInvoice->items()->with('variant.product')->get()->map(fn ($item) => [
            'product_variant_id' => $item->product_variant_id,
            'variant_label' => $item->variant->variant_sku.' — '.$item->variant->product->name,
            'batch_no' => $item->batch_no,
            'mfg_date' => $item->mfg_date?->toDateString(),
            'expiry_date' => $item->expiry_date?->toDateString(),
            'qty' => $item->qty,
            'unit_cost' => number_format($item->unit_cost_paise / 100, 2, '.', ''),
            'gst_rate' => number_format($item->gst_rate_bp / 100, 2, '.', ''),
        ]);

        return view('admin.inventory.grns.form', [
            'invoice' => $purchaseInvoice,
            'items' => $items,
        ] + $this->formOptions());
    }

    public function update(PurchaseInvoiceRequest $request, PurchaseInvoice $purchaseInvoice): RedirectResponse
    {
        $data = $request->validated();

        try {
            $this->purchaseInvoices->updateDraft(
                $purchaseInvoice,
                [
                    'supplier_id' => $data['supplier_id'],
                    'purchase_order_id' => $data['purchase_order_id'] ?? null,
                    'warehouse_code' => $data['warehouse_code'],
                    'supplier_invoice_no' => $data['supplier_invoice_no'],
                    'supplier_invoice_date' => $data['supplier_invoice_date'],
                    'notes' => $data['notes'] ?? null,
                ],
                $this->linesFromRequest($data['lines']),
            );
        } catch (RuntimeException $e) {
            return redirect()->route('admin.inventory.grns.show', $purchaseInvoice)->withErrors(['grn' => $e->getMessage()]);
        }

        return redirect()->route('admin.inventory.grns.show', $purchaseInvoice)->with('status', 'GRN saved.');
    }

    public function show(PurchaseInvoice $purchaseInvoice): View
    {
        $purchaseInvoice->load(['supplier', 'purchaseOrder', 'warehouse', 'postedBy', 'items.variant.product']);

        return view('admin.inventory.grns.show', ['invoice' => $purchaseInvoice]);
    }

    public function post(PurchaseInvoice $purchaseInvoice): RedirectResponse
    {
        try {
            $this->purchaseInvoices->post($purchaseInvoice, (int) Auth::id());
        } catch (RuntimeException $e) {
            return redirect()->route('admin.inventory.grns.show', $purchaseInvoice)->withErrors(['grn' => $e->getMessage()]);
        }

        return redirect()->route('admin.inventory.grns.show', $purchaseInvoice)->with('status', "GRN {$purchaseInvoice->grn_no} posted. Stock is now on hand.");
    }

    public function cancel(Request $request, PurchaseInvoice $purchaseInvoice): RedirectResponse
    {
        $validated = $request->validate(['reason' => ['required', 'string', 'max:255']]);

        try {
            $this->purchaseInvoices->cancel($purchaseInvoice, $validated['reason'], (int) Auth::id());
        } catch (RuntimeException $e) {
            return redirect()->route('admin.inventory.grns.show', $purchaseInvoice)->withErrors(['grn' => $e->getMessage()]);
        }

        return redirect()->route('admin.inventory.grns.show', $purchaseInvoice)->with('status', "GRN {$purchaseInvoice->grn_no} cancelled.");
    }

    /** @return array<string, mixed> */
    private function formOptions(): array
    {
        return [
            'suppliers' => Supplier::query()->active()->orderBy('name')->get(['id', 'name']),
            'warehouses' => Warehouse::query()->active()->orderBy('name')->get(['code', 'name']),
            'purchaseOrders' => PurchaseOrder::query()->whereIn('status', ['sent', 'partially_received'])
                ->orderByDesc('id')->get(['id', 'po_no', 'supplier_id']),
            'variants' => ProductVariant::query()->where('status', 'active')->with('product:id,name')
                ->orderBy('variant_sku')->get(['id', 'product_id', 'variant_sku', 'name', 'gst_rate_bp']),
        ];
    }

    /**
     * @param  list<array{product_variant_id: string|int, batch_no: string, mfg_date: ?string, expiry_date: ?string,
     *                     qty: string|int, unit_cost: string, gst_rate: string}>  $lines
     * @return list<array{product_variant_id: int, batch_no: string, mfg_date: ?string, expiry_date: ?string,
     *                     qty: int, unit_cost_paise: int, gst_rate_bp: int}>
     */
    private function linesFromRequest(array $lines): array
    {
        return array_map(static fn (array $line): array => [
            'product_variant_id' => (int) $line['product_variant_id'],
            'batch_no' => $line['batch_no'],
            'mfg_date' => $line['mfg_date'] ?: null,
            'expiry_date' => $line['expiry_date'] ?: null,
            'qty' => (int) $line['qty'],
            'unit_cost_paise' => (int) round(((float) $line['unit_cost']) * 100),
            'gst_rate_bp' => (int) round(((float) $line['gst_rate']) * 100),
        ], $lines);
    }
}
