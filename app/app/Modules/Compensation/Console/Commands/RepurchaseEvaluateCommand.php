<?php

declare(strict_types=1);

namespace App\Modules\Compensation\Console\Commands;

use App\Modules\Compensation\Services\RepurchaseCycleService;
use App\Modules\Identity\Models\Distributor;
use App\Modules\Shared\Features\RepurchaseEngineFeature;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
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

        $distributors = $this->withPossibleCycle($query->pluck('id'));
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

    /**
     * Narrow the candidates to distributors an evaluation can change.
     *
     * evaluate() opens a cycle only once the distributor has crossed the
     * repurchase BV anchor, which it establishes by scanning
     * bv_ledger_entries — an expensive per-distributor scan that can only ever
     * return null for someone with no BV rows at all. Everyone who already has
     * a cycle is kept regardless (their cycle still has to roll and expire).
     *
     * On the reference dataset this drops 124 of 288 candidates from every
     * daily run, which a full replay makes 46 times over.
     *
     * @param  Collection<int, int>  $distributorIds
     * @return Collection<int, int>
     */
    private function withPossibleCycle(Collection $distributorIds): Collection
    {
        if ($distributorIds->isEmpty()) {
            return $distributorIds;
        }

        $ids = $distributorIds->all();

        $withBv = DB::table('bv_ledger_entries')
            ->whereIn('distributor_id', $ids)
            ->distinct()
            ->pluck('distributor_id');

        $withCycle = DB::table('repurchase_cycles')
            ->whereIn('distributor_id', $ids)
            ->distinct()
            ->pluck('distributor_id');

        $keep = $withBv->merge($withCycle)->map(fn ($id): int => (int) $id)->flip();

        return $distributorIds->filter(fn ($id): bool => $keep->has((int) $id))->values();
    }
}
