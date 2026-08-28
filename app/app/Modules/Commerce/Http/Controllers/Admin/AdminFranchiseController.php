<?php

declare(strict_types=1);

namespace App\Modules\Commerce\Http\Controllers\Admin;

use App\Modules\Commerce\Models\Franchise;
use App\Modules\Shared\Features\FranchiseFeature;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Laravel\Pennant\Feature;

final class AdminFranchiseController extends Controller
{
    public function index(Request $request): View
    {
        $status = $request->query('status');

        $franchises = Franchise::with(['operator', 'operator.user', 'areteCenter'])
            ->when($status, fn ($q) => $q->where('status', $status))
            ->orderByDesc('applied_at')
            ->paginate(25);

        $statusCounts = Franchise::selectRaw('status, COUNT(*) as c')
            ->groupBy('status')
            ->pluck('c', 'status')
            ->all();

        $flagActive = Feature::for(null)->active(FranchiseFeature::class);

        return view('admin.commerce.franchise-index', [
            'franchises' => $franchises,
            'statusCounts' => $statusCounts,
            'flagActive' => $flagActive,
        ]);
    }

    public function approve(Request $request, Franchise $franchise): RedirectResponse
    {
        $validated = $request->validate([
            'admin_notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $franchise->update([
            'status' => Franchise::STATUS_ACTIVE,
            'admin_notes' => $validated['admin_notes'] ?? null,
            'reviewed_by' => $request->user()?->id,
            'reviewed_at' => now(),
            'activated_at' => now(),
        ]);

        return redirect()->route('admin.commerce.franchise.index')
            ->with('success', "Franchise {$franchise->code} approved.");
    }

    public function reject(Request $request, Franchise $franchise): RedirectResponse
    {
        $validated = $request->validate([
            'admin_notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $franchise->update([
            'status' => Franchise::STATUS_REJECTED,
            'admin_notes' => $validated['admin_notes'] ?? null,
            'reviewed_by' => $request->user()?->id,
            'reviewed_at' => now(),
        ]);

        return redirect()->route('admin.commerce.franchise.index')
            ->with('success', "Franchise {$franchise->code} rejected.");
    }
}
