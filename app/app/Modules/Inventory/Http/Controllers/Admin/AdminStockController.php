<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Http\Controllers\Admin;

use App\Modules\Catalog\Models\InventoryLevel;
use App\Modules\Inventory\Models\StockBatch;
use App\Modules\Inventory\Models\Warehouse;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * The read-only "what do we have" screen (plan §7.1). Search and warehouse
 * filter are separate columns from `warehouses`/`transfers`/`adjustments`
 * because this one is `inventory.view` — finance can look without being able
 * to move anything.
 */
final class AdminStockController extends Controller
{
    public function index(Request $request): View
    {
        $warehouseCode = (string) $request->query('warehouse_code', '');
        $search = trim((string) $request->query('q', ''));

        $levels = InventoryLevel::query()
            ->join('product_variants', 'product_variants.id', '=', 'inventory_levels.product_variant_id')
            ->join('products', 'products.id', '=', 'product_variants.product_id')
            ->when($warehouseCode !== '', fn ($q) => $q->where('inventory_levels.warehouse_code', $warehouseCode))
            ->when($search !== '', function ($q) use ($search): void {
                $q->where(function ($q2) use ($search): void {
                    $q2->where('product_variants.variant_sku', 'like', "%{$search}%")
                        ->orWhere('products.name', 'like', "%{$search}%");
                });
            })
            ->orderBy('products.name')
            ->select([
                'inventory_levels.id', 'inventory_levels.product_variant_id', 'inventory_levels.warehouse_code',
                'inventory_levels.on_hand', 'inventory_levels.reserved', 'inventory_levels.reorder_level',
                'product_variants.variant_sku', 'products.name as product_name',
            ])
            ->paginate(25)
            ->withQueryString();

        $variantIds = $levels->getCollection()->pluck('product_variant_id')->unique()->all();
        $warehouseCodes = $levels->getCollection()->pluck('warehouse_code')->unique()->all();

        $batchesByKey = StockBatch::query()
            ->whereIn('product_variant_id', $variantIds)
            ->whereIn('warehouse_code', $warehouseCodes)
            ->orderByRaw('expiry_date IS NULL, expiry_date ASC')
            ->get()
            ->groupBy(static fn (StockBatch $batch): string => $batch->product_variant_id.'|'.$batch->warehouse_code);

        return view('admin.inventory.stock.index', [
            'levels' => $levels,
            'batchesByKey' => $batchesByKey,
            'warehouses' => Warehouse::query()->orderBy('name')->get(['code', 'name']),
            'warehouseCode' => $warehouseCode,
            'search' => $search,
        ]);
    }
}
