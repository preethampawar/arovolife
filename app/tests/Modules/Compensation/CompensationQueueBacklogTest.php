<?php

declare(strict_types=1);

use App\Modules\Compensation\Support\CompensationQueueBacklog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

function queuedJob(string $queue): void
{
    DB::table('jobs')->insert([
        'queue' => $queue,
        'payload' => '{}',
        'attempts' => 0,
        'reserved_at' => null,
        'available_at' => time(),
        'created_at' => time(),
    ]);
}

it('counts only the compensation queue', function (): void {
    queuedJob('compensation');
    queuedJob('compensation');
    queuedJob('default');

    expect((new CompensationQueueBacklog)->depth())->toBe(2);
});

it('returns at once when the queue is empty', function (): void {
    $ticks = 0;

    expect((new CompensationQueueBacklog(maxWaitSeconds: 60, pollSeconds: 1))->waitUntilDrained(function () use (&$ticks): void {
        $ticks++;
    }))->toBe(0)
        ->and($ticks)->toBe(0);
});

it('gives up with the depth left once the wait is exhausted', function (): void {
    queuedJob('compensation');

    expect((new CompensationQueueBacklog(maxWaitSeconds: 0, pollSeconds: 0))->waitUntilDrained())->toBe(1);
});
