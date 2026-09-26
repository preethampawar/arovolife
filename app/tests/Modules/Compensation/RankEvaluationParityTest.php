<?php

declare(strict_types=1);

use App\Modules\Compensation\Models\RankQualification;
use App\Modules\Compensation\Services\RankQualificationService;
use App\Modules\Identity\Models\Distributor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/*
 * Pins checkForMonth()'s written rows so the evaluate/persist split cannot
 * change what the monthly rank check records, and proves evaluateMonth() —
 * the read-only half the progress snapshot uses — names the same qualifiers
 * without writing anything.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    disableTestForeignKeys();
});

function parityPersonalBv(int $distributorId, int $bvPaise, string $at = '2026-06-05 10:00:00'): void
{
    static $order = 970000;
    DB::table('bv_ledger_entries')->insert([
        'distributor_id' => $distributorId,
        'order_id' => $order++,
        'bv_paise' => $bvPaise,
        'type' => 'accrual',
        'effective_at' => $at,
        'created_at' => $at,
        'updated_at' => $at,
    ]);
}

function parityGroupBv(int $distributorId, string $date, int $left, int $right): void
{
    DB::table('group_bv_daily')->insert([
        'distributor_id' => $distributorId,
        'date' => $date,
        'left_bv_paise' => $left,
        'right_bv_paise' => $right,
    ]);
}

function parityPlace(int $parentId, int $childId, string $side): void
{
    DB::table('genealogy_closure')->insertOrIgnore(['ancestor_id' => $childId, 'descendant_id' => $childId, 'depth' => 0]);
    DB::table('genealogy_closure')->insertOrIgnore(['ancestor_id' => $parentId, 'descendant_id' => $parentId, 'depth' => 0]);

    // Every ancestor of the parent becomes an ancestor of the child, one level deeper.
    foreach (DB::table('genealogy_closure')->where('descendant_id', $parentId)->get() as $row) {
        DB::table('genealogy_closure')->insertOrIgnore([
            'ancestor_id' => $row->ancestor_id,
            'descendant_id' => $childId,
            'depth' => $row->depth + 1,
        ]);
    }

    DB::table('distributors')->where('id', $childId)->update([
        'placement_parent_id' => $parentId,
        'placement_side' => $side,
    ]);
}

/**
 * candidate
 *   L: l1 → l2 (both Pearl-level BV)
 *   R: r1 → r2 (both Pearl-level BV)
 * plus a Silver-only distributor s and a candidate own-Pearl match.
 *
 * @return array{candidate: Distributor, silver: Distributor, pearls: list<Distributor>}
 */
function paritySeed(): array
{
    $candidate = Distributor::factory()->create();
    $pearls = Distributor::factory()->count(4)->create()->all();
    [$l1, $l2, $r1, $r2] = $pearls;
    $silver = Distributor::factory()->create();

    parityPlace($candidate->id, $l1->id, 'L');
    parityPlace($l1->id, $l2->id, 'L');
    parityPlace($candidate->id, $r1->id, 'R');
    parityPlace($r1->id, $r2->id, 'R');
    parityPlace($l2->id, $silver->id, 'L');

    parityPersonalBv($candidate->id, 6_000_000);
    parityGroupBv($candidate->id, '2026-06-10', 61_000_000, 61_000_000);

    foreach ($pearls as $p) {
        parityPersonalBv($p->id, 2_000_000);
        parityGroupBv($p->id, '2026-06-10', 61_000_000, 61_000_000);
    }

    parityPersonalBv($silver->id, 700_000);
    parityGroupBv($silver->id, '2026-06-12', 26_000_000, 26_000_000);

    return ['candidate' => $candidate, 'silver' => $silver, 'pearls' => $pearls];
}

/** @return list<string> "distributor:rank:occurrence:status:cf:left:right" */
function parityRows(): array
{
    return RankQualification::query()
        ->orderBy('distributor_id')->orderBy('rank_number')->orderBy('occurrence_in_month')
        ->get()
        ->map(fn (RankQualification $q): string => implode(':', [
            $q->distributor_id, $q->rank_number, $q->occurrence_in_month, $q->status,
            (int) $q->is_carry_forward, $q->left_genos_bv_paise ?? '-', $q->right_genos_bv_paise ?? '-',
        ]))
        ->values()
        ->all();
}

it('records exactly the expected rows for a mixed month', function (): void {
    ['candidate' => $c, 'silver' => $s, 'pearls' => $p] = paritySeed();

    $result = app(RankQualificationService::class)->checkForMonth(Carbon::parse('2026-06-01'));

    expect($result['rank_1_count'])->toBe(6)
        ->and($result['rank_2_count'])->toBe(5)
        ->and($result['rank_3_count'])->toBe(1)
        ->and($result['total_qualifications'])->toBe(12);

    $expected = [];
    foreach ([$c, ...$p] as $d) {
        $expected[] = "{$d->id}:1:1:qualified:0:61000000:61000000";
        $expected[] = "{$d->id}:2:1:qualified:0:61000000:61000000";
    }
    $expected[] = "{$c->id}:3:1:qualified:0:-:-";
    $expected[] = "{$s->id}:1:1:qualified:0:26000000:26000000";
    sort($expected);

    $actual = parityRows();
    sort($actual);

    expect($actual)->toBe($expected);
});

it('keeps counting a stale same-occurrence prior-rank row on a re-run after a reversal', function (): void {
    ['candidate' => $c] = paritySeed();
    $month = Carbon::parse('2026-06-01');
    $svc = app(RankQualificationService::class);

    $svc->checkForMonth($month);

    // The candidate's own Pearl match is reversed away before a re-run of the
    // same occurrence. Their (June, 1, Rank 2) row is never deleted, and it
    // still counts toward Emerald's Q-Period — today's behaviour, preserved.
    DB::table('group_bv_daily')->where('distributor_id', $c->id)->delete();

    $result = $svc->checkForMonth($month);

    expect($result['rank_2_count'])->toBe(4)
        ->and($result['rank_3_count'])->toBe(1)
        ->and(RankQualification::where('distributor_id', $c->id)->where('rank_number', 2)->count())->toBe(1);
});

it('evaluates the same qualifiers checkForMonth records, writing nothing', function (): void {
    paritySeed();
    $month = Carbon::parse('2026-06-01');
    $svc = app(RankQualificationService::class);

    $evaluation = $svc->evaluateMonth($month);

    expect(RankQualification::count())->toBe(0);

    $svc->checkForMonth($month);

    foreach (range(1, 9) as $rank) {
        $recorded = RankQualification::where('rank_number', $rank)->pluck('distributor_id')->map(fn ($id) => (int) $id)->sort()->values()->all();
        $evaluated = $evaluation->qualifierIds[$rank] ?? [];
        sort($evaluated);

        expect($evaluated)->toBe($recorded, "rank {$rank}");
    }
});

it('bounds the evaluation to BV up to and including the through date', function (): void {
    ['silver' => $s] = paritySeed();
    $svc = app(RankQualificationService::class);

    // Silver's only BV day is 12 June: invisible through the 11th, counted through the 12th.
    $before = $svc->evaluateMonth(Carbon::parse('2026-06-01'), through: Carbon::parse('2026-06-11'));
    $after = $svc->evaluateMonth(Carbon::parse('2026-06-01'), through: Carbon::parse('2026-06-12'));

    expect($before->qualifierIds[1] ?? [])->not->toContain($s->id)
        ->and($after->qualifierIds[1] ?? [])->toContain($s->id);
});

it('evaluates the weaker-leg top-up and a second occurrence exactly as they are recorded', function (): void {
    ['silver' => $s] = paritySeed();
    $month = Carbon::parse('2026-06-01');
    $svc = app(RankQualificationService::class);

    // A weaker-leg top-up case: 10,000 BV short on the right, bridged by
    // this month's personal purchases.
    $topped = Distributor::factory()->create();
    parityPersonalBv($topped->id, 1_500_000, '2026-06-03 10:00:00');
    parityGroupBv($topped->id, '2026-06-11', 26_000_000, 24_000_000);

    $svc->checkForMonth($month);

    foreach ([1, 2] as $occurrence) {
        $evaluation = $svc->evaluateMonth($month, $occurrence);
        $svc->checkForMonth($month, $occurrence);

        foreach (range(1, 9) as $rank) {
            $recorded = RankQualification::where('rank_number', $rank)->where('occurrence_in_month', $occurrence)
                ->pluck('distributor_id')->map(fn ($id) => (int) $id)->sort()->values()->all();
            $evaluated = $evaluation->qualifierIds[$rank] ?? [];
            sort($evaluated);

            expect($evaluated)->toBe($recorded, "occurrence {$occurrence}, rank {$rank}");
        }
    }

    expect(RankQualification::where('distributor_id', $topped->id)->where('rank_number', 1)->exists())->toBeTrue();
});
