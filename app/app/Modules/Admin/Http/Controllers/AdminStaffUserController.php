<?php

declare(strict_types=1);

namespace App\Modules\Admin\Http\Controllers;

use App\Modules\Identity\Models\User;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\View\View;
use Spatie\Permission\Models\Role;

/**
 * Platform staff register — every user holding at least one admin role
 * (admin, admin-operations, admin-finance, admin-compliance, and any role
 * added later for KYC reviewers, order managers, …). Staff are deliberately
 * excluded from the Distributors register, which joins on the distributors
 * table; this page is the counterpart view of the team running the platform.
 *
 * Read-only for now: staff accounts are still provisioned via seeder or
 * tinker. A create/invite flow lands with the multi-operator phase.
 */
final class AdminStaffUserController extends Controller
{
    public function index(Request $request): View
    {
        $request->validate([
            'q' => ['nullable', 'string', 'max:64'],
            'role' => ['nullable', 'string', 'max:64'],
        ]);

        $query = User::query()
            ->whereHas('roles')
            ->with('roles');

        if ($search = $request->query('q')) {
            $query->where(function ($q) use ($search) {
                $q->where('email', 'like', "%{$search}%")
                    ->orWhere('full_name', 'like', "%{$search}%");
            });
        }

        if ($role = $request->query('role')) {
            $query->whereHas('roles', fn ($q) => $q->where('name', $role));
        }

        $staff = $query->orderBy('full_name')->paginate(20)->withQueryString();

        $roles = Role::query()->orderBy('name')->pluck('name');

        return view('admin.staff.index', compact('staff', 'roles'));
    }
}
