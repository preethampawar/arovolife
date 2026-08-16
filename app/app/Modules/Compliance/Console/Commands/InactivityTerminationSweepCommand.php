<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Console\Commands;

use App\Modules\Compliance\Services\ComplianceTerminationSettings;
use App\Modules\Compliance\Services\InactivityTerminationService;
use App\Modules\Identity\Models\Distributor;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Daily enforcement of the agreement's §21 dormancy rule.
 *
 * Three passes, in this order:
 *
 *  1. **Clear** notices on anyone who has sold something since the notice was
 *     issued. This runs first on purpose — a distributor who sold yesterday
 *     must not be terminated today by a sweep that had not yet noticed.
 *  2. **Terminate** accounts whose notice has expired with no sale.
 *  3. **Notice** anyone newly past twelve months.
 *
 * Off by default (`termination.inactivity_sweep_enabled`). The right posture
 * to launch in is observing: the first cohort of dormant accounts should be
 * looked at by a human before any of them are closed automatically, because
 * the first run is also the only run that can catch a data problem — an
 * attribution bug that hid a distributor's sales would otherwise terminate
 * them silently, and there is no path back from `terminated`.
 *
 * `--dry-run` reports what would happen regardless of the switch.
 */
final class InactivityTerminationSweepCommand extends Command
{
    protected $signature = 'distributors:inactivity-sweep
        {--dry-run : Report what would change without writing}
        {--limit=500 : Maximum accounts to act on in one run}';

    protected $description = 'Issue §21 dormancy notices and terminate accounts whose notice has expired';

    public function handle(
        InactivityTerminationService $inactivity,
        ComplianceTerminationSettings $settings,
    ): int {
        $now = Carbon::now();
        $dryRun = (bool) $this->option('dry-run') || ! $settings->sweepEnabled();
        $limit = max(1, (int) $this->option('limit'));

        if (! $settings->sweepEnabled() && ! $this->option('dry-run')) {
            $this->warn('Automatic dormancy termination is OFF (termination.inactivity_sweep_enabled). Reporting only.');
        }

        $cleared = $this->clearRevivedNotices($inactivity, $dryRun);
        $terminated = $this->terminateExpiredNotices($inactivity, $now, $dryRun, $limit);
        $noticed = $this->issueNewNotices($inactivity, $settings, $now, $dryRun, $limit);

        $this->info(sprintf(
            '%s%d notice(s) cleared, %d account(s) terminated, %d notice(s) issued.',
            $dryRun ? '[report only] ' : '',
            $cleared,
            $terminated,
            $noticed
        ));

        return self::SUCCESS;
    }

    /**
     * A sale inside the notice window withdraws the notice entirely.
     */
    private function clearRevivedNotices(InactivityTerminationService $inactivity, bool $dryRun): int
    {
        $count = 0;

        Distributor::query()
            ->whereNotNull('inactivity_notice_at')
            ->orderBy('id')
            ->chunkById(200, function ($distributors) use ($inactivity, $dryRun, &$count): void {
                foreach ($distributors as $distributor) {
                    $assessment = $inactivity->assess($distributor);

                    if ($assessment->isDormant) {
                        continue;
                    }

                    $count++;

                    if ($dryRun) {
                        $this->line("  would clear the notice on {$distributor->adn} — sold since it was issued");

                        continue;
                    }

                    $inactivity->clearNotice($distributor, 'A sale was recorded inside the notice period.');
                }
            });

        return $count;
    }

    private function terminateExpiredNotices(
        InactivityTerminationService $inactivity,
        Carbon $now,
        bool $dryRun,
        int $limit,
    ): int {
        $count = 0;

        $due = Distributor::query()
            ->whereNotNull('inactivity_notice_expires_at')
            ->where('inactivity_notice_expires_at', '<=', $now)
            ->whereNull('terminated_at')
            ->orderBy('inactivity_notice_expires_at')
            ->limit($limit)
            ->get();

        foreach ($due as $distributor) {
            // Re-assess rather than trusting the notice: a sale landing between
            // the clearing pass and this one must still save the account.
            if (! $inactivity->assess($distributor, $now)->isDormant) {
                continue;
            }

            if ($dryRun) {
                $this->line("  would terminate {$distributor->adn} — notice expired {$distributor->inactivity_notice_expires_at?->toDateString()}");
                $count++;

                continue;
            }

            if ($inactivity->terminate($distributor, $now)) {
                $count++;
            }
        }

        return $count;
    }

    private function issueNewNotices(
        InactivityTerminationService $inactivity,
        ComplianceTerminationSettings $settings,
        Carbon $now,
        bool $dryRun,
        int $limit,
    ): int {
        $count = 0;
        $cutoff = $now->copy()->subMonths($settings->inactivityMonths());

        Distributor::query()
            ->whereNull('inactivity_notice_at')
            ->whereNull('terminated_at')
            ->where('status', 'active')
            // Cheap pre-filter: nobody who joined inside the window can be
            // dormant, whatever their sales look like.
            ->where('effective_date', '<=', $cutoff)
            ->orderBy('id')
            ->chunkById(200, function ($distributors) use ($inactivity, $now, $dryRun, $limit, &$count): void {
                foreach ($distributors as $distributor) {
                    if ($count >= $limit) {
                        return;
                    }

                    if (! $inactivity->assess($distributor, $now)->isDormant) {
                        continue;
                    }

                    $count++;

                    if ($dryRun) {
                        $this->line("  would issue a §21 notice to {$distributor->adn}");

                        continue;
                    }

                    $inactivity->issueNotice($distributor, $now);
                }
            });

        return $count;
    }
}
