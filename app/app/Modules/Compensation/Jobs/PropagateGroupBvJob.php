<?php

declare(strict_types=1);

namespace App\Modules\Compensation\Jobs;

use App\Modules\Compensation\Services\GroupBvAccumulatorService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

final class PropagateGroupBvJob implements ShouldQueue
{
    use InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $backoff = 60;

    public function __construct(
        private readonly int $orderId,
        private readonly int $distributorId,
        private readonly int $bvPaise,
        private readonly string $date,  // YYYY-MM-DD, captured at dispatch time
    ) {}

    public function handle(GroupBvAccumulatorService $accumulator): void
    {
        if ($this->bvPaise <= 0) {
            return;
        }

        // Idempotency: the unique bv_propagation_log row is inserted in the
        // SAME transaction as the group_bv_daily accumulation, so a retry after
        // a crash either replays nothing (marker committed with the data) or
        // everything (both rolled back). The accumulator is additive, so a
        // marker OUTSIDE the transaction (the old cache-based guard) could
        // double-count BV when the worker died between commit and marker.
        try {
            DB::transaction(function () use ($accumulator): void {
                DB::table('bv_propagation_log')->insert([
                    'order_id' => $this->orderId,
                    'distributor_id' => $this->distributorId,
                    'bv_paise' => $this->bvPaise,
                    'date' => $this->date,
                ]);

                $accumulator->propagate($this->distributorId, $this->bvPaise, Carbon::parse($this->date));
            });
        } catch (QueryException $e) {
            // Unique violation on the marker's order_id: already propagated —
            // nothing to do. Any other integrity error must still surface.
            if (str_starts_with((string) $e->getCode(), '23')
                && str_contains($e->getMessage(), 'bv_propagation_log')) {
                return;
            }

            throw $e;
        }
    }

    /**
     * All retries exhausted — this order's downline BV never reached
     * group_bv_daily and the daily cut-off will silently under-pay unless ops
     * replays it. Loud alert, never a quiet failed_jobs row.
     */
    public function failed(?Throwable $exception): void
    {
        Log::critical('gsb.bv_propagation.permanently_failed', [
            'order_id' => $this->orderId,
            'distributor_id' => $this->distributorId,
            'bv_paise' => $this->bvPaise,
            'date' => $this->date,
            'error' => $exception?->getMessage(),
        ]);
    }
}
