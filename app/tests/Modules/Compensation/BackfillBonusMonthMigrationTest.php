<?php

declare(strict_types=1);

use App\Modules\Compensation\Models\GsbCutoffResult;
use App\Modules\Compensation\Models\WalletLedgerEntry;
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
 * The forward-only backfill that recovers `bonus_month` — the month the income
 * was EARNED for — from the result row each pre-column ledger entry points at.
 */
function runBackfillBonusMonthMigration(): void
{
    $migration = require app_path('Modules/Compensation/Database/Migrations/2026_09_08_100000_backfill_bonus_month_on_wallet_ledger_entries.php');

    $migration->up();
}

it('recovers the earned month from every referenced result row and leaves the rest null', function (): void {
    $dist = Distributor::factory()->create();
    $wallet = app(WalletService::class);

    // A GSB cut-off for 20 August, credited before bonus_month existed: the
    // gross credit, its repurchase_transfer debit and the repurchase_deduction
    // credit all hang off the same result row and share its month.
    $gsbId = DB::table('gsb_cutoff_results')->insertGetId([
        'distributor_id' => $dist->id,
        'cutoff_date' => '2026-08-20',
        'gross_gsb_paise' => 200_000,
        'admin_charge_paise' => 0,
        'tds_paise' => 0,
        'net_gsb_paise' => 180_000,
        'status' => GsbCutoffResult::STATUS_CREDITED,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    // A Rank bonus EARNED for August but written on 1 September — the case the
    // created_at fallback gets wrong.
    $rankId = DB::table('rank_bonus_results')->insertGetId([
        'distributor_id' => $dist->id,
        'month_start' => '2026-08-01',
        'rank_number' => 1,
        'company_turnover_paise' => 0,
        'pool_paise' => 0,
        'qualifier_count' => 1,
        'gross_paise' => 100_000,
        'admin_charge_paise' => 0,
        'tds_paise' => 0,
        'net_paise' => 100_000,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    // Plain credit()/debit() with no bonus_month — exactly how every row was
    // written before the column existed.
    $gross = $wallet->credit($dist->id, 200_000, 'gsb_credit', $gsbId, 'gsb_cutoff_result');
    $transfer = $wallet->debit($dist->id, 20_000, 'repurchase_transfer', $gsbId, 'gsb_cutoff_result');
    $held = $wallet->credit($dist->id, 20_000, 'repurchase_deduction', $gsbId, 'gsb_cutoff_result');
    $rank = $wallet->credit($dist->id, 100_000, 'rank_credit', $rankId, 'rank_bonus_result');

    // Unresolvable: the reference points at no result row at all, and a
    // reference type the map deliberately does not cover.
    $orphan = $wallet->credit($dist->id, 50_000, 'gsb_credit', 999_999, 'gsb_cutoff_result');
    $mentorship = $wallet->credit($dist->id, 50_000, 'mb_credit', walletRef(), 'mentorship_bonus_result');

    expect(WalletLedgerEntry::whereNull('bonus_month')->count())->toBe(6);

    runBackfillBonusMonthMigration();

    expect($gross->fresh()->bonus_month->toDateString())->toBe('2026-08-01')
        ->and($transfer->fresh()->bonus_month->toDateString())->toBe('2026-08-01')
        ->and($held->fresh()->bonus_month->toDateString())->toBe('2026-08-01')
        ->and($rank->fresh()->bonus_month->toDateString())->toBe('2026-08-01')
        // Never guessed: the created_at fallback still answers for these.
        ->and($orphan->fresh()->bonus_month)->toBeNull()
        ->and($mentorship->fresh()->bonus_month)->toBeNull();
});

it('is idempotent and never overwrites a month already stamped', function (): void {
    $dist = Distributor::factory()->create();
    $wallet = app(WalletService::class);

    $gsbId = DB::table('gsb_cutoff_results')->insertGetId([
        'distributor_id' => $dist->id,
        'cutoff_date' => '2026-08-20',
        'gross_gsb_paise' => 200_000,
        'admin_charge_paise' => 0,
        'tds_paise' => 0,
        'net_gsb_paise' => 200_000,
        'status' => GsbCutoffResult::STATUS_CREDITED,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    // A row written under the current engines already carries its earned month,
    // and a July cut-off row reconciled onto an August result must not be
    // silently re-dated by a re-run.
    $stamped = $wallet->credit($dist->id, 200_000, 'gsb_credit', $gsbId, 'gsb_cutoff_result', null, Carbon::create(2026, 7, 1));

    runBackfillBonusMonthMigration();
    runBackfillBonusMonthMigration();

    expect($stamped->fresh()->bonus_month->toDateString())->toBe('2026-07-01');
});
