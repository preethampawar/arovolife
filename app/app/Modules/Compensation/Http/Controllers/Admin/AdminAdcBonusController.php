<?php

declare(strict_types=1);

namespace App\Modules\Compensation\Http\Controllers\Admin;

use App\Modules\Compensation\Models\AdcBonusResult;
use App\Modules\Compensation\Models\AreteCenter;
use App\Modules\Compensation\Models\AreteCenterMember;
use App\Modules\Compliance\Models\AuditLog;
use App\Modules\Identity\Models\Distributor;
use App\Modules\Shared\Features\AreteDevelopmentCenterBonusFeature;
use App\Modules\Shared\Support\IndianStates;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Laravel\Pennant\Feature;

final class AdminAdcBonusController extends Controller
{
    public function index(): View
    {
        abort_unless(Feature::for(null)->active(AreteDevelopmentCenterBonusFeature::class), 404);

        $months = AdcBonusResult::query()
            ->selectRaw('
                month_start,
                COUNT(DISTINCT center_id) as center_count,
                SUM(CASE WHEN status = ? THEN net_paise ELSE 0 END) as total_net_paise,
                MAX(credited_at) as credited_at
            ', [AdcBonusResult::STATUS_CREDITED])
            ->groupBy('month_start')
            ->orderByDesc('month_start')
            ->get();

        return view('admin.compensation.adc-bonus.index', compact('months'));
    }

    public function show(string $month): View
    {
        abort_unless(Feature::for(null)->active(AreteDevelopmentCenterBonusFeature::class), 404);

        $date = Carbon::parse($month.'-01');

        $results = AdcBonusResult::with('center', 'distributor')
            ->where('month_start', $date->toDateString())
            ->orderByDesc('gross_paise')
            ->paginate(50)
            ->withQueryString();

        return view('admin.compensation.adc-bonus.show', compact('results', 'date'));
    }

    public function centersIndex(): View
    {
        abort_unless(Feature::for(null)->active(AreteDevelopmentCenterBonusFeature::class), 404);

        $centers = AreteCenter::with('assignedDistributor')
            ->withCount('members')
            ->orderBy('name')
            ->paginate(30);

        return view('admin.compensation.adc-bonus.centers', compact('centers'));
    }

    public function centersCreate(): View
    {
        abort_unless(Feature::for(null)->active(AreteDevelopmentCenterBonusFeature::class), 404);

        return view('admin.compensation.adc-bonus.center-form', ['center' => null]);
    }

    public function centersStore(Request $request): RedirectResponse
    {
        abort_unless(Feature::for(null)->active(AreteDevelopmentCenterBonusFeature::class), 404);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:200'],
            'location' => ['nullable', 'string', 'max:300'],
            'pincode' => ['nullable', 'digits:6'],
            'district' => ['nullable', 'string', 'max:100'],
            'state' => ['nullable', Rule::in(IndianStates::all())],
            'assigned_adn' => ['required', 'string'],
            'approved_at' => ['nullable', 'date'],
            'notes' => ['nullable', 'string'],
        ]);

        $distributor = Distributor::where('adn', $data['assigned_adn'])->first();
        abort_unless($distributor !== null, 422, 'Distributor ADN not found.');

        $center = AreteCenter::create([
            'name' => $data['name'],
            'location' => $data['location'] ?? null,
            'pincode' => $data['pincode'] ?? null,
            'district' => $data['district'] ?? null,
            'state' => $data['state'] ?? null,
            'assigned_distributor_id' => $distributor->id,
            'status' => AreteCenter::STATUS_ACTIVE,
            'approved_at' => $data['approved_at'] ?? null,
            'notes' => $data['notes'] ?? null,
        ]);

        AuditLog::create([
            'actor_id' => Auth::id(),
            'action' => 'adc.center.created',
            'subject_type' => 'arete_center',
            'subject_id' => $center->id,
            'details' => ['before' => null, 'after' => $this->auditAttributes($center)],
            'ip' => $request->ip(),
        ]);

        return redirect()->route('admin.compensation.adc-bonus.centers.index')
            ->with('success', 'Center created.');
    }

    public function centersEdit(AreteCenter $center): View
    {
        abort_unless(Feature::for(null)->active(AreteDevelopmentCenterBonusFeature::class), 404);

        return view('admin.compensation.adc-bonus.center-form', ['center' => $center]);
    }

    public function centersUpdate(Request $request, AreteCenter $center): RedirectResponse
    {
        abort_unless(Feature::for(null)->active(AreteDevelopmentCenterBonusFeature::class), 404);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:200'],
            'location' => ['nullable', 'string', 'max:300'],
            'pincode' => ['nullable', 'digits:6'],
            'district' => ['nullable', 'string', 'max:100'],
            'state' => ['nullable', Rule::in(IndianStates::all())],
            'assigned_adn' => ['required', 'string'],
            'approved_at' => ['nullable', 'date'],
            'notes' => ['nullable', 'string'],
        ]);

        $distributor = Distributor::where('adn', $data['assigned_adn'])->first();
        abort_unless($distributor !== null, 422, 'Distributor ADN not found.');

        $before = $this->auditAttributes($center);

        // Status is not part of the create form either, so an edit leaves the
        // center's current status untouched.
        $center->update([
            'name' => $data['name'],
            'location' => $data['location'] ?? null,
            'pincode' => $data['pincode'] ?? null,
            'district' => $data['district'] ?? null,
            'state' => $data['state'] ?? null,
            'assigned_distributor_id' => $distributor->id,
            'approved_at' => $data['approved_at'] ?? null,
            'notes' => $data['notes'] ?? null,
        ]);

        AuditLog::create([
            'actor_id' => Auth::id(),
            'action' => 'adc.center.updated',
            'subject_type' => 'arete_center',
            'subject_id' => $center->id,
            'details' => ['before' => $before, 'after' => $this->auditAttributes($center)],
            'ip' => $request->ip(),
        ]);

        return redirect()->route('admin.compensation.adc-bonus.centers.index')
            ->with('success', 'Center updated.');
    }

    /**
     * Audit payload for a center. Carries no PII beyond the center's own
     * address fields and the assigned distributor's internal id.
     *
     * @return array<string, mixed>
     */
    private function auditAttributes(AreteCenter $center): array
    {
        return [
            'name' => $center->name,
            'location' => $center->location,
            'pincode' => $center->pincode,
            'district' => $center->district,
            'state' => $center->state,
            'assigned_distributor_id' => $center->assigned_distributor_id,
            'status' => $center->status,
            'approved_at' => $center->approved_at,
            'notes' => $center->notes,
        ];
    }

    public function centersAddMember(Request $request, int $centerId): RedirectResponse
    {
        abort_unless(Feature::for(null)->active(AreteDevelopmentCenterBonusFeature::class), 404);

        $center = AreteCenter::findOrFail($centerId);

        $data = $request->validate([
            'adn' => ['required', 'string'],
            'effective_from' => ['required', 'date'],
        ]);

        $distributor = Distributor::where('adn', $data['adn'])->first();
        abort_unless($distributor !== null, 422, 'Distributor ADN not found.');

        AreteCenterMember::updateOrCreate(
            ['center_id' => $center->id, 'distributor_id' => $distributor->id],
            ['effective_from' => $data['effective_from'], 'effective_to' => null],
        );

        return back()->with('success', 'Member added to center.');
    }
}
