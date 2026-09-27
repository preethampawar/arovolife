<?php

declare(strict_types=1);

use App\Modules\Compensation\Models\GsbCutoffDeferral;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    disableTestForeignKeys();
});

it('holds one open deferral per distributor and day', function (): void {
    GsbCutoffDeferral::create(['distributor_id' => 7, 'cutoff_date' => '2026-09-26', 'cause' => GsbCutoffDeferral::CAUSE_EVALUATION_FAILED]);

    expect(GsbCutoffDeferral::open()->count())->toBe(1);
    expect(fn () => GsbCutoffDeferral::create(['distributor_id' => 7, 'cutoff_date' => '2026-09-26', 'cause' => GsbCutoffDeferral::CAUSE_EVALUATION_FAILED]))
        ->toThrow(QueryException::class);
});

it('leaves the open scope once resolved', function (): void {
    $row = GsbCutoffDeferral::create(['distributor_id' => 7, 'cutoff_date' => '2026-09-26', 'cause' => GsbCutoffDeferral::CAUSE_EVALUATION_FAILED]);
    $row->update(['resolved_at' => now(), 'resolution' => GsbCutoffDeferral::RESOLUTION_BACKFILLED]);

    expect(GsbCutoffDeferral::open()->count())->toBe(0);
});
