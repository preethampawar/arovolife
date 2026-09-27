<?php

declare(strict_types=1);

use App\Modules\Compensation\Models\GsbCutoffDeferral;
use App\Modules\Compensation\Services\EngineHealthService;
use App\Modules\Compliance\Models\AuditLog;
use App\Modules\Identity\Models\Distributor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    disableTestForeignKeys();
    Carbon::setTestNow('2026-09-08 08:00:00');
});

afterEach(function (): void {
    Carbon::setTestNow();
});

function owedDay(Distributor $distributor, array $attributes = []): GsbCutoffDeferral
{
    return GsbCutoffDeferral::create([
        'distributor_id' => $distributor->id,
        'cutoff_date' => '2026-09-01',
        'cause' => GsbCutoffDeferral::CAUSE_EVALUATION_FAILED,
        'reserved_slab' => 1,
        'reserved_gsb_paise' => 200_000,
        'reserved_msb_points' => 21,
        ...$attributes,
    ]);
}

it('refuses to write off an owed day without a reason', function (): void {
    $d = Distributor::factory()->create(['status' => 'inactive', 'adn' => '100000090']);
    $row = owedDay($d);

    expect(Artisan::call('gsb:write-off-deferral', ['distributor' => '100000090', 'date' => '2026-09-01']))->toBe(1)
        ->and(Artisan::output())->toContain('--reason is required');
    expect($row->fresh()->resolved_at)->toBeNull()
        ->and(AuditLog::where('action', 'gsb.cutoff.deferral_written_off')->exists())->toBeFalse();
});

it('refuses a row that is already resolved', function (): void {
    $d = Distributor::factory()->create(['status' => 'inactive']);
    owedDay($d, ['resolved_at' => now()->subDay(), 'resolution' => GsbCutoffDeferral::RESOLUTION_BACKFILLED]);

    expect(Artisan::call('gsb:write-off-deferral', ['distributor' => (string) $d->id, 'date' => '2026-09-01', '--reason' => 'Terminated distributor']))->toBe(1)
        ->and(Artisan::output())->toContain('already resolved');
    expect(GsbCutoffDeferral::sole()->resolution)->toBe(GsbCutoffDeferral::RESOLUTION_BACKFILLED);
});

it('writes an open owed day off by decision, audits it, and drops it from the digest', function (): void {
    $d = Distributor::factory()->create(['status' => 'inactive', 'adn' => '100000091']);
    $row = owedDay($d);

    expect(app(EngineHealthService::class)->report(Carbon::now())->deferredCutoffs)->not->toBe([]);

    expect(Artisan::call('gsb:write-off-deferral', ['distributor' => '100000091', 'date' => '2026-09-01', '--reason' => 'Terminated distributor']))->toBe(0);

    $row->refresh();
    expect($row->resolution)->toBe(GsbCutoffDeferral::RESOLUTION_WRITTEN_OFF)
        ->and($row->resolved_at)->not->toBeNull()
        ->and($row->gsb_cutoff_result_id)->toBeNull();

    expect(AuditLog::where('action', 'gsb.cutoff.deferral_written_off')->sole()->details)->toMatchArray([
        'distributor_id' => $d->id,
        'adn' => '100000091',
        'cutoff_date' => '2026-09-01',
        'reason' => 'Terminated distributor',
        'reserved_slab' => 1,
        'reserved_gsb_paise' => 200_000,
        'reserved_msb_points' => 21,
    ]);

    expect(app(EngineHealthService::class)->report(Carbon::now())->deferredCutoffs)->toBe([]);
});
