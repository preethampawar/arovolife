<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gate any route that should only be accessible to KYC-approved
 * distributors (Phase 2: cart, checkout, actual-price reveal). A user is
 * considered KYC-approved when `users.status === 'active'` AND they have
 * an associated distributor row.
 *
 * For Phase 1 this middleware is wired up but not attached to any
 * Commerce route — Commerce activates the middleware in Phase 2 by
 * applying it to its own route group.
 */
final class RequireKycApproval
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = Auth::user();
        if ($user === null) {
            return redirect()->route('login');
        }

        $distributor = $user->distributor;
        if ($distributor === null || $user->status !== 'active') {
            return redirect()->route('dashboard')
                ->with('status', 'This action is locked until your KYC is approved. Most reviews complete within 1–2 business days.');
        }

        return $next($request);
    }
}
