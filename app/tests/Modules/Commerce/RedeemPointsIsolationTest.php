<?php

declare(strict_types=1);

/**
 * The redeem-points double-spend guard rests on an assumption nothing states
 * (T-6.1 finding L-5).
 *
 * `RedeemPointsService::redeem()` calls `lockForUpdate()` over a SUM. That
 * locks the rows it reads; it does not by itself stop a concurrent INSERT
 * from changing the sum. What stops that is MySQL's default REPEATABLE READ,
 * whose next-key locks cover the gap in `idx_redeem_points_dist_time`. Under
 * READ COMMITTED there are no gap locks and points become double-spendable.
 *
 * These tests pin the two things that assumption needs. Neither simulates
 * concurrency — a single-threaded suite cannot — but both fail the moment the
 * ground the guard stands on shifts, which is the part nobody would otherwise
 * notice until balances stopped adding up.
 *
 * RPI-01: no isolation override is configured
 * RPI-02: the index the gap lock needs still exists
 * RPI-03: the balance check refuses an overdraw
 */

use App\Modules\Commerce\Models\RedeemPointEntry;
use App\Modules\Commerce\Services\RedeemPointsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    disableTestForeignKeys();
});

it('RPI-01: no isolation level override is configured', function () {
    // Setting this to READ COMMITTED — a common performance tweak — silently
    // reopens the double-spend. If someone needs to change it, they have to
    // change this test, and this comment is what they will read.
    foreach (array_keys(config('database.connections')) as $connection) {
        expect(config("database.connections.{$connection}.isolation_level"))
            ->toBeNull("connection {$connection} sets an isolation level");
    }
});

it('RPI-02: the index the gap lock needs still exists', function () {
    // The lock is only as good as the index it ranges over. Dropping this
    // index for being "unused" would leave the guard reading a table scan.
    expect(Schema::hasTable('redeem_point_entries'))->toBeTrue();

    $indexes = collect(Schema::getIndexes('redeem_point_entries'))
        ->pluck('columns')
        ->map(fn (array $columns): string => implode(',', $columns));

    expect($indexes)->toContain('distributor_id,created_at');
});

it('RPI-03: the balance check refuses an overdraw', function () {
    $service = app(RedeemPointsService::class);
    $service->accrue(distributorId: 1, points: 100, referenceType: 'test', referenceId: 1, memo: 'seed');

    expect(fn () => $service->redeem(1, 101, 1, 'too many'))
        ->toThrow(RuntimeException::class, 'the balance is 100');

    expect(RedeemPointEntry::where('distributor_id', 1)->sum('points'))->toBe(100);
});
