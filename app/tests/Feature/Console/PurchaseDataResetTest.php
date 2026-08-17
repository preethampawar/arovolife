<?php

declare(strict_types=1);

use App\Console\Actions\PurchaseDataResetAction;
use App\Modules\Commerce\Models\BvLedgerEntry;
use App\Modules\Compensation\Services\WalletService;
use App\Modules\Identity\Models\Distributor;
use Database\Seeders\GsbSlabsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    disableTestForeignKeys();
});

function seedPurchaseData(): Distributor
{
    $dist = Distributor::factory()->create(['gsb_frozen_at' => now()]);

    BvLedgerEntry::create([
        'distributor_id' => $dist->id,
        'order_id' => 900_001,
        'bv_paise' => 300_000,
        'type' => 'accrual',
        'effective_at' => now(),
    ]);

    app(WalletService::class)->credit($dist->id, 100_000, 'gsb_credit', walletRef(), 'test_reference');

    DB::table('group_bv_daily')->insert([
        'distributor_id' => $dist->id,
        'date' => now()->toDateString(),
        'left_bv_paise' => 1_500_000,
        'right_bv_paise' => 1_500_000,
    ]);

    DB::table('jobs')->insert([
        'queue' => 'default',
        'payload' => '{"displayName":"App\\\\Modules\\\\Compensation\\\\Jobs\\\\PropagateGroupBvJob"}',
        'attempts' => 0,
        'available_at' => now()->getTimestamp(),
        'created_at' => now()->getTimestamp(),
    ]);

    return $dist;
}

it('wipes purchase-derived tables but preserves distributors, plan config and settings', function () {
    $this->seed(GsbSlabsSeeder::class);
    $slabCount = DB::table('gsb_slabs')->count();
    $dist = seedPurchaseData();

    $this->artisan('platform:reset-purchases', ['--force' => true])
        ->assertExitCode(0);

    foreach (PurchaseDataResetAction::wipeTables() as $table) {
        if (DB::getSchemaBuilder()->hasTable($table)) {
            expect(DB::table($table)->count())->toBe(0, "table {$table} should be empty");
        }
    }

    // The frozen daily pool economics must go too — they are immutable and the
    // freeze is idempotent, so a surviving row would price every later re-run
    // of that date against the pre-reset company BV.
    expect(PurchaseDataResetAction::wipeTables())->toContain('gsb_daily_pools')
        ->and(PurchaseDataResetAction::wipeTables())->toContain('msb_daily_pools');

    // Preserved: the distributor row itself, plan configuration, settings.
    expect(Distributor::query()->whereKey($dist->id)->exists())->toBeTrue()
        ->and(DB::table('gsb_slabs')->count())->toBe($slabCount)
        ->and($dist->fresh()->gsb_frozen_at)->toBeNull();

    // Stale BV-propagation jobs are removed from the queue.
    expect(DB::table('jobs')->where('payload', 'like', '%PropagateGroupBvJob%')->count())->toBe(0);

    // The reset itself is audit-logged.
    expect(DB::table('audit_log')->where('action', 'platform.purchase_reset')->count())->toBe(1);
});

it('is idempotent — a second run succeeds on an already-clean database', function () {
    seedPurchaseData();

    $this->artisan('platform:reset-purchases', ['--force' => true])->assertExitCode(0);
    $this->artisan('platform:reset-purchases', ['--force' => true])->assertExitCode(0);

    expect(DB::table('audit_log')->where('action', 'platform.purchase_reset')->count())->toBe(2);
});

it('aborts without --force when confirmation is declined', function () {
    $dist = seedPurchaseData();

    $this->artisan('platform:reset-purchases')
        ->expectsConfirmation('Proceed with purchase-data reset?', 'no')
        ->assertExitCode(1);

    expect(DB::table('wallet_ledger_entries')->count())->toBeGreaterThan(0)
        ->and($dist->fresh()->gsb_frozen_at)->not->toBeNull();
});
