<?php

declare(strict_types=1);

use App\Modules\Compensation\Events\IncomeReactivated;
use App\Modules\Compensation\Models\GbbMonthlyResult;
use App\Modules\Compensation\Models\WalletLedgerEntry;
use App\Modules\Identity\Models\Distributor;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    disableTestForeignKeys();
});

/**
 * Seed a GBB row exactly as the monthly run leaves it when the repurchase grace
 * window holds the credit: priced at the month's frozen point value, net still
 * equal to gross, nothing in the wallet, waiting to be released.
 */
function gbbReleaseSeedRow(int $distributorId, string $yearMonth, int $grossPaise): GbbMonthlyResult
{
    return GbbMonthlyResult::create([
        'distributor_id' => $distributorId,
        'year_month' => $yearMonth,
        'agp_earned' => 3,
        'company_turnover_paise' => 10_000_000,
        'pool_paise' => 500_000,
        'total_pool_agp' => 200,
        'point_value_paise' => 100_000,
        'gbb_gross_paise' => $grossPaise,
        'gbb_net_paise' => $grossPaise,
        'status' => GbbMonthlyResult::STATUS_REPURCHASE_HELD,
    ]);
}

it('releases a held GBB row through the repurchase deduction, writing three ledger entries', function (): void {
    // Released income is income: it takes the same 10% repurchase deduction as
    // a credit that was never held, so the row settles at gross − deduction and
    // the withheld money lands in the repurchase wallet rather than in cash.
    $dist = Distributor::factory()->create();
    $row = gbbReleaseSeedRow($dist->id, '2026-08-01', 300_000);

    event(new IncomeReactivated($dist->id, 1));

    $entries = WalletLedgerEntry::where('distributor_id', $dist->id)->get();
    expect($entries)->toHaveCount(3);

    expect((int) $entries->firstWhere('type', 'gbb_credit')?->amount_paise)->toBe(300_000);
    expect((int) $entries->firstWhere('type', 'repurchase_transfer')?->amount_paise)->toBe(-30_000);
    expect((int) $entries->firstWhere('type', 'repurchase_deduction')?->amount_paise)->toBe(30_000);

    $row->refresh();
    expect($row->status)->toBe(GbbMonthlyResult::STATUS_CREDITED);
    expect($row->repurchase_deduction_paise)->toBe(30_000);
    expect($row->gbb_net_paise)->toBe(270_000);
});

it('does not double-credit a released GBB row when the reactivation fires again', function (): void {
    $dist = Distributor::factory()->create();
    $row = gbbReleaseSeedRow($dist->id, '2026-08-01', 300_000);

    event(new IncomeReactivated($dist->id, 1));
    event(new IncomeReactivated($dist->id, 2));

    expect(WalletLedgerEntry::where('distributor_id', $dist->id)->count())->toBe(3);

    $row->refresh();
    expect($row->status)->toBe(GbbMonthlyResult::STATUS_CREDITED);
    expect($row->repurchase_deduction_paise)->toBe(30_000);
    expect($row->gbb_net_paise)->toBe(270_000);
});

it('credits nothing for a zero-gross held GBB row but still settles it', function (): void {
    // A month whose frozen point value prices the row at ₹0 is settled without
    // any ledger noise; there is nothing to deduct from.
    $dist = Distributor::factory()->create();
    $row = gbbReleaseSeedRow($dist->id, '2026-08-01', 0);

    event(new IncomeReactivated($dist->id, 1));

    expect(WalletLedgerEntry::where('distributor_id', $dist->id)->count())->toBe(0);

    $row->refresh();
    expect($row->status)->toBe(GbbMonthlyResult::STATUS_CREDITED);
    expect($row->repurchase_deduction_paise)->toBe(0);
    expect($row->gbb_net_paise)->toBe(0);
});
