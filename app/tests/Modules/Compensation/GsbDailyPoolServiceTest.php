<?php

declare(strict_types=1);

use App\Modules\Commerce\Models\BvLedgerEntry;
use App\Modules\Compensation\Models\GsbCutoffResult;
use App\Modules\Compensation\Models\GsbDailyPool;
use App\Modules\Compensation\Services\GsbDailyPoolService;
use App\Modules\Compliance\Models\AuditLog;
use App\Modules\Identity\Models\Distributor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    disableTestForeignKeys();
});

afterEach(function (): void {
    Carbon::setTestNow();
});

/** Put $bvPaise of company-wide BV on the given date. */
function gsbPoolSeedCompanyBv(int $bvPaise, Carbon $date): void
{
    BvLedgerEntry::create([
        'distributor_id' => Distributor::factory()->create()->id,
        'order_id' => random_int(700_000, 799_999),
        'bv_paise' => $bvPaise,
        'type' => $bvPaise < 0 ? 'reversal' : 'accrual',
        'effective_at' => $date->copy()->setTime(18, 0),
    ]);
}

/** A pool-funded result row for the date — money moved against the frozen pool. */
function gsbPoolFundedResult(Carbon $date): void
{
    GsbCutoffResult::create([
        'distributor_id' => Distributor::factory()->create()->id,
        'cutoff_date' => $date->toDateString(),
        'left_bv_paise' => 2_000_000,
        'right_bv_paise' => 1_600_000,
        'weaker_bv_paise' => 1_600_000,
        'slab' => 3,
        'score' => 32,
        'score_value_paise' => 25_000,
        'gross_gsb_paise' => 32 * 25_000,
        'admin_charge_paise' => 0,
        'tds_paise' => 0,
        'net_gsb_paise' => 32 * 25_000,
        'status' => GsbCutoffResult::STATUS_CREDITED,
    ]);
}

// The staging incident of 24 Aug 2026: a manual cut-off at 23:27 froze the
// day's pool at ₹0 company BV / zero achievers, and the scheduled 00:10 run
// then priced the day's real achievers against the empty snapshot. A pool row
// frozen BEFORE its day ended, with nothing funded against it, is provably
// premature and must be replaced by the next full run's freeze.
it('replaces a pool frozen before the day ended when nothing was priced against it', function (): void {
    $date = Carbon::parse('2026-08-24');
    $service = app(GsbDailyPoolService::class);

    // 23:27 on the day itself — the day is still in flight, no BV yet.
    Carbon::setTestNow($date->copy()->setTime(23, 27));
    $premature = $service->freezePoolForDate($date, 0, 0);

    expect($premature->company_bv_paise)->toBe(0);

    // The evening's purchases land after the premature freeze.
    gsbPoolSeedCompanyBv(10_000_000, $date);

    // The scheduled run at 00:10 the next day freezes with the real aggregates.
    Carbon::setTestNow($date->copy()->addDay()->setTime(0, 10));
    $healed = $service->freezePoolForDate($date, 0, 40);

    expect($healed->id)->not->toBe($premature->id)
        ->and($healed->company_bv_paise)->toBe(10_000_000)
        ->and($healed->pool_paise)->toBe(intdiv(10_000_000 * $healed->pool_rate_bp, 10_000))
        ->and($healed->variable_total_score)->toBe(40)
        ->and(GsbDailyPool::count())->toBe(1);

    $audit = AuditLog::where('action', 'gsb.pool.refrozen')->sole();
    expect($audit->details['cutoff_date'])->toBe('2026-08-24')
        ->and($audit->details['company_bv_paise'])->toBe(0);
});

it('keeps a premature pool once results were priced against it', function (): void {
    $date = Carbon::parse('2026-08-24');
    $service = app(GsbDailyPoolService::class);

    Carbon::setTestNow($date->copy()->setTime(23, 27));
    $premature = $service->freezePoolForDate($date, 0, 0);

    // Someone was credited against the frozen (empty) pool — its economics
    // can no longer be replaced without changing money that already moved.
    gsbPoolFundedResult($date);
    gsbPoolSeedCompanyBv(10_000_000, $date);

    Carbon::setTestNow($date->copy()->addDay()->setTime(0, 10));
    $kept = $service->freezePoolForDate($date, 0, 40);

    expect($kept->id)->toBe($premature->id)
        ->and($kept->company_bv_paise)->toBe(0)
        ->and(AuditLog::where('action', 'gsb.pool.refrozen')->exists())->toBeFalse();
});

it('reuses a pool frozen after the day closed — the normal crash re-run path', function (): void {
    $date = Carbon::parse('2026-08-24');
    $service = app(GsbDailyPoolService::class);
    gsbPoolSeedCompanyBv(10_000_000, $date);

    Carbon::setTestNow($date->copy()->addDay()->setTime(0, 10));
    $frozen = $service->freezePoolForDate($date, 0, 40);

    // A re-run 30 minutes later (crash mid-settle) prices against the same row.
    Carbon::setTestNow($date->copy()->addDay()->setTime(0, 40));
    $reused = $service->freezePoolForDate($date, 999, 999);

    expect($reused->id)->toBe($frozen->id)
        ->and($reused->variable_total_score)->toBe(40)
        ->and(GsbDailyPool::count())->toBe(1);
});
