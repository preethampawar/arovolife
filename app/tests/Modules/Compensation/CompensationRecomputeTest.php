<?php

declare(strict_types=1);

use App\Console\Actions\PurchaseDataResetAction;
use App\Modules\Compensation\Jobs\RecomputeAllJob;
use App\Modules\Compensation\Models\EngineRun;
use App\Modules\Compensation\Models\GsbCutoffResult;
use App\Modules\Compensation\Services\DTOs\RecomputeReport;
use App\Modules\Compensation\Services\Recompute\CompensationRecomputeRunner;
use App\Modules\Compensation\Services\Recompute\CompensationStateWiper;
use App\Modules\Compensation\Services\Recompute\EngineReplayService;
use App\Modules\Compensation\Services\Recompute\GroupBvReplayService;
use App\Modules\Compensation\Services\Recompute\RecomputeGuard;
use App\Modules\Compensation\Services\Recompute\RecomputeHorizon;
use App\Modules\Compensation\Services\Recompute\RecomputeNotPermitted;
use App\Modules\Compensation\Services\Recompute\RecomputeProgress;
use App\Modules\Compensation\Support\DerivedTables;
use App\Modules\Compensation\Support\EngineRegistry;
use App\Modules\Identity\Models\Distributor;
use App\Modules\Identity\Models\User;
use App\Modules\Shared\Features\GenosSalesBonusFeature;
use App\Modules\Shared\Features\RankBonusFeature;
use App\Modules\Shared\Features\RepurchaseEngineFeature;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Cache\Events\KeyWritten;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Testing\Fakes\NotificationFake;
use Laravel\Pennant\Feature;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    disableTestForeignKeys();
    $this->seed(RolesAndPermissionsSeeder::class);
    config(['arovolife.recompute.enabled' => true]);
});

/** The recompute console is developer-only (F84), on top of RecomputeGuard. */
function recomputeAdmin(): User
{
    $user = User::create([
        'full_name' => 'Recompute Developer',
        'email' => 'recompute-admin-'.uniqid().'@test.com',
        'phone_e164' => '+91'.str_pad((string) random_int(7000000000, 9999999999), 10, '0'),
        'password_hash' => bcrypt('x'),
        'status' => 'active',
        'email_verified_at' => now(),
    ]);
    $user->assignRole('developer');

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
 * Run a recompute as if "now" were $instant.
 *
 * There is no `to` any more: a recompute replays the scheduler's calendar up to
 * a horizon, and the `now` horizon is this instant. Pinning the clock is
 * therefore how a test asks for a historical window — and it is also the only
 * honest way to ask, because the engines' own guards read the same clock.
 */
function recomputeAsAt(string $instant, ?Carbon $from = null, RecomputeHorizon $horizon = RecomputeHorizon::Now): RecomputeReport
{
    Carbon::setTestNow(Carbon::parse($instant));

    try {
        return app(CompensationRecomputeRunner::class)->run(from: $from, horizon: $horizon);
    } finally {
        Carbon::setTestNow();
    }
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

it('keeps the checkout-time repurchase-wallet debits through a full wipe', function (): void {
    // The debit a distributor takes at checkout is a record of a purchase, not
    // a derived figure: no engine writes it and no replay rebuilds it. Both
    // the cycle verdict (condition B) and the month-end wallet gate read the
    // ledger for the balance it reduces, so wiping it would re-judge every
    // distributor who spent their repurchase wallet as if they never had.
    $dist = Distributor::factory()->create();
    recomputeSeedPaidOrder($dist->id, '2026-06-05 10:00:00', 100_000);

    DB::table('wallet_ledger_entries')->insert([
        [
            'distributor_id' => $dist->id,
            'type' => 'repurchase_deduction',
            'amount_paise' => 50_000,
            'reference_id' => 1,
            'reference_type' => 'gsb_cutoff_result',
            'created_at' => '2026-06-06 00:10:00',
        ],
        [
            'distributor_id' => $dist->id,
            'type' => 'repurchase_wallet_used',
            'amount_paise' => -50_000,
            'reference_id' => 1,
            'reference_type' => 'order',
            'created_at' => '2026-06-20 10:00:00',
        ],
    ]);

    $removed = app(CompensationStateWiper::class)->wipe();

    expect($removed['wallet_ledger_entries'])->toBe(1)
        ->and(DB::table('wallet_ledger_entries')->pluck('type')->all())->toBe(['repurchase_wallet_used']);
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

    $report = app(CompensationRecomputeRunner::class)->run(from: Carbon::parse('2026-06-05'));

    expect($report->daysReplayed)->toBeGreaterThan(0);
    expect($report->ordersPropagated)->toBe(1);
    expect($report->horizon)->toBe(RecomputeHorizon::Now);
    expect($report->simulatedThrough)->toBeNull();
    expect(Carbon::hasTestNow())->toBeFalse();
});

it('replays from the platform\'s first day when a distributor pre-dates the first sale', function (): void {
    // A3. A full recompute truncates `engine_runs` and rebuilds it from what it
    // fires, and a month is later judged on the days it owed from the first
    // DISTRIBUTOR, not from the first sale. Starting at the sale would leave
    // the days before it with no cut-off proof, and a month containing that gap
    // could never be closed again. One anchor, or the two questions disagree.
    $dist = Distributor::factory()->create(['effective_date' => '2026-06-05']);
    recomputeSeedPaidOrder($dist->id, '2026-06-08 10:00:00', 100_000);

    $report = recomputeAsAt('2026-06-10 09:00:00');

    expect($report->from->toDateString())->toBe('2026-06-05');
});

it('replays from the first sale when no distributor pre-dates it', function (): void {
    // The mirror: the anchor is the EARLIER of the two, so a platform whose
    // first sale comes first still starts on the sale, exactly as before.
    $dist = Distributor::factory()->create(['effective_date' => '2026-06-20']);
    recomputeSeedPaidOrder($dist->id, '2026-06-08 10:00:00', 100_000);

    $report = recomputeAsAt('2026-06-10 09:00:00');

    expect($report->from->toDateString())->toBe('2026-06-08');
});

it('leaves notifications on the real channel manager after a replay', function (): void {
    // Regression: the runner muted notifications with `Notification::fake()`
    // and switched the mailer to `array`, but restored neither. In a request
    // that is harmless -- the process ends. On a queue worker, which is
    // long-lived and handles many jobs, the swap outlived the replay and every
    // later SendQueuedNotifications job in that worker resolved the
    // NotificationFake and died with "Argument #1 ($manager) must be of type
    // ChannelManager". 87 such failures were observed on staging, each one a
    // notification lost after the event that produced it was consumed.
    $realManager = Notification::getFacadeRoot();
    $realMailer = config('mail.default');

    $dist = Distributor::factory()->create();
    recomputeSeedPaidOrder($dist->id, '2026-06-05 10:00:00', 100_000);

    recomputeAsAt('2026-06-07 09:00:00', Carbon::parse('2026-06-05'));

    expect(Notification::getFacadeRoot())->not->toBeInstanceOf(NotificationFake::class);
    expect(Notification::getFacadeRoot())->toBe($realManager);
    expect(config('mail.default'))->toBe($realMailer);
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

    recomputeAsAt('2026-06-07 09:00:00', Carbon::parse('2026-06-05'));

    $row = DB::table('audit_log')->where('action', 'compensation.recompute_all')->first();

    expect($row)->not->toBeNull();

    $details = json_decode((string) $row->details, true);
    expect($details['from'])->toBe('2026-06-05');
    expect($details['to'])->toBe('2026-06-07');
    // The horizon is what a later reader — the banner, the scheduler pause —
    // judges the environment by, so it has to be on the row.
    expect($details['horizon'])->toBe('now');
    expect($details['simulated_through'])->toBeNull();
    expect($details)->toHaveKey('rows_removed');
});

it('is idempotent — a second replay reproduces the first', function (): void {
    $dist = Distributor::factory()->create();
    recomputeSeedPaidOrder($dist->id, '2026-06-05 10:00:00', 100_000);

    recomputeAsAt('2026-06-09 09:00:00', Carbon::parse('2026-06-05'));

    $firstWallet = (int) DB::table('wallet_ledger_entries')->sum('amount_paise');
    $firstGroupBv = (int) DB::table('group_bv_daily')->sum('left_bv_paise');
    $firstCutoffs = DB::table('gsb_cutoff_results')->count();

    recomputeAsAt('2026-06-09 09:00:00', Carbon::parse('2026-06-05'));

    expect((int) DB::table('wallet_ledger_entries')->sum('amount_paise'))->toBe($firstWallet);
    expect((int) DB::table('group_bv_daily')->sum('left_bv_paise'))->toBe($firstGroupBv);
    expect(DB::table('gsb_cutoff_results')->count())->toBe($firstCutoffs);
});

it('does not double-count group BV across repeated replays', function (): void {
    $dist = Distributor::factory()->create();
    recomputeSeedPaidOrder($dist->id, '2026-06-05 10:00:00', 100_000);

    recomputeAsAt('2026-06-07 09:00:00', Carbon::parse('2026-06-05'));
    $credits = DB::table('group_bv_credits')->count();

    recomputeAsAt('2026-06-07 09:00:00', Carbon::parse('2026-06-05'));

    expect(DB::table('group_bv_credits')->count())->toBe($credits);
});

/*
|--------------------------------------------------------------------------
| The horizon — how far the scheduler's calendar is replayed
|--------------------------------------------------------------------------
|
| Every engine fires at the instant the scheduler would have fired it, for the
| period the scheduler would have handed it. The horizon decides only where that
| stops. The clock is pinned in each of these: "what has the scheduler reached"
| is a question about a moment, and a test that let the wall clock answer it
| would pass or fail by the hour it ran at.
*/

it('fires the daily cut-off at 00:10 the NEXT morning, for the day before it', function (): void {
    // F125. The replay used to fire the cut-off for day D at D 00:10 — a day
    // early. Two things went wrong with that, and only one of them was visible:
    // the next real scheduled run tripped GsbCutoffService's out-of-order guard,
    // and the repurchase deductions a cut-off writes were dated INSIDE the month
    // whose month-end wallet gate they would then be counted against.
    Feature::activate(GenosSalesBonusFeature::class);

    $dist = Distributor::factory()->create();
    recomputeSeedPaidOrder($dist->id, '2026-06-05 10:00:00', 100_000);

    recomputeAsAt('2026-06-09 12:00:00', Carbon::parse('2026-06-05'));

    $runs = EngineRun::where('engine_key', 'gsb.daily-cutoff')
        ->orderBy('period_start')
        ->get()
        ->mapWithKeys(fn (EngineRun $run): array => [
            $run->period_start->toDateString() => $run->started_at->format('Y-m-d H:i'),
        ]);

    expect($runs->all())->toBe([
        '2026-06-05' => '2026-06-06 00:10',
        '2026-06-06' => '2026-06-07 00:10',
        '2026-06-07' => '2026-06-08 00:10',
        '2026-06-08' => '2026-06-09 00:10',
    ]);
});

it('does not fire an engine whose scheduled instant has not arrived', function (): void {
    // The `now` horizon is the production-faithful one: at 00:07 on the 9th the
    // 00:05 evaluation has fired and the 00:10 cut-off has not, so the 8th is
    // still uncut — exactly as it would be on the real box at 00:07.
    Feature::activate(GenosSalesBonusFeature::class);

    $dist = Distributor::factory()->create();
    recomputeSeedPaidOrder($dist->id, '2026-06-05 10:00:00', 100_000);

    recomputeAsAt('2026-06-09 00:07:00', Carbon::parse('2026-06-05'));

    expect(EngineRun::where('engine_key', 'gsb.daily-cutoff')
        ->whereDate('period_start', '2026-06-08')->exists())->toBeFalse();
    expect(EngineRun::where('engine_key', 'gsb.daily-cutoff')
        ->whereDate('period_start', '2026-06-07')->exists())->toBeTrue();
    expect(EngineRun::where('engine_key', 'repurchase.evaluate')
        ->whereDate('period_start', '2026-06-09')->exists())->toBeTrue();
});

it('never stamps a replayed run in the future under the now horizon', function (): void {
    $dist = Distributor::factory()->create();
    recomputeSeedPaidOrder($dist->id, Carbon::today()->subDays(2)->setTime(10, 0)->toDateTimeString(), 100_000);

    app(CompensationRecomputeRunner::class)->run(from: Carbon::today()->subDays(2));

    expect(DB::table('engine_runs')->where('started_at', '>', Carbon::now()->addMinute())->count())->toBe(0);
});

it('closes a month on the 1st of the next one, not while it is still in flight', function (): void {
    // The month's crediting engines exist at one instant: 00:15-04:00 on the 1st
    // of the following month. Running them at "now" mid-month — which the old
    // catch-up pass did — priced the month on partial BV and dated every row it
    // wrote inside the month it was judging.
    Feature::activate(GenosSalesBonusFeature::class);
    Feature::activate(RankBonusFeature::class);

    $dist = Distributor::factory()->create(['status' => 'active', 'depth' => 0]);
    DB::table('genealogy_closure')->insert([
        'ancestor_id' => $dist->id, 'descendant_id' => $dist->id, 'depth' => 0,
    ]);
    recomputeSeedPaidOrder($dist->id, '2026-08-20 10:00:00', 100_000);

    recomputeAsAt('2026-09-03 12:00:00', Carbon::parse('2026-08-20'));

    $rankBonus = EngineRun::where('engine_key', 'rank.bonus')
        ->whereDate('period_start', '2026-08-01')
        ->latest('id')
        ->first();

    expect($rankBonus)->not->toBeNull('August Rank Bonus never ran');
    expect($rankBonus->started_at->format('Y-m-d H:i'))->toBe('2026-09-01 00:30');

    // September is in flight at this horizon, so nothing has closed it.
    expect(EngineRun::where('engine_key', 'rank.bonus')
        ->whereDate('period_start', '2026-09-01')->exists())->toBeFalse();
});

it('projects the month in flight by firing its close on the 1st of the next month', function (): void {
    Feature::activate(GenosSalesBonusFeature::class);
    Feature::activate(RankBonusFeature::class);

    $dist = Distributor::factory()->create(['status' => 'active', 'depth' => 0]);
    DB::table('genealogy_closure')->insert([
        'ancestor_id' => $dist->id, 'descendant_id' => $dist->id, 'depth' => 0,
    ]);
    recomputeSeedPaidOrder($dist->id, '2026-09-02 10:00:00', 100_000);

    $report = recomputeAsAt('2026-09-14 19:15:00', Carbon::parse('2026-09-01'), RecomputeHorizon::Projection);

    expect($report->horizon)->toBe(RecomputeHorizon::Projection);
    expect($report->simulatedThrough?->format('Y-m-d H:i'))->toBe('2026-10-08 04:00');

    $rankBonus = EngineRun::where('engine_key', 'rank.bonus')
        ->whereDate('period_start', '2026-09-01')
        ->latest('id')
        ->firstOrFail();

    expect($rankBonus->started_at->format('Y-m-d H:i'))->toBe('2026-10-01 00:30');

    // ...and the batch that pays it, a week later.
    $payout = EngineRun::where('engine_key', 'payout.monthly')
        ->whereDate('period_start', '2026-10-01')
        ->latest('id')
        ->firstOrFail();

    expect($payout->started_at->format('Y-m-d H:i'))->toBe('2026-10-08 04:00');
});

it('keeps a month\'s own crediting deductions out of the month they are judged against', function (): void {
    // THE staging bug, 14 Sep 2026. Rank Bonus takes a 10% repurchase deduction
    // at credit time; Growth Booster and Fortune then ask whether the wallet was
    // empty at the last instant of the same month. While the close ran at "now"
    // — inside the month — those deductions landed on, say, 14 September and
    // blocked September for everyone Rank Bonus had just paid. At the
    // scheduler's own clock the close runs on 1 October, so every deduction it
    // writes is dated after September ended and September's verdict cannot see
    // them.
    Feature::activate(GenosSalesBonusFeature::class);
    Feature::activate(RankBonusFeature::class);
    Feature::activate(RepurchaseEngineFeature::class);

    $dist = Distributor::factory()->create(['status' => 'active', 'depth' => 0]);
    DB::table('genealogy_closure')->insert([
        'ancestor_id' => $dist->id, 'descendant_id' => $dist->id, 'depth' => 0,
    ]);
    recomputeSeedPaidOrder($dist->id, '2026-09-02 10:00:00', 500_000);

    recomputeAsAt('2026-09-14 19:15:00', Carbon::parse('2026-09-01'), RecomputeHorizon::Projection);

    $inSeptember = DB::table('wallet_ledger_entries')
        ->where('type', 'repurchase_deduction')
        ->whereBetween('created_at', ['2026-09-01 00:00:00', '2026-09-30 23:59:59'])
        ->whereIn('engine_run_id', EngineRun::whereIn('engine_key', [
            'rank.bonus', 'gbb.monthly', 'fortune.payout',
        ])->pluck('id'))
        ->count();

    expect($inSeptember)->toBe(
        0,
        'A monthly engine wrote a repurchase deduction dated inside September — the month its own wallet gate judges.',
    );
});

/*
|--------------------------------------------------------------------------
| Cadence — the replay follows the registry, not a second copy of the schedule
|--------------------------------------------------------------------------
*/

it('fires monthly engines only on their scheduled day of month', function (): void {
    $gbb = EngineRegistry::get('gbb.monthly');

    expect($gbb->cadence->runsOn(Carbon::parse('2026-07-01')))->toBeTrue();
    expect($gbb->cadence->runsOn(Carbon::parse('2026-07-02')))->toBeFalse();

    // ...and works on the previous month, as its defaultPeriod declares.
    expect($gbb->periodRelativeTo(Carbon::parse('2026-07-01'))->toDateString())->toBe('2026-06-01');
});

it('fires the weekly payout only on Tuesdays', function (): void {
    $weekly = EngineRegistry::get('gsb.weekly-payout');

    expect($weekly->cadence->runsOn(Carbon::parse('2026-07-07')))->toBeTrue();  // Tuesday
    expect($weekly->cadence->runsOn(Carbon::parse('2026-07-08')))->toBeFalse(); // Wednesday
});

it('fires the rank qualification check on the 1st, for the month that just closed', function (): void {
    $rankCheck = EngineRegistry::get('rank.check');

    // Nothing else writes rank_qualifications and Rank Bonus only reads them,
    // so the check must fire on the 1st ahead of it rather than wait for a
    // human — see routes/console.php.
    expect($rankCheck->cadence->isScheduled())->toBeTrue();
    expect($rankCheck->cadence->runsOn(Carbon::parse('2026-07-01')))->toBeTrue();
    expect($rankCheck->cadence->runsOn(Carbon::parse('2026-07-02')))->toBeFalse();
    expect($rankCheck->periodRelativeTo(Carbon::parse('2026-07-01'))->toDateString())->toBe('2026-06-01');
});

it('really invokes the engines rather than passing vacuously', function (): void {
    Feature::for(null)->activate(GenosSalesBonusFeature::class);

    $dist = Distributor::factory()->create();
    recomputeSeedPaidOrder($dist->id, '2026-06-05 10:00:00', 100_000);

    // The 9th at noon: the cut-offs for the 5th-8th have all fired (each at
    // 00:10 the morning after its day), the 9th's has not.
    recomputeAsAt('2026-06-09 12:00:00', Carbon::parse('2026-06-05'));

    // Four days cut off, one row per distributor per day. If the loop were
    // silently skipping (flag off, wrong signature, bad period) this would be
    // zero and every other assertion in this file would pass anyway.
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

    recomputeAsAt('2026-06-09 12:00:00', Carbon::parse('2026-06-05'));

    $state = $progress->read();

    expect($state['state'])->toBe(RecomputeProgress::STATE_COMPLETE);
    expect($state['percent'])->toBe(100);
    // 5-10 June: the loop runs one day past the horizon, because the engines
    // that settle the horizon day fire the next morning.
    expect($state['days_total'])->toBe(6);
    expect($state['days_done'])->toBe(6);
    expect($state['orders_done'])->toBe(1);
    expect($state['summary']['days'])->toBe(6);
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

it('marks the progress failed when the job is refused by the guard before the runner starts', function (): void {
    // Regression: the guard throws before the runner touches the progress
    // record, so a worker still holding a stale allow-list left the admin
    // page reading "Queued — waiting for the queue worker" forever.
    config(['arovolife.recompute.enabled' => true, 'arovolife.recompute.allowed_databases' => ['somewhere-else']]);

    $progress = app(RecomputeProgress::class);
    $progress->queued();

    $job = new RecomputeAllJob(actorUserId: null);

    try {
        $job->handle(app(CompensationRecomputeRunner::class));
        $this->fail('expected the guard to refuse');
    } catch (RecomputeNotPermitted $e) {
        $job->failed($e);
    }

    $state = $progress->read();

    expect($state['state'])->toBe(RecomputeProgress::STATE_FAILED);
    expect($state['error'])->toContain('somewhere-else');
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
        ->post(route('admin.compensation.engine-runs.recompute-all'), ['horizon' => 'now'])
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
        ->post(route('admin.compensation.engine-runs.recompute-all'), ['horizon' => 'now'])
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

it('refuses a partial replay that leaves out the repurchase evaluation the cut-off needs', function (): void {
    // The wipe deletes the window's repurchase cycles whatever is selected, and
    // gsb:daily-cutoff then refuses to run without them — aborting the replay
    // partway and leaving the database half-rebuilt. Refuse the selection before
    // a row is deleted. Partial replays live on the command line now; the page
    // offers only a horizon.
    config(['arovolife.recompute.enabled' => true]);
    Feature::activate(RepurchaseEngineFeature::class);

    $exit = Artisan::call('compensation:recompute-all', [
        '--only' => ['gsb.daily-cutoff'],
        '--force' => true,
    ]);

    expect($exit)->toBe(1)
        ->and(Artisan::output())->toContain('repurchase.evaluate');

    expect(DB::table('audit_log')->where('action', 'compensation.recompute_all')->count())->toBe(0);
});

it('accepts the same partial replay once the repurchase evaluation is selected too', function (): void {
    config(['arovolife.recompute.enabled' => true]);
    Feature::activate(RepurchaseEngineFeature::class);

    $exit = Artisan::call('compensation:recompute-all', [
        '--only' => ['repurchase.evaluate', 'gsb.daily-cutoff'],
        '--force' => true,
    ]);

    expect($exit)->toBe(0);
});

it('allows a cut-off-only replay while the repurchase engine is off', function (): void {
    // Flag off, guards skipped: the selection is runnable exactly as before.
    config(['arovolife.recompute.enabled' => true]);

    $exit = Artisan::call('compensation:recompute-all', [
        '--only' => ['gsb.daily-cutoff'],
        '--force' => true,
    ]);

    expect($exit)->toBe(0);
});

it('refuses outright in production, whatever it is asked for', function (): void {
    config(['arovolife.recompute.enabled' => true]);
    app()->detectEnvironment(fn (): string => 'production');

    try {
        $exit = Artisan::call('compensation:recompute-all', ['--force' => true]);
    } finally {
        app()->detectEnvironment(fn (): string => 'testing');
    }

    expect($exit)->toBe(1)
        ->and(Artisan::output())->toContain('production');
});

it('does nothing when asked to reset an environment that holds no projection', function (): void {
    // The nightly 23:30 entry passes --if-projected precisely so a faithful
    // environment is never wiped and rebuilt for no reason.
    config(['arovolife.recompute.enabled' => true]);

    $exit = Artisan::call('compensation:recompute-all', [
        '--if-projected' => true,
        '--force' => true,
    ]);

    expect($exit)->toBe(0)
        ->and(Artisan::output())->toContain('Nothing to reset');

    expect(DB::table('audit_log')->where('action', 'compensation.recompute_all')->count())->toBe(0);
});

/** A distributor placed under $parent on $side, with its closure rows. */
function recomputePlaceChild(Distributor $parent, string $side): Distributor
{
    $child = Distributor::factory()->create([
        'status' => 'active',
        'placement_parent_id' => $parent->id,
        'placement_side' => $side,
        'depth' => $parent->depth + 1,
    ]);

    DB::table('genealogy_closure')->insert([
        'ancestor_id' => $child->id, 'descendant_id' => $child->id, 'depth' => 0,
    ]);

    foreach (DB::table('genealogy_closure')->where('descendant_id', $parent->id)->get() as $row) {
        DB::table('genealogy_closure')->insert([
            'ancestor_id' => $row->ancestor_id,
            'descendant_id' => $child->id,
            'depth' => $row->depth + 1,
        ]);
    }

    return $child;
}

/** A self-consumption order + its BV — what the repurchase cycle counts. */
function recomputeSeedSelfPurchase(int $distributorId, int $bvPaise, string $date): void
{
    $orderId = DB::table('orders')->insertGetId([
        'order_no' => 'SELF-'.uniqid(),
        'customer_id' => 1,
        'attributed_distributor_id' => $distributorId,
        'self_consumption' => true,
        'idempotency_key' => uniqid('self-'),
        'created_at' => $date,
        'updated_at' => $date,
    ]);

    DB::table('bv_ledger_entries')->insert([
        'distributor_id' => $distributorId,
        'order_id' => $orderId,
        'bv_paise' => $bvPaise,
        'type' => 'accrual',
        'effective_at' => $date,
        'created_at' => $date,
        'updated_at' => $date,
    ]);
}

it('a full replay of the client GSB example produces forfeited rows for 7–8 Aug and a fresh cycle from 9 Aug', function (): void {
    // The client's 2026-09-07 example: anchored 7 July, nothing repurchased
    // until 9 August. The window closed unmet on 6 August, so 7 and 8 August are
    // forfeited outright and the fresh window opens on the fulfilment day.
    config(['arovolife.recompute.enabled' => true]);
    Feature::activate(GenosSalesBonusFeature::class);
    Feature::activate(RepurchaseEngineFeature::class);

    $subject = Distributor::factory()->create(['status' => 'active', 'depth' => 0]);
    DB::table('genealogy_closure')->insert([
        'ancestor_id' => $subject->id, 'descendant_id' => $subject->id, 'depth' => 0,
    ]);
    // Two per leg — a placement side takes one child, so the second sits under
    // the first and still counts to the same group. The subject then has real
    // Genos BV on each of the four days this test reads; without it the cut-off
    // takes the idle shortcut and writes no row at all, which would make the
    // "not forfeited" assertions pass against nothing.
    $leftDue = recomputePlaceChild($subject, 'L');
    $leftForfeited = recomputePlaceChild($leftDue, 'L');
    $rightForfeited = recomputePlaceChild($subject, 'R');
    $rightFulfilment = recomputePlaceChild($rightForfeited, 'R');

    // The anchor: personal purchases reach the 600-BV minimum on 7 July, which
    // is where the first window starts. Only the second half of that falls
    // inside the window, so the 600-BV obligation is not met by the anchor
    // itself — the distributor has to repurchase, and does not.
    recomputeSeedSelfPurchase($subject->id, 30_000, '2026-07-05 10:00:00');
    recomputeSeedSelfPurchase($subject->id, 30_000, '2026-07-07 10:00:00');
    // The late fulfilment, three days after the window closed on 6 August.
    recomputeSeedSelfPurchase($subject->id, 30_000, '2026-08-09 10:00:00');

    // 6 August (the due date) is one-sided, so it leaves a real carry-forward
    // standing — the asset the forfeited days must not move.
    recomputeSeedPaidOrder($leftDue->id, '2026-08-06 09:00:00', 500_000);
    recomputeSeedPaidOrder($leftForfeited->id, '2026-08-07 09:00:00', 500_000);
    recomputeSeedPaidOrder($rightForfeited->id, '2026-08-08 09:00:00', 500_000);
    recomputeSeedPaidOrder($rightFulfilment->id, '2026-08-09 09:00:00', 500_000);

    recomputeAsAt('2026-09-05 12:00:00', Carbon::parse('2026-07-01'));

    $rows = DB::table('gsb_cutoff_results')
        ->where('distributor_id', $subject->id)
        ->orderBy('cutoff_date')
        ->get()
        ->keyBy(fn (object $row): string => Carbon::parse($row->cutoff_date)->toDateString());

    foreach (['2026-08-06', '2026-08-07', '2026-08-08', '2026-08-09'] as $date) {
        expect($rows->has($date))->toBeTrue("no cut-off row for {$date}");
    }

    expect($rows['2026-08-07']->status)->toBe('repurchase_forfeited')
        ->and($rows['2026-08-08']->status)->toBe('repurchase_forfeited')
        // The due date itself is not forfeited, nor is the fulfilment day: both
        // ran the engine and settled normally on BV below any slab.
        ->and($rows['2026-08-06']->status)->toBe('no_match')
        ->and($rows['2026-08-09']->status)->toBe('no_match');

    // A forfeited day moves neither store and funds nothing: "the BVs on both
    // sides ... will stop there as assets" (client spec §2.1).
    foreach (['2026-08-07', '2026-08-08'] as $date) {
        expect((int) $rows[$date]->power_cf_after_paise)->toBe((int) $rows[$date]->power_cf_before_paise)
            ->and((int) $rows[$date]->slab1_weaker_cf_after_paise)->toBe((int) $rows[$date]->slab1_weaker_cf_before_paise)
            ->and((int) $rows[$date]->gross_gsb_paise)->toBe(0)
            ->and((int) $rows[$date]->net_gsb_paise)->toBe(0)
            ->and($rows[$date]->status)->not->toBeIn(GsbCutoffResult::POOL_FUNDED_STATUSES);
    }

    // ...and the fulfilment day resumes on top of the store exactly as the due
    // date left it, two forfeited days later.
    expect((int) $rows['2026-08-06']->power_cf_after_paise)->toBeGreaterThan(0)
        ->and((int) $rows['2026-08-09']->power_cf_before_paise)
        ->toBe((int) $rows['2026-08-06']->power_cf_after_paise)
        ->and((int) $rows['2026-08-09']->slab1_weaker_cf_before_paise)
        ->toBe((int) $rows['2026-08-06']->slab1_weaker_cf_after_paise)
        ->and($rows['2026-08-09']->power_side_before)->toBe($rows['2026-08-06']->power_side_after);

    $cycles = DB::table('repurchase_cycles')
        ->where('distributor_id', $subject->id)
        ->orderBy('cycle_start_date')
        ->get();

    expect($cycles->map(fn ($c): string => Carbon::parse($c->cycle_start_date)->toDateString())->all())
        ->toBe(['2026-07-07', '2026-08-09']);

    $first = $cycles->first();
    expect(Carbon::parse($first->due_date)->toDateString())->toBe('2026-08-06')
        ->and(Carbon::parse($first->fulfilled_on)->toDateString())->toBe('2026-08-09')
        ->and($first->status)->toBe('completed');
});

it('satisfies the rank check\'s repurchase guard from the 1st\'s own evaluation', function (): void {
    // rank:check-qualifications refuses without an evaluate run dated the 1st of
    // the FOLLOWING month — proof that every cycle due in the month has been
    // judged. The replay used to reach the check before that date existed and
    // had to FORCE it past its own guard. At the scheduler's clock the guard is
    // satisfied by construction: the check fires at 00:15 on the 1st, ten
    // minutes after that morning's 00:05 evaluation.
    config(['arovolife.recompute.enabled' => true]);
    Feature::activate(GenosSalesBonusFeature::class);
    Feature::activate(RepurchaseEngineFeature::class);
    Feature::activate(RankBonusFeature::class);

    $distributor = Distributor::factory()->create(['status' => 'active', 'depth' => 0]);
    DB::table('genealogy_closure')->insert([
        'ancestor_id' => $distributor->id, 'descendant_id' => $distributor->id, 'depth' => 0,
    ]);

    $report = recomputeAsAt('2026-09-03 12:00:00', Carbon::parse('2026-08-28'));

    expect($report->enginesRun)->toHaveKey('rank:check-qualifications');

    $run = EngineRun::where('engine_key', 'rank.check')->latest('id')->firstOrFail();

    expect($run->status)->toBe(EngineRun::STATUS_SUCCEEDED)
        ->and(Carbon::parse($run->period_start)->toDateString())->toBe('2026-08-01')
        ->and($run->started_at->format('Y-m-d H:i'))->toBe('2026-09-01 00:15');
});

it('runs the day\'s repurchase evaluation before the engines of that morning that need it', function (): void {
    // A replay whose window reaches the 1st of a month runs rank:check for the
    // month that just closed, and that check needs an evaluate run dated the
    // 1st. Both fire that morning — 00:05 and 00:15 — and the loop runs a day's
    // engines in scheduled-time order, so the order holds by itself.
    Feature::activate(GenosSalesBonusFeature::class);
    Feature::activate(RepurchaseEngineFeature::class);
    Feature::activate(RankBonusFeature::class);

    $distributor = Distributor::factory()->create(['status' => 'active', 'depth' => 0]);
    DB::table('genealogy_closure')->insert([
        'ancestor_id' => $distributor->id, 'descendant_id' => $distributor->id, 'depth' => 0,
    ]);

    Carbon::setTestNow(Carbon::parse('2026-09-01 02:00:00'));

    try {
        app(EngineReplayService::class)->replay(
            Carbon::parse('2026-08-28'),
            Carbon::parse('2026-09-01 02:00:00'),
        );
    } finally {
        Carbon::setTestNow();
    }

    expect(EngineRun::where('engine_key', 'repurchase.evaluate')
        ->whereDate('period_start', '2026-09-01')
        ->where('status', EngineRun::STATUS_SUCCEEDED)
        ->exists())->toBeTrue();

    $check = EngineRun::where('engine_key', 'rank.check')
        ->whereDate('period_start', '2026-08-01')
        ->latest('id')
        ->firstOrFail();

    expect($check->status)->toBe(EngineRun::STATUS_SUCCEEDED);
});

/*
|--------------------------------------------------------------------------
| The weekly payout batch is dated a Tuesday, whatever day the horizon is
|--------------------------------------------------------------------------
|
| The batch command refuses any --date that is not a Tuesday. Nothing hands it
| one any more — it is fired by its own weekly cadence, on Tuesdays, like the
| scheduler does — but the clock is pinned here so the expectation holds on
| every weekday. 8 Sep 2026 is a Tuesday; 10 Sep 2026 a Thursday.
*/

it('fires the weekly payout only on the Tuesdays inside the window', function (): void {
    Feature::activate(GenosSalesBonusFeature::class);

    $distributor = Distributor::factory()->create(['status' => 'active', 'depth' => 0]);
    DB::table('genealogy_closure')->insert([
        'ancestor_id' => $distributor->id, 'descendant_id' => $distributor->id, 'depth' => 0,
    ]);

    Carbon::setTestNow(Carbon::parse('2026-09-10 12:00:00')); // Thursday

    try {
        $result = app(EngineReplayService::class)->replay(
            Carbon::parse('2026-09-07'),
            Carbon::parse('2026-09-10 12:00:00'),
        );
    } finally {
        Carbon::setTestNow();
    }

    expect($result['engines']['gsb:weekly-payout'] ?? 0)->toBe(1);

    $run = EngineRun::where('engine_key', 'gsb.weekly-payout')->sole();

    expect($run->status)->toBe(EngineRun::STATUS_SUCCEEDED)
        ->and(Carbon::parse($run->period_start)->toDateString())->toBe('2026-09-08')
        ->and($run->started_at->format('Y-m-d H:i'))->toBe('2026-09-08 03:00');
});

it('does not invent a weekly batch for a Tuesday the window never reached', function (): void {
    // The catch-up pass used to write an empty `pending` batch dated the
    // previous Tuesday whenever the horizon was not one — a row the real
    // scheduler would never have written, accepted by the client on 2026-09-10
    // only because the tool was temporary. It is not written any more.
    Feature::activate(GenosSalesBonusFeature::class);

    $distributor = Distributor::factory()->create(['status' => 'active', 'depth' => 0]);
    DB::table('genealogy_closure')->insert([
        'ancestor_id' => $distributor->id, 'descendant_id' => $distributor->id, 'depth' => 0,
    ]);

    Carbon::setTestNow(Carbon::parse('2026-09-10 12:00:00')); // Thursday

    try {
        $result = app(EngineReplayService::class)->replay(
            Carbon::parse('2026-09-09'),
            Carbon::parse('2026-09-10 12:00:00'),
        );
    } finally {
        Carbon::setTestNow();
    }

    expect($result['engines']['gsb:weekly-payout'] ?? 0)->toBe(0);
    expect(DB::table('payout_batches')->where('batch_type', 'weekly')->count())->toBe(0);
});
