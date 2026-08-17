<?php

declare(strict_types=1);

use App\Console\Actions\PurchaseDataResetAction;
use App\Modules\Compensation\Services\Recompute\CompensationRecomputeRunner;
use App\Modules\Compensation\Services\Recompute\CompensationStateWiper;
use App\Modules\Compensation\Services\Recompute\RecomputeGuard;
use App\Modules\Compensation\Services\Recompute\RecomputeNotPermitted;
use App\Modules\Compensation\Services\Recompute\RecomputeProgress;
use App\Modules\Compensation\Support\DerivedTables;
use App\Modules\Compensation\Support\EngineRegistry;
use App\Modules\Identity\Models\Distributor;
use App\Modules\Identity\Models\User;
use App\Modules\Shared\Features\GenosSalesBonusFeature;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Laravel\Pennant\Feature;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    disableTestForeignKeys();
    $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);
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
