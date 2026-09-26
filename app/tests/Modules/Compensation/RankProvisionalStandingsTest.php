<?php

declare(strict_types=1);

use App\Modules\Compensation\Models\EngineRun;
use App\Modules\Compensation\Models\RankProvisionalStanding;
use App\Modules\Compensation\Models\RankQualification;
use App\Modules\Compensation\Services\EngineStatusService;
use App\Modules\Compensation\Services\RankProvisionalStandingService;
use App\Modules\Compensation\Services\RankStatusService;
use App\Modules\Identity\Models\Distributor;
use App\Modules\Shared\Features\GenosSalesBonusFeature;
use App\Modules\Shared\Features\RankBonusFeature;
use App\Modules\Shared\Features\RankProgressSnapshotFeature;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Laravel\Pennant\Feature;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    disableTestForeignKeys();
    $this->travelTo(Carbon::parse('2026-06-15 10:00:00', 'Asia/Kolkata'));
    Feature::for(null)->activate(RankProgressSnapshotFeature::class);
    Feature::for(null)->activate(RankBonusFeature::class);
    Feature::for(null)->deactivate(GenosSalesBonusFeature::class);
});

function provPersonalBv(int $distributorId, int $bvPaise, string $at = '2026-06-02 10:00:00'): void
{
    static $order = 980000;
    DB::table('bv_ledger_entries')->insert([
        'distributor_id' => $distributorId, 'order_id' => $order++, 'bv_paise' => $bvPaise,
        'type' => 'accrual', 'effective_at' => $at, 'created_at' => $at, 'updated_at' => $at,
    ]);
}

function provGroupBv(int $distributorId, string $date, int $left, int $right): void
{
    DB::table('group_bv_daily')->insert([
        'distributor_id' => $distributorId, 'date' => $date, 'left_bv_paise' => $left, 'right_bv_paise' => $right,
    ]);
}

function provPlace(int $parentId, int $childId, string $side): void
{
    DB::table('genealogy_closure')->insertOrIgnore(['ancestor_id' => $childId, 'descendant_id' => $childId, 'depth' => 0]);
    DB::table('genealogy_closure')->insertOrIgnore(['ancestor_id' => $parentId, 'descendant_id' => $parentId, 'depth' => 0]);
    foreach (DB::table('genealogy_closure')->where('descendant_id', $parentId)->get() as $row) {
        DB::table('genealogy_closure')->insertOrIgnore([
            'ancestor_id' => $row->ancestor_id, 'descendant_id' => $childId, 'depth' => $row->depth + 1,
        ]);
    }
    DB::table('distributors')->where('id', $childId)->update(['placement_parent_id' => $parentId, 'placement_side' => $side]);
}

/**
 * A candidate who has achieved Pearl (Rank 2) before, with two members per
 * Genos group meeting Pearl's conditions so far in June.
 *
 * @return array{candidate: Distributor, pearls: list<Distributor>}
 */
function provEmeraldCandidate(): array
{
    $candidate = Distributor::factory()->create();
    $pearls = Distributor::factory()->count(4)->create()->all();
    [$l1, $l2, $r1, $r2] = $pearls;

    provPlace($candidate->id, $l1->id, 'L');
    provPlace($l1->id, $l2->id, 'L');
    provPlace($candidate->id, $r1->id, 'R');
    provPlace($r1->id, $r2->id, 'R');

    provPersonalBv($candidate->id, 6_000_000);
    DB::table('rank_qualifications')->insert([
        'distributor_id' => $candidate->id, 'rank_number' => 2, 'month_start' => '2026-05-01',
        'occurrence_in_month' => 1, 'is_carry_forward' => false, 'status' => RankQualification::STATUS_QUALIFIED,
        'created_at' => now(), 'updated_at' => now(),
    ]);

    foreach ($pearls as $p) {
        provPersonalBv($p->id, 2_000_000);
        provGroupBv($p->id, '2026-06-10', 61_000_000, 61_000_000);
    }

    return ['candidate' => $candidate, 'pearls' => $pearls];
}

function partnerRow(Distributor $candidate, string $side): ?object
{
    $status = app(RankStatusService::class)->forDistributor($candidate->fresh());

    return collect($status->nextRequirements)->first(fn ($r) => str_ends_with($r->label, "— {$side} Genos"));
}

// ── The command ──────────────────────────────────────────────────────────────

it('snapshots yesterday, records no rank and moves no money', function (): void {
    provEmeraldCandidate();

    $this->artisan('rank:provisional-standings', ['--date' => '2026-06-14'])->assertSuccessful();

    expect(RankProvisionalStanding::where('rank_number', 2)->count())->toBe(4)
        ->and(RankProvisionalStanding::where('rank_number', 3)->count())->toBe(1)
        ->and(RankProvisionalStanding::query()->value('as_of_date')->toDateString())->toBe('2026-06-14')
        ->and(RankQualification::where('month_start', '2026-06-01')->count())->toBe(0)
        ->and(DB::table('wallet_ledger_entries')->count())->toBe(0);

    $run = EngineRun::where('engine_key', 'rank.provisional-standings')->latest('id')->first();
    expect($run->status)->toBe(EngineRun::STATUS_SUCCEEDED)
        ->and($run->period_start->toDateString())->toBe('2026-06-14');
});

it('records a skipped run and writes nothing while its flag is off', function (): void {
    Feature::for(null)->deactivate(RankProgressSnapshotFeature::class);
    provEmeraldCandidate();

    $this->artisan('rank:provisional-standings', ['--date' => '2026-06-14'])->assertSuccessful();

    expect(RankProvisionalStanding::count())->toBe(0);
    $run = EngineRun::where('engine_key', 'rank.provisional-standings')->latest('id')->first();
    expect($run->status)->toBe(EngineRun::STATUS_SKIPPED)
        ->and(json_decode((string) $run->getRawOriginal('summary'), true)['reason'] ?? null)->toBe('feature_flag_off');
});

it('fails loudly while the day\'s GSB cut-off has not succeeded, and runs once it has', function (): void {
    Feature::for(null)->activate(GenosSalesBonusFeature::class);
    provEmeraldCandidate();

    $this->artisan('rank:provisional-standings', ['--date' => '2026-06-14'])->assertFailed();

    expect(RankProvisionalStanding::count())->toBe(0)
        ->and(EngineRun::where('engine_key', 'rank.provisional-standings')->latest('id')->value('status'))
        ->toBe(EngineRun::STATUS_FAILED);

    EngineRun::create([
        'engine_key' => 'gsb.daily-cutoff', 'period_start' => '2026-06-14', 'status' => EngineRun::STATUS_SUCCEEDED,
        'trigger' => 'console', 'started_at' => now(), 'finished_at' => now(),
    ]);

    $this->artisan('rank:provisional-standings', ['--date' => '2026-06-14'])->assertSuccessful();
    expect(RankProvisionalStanding::count())->toBeGreaterThan(0);
});

it('refuses a day that has not ended', function (): void {
    $this->artisan('rank:provisional-standings', ['--date' => '2026-06-15'])->assertFailed();

    expect(RankProvisionalStanding::count())->toBe(0);
});

it('drops a member whose BV was reversed by the next snapshot, and keeps one month only', function (): void {
    ['pearls' => $pearls] = provEmeraldCandidate();
    $svc = app(RankProvisionalStandingService::class);

    // A May standing (personal BV dated in May too — the snapshot bounds it).
    provPersonalBv($pearls[0]->id, 2_000_000, '2026-05-02 10:00:00');
    provGroupBv($pearls[0]->id, '2026-05-20', 61_000_000, 61_000_000);
    $svc->snapshot(Carbon::parse('2026-05-31'));
    expect(RankProvisionalStanding::where('month_start', '2026-05-01')->exists())->toBeTrue();

    $svc->snapshot(Carbon::parse('2026-06-14'));
    expect(RankProvisionalStanding::where('month_start', '2026-05-01')->exists())->toBeFalse()
        ->and(RankProvisionalStanding::where('distributor_id', $pearls[0]->id)->where('rank_number', 2)->exists())->toBeTrue();

    // A refund reverses the member's June Genos BV.
    DB::table('group_bv_daily')->where('distributor_id', $pearls[0]->id)->delete();
    $svc->snapshot(Carbon::parse('2026-06-14'));

    expect(RankProvisionalStanding::where('distributor_id', $pearls[0]->id)->exists())->toBeFalse();
});

it('measures only BV up to the as-of day', function (): void {
    ['pearls' => $pearls] = provEmeraldCandidate();

    // All the Pearls' BV is dated 10 June.
    app(RankProvisionalStandingService::class)->snapshot(Carbon::parse('2026-06-09'));

    expect(RankProvisionalStanding::where('distributor_id', $pearls[0]->id)->exists())->toBeFalse();
});

// ── The readers ──────────────────────────────────────────────────────────────

it('shows live partner counts with the as-of date instead of zero mid-month', function (): void {
    ['candidate' => $candidate] = provEmeraldCandidate();
    app(RankProvisionalStandingService::class)->snapshot(Carbon::parse('2026-06-14'));

    $status = app(RankStatusService::class)->forDistributor($candidate->fresh());

    expect($status->nextRank)->toBe(3)
        ->and($status->progressSnapshotOn)->toBeTrue()
        ->and($status->progressAsOf?->toDateString())->toBe('2026-06-14')
        ->and(partnerRow($candidate, 'Left')->current)->toBe(2)
        ->and(partnerRow($candidate, 'Right')->current)->toBe(2)
        ->and(partnerRow($candidate, 'Left')->note)->toContain('not a rank');
});

it('keeps recorded-only counts and no note while the flag is off', function (): void {
    ['candidate' => $candidate] = provEmeraldCandidate();
    app(RankProvisionalStandingService::class)->snapshot(Carbon::parse('2026-06-14'));
    Feature::for(null)->deactivate(RankProgressSnapshotFeature::class);

    $status = app(RankStatusService::class)->forDistributor($candidate->fresh());

    expect($status->progressSnapshotOn)->toBeFalse()
        ->and($status->progressAsOf)->toBeNull()
        ->and(partnerRow($candidate, 'Left')->current)->toBe(0);
});

it('never turns a provisional standing into a rank label for the distributor or their upline', function (): void {
    ['candidate' => $candidate] = provEmeraldCandidate();
    app(RankProvisionalStandingService::class)->snapshot(Carbon::parse('2026-06-14'));
    expect(RankProvisionalStanding::where('distributor_id', $candidate->id)->where('rank_number', 3)->exists())->toBeTrue();

    $status = app(RankStatusService::class)->forDistributor($candidate->fresh());
    $labels = app(RankStatusService::class)->labelsFor((int) $candidate->id);

    // Emerald met provisionally, but only the recorded May Pearl is a rank.
    expect($status->thisMonthRank)->toBeNull()
        ->and($status->qualifiedThisMonth)->toBeFalse()
        ->and($status->currentRank)->toBe(2)
        ->and($labels['current'])->not->toContain('Emerald')
        ->and($labels['highest'])->not->toContain('Emerald');
});

it('counts only the distributor\'s own groups, never someone else\'s', function (): void {
    provEmeraldCandidate();

    // A second Pearl-holder outside that tree: the snapshot's four Pearls are
    // not in either of their groups.
    $outsider = Distributor::factory()->create();
    provPersonalBv($outsider->id, 6_000_000);
    DB::table('rank_qualifications')->insert([
        'distributor_id' => $outsider->id, 'rank_number' => 2, 'month_start' => '2026-05-01',
        'occurrence_in_month' => 1, 'is_carry_forward' => false, 'status' => RankQualification::STATUS_QUALIFIED,
        'created_at' => now(), 'updated_at' => now(),
    ]);
    app(RankProvisionalStandingService::class)->snapshot(Carbon::parse('2026-06-14'));

    expect(partnerRow($outsider, 'Left')->current)->toBe(0)
        ->and(partnerRow($outsider, 'Right')->current)->toBe(0);
});

it('never rolls the snapshot back to an older day, and records the older run as skipped', function (): void {
    provEmeraldCandidate();

    $this->artisan('rank:provisional-standings', ['--date' => '2026-06-14'])->assertSuccessful();
    $this->artisan('rank:provisional-standings', ['--date' => '2026-06-12'])->assertSuccessful();

    expect(RankProvisionalStanding::query()->max('as_of_date'))->toBe('2026-06-14')
        ->and(EngineRun::where('engine_key', 'rank.provisional-standings')->latest('id')->value('status'))
        ->toBe(EngineRun::STATUS_SKIPPED);
});

it('treats an earlier failed day as resolved once a later snapshot succeeds', function (): void {
    Feature::for(null)->activate(GenosSalesBonusFeature::class);
    provEmeraldCandidate();

    // 13 June's cut-off never succeeded: that night's snapshot failed.
    $this->artisan('rank:provisional-standings', ['--date' => '2026-06-13'])->assertFailed();
    expect(app(EngineStatusService::class)->unresolvedFailures()->pluck('engine_key'))
        ->toContain('rank.provisional-standings');

    EngineRun::create([
        'engine_key' => 'gsb.daily-cutoff', 'period_start' => '2026-06-14', 'status' => EngineRun::STATUS_SUCCEEDED,
        'trigger' => 'console', 'started_at' => now(), 'finished_at' => now(),
    ]);
    $this->travel(1)->minutes();
    $this->artisan('rank:provisional-standings', ['--date' => '2026-06-14'])->assertSuccessful();

    expect(app(EngineStatusService::class)->unresolvedFailures()->pluck('engine_key'))
        ->not->toContain('rank.provisional-standings');
});

it('says "as of" only when the partner counts come from the snapshot', function (): void {
    ['candidate' => $candidate] = provEmeraldCandidate();
    $fresh = Distributor::factory()->create();
    app(RankProvisionalStandingService::class)->snapshot(Carbon::parse('2026-06-14'));

    $service = app(RankStatusService::class);

    expect($service->forDistributor($candidate->fresh())->showsSnapshotCounts())->toBeTrue()
        ->and($service->forDistributor($fresh)->showsSnapshotCounts())->toBeFalse();
});
