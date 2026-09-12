<?php

declare(strict_types=1);

use App\Modules\Compensation\Jobs\RecomputeAllJob;
use App\Modules\Compensation\Jobs\RunEngineChainJob;
use App\Modules\Compensation\Models\EngineRun;
use App\Modules\Compensation\Models\WalletLedgerEntry;
use App\Modules\Compensation\Services\DTOs\RecomputeReport;
use App\Modules\Compensation\Services\Recompute\RecomputeGuard;
use App\Modules\Compensation\Services\Recompute\RecomputeNotPermitted;
use App\Modules\Compensation\Services\Recompute\RecomputeProgress;
use App\Modules\Compliance\Models\AuditLog;
use App\Modules\Identity\Models\Distributor;
use App\Modules\Identity\Models\User;
use App\Modules\Shared\Features\GenosSalesBonusFeature;
use App\Modules\Shared\Features\GrowthBoosterBonusFeature;
use App\Modules\Shared\Features\RankBonusFeature;
use App\Modules\Shared\Features\RepurchaseEngineFeature;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Laravel\Pennant\Feature;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    disableTestForeignKeys();
    $this->seed(RolesAndPermissionsSeeder::class);
});

function engineRunsUser(string $role): User
{
    $user = User::create([
        'full_name' => 'Engine Runs '.$role,
        'email' => 'engine-runs-'.uniqid().'@test.com',
        'phone_e164' => '+91'.str_pad((string) random_int(7000000000, 9999999999), 10, '0'),
        'password_hash' => bcrypt('x'),
        'status' => 'active',
        'email_verified_at' => now(),
    ]);
    $user->assignRole($role);

    return $user;
}

it('renders the engine runs index with every engine, its schedule and dependencies', function (): void {
    // Flag-off engines are hidden, so the engines this test asserts on need
    // their flags on.
    Feature::activate(GenosSalesBonusFeature::class);
    Feature::activate(GrowthBoosterBonusFeature::class);
    Feature::activate(RankBonusFeature::class);

    $this->actingAs(engineRunsUser('admin'))
        ->get(route('admin.compensation.engine-runs.index'))
        ->assertOk()
        ->assertSee('Growth Booster Bonus')
        ->assertSee('GSB Daily Cut-off (incl. MSB)')
        ->assertSee('Rank Qualification Check')
        ->assertSee('Scheduler-only.')
        ->assertSee('Runs first:')
        ->assertSee(route('admin.compensation.engine-runs.events', ['engine' => 'gbb.monthly']), false);
});

it('hides flag-off engines from the index entirely, including their dependency chips', function (): void {
    // Every flag defaults to off, so only the always-on Monthly Payout Batch
    // remains — and its "Runs first" chips must not name the hidden engines
    // either. A disabled feature leaves no trace.
    $this->actingAs(engineRunsUser('admin'))
        ->get(route('admin.compensation.engine-runs.index'))
        ->assertOk()
        ->assertSee('Monthly Payout Batch')
        ->assertDontSee('GSB Daily Cut-off (incl. MSB)')
        ->assertDontSee('Growth Booster Bonus')
        ->assertDontSee('Fortune Bonus Enrolment')
        ->assertDontSee('Fortune Bonus Payout')
        ->assertDontSee('ADC Bonus');
});

it('shows the last recorded run on the index', function (): void {
    Feature::activate(GrowthBoosterBonusFeature::class);

    EngineRun::create([
        'engine_key' => 'gbb.monthly',
        'period_start' => '2026-07-01',
        'status' => EngineRun::STATUS_SUCCEEDED,
        'trigger' => EngineRun::TRIGGER_CONSOLE,
        'started_at' => now()->subHour(),
        'finished_at' => now()->subHour()->addMinutes(2),
    ]);

    $this->actingAs(engineRunsUser('admin'))
        ->get(route('admin.compensation.engine-runs.index'))
        ->assertOk()
        ->assertSee('succeeded')
        ->assertSee('Jul 2026');
});

it('lists run events and filters them by engine', function (): void {
    EngineRun::create([
        'engine_key' => 'gbb.monthly',
        'period_start' => '2026-07-01',
        'status' => EngineRun::STATUS_SUCCEEDED,
        'trigger' => EngineRun::TRIGGER_MANUAL,
        'started_at' => now(),
        'finished_at' => now(),
    ]);
    EngineRun::create([
        'engine_key' => 'adc.bonus',
        'period_start' => '2026-07-01',
        'status' => EngineRun::STATUS_FAILED,
        'trigger' => EngineRun::TRIGGER_CONSOLE,
        'started_at' => now(),
        'finished_at' => now(),
    ]);

    $this->actingAs(engineRunsUser('admin'))
        ->get(route('admin.compensation.engine-runs.events', ['engine' => 'gbb.monthly']))
        ->assertOk()
        ->assertSee('Growth Booster Bonus')
        // The ADC row is failed; filtering to GBB must hide it. Matched on the
        // status pill's own markup, not the bare word: the page's header text
        // explains what a failed run leaves in the Ledger column.
        ->assertDontSee('>failed<', false)
        ->assertSee('manual');
});

it('shows the ledger entries a run wrote on the events page', function (): void {
    $wrote = EngineRun::create([
        'engine_key' => 'gbb.monthly',
        'period_start' => '2026-07-01',
        'status' => EngineRun::STATUS_FAILED,
        'trigger' => EngineRun::TRIGGER_MANUAL,
        'started_at' => now(),
        'finished_at' => now(),
    ]);
    EngineRun::create([
        'engine_key' => 'gbb.monthly',
        'period_start' => '2026-06-01',
        'status' => EngineRun::STATUS_SUCCEEDED,
        'trigger' => EngineRun::TRIGGER_MANUAL,
        'started_at' => now()->subDay(),
        'finished_at' => now()->subDay(),
    ]);

    $distributor = Distributor::factory()->create();

    foreach ([150_000, 250_000] as $amountPaise) {
        WalletLedgerEntry::create([
            'distributor_id' => $distributor->id,
            'type' => 'gbb_credit',
            'amount_paise' => $amountPaise,
            'reference_id' => $amountPaise,
            'reference_type' => 'gbb_result',
            'engine_run_id' => $wrote->id,
        ]);
    }

    $response = $this->actingAs(engineRunsUser('admin'))
        ->get(route('admin.compensation.engine-runs.events', ['engine' => 'gbb.monthly']))
        ->assertOk()
        // What the failed run committed before it stopped: 2 entries, ₹4,000.
        ->assertSee('2 entries')
        ->assertSee('₹4,000.00')
        ->assertSee('This run failed after committing these entries.');

    // The second run wrote nothing: only one of the two rows carries a figure,
    // the other falls back to the em-dash placeholder.
    expect(substr_count($response->getContent(), 'entries ·'))->toBe(1);
});

it('rejects an events filter for an unknown engine key', function (): void {
    $this->actingAs(engineRunsUser('admin'))
        ->from(route('admin.compensation.engine-runs.index'))
        ->get(route('admin.compensation.engine-runs.events', ['engine' => 'nope.engine']))
        ->assertSessionHasErrors('engine');
});

it('queues the chain job and writes an audit row on trigger', function (): void {
    Queue::fake();
    Feature::activate(RankBonusFeature::class);

    $admin = engineRunsUser('admin');

    $this->actingAs($admin)
        ->post(route('admin.compensation.engine-runs.trigger'), [
            'engine' => 'rank.check',
            'period' => now()->subMonthNoOverflow()->format('Y-m'),
            'reason' => 'Scheduled run missing — backfilling qualifications.',
        ])
        ->assertRedirect(route('admin.compensation.engine-runs.index'))
        ->assertSessionHas('status');

    Queue::assertPushed(RunEngineChainJob::class, function (RunEngineChainJob $job) use ($admin): bool {
        return $job->engineKey === 'rank.check'
            && $job->period === now()->subMonthNoOverflow()->format('Y-m')
            && $job->actorId === $admin->id;
    });

    $log = AuditLog::where('action', 'compensation.engine.manual_run')->sole();
    expect($log->details['engine'])->toBe('rank.check')
        ->and($log->details['reason'])->toBe('Scheduled run missing — backfilling qualifications.')
        ->and($log->details['chain_id'])->not->toBeEmpty()
        ->and($log->details['planned_chain'])->toBeArray();
});

it('plans repurchase evaluation ahead of a manually triggered cut-off', function (): void {
    // The cut-off REFUSES to run without a succeeded evaluate run as at its
    // date, so a manual trigger that did not chain one would queue a job that
    // can only fail. The chain is what satisfies the guard.
    Queue::fake();
    Feature::activate(GenosSalesBonusFeature::class);
    Feature::activate(RepurchaseEngineFeature::class);

    $yesterday = Carbon::yesterday()->toDateString();

    $this->actingAs(engineRunsUser('admin'))
        ->post(route('admin.compensation.engine-runs.trigger'), [
            'engine' => 'gsb.daily-cutoff',
            'period' => $yesterday,
            'reason' => 'Cut-off missed overnight — re-running yesterday.',
        ])
        ->assertRedirect(route('admin.compensation.engine-runs.index'));

    $log = AuditLog::where('action', 'compensation.engine.manual_run')->sole();

    expect($log->details['planned_chain'])->toBe([
        'repurchase.evaluate|'.$yesterday,
        'gsb.daily-cutoff|'.$yesterday,
    ]);
});

it('refuses to trigger the scheduler-only payout-batch engines (maker-checker)', function (): void {
    Queue::fake();
    $admin = engineRunsUser('admin');

    foreach (['payout.monthly' => now()->format('Y-m'), 'gsb.weekly-payout' => now()->toDateString()] as $engine => $period) {
        $this->actingAs($admin)
            ->post(route('admin.compensation.engine-runs.trigger'), [
                'engine' => $engine,
                'period' => $period,
                'reason' => 'Attempting a manual payout batch creation.',
            ])->assertSessionHasErrors('engine');
    }

    Queue::assertNothingPushed();
});

it('refuses to trigger an engine whose feature flag is off', function (): void {
    Queue::fake();

    // RankBonusFeature resolves false by default in tests.
    $this->actingAs(engineRunsUser('admin'))
        ->post(route('admin.compensation.engine-runs.trigger'), [
            'engine' => 'rank.check',
            'period' => now()->subMonthNoOverflow()->format('Y-m'),
            'reason' => 'Flag is off — this must be refused.',
        ])->assertSessionHasErrors('engine');

    Queue::assertNothingPushed();
});

it('forbids triggering without the finance.record permission', function (): void {
    Queue::fake();
    Feature::activate(RankBonusFeature::class);

    $this->actingAs(engineRunsUser('admin-compliance'))
        ->post(route('admin.compensation.engine-runs.trigger'), [
            'engine' => 'rank.check',
            'period' => now()->subMonthNoOverflow()->format('Y-m'),
            'reason' => 'Should never be accepted from this role.',
        ])
        ->assertForbidden();

    Queue::assertNothingPushed();
});

it('rejects invalid engine keys, malformed periods, future periods and short reasons', function (): void {
    // The closed-period rule is production behaviour, and an open recompute
    // gate deliberately lifts it. TestCase declares the connected test database
    // destroyable, so whether the gate is open here otherwise depends on the
    // developer's own COMP_RECOMPUTE_ENABLED — which made this test pass or
    // fail by machine. Pin it closed: that is the state the rule guards.
    config(['arovolife.recompute.enabled' => false]);
    Queue::fake();
    Feature::activate(GrowthBoosterBonusFeature::class);
    $admin = engineRunsUser('admin');

    $this->actingAs($admin)
        ->post(route('admin.compensation.engine-runs.trigger'), [
            'engine' => 'nope.engine',
            'period' => '2026-07',
            'reason' => 'A perfectly valid reason text.',
        ])->assertSessionHasErrors('engine');

    // A month engine given a date string.
    $this->actingAs($admin)
        ->post(route('admin.compensation.engine-runs.trigger'), [
            'engine' => 'gbb.monthly',
            'period' => '2026-07-15',
            'reason' => 'A perfectly valid reason text.',
        ])->assertSessionHasErrors('period');

    $this->actingAs($admin)
        ->post(route('admin.compensation.engine-runs.trigger'), [
            'engine' => 'gbb.monthly',
            'period' => now()->addMonthNoOverflow()->format('Y-m'),
            'reason' => 'A perfectly valid reason text.',
        ])->assertSessionHasErrors('period');

    $this->actingAs($admin)
        ->post(route('admin.compensation.engine-runs.trigger'), [
            'engine' => 'gbb.monthly',
            'period' => now()->subMonthNoOverflow()->format('Y-m'),
            'reason' => 'too short',
        ])->assertSessionHasErrors('reason');

    Queue::assertNothingPushed();
});

it('refuses to run an economics-freezing engine for a period still in flight', function (): void {
    // Staging, 24 Aug 2026: a manual cut-off at 23:27 froze that day's pool at
    // ₹0 before the evening's BV landed, and the scheduled 00:10 run then paid
    // the day's real achievers out of the empty snapshot. A day is only
    // runnable once it has ended; a month once it has ended.
    // The closed-period rule is production behaviour, and an open recompute
    // gate deliberately lifts it. TestCase declares the connected test database
    // destroyable, so whether the gate is open here otherwise depends on the
    // developer's own COMP_RECOMPUTE_ENABLED — which made this test pass or
    // fail by machine. Pin it closed: that is the state the rule guards.
    config(['arovolife.recompute.enabled' => false]);
    Queue::fake();
    Feature::activate(GenosSalesBonusFeature::class);
    Feature::activate(GrowthBoosterBonusFeature::class);
    $admin = engineRunsUser('admin');

    $this->actingAs($admin)
        ->post(route('admin.compensation.engine-runs.trigger'), [
            'engine' => 'gsb.daily-cutoff',
            'period' => now()->toDateString(),
            'reason' => 'Trying to see today\'s results early.',
        ])->assertSessionHasErrors('period');

    $this->actingAs($admin)
        ->post(route('admin.compensation.engine-runs.trigger'), [
            'engine' => 'gbb.monthly',
            'period' => now()->format('Y-m'),
            'reason' => 'Trying to run the current month early.',
        ])->assertSessionHasErrors('period');

    Queue::assertNothingPushed();

    // Yesterday is closed, so the cut-off may run for it.
    $this->actingAs($admin)
        ->post(route('admin.compensation.engine-runs.trigger'), [
            'engine' => 'gsb.daily-cutoff',
            'period' => now()->subDay()->toDateString(),
            'reason' => 'Scheduled run failed — re-running yesterday.',
        ])->assertSessionHasNoErrors();

    Queue::assertPushed(RunEngineChainJob::class);
});

it('still allows in-flight periods for engines that do not freeze economics', function (): void {
    // Repurchase evaluation is DESIGNED to run for today (an as-of-morning
    // view), and the rank check is monotone over the month — neither freezes
    // a pool, so the closed-period rule must not block them.
    Queue::fake();
    Feature::activate(RepurchaseEngineFeature::class);
    Feature::activate(RankBonusFeature::class);
    $admin = engineRunsUser('admin');

    $this->actingAs($admin)
        ->post(route('admin.compensation.engine-runs.trigger'), [
            'engine' => 'repurchase.evaluate',
            'period' => now()->toDateString(),
            'reason' => 'Refreshing repurchase cycles for today.',
        ])->assertSessionHasNoErrors();

    $this->actingAs($admin)
        ->post(route('admin.compensation.engine-runs.trigger'), [
            'engine' => 'rank.check',
            'period' => now()->format('Y-m'),
            'reason' => 'Mid-month rank qualification check.',
        ])->assertSessionHasNoErrors();

    Queue::assertPushed(RunEngineChainJob::class, 2);
});

/*
|--------------------------------------------------------------------------
| TESTING-ONLY full recompute — removed with the scaffold at client sign-off
|--------------------------------------------------------------------------
*/

it('warns on every reader that the recompute gate is open and the in-flight month is runnable (F82)', function (): void {
    config(['arovolife.recompute.enabled' => true]);

    // Not only the developer: any admin on this page can trigger an engine, and
    // with the gate open the period pickers accept the month still in flight.
    foreach (['developer', 'admin', 'admin-finance'] as $role) {
        $this->actingAs(engineRunsUser($role))
            ->get(route('admin.compensation.engine-runs.index'))
            ->assertOk()
            ->assertSee('Recompute testing gate is OPEN on this database')
            ->assertSee('Do not run a period that has not ended');
    }
});

it('shows no trace of the gate warning when the gate is closed (F82)', function (): void {
    config(['arovolife.recompute.enabled' => false]);

    $this->actingAs(engineRunsUser('developer'))
        ->get(route('admin.compensation.engine-runs.index'))
        ->assertOk()
        ->assertDontSee('Recompute testing gate is OPEN on this database')
        ->assertDontSee('Do not run a period that has not ended');
});

it('hides the recompute card entirely when the gate is closed', function (): void {
    config(['arovolife.recompute.enabled' => false]);

    $response = $this->actingAs(engineRunsUser('developer'))
        ->get(route('admin.compensation.engine-runs.index'));

    $response->assertOk();
    // Zero-trace gating: not a disabled button, not a tooltip — no mention at all.
    $response->assertDontSee('Recompute everything');
    $response->assertDontSee('recompute-all');
});

it('shows the recompute card to the developer and to admin when the gate is open', function (): void {
    config(['arovolife.recompute.enabled' => true]);

    foreach (['developer', 'admin'] as $role) {
        $response = $this->actingAs(engineRunsUser($role))
            ->get(route('admin.compensation.engine-runs.index'));

        $response->assertOk();
        $response->assertSee('Testing tool — recompute everything from scratch', false);
        $response->assertSee('Run recompute');
        // The window controls and the engine picker are what make a partial
        // replay reachable from the page at all.
        $response->assertSee('Keep earlier history (rebuild only the window)');
        $response->assertSee('name="engines[]"', false);
        // ...and the purchase-data reset lives behind the same gate.
        $response->assertSee('Testing tool — reset purchase data (start a fresh test cycle)', false);
    }
});

it('keeps both testing tools away from every scoped admin role', function (): void {
    // F84: the cards rendered on RecomputeGuard alone, so `admin`,
    // `admin-finance`, `admin-compliance` and `admin-operations` all saw two
    // destructive buttons — with the target database name and its row counts
    // printed beside them. The guard answers for the environment, never for
    // the reader. `admin` was later deliberately let back in alongside
    // `developer`; the scoped roles — admin-finance, admin-compliance,
    // admin-operations — stay locked out.
    config(['arovolife.recompute.enabled' => true]);

    foreach (['admin-finance', 'admin-compliance', 'admin-operations'] as $role) {
        $user = engineRunsUser($role);

        $this->actingAs($user)
            ->get(route('admin.compensation.engine-runs.index'))
            ->assertOk()
            ->assertDontSee('Testing tool — recompute everything from scratch', false)
            ->assertDontSee('Testing tool — reset purchase data (start a fresh test cycle)', false)
            ->assertDontSee('recompute-all')
            ->assertDontSee('reset-purchase-data');

        // 404 from the controller for the roles that hold `finance.record`,
        // 403 from the route's own permission gate for the roles that do not.
        // Either way the tool is out of reach.
        expect($this->actingAs($user)
            ->post(route('admin.compensation.engine-runs.recompute-all'))
            ->status())->toBeIn([403, 404]);

        expect($this->actingAs($user)
            ->post(route('admin.compensation.engine-runs.reset-purchase-data'), ['confirm_database' => 'x'])
            ->status())->toBeIn([403, 404]);

        $this->actingAs($user)
            ->get(route('admin.compensation.engine-runs.recompute-progress'))
            ->assertNotFound();
    }
});

it('hides the purchase-data reset when the gate is closed', function (): void {
    config(['arovolife.recompute.enabled' => false]);

    $this->actingAs(engineRunsUser('developer'))
        ->get(route('admin.compensation.engine-runs.index'))
        ->assertOk()
        ->assertDontSee('Testing tool — reset purchase data (start a fresh test cycle)', false)
        ->assertDontSee('purchase-reset-confirm-db');
});

it('404s the purchase-data reset when the gate is closed', function (): void {
    config(['arovolife.recompute.enabled' => false]);

    $this->actingAs(engineRunsUser('developer'))
        ->post(route('admin.compensation.engine-runs.reset-purchase-data'), ['confirm_database' => 'x'])
        ->assertNotFound();
});

it('refuses a purchase-data reset unless the database name is typed exactly', function (): void {
    config(['arovolife.recompute.enabled' => true]);

    $this->actingAs(engineRunsUser('developer'))
        ->from(route('admin.compensation.engine-runs.index'))
        ->post(route('admin.compensation.engine-runs.reset-purchase-data'), ['confirm_database' => 'not-the-db'])
        ->assertSessionHasErrors('confirm_database');
});

it('wipes orders on a confirmed purchase-data reset but keeps the distributors', function (): void {
    config(['arovolife.recompute.enabled' => true]);

    $distributor = Distributor::factory()->create();
    DB::table('bv_ledger_entries')->insert([
        'distributor_id' => $distributor->id,
        'order_id' => 4242,
        'bv_paise' => 100_000,
        'type' => 'accrual',
        'effective_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $database = app(RecomputeGuard::class)->targetDatabase();

    $this->actingAs(engineRunsUser('developer'))
        ->post(route('admin.compensation.engine-runs.reset-purchase-data'), ['confirm_database' => $database])
        ->assertRedirect(route('admin.compensation.engine-runs.index'))
        ->assertSessionHas('status');

    expect(DB::table('bv_ledger_entries')->count())->toBe(0)
        ->and(DB::table('distributors')->where('id', $distributor->id)->exists())->toBeTrue();

    expect(DB::table('audit_log')->where('action', 'platform.purchase_reset')->exists())->toBeTrue();
});

it('404s the recompute endpoint when the gate is closed', function (): void {
    config(['arovolife.recompute.enabled' => false]);

    $this->actingAs(engineRunsUser('developer'))
        ->post(route('admin.compensation.engine-runs.recompute-all'))
        ->assertNotFound();
});

it('queues the recompute rather than running it inline', function (): void {
    config(['arovolife.recompute.enabled' => true]);
    Queue::fake();

    $this->actingAs(engineRunsUser('developer'))
        ->post(route('admin.compensation.engine-runs.recompute-all'))
        ->assertRedirect(route('admin.compensation.engine-runs.index'))
        ->assertSessionHas('status');

    Queue::assertPushed(RecomputeAllJob::class);

    AuditLog::query()->where('action', 'compensation.recompute_all.queued')->firstOrFail();
});

it('replaces the previous run summary with a queued state the moment a new run is dispatched', function (): void {
    // Regression: the poller read the stale 'complete' state on the redirected
    // page, rendered the old summary and stopped polling — so the new run never
    // appeared. Dispatching must publish this run's state synchronously.
    config(['arovolife.recompute.enabled' => true]);
    Queue::fake();

    $progress = app(RecomputeProgress::class);
    $progress->complete(new RecomputeReport(
        from: Carbon::parse('2026-07-04'),
        to: Carbon::parse('2026-08-16'),
        rowsRemoved: ['gbb_monthly_pools' => 3],
        ordersPropagated: 315,
        daysReplayed: 44,
        enginesRun: ['gsb:daily-cutoff' => 44],
        warnings: [],
        durationSeconds: 32.5,
    ));

    $this->actingAs(engineRunsUser('developer'))
        ->post(route('admin.compensation.engine-runs.recompute-all'));

    $state = $progress->read();

    expect($state['state'])->toBe(RecomputeProgress::STATE_RUNNING);
    expect($state['summary'])->toBeNull();
    expect($state['percent'])->toBe(0);
});

it('renders a flash message exactly once, not once per view that thought it owned it', function (): void {
    config(['arovolife.recompute.enabled' => true]);

    $response = $this->actingAs(engineRunsUser('admin'))
        ->withSession(['status' => 'Full recompute queued.'])
        ->get(route('admin.compensation.engine-runs.index'));

    $response->assertOk();

    // The admin layout renders session('status') for every page. A view that
    // also renders its own block shows the user the same message twice.
    expect(substr_count($response->getContent(), 'Full recompute queued.'))->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Guards on the testing-only tooling (compliance review, 2026-08-25)
|--------------------------------------------------------------------------
*/

it('refuses the whole scaffold when the connected database is not on the allow-list', function (): void {
    config([
        'arovolife.recompute.enabled' => true,
        // The flag is on and this is not production — the only thing standing
        // between the operator and the data is the database's own name.
        'arovolife.recompute.allowed_databases' => ['some-other-database'],
    ]);

    expect(app(RecomputeGuard::class)->isPermitted())->toBeFalse();

    $admin = engineRunsUser('developer');

    $this->actingAs($admin)
        ->get(route('admin.compensation.engine-runs.index'))
        ->assertOk()
        ->assertDontSee('recompute-all');

    $this->actingAs($admin)
        ->post(route('admin.compensation.engine-runs.recompute-all'))
        ->assertNotFound();

    $this->actingAs($admin)
        ->post(route('admin.compensation.engine-runs.reset-purchase-data'), [
            'confirm_database' => app(RecomputeGuard::class)->targetDatabase(),
        ])
        ->assertNotFound();

    expect(DB::table('audit_log')->where('action', 'like', 'platform.purchase_reset%')->count())->toBe(0);
});

it('names the database in the refusal so the operator sees which one it read', function (): void {
    config([
        'arovolife.recompute.enabled' => true,
        'arovolife.recompute.allowed_databases' => [],
    ]);

    expect(fn () => app(RecomputeGuard::class)->ensurePermitted())
        ->toThrow(RecomputeNotPermitted::class, app(RecomputeGuard::class)->targetDatabase());
});

it('records who ordered a purchase reset and what was standing before it ran', function (): void {
    config(['arovolife.recompute.enabled' => true]);

    $admin = engineRunsUser('developer');
    $distributor = Distributor::factory()->create();
    DB::table('bv_ledger_entries')->insert([
        'distributor_id' => $distributor->id,
        'order_id' => 909,
        'bv_paise' => 250_000,
        'type' => 'accrual',
        'effective_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $this->actingAs($admin)
        ->post(route('admin.compensation.engine-runs.reset-purchase-data'), [
            'confirm_database' => app(RecomputeGuard::class)->targetDatabase(),
        ])
        ->assertSessionHas('status');

    // Written before the truncation, so it survives even a half-finished wipe.
    $requested = DB::table('audit_log')->where('action', 'platform.purchase_reset.requested')->first();
    expect($requested)->not->toBeNull()
        ->and((int) $requested->actor_id)->toBe($admin->id);

    $details = json_decode((string) $requested->details, true);
    expect($details['rows_standing']['bv_ledger_entries'])->toBe(1)
        ->and($details['database'])->toBe(app(RecomputeGuard::class)->targetDatabase());

    // And the action's own entry is attributed to the real operator rather than
    // the seeded admin address, with the counts it actually destroyed.
    $done = DB::table('audit_log')->where('action', 'platform.purchase_reset')->first();
    $doneDetails = json_decode((string) $done->details, true);
    expect((int) $done->actor_id)->toBe($admin->id)
        ->and($doneDetails['rows_removed']['bv_ledger_entries'])->toBe(1)
        ->and($doneDetails['note'])->toContain('the admin Engine Runs console');
});

it('refuses a purchase reset while the replay lock is held rather than stealing it', function (): void {
    config(['arovolife.recompute.enabled' => true]);

    $distributor = Distributor::factory()->create();
    DB::table('bv_ledger_entries')->insert([
        'distributor_id' => $distributor->id,
        'order_id' => 555,
        'bv_paise' => 100_000,
        'type' => 'accrual',
        'effective_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    // A replay is genuinely in flight. Truncating its source orders is the one
    // thing a re-run cannot recover from, so the reset must stand down.
    Cache::lock(RecomputeAllJob::LOCK_KEY, 900)->get();

    $this->actingAs(engineRunsUser('developer'))
        ->from(route('admin.compensation.engine-runs.index'))
        ->post(route('admin.compensation.engine-runs.reset-purchase-data'), [
            'confirm_database' => app(RecomputeGuard::class)->targetDatabase(),
        ])
        ->assertRedirect(route('admin.compensation.engine-runs.index'))
        ->assertSessionHas('error');

    expect(DB::table('bv_ledger_entries')->count())->toBe(1);
});

it('requires the operator to acknowledge the engines a partial replay will not rebuild', function (): void {
    config(['arovolife.recompute.enabled' => true]);
    Queue::fake();

    $this->actingAs(engineRunsUser('developer'))
        ->from(route('admin.compensation.engine-runs.index'))
        ->post(route('admin.compensation.engine-runs.recompute-all'), [
            'from' => today()->toDateString(),
            'windowed' => '1',
            'engines' => ['gsb.daily-cutoff'],
        ])
        ->assertSessionHasErrors('accept_missing_engines');

    Queue::assertNothingPushed();

    $this->actingAs(engineRunsUser('developer'))
        ->post(route('admin.compensation.engine-runs.recompute-all'), [
            'from' => today()->toDateString(),
            'windowed' => '1',
            'engines' => ['gsb.daily-cutoff'],
            'accept_missing_engines' => '1',
        ])
        ->assertSessionHas('status');

    Queue::assertPushed(RecomputeAllJob::class);
});

it('accepts a To date through the end of next month and rejects later ones', function (): void {
    config(['arovolife.recompute.enabled' => true]);
    Queue::fake();

    $nextMonthEnd = Carbon::today()->addMonthNoOverflow()->endOfMonth()->toDateString();

    $this->actingAs(engineRunsUser('developer'))
        ->post(route('admin.compensation.engine-runs.recompute-all'), [
            'to' => $nextMonthEnd,
        ])
        ->assertSessionDoesntHaveErrors('to');

    Queue::assertPushed(RecomputeAllJob::class);

    Queue::clearResolvedInstances();
    Queue::fake();

    $tooFar = Carbon::today()->addMonthNoOverflow()->endOfMonth()->addDay()->toDateString();

    $this->actingAs(engineRunsUser('developer'))
        ->post(route('admin.compensation.engine-runs.recompute-all'), [
            'to' => $tooFar,
        ])
        ->assertSessionHasErrors('to');

    Queue::assertNothingPushed();
});

it('allows in-flight and future periods for economics-freezing engines while the recompute gate is open', function (): void {
    config(['arovolife.recompute.enabled' => true]);
    Queue::fake();
    Feature::activate(GrowthBoosterBonusFeature::class);

    $user = engineRunsUser('admin');

    // Current month (in-flight for GBB which requiresClosedPeriod).
    $this->actingAs($user)
        ->post(route('admin.compensation.engine-runs.trigger'), [
            'engine' => 'gbb.monthly',
            'period' => Carbon::now()->format('Y-m'),
            'reason' => 'Testing gate open — previewing in-flight period',
        ])
        ->assertSessionHas('status');

    // Next month (future period).
    $this->actingAs($user)
        ->post(route('admin.compensation.engine-runs.trigger'), [
            'engine' => 'gbb.monthly',
            'period' => Carbon::now()->addMonthNoOverflow()->format('Y-m'),
            'reason' => 'Testing gate open — previewing future period',
        ])
        ->assertSessionHas('status');

    Queue::assertPushed(RunEngineChainJob::class, 2);
});

it('filters the run events to failures and offers the status filter', function (): void {
    $user = engineRunsUser('admin');
    Feature::for(null)->activate(RankBonusFeature::class);

    EngineRun::create([
        'engine_key' => 'rank.bonus',
        'period_start' => Carbon::parse('2026-08-01'),
        'status' => EngineRun::STATUS_FAILED,
        'trigger' => EngineRun::TRIGGER_CONSOLE,
        'started_at' => Carbon::parse('2026-09-01 00:30'),
        'finished_at' => Carbon::parse('2026-09-01 00:31'),
    ]);
    EngineRun::create([
        'engine_key' => 'rank.check',
        'period_start' => Carbon::parse('2026-08-01'),
        'status' => EngineRun::STATUS_SUCCEEDED,
        'trigger' => EngineRun::TRIGGER_CONSOLE,
        'started_at' => Carbon::parse('2026-09-01 00:20'),
        'finished_at' => Carbon::parse('2026-09-01 00:21'),
    ]);

    $this->actingAs($user)
        ->get(route('admin.compensation.engine-runs.events', ['status' => EngineRun::STATUS_FAILED]))
        ->assertOk()
        // The started_at stamps identify the rows; the engine labels also
        // appear in the filter dropdown, so they cannot be asserted on.
        ->assertSee('01 Sep 2026 00:30:00')
        ->assertDontSee('01 Sep 2026 00:20:00');

    $this->actingAs($user)
        ->get(route('admin.compensation.engine-runs.events', ['status' => 'nonsense']))
        ->assertSessionHasErrors('status');
});

it('badges the admin sidebar with unresolved engine failures, linking to the failed runs', function (): void {
    $user = engineRunsUser('admin');

    // No failure: no badge, no extra nav item.
    $this->actingAs($user)
        ->get(route('admin.compensation.engine-runs.index'))
        ->assertOk()
        ->assertDontSee('Engine failures');

    Cache::forget('admin.engine_runs.unresolved_failure_count');

    EngineRun::create([
        'engine_key' => 'fortune.payout',
        'period_start' => Carbon::now()->startOfMonth()->subMonthNoOverflow(),
        'status' => EngineRun::STATUS_FAILED,
        'trigger' => EngineRun::TRIGGER_CONSOLE,
        'started_at' => Carbon::now()->subDay(),
        'finished_at' => Carbon::now()->subDay(),
    ]);

    $this->actingAs($user)
        ->get(route('admin.compensation.engine-runs.index'))
        ->assertOk()
        ->assertSee('Engine failures')
        ->assertSee(route('admin.compensation.engine-runs.events', ['status' => 'failed']), false);
});

it('shows the engine-failure badge to admin-finance without audit.read', function (): void {
    // The monthly payout on the 8th refuses over exactly these failures, and
    // admin-finance is the role that owns that payout. The badge must not hang
    // on `audit.read`, which is a monitoring grant that role need not hold.
    Role::findByName('admin-finance')->revokePermissionTo('audit.read');
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $user = engineRunsUser('admin-finance');

    expect($user->can('audit.read'))->toBeFalse()
        ->and($user->can('finance.record'))->toBeTrue();

    EngineRun::create([
        'engine_key' => 'fortune.payout',
        'period_start' => Carbon::now()->startOfMonth()->subMonthNoOverflow(),
        'status' => EngineRun::STATUS_FAILED,
        'trigger' => EngineRun::TRIGGER_CONSOLE,
        'started_at' => Carbon::now()->subDay(),
        'finished_at' => Carbon::now()->subDay(),
    ]);

    Cache::forget('admin.engine_runs.unresolved_failure_count');

    $this->actingAs($user)
        ->get(route('admin.compensation.engine-runs.index'))
        ->assertOk()
        ->assertSee('Engine failures')
        ->assertSee(route('admin.compensation.engine-runs.events', ['status' => 'failed']), false);
});

it('renders the run summary as labelled fields rather than raw JSON', function (): void {
    // F83: the DETAILS cell printed `{"output":"","exit_code":0}` verbatim.
    $user = engineRunsUser('admin');
    Feature::for(null)->activate(RankBonusFeature::class);

    EngineRun::create([
        'engine_key' => 'rank.bonus',
        'period_start' => Carbon::parse('2026-08-01'),
        'status' => EngineRun::STATUS_SUCCEEDED,
        'trigger' => EngineRun::TRIGGER_MANUAL,
        'summary' => ['exit_code' => 0, 'output' => ''],
        'started_at' => Carbon::parse('2026-09-01 00:30'),
        'finished_at' => Carbon::parse('2026-09-01 00:31'),
        'duration_ms' => 557,
    ]);

    $response = $this->actingAs($user)
        ->get(route('admin.compensation.engine-runs.events'))
        ->assertOk();

    $response->assertSee('Exit code:');
    $response->assertSee('No console output was captured.');
    $response->assertDontSee('{"output"', false);
    // F85: 557 ms rounded to "1s" hid the very fact the operator was after.
    $response->assertSee('557ms');
});

it('says so when a run re-ran a period that was already computed', function (): void {
    $user = engineRunsUser('admin');
    Feature::for(null)->activate(RankBonusFeature::class);

    foreach (['2026-09-01 00:30' => '2026-09-01 00:31', '2026-09-05 10:00' => '2026-09-05 10:01'] as $started => $finished) {
        EngineRun::create([
            'engine_key' => 'rank.bonus',
            'period_start' => Carbon::parse('2026-08-01'),
            'status' => EngineRun::STATUS_SUCCEEDED,
            'trigger' => EngineRun::TRIGGER_CONSOLE,
            'summary' => ['exit_code' => 0, 'output' => ''],
            'started_at' => Carbon::parse($started),
            'finished_at' => Carbon::parse($finished),
        ]);
    }

    $this->actingAs($user)
        ->get(route('admin.compensation.engine-runs.events'))
        ->assertOk()
        ->assertSee('Already processed — nothing to do.', false)
        ->assertSeeText('This period was already computed by the run of 01 Sep 2026 00:30;');
});

it('names a retired engine rather than leaking its key', function (): void {
    // F85: `repurchase.snapshot` rows survive in engine_runs; the command does
    // not, so the registry cannot carry a definition for it.
    $user = engineRunsUser('admin');

    EngineRun::create([
        'engine_key' => 'repurchase.snapshot',
        'period_start' => Carbon::parse('2026-08-01'),
        'status' => EngineRun::STATUS_SUCCEEDED,
        'trigger' => EngineRun::TRIGGER_CONSOLE,
        'started_at' => Carbon::parse('2026-09-01 00:05'),
        'finished_at' => Carbon::parse('2026-09-01 00:05'),
    ]);

    $this->actingAs($user)
        ->get(route('admin.compensation.engine-runs.events'))
        ->assertOk()
        ->assertSee('Repurchase Snapshot (retired)');
});

it('names the chosen period in the trigger confirmation, not just "the chosen period"', function (): void {
    Feature::activate(GrowthBoosterBonusFeature::class);

    $this->actingAs(engineRunsUser('admin'))
        ->get(route('admin.compensation.engine-runs.index'))
        ->assertOk()
        ->assertSee('data-engine-trigger', false)
        ->assertSee("form.dataset.confirmTitle = 'Confirm: Run ' + form.dataset.engineLabel + ' for ' + value;", false);
});
