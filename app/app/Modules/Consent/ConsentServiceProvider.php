<?php

declare(strict_types=1);

namespace App\Modules\Consent;

use App\Modules\Consent\Console\Commands\BackfillAgreementsCommand;
use App\Modules\Consent\Console\Commands\NotifyUnreadPlanChangesCommand;
use App\Modules\Consent\Http\Controllers\PlanChangeNoticeController;
use App\Modules\Consent\Services\DTOs\PendingPlanChange;
use App\Modules\Consent\Services\PlanChangeNotices;
use App\Modules\Identity\Models\User;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;

final class ConsentServiceProvider extends ServiceProvider
{
    /**
     * Pages the plan-change pop-up never covers: the ones a distributor may
     * need in order to decide — or to leave — without agreeing first (cooling-off,
     * returns, grievances, contact, KYC resubmit, the policy pages, their own
     * consent record).
     */
    private const PLAN_NOTICE_EXEMPT_PATHS = [
        'cooling-off*',
        'contact-us*',
        'grievance*',
        'my/grievances*',
        'help*',
        'kyc/resubmit*',
        'orders/*/return*',
        'p/*',
        'profile/consents*',
        'profile/withdraw-consent*',
        'plan-changes/*',
    ];

    public function register(): void
    {
        // One instance per request, so the published page is read and hashed
        // once however many times the layout asks.
        $this->app->scoped(PlanChangeNotices::class);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/Database/Migrations');

        View::composer('partials.plan-change-notice', function (\Illuminate\View\View $view): void {
            $view->with('planChange', $this->pendingPlanChange());
        });

        if ($this->app->runningInConsole()) {
            // Module commands sit outside app/Console/Commands, where nothing
            // auto-discovers them — an unregistered one fails only at the
            // moment somebody runs it.
            $this->commands([
                BackfillAgreementsCommand::class,
                NotifyUnreadPlanChangesCommand::class,
            ]);

            // Daily at 10:00 IST, after the 09:00 cooling-off reminders and
            // 09:30 dormancy sweep so the three never contend for the mailer.
            $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
                $schedule->command('plan:notify-unread')
                    ->dailyAt('10:00')
                    ->timezone('Asia/Kolkata')
                    ->onOneServer()
                    ->withoutOverlapping();
            });
        }
    }

    /**
     * The change the signed-in distributor should be shown on this page, or
     * null. Never shown to staff wearing a distributor's session, on the
     * exempt pages, or again this session once they chose "Remind me later".
     */
    private function pendingPlanChange(): ?PendingPlanChange
    {
        $request = request();
        $user = $request->user();

        if (! $user instanceof User
            || $request->session()->has('impersonator_id')
            || $request->is(...self::PLAN_NOTICE_EXEMPT_PATHS)) {
            return null;
        }

        $notices = $this->app->make(PlanChangeNotices::class);
        $pending = $notices->pendingFor($user);

        if ($pending === null
            || $request->session()->get(PlanChangeNoticeController::LATER_SESSION_KEY) === $pending->hashHex) {
            return null;
        }

        if ($request->session()->get('plan_change_notice.shown') !== $pending->hashHex && $user->distributor !== null) {
            $notices->markShown($user->distributor, $pending);
            $request->session()->put('plan_change_notice.shown', $pending->hashHex);
        }

        return $pending;
    }
}
