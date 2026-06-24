<?php

declare(strict_types=1);

namespace App\Modules\Compensation\Jobs;

use App\Modules\Compensation\Services\GroupBvAccumulatorService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;

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
        $accumulator->propagate($this->distributorId, $this->bvPaise, Carbon::parse($this->date));
    }
}
