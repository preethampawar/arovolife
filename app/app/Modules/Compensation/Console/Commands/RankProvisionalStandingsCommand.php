<?php

declare(strict_types=1);

namespace App\Modules\Compensation\Console\Commands;

use App\Modules\Compensation\Services\EngineStatusService;
use App\Modules\Compensation\Services\RankProvisionalStandingService;
use App\Modules\Compensation\Support\EngineRunContext;
use App\Modules\Shared\Features\GenosSalesBonusFeature;
use App\Modules\Shared\Features\RankProgressSnapshotFeature;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Laravel\Pennant\Feature;

/**
 * Nightly rank progress snapshot (02:30 IST, for yesterday).
 *
 * Runs outside the nightly compensation chain on purpose: it moves no money
 * and feeds nothing that does, so a failure here must never fail or delay the
 * cut-off, the weekly payout or the monthly close.
 *
 * It measures only a SETTLED day: the day's cut-off must have succeeded (or
 * the GSB engine is off, so there is no cut-off to wait for). A refusal exits
 * with FAILURE so the engine health digest reports it — a snapshot that quietly
 * skips every night would leave the pages showing a stale "as of" forever.
 */
final class RankProvisionalStandingsCommand extends Command
{
    protected $signature = 'rank:provisional-standings
                            {--date= : The settled day to measure through (YYYY-MM-DD, defaults to yesterday)}';

    protected $description = 'Rebuild the rank progress snapshot (progress only — records no rank)';

    public function __construct(
        private readonly RankProvisionalStandingService $standings,
        private readonly EngineStatusService $engineStatus,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        if (! Feature::for(null)->active(RankProgressSnapshotFeature::class)) {
            $this->warn('Rank progress snapshot flag is OFF — nothing to do.');

            return self::SUCCESS;
        }

        $date = $this->option('date')
            ? Carbon::parse((string) $this->option('date'), 'Asia/Kolkata')->startOfDay()
            : Carbon::yesterday('Asia/Kolkata');

        if (! $date->lessThan(Carbon::today('Asia/Kolkata'))) {
            return $this->refuse("{$date->toDateString()} has not ended yet — only a settled day can be measured.");
        }

        if (Feature::for(null)->active(GenosSalesBonusFeature::class)
            && ! $this->engineStatus->isPeriodComputed('gsb.daily-cutoff', $date)) {
            return $this->refuse(
                "The GSB cut-off for {$date->toDateString()} has not succeeded, so the day is not settled. "
                .'It is retried at 02:30 tomorrow; run it now with '
                ."`php artisan rank:provisional-standings --date={$date->toDateString()}` once the cut-off is done "
                .'(a later successful snapshot supersedes this day).'
            );
        }

        $newest = $this->standings->newestAsOf();

        if ($newest !== null && $date->lessThan($newest)) {
            // Never roll the read-model back: a newer settled day is already shown.
            $reason = "A snapshot as of {$newest->toDateString()} already exists; {$date->toDateString()} is superseded.";
            $this->warn($reason);
            app(EngineRunContext::class)->noteSkipped($reason);

            return self::SUCCESS;
        }

        $rows = $this->standings->snapshot($date);

        $this->info("Rank progress snapshot for {$date->format('F Y')}, as of {$date->toDateString()}: {$rows} row(s).");

        return self::SUCCESS;
    }

    private function refuse(string $reason): int
    {
        $this->error($reason);
        app(EngineRunContext::class)->noteFailed($reason);

        return self::FAILURE;
    }
}
