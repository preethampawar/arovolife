<?php

declare(strict_types=1);

use App\Modules\ActionCenter\Models\ActionCenterSnooze;
use App\Modules\ActionCenter\Providers\Platform\FailedJobsProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    disableTestForeignKeys();
    $this->provider = app(FailedJobsProvider::class);
});

/** @param array<string, mixed> $overrides */
function failedJobRow(array $overrides = []): int
{
    return (int) DB::table('failed_jobs')->insertGetId(array_merge([
        'uuid' => (string) str()->uuid(),
        'connection' => 'database',
        'queue' => 'default',
        'payload' => json_encode(['displayName' => 'App\\Jobs\\SendSomething']),
        'exception' => 'Exception: boom',
        'failed_at' => now(),
    ], $overrides));
}

it('has no fixing screen', function (): void {
    expect($this->provider->targetRoute())->toBeNull();
});

it('reports one row per failed job with its queue and class', function (): void {
    $id = failedJobRow(['queue' => 'compensation']);

    expect($this->provider->count())->toBe(1);

    $item = $this->provider->items()->first();
    expect($item->subjectId)->toBe($id)
        ->and($item->title)->toBe('App\\Jobs\\SendSomething')
        ->and($item->meta['queue'])->toBe('compensation')
        ->and($item->url)->toBeNull();
});

it('caps items to the given limit', function (): void {
    failedJobRow();
    failedJobRow();
    failedJobRow();

    expect($this->provider->count())->toBe(3);
    expect($this->provider->items(2))->toHaveCount(2);
});

it('excludes a snoozed failed job', function (): void {
    $id = failedJobRow();

    ActionCenterSnooze::create([
        'action_key' => 'platform.failed_jobs',
        'subject_type' => 'failed_job',
        'subject_id' => $id,
        'snoozed_until' => now()->addDays(2),
        'reason' => 'Known transient gateway timeout, retry scheduled.',
    ]);

    expect($this->provider->count())->toBe(0);
});
