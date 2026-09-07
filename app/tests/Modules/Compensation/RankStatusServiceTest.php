<?php

declare(strict_types=1);

use App\Modules\Compensation\Models\RankQualification;
use App\Modules\Compensation\Models\RepurchaseCycle;
use App\Modules\Compensation\Services\CompensationPlanSettingsService;
use App\Modules\Compensation\Services\RankStatusService;
use App\Modules\Identity\Models\Distributor;
use App\Modules\Shared\Features\RepurchaseEngineFeature;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Laravel\Pennant\Feature;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    disableTestForeignKeys();
});

function seedStatusRankQualification(int $distributorId, int $rank, string $monthStart, int $occurrence = 1): void
{
    DB::table('rank_qualifications')->insert([
        'distributor_id' => $distributorId,
        'rank_number' => $rank,
        'month_start' => $monthStart,
        'occurrence_in_month' => $occurrence,
        'is_carry_forward' => false,
        'status' => RankQualification::STATUS_QUALIFIED,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

it('reports no rank and the Rank-1 conditions for a distributor who has never qualified', function (): void {
    $dist = Distributor::factory()->create();

    $status = app(RankStatusService::class)->forDistributor($dist);

    expect($status->currentRank)->toBeNull()
        ->and($status->highestRank)->toBeNull()
        ->and($status->qualifiedThisMonth)->toBeFalse()
        ->and($status->nextRank)->toBe(1)
        ->and($status->allNextRequirementsMet())->toBeFalse();

    $labels = array_map(fn ($r) => $r->label, $status->nextRequirements);
    expect($labels)->toContain('Left Genos BV this month')
        ->and($labels)->toContain('Right Genos BV this month');
});

it('measures the Rank-1 group BV conditions against this month accumulated Genos BV', function (): void {
    $dist = Distributor::factory()->create();
    $plan = app(CompensationPlanSettingsService::class);
    $required = $plan->rankGroupBvRequired(1);

    // Two days inside the current month — the requirement reads the month sum.
    $monthStart = Carbon::today('Asia/Kolkata')->startOfMonth();
    DB::table('group_bv_daily')->insert([
        ['distributor_id' => $dist->id, 'date' => $monthStart->toDateString(), 'left_bv_paise' => (int) ($required / 2), 'right_bv_paise' => 0],
        ['distributor_id' => $dist->id, 'date' => $monthStart->copy()->addDay()->toDateString(), 'left_bv_paise' => (int) ($required / 2), 'right_bv_paise' => 0],
    ]);

    $status = app(RankStatusService::class)->forDistributor($dist);

    $left = collect($status->nextRequirements)->firstWhere('label', 'Left Genos BV this month');
    $right = collect($status->nextRequirements)->firstWhere('label', 'Right Genos BV this month');

    expect($left->current)->toBe($required)
        ->and($left->met())->toBeTrue()
        ->and($right->current)->toBe(0)
        ->and($right->met())->toBeFalse();
});

it('holds the rank achieved last month as the current rank while this month is still accumulating', function (): void {
    $dist = Distributor::factory()->create();
    $monthStart = Carbon::today('Asia/Kolkata')->startOfMonth();

    seedStatusRankQualification((int) $dist->id, 1, $monthStart->copy()->subMonth()->toDateString());

    $status = app(RankStatusService::class)->forDistributor($dist);

    expect($status->currentRank)->toBe(1)
        ->and($status->highestRank)->toBe(1)
        ->and($status->qualifiedThisMonth)->toBeFalse()
        ->and($status->thisMonthRank)->toBeNull()
        ->and($status->achievementCounts)->toBe([1 => 1])
        ->and($status->nextRank)->toBe(2);
});

it('counts every qualified occurrence and opens the next rank above the highest achieved', function (): void {
    $dist = Distributor::factory()->create();
    $monthStart = Carbon::today('Asia/Kolkata')->startOfMonth();

    seedStatusRankQualification((int) $dist->id, 1, $monthStart->copy()->subMonths(2)->toDateString());
    seedStatusRankQualification((int) $dist->id, 1, $monthStart->toDateString());
    seedStatusRankQualification((int) $dist->id, 2, $monthStart->toDateString());

    $status = app(RankStatusService::class)->forDistributor($dist);

    expect($status->achievementCounts)->toBe([1 => 2, 2 => 1])
        ->and($status->totalAchievements())->toBe(3)
        ->and($status->currentRank)->toBe(2)
        ->and($status->thisMonthRank)->toBe(2)
        ->and($status->nextRank)->toBe(3);

    // Rank 3 is structural: the prior rank's Q-Period plus qualified partners
    // on each Genos side.
    $labels = array_map(fn ($r) => $r->label, $status->nextRequirements);
    expect(implode('|', $labels))->toContain('Left Genos')
        ->and(implode('|', $labels))->toContain('Right Genos');
});

it('exposes the two ID-card rank labels without measuring the next rank', function (): void {
    $dist = Distributor::factory()->create();
    $monthStart = Carbon::today('Asia/Kolkata')->startOfMonth();
    seedStatusRankQualification((int) $dist->id, 1, $monthStart->toDateString());

    $labels = app(RankStatusService::class)->labelsFor((int) $dist->id);
    $rankOneName = app(CompensationPlanSettingsService::class)->rankName(1);

    expect($labels['current'])->toBe($rankOneName)
        ->and($labels['highest'])->toBe($rankOneName);

    // Nothing achieved → both labels are null, so the card renders "—".
    expect(app(RankStatusService::class)->labelsFor((int) Distributor::factory()->create()->id))
        ->toBe(['current' => null, 'highest' => null]);
});

it('shows the counted Genos BV the qualification run uses, not the raw month sum', function (): void {
    // Client spec 2026-09-07 §2.2 — the rank progress page must measure the
    // month exactly as the qualification run will: days the distributor was
    // failed on their repurchase cycle are forfeited and never shown as
    // progress toward the rank.
    Feature::for(null)->activate(RepurchaseEngineFeature::class);

    $dist = Distributor::factory()->create();
    $required = app(CompensationPlanSettingsService::class)->rankGroupBvRequired(1);
    $monthStart = Carbon::today('Asia/Kolkata')->startOfMonth();

    DB::table('group_bv_daily')->insert([
        ['distributor_id' => $dist->id, 'date' => $monthStart->toDateString(), 'left_bv_paise' => $required, 'right_bv_paise' => 0],
        ['distributor_id' => $dist->id, 'date' => $monthStart->copy()->addDays(1)->toDateString(), 'left_bv_paise' => 5_000_000, 'right_bv_paise' => 0],
    ]);

    // Due on the 1st, fulfilled on the 3rd: the 2nd is forfeited.
    RepurchaseCycle::create([
        'distributor_id' => $dist->id,
        'cycle_start_date' => $monthStart->copy()->subDays(30)->toDateString(),
        'due_date' => $monthStart->toDateString(),
        'required_bv_paise' => 60_000,
        'completed_bv_paise' => 60_000,
        'status' => RepurchaseCycle::STATUS_COMPLETED,
        'fulfilled_on' => $monthStart->copy()->addDays(2)->toDateString(),
        'resolved_at' => $monthStart->copy()->addDay()->toDateString().' 00:05:00',
    ]);

    $status = app(RankStatusService::class)->forDistributor($dist);
    $left = collect($status->nextRequirements)->firstWhere('label', 'Left Genos BV this month');

    expect($left->current)->toBe($required)
        ->and($left->met())->toBeTrue();
});

it('counts forfeited days only up to today, never to month end, while a cycle is still unresolved', function (): void {
    // An unresolved cycle has an OPEN forfeited window. forfeitedDayRanges()
    // clamps it to whatever end it is given, so asking for month end would tell
    // a distributor on the 7th that 29 days "were not counted" — 23 of which
    // have not happened. That is a projection, not a fact (hard rule 3).
    Carbon::setTestNow('2026-09-07 12:00:00');
    Feature::for(null)->activate(RepurchaseEngineFeature::class);

    $dist = Distributor::factory()->create();

    // Due 1 Sep, never met: 2–7 September are forfeited so far — six days.
    RepurchaseCycle::create([
        'distributor_id' => $dist->id,
        'cycle_start_date' => '2026-08-02',
        'due_date' => '2026-09-01',
        'required_bv_paise' => 60_000,
        'completed_bv_paise' => 0,
        'status' => RepurchaseCycle::STATUS_SUSPENDED,
        'fulfilled_on' => null,
        'failure_reason' => RepurchaseCycle::REASON_BV_SHORT,
        'resolved_at' => '2026-09-02 00:05:00',
    ]);

    $status = app(RankStatusService::class)->forDistributor($dist);

    // 2 Sep through 7 Sep inclusive. September has 30 days, so a range end of
    // month end would have said 29.
    expect($status->forfeitedDaysThisMonth)->toBe(6);
});

it('counts every forfeited day of a past window once the month is over', function (): void {
    // Closed window inside the month, today past its end: nothing is clamped.
    Carbon::setTestNow('2026-09-20 12:00:00');
    Feature::for(null)->activate(RepurchaseEngineFeature::class);

    $dist = Distributor::factory()->create();

    // Due 1 Sep, met on 5 Sep: 2, 3 and 4 September are forfeited.
    RepurchaseCycle::create([
        'distributor_id' => $dist->id,
        'cycle_start_date' => '2026-08-02',
        'due_date' => '2026-09-01',
        'required_bv_paise' => 60_000,
        'completed_bv_paise' => 60_000,
        'status' => RepurchaseCycle::STATUS_COMPLETED,
        'fulfilled_on' => '2026-09-05',
        'resolved_at' => '2026-09-02 00:05:00',
    ]);

    expect(app(RankStatusService::class)->forDistributor($dist)->forfeitedDaysThisMonth)->toBe(3);
});

it('reports no forfeited days while the repurchase engine is off', function (): void {
    Carbon::setTestNow('2026-09-07 12:00:00');
    Feature::for(null)->deactivate(RepurchaseEngineFeature::class);

    $dist = Distributor::factory()->create();
    RepurchaseCycle::create([
        'distributor_id' => $dist->id,
        'cycle_start_date' => '2026-08-02',
        'due_date' => '2026-09-01',
        'required_bv_paise' => 60_000,
        'completed_bv_paise' => 0,
        'status' => RepurchaseCycle::STATUS_SUSPENDED,
        'fulfilled_on' => null,
        'failure_reason' => RepurchaseCycle::REASON_BV_SHORT,
        'resolved_at' => '2026-09-02 00:05:00',
    ]);

    expect(app(RankStatusService::class)->forDistributor($dist)->forfeitedDaysThisMonth)->toBe(0);
});
