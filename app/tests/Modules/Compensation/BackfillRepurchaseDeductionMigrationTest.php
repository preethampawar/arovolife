<?php

declare(strict_types=1);

use App\Modules\Compensation\Models\GbbMonthlyResult;
use App\Modules\Compensation\Models\GsbCutoffResult;
use App\Modules\Compensation\Services\WalletService;
use App\Modules\Identity\Models\Distributor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    disableTestForeignKeys();
});

/**
 * The backfill that moves existing bonus result rows onto the credit-time
 * meaning of the result tables: repurchase deduction copied from the ledger,
 * net = gross − deduction, and the payout's old admin/TDS write-back zeroed.
 */
function backfillRepurchaseDeductionMigration(): object
{
    return require database_path('migrations/2026_09_05_200001_backfill_repurchase_deduction_on_bonus_results.php');
}

it('copies the ledger deduction onto credited rows, zeroes admin/TDS, and leaves never-credited rows at net = gross', function (): void {
    $dist = Distributor::factory()->create();
    $wallet = app(WalletService::class);

    // A GSB row credited under the credit-time system and then swept by the
    // old payout, which wrote its admin charge and TDS back onto the row.
    $creditedId = DB::table('gsb_cutoff_results')->insertGetId([
        'distributor_id' => $dist->id,
        'cutoff_date' => '2026-08-20',
        'gross_gsb_paise' => 200_000,
        'admin_charge_paise' => 6_000,
        'tds_paise' => 9_700,
        'net_gsb_paise' => 184_300,
        'status' => GsbCutoffResult::STATUS_CREDITED,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $wallet->credit($dist->id, 200_000, 'gsb_credit', $creditedId, 'gsb_cutoff_result', earnedOn: Carbon::parse('2026-08-20'));
    $wallet->credit($dist->id, 20_000, 'repurchase_deduction', $creditedId, 'gsb_cutoff_result');

    // A frozen GSB row: never credited, no ledger entries.
    $frozenId = DB::table('gsb_cutoff_results')->insertGetId([
        'distributor_id' => $dist->id,
        'cutoff_date' => '2026-08-21',
        'gross_gsb_paise' => 800_000,
        'net_gsb_paise' => 800_000,
        'status' => GsbCutoffResult::STATUS_FROZEN,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    // A GBB row held for repurchase: never credited either.
    $held = GbbMonthlyResult::create([
        'distributor_id' => $dist->id,
        'year_month' => '2026-08-01',
        'agp_earned' => 3,
        'company_turnover_paise' => 10_000_000,
        'pool_paise' => 500_000,
        'total_pool_agp' => 200,
        'point_value_paise' => 100_000,
        'gbb_gross_paise' => 300_000,
        'gbb_net_paise' => 300_000,
        'status' => GbbMonthlyResult::STATUS_REPURCHASE_HELD,
    ]);

    backfillRepurchaseDeductionMigration()->up();

    $credited = DB::table('gsb_cutoff_results')->find($creditedId);
    expect((int) $credited->repurchase_deduction_paise)->toBe(20_000)
        ->and((int) $credited->net_gsb_paise)->toBe(180_000)
        ->and((int) $credited->admin_charge_paise)->toBe(0)
        ->and((int) $credited->tds_paise)->toBe(0);

    $frozen = DB::table('gsb_cutoff_results')->find($frozenId);
    expect((int) $frozen->repurchase_deduction_paise)->toBe(0)
        ->and((int) $frozen->net_gsb_paise)->toBe(800_000);

    expect($held->fresh()->repurchase_deduction_paise)->toBe(0)
        ->and($held->fresh()->gbb_net_paise)->toBe(300_000);

    // The reset of the per-row admin/TDS apportionment is recorded.
    $audit = DB::table('audit_log')->where('action', 'compensation.result_deductions.reset')->first();
    expect($audit)->not->toBeNull();
    $details = json_decode((string) $audit->details, true);
    expect($details['rows_updated']['gsb_cutoff_results'])->toBe(1);
});

it('is a no-op on rows that already carry the credit-time figures', function (): void {
    $dist = Distributor::factory()->create();

    DB::table('gsb_cutoff_results')->insert([
        'distributor_id' => $dist->id,
        'cutoff_date' => '2026-08-22',
        'gross_gsb_paise' => 200_000,
        'repurchase_deduction_paise' => 0,
        'net_gsb_paise' => 200_000,
        'status' => GsbCutoffResult::STATUS_CREDITED,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    backfillRepurchaseDeductionMigration()->up();

    expect(DB::table('audit_log')->where('action', 'compensation.result_deductions.reset')->exists())->toBeFalse();
});
