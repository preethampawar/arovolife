<?php

declare(strict_types=1);

use App\Modules\Commerce\Models\BvLedgerEntry;
use App\Modules\Compensation\Services\WalletService;
use App\Modules\Genealogy\Support\ReservedAdns;
use App\Modules\Identity\Models\Distributor;
use App\Modules\Identity\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    disableTestForeignKeys();
});

it('wipes everything and rebuilds only the 31 reserved company distributors (levels 0-4)', function () {
    // A regular (non-reserved) distributor with purchase + bonus data.
    $dist = Distributor::factory()->create();
    BvLedgerEntry::create([
        'distributor_id' => $dist->id,
        'order_id' => 900_001,
        'bv_paise' => 300_000,
        'type' => 'accrual',
        'effective_at' => now(),
    ]);
    app(WalletService::class)->credit($dist->id, 100_000, 'gsb_credit', walletRef(), 'test_reference');
    DB::table('customers')->insert([
        'display_name' => 'Test Buyer',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $this->artisan('platform:reset', ['--force' => true])->assertExitCode(0);

    // Exactly the 31 reserved distributors remain, at depths 0-4, with the
    // canonical company ADN block.
    expect(Distributor::query()->count())->toBe(31)
        ->and(Distributor::query()->max('depth'))->toBe(4)
        ->and(Distributor::query()->pluck('adn')->sort()->values()->all())
        ->toBe(collect(ReservedAdns::all())->sort()->values()->all());

    // Purchase data, wallets and customers are gone.
    expect(DB::table('bv_ledger_entries')->count())->toBe(0)
        ->and(DB::table('wallet_ledger_entries')->count())->toBe(0)
        ->and(DB::table('customers')->count())->toBe(0);

    // Users: 31 reserved + the re-seeded admin, who keeps the admin role.
    expect(User::query()->count())->toBe(32);
    $admin = User::query()->where('email', 'admin@arovolife.test')->firstOrFail();
    expect($admin->hasRole('admin'))->toBeTrue();

    // The closure table matches a complete 31-node binary tree:
    // 31 self-rows + (2*1 + 4*2 + 8*3 + 16*4) ancestor rows = 129.
    expect(DB::table('genealogy_closure')->count())->toBe(129);

    // The reset is audit-logged (audit_log itself was truncated first).
    expect(DB::table('audit_log')->where('action', 'platform.reset')->count())->toBe(1);
});
