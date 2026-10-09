<?php

declare(strict_types=1);

use App\Modules\Compensation\Models\LifetimeAwardMilestone;
use App\Modules\Compliance\Models\AuditLog;
use App\Modules\Identity\Models\Distributor;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    disableTestForeignKeys();
});

function createLifetimeAwardTranchesMigration(): mixed
{
    return require base_path(
        'app/Modules/Compensation/Database/Migrations/2026_10_09_100800_create_lifetime_award_tranches_table.php'
    );
}

function addTrancheToMilestonesMigration(): mixed
{
    return require base_path(
        'app/Modules/Compensation/Database/Migrations/2026_10_09_100900_add_tranche_to_lifetime_award_milestones.php'
    );
}

function replaceLifetimeAwardCatalogueMigration(): mixed
{
    return require base_path(
        'app/Modules/Compensation/Database/Migrations/2026_10_09_100950_replace_lifetime_award_catalogue.php'
    );
}

/**
 * Replace a rank's catalogue rows with the given [item, worth_paise] list in sort order.
 *
 * @param  list<array{0: string, 1: int}>  $items
 */
function seedRankCatalogue(int $rank, array $items): void
{
    DB::table('lifetime_award_rewards')->where('rank_number', $rank)->delete();

    foreach ($items as $sort => [$item, $worth]) {
        DB::table('lifetime_award_rewards')->insert([
            'rank_number' => $rank, 'sort_order' => $sort, 'item' => $item, 'worth_paise' => $worth,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }
}

/**
 * The audit row of the run the test itself made — the latest one, because
 * RefreshDatabase already ran the migration once.
 *
 * @return array<string, mixed>
 */
function lifetimeAwardMigrationAudit(string $action): array
{
    return (array) AuditLog::query()->where('action', $action)->orderByDesc('id')->firstOrFail()->details;
}

/** Put lifetime_award_milestones back to its pre-100900 shape so up() can run again. */
function revertMilestonesToSingleAwardShape(): void
{
    Schema::table('lifetime_award_milestones', function (Blueprint $table): void {
        $table->unique(['distributor_id', 'rank_number'], 'uq_lifetime_award_dist_rank');
    });
    Schema::table('lifetime_award_milestones', function (Blueprint $table): void {
        $table->dropUnique('uq_award_milestone_dist_rank_tranche');
    });
    Schema::table('lifetime_award_milestones', function (Blueprint $table): void {
        $table->dropColumn(['tranche', 'amount_paise', 'released_rule_changed_at']);
    });
}

/** A milestone row in the pre-100900 shape. */
function legacyMilestone(int $rank, int $qualifications, string $status): int
{
    return (int) DB::table('lifetime_award_milestones')->insertGetId([
        'distributor_id' => Distributor::factory()->create()->id,
        'rank_number' => $rank,
        'triggered_month' => '2026-08-01',
        'qualification_count' => $qualifications,
        'award_description' => 'Legacy award',
        'status' => $status,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

it('creates the tranche table with the nine ranks seeded and moves only budgets still on the old default', function (): void {
    Schema::dropIfExists('lifetime_award_tranches');
    DB::table('rank_tiers')->where('rank_number', 4)->update(['lifetime_award_budget_paise' => 36_500_000]); // old default
    DB::table('rank_tiers')->where('rank_number', 5)->update(['lifetime_award_budget_paise' => 77_700_000]); // admin override

    createLifetimeAwardTranchesMigration()->up();

    expect(DB::table('lifetime_award_tranches')->count())->toBe(20)
        ->and((int) DB::table('lifetime_award_tranches')->where('rank_number', 9)->where('tranche', 3)->value('amount_paise'))->toBe(5_000_040_000)
        ->and((int) DB::table('rank_tiers')->where('rank_number', 4)->value('lifetime_award_budget_paise'))->toBe(32_400_000)
        ->and((int) DB::table('rank_tiers')->where('rank_number', 5)->value('lifetime_award_budget_paise'))->toBe(77_700_000);

    $budgets = collect(lifetimeAwardMigrationAudit('plan.migration.lifetime_award_tranches')['budgets'])->keyBy('rank');
    expect($budgets[4])->toEqual(['rank' => 4, 'moved' => true, 'before' => 36_500_000, 'after' => 32_400_000])
        ->and($budgets[5])->toEqual(['rank' => 5, 'moved' => false, 'before' => 77_700_000, 'after' => 77_700_000]);
});

it('backfills tranche 1 amounts and flags pending rows the per-tranche rule made releasable', function (): void {
    revertMilestonesToSingleAwardShape();

    $gold = legacyMilestone(rank: 4, qualifications: 1, status: 'pending');      // old rule: needs 2 → now releasable
    $silver = legacyMilestone(rank: 1, qualifications: 1, status: 'pending');    // releasable under both rules
    $blue = legacyMilestone(rank: 6, qualifications: 1, status: 'delivered');    // amount only: no gross → tranche A
    $royal = legacyMilestone(rank: 7, qualifications: 1, status: 'cancelled');   // amount only
    $crown = legacyMilestone(rank: 8, qualifications: 1, status: 'delivered');   // amount only: keeps its gross
    DB::table('lifetime_award_milestones')->where('id', $crown)->update(['gross_paise' => 140_000_000, 'disbursement_type' => 'goods']);

    addTrancheToMilestonesMigration()->up();

    $rows = LifetimeAwardMilestone::query()->get()->keyBy('id');

    expect($rows[$gold]->tranche)->toBe(1)
        ->and($rows[$gold]->amount_paise)->toBe(14_580_000)
        ->and($rows[$gold]->released_rule_changed_at)->not->toBeNull()
        ->and($rows[$gold]->status)->toBe('pending');
    expect($rows[$silver]->amount_paise)->toBe(1_540_000)
        ->and($rows[$silver]->released_rule_changed_at)->toBeNull();
    expect($rows[$blue]->amount_paise)->toBe(84_780_000)
        ->and($rows[$blue]->released_rule_changed_at)->toBeNull()
        ->and($rows[$blue]->qualification_count)->toBe(1)
        ->and($rows[$blue]->status)->toBe('delivered');
    expect($rows[$crown]->amount_paise)->toBe(140_000_000)
        ->and($rows[$crown]->released_rule_changed_at)->toBeNull()
        ->and($rows[$crown]->status)->toBe('delivered');
    expect($rows[$royal]->amount_paise)->toBe(245_250_000)
        ->and($rows[$royal]->released_rule_changed_at)->toBeNull()
        ->and($rows[$royal]->status)->toBe('cancelled');

    $audit = lifetimeAwardMigrationAudit('plan.migration.add_tranche_to_lifetime_award_milestones');
    expect($audit['rows_backfilled'])->toBe(3)
        ->and($audit['rows_release_rule_changed'])->toBe(1)
        ->and(array_column($audit['changed'], 'id'))->toEqualCanonicalizing([$gold, $silver, $royal])
        ->and($audit['rows_delivered_amount_recorded'])->toBe(2)
        ->and(collect($audit['delivered_amount_recorded'])->keyBy('id')->map(fn (array $e): array => $e['after'])->all())->toEqual([
            $blue => ['amount_paise' => 84_780_000, 'source' => 'tranche_a'],
            $crown => ['amount_paise' => 140_000_000, 'source' => 'gross_paise'],
        ]);

    $goldEntry = collect($audit['changed'])->firstWhere('id', $gold);
    expect($goldEntry['before'])->toEqual(['amount_paise' => 0, 'releasable' => false])
        ->and($goldEntry['after']['amount_paise'])->toBe(14_580_000)
        ->and($goldEntry['after']['releasable'])->toBeTrue()
        ->and($goldEntry['after']['released_rule_changed'])->toBeTrue();
});

it('lets a distributor hold one milestone per tranche of a rank', function (): void {
    $distributorId = Distributor::factory()->create()->id;

    foreach ([1, 2] as $tranche) {
        LifetimeAwardMilestone::create([
            'distributor_id' => $distributorId, 'rank_number' => 3, 'tranche' => $tranche,
            'triggered_month' => '2026-08-01', 'qualification_count' => $tranche,
            'award_description' => 'x', 'status' => LifetimeAwardMilestone::STATUS_PENDING,
        ]);
    }

    expect(fn () => LifetimeAwardMilestone::create([
        'distributor_id' => $distributorId, 'rank_number' => 3, 'tranche' => 2,
        'triggered_month' => '2026-09-01', 'qualification_count' => 2,
        'award_description' => 'x', 'status' => LifetimeAwardMilestone::STATUS_PENDING,
    ]))->toThrow(UniqueConstraintViolationException::class);
});

it('replaces a rank catalogue still equal to the old seeded list and keeps an admin-edited one', function (): void {
    // Rank 9: exactly the pre-2026-10-09 seeded list → replaced.
    seedRankCatalogue(9, [
        ['4 foreign tickets (10N/11D)', 100_000_000],
        ['Independent villa down payment', 1_350_000_000],
        ['Luxury car down payment', 500_000_000],
        ['Driver salary', 5_000_000],
        ['Office rent', 10_000_000],
        ['PA salary', 5_000_000],
        ['Preloaded debit card', 50_000_000],
        ['Gold', 120_000_000],
        ['Silver', 110_000_000],
    ]);
    // Rank 4: the old list with one worth edited by an admin → left alone.
    seedRankCatalogue(4, [
        ['4 foreign trip tickets (3N/4D)', 20_000_000],
        ['Samsung Tab', 3_500_000],
        ['Gold', 13_500_000],
    ]);

    replaceLifetimeAwardCatalogueMigration()->up();

    expect(DB::table('lifetime_award_rewards')->where('rank_number', 9)->orderBy('sort_order')->pluck('worth_paise')->map(fn ($w): int => (int) $w)->all())
        ->toBe([918_630_000, 956_070_000, 5_000_040_000])
        ->and(DB::table('lifetime_award_rewards')->where('rank_number', 9)->where('sort_order', 0)->value('item'))
        ->toBe('Merchandise, tranche A — items to be specified by the company')
        ->and(DB::table('lifetime_award_rewards')->where('rank_number', 4)->orderBy('sort_order')->pluck('worth_paise')->map(fn ($w): int => (int) $w)->all())
        ->toBe([20_000_000, 3_500_000, 13_500_000]);

    $ranks = collect(lifetimeAwardMigrationAudit('plan.migration.lifetime_award_catalogue')['ranks'])->keyBy('rank');
    expect($ranks)->toHaveCount(9)
        ->and($ranks[9]['moved'])->toBeTrue()
        ->and($ranks[9]['before'])->toHaveCount(9)
        ->and($ranks[9]['before'][1]['item'])->toBe('Independent villa down payment')
        ->and(array_column($ranks[9]['after'], 'worth_paise'))->toBe([918_630_000, 956_070_000, 5_000_040_000])
        ->and($ranks[4]['moved'])->toBeFalse()
        ->and($ranks[4]['after'])->toEqual($ranks[4]['before'])
        // Rank 1 holds the new placeholder from the seeder, not the old list.
        ->and($ranks[1]['moved'])->toBeFalse();
});

it('refuses to guess on rollback', function (): void {
    expect(fn () => createLifetimeAwardTranchesMigration()->down())
        ->toThrow(RuntimeException::class, 'restore from the plan.migration.* audit row');
    expect(fn () => addTrancheToMilestonesMigration()->down())
        ->toThrow(RuntimeException::class, 'restore from the plan.migration.* audit row');
    expect(fn () => replaceLifetimeAwardCatalogueMigration()->down())
        ->toThrow(RuntimeException::class, 'restore from the plan.migration.* audit row');
});
