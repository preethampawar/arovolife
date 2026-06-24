<?php

declare(strict_types=1);

namespace App\Modules\Compensation\Jobs;

use App\Modules\Compensation\Services\GroupBvAccumulatorService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

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

        // Idempotency guard: if this order's BV has already been propagated
        // (e.g. a previous job attempt succeeded before a worker crash), skip
        // to prevent double-accumulation in group_bv_daily.
        $idempotencyKey = "bv_propagated:order:{$this->orderId}";
        if (Cache::has($idempotencyKey)) {
            return;
        }

        $accumulator->propagate($this->distributorId, $this->bvPaise, Carbon::parse($this->date));

        // Mark as done. 48 h TTL is ample — retries beyond that window are
        // vanishingly unlikely and a fresh run after TTL expiry is safe because
        // GroupBvAccumulatorService::upsertAccumulator() adds, not replaces.
        Cache::put($idempotencyKey, true, now()->addHours(48));
    }
}
