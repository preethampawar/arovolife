<?php

declare(strict_types=1);

use App\Modules\Compensation\Models\GsbCutoffResult;
use App\Modules\Compensation\Models\WalletLedgerEntry;
use App\Modules\Identity\Models\Distributor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    disableTestForeignKeys();
});

/**
 * The forward-only backfill that recovers `earned_on` — the DAY the income was
 * earned — from the Group A result row each pre-column ledger entry points at.
 */
function runBackfillEarnedOnMigration(): void
{
    $migration = require app_path('Modules/Compensation/Database/Migrations/2026_09_07_100003_backfill_earned_on_on_wallet_ledger_entries.php');

    $migration->up();
}

/**
 * Write a ledger row the way every pre-column row was written: straight to the
 * table, with no `earned_on`. WalletService::credit() now refuses a Group A
 * credit without one, which is exactly the state this migration repairs.
 */
function legacyLedgerRow(int $distributorId, string $type, int $paise, ?int $referenceId, ?string $referenceType): WalletLedgerEntry
{
    return WalletLedgerEntry::create([
        'distributor_id' => $distributorId,
        'type' => $type,
        'amount_paise' => $paise,
        'reference_id' => $referenceId,
        'reference_type' => $referenceType,
    ]);
}

it('stamps the gsb, mentorship and repurchase rows from their result rows and leaves the rest null', function (): void {
    $dist = Distributor::factory()->create();

    // A GSB cut-off for 20 August: the gross credit, the repurchase_transfer
    // debit that moved the deduction out of the main wallet, and the
    // repurchase_deduction credit that put it in the repurchase wallet all hang
    // off this row and share its day.
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

    // Mentorship rides the same earning week (spec §3, A3) and must be stamped
    // too — the bonus_month backfill that shipped before this one omitted it.
    $mbId = DB::table('mentorship_bonus_results')->insertGetId([
        'sponsor_id' => $dist->id,
        'sponsee_id' => $dist->id + 1,
        'cutoff_date' => '2026-08-18',
        'sponsee_gsb_paise' => 100_000,
        'mb_gross_paise' => 10_000,
        'mb_admin_charge_paise' => 0,
        'mb_tds_paise' => 0,
        'status' => 'credited',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $gross = legacyLedgerRow($dist->id, 'gsb_credit', 200_000, $gsbId, 'gsb_cutoff_result');
    $transfer = legacyLedgerRow($dist->id, 'repurchase_transfer', -20_000, $gsbId, 'gsb_cutoff_result');
    $held = legacyLedgerRow($dist->id, 'repurchase_deduction', 20_000, $gsbId, 'gsb_cutoff_result');
    $mentorship = legacyLedgerRow($dist->id, 'mb_credit', 10_000, $mbId, 'mentorship_bonus_result');

    // An admin correction hangs off the same cut-off row but is not Group A, was
    // not earned on that day, and no weekly batch sweeps it.
    $manual = legacyLedgerRow($dist->id, 'manual_credit', 5_000, $gsbId, 'gsb_cutoff_result');
    // Unresolvable: no such result row.
    $orphan = legacyLedgerRow($dist->id, 'gsb_credit', 50_000, 999_999, 'gsb_cutoff_result');
    // A monthly stream: earned for a month, never for a day.
    $rank = legacyLedgerRow($dist->id, 'rank_credit', 100_000, 7, 'rank_bonus_result');

    expect(WalletLedgerEntry::whereNull('earned_on')->count())->toBe(7);

    runBackfillEarnedOnMigration();

    expect($gross->fresh()->earned_on->toDateString())->toBe('2026-08-20')
        ->and($transfer->fresh()->earned_on->toDateString())->toBe('2026-08-20')
        ->and($held->fresh()->earned_on->toDateString())->toBe('2026-08-20')
        ->and($mentorship->fresh()->earned_on->toDateString())->toBe('2026-08-18')
        // Never guessed: the batch's null-passthrough still answers for these.
        ->and($manual->fresh()->earned_on)->toBeNull()
        ->and($orphan->fresh()->earned_on)->toBeNull()
        ->and($rank->fresh()->earned_on)->toBeNull();
});

it('is idempotent and never overwrites a day already stamped', function (): void {
    $dist = Distributor::factory()->create();

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

    // A row written under the current engines already carries its earning day.
    // Re-dating it would move real money into a different payout week.
    $stamped = WalletLedgerEntry::create([
        'distributor_id' => $dist->id,
        'type' => 'gsb_credit',
        'amount_paise' => 200_000,
        'reference_id' => $gsbId,
        'reference_type' => 'gsb_cutoff_result',
        'earned_on' => '2026-08-13',
    ]);

    runBackfillEarnedOnMigration();
    runBackfillEarnedOnMigration();

    expect($stamped->fresh()->earned_on->toDateString())->toBe('2026-08-13');
});
