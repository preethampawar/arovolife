<?php

declare(strict_types=1);

use App\Modules\Compliance\Models\AuditLog;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    disableTestForeignKeys();
});

function narrowAwardsCreditWalletTypeMigration(): mixed
{
    return require base_path(
        'app/Modules/Compensation/Database/Migrations/2026_10_09_101300_narrow_awards_credit_wallet_type.php'
    );
}

function narrowRankRepurchaseHeldStatusMigration(): mixed
{
    return require base_path(
        'app/Modules/Compensation/Database/Migrations/2026_10_09_101400_narrow_rank_repurchase_held_status.php'
    );
}

/** The MySQL column type, e.g. "enum('pending',…)"; null on other drivers. */
function staleEnumColumnType(string $table, string $column): ?string
{
    if (DB::getDriverName() !== 'mysql') {
        return null;
    }

    return (string) DB::selectOne("SHOW COLUMNS FROM {$table} LIKE '{$column}'")->Type;
}

/** @return array<string, mixed> */
function staleEnumWalletRow(string $type): array
{
    return [
        'distributor_id' => 990_101, 'type' => $type, 'amount_paise' => 100_000,
        'reference_id' => walletRef(), 'reference_type' => 'lifetime_award_milestone', 'created_at' => now(),
    ];
}

/** @return array<string, mixed> */
function staleEnumRankRow(string $status): array
{
    return [
        'distributor_id' => 990_102, 'month_start' => '2026-08-01', 'rank_number' => 1, 'status' => $status,
        'company_turnover_paise' => 0, 'pool_paise' => 0, 'qualifier_count' => 1, 'gross_paise' => 0,
        'admin_charge_paise' => 0, 'tds_paise' => 0, 'net_paise' => 0,
        'created_at' => now(), 'updated_at' => now(),
    ];
}

const NARROWED_WALLET_TYPE_ENUM = "enum('gsb_credit','mb_credit','gbb_credit','rank_credit','fortune_credit','adc_credit','payout_debit','admin_charge_debit','tds_debit','repurchase_deduction','rank_cap_forfeit','income_cap_forfeit','manual_credit','reversal','repurchase_wallet_used','repurchase_transfer')";

const NARROWED_RANK_STATUS_ENUM = "enum('pending','credited','reversed','requalification_held','repurchase_wallet_blocked')";

it('refuses to drop awards_credit while a ledger row still carries it, and changes nothing', function (): void {
    // RefreshDatabase already narrowed the enum; widen it so a legacy row can exist.
    narrowAwardsCreditWalletTypeMigration()->down();
    $before = staleEnumColumnType('wallet_ledger_entries', 'type');
    $auditBefore = AuditLog::where('action', 'plan.migration.narrow_awards_credit_wallet_type')->count();

    $id = DB::table('wallet_ledger_entries')->insertGetId(staleEnumWalletRow('awards_credit'));

    try {
        expect(fn () => narrowAwardsCreditWalletTypeMigration()->up())
            ->toThrow(RuntimeException::class, 'Refusing to narrow the wallet ledger type enum: 1 wallet_ledger_entries rows still carry awards_credit. Replay or wipe history first.');

        expect(staleEnumColumnType('wallet_ledger_entries', 'type'))->toBe($before)
            ->and(DB::table('wallet_ledger_entries')->where('id', $id)->value('type'))->toBe('awards_credit')
            ->and(AuditLog::where('action', 'plan.migration.narrow_awards_credit_wallet_type')->count())->toBe($auditBefore);
    } finally {
        DB::table('wallet_ledger_entries')->where('id', $id)->delete();
        narrowAwardsCreditWalletTypeMigration()->up();
    }
});

it('drops awards_credit from the ledger type enum when no row carries it and records the run', function (): void {
    narrowAwardsCreditWalletTypeMigration()->down();

    narrowAwardsCreditWalletTypeMigration()->up();

    $audit = (array) AuditLog::query()
        ->where('action', 'plan.migration.narrow_awards_credit_wallet_type')
        ->orderByDesc('id')
        ->firstOrFail()
        ->details;

    expect($audit)->toMatchArray([
        'migration' => '2026_10_09_101300_narrow_awards_credit_wallet_type',
        'driver' => DB::getDriverName(),
        'wallet_ledger_entries' => ['column' => 'type', 'removed' => ['awards_credit'], 'rows_carrying' => 0],
    ])->and($audit['reason'])->toContain('merchandise only');

    if (DB::getDriverName() === 'mysql') {
        expect(staleEnumColumnType('wallet_ledger_entries', 'type'))->toBe(NARROWED_WALLET_TYPE_ENUM);

        // Strict mode: MySQL now refuses the retired value outright.
        expect(fn () => DB::table('wallet_ledger_entries')->insert(staleEnumWalletRow('awards_credit')))
            ->toThrow(QueryException::class);
    }
});

it('refuses to drop the rank repurchase_held status while a row still carries it, and changes nothing', function (): void {
    // A database migrated after 43f66121 never had the value; widen to the
    // shape the deleted 2026_09_06_100001 left so a legacy row can exist.
    narrowRankRepurchaseHeldStatusMigration()->down();
    $before = staleEnumColumnType('rank_bonus_results', 'status');
    $auditBefore = AuditLog::where('action', 'plan.migration.narrow_rank_repurchase_held_status')->count();

    $id = DB::table('rank_bonus_results')->insertGetId(staleEnumRankRow('repurchase_held'));

    try {
        expect(fn () => narrowRankRepurchaseHeldStatusMigration()->up())
            ->toThrow(RuntimeException::class, 'Refusing to narrow the rank bonus status enum: 1 rank_bonus_results rows still carry repurchase_held. Replay or wipe history first.');

        expect(staleEnumColumnType('rank_bonus_results', 'status'))->toBe($before)
            ->and(DB::table('rank_bonus_results')->where('id', $id)->value('status'))->toBe('repurchase_held')
            ->and(AuditLog::where('action', 'plan.migration.narrow_rank_repurchase_held_status')->count())->toBe($auditBefore);
    } finally {
        DB::table('rank_bonus_results')->where('id', $id)->delete();
        narrowRankRepurchaseHeldStatusMigration()->up();
    }
});

it('drops repurchase_held from the rank status enum when no row carries it and records the run', function (): void {
    narrowRankRepurchaseHeldStatusMigration()->down();

    narrowRankRepurchaseHeldStatusMigration()->up();

    $audit = (array) AuditLog::query()
        ->where('action', 'plan.migration.narrow_rank_repurchase_held_status')
        ->orderByDesc('id')
        ->firstOrFail()
        ->details;

    expect($audit)->toMatchArray([
        'migration' => '2026_10_09_101400_narrow_rank_repurchase_held_status',
        'driver' => DB::getDriverName(),
        'rank_bonus_results' => ['column' => 'status', 'removed' => ['repurchase_held'], 'rows_carrying' => 0],
    ])->and($audit['reason'])->toContain('forfeit spec');

    if (DB::getDriverName() === 'mysql') {
        expect(staleEnumColumnType('rank_bonus_results', 'status'))->toBe(NARROWED_RANK_STATUS_ENUM)
            ->and(DB::selectOne("SHOW COLUMNS FROM rank_bonus_results LIKE 'status'")->Default)->toBe('pending');

        expect(fn () => DB::table('rank_bonus_results')->insert(staleEnumRankRow('repurchase_held')))
            ->toThrow(QueryException::class);
    }
});

function narrowFortuneRepurchaseHeldStatusMigration(): mixed
{
    return require base_path(
        'app/Modules/Compensation/Database/Migrations/2026_10_09_101500_narrow_fortune_repurchase_held_status.php'
    );
}

/** @return array<string, mixed> */
function staleEnumFortuneRow(string $status): array
{
    return [
        'distributor_id' => 990_103, 'month_start' => '2026-08-01', 'position' => 1, 'matrix_level' => 0, 'status' => $status,
        'created_at' => now(), 'updated_at' => now(),
    ];
}

const NARROWED_FORTUNE_STATUS_ENUM = "enum('pending','credited','skipped','repurchase_wallet_blocked')";

it('refuses to drop the fortune repurchase_held status while a row still carries it, and changes nothing', function (): void {
    narrowFortuneRepurchaseHeldStatusMigration()->down();
    $before = staleEnumColumnType('fortune_bonus_results', 'status');
    $auditBefore = AuditLog::where('action', 'plan.migration.narrow_fortune_repurchase_held_status')->count();

    $id = DB::table('fortune_bonus_results')->insertGetId(staleEnumFortuneRow('repurchase_held'));

    try {
        expect(fn () => narrowFortuneRepurchaseHeldStatusMigration()->up())
            ->toThrow(RuntimeException::class, 'Refusing to narrow the fortune bonus status enum: 1 fortune_bonus_results rows still carry repurchase_held. Replay or wipe history first.');

        expect(staleEnumColumnType('fortune_bonus_results', 'status'))->toBe($before)
            ->and(DB::table('fortune_bonus_results')->where('id', $id)->value('status'))->toBe('repurchase_held')
            ->and(AuditLog::where('action', 'plan.migration.narrow_fortune_repurchase_held_status')->count())->toBe($auditBefore);
    } finally {
        DB::table('fortune_bonus_results')->where('id', $id)->delete();
        narrowFortuneRepurchaseHeldStatusMigration()->up();
    }
})->skip(fn (): bool => DB::getDriverName() !== 'mysql', 'SQLite keeps the CHECK list without repurchase_held; a legacy row cannot exist there.');

it('drops repurchase_held from the fortune status enum when no row carries it and records the run', function (): void {
    narrowFortuneRepurchaseHeldStatusMigration()->down();

    narrowFortuneRepurchaseHeldStatusMigration()->up();

    $audit = (array) AuditLog::query()
        ->where('action', 'plan.migration.narrow_fortune_repurchase_held_status')
        ->orderByDesc('id')
        ->firstOrFail()
        ->details;

    expect($audit)->toMatchArray([
        'migration' => '2026_10_09_101500_narrow_fortune_repurchase_held_status',
        'driver' => DB::getDriverName(),
        'fortune_bonus_results' => ['column' => 'status', 'removed' => ['repurchase_held'], 'rows_carrying' => 0],
    ])->and($audit['reason'])->toContain('forfeit spec');

    if (DB::getDriverName() === 'mysql') {
        expect(staleEnumColumnType('fortune_bonus_results', 'status'))->toBe(NARROWED_FORTUNE_STATUS_ENUM)
            ->and(DB::selectOne("SHOW COLUMNS FROM fortune_bonus_results LIKE 'status'")->Default)->toBe('pending');

        expect(fn () => DB::table('fortune_bonus_results')->insert(staleEnumFortuneRow('repurchase_held')))
            ->toThrow(QueryException::class);
    }
});
