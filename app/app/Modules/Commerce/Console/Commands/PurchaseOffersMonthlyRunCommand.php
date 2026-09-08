<?php

declare(strict_types=1);

namespace App\Modules\Commerce\Console\Commands;

use App\Modules\Commerce\Services\PurchaseOfferService;
use App\Modules\Compensation\Support\OpenMonthGuard;
use App\Modules\Shared\Features\PurchaseOffersFeature;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Laravel\Pennant\Feature;

/**
 * Monthly evaluation of the two purchase offers.
 *
 * Flag-gated. A flag-off run reports **skipped**, never succeeded, so the
 * engine-runs dependency resolver can never treat an uncomputed period as
 * computed (the 2026-08-13 compliance finding).
 */
final class PurchaseOffersMonthlyRunCommand extends Command
{
    protected $signature = 'offers:monthly-run
        {--month= : Month to evaluate as YYYY-MM (default: last month)}
        {--in-flight : Testing only — grant for a month that has not closed; grants are idempotent per month, so a partial-month run is final}';

    protected $description = 'Grant the half-price product and redeem-point streak offers for a month';

    public function handle(PurchaseOfferService $offers): int
    {
        if (! Feature::for(null)->active(PurchaseOffersFeature::class)) {
            $this->warn('Purchase offers are off — skipped. Nothing evaluated and nothing granted.');

            return self::SUCCESS;
        }

        $month = $this->resolveMonth();

        if ($month === null) {
            $this->error('--month must be YYYY-MM.');

            return self::FAILURE;
        }

        // Grants are idempotent per distributor per month, so a run on partial
        // BV is never topped up by the 1st-of-month run — same class as the
        // pool-freezing engines (see OpenMonthGuard).
        if (! $this->option(OpenMonthGuard::OPTION) && ($refusal = OpenMonthGuard::refusal($month)) !== null) {
            $this->error($refusal);

            return self::FAILURE;
        }

        $this->info('Purchase offers for '.$month->format('F Y').'...');

        $summary = $offers->runForMonth($month);

        $this->info(sprintf(
            '%d half-price grant(s), %d points grant(s) totalling %s point(s), %d ranked distributor(s) skipped.',
            $summary['half_price_granted'],
            $summary['points_granted'],
            number_format($summary['points_awarded']),
            $summary['skipped_ranked']
        ));

        if ($summary['half_price_granted'] === 0) {
            $this->line('  No half-price grants. Check that a product was announced for this month at Admin → Offers.');
        }

        return self::SUCCESS;
    }

    private function resolveMonth(): ?Carbon
    {
        $raw = $this->option('month');

        if ($raw === null || $raw === '') {
            return Carbon::now('Asia/Kolkata')->subMonth()->startOfMonth();
        }

        if (preg_match('/^\d{4}-\d{2}$/', (string) $raw) !== 1) {
            return null;
        }

        return Carbon::createFromFormat('Y-m-d', $raw.'-01')?->startOfMonth();
    }
}
