<?php

declare(strict_types=1);

use App\Modules\Compensation\Jobs\RebuildPeriodJob;
use App\Modules\Compensation\Jobs\RecomputeAllJob;
use App\Modules\Compensation\Jobs\RunEngineChainJob;
use App\Modules\Compensation\Models\EngineRun;
use App\Modules\Compensation\Models\GsbCutoffResult;
use App\Modules\Compensation\Models\GsbPersonalBvTopup;
use App\Modules\Compensation\Models\PayoutBatch;
use App\Modules\Compensation\Models\PayoutLineItem;
use App\Modules\Compensation\Models\WalletLedgerEntry;
use App\Modules\Compensation\Services\DTOs\RecomputeReport;
use App\Modules\Compensation\Services\Recompute\RecomputeGuard;
use App\Modules\Compensation\Services\Recompute\RecomputeNotPermitted;
use App\Modules\Compensation\Services\Recompute\RecomputeProgress;
use App\Modules\Compensation\Support\EngineRegistry;
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
    // Outside the 00:00-05:00 IST engine window by default, so these tests
    // don't flake depending on the wall-clock time they happen to run at.
    Carbon::setTestNow(Carbon::parse('2026-01-01 12:00:00', 'Asia/Kolkata'));
    // Gate shut by default — production behaviour, and the state most of this
    // file is about. TestCase declares the connected test database destroyable,
    // so without this the gate would be open or shut according to the
    // developer's own COMP_RECOMPUTE_ENABLED and the trigger tests would pass or
    // fail by machine. The recompute tests below open it explicitly.
    config(['arovolife.recompute.enabled' => false]);
});

afterEach(function (): void {
    Carbon::setTestNow();
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
        // Every card states the instant it next fires, not only the rule it
        // fires by: "Daily, 00:05 IST" never told anyone which 00:05.
        ->assertSee('Next run:')
        ->assertSee(Carbon::tomorrow()->setTime(0, 5)->format('d M Y, H:i').' IST')
        ->assertSee(route('admin.compensation.engine-runs.events', ['engine' => 'gbb.monthly']), false);
});

it('never shows a rebuild engine card, whatever the role', function (): void {
    // The four rebuilds are registry entries so their runs are recorded, not
    // engines anyone starts from this page — the developer's rebuild panel is
    // its own surface (S4). Asserted for the developer too: `developerOnly` hides
    // the CARD from everyone.
    foreach (['admin', 'admin-finance', 'developer'] as $role) {
        $response = $this->actingAs(engineRunsUser($role))
            ->get(route('admin.compensation.engine-runs.index'))
            ->assertOk();

        foreach (EngineRegistry::rebuildKeys() as $key) {
            $response->assertDontSee(EngineRegistry::get($key)->label);
        }
    }
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

it('tells every reader that engines are run by recompute on this environment', function (): void {
    config(['arovolife.recompute.enabled' => true]);

    // Not only the developer who can see the recompute card: every admin on this
    // page used to have a trigger button, and the reason it is gone has to be
    // where the button was.
    foreach (['developer', 'admin', 'admin-finance'] as $role) {
        $this->actingAs(engineRunsUser($role))
            ->get(route('admin.compensation.engine-runs.index'))
            ->assertOk()
            ->assertSee('Engines are not run one at a time on this environment')
            ->assertSee('Run by recompute.')
            ->assertDontSee('Preview &amp; Confirm', false);
    }
});

it('keeps the per-engine trigger forms in production, where the gate is shut', function (): void {
    config(['arovolife.recompute.enabled' => false]);
    Feature::activate(GenosSalesBonusFeature::class);

    $this->actingAs(engineRunsUser('developer'))
        ->get(route('admin.compensation.engine-runs.index'))
        ->assertOk()
        ->assertDontSee('Engines are not run one at a time on this environment')
        ->assertSee('Preview &amp; Confirm', false);
});

it('hides the recompute card entirely when the gate is closed', function (): void {
    config(['arovolife.recompute.enabled' => false]);

    $response = $this->actingAs(engineRunsUser('developer'))
        ->get(route('admin.compensation.engine-runs.index'));

    $response->assertOk();
    // Zero-trace gating: not a disabled button, not a tooltip — no mention at all.
    $response->assertDontSee('Recompute — rebuild every bonus from the orders', false);
    $response->assertDontSee('recompute-all');
});

it('shows the recompute card to the developer and to admin when the gate is open', function (): void {
    config(['arovolife.recompute.enabled' => true]);

    foreach (['developer', 'admin'] as $role) {
        $response = $this->actingAs(engineRunsUser($role))
            ->get(route('admin.compensation.engine-runs.index'));

        $response->assertOk();
        $response->assertSee('Recompute — rebuild every bonus from the orders', false);
        $response->assertSee('Run recompute');
        // The one choice the page offers: how far along the scheduler's
        // calendar to replay.
        $response->assertSee('name="horizon"', false);
        $response->assertSee('Up to now');
        $response->assertSee('Project through the');
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

it('refuses a purchase-data reset inside the nightly engine window', function (): void {
    config(['arovolife.recompute.enabled' => true]);
    Carbon::setTestNow(Carbon::parse('2026-01-02 02:00:00', 'Asia/Kolkata'));

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
        ->from(route('admin.compensation.engine-runs.index'))
        ->post(route('admin.compensation.engine-runs.reset-purchase-data'), ['confirm_database' => $database])
        ->assertRedirect(route('admin.compensation.engine-runs.index'))
        ->assertSessionHas('error');

    expect(DB::table('bv_ledger_entries')->count())->toBe(1)
        ->and(DB::table('audit_log')->where('action', 'platform.purchase_reset.refused')->count())->toBe(1)
        ->and(DB::table('audit_log')->where('action', 'platform.purchase_reset.requested')->count())->toBe(0)
        ->and(DB::table('audit_log')->where('action', 'platform.purchase_reset')->count())->toBe(0);
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
        ->post(route('admin.compensation.engine-runs.recompute-all'), ['horizon' => 'now'])
        ->assertRedirect(route('admin.compensation.engine-runs.index'))
        ->assertSessionHas('status');

    Queue::assertPushed(RecomputeAllJob::class);

    $row = AuditLog::query()->where('action', 'compensation.recompute_all.queued')->firstOrFail();

    expect($row->details['horizon'])->toBe('now')
        ->and($row->details['simulated_through'])->toBeNull();
});

it('records what a projection will simulate, on the row the banner reads', function (): void {
    config(['arovolife.recompute.enabled' => true]);
    Queue::fake();

    $this->actingAs(engineRunsUser('developer'))
        ->post(route('admin.compensation.engine-runs.recompute-all'), ['horizon' => 'projection'])
        ->assertSessionHas('status');

    $row = AuditLog::query()->where('action', 'compensation.recompute_all.queued')->firstOrFail();

    expect($row->details['horizon'])->toBe('projection')
        ->and($row->details['simulated_through'])->not->toBeNull();
});

it('rejects a horizon it does not know, and a missing one', function (): void {
    config(['arovolife.recompute.enabled' => true]);
    Queue::fake();

    $this->actingAs(engineRunsUser('developer'))
        ->post(route('admin.compensation.engine-runs.recompute-all'), ['horizon' => 'next-year'])
        ->assertSessionHasErrors('horizon');

    $this->actingAs(engineRunsUser('developer'))
        ->post(route('admin.compensation.engine-runs.recompute-all'))
        ->assertSessionHasErrors('horizon');

    Queue::assertNothingPushed();
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
        ->post(route('admin.compensation.engine-runs.recompute-all'), ['horizon' => 'now']);

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

it('refuses a manual trigger outright while the recompute gate is open', function (): void {
    // The gate used to LIFT the closed-period rule here, so an admin could
    // freeze the live month from this page. That is the 24 Aug 2026 incident at
    // month scale, and the 14 Sep 2026 month-end-wallet bug came through the
    // same door. On a test environment the recompute runs the calendar instead.
    config(['arovolife.recompute.enabled' => true]);
    Queue::fake();
    Feature::activate(GrowthBoosterBonusFeature::class);

    $this->actingAs(engineRunsUser('admin'))
        ->post(route('admin.compensation.engine-runs.trigger'), [
            'engine' => 'gbb.monthly',
            'period' => Carbon::now()->subMonthNoOverflow()->format('Y-m'),
            'reason' => 'Trying to run one engine by hand on a test environment.',
        ])
        ->assertSessionHasErrors('engine');

    Queue::assertNothingPushed();
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

/*
|--------------------------------------------------------------------------
| The three run-failure banners
|--------------------------------------------------------------------------
|
| One banner per scheduled run, and each exists only while THAT run's last
| finished attempt is a failure. Much of what follows is about the word "last":
| a night that failed and was then healed by a later night must leave no banner,
| because the run has already done what a button would have done.
|
| They are information and nothing else. The `finance.record` retry that used to
| sit inside the nightly banner is gone: a failed run is repaired by the platform
| team, and no admin role has a control here.
*/

function chainRun(string $night, string $status, ?string $error = null): EngineRun
{
    return EngineRun::create([
        'engine_key' => 'compensation.nightly-run',
        'period_start' => $night,
        'status' => $status,
        'trigger' => 'schedule',
        'actor_id' => null,
        'chain_id' => null,
        'summary' => null,
        'error' => $error,
        'started_at' => Carbon::parse($night.' 00:05:00'),
        'finished_at' => Carbon::parse($night.' 00:20:00'),
        'duration_ms' => 900000,
    ]);
}

/** A run row for any of the three orchestrators, in whatever state. */
function rootRun(string $key, string $night, string $status, ?string $error = null): EngineRun
{
    return EngineRun::create([
        'engine_key' => $key,
        'period_start' => $night,
        'status' => $status,
        'trigger' => 'schedule',
        'actor_id' => null,
        'chain_id' => null,
        'summary' => null,
        'error' => $error,
        'started_at' => Carbon::parse($night.' 00:05:00'),
        'finished_at' => Carbon::parse($night.' 00:20:00'),
        'duration_ms' => 900000,
    ]);
}

/** A step row written by one of the runs, stamped with when it actually started. */
function stepRun(string $key, string $period, string $status, string $startedAt, ?string $error = null): EngineRun
{
    return EngineRun::create([
        'engine_key' => $key,
        'period_start' => $period,
        'status' => $status,
        'trigger' => 'schedule',
        'actor_id' => null,
        'chain_id' => null,
        'summary' => null,
        'error' => $error,
        'started_at' => Carbon::parse($startedAt),
        'finished_at' => Carbon::parse($startedAt)->addMinute(),
        'duration_ms' => 60000,
    ]);
}

it('shows no failure banner while the three runs are healthy', function (): void {
    chainRun('2025-12-31', EngineRun::STATUS_SUCCEEDED);

    $this->actingAs(engineRunsUser('admin'))
        ->get(route('admin.compensation.engine-runs.index'))
        ->assertOk()
        ->assertDontSee('failed on 31 Dec 2025');
});

it('shows the nightly-run banner when it last finished in failure, with no control', function (): void {
    chainRun('2025-12-31', EngineRun::STATUS_FAILED, 'Rank Bonus exited 1 for Dec 2025.');

    $this->actingAs(engineRunsUser('admin'))
        ->get(route('admin.compensation.engine-runs.index'))
        ->assertOk()
        ->assertSee('The nightly run failed on 31 Dec 2025')
        ->assertSee('Rank Bonus exited 1 for Dec 2025.')
        ->assertSee('Nothing is lost by waiting')
        // Information, not a control: the retry belonged to `finance.record`
        // and is gone for every role.
        ->assertDontSee('Retry this night');
});

it('shows the weekly banner for a failed weekly run', function (): void {
    rootRun('compensation.weekly-run', '2025-12-30', EngineRun::STATUS_FAILED, 'GSB Weekly Payout exited 1.');

    $this->actingAs(engineRunsUser('admin'))
        ->get(route('admin.compensation.engine-runs.index'))
        ->assertOk()
        ->assertSee('The weekly run failed on 30 Dec 2025')
        // The weekly run sweeps credits that already exist; telling an operator
        // nobody has been credited would be false.
        ->assertSee('weekly income is still in the distributors')
        ->assertDontSee('The nightly run failed on');
});

it('banners all three runs at once, and offers no button to any admin role', function (): void {
    chainRun('2025-12-31', EngineRun::STATUS_FAILED, 'nightly boom');
    rootRun('compensation.weekly-run', '2025-12-30', EngineRun::STATUS_FAILED, 'weekly boom');
    rootRun('compensation.monthly-run', '2025-12-29', EngineRun::STATUS_FAILED, 'monthly boom');

    foreach (['admin', 'admin-finance', 'admin-compliance', 'admin-operations'] as $role) {
        $this->actingAs(engineRunsUser($role))
            ->get(route('admin.compensation.engine-runs.index'))
            ->assertOk()
            ->assertSee('The nightly run failed on 31 Dec 2025')
            ->assertSee('The weekly run failed on 30 Dec 2025')
            ->assertSee('The monthly run failed on 29 Dec 2025')
            ->assertDontSee('Retry this night');
    }
});

it('lists the nightly run\'s steps with the day each one was given', function (): void {
    // The cut-off is dated night − 1, and a backfilled night reaches further
    // back still, so a step list scoped by the run's PERIOD could never show
    // the one step whose failure the banner exists to explain.
    $run = chainRun('2025-12-31', EngineRun::STATUS_FAILED, 'GSB Daily Cut-off exited 1.');

    stepRun('repurchase.evaluate', '2025-12-31', EngineRun::STATUS_SUCCEEDED, '2025-12-31 00:06:00');
    stepRun('gsb.daily-cutoff', '2025-12-29', EngineRun::STATUS_SUCCEEDED, '2025-12-31 00:08:00');
    stepRun('gsb.daily-cutoff', '2025-12-30', EngineRun::STATUS_FAILED, '2025-12-31 00:12:00');
    // Someone re-ran the evaluate by hand an hour after the run gave up: a
    // different attempt, and it belongs to no run row on this page.
    stepRun('repurchase.evaluate', '2025-12-31', EngineRun::STATUS_SUCCEEDED, '2025-12-31 09:00:00');

    $response = $this->actingAs(engineRunsUser('admin'))
        ->get(route('admin.compensation.engine-runs.index'))
        ->assertOk()
        ->assertSee('GSB Daily Cut-off (incl. MSB) — 30 Dec 2025')
        ->assertSee('GSB Daily Cut-off (incl. MSB) — 29 Dec 2025')
        ->assertSee('Repurchase Evaluation — 31 Dec 2025');

    // The hand-typed re-run is the second row for that engine and period; the
    // banner must list the engine once, from inside the run's own window.
    expect(substr_count($response->getContent() ?: '', 'Repurchase Evaluation — 31 Dec 2025'))->toBe(1);
    expect($run->finished_at)->not->toBeNull();
});

it('drops the banner once a later night has succeeded, because the backfill healed the gap', function (): void {
    chainRun('2025-12-30', EngineRun::STATUS_FAILED, 'boom');
    chainRun('2025-12-31', EngineRun::STATUS_SUCCEEDED);

    $this->actingAs(engineRunsUser('admin'))
        ->get(route('admin.compensation.engine-runs.index'))
        ->assertOk()
        ->assertDontSee('The nightly run failed on');
});

it('shows no banner for a run that was skipped rather than failed', function (): void {
    // A preflight refusal is a decision — a stale worker, a standing
    // projection. The chain alerts say what to actually do about each.
    chainRun('2025-12-31', EngineRun::STATUS_SKIPPED, 'A recompute projection is standing.');

    $this->actingAs(engineRunsUser('admin'))
        ->get(route('admin.compensation.engine-runs.index'))
        ->assertOk()
        ->assertDontSee('The nightly run failed on');
});

it('ignores a run still in flight when deciding whether to banner it', function (): void {
    chainRun('2025-12-30', EngineRun::STATUS_SUCCEEDED);
    chainRun('2025-12-31', EngineRun::STATUS_RUNNING);

    $this->actingAs(engineRunsUser('admin'))
        ->get(route('admin.compensation.engine-runs.index'))
        ->assertOk()
        ->assertDontSee('The nightly run failed on');
});

it('says a new attempt is running rather than leaving the page looking unchanged', function (): void {
    // failedRootRun() ignores `running` rows on purpose, so the banner stays up
    // for the whole of the next attempt.
    chainRun('2025-12-31', EngineRun::STATUS_FAILED, 'boom');
    EngineRun::create([
        'engine_key' => 'compensation.nightly-run',
        'period_start' => '2025-12-31',
        'status' => EngineRun::STATUS_RUNNING,
        'trigger' => 'schedule',
        'actor_id' => null,
        'chain_id' => null,
        'summary' => null,
        'error' => null,
        'started_at' => Carbon::now()->subMinutes(2),
        'finished_at' => null,
        'duration_ms' => null,
    ]);

    $this->actingAs(engineRunsUser('admin'))
        ->get(route('admin.compensation.engine-runs.index'))
        ->assertOk()
        ->assertSee('A new attempt is running now.');
});

it('caps the failure text it renders, so a query exception cannot dump its bindings onto the page', function (): void {
    chainRun('2025-12-31', EngineRun::STATUS_FAILED, str_repeat('E', 4000));

    $response = $this->actingAs(engineRunsUser('admin'))
        ->get(route('admin.compensation.engine-runs.index'))
        ->assertOk();

    expect(substr_count($response->getContent() ?: '', 'E'))->toBeLessThan(2000);
});

/*
|--------------------------------------------------------------------------
| The developer rebuild surface (ADR-0016, D4)
|--------------------------------------------------------------------------
|
| A rebuild deletes derived money rows in production, so it belongs to one
| role and is invisible to every other. Two kinds of assertion follow and both
| matter: the POSTs are refused to the whole admin family, AND the page they
| land on carries no trace of the feature. A control an admin can see but not
| use is an invitation to ask who can — and the developer role is never
| surfaced anywhere in the UI (F84).
*/

/** A pending Tuesday batch — the cheapest period that has something to un-build. */
function rebuildableWeeklyBatch(string $tuesday = '2025-12-30', string $status = PayoutBatch::STATUS_PENDING): PayoutBatch
{
    return PayoutBatch::create([
        'batch_type' => PayoutBatch::TYPE_WEEKLY,
        'batch_date' => $tuesday,
        'status' => $status,
        'approved_at' => $status === PayoutBatch::STATUS_PENDING ? null : Carbon::parse($tuesday.' 10:00:00'),
    ]);
}

it('leaves no trace of the rebuild surface on an admin\'s page, healthy or failed', function (): void {
    rebuildableWeeklyBatch();
    // The state a developer would come here to repair: the banners are shown to
    // everyone, the repair is not.
    chainRun('2025-12-31', EngineRun::STATUS_FAILED, 'GSB Daily Cut-off exited 1.');
    rootRun('compensation.weekly-run', '2025-12-30', EngineRun::STATUS_FAILED, 'weekly boom');

    foreach (['admin', 'admin-finance', 'admin-compliance', 'admin-operations'] as $role) {
        $html = $this->actingAs(engineRunsUser($role))
            ->get(route('admin.compensation.engine-runs.index'))
            ->assertOk()
            ->assertSee('The nightly run failed on 31 Dec 2025')
            ->getContent() ?: '';

        // The CONTROL, not the word. The standard is "no control, no command":
        // no panel, neither form target, no reason field and no rebuild command
        // an admin could copy into a shell. The bare noun is not the test —
        // FrozenPayoutGuard's frozen-month refusal uses "rebuild" as an ordinary
        // English word, and it renders here through the layout's $errors block.
        expect($html)->not->toContain('Rebuild a period')
            ->and($html)->not->toContain('Preview rebuild')
            ->and($html)->not->toContain('Rebuild now')
            ->and($html)->not->toContain('rebuild-reason')
            ->and($html)->not->toContain(route('admin.compensation.engine-runs.rebuild.preview', absolute: false))
            ->and($html)->not->toContain(route('admin.compensation.engine-runs.rebuild', absolute: false))
            ->and($html)->not->toContain('compensation:rebuild-');
    }
});

it('refuses both rebuild routes to every admin role, super staff included', function (): void {
    Queue::fake();

    foreach (['admin', 'admin-finance', 'admin-compliance', 'admin-operations'] as $role) {
        $user = engineRunsUser($role);

        $this->actingAs($user)
            ->post(route('admin.compensation.engine-runs.rebuild.preview'), [
                'kind' => 'week',
                'period' => '2025-12-30',
            ])
            ->assertForbidden();

        $this->actingAs($user)
            ->post(route('admin.compensation.engine-runs.rebuild'), [
                'kind' => 'week',
                'period' => '2025-12-30',
                'fingerprint' => 'anything',
                'reason' => 'Trying it on from an admin account.',
            ])
            ->assertForbidden();
    }

    Queue::assertNothingPushed();
});

it('keeps the rebuilds out of the admin\'s Run events filter until one has run', function (): void {
    // The rebuild rows are an audit fact; the DROPDOWN is not. On a platform
    // where no rebuild has ever happened, listing the four in the Engine filter
    // announces a developer-only control with no fact behind it (F84).
    foreach (['admin', 'admin-finance', 'admin-compliance', 'admin-operations'] as $role) {
        $this->actingAs(engineRunsUser($role))
            ->get(route('admin.compensation.engine-runs.events'))
            ->assertOk()
            ->assertSee('All engines')
            ->assertDontSee('Rebuild — night')
            ->assertDontSee('Rebuild — weekly payout')
            ->assertDontSee('Rebuild — monthly close')
            ->assertDontSee('Rebuild — monthly payout');
    }

    $this->actingAs(engineRunsUser('developer'))
        ->get(route('admin.compensation.engine-runs.events'))
        ->assertOk()
        ->assertSee('Rebuild — night')
        ->assertSee('Rebuild — weekly payout')
        ->assertSee('Rebuild — monthly close')
        ->assertSee('Rebuild — monthly payout');
});

it('still lists a rebuild that happened to every admin role', function (): void {
    // The other half of the same rule: what the dropdown withholds, the run log
    // does not. A rebuild deleted derived money rows, and that is an audit fact
    // for `admin-compliance` whether or not they can start one.
    rootRun('compensation.rebuild-night', '2026-01-01', EngineRun::STATUS_SUCCEEDED);

    foreach (['admin', 'admin-compliance'] as $role) {
        $this->actingAs(engineRunsUser($role))
            ->get(route('admin.compensation.engine-runs.events'))
            ->assertOk()
            ->assertSee('Rebuild — night');
    }
});

it('refuses an admin a Run events filter on a rebuild engine, and allows the developer one', function (): void {
    $this->actingAs(engineRunsUser('admin'))
        ->from(route('admin.compensation.engine-runs.index'))
        ->get(route('admin.compensation.engine-runs.events', ['engine' => 'compensation.rebuild-night']))
        ->assertSessionHasErrors('engine');

    $this->actingAs(engineRunsUser('developer'))
        ->get(route('admin.compensation.engine-runs.events', ['engine' => 'compensation.rebuild-night']))
        ->assertOk();
});

it('shows the developer the rebuild panel and its four periods', function (): void {
    rebuildableWeeklyBatch();
    PayoutBatch::create([
        'batch_type' => PayoutBatch::TYPE_MONTHLY,
        'batch_date' => '2026-01-01',
        'status' => PayoutBatch::STATUS_PENDING,
    ]);

    $this->actingAs(engineRunsUser('developer'))
        ->get(route('admin.compensation.engine-runs.index'))
        ->assertOk()
        ->assertSee('Rebuild a period (platform team)')
        // The form-purpose note, before any field. Raw: it is literal Blade
        // text, so its apostrophes are not HTML-escaped in the response.
        ->assertSee('Rebuild wipes one period\'s computed rows and runs that period\'s command again from scratch.', false)
        ->assertSee('Night')
        ->assertSee('Weekly payout batch')
        ->assertSee('Monthly close')
        ->assertSee('Monthly payout batch')
        // The unapproved batches are offered by id and date; December 2025 is
        // the newest ended month.
        ->assertSee('Tue 30 Dec 2025')
        ->assertSee('December 2025')
        ->assertSee('Preview rebuild');
});

it('leaves an approved batch out of the developer\'s pickers entirely', function (): void {
    rebuildableWeeklyBatch('2025-12-30', PayoutBatch::STATUS_APPROVED);

    $this->actingAs(engineRunsUser('developer'))
        ->get(route('admin.compensation.engine-runs.index'))
        ->assertOk()
        ->assertSee('No unapproved weekly batch in the last eight weeks.')
        ->assertDontSee('Tue 30 Dec 2025');
});

it('previews a rebuild with its row counts, un-sweeps and warnings, and no confirm until then', function (): void {
    $batch = rebuildableWeeklyBatch();
    $developer = engineRunsUser('developer');

    $this->actingAs($developer)
        ->post(route('admin.compensation.engine-runs.rebuild.preview'), [
            'kind' => 'week',
            'period' => '2025-12-30',
        ])
        ->assertRedirect(route('admin.compensation.engine-runs.index'))
        ->assertSessionHas('rebuild_preview');

    $this->actingAs($developer)
        ->get(route('admin.compensation.engine-runs.index'))
        ->assertOk()
        ->assertSee('Removed first')
        ->assertSee('payout_batches')
        ->assertSee('Wallet credits un-swept (batch stamp removed, credit kept):')
        // R-102's precise rule, not the blanket claim the old line made while
        // sitting under a table that can list `wallet_ledger_entries`.
        ->assertSee('A credit is deleted only together with the result row it derives from')
        ->assertSee('Run these after it succeeds:')
        ->assertSee('The rebuilt batch records you as its maker; a second person must approve it.')
        ->assertSee('gsb:weekly-payout --date=2025-12-30')
        ->assertSee('Rebuild now');

    expect($batch->fresh())->not->toBeNull();
});

it('names what a night rebuild corrects in place, not only what it deletes', function (): void {
    Queue::fake();

    $developer = engineRunsUser('developer');

    // The night of 1 Jan rebuilds the 31 Dec cut-off. A personal-BV top-up on
    // that day is not deleted outright: its BV is handed back to the
    // `group_bv_daily` accumulator it inflated. That is a mutation of BV, so the
    // preview and the confirm have to disclose it — a confirm that lists only
    // deletions understates what is being authorised (compliance C1).
    GsbPersonalBvTopup::create([
        'distributor_id' => 1,
        'order_id' => 1,
        'bv_paise' => 250000,
        'side' => 'L',
        'date' => '2025-12-31',
    ]);

    $this->actingAs($developer)
        ->post(route('admin.compensation.engine-runs.rebuild.preview'), [
            'kind' => 'night',
            'period' => '2026-01-01',
        ])
        ->assertRedirect(route('admin.compensation.engine-runs.index'));

    $preview = session('rebuild_preview');

    expect($preview['adjustments'])->toBe(['group_bv_daily' => 1]);

    $this->actingAs($developer)
        ->get(route('admin.compensation.engine-runs.index'))
        ->assertOk()
        ->assertSee('Rebuild — night')
        ->assertSee('Corrected in place, not deleted:')
        ->assertSee('group_bv_daily')
        ->assertSee('gsb_personal_bv_topups')
        // And in the confirm modal's own impact line, not only in the card
        // above it: the modal is the last thing read before the rebuild is
        // authorised, and it summed deletions alone.
        ->assertSee('Corrected in place, not deleted: group_bv_daily — 1 row(s).', false)
        // The registry label already begins with "Rebuild — ", so the modal
        // title must not prefix it again.
        ->assertSee('data-confirm-title="Rebuild — night · 2026-01-01"', false)
        ->assertDontSee('Rebuild Rebuild', false);

    $this->actingAs($developer)
        ->post(route('admin.compensation.engine-runs.rebuild'), [
            'kind' => 'night',
            'period' => '2026-01-01',
            'fingerprint' => $preview['fingerprint'],
            'reason' => 'Cut-off exited 1 on a deadlocked write — re-running the night.',
        ])
        ->assertRedirect(route('admin.compensation.engine-runs.index'));

    $log = AuditLog::where('action', 'compensation.rebuild.queued')->sole();

    expect($log->details['adjustments'])->toBe(['group_bv_daily' => 1]);
});

it('previews a monthly close, whose period is a month rather than a date', function (): void {
    $developer = engineRunsUser('developer');

    // The `Y-m` half of the two period formats, and the months picker that
    // feeds it — December 2025 is the newest ended month at the frozen clock.
    $this->actingAs($developer)
        ->post(route('admin.compensation.engine-runs.rebuild.preview'), [
            'kind' => 'month',
            'period' => '2025-12',
        ])
        ->assertRedirect(route('admin.compensation.engine-runs.index'));

    $this->actingAs($developer)
        ->get(route('admin.compensation.engine-runs.index'))
        ->assertOk()
        ->assertSee('Rebuild — monthly close')
        ->assertSee('Nothing to remove for this period')
        ->assertSee('Repurchase cycle verdicts taken between the original close and now are not re-taken.')
        ->assertSee('Rebuild now');
});

it('offers the developer the nights that have something to rebuild', function (): void {
    // Both sources `recentNights()` reads: a run row for one night, a cut-off
    // row that dates its own night a day later.
    chainRun('2025-12-30', EngineRun::STATUS_FAILED, 'GSB Daily Cut-off exited 1.');
    GsbCutoffResult::create([
        'distributor_id' => 1,
        'cutoff_date' => '2025-12-31',
        'left_bv_paise' => 0, 'right_bv_paise' => 0, 'weaker_bv_paise' => 0,
        'slab' => 0, 'score' => 0, 'score_value_paise' => 0,
        'gross_gsb_paise' => 0, 'repurchase_deduction_paise' => 0, 'admin_charge_paise' => 0,
        'tds_paise' => 0, 'net_gsb_paise' => 0,
        'power_cf_before_paise' => 0, 'power_cf_after_paise' => 0,
        'slab1_weaker_cf_before_paise' => 0, 'slab1_weaker_cf_after_paise' => 0,
        'status' => GsbCutoffResult::STATUS_NO_MATCH,
    ]);

    $this->actingAs(engineRunsUser('developer'))
        ->get(route('admin.compensation.engine-runs.index'))
        ->assertOk()
        // The cut-off dated 31 Dec belongs to the night of 1 Jan.
        ->assertSee('Thu 01 Jan 2026')
        ->assertSee('Tue 30 Dec 2025');
});

it('renders the refusals and offers no confirm form when a period cannot be rebuilt', function (): void {
    // No batch exists for that Tuesday: nothing to un-build, so the planner
    // refuses rather than queueing a job that would do nothing.
    $developer = engineRunsUser('developer');

    $this->actingAs($developer)
        ->post(route('admin.compensation.engine-runs.rebuild.preview'), [
            'kind' => 'week',
            'period' => '2025-12-30',
        ])
        ->assertRedirect(route('admin.compensation.engine-runs.index'));

    $this->actingAs($developer)
        ->get(route('admin.compensation.engine-runs.index'))
        ->assertOk()
        ->assertSee('This period cannot be rebuilt:')
        ->assertSee('No weekly batch is dated 2025-12-30')
        ->assertDontSee('Rebuild now')
        ->assertSee('Dismiss');
});

it('queues the rebuild, audits it under the developer with the client IP, and clears the preview', function (): void {
    Queue::fake();

    $batch = rebuildableWeeklyBatch();
    $developer = engineRunsUser('developer');

    $this->actingAs($developer)
        ->post(route('admin.compensation.engine-runs.rebuild.preview'), ['kind' => 'week', 'period' => '2025-12-30']);

    $preview = session('rebuild_preview');
    expect($preview)->toBeArray();

    $this->actingAs($developer)
        ->post(route('admin.compensation.engine-runs.rebuild'), [
            'kind' => 'week',
            'period' => '2025-12-30',
            'fingerprint' => $preview['fingerprint'],
            'reason' => 'Weekly run exited 1 on a deadlocked write — rebuilding the batch.',
        ])
        ->assertRedirect(route('admin.compensation.engine-runs.index'))
        ->assertSessionHas('status')
        ->assertSessionMissing('rebuild_preview');

    Queue::assertPushed(RebuildPeriodJob::class, function (RebuildPeriodJob $job) use ($developer): bool {
        return $job->kind === 'week'
            && $job->period === '2025-12-30'
            && $job->actorId === $developer->id
            && $job->chainId !== '';
    });

    $log = AuditLog::where('action', 'compensation.rebuild.queued')->sole();

    expect($log->actor_id)->toBe($developer->id)
        ->and($log->details['kind'])->toBe('week')
        ->and($log->details['period'])->toBe('2025-12-30')
        ->and($log->details['reason'])->toBe('Weekly run exited 1 on a deadlocked write — rebuilding the batch.')
        ->and($log->details['warnings'])->toContain('The rebuilt batch records you as its maker; a second person must approve it.')
        ->and($log->details['rows_to_remove'])->toBe(['payout_batches' => 1])
        // Everything the rebuild touches, at the moment it was authorised: a
        // batch corrects nothing in place, but the key is recorded either way
        // so a night's `group_bv_daily` hand-back can never go unrecorded here.
        ->and($log->details)->toHaveKey('adjustments')
        ->and($log->details['adjustments'])->toBe([])
        ->and($log->details['chain_id'])->not->toBeEmpty()
        // A shell rebuild records no address; a decision taken over the web
        // must carry where it was taken from.
        ->and($log->ip)->not->toBeNull();

    expect(PayoutBatch::find($batch->id))->not->toBeNull();
});

it('refuses a confirm whose fingerprint does not match the state it was previewed against', function (): void {
    Queue::fake();

    rebuildableWeeklyBatch();
    $developer = engineRunsUser('developer');

    $this->actingAs($developer)
        ->post(route('admin.compensation.engine-runs.rebuild'), [
            'kind' => 'week',
            'period' => '2025-12-30',
            'fingerprint' => str_repeat('0', 64),
            'reason' => 'Confirming against a preview nobody took.',
        ])
        ->assertSessionHasErrors('fingerprint')
        // The fresh plan goes back on the page: a refusal beside a stale card
        // is how somebody confirms the same thing twice.
        ->assertSessionHas('rebuild_preview');

    Queue::assertNothingPushed();
});

it('refuses a confirm once the state moved under it, and says so', function (): void {
    Queue::fake();

    $batch = rebuildableWeeklyBatch();
    $developer = engineRunsUser('developer');

    $this->actingAs($developer)
        ->post(route('admin.compensation.engine-runs.rebuild.preview'), ['kind' => 'week', 'period' => '2025-12-30']);

    $fingerprint = session('rebuild_preview')['fingerprint'];

    // One more line item than the preview counted — the same batch, a
    // different wipe.
    PayoutLineItem::create([
        'payout_batch_id' => $batch->id,
        'distributor_id' => 1,
        'gross_paise' => 100000,
        'admin_charge_paise' => 0,
        'tds_paise' => 0,
        'wallet_balance_paise' => 0,
        'repurchase_deduction_paise' => 0,
        'net_transferred_paise' => 100000,
        'status' => PayoutLineItem::STATUS_PENDING,
    ]);

    $this->actingAs($developer)
        ->post(route('admin.compensation.engine-runs.rebuild'), [
            'kind' => 'week',
            'period' => '2025-12-30',
            'fingerprint' => $fingerprint,
            'reason' => 'Rebuilding the Tuesday batch after the failed weekly run.',
        ])
        ->assertSessionHasErrors(['fingerprint' => 'The state changed since the preview — preview again.']);

    Queue::assertNothingPushed();
});

it('refuses a confirm for a period the planner will not rebuild, naming every refusal', function (): void {
    Queue::fake();

    rebuildableWeeklyBatch('2025-12-30', PayoutBatch::STATUS_APPROVED);
    $developer = engineRunsUser('developer');

    $this->actingAs($developer)
        ->post(route('admin.compensation.engine-runs.rebuild'), [
            'kind' => 'week',
            'period' => '2025-12-30',
            'fingerprint' => str_repeat('0', 64),
            'reason' => 'Trying to rebuild a batch finance already signed off.',
        ])
        ->assertSessionHasErrors('period');

    expect(session('errors')->get('period')[0])->toContain('finance has signed off is not rebuilt');

    Queue::assertNothingPushed();
});

it('requires a reason of at least ten characters and a period in the kind\'s own format', function (): void {
    Queue::fake();

    rebuildableWeeklyBatch();
    $developer = engineRunsUser('developer');

    $this->actingAs($developer)
        ->post(route('admin.compensation.engine-runs.rebuild'), [
            'kind' => 'week',
            'period' => '2025-12-30',
            'fingerprint' => str_repeat('0', 64),
            'reason' => 'too short',
        ])
        ->assertSessionHasErrors('reason');

    // A month rebuild takes YYYY-MM; a date typed into it is refused before
    // anything is planned.
    $this->actingAs($developer)
        ->post(route('admin.compensation.engine-runs.rebuild.preview'), [
            'kind' => 'month',
            'period' => '2025-12-30',
        ])
        ->assertSessionHasErrors('period');

    $this->actingAs($developer)
        ->post(route('admin.compensation.engine-runs.rebuild.preview'), [
            'kind' => 'not-a-kind',
            'period' => '2025-12-30',
        ])
        ->assertSessionHasErrors('kind');

    Queue::assertNothingPushed();
});

/*
|--------------------------------------------------------------------------
| The manual trigger's own refusals (A1, A10)
|--------------------------------------------------------------------------
*/

it('refuses a manual monthly engine for a month whose payout finance has approved', function (): void {
    Queue::fake();
    Feature::activate(RankBonusFeature::class);

    PayoutBatch::create([
        'batch_type' => PayoutBatch::TYPE_MONTHLY,
        'batch_date' => '2026-01-01',
        'status' => PayoutBatch::STATUS_APPROVED,
        'approved_at' => Carbon::parse('2026-01-08 10:00:00'),
    ]);

    $this->actingAs(engineRunsUser('admin'))
        ->post(route('admin.compensation.engine-runs.trigger'), [
            'engine' => 'rank.check',
            'period' => '2025-12',
            'reason' => 'Re-running December after the fix.',
        ])
        ->assertSessionHasErrors('period');

    expect(session('errors')->get('period')[0])->toContain('December 2025 is frozen');

    Queue::assertNothingPushed();
});

it('never names the developer\'s rebuild command to an admin when a month is closed to credits', function (): void {
    Queue::fake();
    Feature::activate(RankBonusFeature::class);

    // Built and waiting for finance: nothing may be credited into December any
    // more, but the batch itself can still be taken apart — by the platform
    // team, on a surface this admin must not learn about.
    PayoutBatch::create([
        'batch_type' => PayoutBatch::TYPE_MONTHLY,
        'batch_date' => '2026-01-01',
        'status' => PayoutBatch::STATUS_PENDING,
        'processed_at' => Carbon::parse('2026-01-08 03:00:00'),
    ]);

    $this->actingAs(engineRunsUser('admin-finance'))
        ->post(route('admin.compensation.engine-runs.trigger'), [
            'engine' => 'rank.check',
            'period' => '2025-12',
            'reason' => 'Re-running December after the fix.',
        ])
        ->assertSessionHasErrors('period');

    $message = session('errors')->get('period')[0];

    expect($message)->toContain('awaits approval')
        ->and($message)->toContain('The platform team has to put the batch back in step')
        ->and($message)->not->toContain('compensation:rebuild-payout');

    // The developer, who can act on it, is told exactly what to run.
    $this->actingAs(engineRunsUser('developer'))
        ->post(route('admin.compensation.engine-runs.trigger'), [
            'engine' => 'rank.check',
            'period' => '2025-12',
            'reason' => 'Re-running December after the fix.',
        ])
        ->assertSessionHasErrors('period');

    expect(session('errors')->get('period')[0])->toContain('compensation:rebuild-payout --month=2025-12');

    Queue::assertNothingPushed();
});

it('refuses a manual cut-off for a day the carry-forward has already moved past', function (): void {
    Queue::fake();
    Feature::activate(GenosSalesBonusFeature::class);

    // 30 Dec advanced the rolling store; re-running 28 Dec now would fold its
    // Genos BV in on top of the 30th's. GsbCutoffService throws on exactly this
    // per distributor, halfway through a run that has already written rows.
    GsbCutoffResult::create([
        'distributor_id' => 1,
        'cutoff_date' => '2025-12-30',
        'left_bv_paise' => 0, 'right_bv_paise' => 0, 'weaker_bv_paise' => 0,
        'slab' => 0, 'score' => 0, 'score_value_paise' => 0,
        'gross_gsb_paise' => 0, 'repurchase_deduction_paise' => 0, 'admin_charge_paise' => 0,
        'tds_paise' => 0, 'net_gsb_paise' => 0,
        'power_cf_before_paise' => 0, 'power_cf_after_paise' => 0,
        'slab1_weaker_cf_before_paise' => 0, 'slab1_weaker_cf_after_paise' => 0,
        'status' => GsbCutoffResult::STATUS_NO_MATCH,
    ]);

    $this->actingAs(engineRunsUser('admin'))
        ->post(route('admin.compensation.engine-runs.trigger'), [
            'engine' => 'gsb.daily-cutoff',
            'period' => '2025-12-28',
            'reason' => 'Cut-off missed on the 28th — backfilling it.',
        ])
        ->assertSessionHasErrors('period');

    $message = session('errors')->get('period')[0];

    expect($message)->toContain('30 Dec 2025 has already been cut off')
        ->and($message)->toContain('only while it is the newest one')
        // The remedy an admin is given is the run that heals itself, never a
        // control they do not have.
        ->and($message)->not->toContain('rebuild-night');

    Queue::assertNothingPushed();
});

it('lets a manual cut-off through when the later day never advanced the carry-forward', function (): void {
    Queue::fake();
    Feature::activate(GenosSalesBonusFeature::class);

    // `below_600bv` returns before touching the rolling store, so the 30th
    // moved nothing and the 28th is still the newest day that matters.
    GsbCutoffResult::create([
        'distributor_id' => 1,
        'cutoff_date' => '2025-12-30',
        'left_bv_paise' => 0, 'right_bv_paise' => 0, 'weaker_bv_paise' => 0,
        'slab' => 0, 'score' => 0, 'score_value_paise' => 0,
        'gross_gsb_paise' => 0, 'repurchase_deduction_paise' => 0, 'admin_charge_paise' => 0,
        'tds_paise' => 0, 'net_gsb_paise' => 0,
        'power_cf_before_paise' => 0, 'power_cf_after_paise' => 0,
        'slab1_weaker_cf_before_paise' => 0, 'slab1_weaker_cf_after_paise' => 0,
        'status' => GsbCutoffResult::STATUS_BELOW_600BV,
    ]);

    $this->actingAs(engineRunsUser('admin'))
        ->post(route('admin.compensation.engine-runs.trigger'), [
            'engine' => 'gsb.daily-cutoff',
            'period' => '2025-12-28',
            'reason' => 'Cut-off missed on the 28th — backfilling it.',
        ])
        ->assertRedirect(route('admin.compensation.engine-runs.index'))
        ->assertSessionHasNoErrors();

    Queue::assertPushed(RunEngineChainJob::class);
});
