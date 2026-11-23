<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Ends the live session of a partner erased while signed in. Sessions live in
 * Redis, so the erase cannot delete them; this closes one on its next request.
 * Acts only on closure_type 'erased', so no other account is affected.
 */
final class LogoutErasedUser
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->user()?->closure_type === 'erased') {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('login');
        }

        return $next($request);
    }
}
