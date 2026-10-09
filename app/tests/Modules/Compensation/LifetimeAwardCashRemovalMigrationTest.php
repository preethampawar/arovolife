<?php

declare(strict_types=1);

use App\Modules\Compensation\Models\WalletLedgerEntry;
use App\Modules\Compliance\Models\AuditLog;
use App\Modules\Identity\Models\Distributor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    disableTestForeignKeys();
});

function dropLifetimeAwardCashColumnsMigration(): mixed
{
    return require base_path(
        'app/Modules/Compensation/Database/Migrations/2026_10_09_101000_drop_lifetime_award_cash_columns.php'
    );
}

function removeAdminChargeAppliesToAwardsMigration(): mixed
{
    return require base_path(
        'app/Modules/Compensation/Database/Migrations/2026_10_09_101100_remove_admin_charge_applies_to_awards_setting.php'
    );
}

/** @return array<string, mixed> */
function latestCashRemovalAudit(string $action): array
{
    return (array) AuditLog::query()->where('action', $action)->orderByDesc('id')->firstOrFail()->details;
}

it('drops the five cash columns and records the values they held', function (): void {
    // RefreshDatabase already dropped them; bring them back to run up() again.
    dropLifetimeAwardCashColumnsMigration()->down();

    $base = [
        'rank_number' => 3, 'tranche' => 1, 'amount_paise' => 1_000, 'triggered_month' => '2026-08-01',
        'qualification_count' => 1, 'award_description' => 'x', 'created_at' => now(), 'updated_at' => now(),
    ];
    $delivered = (int) DB::table('lifetime_award_milestones')->insertGetId($base + [
        'distributor_id' => Distributor::factory()->create()->id,
        'status' => 'delivered', 'disbursement_type' => 'cash', 'gross_paise' => 5_000_000,
        'admin_charge_paise' => 150_000, 'tds_paise' => 242_500, 'net_paise' => 4_607_500,
    ]);
    DB::table('lifetime_award_milestones')->insert($base + [
        'distributor_id' => Distributor::factory()->create()->id, 'status' => 'pending',
    ]);

    dropLifetimeAwardCashColumnsMigration()->up();

    foreach (['disbursement_type', 'gross_paise', 'admin_charge_paise', 'tds_paise', 'net_paise'] as $column) {
        expect(Schema::hasColumn('lifetime_award_milestones', $column))->toBeFalse();
    }

    $audit = latestCashRemovalAudit('plan.migration.drop_lifetime_award_cash_columns');
    expect($audit['rows_with_cash_values'])->toBe(1)
        ->and($audit['rows'])->toEqual([
            [
                'id' => $delivered, 'disbursement_type' => 'cash', 'gross_paise' => 5_000_000,
                'admin_charge_paise' => 150_000, 'tds_paise' => 242_500, 'net_paise' => 4_607_500,
            ],
        ]);
});

it('re-adds the cash columns nullable on rollback', function (): void {
    dropLifetimeAwardCashColumnsMigration()->down();

    foreach (['disbursement_type', 'gross_paise', 'admin_charge_paise', 'tds_paise', 'net_paise'] as $column) {
        expect(Schema::hasColumn('lifetime_award_milestones', $column))->toBeTrue();
    }

    // Leave the schema as the full migration set does.
    dropLifetimeAwardCashColumnsMigration()->up();
});

it('deletes the applies_to_awards setting and records its value', function (): void {
    DB::table('settings')->insertOrIgnore([
        'key' => 'comp.admin_charge.applies_to_awards', 'value' => 'false', 'version' => 1,
        'updated_by' => null, 'created_at' => now(), 'updated_at' => now(),
    ]);

    removeAdminChargeAppliesToAwardsMigration()->up();

    expect(DB::table('settings')->where('key', 'comp.admin_charge.applies_to_awards')->exists())->toBeFalse()
        ->and(latestCashRemovalAudit('plan.migration.remove_admin_charge_applies_to_awards_setting'))
        ->toMatchArray(['key' => 'comp.admin_charge.applies_to_awards', 'value_before' => 'false', 'deleted' => true]);
});

it('records deleted false when the applies_to_awards setting is already absent', function (): void {
    DB::table('settings')->where('key', 'comp.admin_charge.applies_to_awards')->delete();

    removeAdminChargeAppliesToAwardsMigration()->up();

    expect(latestCashRemovalAudit('plan.migration.remove_admin_charge_applies_to_awards_setting'))
        ->toMatchArray(['key' => 'comp.admin_charge.applies_to_awards', 'value_before' => null, 'deleted' => false]);
});

it('refuses to retire the awards cash path while an unswept awards_credit ledger row exists', function (): void {
    DB::table('settings')->insertOrIgnore([
        'key' => 'comp.admin_charge.applies_to_awards', 'value' => 'false', 'version' => 1,
        'updated_by' => null, 'created_at' => now(), 'updated_at' => now(),
    ]);
    $distributorId = Distributor::factory()->create()->id;
    $stranded = WalletLedgerEntry::create([
        'distributor_id' => $distributorId,
        'type' => 'awards_credit',
        'amount_paise' => 100_000,
        'reference_id' => walletRef(),
        'reference_type' => 'lifetime_award_milestone',
        'memo' => 'cash award delivered before the 2026-10-09 rule',
    ]);

    // The migration set already ran once under RefreshDatabase, so count from there.
    $auditRowsBefore = AuditLog::where('action', 'plan.migration.remove_admin_charge_applies_to_awards_setting')->count();

    expect(fn () => removeAdminChargeAppliesToAwardsMigration()->up())
        ->toThrow(RuntimeException::class, '1 unswept awards_credit wallet ledger row(s)');

    // Nothing written: the setting row and the ledger row are untouched and no new audit row exists.
    expect(DB::table('settings')->where('key', 'comp.admin_charge.applies_to_awards')->value('value'))->toBe('false')
        ->and(WalletLedgerEntry::whereKey($stranded->id)->exists())->toBeTrue()
        ->and(AuditLog::where('action', 'plan.migration.remove_admin_charge_applies_to_awards_setting')->count())->toBe($auditRowsBefore);

    // A swept row is history, not a stranded payout: counted on the audit row, no refusal.
    $stranded->update(['swept_by_payout_batch_id' => 1]);

    removeAdminChargeAppliesToAwardsMigration()->up();

    expect(latestCashRemovalAudit('plan.migration.remove_admin_charge_applies_to_awards_setting'))
        ->toMatchArray(['deleted' => true, 'awards_credit_ledger_rows' => 1, 'awards_credit_unswept' => 0]);
});

it('refuses to guess the deleted setting on rollback', function (): void {
    expect(fn () => removeAdminChargeAppliesToAwardsMigration()->down())
        ->toThrow(RuntimeException::class, 'restore from the plan.migration.* audit row');
});
