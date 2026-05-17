<?php

use App\Modules\Commerce\Http\Middleware\CaptureAttribution;
use App\Modules\Identity\Http\Middleware\EnsureRegistrationProgress;
use App\Modules\Identity\Http\Middleware\RequireKycApproval;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Spatie\Permission\Middleware\PermissionMiddleware;
use Spatie\Permission\Middleware\RoleMiddleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'wizard.progress' => EnsureRegistrationProgress::class,
            'kyc.approved' => RequireKycApproval::class,
            'role' => RoleMiddleware::class,
            'permission' => PermissionMiddleware::class,
            'capture.attribution' => CaptureAttribution::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })
    ->withSchedule(function (Schedule $schedule): void {
        $schedule->command('drafts:purge')->daily();
    })
    ->create();
