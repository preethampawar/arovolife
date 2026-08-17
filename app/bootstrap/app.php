<?php

use App\Modules\Commerce\Http\Middleware\CaptureAttribution;
use App\Modules\Identity\Http\Middleware\EnsureRegistrationProgress;
use App\Modules\Identity\Http\Middleware\RedirectRejectedToResubmit;
use App\Modules\Identity\Http\Middleware\RequireKycApproval;
use App\Modules\Shared\Http\Middleware\SecurityHeaders;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Spatie\Permission\Middleware\PermissionMiddleware;
use Spatie\Permission\Middleware\RoleMiddleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Applied to every web response. See the class docblock for why the
        // CSP still allows inline script — the value is in what it blocks
        // (framing, object-src, base-uri, cross-origin form posts).
        $middleware->web(append: [
            SecurityHeaders::class,
        ]);

        // Trust the Cloudways load balancer's forwarding headers, so
        // `$request->secure()` is true behind TLS termination. Without this
        // HSTS is never sent and `secure` session cookies are never set,
        // because the app believes every request arrived over plain HTTP.
        $middleware->trustProxies(at: '*', headers: Request::HEADER_X_FORWARDED_FOR
            | Request::HEADER_X_FORWARDED_HOST
            | Request::HEADER_X_FORWARDED_PORT
            | Request::HEADER_X_FORWARDED_PROTO);

        $middleware->alias([
            'wizard.progress' => EnsureRegistrationProgress::class,
            'kyc.approved' => RequireKycApproval::class,
            'kyc.rejected.resubmit' => RedirectRejectedToResubmit::class,
            'role' => RoleMiddleware::class,
            'permission' => PermissionMiddleware::class,
            'capture.attribution' => CaptureAttribution::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })
    ->withSchedule(function (Schedule $schedule): void {
        //
    })
    ->create();
