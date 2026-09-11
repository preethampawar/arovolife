<?php

declare(strict_types=1);

use App\Modules\Compensation\Models\EngineRun;
use App\Modules\Compensation\Notifications\EngineHealthDigestNotification;
use App\Modules\Compensation\Services\EngineHealthService;
use App\Modules\Compensation\Support\EngineRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

uses(RefreshDatabase::class);

/**
 * The period each scheduled, non-orchestrator engine is expected to have run
 * for as at 08 Sep 2026 08:00 IST — pinned by hand rather than derived from the
 * registry, so a change to a cadence or a default period has to be looked at
 * rather than silently absorbed by the service's own walk.
 *
 * 08 Sep 2026 is a Tuesday (the weekly batch) and the 8th (the monthly payout).
 */
const HEALTHY_PERIODS = [
    'repurchase.evaluate' => '2026-09-08',
    'gsb.daily-cutoff' => '2026-09-07',      // fires 00:10 today for yesterday
    'gsb.weekly-payout' => '2026-09-08',
    'gbb.monthly' => '2026-08-01',
    'rank.check' => '2026-08-01',
    'rank.bonus' => '2026-08-01',
    'adc.bonus' => '2026-08-01',
    'offers.monthly' => '2026-08-01',
    'fortune.enroll' => '2026-08-01',
    'fortune.payout' => '2026-08-01',
    'payout.monthly' => '2026-09-01',        // dated the month the money moves
];

beforeEach(function (): void {
    Carbon::setTestNow('2026-09-08 08:00:00');
    Notification::fake();

    DB::table('settings')->updateOrInsert(
        ['key' => 'notifications.engine_health_email'],
        ['value' => 'ops@arovolife.test', 'version' => 1, 'updated_at' => now(), 'created_at' => now()],
    );
});

afterEach(function (): void {
    Carbon::setTestNow();
});

function engineRun(string $key, string $period, string $status, string $startedAt, ?string $error = null, ?string $reason = null): EngineRun
{
    return EngineRun::create([
        'engine_key' => $key,
        'period_start' => $period,
        'status' => $status,
        'trigger' => 'console',
        'summary' => $reason !== null ? ['reason' => $reason] : null,
        'error' => $error,
        'started_at' => $startedAt,
        'finished_at' => $status === EngineRun::STATUS_RUNNING ? null : $startedAt,
    ]);
}

/**
 * A succeeded run for every scheduled engine's expected period.
 *
 * @param  array<string, string>  $except  key => replacement period, or key => '' to drop the row
 */
function seedHealthyRuns(array $periods = HEALTHY_PERIODS, array $except = []): void
{
    foreach ($periods as $key => $period) {
        if (array_key_exists($key, $except)) {
            $period = $except[$key];
        }

        if ($period === '') {
            continue;
        }

        engineRun($key, $period, EngineRun::STATUS_SUCCEEDED, '2026-09-08 04:30:00');
    }
}

function sentDigest(): EngineHealthDigestNotification
{
    $sent = null;

    Notification::assertSentOnDemand(
        EngineHealthDigestNotification::class,
        function (EngineHealthDigestNotification $notification, array $channels, object $notifiable) use (&$sent): bool {
            $sent = $notification;

            return true;
        },
    );

    expect($sent)->toBeInstanceOf(EngineHealthDigestNotification::class);

    return $sent;
}

/** Subject plus every rendered line, as one searchable string. */
function digestText(EngineHealthDigestNotification $notification): string
{
    $mail = $notification->toMail(new AnonymousNotifiable);

    return implode("\n", array_merge(
        [(string) $mail->subject],
        $mail->introLines,
        $mail->outroLines,
    ));
}

it('reports a failed run that has not been re-run', function (): void {
    seedHealthyRuns(except: ['gsb.daily-cutoff' => '']);
    engineRun('gsb.daily-cutoff', '2026-09-07', EngineRun::STATUS_FAILED, '2026-09-08 00:10:00', "Repurchase evaluation has not seen 2026-09-07.\nstack trace line");

    $this->artisan('compensation:engine-health-digest')->assertExitCode(0);

    Notification::assertSentOnDemand(
        EngineHealthDigestNotification::class,
        fn ($notification, array $channels, object $notifiable): bool => $notifiable->routes['mail'] === 'ops@arovolife.test',
    );

    $text = digestText(sentDigest());

    expect($text)->toContain('Compensation engines need attention — 1 item(s)')
        ->toContain('GSB Daily Cut-off (incl. MSB) — 07 Sep 2026')
        ->toContain('Error recorded: Repurchase evaluation has not seen 2026-09-07.')
        // The stack trace never travels: only the first line of the error does.
        ->not->toContain('stack trace line');
});

it('does not report a failure that was later re-run successfully', function (): void {
    seedHealthyRuns();
    engineRun('gsb.daily-cutoff', '2026-09-07', EngineRun::STATUS_FAILED, '2026-09-08 00:10:00', 'Boom.');
    engineRun('gsb.daily-cutoff', '2026-09-07', EngineRun::STATUS_SUCCEEDED, '2026-09-08 06:00:00');

    $this->artisan('compensation:engine-health-digest')
        ->expectsOutputToContain('All engines healthy')
        ->assertExitCode(0);

    Notification::assertNothingSent();
});

it('reports a scheduled period that never ran at all', function (): void {
    seedHealthyRuns(except: ['gsb.daily-cutoff' => '']);

    $this->artisan('compensation:engine-health-digest')->assertExitCode(0);

    expect(digestText(sentDigest()))
        ->toContain('GSB Daily Cut-off (incl. MSB) — 07 Sep 2026')
        ->toContain('no run recorded');
});

it('treats a flag-off skip as having run', function (): void {
    seedHealthyRuns(except: ['adc.bonus' => '']);
    engineRun('adc.bonus', '2026-08-01', EngineRun::STATUS_SKIPPED, '2026-09-01 01:15:00', reason: 'feature_flag_off');

    $this->artisan('compensation:engine-health-digest')->assertExitCode(0);

    Notification::assertNothingSent();
});

it('reports a run that is still running hours later', function (): void {
    seedHealthyRuns();
    engineRun('gsb.daily-cutoff', '2026-09-05', EngineRun::STATUS_RUNNING, '2026-09-08 04:00:00');

    $this->artisan('compensation:engine-health-digest')->assertExitCode(0);

    expect(digestText(sentDigest()))
        ->toContain('Runs that appear stuck')
        ->toContain('(running since 08 Sep 2026 04:00)');
});

it('sends nothing when the recipient setting is blank', function (): void {
    DB::table('settings')->where('key', 'notifications.engine_health_email')->delete();
    seedHealthyRuns(except: ['gsb.daily-cutoff' => '']);

    $this->artisan('compensation:engine-health-digest')
        ->expectsOutputToContain('not set')
        ->assertExitCode(0);

    Notification::assertNothingSent();
});

it('sends on a healthy day only with --always', function (): void {
    seedHealthyRuns();

    $this->artisan('compensation:engine-health-digest --always')->assertExitCode(0);

    expect(digestText(sentDigest()))->toContain('Compensation engines — all healthy');
});

it('never sends on a dry run', function (): void {
    seedHealthyRuns(except: ['gsb.daily-cutoff' => '']);

    $this->artisan('compensation:engine-health-digest --dry-run')
        ->expectsOutputToContain('GSB Daily Cut-off')
        ->assertExitCode(0);

    Notification::assertNothingSent();
});

it('does not report a fire instant that has not arrived yet', function (): void {
    // 00:03: today's 00:05 evaluation and 00:10 cut-off have not fired, so the
    // periods still owed are yesterday's fires — evaluate for the 7th, cut-off
    // for the 6th.
    Carbon::setTestNow('2026-09-08 00:03:00');

    $earlier = [
        'repurchase.evaluate' => '2026-09-07',
        'gsb.daily-cutoff' => '2026-09-06',
        'gsb.weekly-payout' => '2026-09-01',
        'gbb.monthly' => '2026-08-01',
        'rank.check' => '2026-08-01',
        'rank.bonus' => '2026-08-01',
        'adc.bonus' => '2026-08-01',
        'offers.monthly' => '2026-08-01',
        'fortune.enroll' => '2026-08-01',
        'fortune.payout' => '2026-08-01',
        'payout.monthly' => '2026-08-01',
    ];

    seedHealthyRuns($earlier);

    $this->artisan('compensation:engine-health-digest')->assertExitCode(0);
    Notification::assertNothingSent();

    // Drop only the 6 Sep cut-off: that is the period the 07 Sep fire owed.
    EngineRun::query()->where('engine_key', 'gsb.daily-cutoff')->delete();

    $this->artisan('compensation:engine-health-digest')->assertExitCode(0);

    expect(digestText(sentDigest()))->toContain('GSB Daily Cut-off (incl. MSB) — 06 Sep 2026');
});

it('gives each item instructions specific to its engine', function (): void {
    seedHealthyRuns(except: [
        'gsb.daily-cutoff' => '',
        'gsb.weekly-payout' => '',
        'payout.monthly' => '',
    ]);
    engineRun('gsb.daily-cutoff', '2026-09-07', EngineRun::STATUS_FAILED, '2026-09-08 00:10:00', 'Boom.');
    engineRun('payout.monthly', '2026-09-01', EngineRun::STATUS_FAILED, '2026-09-08 04:00:00', 'Boom.');
    engineRun('compensation.monthly-close', '2026-08-01', EngineRun::STATUS_FAILED, '2026-09-01 00:20:00', 'Step 3 failed.');
    engineRun('rank.bonus', '2026-08-01', EngineRun::STATUS_RUNNING, '2026-09-08 03:00:00');

    $this->artisan('compensation:engine-health-digest')->assertExitCode(0);

    $text = digestText(sentDigest());

    expect($text)
        // A triggerable engine: the card, the picker value, the button.
        ->toContain('Find the card "GSB Daily Cut-off (incl. MSB)".')
        ->toContain('select 07 Sep 2026 (2026-09-07)')
        ->toContain('Preview & Confirm')
        // The weekly batch has no button and heals itself a week later.
        ->toContain("next Tuesday's batch sweeps it automatically")
        ->toContain('gsb:weekly-payout --date=2026-09-15')
        // The monthly payout is driven by the CREDITING month, not its own.
        ->toContain('compensation:monthly-payout-close --month=2026-08')
        // A stuck run is a dead worker, not something to re-trigger.
        ->toContain('queue:restart')
        // The close names the order its steps have to be re-run in.
        ->toContain('Rank Qualification Check → Rank Bonus');
});

it('names the picker field and both period forms in the remedy steps', function (): void {
    $health = app(EngineHealthService::class);

    $monthly = $health->remedyFor(EngineRegistry::get('gbb.monthly'), Carbon::parse('2026-08-01'), 'failed');
    $daily = $health->remedyFor(EngineRegistry::get('gsb.daily-cutoff'), Carbon::parse('2026-09-07'), 'missing');

    expect(implode("\n", $monthly))->toContain('In its Month field select Aug 2026 (2026-08)');
    expect(implode("\n", $daily))->toContain('In its Date field select 07 Sep 2026 (2026-09-07)');
});

it('keeps every remedy step short enough to read in an email', function (): void {
    $health = app(EngineHealthService::class);

    foreach (EngineRegistry::all() as $definition) {
        foreach (['failed', 'missing', 'stuck'] as $kind) {
            $steps = $health->remedyFor($definition, $definition->defaultPeriodDate(), $kind);

            expect($steps)->not->toBeEmpty();

            foreach ($steps as $step) {
                expect(mb_strlen($step))->toBeLessThanOrEqual(
                    200,
                    "[{$definition->key}/{$kind}] step is too long: {$step}",
                );
            }
        }
    }
});

it('never sends a scheduler-only engine looking for a button', function (): void {
    $health = app(EngineHealthService::class);

    foreach (EngineRegistry::all() as $definition) {
        if ($definition->manuallyTriggerable) {
            continue;
        }

        foreach (['failed', 'missing'] as $kind) {
            $text = implode("\n", $health->remedyFor($definition, $definition->defaultPeriodDate(), $kind));

            expect($text)->not->toContain('Preview & Confirm', "[{$definition->key}/{$kind}] tells an admin to use a button that does not exist");
        }
    }
});

it('never tells an admin to run the repurchase evaluation for a past date', function (): void {
    $health = app(EngineHealthService::class);
    $engine = EngineRegistry::get('repurchase.evaluate');

    foreach (['failed', 'missing'] as $kind) {
        $text = implode("\n", $health->remedyFor($engine, Carbon::parse('2026-09-05'), $kind));

        expect($text)
            ->toContain("leave its Date field on today's date")
            ->toContain('Do NOT select 05 Sep 2026 (2026-09-05)')
            ->not->toContain('In its Date field select');
    }
});

it('pins the cadence note the cut-off period offset is read from', function (): void {
    // EngineHealthService shifts the GSB cut-off's expected period back a day
    // because the scheduler passes --date=yesterday; the only place that fact
    // lives is this note. Reword it and the digest reports the wrong day.
    expect(EngineRegistry::get('gsb.daily-cutoff')->cadence->note)->toContain('previous day');
});

it('does not report a deliberate refusal as a failure', function (): void {
    // F43/F50: a weekly batch refused for a Wednesday it can never satisfy was
    // recorded as failed, and the digest then reported it every morning for
    // thirty days. A refusal is recorded `skipped` and carries its reason.
    seedHealthyRuns();
    engineRun(
        'gsb.weekly-payout',
        '2026-09-09',
        EngineRun::STATUS_SKIPPED,
        '2026-09-08 05:00:00',
        error: 'A weekly payout batch is dated a Tuesday; 2026-09-09 is a Wednesday.',
        reason: 'A weekly payout batch is dated a Tuesday; 2026-09-09 is a Wednesday.',
    );

    $this->artisan('compensation:engine-health-digest')
        ->expectsOutputToContain('All engines healthy')
        ->assertExitCode(0);

    Notification::assertNothingSent();
});
