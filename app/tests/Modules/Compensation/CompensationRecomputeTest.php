<?php

declare(strict_types=1);

use App\Console\Actions\PurchaseDataResetAction;
use App\Modules\Compensation\Jobs\RecomputeAllJob;
use App\Modules\Compensation\Services\Recompute\CompensationRecomputeRunner;
use App\Modules\Compensation\Services\Recompute\CompensationStateWiper;
use App\Modules\Compensation\Services\Recompute\GroupBvReplayService;
use App\Modules\Compensation\Services\Recompute\RecomputeGuard;
use App\Modules\Compensation\Services\Recompute\RecomputeNotPermitted;
use App\Modules\Compensation\Services\Recompute\RecomputeProgress;
use App\Modules\Compensation\Support\DerivedTables;
use App\Modules\Compensation\Support\EngineRegistry;
use App\Modules\Identity\Models\Distributor;
use App\Modules\Identity\Models\User;
use App\Modules\Shared\Features\GenosSalesBonusFeature;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Cache\Events\KeyWritten;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Laravel\Pennant\Feature;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    disableTestForeignKeys();
    $this->seed(RolesAndPermissionsSeeder::class);
    config(['arovolife.recompute.enabled' => true]);
});

function recomputeAdmin(): User
{
    $user = User::create([
        'full_name' => 'Recompute Admin',
        'email' => 'recompute-admin-'.uniqid().'@test.com',
        'phone_e164' => '+91'.str_pad((string) random_int(7000000000, 9999999999), 10, '0'),
        'password_hash' => bcrypt('x'),
        'status' => 'active',
        'email_verified_at' => now(),
    ]);
    $user->assignRole('admin');

    return $user;
}

/**
 * A paid order with matching BV — the minimum a replay needs in order to have
 * something to propagate.
 */
function recomputeSeedPaidOrder(int $distributorId, string $paidAt, int $bvPaise): int
{
    $customerId = DB::table('customers')->insertGetId([
        'distributor_id' => $distributorId,
        'display_name' => 'Recompute Fixture',
        'email_hash' => hash('sha256', uniqid('email', true)),
        'phone_hash' => hash('sha256', uniqid('phone', true)),
        'created_at' => $paidAt,
        'updated_at' => $paidAt,
    ]);

    $orderId = DB::table('orders')->insertGetId([
        'order_no' => 'ORD-'.uniqid(),
        'customer_id' => $customerId,
        'attributed_distributor_id' => $distributorId,
        'status' => 'paid',
        'payment_method' => 'online',
        'idempotency_key' => uniqid('idem-'),
        'subtotal_paise' => 100_000,
        'gst_paise' => 0,
        'shipping_paise' => 0,
        'discount_paise' => 0,
        'total_paise' => 100_000,
        'ship_name' => 'Test',
        'ship_phone_e164' => '+919999999999',
        'ship_line1' => 'Line 1',
        'ship_city' => 'Hyderabad',
        'ship_state' => 'TG',
        'ship_pincode' => '500001',
        'paid_at' => $paidAt,
        'placed_at' => $paidAt,
        'created_at' => $paidAt,
        'updated_at' => $paidAt,
    ]);

    DB::table('bv_ledger_entries')->insert([
        'distributor_id' => $distributorId,
        'order_id' => $orderId,
        'bv_paise' => $bvPaise,
        'type' => 'accrual',
        'effective_at' => $paidAt,
        'created_at' => $paidAt,
        'updated_at' => $paidAt,
    ]);

    return $orderId;
}

/**
 * Ages the running run's heartbeat so it reads as abandoned.
 *
 * Written straight to the cache rather than by travelling Carbon: heartbeats
 * are deliberately stamped against the wall clock, because the replay itself
 * travels the clock and a travelled heartbeat would read as weeks stale the
 * moment it was written. Moving the test clock therefore moves nothing here.
 */
function recomputeAgeHeartbeat(int $minutes): void
{
    $key = 'compensation:recompute:progress';
    $state = Cache::get($key);
    $state['heartbeat_at'] = Carbon::now()->subMinutes($minutes)->toIso8601String();
    Cache::put($key, $state, 7200);
}

/*
|--------------------------------------------------------------------------
| The guard
|--------------------------------------------------------------------------
*/

it('refuses to recompute in production, flag or no flag', function (): void {
    app()->detectEnvironment(fn (): string => 'production');
    config(['arovolife.recompute.enabled' => true]);

    $guard = app(RecomputeGuard::class);

    expect($guard->isPermitted())->toBeFalse();
    expect(fn () => $guard->ensurePermitted())
        ->toThrow(RecomputeNotPermitted::class, 'production environment');
});

it('refuses to recompute when the env flag is not set', function (): void {
    config(['arovolife.recompute.enabled' => false]);

    $guard = app(RecomputeGuard::class);

    expect($guard->isPermitted())->toBeFalse();
    expect(fn () => $guard->ensurePermitted())
        ->toThrow(RecomputeNotPermitted::class, 'COMP_RECOMPUTE_ENABLED');
});

it('names the database a recompute would destroy', function (): void {
    expect(app(RecomputeGuard::class)->targetDatabase())->not->toBeEmpty();
});

/*
|--------------------------------------------------------------------------
| The wiper
|--------------------------------------------------------------------------
*/

it('wipes every derived table but keeps the purchases that produced them', function (): void {
    $dist = Distributor::factory()->create();
    $orderId = recomputeSeedPaidOrder($dist->id, '2026-06-05 10:00:00', 100_000);

    DB::table('gbb_monthly_pools')->insert([
        'month_start' => '2026-06-01',
        'company_bv_paise' => 100_000,
        'pool_rate_bp' => 500,
        'pool_paise' => 5_000,
        'total_agp' => 10,
        'point_value_paise' => 500,
        'payout_paise' => 5_000,
        'leftover_paise' => 0,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $removed = app(CompensationStateWiper::class)->wipe();

    expect($removed['gbb_monthly_pools'])->toBe(1);
    expect(DB::table('gbb_monthly_pools')->count())->toBe(0);

    // The source data a replay needs must survive.
    expect(DB::table('orders')->where('id', $orderId)->count())->toBe(1);
    expect(DB::table('bv_ledger_entries')->where('order_id', $orderId)->count())->toBe(1);
    expect(DB::table('distributors')->where('id', $dist->id)->count())->toBe(1);
});

it('clears a manual GSB freeze so it cannot suppress the replay', function (): void {
    $dist = Distributor::factory()->create();
    DB::table('distributors')->where('id', $dist->id)->update(['gsb_frozen_at' => now()]);

    app(CompensationStateWiper::class)->wipe();

    expect(DB::table('distributors')->where('id', $dist->id)->value('gsb_frozen_at'))->toBeNull();
});

it('previews the row counts it would destroy without destroying them', function (): void {
    DB::table('gbb_monthly_pools')->insert([
        'month_start' => '2026-06-01',
        'company_bv_paise' => 1, 'pool_rate_bp' => 500, 'pool_paise' => 1,
        'total_agp' => 1, 'point_value_paise' => 1, 'payout_paise' => 1, 'leftover_paise' => 0,
        'created_at' => now(), 'updated_at' => now(),
    ]);

    $preview = app(CompensationStateWiper::class)->preview();

    expect($preview['gbb_monthly_pools'])->toBe(1);
    expect(DB::table('gbb_monthly_pools')->count())->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Single source of truth
|--------------------------------------------------------------------------
*/

it('shares one derived-table list between both resets', function (): void {
    $purchaseReset = PurchaseDataResetAction::wipeTables();

    foreach (DerivedTables::inTruncationOrder() as $table) {
        // toContain treats extra arguments as more expected values, not a
        // message — assert the membership as a boolean so the failure names
        // the offending table.
        expect(in_array($table, $purchaseReset, true))
            ->toBeTrue("Derived table [{$table}] is missing from the purchase reset.");
    }

    // The three that historically drifted out of the list.
    expect(DerivedTables::inTruncationOrder())
        ->toContain('rank_aogo_grants')
        ->toContain('gsb_personal_bv_topups')
        ->toContain('engine_runs');
});

it('lists only tables that actually exist', function (): void {
    foreach (DerivedTables::inTruncationOrder() as $table) {
        expect(Schema::hasTable($table))->toBeTrue("Derived table [{$table}] does not exist.");
    }
});

it('truncates payout children before their parent', function (): void {
    $order = DerivedTables::inTruncationOrder();

    $position = static fn (string $table): int => (int) array_search($table, $order, true);

    expect($position('payout_line_items'))->toBeLessThan($position('payout_batches'));
    expect($position('wallet_ledger_entries'))->toBeLessThan($position('payout_batches'));
    expect($position('fortune_monthly_pool_levels'))->toBeLessThan($position('fortune_monthly_pools'));
});

/*
|--------------------------------------------------------------------------
| The replay
|--------------------------------------------------------------------------
*/

it('replays a window and leaves the clock on real time', function (): void {
    $dist = Distributor::factory()->create();
    recomputeSeedPaidOrder($dist->id, '2026-06-05 10:00:00', 100_000);

    $report = app(CompensationRecomputeRunner::class)->run(
        from: Carbon::parse('2026-06-05'),
        to: Carbon::parse('2026-06-10'),
    );

    expect($report->daysReplayed)->toBe(6);
    expect($report->ordersPropagated)->toBe(1);
    expect(Carbon::hasTestNow())->toBeFalse();
});

it('leaves the clock on real time even when the replay throws', function (): void {
    Carbon::setTestNow(null);

    // A window whose engines will run against a schema with foreign keys
    // disabled is fine; force the failure instead by pointing the runner at a
    // guard that refuses mid-flight.
    config(['arovolife.recompute.enabled' => false]);

    expect(fn () => app(CompensationRecomputeRunner::class)->run())
        ->toThrow(RecomputeNotPermitted::class);

    expect(Carbon::hasTestNow())->toBeFalse();
});

it('writes one audit-log row recording what it destroyed', function (): void {
    $dist = Distributor::factory()->create();
    recomputeSeedPaidOrder($dist->id, '2026-06-05 10:00:00', 100_000);

    app(CompensationRecomputeRunner::class)->run(
        from: Carbon::parse('2026-06-05'),
        to: Carbon::parse('2026-06-06'),
    );

    $row = DB::table('audit_log')->where('action', 'compensation.recompute_all')->first();

    expect($row)->not->toBeNull();

    $details = json_decode((string) $row->details, true);
    expect($details['from'])->toBe('2026-06-05');
    expect($details['to'])->toBe('2026-06-06');
    expect($details)->toHaveKey('rows_removed');
});

it('is idempotent — a second replay reproduces the first', function (): void {
    $dist = Distributor::factory()->create();
    recomputeSeedPaidOrder($dist->id, '2026-06-05 10:00:00', 100_000);

    $runner = app(CompensationRecomputeRunner::class);

    $runner->run(from: Carbon::parse('2026-06-05'), to: Carbon::parse('2026-06-08'));

    $firstWallet = (int) DB::table('wallet_ledger_entries')->sum('amount_paise');
    $firstGroupBv = (int) DB::table('group_bv_daily')->sum('left_bv_paise');
    $firstCutoffs = DB::table('gsb_cutoff_results')->count();

    $runner->run(from: Carbon::parse('2026-06-05'), to: Carbon::parse('2026-06-08'));

    expect((int) DB::table('wallet_ledger_entries')->sum('amount_paise'))->toBe($firstWallet);
    expect((int) DB::table('group_bv_daily')->sum('left_bv_paise'))->toBe($firstGroupBv);
    expect(DB::table('gsb_cutoff_results')->count())->toBe($firstCutoffs);
});

it('does not double-count group BV across repeated replays', function (): void {
    $dist = Distributor::factory()->create();
    recomputeSeedPaidOrder($dist->id, '2026-06-05 10:00:00', 100_000);

    $runner = app(CompensationRecomputeRunner::class);

    $runner->run(from: Carbon::parse('2026-06-05'), to: Carbon::parse('2026-06-06'));
    $credits = DB::table('group_bv_credits')->count();

    $runner->run(from: Carbon::parse('2026-06-05'), to: Carbon::parse('2026-06-06'));

    expect(DB::table('group_bv_credits')->count())->toBe($credits);
});

/*
|--------------------------------------------------------------------------
| The period in flight — testing-only catch-up
|--------------------------------------------------------------------------
*/

it('replays through today rather than stopping at yesterday', function (): void {
    $dist = Distributor::factory()->create();
    recomputeSeedPaidOrder($dist->id, Carbon::today()->subDay()->setTime(10, 0)->toDateTimeString(), 100_000);

    $report = app(CompensationRecomputeRunner::class)->run();

    expect($report->to->toDateString())->toBe(Carbon::today()->toDateString());
});

it('leaves no scheduled engine uncomputed for the period in flight', function (): void {
    $dist = Distributor::factory()->create();
    recomputeSeedPaidOrder($dist->id, Carbon::today()->subDay()->setTime(10, 0)->toDateTimeString(), 100_000);

    $report = app(CompensationRecomputeRunner::class)->run(from: Carbon::today()->subDay());

    // The day loop alone cannot produce these: the weekly payout only fires on
    // Tuesdays and the monthly engines only on the 2nd, 8th and 9th, so a
    // two-day window reaches them only through the catch-up pass.
    foreach (EngineRegistry::all() as $definition) {
        if ($definition->cadence->isScheduled()) {
            expect($report->enginesRun)->toHaveKey($definition->commandSignature);
        }
    }
});

it('computes the month in flight, not only the months that have closed', function (): void {
    $dist = Distributor::factory()->create();
    recomputeSeedPaidOrder($dist->id, Carbon::today()->setTime(10, 0)->toDateTimeString(), 100_000);

    app(CompensationRecomputeRunner::class)->run(from: Carbon::today());

    // GBB fires on the 2nd for the *previous* month, so a run whose period is
    // the current month can only have come from the catch-up.
    expect(DB::table('engine_runs')
        ->where('engine_key', 'gbb.monthly')
        ->whereDate('period_start', Carbon::today()->startOfMonth())
        ->exists())->toBeTrue();
});

it('does not catch up the current period when replaying a historical window', function (): void {
    $dist = Distributor::factory()->create();
    recomputeSeedPaidOrder($dist->id, '2026-06-05 10:00:00', 100_000);

    $report = app(CompensationRecomputeRunner::class)->run(
        from: Carbon::parse('2026-06-05'),
        to: Carbon::parse('2026-06-08'),
    );

    // 5–8 June contains no 2nd, 8th or 9th and the window does not reach today,
    // so no monthly engine should have run at all.
    expect($report->enginesRun)->not->toHaveKey('gbb:monthly-run');
    expect($report->enginesRun)->not->toHaveKey('payout:monthly-run');
});

it('freezes the period in flight at the real clock, not the simulated schedule instant', function (): void {
    // The runner lands the clock on real time itself, so the test cannot pin
    // "now" — instead it brackets the run with the wall clock. The day loop
    // used to stamp today's cut-off at its simulated 00:10 schedule instant,
    // which lies before this bracket for any test started after 00:10; the
    // rare just-after-midnight run is covered too, because the day loop's
    // future-clamp would then also collapse onto the wall clock.
    $dist = Distributor::factory()->create();
    recomputeSeedPaidOrder($dist->id, Carbon::today()->setTime(0, 1)->toDateTimeString(), 100_000);

    $before = Carbon::now()->subSecond();
    app(CompensationRecomputeRunner::class)->run(from: Carbon::today());

    $run = DB::table('engine_runs')
        ->where('engine_key', 'gsb.daily-cutoff')
        ->whereDate('period_start', Carbon::today())
        ->first();
    expect($run)->not->toBeNull();
    expect(Carbon::parse($run->started_at)->gte($before))->toBeTrue();
    expect(Carbon::parse($run->started_at)->lte(Carbon::now()))->toBeTrue();
});

it('never stamps a replayed run in the future', function (): void {
    $dist = Distributor::factory()->create();
    recomputeSeedPaidOrder($dist->id, Carbon::today()->setTime(0, 1)->toDateTimeString(), 100_000);

    app(CompensationRecomputeRunner::class)->run(from: Carbon::today());

    // Today's engines are due at scheduled times that may not have arrived yet
    // (the cut-off at 00:10, the payout at 09:00); those are clamped to now.
    expect(DB::table('engine_runs')->where('started_at', '>', Carbon::now()->addMinute())->count())->toBe(0);
});

/*
|--------------------------------------------------------------------------
| Cadence — the replay follows the registry, not a second copy of the schedule
|--------------------------------------------------------------------------
*/

it('fires monthly engines only on their scheduled day of month', function (): void {
    $gbb = EngineRegistry::get('gbb.monthly');

    expect($gbb->cadence->runsOn(Carbon::parse('2026-07-02')))->toBeTrue();
    expect($gbb->cadence->runsOn(Carbon::parse('2026-07-03')))->toBeFalse();

    // ...and works on the previous month, as its defaultPeriod declares.
    expect($gbb->periodRelativeTo(Carbon::parse('2026-07-02'))->toDateString())->toBe('2026-06-01');
});

it('fires the weekly payout only on Tuesdays', function (): void {
    $weekly = EngineRegistry::get('gsb.weekly-payout');

    expect($weekly->cadence->runsOn(Carbon::parse('2026-07-07')))->toBeTrue();  // Tuesday
    expect($weekly->cadence->runsOn(Carbon::parse('2026-07-08')))->toBeFalse(); // Wednesday
});

it('never fires a manual-only engine from the day loop', function (): void {
    $rankCheck = EngineRegistry::get('rank.check');

    expect($rankCheck->cadence->isScheduled())->toBeFalse();
    expect($rankCheck->cadence->runsOn(Carbon::parse('2026-07-02')))->toBeFalse();
});

it('really invokes the engines rather than passing vacuously', function (): void {
    Feature::for(null)->activate(GenosSalesBonusFeature::class);

    $dist = Distributor::factory()->create();
    recomputeSeedPaidOrder($dist->id, '2026-06-05 10:00:00', 100_000);

    app(CompensationRecomputeRunner::class)->run(
        from: Carbon::parse('2026-06-05'),
        to: Carbon::parse('2026-06-08'),
    );

    // Four days replayed, one cut-off row per distributor per day. If the day
    // loop were silently skipping (flag off, wrong signature, bad period) this
    // would be zero and every other assertion in this file would pass anyway.
    expect(DB::table('gsb_cutoff_results')->count())->toBe(4);
    expect(DB::table('gsb_cutoff_results')->distinct()->count('cutoff_date'))->toBe(4);

    // And they carry the replayed dates, not today's.
    expect(DB::table('gsb_cutoff_results')->min('cutoff_date'))->toContain('2026-06-05');
    expect(DB::table('gsb_cutoff_results')->max('cutoff_date'))->toContain('2026-06-08');

    // engine_runs repopulates as the replay goes, which is what the admin page reads.
    expect(DB::table('engine_runs')->count())->toBeGreaterThan(0);
});

/*
|--------------------------------------------------------------------------
| Live progress
|--------------------------------------------------------------------------
*/

it('publishes progress through every phase of a replay', function (): void {
    Feature::for(null)->activate(GenosSalesBonusFeature::class);

    $dist = Distributor::factory()->create();
    recomputeSeedPaidOrder($dist->id, '2026-06-05 10:00:00', 100_000);

    $progress = app(RecomputeProgress::class);

    expect($progress->read())->toBeNull();

    app(CompensationRecomputeRunner::class)->run(
        from: Carbon::parse('2026-06-05'),
        to: Carbon::parse('2026-06-08'),
    );

    $state = $progress->read();

    expect($state['state'])->toBe(RecomputeProgress::STATE_COMPLETE);
    expect($state['percent'])->toBe(100);
    expect($state['days_total'])->toBe(4);
    expect($state['days_done'])->toBe(4);
    expect($state['orders_done'])->toBe(1);
    expect($state['summary']['days'])->toBe(4);
    expect($state['summary']['engine_runs'])->toBeGreaterThan(0);
    expect($state['rows_removed'])->toBeGreaterThanOrEqual(0);
});

it('records the failure on the progress state when a replay throws', function (): void {
    config(['arovolife.recompute.enabled' => true]);

    $progress = app(RecomputeProgress::class);
    $progress->start();

    // Guard passes, then the window resolution finds nothing and the day loop
    // aborts on a command that cannot run — simulate by failing directly.
    $progress->fail('gsb:daily-cutoff for 05 Jun 2026 exited with code 1');

    $state = $progress->read();

    expect($state['state'])->toBe(RecomputeProgress::STATE_FAILED);
    expect($state['error'])->toContain('exited with code 1');
    expect($state['finished_at'])->not->toBeNull();
});

it('reports idle before any recompute has run', function (): void {
    config(['arovolife.recompute.enabled' => true]);

    $response = $this->actingAs(recomputeAdmin())
        ->getJson(route('admin.compensation.engine-runs.recompute-progress'));

    $response->assertOk()->assertJson(['state' => 'idle']);
});

it('404s the progress endpoint when the gate is closed', function (): void {
    config(['arovolife.recompute.enabled' => false]);

    $this->actingAs(recomputeAdmin())
        ->getJson(route('admin.compensation.engine-runs.recompute-progress'))
        ->assertNotFound();
});

it('serves live progress to the poller while a replay is in flight', function (): void {
    config(['arovolife.recompute.enabled' => true]);

    $progress = app(RecomputeProgress::class);
    $progress->start();
    $progress->daysTotal(44);
    $progress->dayReplayed('2026-07-15', ['gsb:daily-cutoff', 'repurchase:evaluate'], 12, 24);

    $response = $this->actingAs(recomputeAdmin())
        ->getJson(route('admin.compensation.engine-runs.recompute-progress'));

    $response->assertOk()
        ->assertJson([
            'state' => RecomputeProgress::STATE_RUNNING,
            'current_date' => '2026-07-15',
            'days_done' => 12,
            'days_total' => 44,
            'engine_runs' => 24,
        ]);

    // 5 (wipe) + 15 (propagate) + 75 * 12/44 ≈ 40
    expect($response->json('percent'))->toBeGreaterThan(20)->toBeLessThan(60);
});
it('keeps progress alive across the replay clock travel', function (): void {
    // Regression: progress updates are published from inside the travelled
    // clock section. A cache TTL computed against a back-dated Carbon::now()
    // expires the instant the real clock is restored, wiping the progress the
    // user is watching.
    $progress = app(RecomputeProgress::class);
    $progress->start();

    Carbon::setTestNow(Carbon::parse('2026-06-05 10:00:00'));
    $progress->dayReplayed('2026-06-05', ['gsb:daily-cutoff'], 1, 1);
    Carbon::setTestNow();

    expect($progress->read())->not->toBeNull();
    expect($progress->read()['current_date'])->toBe('2026-06-05');
});

it('reports a run that stopped reporting as failed rather than as still running', function (): void {
    // A worker killed from outside — queue:listen enforcing its 60s child-process
    // timeout, an OOM, a deploy — never reaches fail(). Without a heartbeat the
    // last 'running' state it wrote sits there for the full two-hour TTL and the
    // console shows a frozen bar that is indistinguishable from a slow replay.
    $progress = app(RecomputeProgress::class);
    $progress->start();
    $progress->ordersTotal(330);
    $progress->ordersProgressed(0);
    recomputeAgeHeartbeat(20);

    $state = $progress->read();

    expect($state['state'])->toBe(RecomputeProgress::STATE_FAILED);
    expect($state['error'])->toContain('stopped reporting');
    expect($state['error'])->toContain('Re-deriving group BV from paid orders');
    expect($progress->isRunning())->toBeFalse();
});

it('leaves a run that is merely between heartbeats alone', function (): void {
    $progress = app(RecomputeProgress::class);
    $progress->start();
    recomputeAgeHeartbeat(5);

    expect($progress->read()['state'])->toBe(RecomputeProgress::STATE_RUNNING);
    expect($progress->isRunning())->toBeTrue();
});

it('heartbeats against the real clock, not the clock the replay travelled to', function (): void {
    // Every day-loop update is published from inside the travelled section. A
    // heartbeat stamped with the back-dated clock reads as weeks stale the
    // moment it is written, and would fail a perfectly healthy replay.
    $progress = app(RecomputeProgress::class);
    $progress->start();

    Carbon::setTestNow(Carbon::parse('2026-06-05 10:00:00'));
    $progress->dayReplayed('2026-06-05', ['gsb:daily-cutoff'], 1, 1);
    Carbon::setTestNow();

    $heartbeat = Carbon::parse(Cache::get('compensation:recompute:progress')['heartbeat_at']);

    expect($heartbeat->toDateString())->not->toBe('2026-06-05');
    expect($heartbeat->diffInMinutes(Carbon::now(), absolute: true))->toBeLessThan(1);
    expect($progress->read()['state'])->toBe(RecomputeProgress::STATE_RUNNING);
});

it('does not resurrect a stalled state once the replay reports again', function (): void {
    // read() downgrades a stalled run for the reader only. If merge() folded
    // that downgrade back into the cache, a run that was briefly quiet would be
    // marked failed permanently and its own completion would never show.
    $progress = app(RecomputeProgress::class);
    $progress->start();
    recomputeAgeHeartbeat(20);

    expect($progress->read()['state'])->toBe(RecomputeProgress::STATE_FAILED);

    $progress->dayReplayed('2026-06-05', ['gsb:daily-cutoff'], 1, 1);

    expect($progress->read()['state'])->toBe(RecomputeProgress::STATE_RUNNING);
});

it('lets the console start a new run once the previous one is confirmed dead', function (): void {
    // The killed worker never released its lock, so the lock alone would refuse
    // every retry for two hours — precisely when a retry is the only way to
    // finish rebuilding a half-wiped database.
    config(['arovolife.recompute.enabled' => true]);

    app(RecomputeProgress::class)->start();
    recomputeAgeHeartbeat(20);
    Cache::lock(RecomputeAllJob::LOCK_KEY, 7200)->get();

    Queue::fake();

    $this->actingAs(recomputeAdmin())
        ->post(route('admin.compensation.engine-runs.recompute-all'))
        ->assertRedirect(route('admin.compensation.engine-runs.index'))
        ->assertSessionHas('status');

    Queue::assertPushed(RecomputeAllJob::class);
});

it('still refuses to start a second run while the first is reporting', function (): void {
    config(['arovolife.recompute.enabled' => true]);

    app(RecomputeProgress::class)->start();
    Cache::lock(RecomputeAllJob::LOCK_KEY, 7200)->get();

    Queue::fake();

    $this->actingAs(recomputeAdmin())
        ->post(route('admin.compensation.engine-runs.recompute-all'))
        ->assertSessionHas('error');

    Queue::assertNothingPushed();
});

it('publishes propagation progress often enough to show a stall as a stall', function (): void {
    // The bar only moves when a chunk closes, so the chunk size is the bar's
    // resolution. At 200 orders per chunk a real-world replay reported twice and
    // looked frozen for minutes; the operator could not tell it from a dead one.
    // One customer per distributor — customers.distributor_id is unique.
    for ($i = 0; $i < 30; $i++) {
        recomputeSeedPaidOrder(Distributor::factory()->create()->id, '2026-06-05 10:00:00', 100_000);
    }

    $published = [];
    Event::listen(KeyWritten::class, function (KeyWritten $event) use (&$published): void {
        if ($event->key === 'compensation:recompute:progress' && is_array($event->value)) {
            $published[] = $event->value['orders_done'] ?? null;
        }
    });

    app(GroupBvReplayService::class)->replay();

    // Not just the opening 0 and the closing 30 — at least one tick in between.
    expect(array_filter($published, fn ($done) => $done > 0 && $done < 30))->not->toBeEmpty();
});
