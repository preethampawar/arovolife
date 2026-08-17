<?php

declare(strict_types=1);

namespace App\Modules\Shared\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Security response headers (T-6.1 finding M-2).
 *
 * The platform had none. Each of these closes a class of attack that the
 * application code cannot close on its own, because they are instructions to
 * the browser rather than to us.
 *
 * The one that matters most here is **X-Frame-Options**. Without it any site
 * can iframe the admin console and overlay it, and an operator who thinks they
 * are clicking "Accept cookies" is clicking "Approve payout batch". Nothing in
 * PHP can prevent that.
 *
 * The CSP is deliberately not `default-src 'self'` alone. Blade renders inline
 * event handlers and Alpine-style attributes throughout, and a policy that
 * breaks the console the first time someone opens it gets switched off within
 * the hour — so `'unsafe-inline'` is granted for scripts and styles and the
 * value is in what remains blocked: no `object-src`, no `base-uri` hijack, no
 * form posting to a third-party origin, and no framing. Tightening it means
 * removing inline handlers first, which is a refactor and not a header change.
 */
final class SecurityHeaders
{
    /**
     * `frame-ancestors 'none'` is the modern form of X-Frame-Options and the
     * one browsers honour when both are present; the older header stays for
     * anything that predates CSP support.
     */
    private const CSP = "default-src 'self'; "
        ."script-src 'self' 'unsafe-inline'; "
        ."style-src 'self' 'unsafe-inline'; "
        ."img-src 'self' data: blob: https:; "
        ."font-src 'self' data:; "
        ."connect-src 'self'; "
        ."media-src 'self'; "
        ."object-src 'none'; "
        ."base-uri 'self'; "
        ."form-action 'self'; "
        ."frame-ancestors 'none'";

    public function handle(Request $request, Closure $next): Response
    {
        /** @var Response $response */
        $response = $next($request);

        $headers = [
            'Content-Security-Policy' => self::CSP,
            'X-Frame-Options' => 'DENY',
            // Stops a browser second-guessing a declared content type, which
            // is how an uploaded file served as text/plain becomes script.
            'X-Content-Type-Options' => 'nosniff',
            // A distributor's ADN and order numbers travel in URLs. Send the
            // origin only, so an outbound link does not hand the full path to
            // whoever is on the other end.
            'Referrer-Policy' => 'strict-origin-when-cross-origin',
            // Nothing here needs a camera, a microphone or a location.
            'Permissions-Policy' => 'camera=(), microphone=(), geolocation=(), payment=(), usb=()',
        ];

        // HSTS only over TLS. Sending it on a plain-HTTP response is at best
        // ignored and at worst pins a development host to a scheme it cannot
        // serve, which is a self-inflicted outage that survives a browser
        // restart.
        if ($request->secure()) {
            $headers['Strict-Transport-Security'] = 'max-age=31536000; includeSubDomains';
        }

        foreach ($headers as $header => $value) {
            // Never overwrite a header a controller set deliberately — the
            // invoice download and the KYC document stream both set their own.
            if (! $response->headers->has($header)) {
                $response->headers->set($header, $value);
            }
        }

        return $response;
    }
}
