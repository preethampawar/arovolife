<?php

declare(strict_types=1);

namespace App\Modules\Compensation\Console\Commands;

use App\Modules\Compensation\Services\RepurchaseCycleService;
use App\Modules\Identity\Models\Distributor;
use App\Modules\Shared\Features\RepurchaseEngineFeature;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Laravel\Pennant\Feature;

/**
 * Daily evaluation of every active distributor's repurchase cycle: opens/rolls
 * cycles, refreshes completion from self-purchase BV, and transitions
 * active → grace → suspended → completed (emitting the domain events). The GSB
 * cut-off reads the resulting status; this command keeps it current.
 */
final class RepurchaseEvaluateCommand extends Command
{
    protected $signature = 'repurchase:evaluate
                            {--date= : Override the as-of date (YYYY-MM-DD, default: today)}
                            {--distributor= : Evaluate a single distributor ID only}';

    protected $description = 'Evaluate each distributor\'s monthly repurchase cycle and update income eligibility';

    public function __construct(
        private readonly RepurchaseCycleService $cycles,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        if (! Feature::for(null)->active(RepurchaseEngineFeature::class)) {
            $this->info('Repurchase engine is disabled (feature flag off) — nothing to run.');

            return self::SUCCESS;
        }

        if ($this->option('date') !== null) {
            $rawDate = (string) $this->option('date');
            if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $rawDate)) {
                $this->error("--date must be in YYYY-MM-DD format, got: {$rawDate}");

                return self::FAILURE;
            }
            $asOf = Carbon::createFromFormat('Y-m-d', $rawDate)->startOfDay();
        } else {
            $asOf = Carbon::today();
        }

        $query = Distributor::query()
            ->whereNotNull('adn')
            ->where('status', 'active');

        if ($this->option('distributor')) {
            $query->where('id', (int) $this->option('distributor'));
        }

        $this->info("Repurchase evaluation — as of {$asOf->toDateString()}");

        $distributors = $query->pluck('id');
        $evaluated = 0;
        $failed = 0;

        foreach ($distributors as $distributorId) {
            try {
                $this->cycles->evaluate((int) $distributorId, $asOf);
                $evaluated++;
            } catch (\Throwable $e) {
                $failed++;
                Log::error('repurchase.evaluate.exception', [
                    'distributor_id' => $distributorId,
                    'error' => $e->getMessage(),
                    'exception' => get_class($e),
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                ]);
            }
        }

        $this->info("Done — evaluated: {$evaluated}, failed: {$failed}");

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
