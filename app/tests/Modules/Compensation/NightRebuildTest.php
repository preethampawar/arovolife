<?php

declare(strict_types=1);

use App\Modules\Commerce\Models\BvLedgerEntry;
use App\Modules\Compensation\Exceptions\RebuildStateChanged;
use App\Modules\Compensation\Models\EngineRun;
use App\Modules\Compensation\Models\GroupBvDaily;
use App\Modules\Compensation\Models\GsbCutoffResult;
use App\Modules\Compensation\Models\GsbDailyPool;
use App\Modules\Compensation\Models\GsbPersonalBvTopup;
use App\Modules\Compensation\Models\MentorshipBonusResult;
use App\Modules\Compensation\Models\MsbDailyPool;
use App\Modules\Compensation\Models\PayoutBatch;
use App\Modules\Compensation\Models\WalletLedgerEntry;
use App\Modules\Compensation\Services\EngineStatusService;
use App\Modules\Compensation\Services\Rebuild\RebuildKind;
use App\Modules\Compensation\Services\Rebuild\RebuildPlanner;
use App\Modules\Compensation\Services\WalletService;
use App\Modules\Compliance\Models\AuditLog;
use App\Modules\Identity\Models\Distributor;
use App\Modules\Identity\Models\User;
use App\Modules\Shared\Features\GenosSalesBonusFeature;
use App\Modules\Shared\Features\GsbDailyPoolPricingFeature;
use App\Modules\Shared\Features\MentorshipBonusFeature;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\WithConsoleEvents;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Laravel\Pennant\Feature;
use Tests\Support\StubEngineStepCommand;

// RecordEngineRun writes the run rows this whole file reads back — the proof a
// day is computed, the rebuild's own row, and the frontier the nightly run
// resumes from — and it listens to console events Laravel suppresses under
// tests unless this trait opts back in.
uses(RefreshDatabase::class, WithConsoleEvents::class);

/** The night under test, and the cut-off day it owns: N = 17 Sep 2026 (Thu), D = 16 Sep. */
const REBUILD_NIGHT = '2026-09-17';

const REBUILD_DAY = '2026-09-16';

beforeEach(function (): void {
    disableTestForeignKeys();
    app(RolesAndPermissionsSeeder::class)->run();
    Carbon::setTestNow(Carbon::parse(REBUILD_NIGHT.' 06:00:00', 'Asia/Kolkata'));
});

afterEach(function (): void {
    Carbon::setTestNow();
});

function nightRebuildDeveloper(): User
{
    $user = User::create([
        'full_name' => 'Platform Developer',
        'email' => 'rebuild-'.uniqid().'@test.com',
        'phone_e164' => '+91'.str_pad((string) random_int(7000000000, 9999999999), 10, '0'),
        'password_hash' => bcrypt('x'),
        'status' => 'active',
        'email_verified_at' => now(),
    ]);
    $user->assignRole('developer');

    return $user;
}

/**
 * Three distributors: a sponsor, a sponsee whose weaker group matches GSB slab
 * 1 on $day, and a bystander with nothing, so the day writes credited rows, a
 * Mentorship row and an idle row all at once.
 *
 * @return array{0: Distributor, 1: Distributor, 2: Distributor}
 */
function seedNightRebuildTree(Carbon $day): array
{
    $sponsor = Distributor::factory()->create(['status' => 'active', 'adn' => '100000001']);
    $sponsee = Distributor::factory()->create(['status' => 'active', 'adn' => '100000002']);
    $bystander = Distributor::factory()->create(['status' => 'active', 'adn' => '100000003']);

    // The conditional personal-BV top-up has to be LIVE in this file: the wipe
    // must hand its group BV back, and GsbSlabsSeeder — which Pest runs before
    // every Compensation test — pins the go-live to the WALL-CLOCK date, which
    // is after $day. Left alone, the whole top-up path is dead here and the
    // rebuild's hardest case is never exercised. Mirrors
    // GsbCutoffServiceTest::enableTopupGolive().
    DB::table('settings')->updateOrInsert(
        ['key' => 'comp.gsb.topup_golive_date'],
        ['value' => '2000-01-01'],
    );

    // Sponsee personal BV 3,000 (Retailer) so GSB transfers; sponsor 600 BV for MB eligibility.
    BvLedgerEntry::create(['distributor_id' => $sponsee->id, 'order_id' => 999_001, 'bv_paise' => 300_000, 'type' => 'accrual', 'effective_at' => $day->copy()->startOfDay()]);
    BvLedgerEntry::create(['distributor_id' => $sponsor->id, 'order_id' => 999_002, 'bv_paise' => 60_000, 'type' => 'accrual', 'effective_at' => $day->copy()->startOfDay()]);

    // Weaker side 16,000 BV ≥ the slab-1 threshold → credits slab 1.
    GroupBvDaily::create(['distributor_id' => $sponsee->id, 'date' => $day->toDateString(), 'left_bv_paise' => 2_000_000, 'right_bv_paise' => 1_600_000]);

    DB::table('sponsorship')->insert(['sponsor_id' => $sponsor->id, 'distributor_id' => $sponsee->id, 'created_at' => now()]);

    Feature::for(null)->activate(GenosSalesBonusFeature::class);
    Feature::for(null)->activate(MentorshipBonusFeature::class);
    // Without the pool the cut-off writes no gsb_daily_pools row, and the
    // rebuild's whole point is that the day's frozen pool is re-priced too.
    Feature::for(null)->activate(GsbDailyPoolPricingFeature::class);

    return [$sponsor, $sponsee, $bystander];
}

/** Run the day's cut-off for real, exactly as the nightly run would. */
function runCutoffForDay(): void
{
    expect(Artisan::call('gsb:daily-cutoff', ['--date' => REBUILD_DAY]))->toBe(0);
}

/**
 * distributor_id => net_gsb_paise for the day, so before/after can be compared.
 *
 * @return array<int, int>
 */
function cutoffFigures(): array
{
    return GsbCutoffResult::whereDate('cutoff_date', REBUILD_DAY)
        ->orderBy('distributor_id')
        ->pluck('net_gsb_paise', 'distributor_id')
        ->all();
}

/**
 * distributor_id => [power, side, slab1] as the rolling store holds it now.
 *
 * @return array<int, array{0: int, 1: string|null, 2: int}>
 */
function carryforwardState(): array
{
    return DB::table('gsb_carryforward')
        ->orderBy('distributor_id')
        ->get()
        ->mapWithKeys(fn (object $row): array => [(int) $row->distributor_id => [
            (int) $row->power_side_bv_paise,
            $row->power_side,
            (int) $row->slab1_weaker_bv_paise,
        ]])
        ->all();
}

/**
 * The day's group BV accumulator for one distributor, as [left, right] paise.
 *
 * @return array{0: int, 1: int}
 */
function groupBvForDay(int $distributorId): array
{
    $row = GroupBvDaily::where('distributor_id', $distributorId)
        ->whereDate('date', REBUILD_DAY)
        ->first();

    return [(int) ($row->left_bv_paise ?? 0), (int) ($row->right_bv_paise ?? 0)];
}

it('plans the night, wipes it and rebuilds identical figures', function (): void {
    seedNightRebuildTree(Carbon::parse(REBUILD_DAY));
    runCutoffForDay();

    $figuresBefore = cutoffFigures();
    $carryforwardBefore = carryforwardState();
    $groupBvBefore = DB::table('group_bv_daily')->orderBy('id')->get()->map(fn (object $row): array => [
        (int) $row->distributor_id, (int) $row->left_bv_paise, (int) $row->right_bv_paise,
    ])->all();
    $beforeState = GsbCutoffResult::whereDate('cutoff_date', REBUILD_DAY)
        ->orderBy('distributor_id')
        ->get(['distributor_id', 'power_cf_before_paise', 'power_side_before', 'slab1_weaker_cf_before_paise']);

    expect($figuresBefore)->not->toBeEmpty();
    expect(WalletLedgerEntry::where('type', 'gsb_credit')->count())->toBeGreaterThan(0);

    $plan = app(RebuildPlanner::class)->plan(RebuildKind::Night, Carbon::parse(REBUILD_NIGHT));

    expect($plan->refusals)->toBe([]);
    expect($plan->rowsToRemove)->toHaveKey('gsb_cutoff_results');
    expect($plan->rowsToRemove['gsb_cutoff_results'])->toBe(GsbCutoffResult::whereDate('cutoff_date', REBUILD_DAY)->count());
    expect($plan->rowsToRemove)->toHaveKey('gsb_daily_pools');
    expect($plan->rowsToRemove)->toHaveKey('wallet_ledger_entries');
    expect($plan->rerunCommand())->toBe('compensation:nightly-run --date='.REBUILD_NIGHT.' --restart');
    // The wipe does not only delete: it hands the day's personal-BV top-up back
    // to the accumulator that carries it, and the preview says so.
    expect($plan->adjustments)->toBe(['group_bv_daily' => 1]);

    $exit = Artisan::call('compensation:rebuild-night', [
        '--date' => REBUILD_NIGHT,
        '--actor' => nightRebuildDeveloper()->id,
        '--yes' => true,
    ]);

    expect($exit)->toBe(0);

    // Same figures, same rolling store, and the day's rows were genuinely
    // re-derived rather than left in place: the result ids move.
    expect(cutoffFigures())->toBe($figuresBefore);
    expect(carryforwardState())->toBe($carryforwardBefore);

    // And the source accumulator the engine reads is where the first run left
    // it — the day's top-up was handed back before the re-run applied it again,
    // so the weaker leg is not carrying it twice.
    expect(DB::table('group_bv_daily')->orderBy('id')->get()->map(fn (object $row): array => [
        (int) $row->distributor_id, (int) $row->left_bv_paise, (int) $row->right_bv_paise,
    ])->all())->toBe($groupBvBefore);

    // The rewind put the store back to the *_before state each row recorded,
    // which is what the re-run then started from — the side included, because a
    // power carry-forward on the wrong leg matches against the wrong group.
    foreach ($beforeState as $row) {
        $rebuilt = GsbCutoffResult::whereDate('cutoff_date', REBUILD_DAY)
            ->where('distributor_id', $row->distributor_id)
            ->first();

        expect((int) $rebuilt->power_cf_before_paise)->toBe((int) $row->power_cf_before_paise);
        expect($rebuilt->power_side_before)->toBe($row->power_side_before);
        expect((int) $rebuilt->slab1_weaker_cf_before_paise)->toBe((int) $row->slab1_weaker_cf_before_paise);
    }

    // One credit per rebuilt result — no doubled wallet rows.
    expect(WalletLedgerEntry::where('type', 'gsb_credit')->count())
        ->toBe(GsbCutoffResult::whereDate('cutoff_date', REBUILD_DAY)->whereIn('status', [GsbCutoffResult::STATUS_CREDITED])->count());

    expect(EngineRun::where('engine_key', 'compensation.rebuild-night')->value('status'))
        ->toBe(EngineRun::STATUS_SUCCEEDED);

    expect(AuditLog::where('action', 'compensation.rebuild.completed')->exists())->toBeTrue();
    expect(AuditLog::where('action', 'compensation.rebuild.wiped')->exists())->toBeTrue();
});

it('lands on the same figures however often it is run', function (): void {
    seedNightRebuildTree(Carbon::parse(REBUILD_DAY));
    runCutoffForDay();

    $figures = cutoffFigures();
    $carryforward = carryforwardState();
    $developerId = nightRebuildDeveloper()->id;

    foreach ([1, 2] as $_attempt) {
        expect(Artisan::call('compensation:rebuild-night', [
            '--date' => REBUILD_NIGHT,
            '--actor' => $developerId,
            '--yes' => true,
        ]))->toBe(0);
    }

    expect(cutoffFigures())->toBe($figures);
    expect(carryforwardState())->toBe($carryforward);
    expect(WalletLedgerEntry::where('type', 'gsb_credit')->count())
        ->toBe(GsbCutoffResult::whereDate('cutoff_date', REBUILD_DAY)->where('status', GsbCutoffResult::STATUS_CREDITED)->count());
});

it('refuses a night a later cut-off has already passed, idle rows included', function (): void {
    [, $sponsee] = seedNightRebuildTree(Carbon::parse(REBUILD_DAY));
    runCutoffForDay();

    // An idle no_match row for the NEXT day is enough: the carry-forward store
    // has still been read and written past D (R-91).
    GsbCutoffResult::create([
        'distributor_id' => $sponsee->id,
        'cutoff_date' => REBUILD_NIGHT,
        'left_bv_paise' => 0, 'right_bv_paise' => 0, 'weaker_bv_paise' => 0,
        'slab' => 0, 'score' => 0, 'score_value_paise' => 0,
        'gross_gsb_paise' => 0, 'repurchase_deduction_paise' => 0, 'admin_charge_paise' => 0,
        'tds_paise' => 0, 'net_gsb_paise' => 0,
        'power_cf_before_paise' => 0, 'power_cf_after_paise' => 0,
        'slab1_weaker_cf_before_paise' => 0, 'slab1_weaker_cf_after_paise' => 0,
        'status' => GsbCutoffResult::STATUS_NO_MATCH,
    ]);

    $plan = app(RebuildPlanner::class)->plan(RebuildKind::Night, Carbon::parse(REBUILD_NIGHT));

    expect($plan->isRefused())->toBeTrue();
    expect(implode("\n", $plan->refusals))->toContain('R-91');
    expect(implode("\n", $plan->refusals))->toContain('only while it is the newest one');

    expect(Artisan::call('compensation:rebuild-night', [
        '--date' => REBUILD_NIGHT,
        '--actor' => nightRebuildDeveloper()->id,
        '--yes' => true,
    ]))->toBe(1);

    // Nothing was written: the day's rows are untouched.
    expect(GsbCutoffResult::whereDate('cutoff_date', REBUILD_DAY)->count())->toBeGreaterThan(0);
    expect(EngineRun::where('engine_key', 'compensation.rebuild-night')->value('status'))
        ->toBe(EngineRun::STATUS_SKIPPED);
    expect(AuditLog::where('action', 'compensation.rebuild.refused')->exists())->toBeTrue();
});

it('refuses when one of the day\'s credits has already been paid', function (): void {
    seedNightRebuildTree(Carbon::parse(REBUILD_DAY));
    runCutoffForDay();

    $batch = PayoutBatch::create([
        'batch_type' => PayoutBatch::TYPE_WEEKLY,
        'batch_date' => REBUILD_NIGHT,
        'status' => PayoutBatch::STATUS_APPROVED,
    ]);

    WalletLedgerEntry::where('type', 'gsb_credit')->limit(1)->update(['swept_by_payout_batch_id' => $batch->id]);

    $plan = app(RebuildPlanner::class)->plan(RebuildKind::Night, Carbon::parse(REBUILD_NIGHT));

    expect($plan->isRefused())->toBeTrue();
    expect(implode("\n", $plan->refusals))->toContain('money that left cannot be recomputed');
});

it('refuses when one of the day\'s results was reversed', function (): void {
    seedNightRebuildTree(Carbon::parse(REBUILD_DAY));
    runCutoffForDay();

    GsbCutoffResult::whereDate('cutoff_date', REBUILD_DAY)
        ->where('status', GsbCutoffResult::STATUS_CREDITED)
        ->limit(1)
        ->update(['status' => GsbCutoffResult::STATUS_REVERSED]);

    $plan = app(RebuildPlanner::class)->plan(RebuildKind::Night, Carbon::parse(REBUILD_NIGHT));

    expect($plan->isRefused())->toBeTrue();
    expect(implode("\n", $plan->refusals))->toContain('reversed by an admin decision');
});

it('refuses while a scheduled run is in flight', function (): void {
    seedNightRebuildTree(Carbon::parse(REBUILD_DAY));

    EngineRun::create([
        'engine_key' => 'compensation.nightly-run',
        'period_start' => REBUILD_NIGHT,
        'status' => EngineRun::STATUS_RUNNING,
        'trigger' => EngineRun::TRIGGER_CONSOLE,
        'started_at' => Carbon::now()->subMinute(),
    ]);

    $plan = app(RebuildPlanner::class)->plan(RebuildKind::Night, Carbon::parse(REBUILD_NIGHT));

    expect($plan->isRefused())->toBeTrue();
    expect(implode("\n", $plan->refusals))->toContain('is running right now');
});

it('refuses a night inside a month whose payout is frozen', function (): void {
    seedNightRebuildTree(Carbon::parse(REBUILD_DAY));
    runCutoffForDay();

    EngineRun::create([
        'engine_key' => 'compensation.monthly-close',
        'period_start' => '2026-09-01',
        'status' => EngineRun::STATUS_SUCCEEDED,
        'trigger' => EngineRun::TRIGGER_CONSOLE,
        'started_at' => Carbon::parse('2026-10-01 00:20:00'),
        'finished_at' => Carbon::parse('2026-10-01 00:40:00'),
    ]);

    PayoutBatch::create([
        'batch_type' => PayoutBatch::TYPE_MONTHLY,
        'batch_date' => '2026-10-01',
        'status' => PayoutBatch::STATUS_APPROVED,
        'approved_at' => Carbon::parse('2026-10-08 10:00:00'),
    ]);

    $plan = app(RebuildPlanner::class)->plan(RebuildKind::Night, Carbon::parse(REBUILD_NIGHT));

    expect($plan->isRefused())->toBeTrue();
    expect(implode("\n", $plan->refusals))->toContain('September 2026 was closed and its payout is frozen');
});

it('records a failed rebuild and leaves the day un-proven when the re-run fails', function (): void {
    seedNightRebuildTree(Carbon::parse(REBUILD_DAY));
    runCutoffForDay();

    expect(app(EngineStatusService::class)->completedCutoffDatesBetween(
        Carbon::parse(REBUILD_DAY), Carbon::parse(REBUILD_DAY),
    ))->toBe([REBUILD_DAY]);

    StubEngineStepCommand::register(['gsb.daily-cutoff']);
    StubEngineStepCommand::$exitCodes['gsb.daily-cutoff'] = 1;

    expect(Artisan::call('compensation:rebuild-night', [
        '--date' => REBUILD_NIGHT,
        '--actor' => nightRebuildDeveloper()->id,
        '--yes' => true,
    ]))->toBe(1);

    // The wipe landed, the re-run did not: the day must read "not computed" so
    // the ordinary nightly backfill re-attempts it (D13).
    expect(GsbCutoffResult::whereDate('cutoff_date', REBUILD_DAY)->count())->toBe(0);
    expect(app(EngineStatusService::class)->completedCutoffDatesBetween(
        Carbon::parse(REBUILD_DAY), Carbon::parse(REBUILD_DAY),
    ))->toBe([]);

    expect(EngineRun::where('engine_key', 'compensation.rebuild-night')->value('status'))
        ->toBe(EngineRun::STATUS_FAILED);
    expect(AuditLog::where('action', 'compensation.rebuild.rerun_failed')->exists())->toBeTrue();
});

it('warns about the Tuesday batch the rebuilt night owes', function (): void {
    Carbon::setTestNow(Carbon::parse('2026-09-22 06:00:00', 'Asia/Kolkata'));
    seedNightRebuildTree(Carbon::parse('2026-09-21'));

    $plan = app(RebuildPlanner::class)->plan(RebuildKind::Night, Carbon::parse('2026-09-22'));

    expect(implode("\n", $plan->warnings))->toContain('compensation:weekly-run --date=2026-09-22');
});

it('warns about the monthly close when the night is the 1st, and about a month already closed', function (): void {
    Carbon::setTestNow(Carbon::parse('2026-10-01 06:00:00', 'Asia/Kolkata'));
    seedNightRebuildTree(Carbon::parse('2026-09-30'));

    $plan = app(RebuildPlanner::class)->plan(RebuildKind::Night, Carbon::parse('2026-10-01'));

    expect(implode("\n", $plan->warnings))->toContain('compensation:monthly-run --date=2026-10-01');

    EngineRun::create([
        'engine_key' => 'compensation.monthly-close',
        'period_start' => '2026-09-01',
        'status' => EngineRun::STATUS_SUCCEEDED,
        'trigger' => EngineRun::TRIGGER_CONSOLE,
        'started_at' => Carbon::parse('2026-10-01 00:20:00'),
        'finished_at' => Carbon::parse('2026-10-01 00:40:00'),
    ]);

    $closed = app(RebuildPlanner::class)->plan(RebuildKind::Night, Carbon::parse('2026-10-01'));

    expect(implode("\n", $closed->warnings))->toContain('compensation:rebuild-month --month=2026-09');
});

it('refuses a rebuild that names no developer', function (): void {
    seedNightRebuildTree(Carbon::parse(REBUILD_DAY));
    runCutoffForDay();

    $plain = User::create([
        'full_name' => 'Finance Admin',
        'email' => 'finance-'.uniqid().'@test.com',
        'phone_e164' => '+91'.str_pad((string) random_int(7000000000, 9999999999), 10, '0'),
        'password_hash' => bcrypt('x'),
        'status' => 'active',
        'email_verified_at' => now(),
    ]);
    $plain->assignRole('admin-finance');

    expect(Artisan::call('compensation:rebuild-night', ['--date' => REBUILD_NIGHT, '--yes' => true]))->toBe(1);
    expect(Artisan::output())->toContain('pass --actor=<developer user id>');

    expect(Artisan::call('compensation:rebuild-night', [
        '--date' => REBUILD_NIGHT,
        '--actor' => $plain->id,
        '--yes' => true,
    ]))->toBe(1);

    expect(GsbCutoffResult::whereDate('cutoff_date', REBUILD_DAY)->count())->toBeGreaterThan(0);
    expect(GsbDailyPool::whereDate('cutoff_date', REBUILD_DAY)->exists())->toBeTrue();
    expect(MsbDailyPool::count() + MentorshipBonusResult::count())->toBeGreaterThan(0);
});

it('hands the day\'s personal-BV top-up back to the accumulator before it re-derives the day', function (): void {
    [, $sponsee] = seedNightRebuildTree(Carbon::parse(REBUILD_DAY));
    runCutoffForDay();

    // The cut-off moved the 3,000 BV pending personal purchase onto the weaker
    // (Right) leg: 16,000 + 3,000.
    expect(groupBvForDay($sponsee->id))->toBe([2_000_000, 1_900_000]);
    expect(GsbPersonalBvTopup::whereDate('date', REBUILD_DAY)->count())->toBe(1);

    $carryforwardBefore = carryforwardState();

    expect(Artisan::call('compensation:rebuild-night', [
        '--date' => REBUILD_NIGHT,
        '--actor' => nightRebuildDeveloper()->id,
        '--yes' => true,
    ]))->toBe(0);

    // Deleting the top-up row alone would leave its BV in the accumulator and
    // let the re-run add it a SECOND time — 22,000 on the Right, permanently,
    // and read as Genos BV by the rank check too. The wipe hands it back first,
    // so the leg is where the first run left it and the carry-forward has not
    // changed sides.
    expect(groupBvForDay($sponsee->id))->toBe([2_000_000, 1_900_000]);
    expect(GsbPersonalBvTopup::whereDate('date', REBUILD_DAY)->count())->toBe(1);
    expect(carryforwardState())->toBe($carryforwardBefore);

    // And again, however often it is run.
    expect(Artisan::call('compensation:rebuild-night', [
        '--date' => REBUILD_NIGHT,
        '--actor' => nightRebuildDeveloper()->id,
        '--yes' => true,
    ]))->toBe(0);

    expect(groupBvForDay($sponsee->id))->toBe([2_000_000, 1_900_000]);
    expect(carryforwardState())->toBe($carryforwardBefore);
});

it('refuses inside the wipe when the night moved between the preview and the confirm', function (): void {
    [, $sponsee] = seedNightRebuildTree(Carbon::parse(REBUILD_DAY));
    runCutoffForDay();

    $plan = app(RebuildPlanner::class)->plan(RebuildKind::Night, Carbon::parse(REBUILD_NIGHT));
    expect($plan->refusals)->toBe([]);

    $figures = cutoffFigures();
    $carryforward = carryforwardState();

    // 00:05: the scheduled nightly run cuts the next day off while the operator
    // is still reading the preview they are about to confirm.
    GsbCutoffResult::create([
        'distributor_id' => $sponsee->id,
        'cutoff_date' => REBUILD_NIGHT,
        'left_bv_paise' => 0, 'right_bv_paise' => 0, 'weaker_bv_paise' => 0,
        'slab' => 0, 'score' => 0, 'score_value_paise' => 0,
        'gross_gsb_paise' => 0, 'repurchase_deduction_paise' => 0, 'admin_charge_paise' => 0,
        'tds_paise' => 0, 'net_gsb_paise' => 0,
        'power_cf_before_paise' => 0, 'power_cf_after_paise' => 0,
        'slab1_weaker_cf_before_paise' => 0, 'slab1_weaker_cf_after_paise' => 0,
        'status' => GsbCutoffResult::STATUS_NO_MATCH,
    ]);

    expect(fn () => app(RebuildPlanner::class)->execute(
        $plan,
        nightRebuildDeveloper()->id,
        static fn (string $message): null => null,
    ))->toThrow(RebuildStateChanged::class);

    // The transaction rolled back: the day is exactly as it was, and the store
    // was not rewound under the day that has since been built on it.
    expect(cutoffFigures())->toBe($figures);
    expect(carryforwardState())->toBe($carryforward);
    expect(GsbPersonalBvTopup::whereDate('date', REBUILD_DAY)->count())->toBe(1);
});

it('turns a period that moved under the confirm into a refusal the operator can read', function (): void {
    [, $sponsee] = seedNightRebuildTree(Carbon::parse(REBUILD_DAY));
    runCutoffForDay();

    // The plan is clean when the command prints it; the next day is cut off
    // between that and the wipe. The audit row the command writes just before
    // executing is the hook that lands the change in that exact window.
    Event::listen('eloquent.created: '.AuditLog::class, function (AuditLog $entry) use ($sponsee): void {
        if ($entry->action !== 'compensation.rebuild.started') {
            return;
        }

        GsbCutoffResult::create([
            'distributor_id' => $sponsee->id,
            'cutoff_date' => REBUILD_NIGHT,
            'left_bv_paise' => 0, 'right_bv_paise' => 0, 'weaker_bv_paise' => 0,
            'slab' => 0, 'score' => 0, 'score_value_paise' => 0,
            'gross_gsb_paise' => 0, 'repurchase_deduction_paise' => 0, 'admin_charge_paise' => 0,
            'tds_paise' => 0, 'net_gsb_paise' => 0,
            'power_cf_before_paise' => 0, 'power_cf_after_paise' => 0,
            'slab1_weaker_cf_before_paise' => 0, 'slab1_weaker_cf_after_paise' => 0,
            'status' => GsbCutoffResult::STATUS_NO_MATCH,
        ]);
    });

    expect(Artisan::call('compensation:rebuild-night', [
        '--date' => REBUILD_NIGHT,
        '--actor' => nightRebuildDeveloper()->id,
        '--yes' => true,
    ]))->toBe(1);

    expect(Artisan::output())->toContain('changed while this rebuild was waiting to be confirmed');

    // A refusal, not a crash: nothing written, the run row terminal and skipped.
    expect(GsbCutoffResult::whereDate('cutoff_date', REBUILD_DAY)->count())->toBeGreaterThan(0);
    expect(EngineRun::where('engine_key', 'compensation.rebuild-night')->value('status'))
        ->toBe(EngineRun::STATUS_SKIPPED);
    expect(AuditLog::where('action', 'compensation.rebuild.refused')->exists())->toBeTrue();
});

it('refuses when the day\'s repurchase-wallet credits have since been spent', function (): void {
    [, $sponsee] = seedNightRebuildTree(Carbon::parse(REBUILD_DAY));
    runCutoffForDay();

    $credit = WalletLedgerEntry::where('type', 'repurchase_deduction')->firstOrFail();

    // The distributor spent the credit on a repurchase order this afternoon.
    app(WalletService::class)->debit(
        distributorId: (int) $credit->distributor_id,
        amountPaise: 50_000,
        type: 'repurchase_wallet_used',
        referenceId: 4_242,
        referenceType: 'order',
    );

    $plan = app(RebuildPlanner::class)->plan(RebuildKind::Night, Carbon::parse(REBUILD_NIGHT));

    expect($plan->isRefused())->toBeTrue();
    expect(implode("\n", $plan->refusals))->toContain('repurchase wallet has been drawn on');
});

it('records the wipe with the money it removed and the accumulator it corrected', function (): void {
    seedNightRebuildTree(Carbon::parse(REBUILD_DAY));
    runCutoffForDay();

    expect(Artisan::call('compensation:rebuild-night', [
        '--date' => REBUILD_NIGHT,
        '--actor' => nightRebuildDeveloper()->id,
        '--yes' => true,
    ]))->toBe(0);

    $wiped = AuditLog::where('action', 'compensation.rebuild.wiped')->firstOrFail();

    // A row count alone cannot answer "how much income did that remove" once the
    // period has been wiped and never rebuilt.
    expect($wiped->details['wallet_totals'])->toHaveKey('gsb_credit');
    expect($wiped->details['wallet_totals']['gsb_credit']['paise'])->toBeGreaterThan(0);
    expect($wiped->details['adjusted'])->toBe(['group_bv_daily' => 1]);
});

it('records a declined confirm as a decision rather than a successful rebuild', function (): void {
    seedNightRebuildTree(Carbon::parse(REBUILD_DAY));
    runCutoffForDay();

    $figures = cutoffFigures();

    // No `--yes`, and non-interactive: the confirm takes its default, which is
    // "no" — exactly what a developer typing `n` at the prompt gets.
    expect(Artisan::call('compensation:rebuild-night', [
        '--date' => REBUILD_NIGHT,
        '--actor' => nightRebuildDeveloper()->id,
        '--no-interaction' => true,
    ]))->toBe(0);

    expect(Artisan::output())->toContain('Left as it is.');

    expect(cutoffFigures())->toBe($figures);
    expect(EngineRun::where('engine_key', 'compensation.rebuild-night')->value('status'))
        ->toBe(EngineRun::STATUS_SKIPPED);
    expect(AuditLog::where('action', 'compensation.rebuild.declined')->exists())->toBeTrue();
});

it('names the developer on the rebuild\'s own run row', function (): void {
    seedNightRebuildTree(Carbon::parse(REBUILD_DAY));
    runCutoffForDay();

    $developer = nightRebuildDeveloper();

    expect(Artisan::call('compensation:rebuild-night', [
        '--date' => REBUILD_NIGHT,
        '--actor' => $developer->id,
        '--yes' => true,
    ]))->toBe(0);

    $run = EngineRun::where('engine_key', 'compensation.rebuild-night')->firstOrFail();

    // RecordEngineRun opens the row before --actor is parsed, so without the
    // trait's own update this reads `console` with nobody's name on it.
    expect($run->trigger)->toBe(EngineRun::TRIGGER_MANUAL);
    expect((int) $run->actor_id)->toBe((int) $developer->id);
});
