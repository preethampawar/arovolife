<?php

declare(strict_types=1);

namespace App\Modules\Admin\Http\Controllers;

use App\Modules\Compliance\Models\AuditLog;
use App\Modules\Identity\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Auth;

/**
 * Admin → Distributor impersonation.
 *
 * `start($userId)` requires admin role; saves the admin's own user id in the
 * session and swaps Auth to the target user. The "viewing as distributor"
 * banner (partials.impersonation-banner) shows on every authed page so the
 * admin can return to their own session at any time.
 *
 * `stop()` requires an active impersonator_id in session (the page will be
 * 404 otherwise); swaps Auth back to the admin and clears the session
 * marker.
 *
 * Every start/stop is audit-logged for the support trail.
 */
final class AdminImpersonationController extends Controller
{
    private const SESSION_KEY = 'impersonator_id';

    public function start(Request $request, int $userId): RedirectResponse
    {
        $admin = Auth::user();
        if ($admin === null || ! $admin->hasRole('admin')) {
            abort(403);
        }

        $target = User::query()->find($userId);
        if ($target === null) {
            return back()->withErrors(['impersonate' => 'User not found.']);
        }

        // Refuse to impersonate another admin — the admin role already has
        // the privileges needed; impersonating sideways adds no value and
        // muddies the audit log.
        if ($target->hasRole('admin')) {
            return back()->withErrors(['impersonate' => 'Cannot impersonate another admin.']);
        }

        $request->session()->put(self::SESSION_KEY, (int) $admin->id);

        AuditLog::create([
            'actor_id' => $admin->id,
            'action' => 'admin.impersonate.start',
            'subject_type' => 'user',
            'subject_id' => $target->id,
            'details' => ['impersonator_email' => $admin->email, 'target_email' => $target->email],
            'ip' => $request->ip(),
        ]);

        Auth::login($target);
        $request->session()->regenerate();
        // Re-attach the impersonator marker — session()->regenerate() rotates
        // the session id but copies its data; making the put explicit AFTER
        // regenerate is belt-and-braces in case any future Laravel version
        // changes that behaviour.
        $request->session()->put(self::SESSION_KEY, (int) $admin->id);

        return redirect()->route('dashboard')
            ->with('status', 'Now impersonating '.($target->full_name ?? $target->email).'. Use the banner at the top of the page to return to admin.');
    }

    public function stop(Request $request): RedirectResponse
    {
        $impersonatorId = $request->session()->pull(self::SESSION_KEY);
        if (! is_int($impersonatorId)) {
            abort(404);
        }

        $impersonator = User::query()->find($impersonatorId);
        if ($impersonator === null) {
            // Should never happen, but if the admin row was deleted while
            // impersonating, log out fully and bounce to login.
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('login')->with('status', 'Impersonation ended; please sign in again.');
        }

        $target = Auth::user();

        AuditLog::create([
            'actor_id' => $impersonator->id,
            'action' => 'admin.impersonate.stop',
            'subject_type' => 'user',
            'subject_id' => $target?->id,
            'details' => ['impersonator_email' => $impersonator->email, 'target_email' => $target?->email],
            'ip' => $request->ip(),
        ]);

        Auth::login($impersonator);
        $request->session()->regenerate();

        return redirect()->route('admin.dashboard')
            ->with('status', 'Returned to your admin session.');
    }
}
