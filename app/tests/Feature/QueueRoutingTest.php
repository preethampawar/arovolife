<?php

declare(strict_types=1);

use App\Modules\Compensation\Jobs\PropagateGroupBvJob;
use App\Modules\Compensation\Jobs\RecomputeAllJob;
use App\Modules\Compensation\Jobs\ReverseGroupBvJob;
use App\Modules\Compensation\Jobs\RunEngineChainJob;
use App\Modules\Shared\Notifications\OtpCodeNotification;
use Illuminate\Support\Facades\Queue;

/**
 * Workers are split by queue name (docs/runbooks/cloudways-deployment.md §1.9):
 * one process drains `otp,default` — every distributor-facing mail — and a
 * separate single process drains `compensation`.
 *
 * The split only works if the heavy jobs actually carry the queue name. An
 * untagged compensation job silently falls back to `default`, where a
 * multi-minute engine chain parks in front of every OTP and order email on the
 * platform, and nothing fails — it just gets slow in a way nobody can see.
 */
it('routes every compensation job to the compensation queue', function () {
    Queue::fake();

    dispatch(new PropagateGroupBvJob(1, 2, 100, '2026-08-29'));
    dispatch(new ReverseGroupBvJob(1));
    dispatch(new RunEngineChainJob('gsb', '2026-08', null, 'chain-1'));
    dispatch(new RecomputeAllJob);

    Queue::assertPushedOn('compensation', PropagateGroupBvJob::class);
    Queue::assertPushedOn('compensation', ReverseGroupBvJob::class);
    Queue::assertPushedOn('compensation', RunEngineChainJob::class);
    Queue::assertPushedOn('compensation', RecomputeAllJob::class);
});

it('keeps OTP delivery on its own queue', function () {
    expect((new OtpCodeNotification('123456', 'update your contact details'))->queue)
        ->toBe('otp');
});

/**
 * Guards the guard: the test above names four jobs explicitly, so a fifth one
 * added to the module would sail past it. This fails when any job class in
 * Compensation/Jobs does not pin itself to the compensation queue.
 */
it('leaves no compensation job untagged', function () {
    $files = glob(app_path('Modules/Compensation/Jobs/*.php')) ?: [];

    expect($files)->not->toBeEmpty();

    // Jobs that legitimately run on 'default': they never move money and a
    // dropped job here causes a status delay, not a missed credit.
    $defaultQueueJobs = [
        'ProcessRazorpayPayoutWebhookJob.php', // reconciliation only — see job docblock
    ];

    $untagged = array_values(array_filter(
        $files,
        fn (string $f) => ! in_array(basename($f), $defaultQueueJobs, true)
            && ! str_contains((string) file_get_contents($f), "onQueue('compensation')")
    ));

    expect($untagged)->toBe([], 'Compensation jobs missing onQueue(\'compensation\'): '.implode(', ', array_map('basename', $untagged)));
});

it('keeps the redis-named connection on the database driver for Cloudways', function () {
    // Cloudways' Supervisord Jobs panel launches every worker as
    // `queue:work redis` -- the Connection Driver field is read-only. That
    // argument is a name resolved through config/queue.php, so the name
    // stays and the driver beneath it is the database. Anyone "fixing" the
    // alias back to the real Redis driver silently moves the money-path
    // queue onto a shared, eviction-prone Redis. This is the tripwire.
    expect(config('queue.connections.redis'))
        ->toBe(config('queue.connections.database'))
        ->and(config('queue.connections.redis.driver'))->toBe('database');
});
