<?php

declare(strict_types=1);

use App\Modules\Compensation\Jobs\RunEngineChainJob;
use App\Modules\Compensation\Models\EngineRun;
use App\Modules\Compliance\Models\AuditLog;
use App\Modules\Identity\Models\User;
use App\Modules\Shared\Features\GenosSalesBonusFeature;
use App\Modules\Shared\Features\GrowthBoosterBonusFeature;
use App\Modules\Shared\Features\RankBonusFeature;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Laravel\Pennant\Feature;

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
        ->assertSee('Not scheduled — manual only')
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
        // The ADC row is failed; filtering to GBB must hide it.
        ->assertDontSee('failed')
        ->assertSee('manual');
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

/*
|--------------------------------------------------------------------------
| TESTING-ONLY full recompute — removed with the scaffold at client sign-off
|--------------------------------------------------------------------------
*/

it('hides the recompute card entirely when the gate is closed', function (): void {
    config(['arovolife.recompute.enabled' => false]);

    $response = $this->actingAs(engineRunsUser('admin'))
        ->get(route('admin.compensation.engine-runs.index'));

    $response->assertOk();
    // Zero-trace gating: not a disabled button, not a tooltip — no mention at all.
    $response->assertDontSee('Recompute everything');
    $response->assertDontSee('recompute-all');
});

it('shows the recompute card to an admin when the gate is open', function (): void {
    config(['arovolife.recompute.enabled' => true]);

    $response = $this->actingAs(engineRunsUser('admin'))
        ->get(route('admin.compensation.engine-runs.index'));

    $response->assertOk();
    $response->assertSee('Recompute everything');
});

it('404s the recompute endpoint when the gate is closed', function (): void {
    config(['arovolife.recompute.enabled' => false]);

    $this->actingAs(engineRunsUser('admin'))
        ->post(route('admin.compensation.engine-runs.recompute-all'))
        ->assertNotFound();
});

it('queues the recompute rather than running it inline', function (): void {
    config(['arovolife.recompute.enabled' => true]);
    Queue::fake();

    $this->actingAs(engineRunsUser('admin'))
        ->post(route('admin.compensation.engine-runs.recompute-all'))
        ->assertRedirect(route('admin.compensation.engine-runs.index'))
        ->assertSessionHas('status');

    Queue::assertPushed(\App\Modules\Compensation\Jobs\RecomputeAllJob::class);

    AuditLog::query()->where('action', 'compensation.recompute_all.queued')->firstOrFail();
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
