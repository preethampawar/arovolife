<?php

declare(strict_types=1);

namespace App\Providers;

use App\Console\Commands\DeployCommand;
use App\Console\Commands\ResetAdnsCommand;
use App\Modules\Admin\Console\Commands\CreateStaffUserCommand;
use App\Modules\Commerce\Events\OrderStatusChanged;
use App\Modules\Compensation\Console\Commands\AdcBonusRunCommand;
use App\Modules\Compensation\Console\Commands\FortuneBonusEnrollCommand;
use App\Modules\Compensation\Console\Commands\FortuneBonusRunCommand;
use App\Modules\Compensation\Console\Commands\GbbMonthlyRunCommand;
use App\Modules\Compensation\Console\Commands\CompensationRecomputeAllCommand;
use App\Modules\Compensation\Console\Commands\GsbDailyCutoffCommand;
use App\Modules\Compensation\Console\Commands\GsbWeeklyPayoutCommand;
use App\Modules\Compensation\Console\Commands\MonthlyPayoutCommand;
use App\Modules\Compensation\Console\Commands\RankBonusRunCommand;
use App\Modules\Compensation\Console\Commands\RankCheckCommand;
use App\Modules\Compensation\Console\Commands\RepurchaseEvaluateCommand;
use App\Modules\Compensation\Events\IncomeReactivated;
use App\Modules\Compensation\Listeners\PropagateGroupBvOnOrderPaid;
use App\Modules\Compensation\Listeners\RecordEngineRun;
use App\Modules\Compensation\Listeners\ReleaseHeldGbbOnReactivation;
use App\Modules\Compensation\Listeners\ReleaseHeldGsbOnReactivation;
use App\Modules\Compensation\Listeners\ReverseGroupBvOnOrderReversal;
use App\Modules\Compensation\Support\EngineRunContext;
use App\Modules\Identity\Models\User;
use App\Modules\Returns\Events\OrderRefundApproved;
use Illuminate\Console\Events\CommandFinished;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Mail\Events\MessageSending;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Number;
use Illuminate\Support\ServiceProvider;
use RuntimeException;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // RecordEngineRun keeps the in-flight run ids between CommandStarting
        // and CommandFinished, so it must be the same instance for both events;
        // EngineRunContext carries the "who asked for this run" attribution
        // that EngineRunService binds around each Artisan::call.
        $this->app->scoped(EngineRunContext::class);
        $this->app->singleton(RecordEngineRun::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->assertS3IsConfigured();

        // All human-facing numbers (BV, ₹) use Indian digit grouping
        // (24,30,000 not 2,430,000) — every display surface must format via
        // IndianNumber::format / the @bv directive, never raw number_format()
        // and never Illuminate\Support\Number::format: CLDR dropped lakh
        // grouping from the Indian locales (ICU 78 renders en_IN western), so
        // this locale is kept only as a sane default for third-party callers.
        // CSV exports are the exception: they stay ungrouped for spreadsheets.
        Number::useLocale('en_IN');

        Event::listen(OrderStatusChanged::class, PropagateGroupBvOnOrderPaid::class);
        Event::listen(OrderStatusChanged::class, ReverseGroupBvOnOrderReversal::class);
        Event::listen(OrderRefundApproved::class, ReverseGroupBvOnOrderReversal::class);

        // Release GSB income held during a repurchase grace window once the
        // distributor completes their repurchase (KP 2026-06-28). Suspended
        // (post-grace) income stays forfeited — see the listener.
        Event::listen(IncomeReactivated::class, ReleaseHeldGsbOnReactivation::class);

        // Same for the Growth Booster's monthly held rows. Suspended
        // (post-grace) months stay forfeited — see the listener.
        Event::listen(IncomeReactivated::class, ReleaseHeldGbbOnReactivation::class);

        // Run log for the ten compensation engines. Listening to the console
        // events (rather than refactoring the commands) means cron runs,
        // developer CLI runs and admin-triggered runs are all recorded by one
        // writer. Module listeners are not auto-discovered, hence the explicit
        // wiring — and the method-array form because one class handles both.
        Event::listen(CommandStarting::class, [RecordEngineRun::class, 'starting']);
        Event::listen(CommandFinished::class, [RecordEngineRun::class, 'finished']);

        // Super staff: `developer` and `admin` bypass every permission check
        // (R-17 separation of duties). The specialised roles (admin-operations
        // / admin-finance / admin-compliance) carry only their scoped
        // permissions, so e.g. admin-finance can't freeze and admin-compliance
        // can't record payments — while super staff keep doing everything.
        // Developer-exclusive surfaces are gated by `role:developer`
        // middleware, which this bypass does NOT affect.
        Gate::before(fn (User $user) => $user->isSuperStaff() ? true : null);

        if ($this->app->runningInConsole()) {
            // Module commands live outside app/Console/Commands, so Laravel's
            // auto-discovery never sees them — every one must be listed here.
            // Schedule::command() in routes/console.php only formats the
            // signature into an artisan string; it does NOT verify the command
            // is registered, so an omission here surfaces as a cron-time
            // "There are no commands defined" and nothing else.
            // ScheduledCommandsAreRegisteredTest guards the whole schedule.
            $this->commands([
                DeployCommand::class,
                ResetAdnsCommand::class,
                CreateStaffUserCommand::class,
                GsbDailyCutoffCommand::class,
                GsbWeeklyPayoutCommand::class,
                GbbMonthlyRunCommand::class,
                RankBonusRunCommand::class,
                RankCheckCommand::class,
                FortuneBonusRunCommand::class,
                FortuneBonusEnrollCommand::class,
                AdcBonusRunCommand::class,
                MonthlyPayoutCommand::class,
                RepurchaseEvaluateCommand::class,
                // TESTING ONLY — removed with the recompute scaffold at sign-off.
                CompensationRecomputeAllCommand::class,
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
