<?php

declare(strict_types=1);

namespace App\Modules\Admin\Http\Controllers;

use App\Modules\Admin\Services\StaffAccountService;
use App\Modules\Identity\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use RuntimeException;

/**
 * Platform staff register + management — every user holding at least one
 * staff role. Staff are deliberately excluded from the Distributors register,
 * which joins on the distributors table; this page is the counterpart view of
 * the team running the platform.
 *
 * Visibility rule: the role list a viewer can see, filter by, and assign is
 * always User::visibleRoleNames($viewer). Accounts holding a role outside
 * that list are filtered out of every query and are 404 on the detail routes,
 * so a viewer cannot learn that a more privileged role exists — not from the
 * list, the filter dropdown, the assign form, or a hand-crafted request.
 */
final class AdminStaffUserController extends Controller
{
    public function __construct(private readonly StaffAccountService $staff) {}

    public function index(Request $request): View
    {
        $request->validate([
            'q' => ['nullable', 'string', 'max:64'],
            'role' => ['nullable', 'string', 'max:64'],
        ]);

        $viewer = $request->user();
        $visibleRoles = User::visibleRoleNames($viewer);

        $query = $this->visibleStaffQuery($viewer);

        if ($search = $request->query('q')) {
            $query->where(function ($q) use ($search) {
                $q->where('email', 'like', "%{$search}%")
                    ->orWhere('full_name', 'like', "%{$search}%");
            });
        }

        // Only a role the viewer may see can be filtered on — an unknown or
        // hidden value falls through to "no filter" rather than confirming it.
        $role = $request->query('role');
        if ($role !== null && in_array($role, $visibleRoles, true)) {
            $query->whereHas('roles', fn ($q) => $q->where('name', $role));
        }

        $staff = $query->orderBy('full_name')->paginate(20)->withQueryString();

        return view('admin.staff.index', [
            'staff' => $staff,
            'roles' => collect($visibleRoles),
            'currentUserId' => $viewer?->id,
        ]);
    }

    public function create(Request $request): View
    {
        return view('admin.staff.create', [
            'roles' => collect(User::visibleRoleNames($request->user())),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $visibleRoles = User::visibleRoleNames($request->user());

        $data = $request->validate([
            'full_name' => ['required', 'string', 'max:150'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')],
            'phone_e164' => ['required', 'string', 'regex:/^\+91[6-9]\d{9}$/', Rule::unique('users', 'phone_e164')],
            'password' => ['required', 'string', 'min:12', 'max:255', 'confirmed'],
            // Rule::in against the VISIBLE list — a crafted POST naming a
            // hidden role is rejected as an invalid value, exactly as a
            // typo'd role would be.
            'roles' => ['required', 'array', 'min:1'],
            'roles.*' => ['string', Rule::in($visibleRoles)],
        ], [
            'phone_e164.regex' => 'Enter a valid Indian mobile in +91XXXXXXXXXX format.',
        ]);

        try {
            $user = $this->staff->create(
                email: $data['email'],
                fullName: $data['full_name'],
                phoneE164: $data['phone_e164'],
                plainPassword: $data['password'],
                roles: array_values($data['roles']),
                actorId: $request->user()?->id,
            );
        } catch (RuntimeException $e) {
            return back()->withInput($request->except(['password', 'password_confirmation']))
                ->withErrors(['email' => $e->getMessage()]);
        }

        return redirect()->route('admin.staff.index')
            ->with('status', "Staff account created: {$user->email}.");
    }

    public function edit(Request $request, int $id): View
    {
        $staff = $this->findVisibleOrFail($request, $id);

        return view('admin.staff.edit', [
            'staff' => $staff,
            'roles' => collect(User::visibleRoleNames($request->user())),
            'assigned' => $staff->roles->pluck('name')->all(),
            'isSelf' => $request->user()?->id === $staff->id,
        ]);
    }

    public function updateRoles(Request $request, int $id): RedirectResponse
    {
        $staff = $this->findVisibleOrFail($request, $id);
        $visibleRoles = User::visibleRoleNames($request->user());

        $data = $request->validate([
            'roles' => ['required', 'array', 'min:1'],
            'roles.*' => ['string', Rule::in($visibleRoles)],
        ]);

        if ($request->user()?->id === $staff->id) {
            return back()->withErrors(['roles' => 'You cannot change your own roles.']);
        }

        try {
            $this->staff->syncRoles($staff, array_values($data['roles']), $request->user()?->id);
        } catch (RuntimeException $e) {
            return back()->withErrors(['roles' => $e->getMessage()]);
        }

        return redirect()->route('admin.staff.index')
            ->with('status', "Roles updated for {$staff->email}.");
    }

    public function updateStatus(Request $request, int $id): RedirectResponse
    {
        $staff = $this->findVisibleOrFail($request, $id);

        $data = $request->validate([
            'status' => ['required', Rule::in(['active', 'frozen'])],
        ]);

        if ($request->user()?->id === $staff->id) {
            return back()->withErrors(['status' => 'You cannot change your own account status.']);
        }

        try {
            $this->staff->setStatus($staff, $data['status'], $request->user()?->id);
        } catch (RuntimeException $e) {
            return back()->withErrors(['status' => $e->getMessage()]);
        }

        $verb = $data['status'] === 'frozen' ? 'deactivated' : 'reactivated';

        return redirect()->route('admin.staff.index')
            ->with('status', "Staff account {$verb}: {$staff->email}.");
    }

    /**
     * Staff whose roles are all visible to this viewer. An account holding a
     * hidden role simply doesn't exist as far as the query is concerned.
     *
     * @return Builder<User>
     */
    private function visibleStaffQuery(?User $viewer): Builder
    {
        $query = User::query()->whereHas('roles')->with('roles');

        if ($viewer === null || ! $viewer->hasRole('developer')) {
            $query->whereDoesntHave('roles', fn ($q) => $q->where('name', 'developer'));
        }

        return $query;
    }

    private function findVisibleOrFail(Request $request, int $id): User
    {
        $staff = $this->visibleStaffQuery($request->user())->whereKey($id)->first();

        abort_if($staff === null, 404);

        return $staff;
    }
}
