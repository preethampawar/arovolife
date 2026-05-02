<?php

declare(strict_types=1);

namespace App\Modules\Admin\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class EnsureAdminRole
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->user() || ! $request->user()->hasRole('admin')) {
            abort(403, 'Admin access required.');
        }

        return $next($request);
    }
}
