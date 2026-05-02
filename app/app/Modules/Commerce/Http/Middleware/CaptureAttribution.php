<?php

declare(strict_types=1);

namespace App\Modules\Commerce\Http\Middleware;

use App\Modules\Commerce\Services\AttributionService;
use Closure;
use Illuminate\Http\Request;

/**
 * When a request lands on any storefront URL with ?ref=ADN, record
 * an attribution touch and drop the av_ref cookie.
 */
final class CaptureAttribution
{
    public function __construct(private readonly AttributionService $attribution) {}

    public function handle(Request $request, Closure $next)
    {
        $ref = $request->query('ref');
        if (is_string($ref) && $ref !== '') {
            $this->attribution->recordTouch($request, $ref);
        }

        return $next($request);
    }
}
