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
use Illuminate\Support\Facades\Log;
use Spatie\Permission\Middleware\PermissionMiddleware;
use Spatie\Permission\Middleware\RoleMiddleware;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

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
        // Sensitive request input must never be flashed back into a debug
        // page or redirect. Laravel's default list covers only the password
        // trio; this platform also carries PAN, Aadhaar, OTPs and bank
        // details through its forms (hard rule #8 / DPDP 2023).
        $exceptions->dontFlash([
            'password',
            'password_confirmation',
            'current_password',
            'pan',
            'pan_number',
            'aadhaar',
            'aadhaar_number',
            'otp',
            'token',
            'bank_account',
            'bank_account_number',
            'ifsc',
        ]);

        // Slack alert for genuine server-side failures. Goes through the
        // configured `slack` LOG CHANNEL (config/logging.php) rather than a
        // raw webhook POST so the TapPiiScrubber redacts PAN/Aadhaar/OTP
        // before anything leaves the box. Client-side HTTP errors (403/404/
        // 419/429...) are noise and are explicitly excluded. This callback
        // runs IN ADDITION to normal log reporting — it never calls stop().
        $exceptions->report(function (Throwable $e): void {
            $webhookUrl = config('logging.channels.slack.url');

            if (! is_string($webhookUrl) || $webhookUrl === '') {
                return;
            }

            if ($e instanceof HttpExceptionInterface && $e->getStatusCode() < 500) {
                return;
            }

            $isServerHttpError = $e instanceof HttpExceptionInterface && $e->getStatusCode() >= 500;
            // getCode() is int on most exceptions but a SQLSTATE string on
            // PDOException — guard with is_int() so string codes never
            // accidentally satisfy the comparison.
            $hasServerErrorCode = is_int($e->getCode()) && $e->getCode() >= 500;

            if (! ($isServerHttpError || $hasServerErrorCode || $e instanceof Error)) {
                return;
            }

            try {
                Log::channel('slack')->critical(
                    'Unhandled exception: '.$e::class.': '.$e->getMessage(),
                    [
                        'exception' => $e::class,
                        'location' => $e->getFile().':'.$e->getLine(),
                        'method' => request()?->method(),
                        'path' => request()?->path(),
                    ],
                );
            } catch (Throwable $slackFailure) {
                // The alerting path must never take the request down with it.
                Log::debug('Slack exception alert could not be delivered', [
                    'error' => $slackFailure->getMessage(),
                ]);
            }
        });

        // Branded error pages for the statuses users actually meet. Laravel
        // converts TokenMismatchException to an HttpException(419) before
        // render callbacks run, so 419 lands here too. API clients get a
        // stable JSON shape with no exception message and no stack trace.
        $exceptions->render(function (HttpExceptionInterface $e, Request $request) {
            $status = $e->getStatusCode();

            $messages = [
                403 => 'Access denied.',
                404 => 'Page not found.',
                419 => 'Session expired — please refresh and try again.',
                429 => 'Too many requests — please wait a moment.',
                500 => 'Something went wrong — our team has been notified.',
            ];

            if (! array_key_exists($status, $messages)) {
                return null; // fall through to the framework default
            }

            if ($request->expectsJson()) {
                return response()->json([
                    'message' => $messages[$status],
                    'error' => $status,
                ], $status, $e->getHeaders());
            }

            return response()->view("errors.{$status}", [], $status, $e->getHeaders());
        });
    })
    ->withSchedule(function (Schedule $schedule): void {
        //
    })
    ->create();
