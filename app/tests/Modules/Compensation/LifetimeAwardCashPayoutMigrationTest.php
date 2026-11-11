<?php

declare(strict_types=1);

use App\Modules\Compliance\Models\AuditLog;
use App\Modules\Identity\Models\Distributor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    disableTestForeignKeys();
});

function convertToCashAwardMigration(): mixed
{
    return require base_path(
        'app/Modules/Compensation/Database/Migrations/2026_11_11_100200_convert_lifetime_award_milestones_to_single_cash_award.php'
    );
}

/** @param  array<string, mixed>  $overrides */
function legacyAwardMilestone(array $overrides): int
{
    // achieved_month null: a row written before 2026_11_11_100100.
    return DB::table('lifetime_award_milestones')->insertGetId(array_merge([
        'distributor_id' => Distributor::factory()->create()->id,
        'rank_number' => 3,
        'tranche' => 1,
        'amount_paise' => 4_860_000,
        'triggered_month' => '2026-08-01',
        'qualification_count' => 1,
        'award_description' => 'Emerald Partner — tranche A, merchandise per plan',
        'status' => 'pending',
        'created_at' => now(),
        'updated_at' => now(),
    ], $overrides));
}

it('adds the cash award columns, the credited status and the awards line column', function (): void {
    expect(Schema::hasColumns('lifetime_award_milestones', ['achieved_month', 'rank_qualification_id', 'personal_bv_paise', 'left_genos_bv_paise', 'right_genos_bv_paise', 'credited_at']))->toBeTrue()
        ->and(Schema::hasColumn('payout_line_items', 'awards_gross_paise'))->toBeTrue();

    $id = legacyAwardMilestone(['status' => 'credited']);
    expect(DB::table('lifetime_award_milestones')->where('id', $id)->value('status'))->toBe('credited');
});

it('cancels every pending merchandise milestone, keeps delivered ones and backfills the achieved month, with an audit row', function (): void {
    $trancheA = legacyAwardMilestone([]);
    $trancheB = legacyAwardMilestone(['tranche' => 2, 'amount_paise' => 5_940_000]);
    $delivered = legacyAwardMilestone(['status' => 'delivered', 'delivered_at' => now()]);

    convertToCashAwardMigration()->up();

    $row = fn (int $id): object => DB::table('lifetime_award_milestones')->where('id', $id)->first();

    expect($row($trancheA)->status)->toBe('cancelled')
        ->and($row($trancheA)->notes)->toContain('never retrospectively')
        ->and($row($trancheB)->status)->toBe('cancelled')
        ->and($row($trancheB)->notes)->toContain('Tranche model retired')
        ->and($row($delivered)->status)->toBe('delivered')
        ->and((string) $row($delivered)->achieved_month)->toBe('2026-08-01')
        ->and((string) $row($trancheA)->achieved_month)->toBe('2026-08-01');

    $audit = AuditLog::where('action', 'plan.migration.convert_lifetime_award_milestones_to_single_cash_award')->orderByDesc('id')->firstOrFail();
    expect($audit->details['rows_cancelled'])->toBe(2)
        ->and(array_column($audit->details['cancelled'], 'id'))->toBe([$trancheA, $trancheB]);
});

it('refuses to guess the merchandise rows back on rollback', function (): void {
    expect(fn () => convertToCashAwardMigration()->down())
        ->toThrow(RuntimeException::class, 'restore from the plan.migration.* audit row');
});
