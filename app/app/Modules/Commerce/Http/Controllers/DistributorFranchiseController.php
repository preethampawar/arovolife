<?php

declare(strict_types=1);

namespace App\Modules\Commerce\Http\Controllers;

use App\Modules\Commerce\Models\Franchise;
use App\Modules\Commerce\Services\FranchiseCodeGenerator;
use App\Modules\Compensation\Models\AreteCenter;
use App\Modules\Shared\Features\FranchiseFeature;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\View\View;
use Laravel\Pennant\Feature;

final class DistributorFranchiseController extends Controller
{
    public function showApply(Request $request): View|RedirectResponse
    {
        if (! Feature::for(null)->active(FranchiseFeature::class)) {
            abort(403, 'Franchise applications are not yet open.');
        }

        $distributor = $request->user()?->distributor;
        if (! $distributor) {
            return redirect()->route('login');
        }

        $existing = Franchise::where('operator_distributor_id', $distributor->id)
            ->whereIn('status', [Franchise::STATUS_PENDING, Franchise::STATUS_ACTIVE])
            ->first();

        if ($existing) {
            return redirect()->route('franchise.status');
        }

        $areteCentres = AreteCenter::where('status', AreteCenter::STATUS_ACTIVE)
            ->orderBy('name')
            ->get();

        return view('my.franchise.apply', compact('areteCentres'));
    }

    public function handleApply(Request $request): RedirectResponse
    {
        if (! Feature::for(null)->active(FranchiseFeature::class)) {
            abort(403, 'Franchise applications are not yet open.');
        }

        $distributor = $request->user()?->distributor;
        abort_unless($distributor !== null, 403);

        abort_if(
            Franchise::where('operator_distributor_id', $distributor->id)
                ->whereIn('status', [Franchise::STATUS_PENDING, Franchise::STATUS_ACTIVE])
                ->exists(),
            422,
            'You already have an active or pending franchise application.',
        );

        $validated = $request->validate([
            'address_line' => ['required', 'string', 'max:255'],
            'pincode' => ['required', 'string', 'digits:6'],
            'district' => ['required', 'string', 'max:100'],
            'state' => ['required', 'string', 'max:100'],
            'arete_center_id' => ['nullable', 'integer', 'exists:arete_centers,id'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $code = app(FranchiseCodeGenerator::class)->generate();

        // `name` is NOT NULL on the franchises table. Applicants do not choose
        // it — admin renames on approval — so derive a stable placeholder from
        // the operator and the proposed location.
        $operatorName = $distributor->user->full_name ?? $distributor->adn;

        Franchise::create([
            'operator_distributor_id' => $distributor->id,
            'name' => mb_substr($operatorName.' — '.$validated['district'], 0, 160),
            'status' => Franchise::STATUS_PENDING,
            'applied_at' => now(),
            'code' => $code,
            'address_line' => $validated['address_line'],
            'pincode' => $validated['pincode'],
            'district' => $validated['district'],
            'state' => $validated['state'],
            'arete_center_id' => $validated['arete_center_id'] ?? null,
            'notes' => $validated['notes'] ?? null,
        ]);

        return redirect()->route('franchise.status')
            ->with('success', 'Your application has been submitted. We will be in touch shortly.');
    }

    public function showStatus(Request $request): View
    {
        if (! Feature::for(null)->active(FranchiseFeature::class)) {
            abort(403, 'Franchise applications are not yet open.');
        }

        $distributor = $request->user()?->distributor;
        abort_unless($distributor !== null, 403);

        $franchise = Franchise::where('operator_distributor_id', $distributor->id)
            ->latest()
            ->first();

        return view('my.franchise.status', compact('franchise'));
    }
}
