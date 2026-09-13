<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Http\Controllers\Admin;

use App\Modules\Inventory\Http\Requests\WarehouseRequest;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\WarehouseService;
use App\Modules\Shared\Support\FilterField;
use App\Modules\Shared\Support\ListFilters;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Auth;
use RuntimeException;

final class AdminWarehouseController extends Controller
{
    public function __construct(private readonly WarehouseService $warehouses) {}

    public function index(Request $request): View
    {
        $filters = ListFilters::make($request, [
            FilterField::text('q', 'Search', 'Name or code', columns: ['warehouses.name', 'warehouses.code']),
            FilterField::select('status', 'Status', [
                Warehouse::STATUS_ACTIVE => 'Active',
                Warehouse::STATUS_ARCHIVED => 'Archived',
            ], column: 'warehouses.status', placeholder: 'All statuses'),
            FilterField::select('type', 'Type', [
                Warehouse::TYPE_HUB => 'Hub',
                Warehouse::TYPE_WAREHOUSE => 'Warehouse',
                Warehouse::TYPE_FRANCHISE => 'Franchise',
            ], column: 'warehouses.type', placeholder: 'All types'),
            FilterField::boolean('fulfils_orders', 'Fulfils orders', column: 'warehouses.fulfils_orders'),
        ]);

        $warehouses = $filters->apply(Warehouse::query())
            ->orderBy('name')
            ->paginate(25)
            ->withQueryString();

        return view('admin.inventory.warehouses.index', ['warehouses' => $warehouses, 'filters' => $filters]);
    }

    public function create(): View
    {
        return view('admin.inventory.warehouses.form', [
            'warehouse' => new Warehouse(['type' => Warehouse::TYPE_WAREHOUSE, 'fulfils_orders' => true, 'status' => Warehouse::STATUS_ACTIVE]),
        ]);
    }

    public function store(WarehouseRequest $request): RedirectResponse
    {
        $warehouse = $this->warehouses->create($request->validated(), (int) Auth::id());

        return redirect()->route('admin.inventory.warehouses.index')->with('status', "Warehouse \"{$warehouse->name}\" created.");
    }

    public function edit(Warehouse $warehouse): View
    {
        return view('admin.inventory.warehouses.form', ['warehouse' => $warehouse]);
    }

    public function update(WarehouseRequest $request, Warehouse $warehouse): RedirectResponse
    {
        $this->warehouses->update($warehouse, $request->validated(), (int) Auth::id());

        return redirect()->route('admin.inventory.warehouses.edit', $warehouse)->with('status', 'Warehouse saved.');
    }

    public function archive(Warehouse $warehouse): RedirectResponse
    {
        try {
            $this->warehouses->archive($warehouse, (int) Auth::id());
        } catch (RuntimeException $e) {
            return redirect()->route('admin.inventory.warehouses.edit', $warehouse)->withErrors(['warehouse' => $e->getMessage()]);
        }

        return redirect()->route('admin.inventory.warehouses.index')->with('status', "Warehouse \"{$warehouse->name}\" archived.");
    }

    public function reactivate(Warehouse $warehouse): RedirectResponse
    {
        $this->warehouses->reactivate($warehouse, (int) Auth::id());

        return redirect()->route('admin.inventory.warehouses.index')->with('status', "Warehouse \"{$warehouse->name}\" reactivated.");
    }
}
