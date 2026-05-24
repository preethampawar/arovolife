<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * A distributor whose KYC was rejected (status='rejected') can sign in, but
 * is funnelled to /kyc/resubmit until they upload replacement documents. Any
 * attempt to load the dashboard, settings, etc. is bounced to the resubmit
 * page with a notice explaining why.
 *
 * The middleware lets the resubmit page itself, the logout endpoint, and a
 * tiny allowlist of profile / password routes pass through so the user can
 * always recover from the dead-end (sign out, reset password, contact us).
 */
final class RedirectRejectedToResubmit
{
    /**
     * Routes the middleware lets through even when status='rejected'. Keep
     * narrow — the whole point of this middleware is to keep rejected users
     * on the resubmit flow.
     *
     * @var array<int, string>
     */
    private const ALLOW = [
        'kyc/resubmit',
        'logout',
        'password',
        'forgot-password',
        'reset-password',
        'contact-us',
        'p/grievance',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $user = Auth::user();

        if ($user === null || $user->status !== 'rejected') {
            return $next($request);
        }

        $path = ltrim($request->path(), '/');
        foreach (self::ALLOW as $allowed) {
            if ($path === $allowed || str_starts_with($path, $allowed.'/')) {
                return $next($request);
            }
        }

        return redirect()->route('kyc.resubmit.show');
    }
}
