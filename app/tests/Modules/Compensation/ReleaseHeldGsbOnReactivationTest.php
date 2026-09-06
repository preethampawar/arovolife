<?php

declare(strict_types=1);

use App\Modules\Compensation\Events\IncomeReactivated;
use App\Modules\Compensation\Models\GsbCutoffResult;
use App\Modules\Compensation\Models\WalletLedgerEntry;
use App\Modules\Identity\Models\Distributor;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    disableTestForeignKeys();
});

/**
 * Seed a GSB row exactly as the cut-off leaves it when the repurchase grace
 * window holds the credit: calculated, net still equal to gross, nothing in the
 * wallet, waiting for ReleaseHeldGsbOnReactivation to settle it.
 */
function gsbReleaseSeedRow(int $distributorId, string $date, int $grossPaise): GsbCutoffResult
{
    return GsbCutoffResult::create([
        'distributor_id' => $distributorId,
        'cutoff_date' => $date,
        'left_bv_paise' => 2_000_000,
        'right_bv_paise' => 1_600_000,
        'slab' => 1,
        'gross_gsb_paise' => $grossPaise,
        'net_gsb_paise' => $grossPaise,
        'power_cf_after_paise' => 0,
        'slab1_weaker_cf_after_paise' => 0,
        'power_side_after' => 'L',
        'status' => GsbCutoffResult::STATUS_REPURCHASE_HELD,
    ]);
}

it('releases a held GSB row through the repurchase deduction, writing three ledger entries', function (): void {
    // Released income is income: it takes the same 10% repurchase deduction as
    // a credit that was never held, so the row settles at gross − deduction and
    // the withheld money lands in the repurchase wallet rather than in cash.
    $dist = Distributor::factory()->create();
    $row = gsbReleaseSeedRow($dist->id, '2026-08-20', 200_000);

    event(new IncomeReactivated($dist->id, 1));

    $entries = WalletLedgerEntry::where('distributor_id', $dist->id)->get();
    expect($entries)->toHaveCount(3);

    expect((int) $entries->firstWhere('type', 'gsb_credit')?->amount_paise)->toBe(200_000);
    expect((int) $entries->firstWhere('type', 'repurchase_transfer')?->amount_paise)->toBe(-20_000);
    expect((int) $entries->firstWhere('type', 'repurchase_deduction')?->amount_paise)->toBe(20_000);

    $row->refresh();
    expect($row->status)->toBe(GsbCutoffResult::STATUS_CREDITED);
    expect($row->repurchase_deduction_paise)->toBe(20_000);
    expect($row->net_gsb_paise)->toBe(180_000);
});

it('does not double-credit a released GSB row when the reactivation fires again', function (): void {
    $dist = Distributor::factory()->create();
    $row = gsbReleaseSeedRow($dist->id, '2026-08-20', 200_000);

    event(new IncomeReactivated($dist->id, 1));
    event(new IncomeReactivated($dist->id, 2));

    expect(WalletLedgerEntry::where('distributor_id', $dist->id)->count())->toBe(3);

    $row->refresh();
    expect($row->status)->toBe(GsbCutoffResult::STATUS_CREDITED);
    expect($row->repurchase_deduction_paise)->toBe(20_000);
    expect($row->net_gsb_paise)->toBe(180_000);
});

it('credits nothing for a zero-gross held GSB row but still settles it', function (): void {
    // A starved pool day can price a matched slab at ₹0: the row is settled
    // without any ledger noise, exactly as GsbCutoffService::settle() does.
    $dist = Distributor::factory()->create();
    $row = gsbReleaseSeedRow($dist->id, '2026-08-21', 0);

    event(new IncomeReactivated($dist->id, 1));

    expect(WalletLedgerEntry::where('distributor_id', $dist->id)->count())->toBe(0);

    $row->refresh();
    expect($row->status)->toBe(GsbCutoffResult::STATUS_CREDITED);
    expect($row->repurchase_deduction_paise)->toBe(0);
    expect($row->net_gsb_paise)->toBe(0);
});
