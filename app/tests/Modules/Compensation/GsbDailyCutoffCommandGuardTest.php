<?php

declare(strict_types=1);

use App\Modules\Commerce\Models\BvLedgerEntry;
use App\Modules\Compensation\Models\EngineRun;
use App\Modules\Compensation\Models\GroupBvDaily;
use App\Modules\Compensation\Models\GsbCutoffDeferral;
use App\Modules\Compensation\Models\GsbCutoffResult;
use App\Modules\Compensation\Models\GsbDailyPool;
use App\Modules\Compensation\Models\MentorshipBonusResult;
use App\Modules\Compensation\Models\MsbDailyPool;
use App\Modules\Compensation\Models\WalletLedgerEntry;
use App\Modules\Compensation\Services\EngineStatusService;
use App\Modules\Compensation\Services\GsbCutoffService;
use App\Modules\Compensation\Support\EngineRegistry;
use App\Modules\Compliance\Models\AuditLog;
use App\Modules\Identity\Models\Distributor;
use App\Modules\Shared\Features\GenosSalesBonusFeature;
use App\Modules\Shared\Features\GsbDailyPoolPricingFeature;
use App\Modules\Shared\Features\MentorshipBonusFeature;
use App\Modules\Shared\Features\RepurchaseEngineFeature;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Laravel\Pennant\Feature;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    disableTestForeignKeys();
    Feature::for(null)->activate(GenosSalesBonusFeature::class);
});

/**
 * A succeeded `repurchase:evaluate` run for the given period.
 *
 * `$startedAt` defaults to the scheduled 00:05 of the period itself — the run
 * the cron actually produces. Pass it explicitly to model a re-run or a manual
 * trigger that happened after the day it was dated for had ended.
 */
function seedEvaluateRun(string $period, ?string $startedAt = null): void
{
    $started = Carbon::parse($startedAt ?? $period.' 00:05:00');

    EngineRun::create([
        'engine_key' => 'repurchase.evaluate',
        'period_start' => $period,
        'status' => EngineRun::STATUS_SUCCEEDED,
        'trigger' => EngineRun::TRIGGER_CONSOLE,
        'started_at' => $started,
        'finished_at' => $started->copy()->addMinute(),
    ]);
}

it('refuses when no evaluate run has seen the whole cut-off day', function (): void {
    // The repurchase verdict this cut-off reads is written by exactly one
    // process. Running before it would credit days the client's rules forfeit —
    // permanently, with no later correction.
    Feature::for(null)->activate(RepurchaseEngineFeature::class);
    seedEvaluateRun('2026-08-24'); // the day BEFORE — not far enough

    Log::shouldReceive('critical')
        ->once()
        ->withArgs(fn (string $message, array $context): bool => $message === 'gsb.cutoff.refused_missing_evaluate'
            && $context['date'] === '2026-08-25');

    $exitCode = Artisan::call('gsb:daily-cutoff', ['--date' => '2026-08-25']);

    expect($exitCode)->toBe(1)
        ->and(Artisan::output())->toContain('repurchase:evaluate --date=2026-08-26')
        ->and(GsbCutoffResult::count())->toBe(0);
});

it('names the distributors the evaluation could not judge when it refuses', function (): void {
    // F23: `repurchase:evaluate` isolates a throwing distributor and carries
    // on. Above the skip cap (E5) the run finishes as `failed`, with a
    // `failed_partial` summary, and the gate stays shut platform-wide — but the
    // operator is told which ADNs to fix instead of being sent to the log.
    Feature::for(null)->activate(RepurchaseEngineFeature::class);

    EngineRun::create([
        'engine_key' => 'repurchase.evaluate',
        'period_start' => '2026-08-26',
        'status' => EngineRun::STATUS_FAILED,
        'trigger' => EngineRun::TRIGGER_CONSOLE,
        'started_at' => Carbon::parse('2026-08-26 00:05:00'),
        'finished_at' => Carbon::parse('2026-08-26 00:06:00'),
        'summary' => [
            'outcome' => 'failed_partial',
            'evaluated' => 6,
            'failed' => 600,
            'failed_adns' => ['ADN12345'],
            'failure_classes' => ['RuntimeException'],
        ],
    ]);

    Log::shouldReceive('critical')
        ->once()
        ->withArgs(fn (string $message, array $context): bool => $message === 'gsb.cutoff.refused_missing_evaluate'
            && $context['evaluate_failures'] === 600
            && $context['evaluate_failed_adns'] === ['ADN12345']);

    expect(Artisan::call('gsb:daily-cutoff', ['--date' => '2026-08-25']))->toBe(1);

    // Read once: Artisan::output() drains the buffer.
    $output = Artisan::output();

    expect($output)->toContain('ADN12345')
        ->and($output)->toContain('more than the')
        ->and(GsbCutoffResult::count())->toBe(0);
});

it('says nothing about failures when the evaluation simply never ran', function (): void {
    Feature::for(null)->activate(RepurchaseEngineFeature::class);

    expect(Artisan::call('gsb:daily-cutoff', ['--date' => '2026-08-25']))->toBe(1)
        ->and(Artisan::output())->not->toContain('threw and still carry');
});

it('records a FAILED engine run when refused', function (): void {
    Feature::for(null)->activate(RepurchaseEngineFeature::class);

    expect(Artisan::call('gsb:daily-cutoff', ['--date' => '2026-08-25']))->toBe(1);

    $run = EngineRun::where('engine_key', 'gsb.daily-cutoff')->latest('id')->first();

    expect($run)->not->toBeNull();
    expect($run->status)->toBe(EngineRun::STATUS_FAILED);
    expect($run->period_start->toDateString())->toBe('2026-08-25');
});

it('runs when a later evaluate run exists', function (): void {
    Feature::for(null)->activate(RepurchaseEngineFeature::class);
    seedEvaluateRun('2026-08-26');

    expect(Artisan::call('gsb:daily-cutoff', ['--date' => '2026-08-25']))->toBe(0);
});

it('refuses an evaluate run dated the cut-off day that ran at 00:05 that morning', function (): void {
    // The scheduled run for D starts at 00:05 ON D, so a fulfilment purchase
    // made later that day is invisible to it. Accepting it would let the
    // cut-off forfeit a day the distributor actually fulfilled.
    Feature::for(null)->activate(RepurchaseEngineFeature::class);
    seedEvaluateRun('2026-08-25');

    expect(Artisan::call('gsb:daily-cutoff', ['--date' => '2026-08-25']))->toBe(1)
        ->and(GsbCutoffResult::count())->toBe(0);
});

it('runs when the evaluate run for the cut-off day started after that day ended', function (): void {
    // A re-run or manual trigger the next morning has seen every purchase made
    // on the cut-off day, so it is proof enough even dated for that day.
    Feature::for(null)->activate(RepurchaseEngineFeature::class);
    seedEvaluateRun('2026-08-25', '2026-08-26 00:05:00');

    expect(Artisan::call('gsb:daily-cutoff', ['--date' => '2026-08-25']))->toBe(0);
});

it('runs with --force despite the missing evaluate run', function (): void {
    Feature::for(null)->activate(RepurchaseEngineFeature::class);

    expect(Artisan::call('gsb:daily-cutoff', ['--date' => '2026-08-25', '--force' => true]))->toBe(0);
});

it('skips the guard entirely when the repurchase engine is off', function (): void {
    // Flag off = no forfeits anywhere, so the cut-off has nothing to wait for.
    expect(Artisan::call('gsb:daily-cutoff', ['--date' => '2026-08-25']))->toBe(0);
});

it('the replay fires repurchase:evaluate before the cut-off on every replayed day', function (): void {
    // EngineReplayService orders each day's engines by their cadence time, so
    // the guard is satisfiable during a replay only while evaluate's scheduled
    // time precedes the cut-off's. If that ever inverts, `recompute-all` starts
    // refusing every day it replays.
    $evaluate = EngineRegistry::get('repurchase.evaluate')->cadence;
    $cutoff = EngineRegistry::get('gsb.daily-cutoff')->cadence;

    expect($evaluate->isScheduled())->toBeTrue();
    expect($cutoff->isScheduled())->toBeTrue();
    expect($evaluate->time)->toBeLessThan($cutoff->time);
});

it('refuses a cut-off for today, because the day has not ended', function (): void {
    // F31: the CLI default is today. The cut-off freezes the day's GSB and MSB
    // pools on whatever BV exists at that instant and the scheduled run after
    // midnight keeps that pricing — the 24 Aug 2026 staging incident.
    $exitCode = Artisan::call('gsb:daily-cutoff');
    $output = Artisan::output();

    expect($exitCode)->toBe(1)
        ->and($output)->toContain('that day has not ended')
        ->and(GsbCutoffResult::count())->toBe(0);
});

it('refuses a cut-off dated in the future', function (): void {
    expect(Artisan::call('gsb:daily-cutoff', ['--date' => Carbon::tomorrow()->toDateString()]))->toBe(1)
        ->and(GsbCutoffResult::count())->toBe(0);
});

it('--force does not lift the closed-day guard, because it answers a different question', function (): void {
    // --force says "run without a repurchase verdict for the day". It is not a
    // statement that a partial day may be frozen, and reading it as one is how
    // the only barrier in front of a same-day cut-off came to be liftable.
    expect(Artisan::call('gsb:daily-cutoff', ['--force' => true]))->toBe(1)
        ->and(GsbCutoffResult::count())->toBe(0);
});

it('--in-flight runs today deliberately, for a provisional test cut-off', function (): void {
    expect(Artisan::call('gsb:daily-cutoff', ['--in-flight' => true]))->toBe(0);
});

it('runs a closed day with no override at all', function (): void {
    expect(Artisan::call('gsb:daily-cutoff', ['--date' => Carbon::yesterday()->toDateString()]))->toBe(0);
});

it('records a SKIPPED run when a later cut-off has already passed the day, and the day stays proven', function (): void {
    // A1/R-91. The carry-forward store is rolling, so a day a later cut-off has
    // passed cannot be recomputed — an ordering decision, not an attempt that
    // failed. Recorded as `failed` it would un-prove a day that was cut off
    // correctly (D13: the latest finished attempt decides), and the month
    // containing it would be unclosable over a refusal nobody can clear.
    $day = Carbon::today()->subDays(2);
    $next = $day->copy()->addDay();

    $distributor = Distributor::factory()->create(['status' => 'active', 'adn' => '100000001']);
    BvLedgerEntry::create([
        'distributor_id' => $distributor->id,
        'order_id' => 999_101,
        'bv_paise' => 300_000,
        'type' => 'accrual',
        'effective_at' => $day->copy()->subDay(),
    ]);

    // Below the slab-1 threshold on both days: the cut-off records `no_match`,
    // which still advances the rolling store. A CREDITED day would never reach
    // the guard — the engine's idempotency check returns first.
    foreach ([$day, $next] as $date) {
        GroupBvDaily::create([
            'distributor_id' => $distributor->id,
            'date' => $date->toDateString(),
            'left_bv_paise' => 1_000_000,
            'right_bv_paise' => 800_000,
        ]);
    }

    // The day was cut off correctly; the next day then advanced the store.
    app(GsbCutoffService::class)->runForDistributor($distributor->id, $day);
    app(GsbCutoffService::class)->runForDistributor($distributor->id, $next);

    // The run row the chain recorded for the day, started once the day ended.
    EngineRun::create([
        'engine_key' => 'gsb.daily-cutoff',
        'period_start' => $day->toDateString(),
        'status' => EngineRun::STATUS_SUCCEEDED,
        'trigger' => EngineRun::TRIGGER_CONSOLE,
        'started_at' => $next->copy()->setTime(0, 6),
        'finished_at' => $next->copy()->setTime(0, 8),
    ]);

    $exitCode = Artisan::call('gsb:daily-cutoff', ['--date' => $day->toDateString()]);

    $run = EngineRun::where('engine_key', 'gsb.daily-cutoff')->latest('id')->first();

    // Non-zero all the same: the caller asked for work that did not happen.
    expect($exitCode)->toBe(1)
        ->and($run->period_start->toDateString())->toBe($day->toDateString())
        ->and($run->status)->toBe(EngineRun::STATUS_SKIPPED)
        ->and($run->error)->toContain('only while it is the newest one')
        ->and(app(EngineStatusService::class)->completedCutoffDatesBetween($day, $day))
        ->toBe([$day->toDateString()]);
});

it('refuses while another cut-off run is in flight, as a skipped run', function (): void {
    Feature::for(null)->activate(RepurchaseEngineFeature::class);
    seedEvaluateRun('2026-08-26');
    Carbon::setTestNow('2026-08-26 00:10:00');

    EngineRun::create([
        'engine_key' => 'gsb.daily-cutoff',
        'period_start' => '2026-08-25',
        'status' => EngineRun::STATUS_RUNNING,
        'trigger' => EngineRun::TRIGGER_MANUAL,
        'started_at' => Carbon::parse('2026-08-26 00:06:00'),
    ]);

    expect(Artisan::call('gsb:daily-cutoff', ['--date' => '2026-08-25']))->toBe(1)
        ->and(Artisan::output())->toContain('still in flight')
        ->and(GsbCutoffResult::count())->toBe(0);

    $own = EngineRun::where('engine_key', 'gsb.daily-cutoff')->where('trigger', EngineRun::TRIGGER_CONSOLE)->latest('id')->first();
    expect($own->status)->toBe(EngineRun::STATUS_SKIPPED)
        ->and($own->error)->toContain('still in flight');
});

it('--force does not lift the in-flight guard', function (): void {
    Feature::for(null)->activate(RepurchaseEngineFeature::class);
    Carbon::setTestNow('2026-08-26 00:10:00');
    EngineRun::create([
        'engine_key' => 'gsb.daily-cutoff',
        'period_start' => '2026-08-25',
        'status' => EngineRun::STATUS_RUNNING,
        'trigger' => EngineRun::TRIGGER_MANUAL,
        'started_at' => Carbon::parse('2026-08-26 00:06:00'),
    ]);

    expect(Artisan::call('gsb:daily-cutoff', ['--date' => '2026-08-25', '--force' => true]))->toBe(1);
});

// ── Deferred cut-offs (E5 redesign, 2026-09-27) ─────────────────────────────

/**
 * A succeeded `repurchase:evaluate` run for the period that could not judge
 * the given distributors — the `completed_with_skips` shape the command writes.
 *
 * @param  list<int>  $ids
 * @param  list<string>  $adns
 */
function seedEvaluateRunWithDeferrals(string $period, array $ids, array $adns, ?string $startedAt = null): EngineRun
{
    $started = Carbon::parse($startedAt ?? $period.' 00:05:00');

    return EngineRun::create([
        'engine_key' => 'repurchase.evaluate',
        'period_start' => $period,
        'status' => EngineRun::STATUS_SUCCEEDED,
        'trigger' => EngineRun::TRIGGER_CONSOLE,
        'started_at' => $started,
        'finished_at' => $started->copy()->addMinute(),
        'summary' => [
            'outcome' => $ids === [] ? 'completed' : 'completed_with_skips',
            'failed' => count($ids),
            'failed_adns' => $adns,
            'failed_distributor_ids' => $ids,
        ],
    ]);
}

/**
 * A slab-N achiever (the GsbDailyCutoffCommandTest seedSlabAchiever() shape)
 * whose group BV matches the slab on each of $days, with a sponsor who clears
 * the MSB minimum BV. Personal BV is dated before the first day so it stays
 * out of every day's company BV.
 *
 * @param  list<string>  $days
 * @return array{0: Distributor, 1: Distributor} [achiever, sponsor]
 */
function seedDeferralAchiever(int $slab, array $days): array
{
    static $seq = 0;
    $titleMinBySlab = [1 => 300_000, 3 => 1_500_000];
    $thresholdBySlab = [1 => 1_500_000, 3 => 10_000_000];
    $before = Carbon::parse($days[0])->subDay();

    $achiever = Distributor::factory()->create(['status' => 'active', 'adn' => '3000'.str_pad((string) ++$seq, 5, '0', STR_PAD_LEFT)]);
    $sponsor = Distributor::factory()->create(['status' => 'active', 'adn' => '3100'.str_pad((string) $seq, 5, '0', STR_PAD_LEFT)]);

    BvLedgerEntry::create(['distributor_id' => $achiever->id, 'order_id' => 910_000 + $seq, 'bv_paise' => $titleMinBySlab[$slab], 'type' => 'accrual', 'effective_at' => $before]);
    BvLedgerEntry::create(['distributor_id' => $sponsor->id, 'order_id' => 920_000 + $seq, 'bv_paise' => 60_000, 'type' => 'accrual', 'effective_at' => $before]);
    DB::table('sponsorship')->insert(['sponsor_id' => $sponsor->id, 'distributor_id' => $achiever->id, 'created_at' => $before]);

    foreach ($days as $day) {
        GroupBvDaily::create([
            'distributor_id' => $achiever->id, 'date' => $day,
            'left_bv_paise' => $thresholdBySlab[$slab], 'right_bv_paise' => $thresholdBySlab[$slab],
        ]);
    }

    return [$achiever, $sponsor];
}

/** The company's turnover BV on each day, carried by one accrual per day. */
function seedDeferralCompanyBv(array $days, int $bvPaise = 100_000_000): void
{
    $dummy = Distributor::factory()->create(['status' => 'active', 'adn' => '399999999']);

    foreach ($days as $i => $day) {
        BvLedgerEntry::create([
            'distributor_id' => $dummy->id, 'order_id' => 930_000 + $i,
            'bv_paise' => $bvPaise, 'type' => 'accrual', 'effective_at' => Carbon::parse($day)->setTime(12, 0),
        ]);
    }
}

/** Every flag the deferral path touches, on. */
function activateDeferralFeatures(): void
{
    Feature::for(null)->activate(RepurchaseEngineFeature::class);
    Feature::for(null)->activate(GsbDailyPoolPricingFeature::class);
    Feature::for(null)->activate(MentorshipBonusFeature::class);
}

it('reserves a deferred distributor\'s share in the frozen pools and writes a deferral instead of a row', function (): void {
    Carbon::setTestNow('2026-08-27 00:30:00');
    activateDeferralFeatures();

    seedDeferralCompanyBv(['2026-08-25']);
    [$achiever] = seedDeferralAchiever(3, ['2026-08-25']);
    [$pairSponsee] = seedDeferralAchiever(1, ['2026-08-25']);
    seedEvaluateRunWithDeferrals('2026-08-26', [$achiever->id], [$achiever->adn]);

    expect(Artisan::call('gsb:daily-cutoff', ['--date' => '2026-08-25']))->toBe(0);
    expect(Artisan::output())->toContain('deferred: 1');

    $deferral = GsbCutoffDeferral::where('distributor_id', $achiever->id)->sole();
    expect($deferral->cutoff_date->toDateString())->toBe('2026-08-25')
        ->and($deferral->reserved_slab)->toBe(3)
        ->and($deferral->reserved_gsb_paise)->toBeGreaterThan(0)
        ->and($deferral->reserved_msb_points)->toBe(15)
        ->and($deferral->resolved_at)->toBeNull();
    expect(GsbCutoffResult::where('distributor_id', $achiever->id)->exists())->toBeFalse()
        ->and(WalletLedgerEntry::where('distributor_id', $achiever->id)->exists())->toBeFalse();

    // Reserved: the pool's variable score total is the deferred slab-3 score,
    // and the reserved gross is that score at the frozen value.
    $pool = GsbDailyPool::whereDate('cutoff_date', '2026-08-25')->sole();
    expect($pool->variable_total_score)->toBe(32)
        ->and($deferral->reserved_gsb_paise)->toBe(32 * $pool->variable_score_value_paise);

    // The MSB denominator carries the deferred sponsee's 15 points beside the
    // credited slab-1 sponsee's 21, and only the credited one is paid.
    expect(MsbDailyPool::whereDate('cutoff_date', '2026-08-25')->sole()->total_points)->toBe(36)
        ->and(GsbCutoffResult::where('distributor_id', $pairSponsee->id)->value('status'))->toBe(GsbCutoffResult::STATUS_CREDITED)
        ->and(MentorshipBonusResult::count())->toBe(1);
});

it('writes the deferral even when pool pricing is off', function (): void {
    Carbon::setTestNow('2026-08-27 00:30:00');
    Feature::for(null)->activate(RepurchaseEngineFeature::class);
    $d = Distributor::factory()->create(['status' => 'active']);
    seedEvaluateRunWithDeferrals('2026-08-26', [$d->id], [$d->adn]);

    expect(Artisan::call('gsb:daily-cutoff', ['--date' => '2026-08-25']))->toBe(0)
        ->and(GsbCutoffDeferral::where('distributor_id', $d->id)->exists())->toBeTrue()
        ->and(GsbCutoffResult::where('distributor_id', $d->id)->exists())->toBeFalse()
        ->and(GsbDailyPool::count())->toBe(0);
});

/**
 * The night of 26 Aug: the achiever's evaluation threw, so the 25 Aug full
 * cut-off deferred them. Group BV and company BV exist for 25 and 26 Aug.
 */
function deferAchieverOn25th(): Distributor
{
    Carbon::setTestNow('2026-08-27 00:30:00');
    activateDeferralFeatures();

    seedDeferralCompanyBv(['2026-08-25', '2026-08-26']);
    [$achiever] = seedDeferralAchiever(3, ['2026-08-25', '2026-08-26']);
    seedEvaluateRunWithDeferrals('2026-08-26', [$achiever->id], [$achiever->adn]);

    expect(Artisan::call('gsb:daily-cutoff', ['--date' => '2026-08-25']))->toBe(0);
    expect(GsbCutoffDeferral::open()->where('distributor_id', $achiever->id)->count())->toBe(1);

    return $achiever;
}

it('backfills an open deferral before computing the new day, and advances the store in order', function (): void {
    $achiever = deferAchieverOn25th();
    seedEvaluateRun('2026-08-27', '2026-08-27 00:05:00');   // clean run, no failed ids

    expect(Artisan::call('gsb:daily-cutoff', ['--date' => '2026-08-26']))->toBe(0)
        ->and(Artisan::output())->toContain('Backfilled 1 deferred cut-off(s)');

    $deferral = GsbCutoffDeferral::where('distributor_id', $achiever->id)->sole();
    expect($deferral->resolution)->toBe(GsbCutoffDeferral::RESOLUTION_BACKFILLED)
        ->and($deferral->gsb_cutoff_result_id)->not->toBeNull();

    $rows = GsbCutoffResult::where('distributor_id', $achiever->id)->orderBy('cutoff_date')->orderBy('id')->get();
    expect($rows->map(fn (GsbCutoffResult $r): string => $r->cutoff_date->toDateString())->all())->toBe(['2026-08-25', '2026-08-26'])
        ->and($rows->pluck('status')->unique()->all())->toBe([GsbCutoffResult::STATUS_CREDITED])
        ->and($rows->first()->id)->toBe($deferral->gsb_cutoff_result_id);

    // Paid at the frozen 25 Aug value, not recomputed.
    expect($rows->first()->score_value_paise)
        ->toBe(GsbDailyPool::whereDate('cutoff_date', '2026-08-25')->sole()->variable_score_value_paise);

    // The sponsor's MB for the 25th is credited at that day's frozen point value.
    $msb25 = MsbDailyPool::whereDate('cutoff_date', '2026-08-25')->sole();
    $mb = MentorshipBonusResult::where('sponsee_id', $achiever->id)->whereDate('cutoff_date', '2026-08-25')->sole();
    expect($mb->msb_points)->toBe(15)
        ->and($mb->msb_point_value_paise)->toBe($msb25->point_value_paise);
});

it('keeps a deferral open while the distributor still fails evaluation, and adds one for the new day', function (): void {
    $achiever = deferAchieverOn25th();
    seedEvaluateRunWithDeferrals('2026-08-27', [$achiever->id], [$achiever->adn]);

    expect(Artisan::call('gsb:daily-cutoff', ['--date' => '2026-08-26']))->toBe(0);

    expect(GsbCutoffDeferral::open()->where('distributor_id', $achiever->id)->orderBy('cutoff_date')->get()
        ->map(fn (GsbCutoffDeferral $d): string => $d->cutoff_date->toDateString())->all())
        ->toBe(['2026-08-25', '2026-08-26'])
        ->and(GsbCutoffResult::where('distributor_id', $achiever->id)->exists())->toBeFalse();

    // Both are backfilled oldest-first the first night they evaluate cleanly.
    seedEvaluateRun('2026-08-28', '2026-08-28 00:05:00');
    Carbon::setTestNow('2026-08-28 00:30:00');

    expect(Artisan::call('gsb:daily-cutoff', ['--date' => '2026-08-27']))->toBe(0);
    expect(GsbCutoffDeferral::open()->count())->toBe(0)
        ->and(GsbCutoffResult::where('distributor_id', $achiever->id)->orderBy('cutoff_date')->get()
            ->map(fn (GsbCutoffResult $r): string => $r->cutoff_date->toDateString())->all())
        ->toBe(['2026-08-25', '2026-08-26', '2026-08-27']);
});

it('never backfills an inactive distributor, and leaves the row open', function (): void {
    $achiever = deferAchieverOn25th();
    $achiever->update(['status' => 'inactive']);
    seedEvaluateRun('2026-08-27', '2026-08-27 00:05:00');

    expect(Artisan::call('gsb:daily-cutoff', ['--date' => '2026-08-26']))->toBe(0);
    expect(GsbCutoffDeferral::open()->count())->toBe(1)
        ->and(GsbCutoffResult::where('distributor_id', $achiever->id)->exists())->toBeFalse();
});

it('refuses a by-name run of a deferred day without --force, and resolves it as manual with it', function (): void {
    $achiever = deferAchieverOn25th();

    // A later day by name would overtake the owed one: refused even with --force.
    expect(Artisan::call('gsb:daily-cutoff', ['--date' => '2026-08-26', '--distributor' => (string) $achiever->id, '--force' => true]))->toBe(1)
        ->and(Artisan::output())->toContain('still open');

    expect(Artisan::call('gsb:daily-cutoff', ['--date' => '2026-08-25', '--distributor' => (string) $achiever->id]))->toBe(1)
        ->and(Artisan::output())->toContain('backfill automatically');
    expect(GsbCutoffResult::where('distributor_id', $achiever->id)->exists())->toBeFalse();

    expect(Artisan::call('gsb:daily-cutoff', ['--date' => '2026-08-25', '--distributor' => (string) $achiever->id, '--force' => true]))->toBe(0);
    $deferral = GsbCutoffDeferral::sole();
    expect($deferral->resolution)->toBe(GsbCutoffDeferral::RESOLUTION_MANUAL)
        ->and($deferral->gsb_cutoff_result_id)->toBe(GsbCutoffResult::where('distributor_id', $achiever->id)->value('id'));
});

it('does not backfill a deferral a by-name run already settled', function (): void {
    $achiever = deferAchieverOn25th();
    expect(Artisan::call('gsb:daily-cutoff', ['--date' => '2026-08-25', '--distributor' => (string) $achiever->id, '--force' => true]))->toBe(0);

    seedEvaluateRun('2026-08-27', '2026-08-27 00:05:00');
    expect(Artisan::call('gsb:daily-cutoff', ['--date' => '2026-08-26']))->toBe(0);

    expect(GsbCutoffResult::where('distributor_id', $achiever->id)->count())->toBe(2)
        ->and(WalletLedgerEntry::where('distributor_id', $achiever->id)->where('type', 'gsb_credit')->count())->toBe(2)
        ->and(GsbCutoffDeferral::sole()->resolution)->toBe(GsbCutoffDeferral::RESOLUTION_MANUAL);
});

// ── Review fixes (N1, M1–M5, L1–L5), 2026-09-27 ─────────────────────────────

/**
 * A slab-1-titled achiever (3,000 BV personal, dated before the first day) with
 * a sponsor over the MSB minimum, and the given [left, right] group BV (paise)
 * on each day.
 *
 * @param  array<string, array{0: int, 1: int}>  $legsByDay
 * @return array{0: Distributor, 1: Distributor} [achiever, sponsor]
 */
function seedLeggedAchiever(array $legsByDay): array
{
    static $seq = 0;
    $before = Carbon::parse(array_key_first($legsByDay))->subDay();
    $seq++;

    $achiever = Distributor::factory()->create(['status' => 'active', 'adn' => '3200'.str_pad((string) $seq, 5, '0', STR_PAD_LEFT)]);
    $sponsor = Distributor::factory()->create(['status' => 'active', 'adn' => '3300'.str_pad((string) $seq, 5, '0', STR_PAD_LEFT)]);

    BvLedgerEntry::create(['distributor_id' => $achiever->id, 'order_id' => 940_000 + $seq, 'bv_paise' => 300_000, 'type' => 'accrual', 'effective_at' => $before]);
    BvLedgerEntry::create(['distributor_id' => $sponsor->id, 'order_id' => 950_000 + $seq, 'bv_paise' => 60_000, 'type' => 'accrual', 'effective_at' => $before]);
    DB::table('sponsorship')->insert(['sponsor_id' => $sponsor->id, 'distributor_id' => $achiever->id, 'created_at' => $before]);

    foreach ($legsByDay as $day => [$left, $right]) {
        GroupBvDaily::create([
            'distributor_id' => $achiever->id, 'date' => $day,
            'left_bv_paise' => $left, 'right_bv_paise' => $right,
        ]);
    }

    return [$achiever, $sponsor];
}

it('reserves a still-deferred day on top of the days the distributor is owed (N1)', function (): void {
    // The review's example: store empty; 25 Aug is L 10,000 / R 8,000 (no
    // match); 26 Aug is L 6,000 / R 8,000. On the unadvanced store the 26th
    // matches nothing, but after the 25th settles it is L 16,000 / R 8,000 with
    // 8,000 slab-1 CF — slab 1. The 26th's reservation must include it.
    Carbon::setTestNow('2026-08-27 00:30:00');
    activateDeferralFeatures();
    seedDeferralCompanyBv(['2026-08-25', '2026-08-26', '2026-08-27']);
    [$achiever] = seedLeggedAchiever([
        '2026-08-25' => [1_000_000, 800_000],
        '2026-08-26' => [600_000, 800_000],
    ]);

    seedEvaluateRunWithDeferrals('2026-08-26', [$achiever->id], [$achiever->adn]);
    expect(Artisan::call('gsb:daily-cutoff', ['--date' => '2026-08-25']))->toBe(0);

    seedEvaluateRunWithDeferrals('2026-08-27', [$achiever->id], [$achiever->adn]);
    expect(Artisan::call('gsb:daily-cutoff', ['--date' => '2026-08-26']))->toBe(0);

    $d25 = GsbCutoffDeferral::where('distributor_id', $achiever->id)->whereDate('cutoff_date', '2026-08-25')->sole();
    $d26 = GsbCutoffDeferral::where('distributor_id', $achiever->id)->whereDate('cutoff_date', '2026-08-26')->sole();
    $pool26 = GsbDailyPool::whereDate('cutoff_date', '2026-08-26')->sole();

    expect($d25->reserved_slab)->toBeNull()
        ->and($d26->reserved_slab)->toBe(1)
        ->and($d26->reserved_gsb_paise)->toBe(200_000)
        ->and($pool26->fixed_payout_paise)->toBe(200_000);

    // The night they evaluate cleanly: both days backfill in order, and the
    // 26th pays exactly what its pool set aside.
    Carbon::setTestNow('2026-08-28 00:30:00');
    seedEvaluateRun('2026-08-28', '2026-08-28 00:05:00');
    expect(Artisan::call('gsb:daily-cutoff', ['--date' => '2026-08-27']))->toBe(0);

    $rows = GsbCutoffResult::where('distributor_id', $achiever->id)->orderBy('cutoff_date')->get();
    expect($rows->take(2)->map(fn (GsbCutoffResult $r): array => [$r->cutoff_date->toDateString(), $r->status, $r->slab])->all())
        ->toBe([
            ['2026-08-25', GsbCutoffResult::STATUS_NO_MATCH, null],
            ['2026-08-26', GsbCutoffResult::STATUS_CREDITED, 1],
        ])
        ->and($rows[1]->gross_gsb_paise)->toBe($d26->reserved_gsb_paise)
        ->and(GsbDailyPool::whereDate('cutoff_date', '2026-08-26')->sole()->leftover_paise)->toBe($pool26->leftover_paise);
});

it('records a backfill that pays more than the night reserved, in the row, the audit log and the log (N1)', function (): void {
    // The deferring night saw no group BV for the achiever and reserved nothing;
    // BV arriving for that day afterwards makes the fresh backfill match slab 1.
    Carbon::setTestNow('2026-08-27 00:30:00');
    activateDeferralFeatures();
    seedDeferralCompanyBv(['2026-08-25', '2026-08-26']);
    [$achiever] = seedLeggedAchiever(['2026-08-25' => [0, 0]]);

    seedEvaluateRunWithDeferrals('2026-08-26', [$achiever->id], [$achiever->adn]);
    expect(Artisan::call('gsb:daily-cutoff', ['--date' => '2026-08-25']))->toBe(0);

    $deferral = GsbCutoffDeferral::where('distributor_id', $achiever->id)->sole();
    expect($deferral->reserved_slab)->toBeNull()
        ->and($deferral->reserved_gsb_paise)->toBe(0)
        ->and($deferral->reserved_msb_points)->toBe(0);

    GroupBvDaily::where('distributor_id', $achiever->id)->whereDate('date', '2026-08-25')
        ->update(['left_bv_paise' => 1_600_000, 'right_bv_paise' => 1_600_000]);

    Log::spy();
    seedEvaluateRun('2026-08-27', '2026-08-27 00:05:00');
    expect(Artisan::call('gsb:daily-cutoff', ['--date' => '2026-08-26']))->toBe(0);

    $deferral->refresh();
    $points = (int) MentorshipBonusResult::where('sponsee_id', $achiever->id)->value('msb_points');

    expect($points)->toBeGreaterThan(0)
        ->and($deferral->resolution)->toBe(GsbCutoffDeferral::RESOLUTION_BACKFILLED)
        ->and($deferral->paid_gsb_paise)->toBe(200_000)
        ->and($deferral->paid_msb_points)->toBe($points)
        ->and($deferral->exceeded_reservation_at)->not->toBeNull();

    $audit = AuditLog::where('action', 'gsb.cutoff.backfill_exceeds_reservation')->sole();
    expect($audit->details)->toMatchArray([
        'distributor_id' => $achiever->id,
        'adn' => $achiever->adn,
        'cutoff_date' => '2026-08-25',
        'reserved_gsb_paise' => 0,
        'paid_gsb_paise' => 200_000,
        'reserved_msb_points' => 0,
        'paid_msb_points' => $points,
        'delta_gsb_paise' => 200_000,
        'delta_msb_points' => $points,
    ]);

    Log::shouldHaveReceived('warning')
        ->withArgs(fn (string $message, array $context = []): bool => $message === 'gsb.cutoff.backfill_exceeds_reservation'
            && $context['delta_gsb_paise'] === 200_000)
        ->once();
});

it('records what a backfill paid without flagging it when it stays inside the reservation', function (): void {
    $achiever = deferAchieverOn25th();
    seedEvaluateRun('2026-08-27', '2026-08-27 00:05:00');

    expect(Artisan::call('gsb:daily-cutoff', ['--date' => '2026-08-26']))->toBe(0);

    $deferral = GsbCutoffDeferral::where('distributor_id', $achiever->id)->sole();
    expect($deferral->paid_gsb_paise)->toBe($deferral->reserved_gsb_paise)
        ->and($deferral->paid_msb_points)->toBe($deferral->reserved_msb_points)
        ->and($deferral->exceeded_reservation_at)->toBeNull()
        ->and(AuditLog::where('action', 'gsb.cutoff.backfill_exceeds_reservation')->exists())->toBeFalse();
});
