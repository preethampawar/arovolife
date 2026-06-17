<?php

declare(strict_types=1);

namespace App\Modules\Admin\Http\Controllers;

use App\Modules\Admin\Events\DistributorDeactivated;
use App\Modules\Admin\Events\DistributorFrozen;
use App\Modules\Admin\Events\DistributorReactivated;
use App\Modules\Admin\Events\DistributorTerminated;
use App\Modules\Admin\Events\DistributorUnfrozen;
use App\Modules\Compliance\Models\AuditLog;
use App\Modules\Identity\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

final class AdminDistributorController extends Controller
{
    public function index(Request $request): View
    {
        $request->validate([
            'q' => ['nullable', 'string', 'max:64'],
            'status' => ['nullable', 'in:pending,active,frozen,terminated,rejected'],
            'state' => ['nullable', 'regex:/^[A-Z]{2}$/'],
            'cooling_off' => ['nullable', 'in:active,expiring'],
        ]);

        $query = DB::table('distributors')
            ->join('users', 'distributors.user_id', '=', 'users.id')
            ->select(
                'distributors.id', 'distributors.adn', 'distributors.depth',
                'distributors.placement_side', 'distributors.effective_date',
                'distributors.cooling_off_end_at', 'distributors.state',
                'distributors.sponsor_id', 'distributors.placement_parent_id',
                'users.email', 'users.full_name', 'users.status', 'users.phone_e164'
            );

        if ($search = $request->query('q')) {
            $query->where(function ($q) use ($search) {
                $q->where('distributors.adn', 'like', "%{$search}%")
                    ->orWhere('users.email', 'like', "%{$search}%")
                    ->orWhere('users.full_name', 'like', "%{$search}%");
            });
        }

        if ($status = $request->query('status')) {
            $query->where('users.status', $status);
        }

        if ($state = $request->query('state')) {
            $query->where('distributors.state', $state);
        }

        // Click-through filters from the dashboard "Cooling-Off Active" and
        // "Expiring (7 days)" stat tiles. Both predicates mirror exactly the
        // SQL used in AdminDashboardController::index so the row counts
        // shown on the dashboard match the rows shown here.
        if ($coolingOff = $request->query('cooling_off')) {
            if ($coolingOff === 'active') {
                $query->where('distributors.cooling_off_end_at', '>', now());
            } elseif ($coolingOff === 'expiring') {
                $query->whereBetween('distributors.cooling_off_end_at', [now(), now()->addDays(7)]);
            }
        }

        $distributors = $query->orderByDesc('distributors.id')->paginate(20)->withQueryString();

        $statusCounts = DB::table('users')
            ->select('status', DB::raw('count(*) as cnt'))
            ->groupBy('status')
            ->pluck('cnt', 'status');

        return view('admin.distributors.index', compact('distributors', 'statusCounts'));
    }

    public function show(int $id): View
    {
        $distributor = DB::table('distributors')
            ->join('users', 'distributors.user_id', '=', 'users.id')
            ->select('distributors.*', 'distributors.status as distributor_status',
                'users.email', 'users.full_name', 'users.status', 'users.closure_type',
                'users.phone_e164', 'users.date_of_birth', 'users.created_at as user_created_at')
            ->where('distributors.id', $id)
            ->firstOrFail();

        $sponsor = $distributor->sponsor_id
            ? DB::table('distributors')
                ->join('users', 'distributors.user_id', '=', 'users.id')
                ->select('distributors.adn', 'users.full_name', 'users.email')
                ->where('distributors.id', $distributor->sponsor_id)
                ->first()
            : null;

        $placementParent = $distributor->placement_parent_id && $distributor->placement_parent_id !== $id
            ? DB::table('distributors')
                ->join('users', 'distributors.user_id', '=', 'users.id')
                ->select('distributors.adn', 'users.full_name')
                ->where('distributors.id', $distributor->placement_parent_id)
                ->first()
            : null;

        $consents = DB::table('consents')
            ->where('distributor_id', $id)
            ->orderBy('document_type')
            ->get();

        $auditLogs = DB::table('audit_log')
            ->leftJoin('users', 'audit_log.actor_id', '=', 'users.id')
            ->select('audit_log.*', 'users.email as actor_email')
            ->where(function ($q) use ($id, $distributor) {
                $q->where(function ($q2) use ($id) {
                    $q2->where('audit_log.subject_type', 'distributor')
                        ->where('audit_log.subject_id', $id);
                })->orWhere(function ($q2) use ($distributor) {
                    $q2->where('audit_log.subject_type', 'user')
                        ->where('audit_log.subject_id', $distributor->user_id);
                });
            })
            ->orderByDesc('audit_log.created_at')
            ->limit(20)
            ->get();

        $downlineCount = DB::table('genealogy_closure')
            ->where('ancestor_id', $id)
            ->where('depth', '>=', 1)
            ->count();

        $leftChild = DB::table('distributors')
            ->join('users', 'distributors.user_id', '=', 'users.id')
            ->select('distributors.id', 'distributors.adn', 'users.full_name')
            ->where('distributors.placement_parent_id', $id)
            ->where('distributors.placement_side', 'L')
            ->where('distributors.id', '!=', $id)
            ->first();

        $rightChild = DB::table('distributors')
            ->join('users', 'distributors.user_id', '=', 'users.id')
            ->select('distributors.id', 'distributors.adn', 'users.full_name')
            ->where('distributors.placement_parent_id', $id)
            ->where('distributors.placement_side', 'R')
            ->where('distributors.id', '!=', $id)
            ->first();

        return view('admin.distributors.show', compact(
            'distributor', 'sponsor', 'placementParent', 'consents',
            'auditLogs', 'downlineCount', 'leftChild', 'rightChild'
        ));
    }

    public function freeze(Request $request, int $id): RedirectResponse
    {
        $request->validate(['reason' => ['required', 'string', 'max:500']]);

        $distributor = DB::table('distributors')->where('id', $id)->firstOrFail();
        $user = User::findOrFail($distributor->user_id);

        if (! in_array($user->status, ['active', 'pending'])) {
            return back()->withErrors(['reason' => 'User cannot be blocked in current status: '.$user->status]);
        }

        $user->update(['status' => 'frozen']);

        AuditLog::create([
            'actor_id' => auth()->id(),
            'action' => 'admin.distributor.frozen',
            'subject_type' => 'distributor',
            'subject_id' => $id,
            'details' => ['reason' => $request->reason, 'previous_status' => $user->getOriginal('status') ?? 'active'],
            'ip' => $request->ip(),
        ]);

        DistributorFrozen::dispatch($id, (int) auth()->id(), (string) $request->reason, Carbon::now());

        return redirect()->route('admin.distributors.show', $id)
            ->with('status', 'Distributor blocked successfully.');
    }

    public function unfreeze(Request $request, int $id): RedirectResponse
    {
        $distributor = DB::table('distributors')->where('id', $id)->firstOrFail();
        $user = User::findOrFail($distributor->user_id);

        if ($user->status !== 'frozen') {
            return back()->withErrors(['error' => 'User is not currently blocked.']);
        }

        $user->update(['status' => 'active']);

        AuditLog::create([
            'actor_id' => auth()->id(),
            'action' => 'admin.distributor.unfrozen',
            'subject_type' => 'distributor',
            'subject_id' => $id,
            'details' => ['previous_status' => 'frozen'],
            'ip' => $request->ip(),
        ]);

        DistributorUnfrozen::dispatch($id, (int) auth()->id(), Carbon::now());

        return redirect()->route('admin.distributors.show', $id)
            ->with('status', 'Distributor account unblocked.');
    }

    public function terminate(Request $request, int $id): RedirectResponse
    {
        $request->validate(['reason' => ['required', 'string', 'max:500']]);

        $distributor = DB::table('distributors')->where('id', $id)->firstOrFail();
        $user = User::findOrFail($distributor->user_id);

        if ($user->status === 'terminated') {
            return back()->withErrors(['reason' => 'User is already terminated.']);
        }

        $previousStatus = (string) $user->status;
        $now = Carbon::now();
        $reason = (string) $request->reason;
        $actorId = (int) auth()->id();

        DB::transaction(function () use ($user, $id, $reason, $previousStatus, $request, $actorId): void {
            $user->update([
                'status' => 'terminated',
                'closure_type' => 'admin_termination',
            ]);

            // Keep the distributor-record flag coherent with the account's
            // terminal state — no "Distributor: Active" pill post-termination.
            DB::table('distributors')->where('id', $id)->update([
                'status' => 'inactive',
                'updated_at' => now(),
            ]);

            AuditLog::create([
                'actor_id' => $actorId,
                'action' => 'admin.distributor.terminated',
                'subject_type' => 'distributor',
                'subject_id' => $id,
                'details' => ['reason' => $reason, 'previous_status' => $previousStatus],
                'ip' => $request->ip(),
            ]);
        });

        // Mirror TerminateDistributor / the KYC terminate path: this
        // distributor-show terminate previously sent NO email. Dispatching the
        // event drives SendDistributorTerminatedMail → AccountTerminatedNotification.
        DistributorTerminated::dispatch($id, $actorId, $reason, $now);

        return redirect()->route('admin.distributors.show', $id)
            ->with('status', 'Distributor terminated.');
    }

    /**
     * Register of Direct Sellers — DSR 2021 Rule 3(g) record-keeping export.
     * The CSV is regulator-facing, so the column allow-list is explicit and
     * deliberately excludes anything that could leak full PII or secrets:
     * `pan_hash`, `aadhaar_ref`, `bank_account_enc`, encrypted MFA seed, etc.
     * Only the regulator-shareable last-4 derivatives appear.
     */
    public function export(): Response
    {
        $distributors = DB::table('distributors')
            ->join('users', 'distributors.user_id', '=', 'users.id')
            ->leftJoin('distributors AS sponsors', 'distributors.sponsor_id', '=', 'sponsors.id')
            ->leftJoin('distributors AS spouses', 'distributors.spouse_distributor_id', '=', 'spouses.id')
            ->leftJoinSub(
                DB::table('kyc_documents')
                    ->select('distributor_id', DB::raw('MAX(verified_at) AS kyc_verified_at'))
                    ->whereNotNull('verified_at')
                    ->groupBy('distributor_id'),
                'kyc_v',
                'kyc_v.distributor_id',
                '=',
                'distributors.id',
            )
            ->select(
                'distributors.adn', 'users.full_name', 'users.email', 'users.phone_e164',
                'distributors.state', 'distributors.pan_last4', 'distributors.aadhaar_last4',
                'distributors.bank_ifsc', 'distributors.depth', 'distributors.placement_side',
                'distributors.effective_date', 'distributors.cooling_off_end_at',
                'users.status', 'users.date_of_birth',
                'sponsors.adn AS sponsor_adn',
                'kyc_v.kyc_verified_at',
                'distributors.is_primary_couple',
                'distributors.spouse_distributor_id',
                'spouses.adn AS spouse_adn',
            )
            ->orderBy('distributors.id')
            ->get();

        AuditLog::create([
            'actor_id' => auth()->id(),
            'action' => 'admin.register.exported',
            'subject_type' => 'system',
            'subject_id' => null,
            'details' => ['row_count' => $distributors->count()],
            'ip' => request()->ip(),
        ]);

        $csv = "ADN,Full Name,Email,Phone,State,PAN Last4,Aadhaar Last4,Bank IFSC,Depth,Side,Effective Date,Cooling Off End,Status,DOB,Sponsor ADN,KYC Verified,Couple Role,Spouse ADN\n";
        foreach ($distributors as $d) {
            // Self-rooted distributors (the genealogy seed) report no sponsor —
            // surface that as blank rather than echoing their own ADN.
            $sponsorAdn = $d->sponsor_adn === $d->adn ? '' : ($d->sponsor_adn ?? '');

            // Couple role label: "Primary" if this row is the primary half
            // of a couple, "Secondary" if it's the spouse, blank for solo.
            $coupleRole = $d->spouse_distributor_id === null
                ? ''
                : ($d->is_primary_couple ? 'Primary' : 'Secondary');

            $csv .= implode(',', array_map(
                fn ($v) => '"'.str_replace('"', '""', (string) ($v ?? '')).'"',
                [
                    $d->adn, $d->full_name, $d->email, $d->phone_e164,
                    $d->state, $d->pan_last4, $d->aadhaar_last4 ?? '', $d->bank_ifsc,
                    $d->depth, $d->placement_side ?? '', $d->effective_date,
                    $d->cooling_off_end_at, $d->status, $d->date_of_birth ?? '',
                    $sponsorAdn, $d->kyc_verified_at ?? '',
                    $coupleRole, $d->spouse_adn ?? '',
                ]
            ))."\n";
        }

        return response($csv, 200, [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => 'attachment; filename="register-of-direct-sellers-'.now()->format('Y-m-d').'.csv"',
        ]);
    }

    /**
     * Flip the distributor row's own status enum to 'active'. This is the
     * distributor-record-level flag (`distributors.status`), distinct from
     * the broader user-account lifecycle (`users.status`) which freeze /
     * unfreeze / terminate operate on. Used to put a reserved-block or
     * non-trading node out of circulation without touching the user account.
     */
    public function activate(Request $request, int $id): RedirectResponse
    {
        return $this->toggleDistributorStatus($request, $id, 'active');
    }

    /**
     * Flip the distributor row's own status enum to 'inactive'. See {@see activate()}.
     */
    public function deactivate(Request $request, int $id): RedirectResponse
    {
        return $this->toggleDistributorStatus($request, $id, 'inactive');
    }

    private function toggleDistributorStatus(Request $request, int $id, string $status): RedirectResponse
    {
        $row = DB::table('distributors')->where('id', $id)->first(['id', 'status', 'adn']);
        if ($row === null) {
            abort(404);
        }

        $previous = (string) $row->status;
        if ($previous === $status) {
            return back()->with('status', sprintf('Distributor %s is already %s.', $row->adn, $status));
        }

        DB::table('distributors')->where('id', $id)->update([
            'status' => $status,
            'updated_at' => now(),
        ]);

        AuditLog::create([
            'actor_id' => auth()->id(),
            'action' => 'distributor.status_changed',
            'subject_type' => 'distributor',
            'subject_id' => $id,
            'details' => ['from' => $previous, 'to' => $status, 'adn' => $row->adn],
            'ip' => $request->ip(),
        ]);

        // deactivate/reactivate funnel through this single toggle, so the
        // event we fire depends on the resulting distributor-record status.
        $actorId = (int) auth()->id();
        if ($status === 'active') {
            DistributorReactivated::dispatch($id, $actorId, Carbon::now());
        } elseif ($status === 'inactive') {
            DistributorDeactivated::dispatch($id, $actorId, Carbon::now());
        }

        return back()->with('status', sprintf('Distributor %s set to %s.', $row->adn, $status));
    }
}
