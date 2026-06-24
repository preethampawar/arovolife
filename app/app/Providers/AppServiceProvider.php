<?php

declare(strict_types=1);

namespace App\Providers;

use App\Console\Commands\DeployCommand;
use App\Console\Commands\ResetAdnsCommand;
use App\Modules\Commerce\Events\OrderStatusChanged;
use App\Modules\Compensation\Listeners\PropagateGroupBvOnOrderPaid;
use App\Modules\Identity\Models\User;
use Illuminate\Mail\Events\MessageSending;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use RuntimeException;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->assertS3IsConfigured();

        Event::listen(OrderStatusChanged::class, PropagateGroupBvOnOrderPaid::class);

        // Super-admin: the `admin` role bypasses every permission check (R-17
        // separation of duties). The specialised roles (admin-operations /
        // admin-finance / admin-compliance) carry only their scoped
        // permissions, so e.g. admin-finance can't freeze and admin-compliance
        // can't record payments — while a full `admin` keeps doing everything.
        Gate::before(fn (User $user) => $user->hasRole('admin') ? true : null);

        if ($this->app->runningInConsole()) {
            $this->commands([
                DeployCommand::class,
                ResetAdnsCommand::class,
            ]);
        }

        // Staging-wide BCC. Whatever address (or comma-separated list) is in
        // MAIL_GLOBAL_BCC silently receives a copy of every outgoing email,
        // useful while wiring up SMTP / templates on a non-prod environment.
        // Leave the env var unset on production.
        $globalBcc = (string) config('mail.global_bcc', '');
        if ($globalBcc !== '') {
            $addresses = array_values(array_filter(array_map('trim', explode(',', $globalBcc))));
            if ($addresses !== []) {
                Event::listen(MessageSending::class, function (MessageSending $event) use ($addresses): void {
                    foreach ($addresses as $address) {
                        $event->message->addBcc($address);
                    }
                });
            }
        }
    }

    /**
     * KYC documents are PII; falling back to local disk silently is not an
     * option. Refuse to boot the app unless the S3 credentials the `kyc`
     * disk needs are populated. Reads via config() so this works after
     * `php artisan config:cache`.
     */
    private function assertS3IsConfigured(): void
    {
        // Tolerate the missing-keys case for unit tests / local artisan
        // bootstrap commands that aren't actually going to touch S3.
        if (app()->runningUnitTests()) {
            return;
        }

        $required = [
            'AWS_ACCESS_KEY_ID' => config('filesystems.disks.kyc.key'),
            'AWS_SECRET_ACCESS_KEY' => config('filesystems.disks.kyc.secret'),
            'AWS_DEFAULT_REGION' => config('filesystems.disks.kyc.region'),
            'AWS_BUCKET' => config('filesystems.disks.kyc.bucket'),
        ];

        $missing = array_keys(array_filter($required, fn ($v) => ! is_string($v) || $v === ''));

        if ($missing === []) {
            return;
        }

        throw new RuntimeException(
            'S3 is not configured. The following env vars must be set: '
            .implode(', ', $missing)
            .'. KYC documents are PII and falling back to local disk is not allowed.'
        );
    }
}
