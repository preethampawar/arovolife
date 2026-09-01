<?php

declare(strict_types=1);

namespace App\Modules\Compensation\Http\Controllers\Admin;

use App\Modules\Compensation\Models\AreteCenter;
use App\Modules\Compensation\Models\AreteCenterMember;
use App\Modules\Compensation\Notifications\AreteCenterDeactivatedNotification;
use App\Modules\Compliance\Models\AuditLog;
use App\Modules\Identity\Models\Distributor;
use App\Modules\Shared\Support\IndianStates;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Arete Development Centre registry (spec §B).
 *
 * Centres are entities in their own right — registration Step 11, the
 * profile page and the member directory all read this registry — so it is
 * never gated on the ADC *bonus* flag. The bonus is a layer on top; its
 * pages live under Compensation and carry their own flag.
 */
final class AdminAreteCenterController extends Controller
{
    public function index(Request $request): View
    {
        $filters = [
            'status' => (string) $request->query('status', ''),
            'state' => (string) $request->query('state', ''),
            'city' => trim((string) $request->query('city', '')),
            'type' => (string) $request->query('type', ''),
            'phase' => (string) $request->query('phase', ''),
            'adn' => trim((string) $request->query('adn', '')),
            'q' => trim((string) $request->query('q', '')),
        ];

        $query = AreteCenter::with('assignedDistributor');

        if (in_array($filters['status'], [AreteCenter::STATUS_ACTIVE, AreteCenter::STATUS_INACTIVE], true)) {
            $query->where('status', $filters['status']);
        }
        if ($filters['state'] !== '' && in_array($filters['state'], IndianStates::all(), true)) {
            $query->where('state', $filters['state']);
        }
        if ($filters['city'] !== '') {
            $query->where(fn ($q) => $q->where('city', 'like', $filters['city'].'%')->orWhere('district', 'like', $filters['city'].'%'));
        }
        if (array_key_exists($filters['type'], AreteCenter::TYPES)) {
            $query->where('centre_type', $filters['type']);
        }
        if (ctype_digit($filters['phase']) && isset(AreteCenter::PHASES[(int) $filters['phase']])) {
            $query->where('development_phase', (int) $filters['phase']);
        }
        if ($filters['adn'] !== '') {
            $query->whereHas('assignedDistributor', fn ($d) => $d->where('adn', 'like', '%'.$filters['adn'].'%'));
        }
        if ($filters['q'] !== '') {
            $query->where('name', 'like', '%'.$filters['q'].'%');
        }

        $centers = $query->orderBy('name')->paginate(30)->withQueryString();

        // Live attributed BV for the current month — keyed by center id.
        $monthStart = Carbon::today()->startOfMonth()->toDateString();
        $monthEnd = Carbon::today()->endOfMonth()->toDateString();
        $centerIds = $centers->pluck('id');
        $currentMonthBv = DB::table('bv_ledger_entries')
            ->join('orders', 'orders.id', '=', 'bv_ledger_entries.order_id')
            ->whereIn('orders.arete_center_id', $centerIds)
            ->whereBetween('bv_ledger_entries.effective_at', [$monthStart.' 00:00:00', $monthEnd.' 23:59:59'])
            ->groupBy('orders.arete_center_id')
            ->selectRaw('orders.arete_center_id, SUM(bv_ledger_entries.bv_paise) as bv_paise')
            ->pluck('bv_paise', 'arete_center_id');

        return view('admin.arete-centres.index', [
            'centers' => $centers,
            'filters' => $filters,
            'states' => IndianStates::all(),
            'currentMonthBv' => $currentMonthBv,
        ]);
    }

    public function create(): View
    {
        return view('admin.arete-centres.form', ['center' => null]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validateCenter($request);

        $distributor = $this->resolveOwner($data);
        if ($distributor === false) {
            return back()->withInput()->withErrors(['assigned_adn' => 'Distributor ADN not found.']);
        }

        $center = AreteCenter::create([
            ...$this->centerAttributes($data, $distributor),
            'status' => AreteCenter::STATUS_ACTIVE,
            'development_phase' => $data['development_phase'] ?? 1,
        ]);

        AuditLog::create([
            'actor_id' => Auth::id(),
            'action' => 'adc.center.created',
            'subject_type' => 'arete_center',
            'subject_id' => $center->id,
            'details' => ['before' => null, 'after' => $this->auditAttributes($center)],
            'ip' => $request->ip(),
        ]);

        return redirect()->route('admin.arete-centres.index')
            ->with('success', 'Center created.');
    }

    public function edit(AreteCenter $center): View
    {
        return view('admin.arete-centres.form', ['center' => $center]);
    }

    public function update(Request $request, AreteCenter $center): RedirectResponse
    {
        $data = $this->validateCenter($request);

        $distributor = $this->resolveOwner($data);
        if ($distributor === false) {
            return back()->withInput()->withErrors(['assigned_adn' => 'Distributor ADN not found.']);
        }

        $before = $this->auditAttributes($center);

        // Status is not part of the create form either, so an edit leaves the
        // center's current status untouched.
        $center->update([
            ...$this->centerAttributes($data, $distributor),
            'development_phase' => $data['development_phase'] ?? $center->development_phase,
        ]);

        AuditLog::create([
            'actor_id' => Auth::id(),
            'action' => 'adc.center.updated',
            'subject_type' => 'arete_center',
            'subject_id' => $center->id,
            'details' => ['before' => $before, 'after' => $this->auditAttributes($center)],
            'ip' => $request->ip(),
        ]);

        return redirect()->route('admin.arete-centres.index')
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
        return $center->only([
            'name', 'centre_type', 'location', 'address_line_1', 'address_line_2', 'landmark',
            'city', 'pincode', 'district', 'state', 'property_type', 'premises_sqft',
            'distance_to_nearest_adc_km', 'opening_time', 'closing_time', 'weekly_off',
            'contact_person', 'contact_number', 'alternate_contact_number',
            'assigned_distributor_id', 'status', 'development_phase',
            'monthly_cap_override_paise', 'approved_at', 'notes', 'is_company_default',
            'deactivated_at', 'deactivation_reason',
        ]);
    }

    /** @return array<string, mixed> */
    private function validateCenter(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:200'],
            // Optional: derived from the owner ADN when omitted (owner → distributor centre).
            'centre_type' => ['nullable', Rule::in(array_keys(AreteCenter::TYPES))],
            'address_line_1' => ['nullable', 'string', 'max:255'],
            'address_line_2' => ['nullable', 'string', 'max:255'],
            'landmark' => ['nullable', 'string', 'max:150'],
            'city' => ['nullable', 'string', 'max:100'],
            // Legacy field names still accepted as aliases (district → city).
            'district' => ['nullable', 'string', 'max:100'],
            'location' => ['nullable', 'string', 'max:300'],
            'pincode' => ['nullable', 'digits:6'],
            'state' => ['nullable', Rule::in(IndianStates::all())],
            'property_type' => ['nullable', Rule::in(array_keys(AreteCenter::PROPERTY_TYPES))],
            'premises_sqft' => ['nullable', 'integer', 'min:1', 'max:100000'],
            'distance_to_nearest_adc_km' => ['nullable', 'numeric', 'min:0', 'max:9999.9'],
            'opening_time' => ['nullable', 'date_format:H:i'],
            'closing_time' => ['nullable', 'date_format:H:i'],
            'weekly_off' => ['nullable', Rule::in(array_keys(AreteCenter::WEEKLY_OFF_OPTIONS))],
            'contact_person' => ['nullable', 'string', 'max:150'],
            'contact_number' => ['nullable', 'string', 'regex:/^\+?[0-9]{10,15}$/'],
            'alternate_contact_number' => ['nullable', 'string', 'regex:/^\+?[0-9]{10,15}$/'],
            // Required for a distributor centre (someone must earn the bonus);
            // blank for a company centre.
            'assigned_adn' => ['nullable', 'required_if:centre_type,'.AreteCenter::TYPE_DISTRIBUTOR, 'string', 'max:20'],
            'development_phase' => ['nullable', 'integer', 'between:1,4'],
            'monthly_cap_override' => ['nullable', 'integer', 'min:0', 'max:100000'],
            'approved_at' => ['nullable', 'date'],
            'notes' => ['nullable', 'string'],
        ], [
            'assigned_adn.required_if' => 'A distributor centre needs an owner ADN.',
        ]);
    }

    /**
     * Owner for the submitted ADN: the distributor, null when a company centre
     * has none, or false when the ADN does not exist.
     *
     * @param  array<string, mixed>  $data
     */
    private function resolveOwner(array $data): Distributor|null|false
    {
        $adn = trim((string) ($data['assigned_adn'] ?? ''));
        if ($adn === '') {
            return null;
        }

        return Distributor::where('adn', $adn)->first() ?? false;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function centerAttributes(array $data, ?Distributor $owner): array
    {
        return [
            'name' => $data['name'],
            'centre_type' => $data['centre_type'] ?? ($owner !== null ? AreteCenter::TYPE_DISTRIBUTOR : AreteCenter::TYPE_COMPANY),
            'location' => $data['location'] ?? null,
            'address_line_1' => $data['address_line_1'] ?? ($data['location'] ?? null),
            'address_line_2' => $data['address_line_2'] ?? null,
            'landmark' => $data['landmark'] ?? null,
            'city' => $data['city'] ?? ($data['district'] ?? null),
            'district' => $data['city'] ?? ($data['district'] ?? null),
            'pincode' => $data['pincode'] ?? null,
            'state' => $data['state'] ?? null,
            'property_type' => $data['property_type'] ?? null,
            'premises_sqft' => $data['premises_sqft'] ?? null,
            'distance_to_nearest_adc_km' => $data['distance_to_nearest_adc_km'] ?? null,
            'opening_time' => $data['opening_time'] ?? null,
            'closing_time' => $data['closing_time'] ?? null,
            'weekly_off' => $data['weekly_off'] ?? null,
            'contact_person' => $data['contact_person'] ?? null,
            'contact_number' => $data['contact_number'] ?? null,
            'alternate_contact_number' => $data['alternate_contact_number'] ?? null,
            'assigned_distributor_id' => $owner?->id,
            'monthly_cap_override_paise' => isset($data['monthly_cap_override']) ? (int) $data['monthly_cap_override'] * 100 : null,
            'approved_at' => $data['approved_at'] ?? null,
            'notes' => $data['notes'] ?? null,
        ];
    }

    /**
     * Activate / deactivate a centre. Deactivation needs a reason; it hides the
     * centre from every picker but never rewrites who chose it in the past.
     *
     * @param  'activate'|'deactivate'  $action
     */
    public function setStatus(Request $request, AreteCenter $center, string $action): RedirectResponse
    {
        $target = match ($action) {
            'activate' => AreteCenter::STATUS_ACTIVE,
            'deactivate' => AreteCenter::STATUS_INACTIVE,
            default => abort(404),
        };

        if ($target === AreteCenter::STATUS_INACTIVE && $center->is_company_default) {
            return back()->withErrors(['status' => 'The company default centre cannot be deactivated — set another centre as the default first.']);
        }

        $validated = $request->validate([
            'reason' => [$target === AreteCenter::STATUS_INACTIVE ? 'required' : 'nullable', 'string', 'max:500'],
        ]);

        $before = $this->auditAttributes($center);

        $center->update([
            'status' => $target,
            'deactivated_at' => $target === AreteCenter::STATUS_INACTIVE ? now() : null,
            'deactivation_reason' => $target === AreteCenter::STATUS_INACTIVE ? $validated['reason'] : null,
        ]);

        AuditLog::create([
            'actor_id' => Auth::id(),
            'action' => 'adc.center.status_changed',
            'subject_type' => 'arete_center',
            'subject_id' => $center->id,
            'details' => ['before' => $before, 'after' => $this->auditAttributes($center), 'reason' => $validated['reason'] ?? null],
            'ip' => $request->ip(),
        ]);

        // The owner loses the ADC bonus from this centre: tell them why, and
        // where to object (DSR 2021 grievance duty).
        if ($target === AreteCenter::STATUS_INACTIVE && $center->assignedDistributor?->user !== null) {
            $center->assignedDistributor->user->notify(new AreteCenterDeactivatedNotification($center->name, (string) $validated['reason']));
        }

        return back()->with('success', 'Centre "'.$center->name.'" is now '.$target.'.');
    }

    /** Make this active centre the company default that Step 11 pre-selects. */
    public function setDefault(Request $request, AreteCenter $center): RedirectResponse
    {

        if (! $center->isActive()) {
            return back()->withErrors(['status' => 'Only an active centre can be the company default.']);
        }

        $previous = AreteCenter::where('is_company_default', true)->where('id', '!=', $center->id)->get();

        AreteCenter::where('is_company_default', true)->update(['is_company_default' => false]);
        $center->update(['is_company_default' => true]);

        AuditLog::create([
            'actor_id' => Auth::id(),
            'action' => 'adc.center.default_changed',
            'subject_type' => 'arete_center',
            'subject_id' => $center->id,
            'details' => ['before' => $previous->pluck('id')->all(), 'after' => [$center->id]],
            'ip' => $request->ip(),
        ]);

        return back()->with('success', 'Centre "'.$center->name.'" is now the company default.');
    }

    public function addMember(Request $request, int $centerId): RedirectResponse
    {
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
