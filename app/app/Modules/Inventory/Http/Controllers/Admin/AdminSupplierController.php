<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Http\Controllers\Admin;

use App\Modules\Inventory\Http\Requests\SupplierRequest;
use App\Modules\Inventory\Models\Supplier;
use App\Modules\Inventory\Services\SupplierService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Auth;

final class AdminSupplierController extends Controller
{
    public function __construct(private readonly SupplierService $suppliers) {}

    public function index(): View
    {
        $suppliers = Supplier::query()->orderBy('name')->paginate(25);

        return view('admin.inventory.suppliers.index', ['suppliers' => $suppliers]);
    }

    public function create(): View
    {
        return view('admin.inventory.suppliers.form', [
            'supplier' => new Supplier(['status' => Supplier::STATUS_ACTIVE]),
        ]);
    }

    public function store(SupplierRequest $request): RedirectResponse
    {
        $supplier = $this->suppliers->create($request->validated(), (int) Auth::id());

        return redirect()->route('admin.inventory.suppliers.index')->with('status', "Supplier \"{$supplier->name}\" created.");
    }

    public function edit(Supplier $supplier): View
    {
        return view('admin.inventory.suppliers.form', ['supplier' => $supplier]);
    }

    public function update(SupplierRequest $request, Supplier $supplier): RedirectResponse
    {
        $this->suppliers->update($supplier, $request->validated(), (int) Auth::id());

        return redirect()->route('admin.inventory.suppliers.edit', $supplier)->with('status', 'Supplier saved.');
    }

    public function archive(Supplier $supplier): RedirectResponse
    {
        $this->suppliers->archive($supplier, (int) Auth::id());

        return redirect()->route('admin.inventory.suppliers.index')->with('status', "Supplier \"{$supplier->name}\" archived.");
    }

    public function reactivate(Supplier $supplier): RedirectResponse
    {
        $this->suppliers->reactivate($supplier, (int) Auth::id());

        return redirect()->route('admin.inventory.suppliers.index')->with('status', "Supplier \"{$supplier->name}\" reactivated.");
    }
}
