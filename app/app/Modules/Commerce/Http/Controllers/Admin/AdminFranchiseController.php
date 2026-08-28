<?php

declare(strict_types=1);

namespace App\Modules\Commerce\Http\Controllers\Admin;

use App\Modules\Commerce\Models\Franchise;
use App\Modules\Commerce\Services\FranchiseCodeGenerator;
use App\Modules\Compensation\Models\FranchiseCommissionResult;
use App\Modules\Compensation\Services\CompensationPlanSettingsService;
use App\Modules\Compliance\Models\AuditLog;
use App\Modules\Identity\Models\Distributor;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use App\Modules\Shared\Features\FranchiseFeature;
use Illuminate\View\View;
use Laravel\Pennant\Feature;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Franchise register and its lifecycle.
 *
 * A franchise starts as an application and only earns once someone approves
 * it. That gate is the point: the compliance review permits franchises as
 * company fulfilment infrastructure, and infrastructure the company has not
 * agreed to is not infrastructure.
 */
final class AdminFranchiseController extends Controller
{
    public function __construct(
        private readonly FranchiseCodeGenerator $codes,
        private readonly CompensationPlanSettingsService $plan,
    ) {}

    public function index(Request $request): View
    {
        $this->guardFeature();

        $status = (string) $request->query('status', '');
        $search = trim((string) $request->query('q', ''));

        $query = Franchise::query()->with(['operator.user', 'areteCenter']);

        if ($status !== '' && in_array($status, [
            Franchise::STATUS_PENDING, Franchise::STATUS_ACTIVE,
            Franchise::STATUS_SUSPENDED, Franchise::STATUS_CLOSED,
        ], true)) {
            $query->where('status', $status);
        }

        if ($search !== '') {
            $query->where(function ($q) use ($search): void {
                $q->where('code', 'like', "%{$search}%")
                    ->orWhere('name', 'like', "%{$search}%")
                    ->orWhere('pincode', 'like', "%{$search}%")
                    ->orWhere('district', 'like', "%{$search}%");
            });
        }

        return view('admin.commerce.franchises.index', [
            'franchises' => $query->orderByDesc('created_at')->paginate(20)->withQueryString(),
            'status' => $status,
            'search' => $search,
            'planRateBp' => $this->plan->franchiseRateBp(),
            'pendingCount' => Franchise::where('status', Franchise::STATUS_PENDING)->count(),
        ]);
    }

    public function create(): View
    {
        $this->guardFeature();

        return view('admin.commerce.franchises.create', [
            'planRateBp' => $this->plan->franchiseRateBp(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->guardFeature();

        $validated = $this->validatePayload($request);

        $operatorId = $this->resolveOperator($validated['operator_adn'] ?? null);

        if ($operatorId === false) {
            return back()->withInput()->withErrors(['operator_adn' => 'No distributor with that ADN.']);
        }

        $franchise = Franchise::create([
            'code' => $this->codes->generate(),
            'name' => $validated['name'],
            'operator_distributor_id' => $operatorId,
            'is_company_primary' => (bool) ($validated['is_company_primary'] ?? false),
            'address_line' => $validated['address_line'] ?? null,
            'pincode' => $validated['pincode'] ?? null,
            'district' => $validated['district'] ?? null,
            'state' => $validated['state'] ?? null,
            'status' => Franchise::STATUS_PENDING,
            'commission_rate_bp' => $validated['commission_rate_bp'] ?? null,
            'notes' => $validated['notes'] ?? null,
            'applied_at' => Carbon::now()->toDateString(),
        ]);

        $this->audit($franchise, 'franchise.created', [
            'operator_distributor_id' => $operatorId,
            'is_company_primary' => $franchise->is_company_primary,
        ]);

        return redirect()->route('admin.commerce.franchises.edit', $franchise->id)
            ->with('status', 'Franchise '.$franchise->code.' recorded as an application. Approve it to start earning.');
    }

    public function edit(int $id): View
    {
        $this->guardFeature();

        $franchise = $this->findOrFail($id);

        return view('admin.commerce.franchises.edit', [
            'franchise' => $franchise,
            'planRateBp' => $this->plan->franchiseRateBp(),
            'results' => FranchiseCommissionResult::where('franchise_id', $franchise->id)
                ->orderByDesc('month_start')
                ->limit(12)
                ->get(),
        ]);
    }

    public function update(Request $request, int $id): RedirectResponse
    {
        $this->guardFeature();

        $franchise = $this->findOrFail($id);
        $validated = $this->validatePayload($request);

        $operatorId = $this->resolveOperator($validated['operator_adn'] ?? null);

        if ($operatorId === false) {
            return back()->withInput()->withErrors(['operator_adn' => 'No distributor with that ADN.']);
        }

        $before = $franchise->only(['name', 'operator_distributor_id', 'pincode', 'commission_rate_bp']);

        $franchise->update([
            'name' => $validated['name'],
            'operator_distributor_id' => $operatorId,
            'is_company_primary' => (bool) ($validated['is_company_primary'] ?? false),
            'address_line' => $validated['address_line'] ?? null,
            'pincode' => $validated['pincode'] ?? null,
            'district' => $validated['district'] ?? null,
            'state' => $validated['state'] ?? null,
            'commission_rate_bp' => $validated['commission_rate_bp'] ?? null,
            'notes' => $validated['notes'] ?? null,
        ]);

        $this->audit($franchise, 'franchise.updated', [
            'before' => $before,
            'after' => $franchise->only(['name', 'operator_distributor_id', 'pincode', 'commission_rate_bp']),
        ]);

        return back()->with('status', 'Franchise updated.');
    }

    /**
     * Approve an application. Until this happens the franchise earns nothing
     * and cannot be chosen at checkout.
     */
    public function approve(Request $request, int $id): RedirectResponse
    {
        $this->guardFeature();

        $franchise = $this->findOrFail($id);

        if ($franchise->status === Franchise::STATUS_ACTIVE) {
            return back()->withErrors(['status' => 'This franchise is already active.']);
        }

        if ($franchise->operator_distributor_id === null && ! $franchise->is_company_primary) {
            return back()->withErrors([
                'status' => 'Assign an operating distributor before approving, or mark it as the company primary franchise.',
            ]);
        }

        $franchise->update([
            'status' => Franchise::STATUS_ACTIVE,
            'approved_at' => Carbon::now()->toDateString(),
            'approved_by_user_id' => Auth::id(),
        ]);

        $this->audit($franchise, 'franchise.approved');

        return back()->with('status', 'Franchise '.$franchise->code.' approved and open for collections.');
    }

    /**
     * @param  'suspend'|'close'|'reinstate'  $action
     */
    public function changeStatus(Request $request, int $id, string $action): RedirectResponse
    {
        $this->guardFeature();

        $franchise = $this->findOrFail($id);

        $validated = $request->validate([
            'reason' => ['required', 'string', 'max:500'],
        ]);

        $target = match ($action) {
            'suspend' => Franchise::STATUS_SUSPENDED,
            'close' => Franchise::STATUS_CLOSED,
            'reinstate' => Franchise::STATUS_ACTIVE,
            default => null,
        };

        if ($target === null) {
            throw new NotFoundHttpException;
        }

        $from = $franchise->status;
        $franchise->update(['status' => $target]);

        $this->audit($franchise, 'franchise.status_changed', [
            'from' => $from,
            'to' => $target,
            'reason' => $validated['reason'],
        ]);

        return back()->with('status', 'Franchise '.$franchise->code.' is now '.str_replace('_', ' ', $target).'.');
    }

    // ── Internals ────────────────────────────────────────────────────────────

    /**
     * Zero-trace gating: a franchise programme that has not launched must
     * leave no evidence that it exists, so an off flag 404s rather than 403s.
     */
    private function guardFeature(): void
    {
        abort_unless(Feature::for(null)->active(FranchiseFeature::class), 404);
    }


    /**
     * @return array<string, mixed>
     */
    private function validatePayload(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:160'],
            'operator_adn' => ['nullable', 'string', 'max:16'],
            'is_company_primary' => ['nullable', 'boolean'],
            'address_line' => ['nullable', 'string', 'max:255'],
            'pincode' => ['nullable', 'string', 'regex:/^[1-9][0-9]{5}$/'],
            'district' => ['nullable', 'string', 'max:100'],
            'state' => ['nullable', 'string', 'max:100'],
            // Basis points. Capped at 10% so a mistyped figure cannot quietly
            // commit the company to a rate nobody agreed.
            'commission_rate_bp' => ['nullable', 'integer', 'min:0', 'max:1000'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ], [
            'pincode.regex' => 'PIN code must be exactly 6 digits, e.g. 500032.',
            'commission_rate_bp.max' => 'A franchise rate above 10% needs a plan change, not a per-franchise override.',
        ]);
    }

    /**
     * @return int|null|false  false when the ADN does not resolve
     */
    private function resolveOperator(?string $adn): int|null|false
    {
        if ($adn === null || trim($adn) === '') {
            return null;
        }

        return Distributor::where('adn', trim($adn))->value('id') ?? false;
    }

    private function findOrFail(int $id): Franchise
    {
        $franchise = Franchise::with(['operator.user', 'areteCenter', 'approvedBy'])->find($id);

        if ($franchise === null) {
            throw new NotFoundHttpException;
        }

        return $franchise;
    }

    /**
     * @param  array<string, mixed>  $details
     */
    private function audit(Franchise $franchise, string $action, array $details = []): void
    {
        AuditLog::create([
            'actor_id' => Auth::id(),
            'action' => $action,
            'subject_type' => 'franchise',
            'subject_id' => $franchise->id,
            'details' => array_merge(['code' => $franchise->code], $details),
        ]);
    }
}
