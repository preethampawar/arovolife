<?php

declare(strict_types=1);

namespace App\Providers;

use App\Console\Commands\DeployCommand;
use App\Console\Commands\ResetAdnsCommand;
use App\Modules\Admin\Console\Commands\CreateStaffUserCommand;
use App\Modules\Commerce\Console\Commands\PurchaseOffersMonthlyRunCommand;
use App\Modules\Commerce\Events\OrderStatusChanged;
use App\Modules\Compensation\Console\Commands\AdcBonusRunCommand;
use App\Modules\Compensation\Console\Commands\AdcPurgeRejectedDocumentsCommand;
use App\Modules\Compensation\Console\Commands\AutoRetryFailedPayoutsCommand;
use App\Modules\Compensation\Console\Commands\FortuneBonusEnrollCommand;
use App\Modules\Compensation\Console\Commands\FortuneBonusRunCommand;
use App\Modules\Compensation\Console\Commands\FortuneStagingE2ESeedCommand;
use App\Modules\Compensation\Console\Commands\GbbMonthlyRunCommand;
use App\Modules\Compensation\Console\Commands\GsbDailyCutoffCommand;
use App\Modules\Compensation\Console\Commands\GsbWeeklyPayoutCommand;
use App\Modules\Compensation\Console\Commands\MonthlyPayoutCommand;
use App\Modules\Compensation\Console\Commands\RankBonusRunCommand;
use App\Modules\Compensation\Console\Commands\RankCheckCommand;
use App\Modules\Compensation\Console\Commands\RepurchaseEvaluateCommand;
use App\Modules\Compensation\Console\Commands\RepurchaseMonthlySnapshotCommand;
use App\Modules\Compensation\Events\IncomeReactivated;
use App\Modules\Compensation\Listeners\PropagateGroupBvOnOrderPaid;
use App\Modules\Compensation\Listeners\RecordEngineRun;
use App\Modules\Compensation\Listeners\ReleaseHeldGbbOnReactivation;
use App\Modules\Compensation\Listeners\ReleaseHeldGsbOnReactivation;
use App\Modules\Compensation\Listeners\ReverseGroupBvOnOrderReversal;
use App\Modules\Compensation\Support\EngineRunContext;
use App\Modules\Identity\Models\User;
use App\Modules\Payments\Console\Commands\ExpireUnpaidOrdersCommand;
use App\Modules\Payments\Console\Commands\PaymentsReconcileCommand;
use App\Modules\Payments\Console\Commands\PaymentsRedactEventsCommand;
use App\Modules\Returns\Events\OrderRefundApproved;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Console\Events\CommandFinished;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Http\Request;
use Illuminate\Mail\Events\MessageSending;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
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
        $this->warnOnMissingOpsEnv();

        // All human-facing numbers (BV, ₹) use Indian digit grouping
        // (24,30,000 not 2,430,000) — every display surface must format via
        // IndianNumber::format / the @bv directive, never raw number_format()
        // and never Illuminate\Support\Number::format: CLDR dropped lakh
        // grouping from the Indian locales (ICU 78 renders en_IN western), so
        // this locale is kept only as a sane default for third-party callers.
        // CSV exports are the exception: they stay ungrouped for spreadsheets.
        Number::useLocale('en_IN');

        // Named rate limiter for the registration wizard (steps 1 & 2). Limits
        // are DB-driven so operations can adjust them without a deploy. Values
        // are cached for 5 minutes so a change takes effect quickly without a
        // DB read on every request. Defaults are 60 requests per 60 minutes per
        // IP — enough for a group-onboarding session, far below any useful flood.
        RateLimiter::for('registration', function (Request $request): Limit {
            /** @var array{requests: int, window: int} $limits */
            $limits = Cache::remember('settings.registration_throttle', 300, function (): array {
                $rows = DB::table('settings')
                    ->whereIn('key', [
                        'security.registration_throttle_requests',
                        'security.registration_throttle_window_minutes',
                    ])
                    ->pluck('value', 'key');

                return [
                    'requests' => max(1, (int) ($rows['security.registration_throttle_requests'] ?? 60)),
                    'window' => max(1, (int) ($rows['security.registration_throttle_window_minutes'] ?? 60)),
                ];
            });

            return Limit::perMinutes($limits['window'], $limits['requests'])->by($request->ip());
        });

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

        // Pulse's own authorisation. The `role:developer` middleware in
        // config/pulse.php is what actually keeps admins out — Gate::before
        // above answers true for them before this ever runs — but Pulse checks
        // this ability internally too, and its packaged default is
        // "allow if local", which would be wide open on staging.
        Gate::define('viewPulse', fn (User $user) => $user->hasRole('developer'));

        // @developer gates the engine/plan explainer banners on the
        // compensation console and income pages: plan internals are
        // developer-only, hidden from admins and distributors alike.
        Blade::if('developer', fn (): bool => auth()->user()?->hasRole('developer') === true);

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
                FortuneStagingE2ESeedCommand::class,
                AdcBonusRunCommand::class,
                AdcPurgeRejectedDocumentsCommand::class,
                PurchaseOffersMonthlyRunCommand::class,
                MonthlyPayoutCommand::class,
                AutoRetryFailedPayoutsCommand::class,
                RepurchaseEvaluateCommand::class,
                RepurchaseMonthlySnapshotCommand::class,
                PaymentsReconcileCommand::class,
                ExpireUnpaidOrdersCommand::class,
                PaymentsRedactEventsCommand::class,
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

    /**
     * EH-L9/LOG-6: surface deploy-time misconfiguration in the very first log
     * entries instead of as a mystery three days later. Warn-only by design —
     * the app must still boot so the warning has somewhere to land.
     */
    private function warnOnMissingOpsEnv(): void
    {
        if ($this->app->runningUnitTests()) {
            return;
        }

        if ((string) config('logging.channels.slack.url', '') === '') {
            Log::warning('Ops env check: LOG_SLACK_WEBHOOK_URL is empty — critical alerts will not reach Slack.');
        }

        if ((string) config('app.key', '') === '') {
            Log::warning('Ops env check: APP_KEY is empty — encryption, sessions and signed URLs will fail.');
        }

        $queue = (string) config('queue.default', '');
        if ($queue !== 'database') {
            Log::warning('Ops env check: QUEUE_CONNECTION must be "database" (ADR-0011) — currently misconfigured.', [
                'queue_connection' => $queue,
            ]);
        }
    }
}
