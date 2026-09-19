<?php

declare(strict_types=1);

use App\Modules\Compensation\Models\EngineRun;
use App\Modules\Compensation\Notifications\EngineHealthDigestNotification;
use App\Modules\Compensation\Services\EngineHealthService;
use App\Modules\Compensation\Support\EngineRegistry;
use App\Modules\Compensation\Support\NightlyRunAlert;
use App\Modules\Compensation\Support\PrematureFreezeAlert;
use App\Modules\Compliance\Models\AuditLog;
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
        // The weekly batch has no button, and the weekly run rebuilds a missed
        // Tuesday on its next night — still dated that Tuesday.
        ->toContain('distributors do not wait a week')
        ->toContain('compensation:weekly-run --date=2026-09-08')
        // The monthly payout is re-attempted every night from the 8th; nothing
        // for an admin to type, and no control to point at.
        ->toContain('The monthly run re-attempts the payout every night from the 8th')
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

it('reports a pool the self-heal had to keep after a premature freeze', function (): void {
    // F30: the 05 Sep staging cut-off was frozen mid-day and kept because
    // mentors had already been credited at the wrong point value. That fact
    // existed only as a log line — no audit row, no failed run, nothing in this
    // email — and went unnoticed for a month while a distributor was ₹7,488 short.
    seedHealthyRuns();

    AuditLog::create([
        'actor_id' => null,
        'action' => PrematureFreezeAlert::ACTION,
        'subject_type' => 'gsb_daily_pool',
        'subject_id' => 11,
        'details' => [
            'engine_key' => 'gsb.daily-cutoff',
            'period' => '2026-09-05',
            'frozen_at' => '2026-09-05 14:03:29',
            'reason' => 'results were already priced against this pool',
        ],
    ]);

    $this->artisan('compensation:engine-health-digest')->assertExitCode(0);

    $text = digestText(sentDigest());

    expect($text)
        ->toContain('Compensation engines need attention — 1 item(s)')
        ->toContain('GSB Daily Cut-off (incl. MSB) — 05 Sep 2026')
        ->toContain('pool frozen 2026-09-05 14:03:29')
        ->toContain('Do NOT re-run the engine');
});

it('records a kept premature freeze once, however many runs re-detect it', function (): void {
    // The condition is permanent: every later run for the day finds it again.
    PrematureFreezeAlert::kept(
        engineKey: 'gsb.daily-cutoff',
        subjectType: 'gsb_daily_pool',
        subjectId: 11,
        period: '2026-09-05',
        reason: 'results were already priced against this pool',
        details: ['company_bv_paise' => 55_940_000],
    );
    PrematureFreezeAlert::kept(
        engineKey: 'gsb.daily-cutoff',
        subjectType: 'gsb_daily_pool',
        subjectId: 11,
        period: '2026-09-05',
        reason: 'results were already priced against this pool',
        details: ['company_bv_paise' => 55_940_000],
    );

    expect(AuditLog::where('action', PrematureFreezeAlert::ACTION)->count())->toBe(1);
});

it('stops reporting a failed monthly run once any later night of it succeeded', function (): void {
    // D5. The period of a root orchestrator's run is a NIGHT, and a night is
    // never re-run: the monthly run re-attempts what the month owes on its next
    // night, under a new date. The per-period rule would have kept 08 September
    // in the digest for thirty days with no run of that date ever coming to
    // resolve it (staging, since 8 Sep 2026).
    seedHealthyRuns();
    engineRun('compensation.monthly-run', '2026-09-06', EngineRun::STATUS_FAILED, '2026-09-06 04:00:00', 'Monthly Close exited 1.');
    engineRun('compensation.monthly-run', '2026-09-07', EngineRun::STATUS_SUCCEEDED, '2026-09-07 04:00:00');

    $this->artisan('compensation:engine-health-digest')
        ->expectsOutputToContain('All engines healthy')
        ->assertExitCode(0);

    Notification::assertNothingSent();
});

it('still reports a failed leaf that a later period of the same engine did not fix', function (): void {
    // The other half of D5: a cut-off that failed for the 5th is still owed
    // however many later days succeed, because that day's results do not exist.
    seedHealthyRuns();
    engineRun('gsb.daily-cutoff', '2026-09-05', EngineRun::STATUS_FAILED, '2026-09-06 00:10:00', 'Boom.');

    $this->artisan('compensation:engine-health-digest')->assertExitCode(0);

    expect(digestText(sentDigest()))->toContain('GSB Daily Cut-off (incl. MSB) — 05 Sep 2026');
});

it('names the run a skipped-night alert belongs to', function (): void {
    // Three runs fire on their own clocks now, and a nightly overlap says
    // nothing about whether the weekly one started. The orchestrator key is in
    // the dedupe key as well as the row, so one night can carry one alert per
    // run rather than the first one swallowing the rest.
    seedHealthyRuns();

    NightlyRunAlert::skippedNight(Carbon::parse('2026-09-08'), 'nightly overlapped', 'compensation.nightly-run');
    NightlyRunAlert::skippedNight(Carbon::parse('2026-09-08'), 'weekly overlapped', 'compensation.weekly-run');
    NightlyRunAlert::skippedNight(Carbon::parse('2026-09-08'), 'weekly overlapped', 'compensation.weekly-run');

    expect(AuditLog::where('action', NightlyRunAlert::ACTION_SKIPPED_NIGHT)->count())->toBe(2);
    expect(
        AuditLog::where('action', NightlyRunAlert::ACTION_SKIPPED_NIGHT)
            ->get()
            ->pluck('details.orchestrator')
            ->all()
    )->toBe(['compensation.nightly-run', 'compensation.weekly-run']);

    $this->artisan('compensation:engine-health-digest')->assertExitCode(0);

    expect(app(EngineHealthService::class)->report(Carbon::now())->chainAlerts)->toHaveCount(2);
});

it('tells a deferred close apart by its cause, and never sends a red run looking for missing days', function (): void {
    // Plan §20. Three causes, three different actions: a month deferred because
    // tonight's nightly run failed must not be reported to the ops mailbox as a
    // coverage gap somebody then goes looking for. `missing_days` is null for
    // every cause but coverage.
    seedHealthyRuns();

    NightlyRunAlert::monthCloseDeferred(
        Carbon::parse('2026-09-08'),
        Carbon::parse('2026-08-01'),
        null,
        'prerequisite',
        "August 2026 was not closed tonight: tonight's nightly run has not succeeded.",
    );

    $this->artisan('compensation:engine-health-digest')->assertExitCode(0);

    $text = digestText(sentDigest());

    expect($text)
        ->toContain("August 2026 was not closed — tonight's nightly run (or the Tuesday batch) had not succeeded")
        ->toContain('the monthly run closes it on the first night the nightly run');
    expect($text)->not->toContain('of its days have no completed cut-off');
    expect($text)->not->toContain('gsb:daily-cutoff --date=<day>');
});

it('reports a cut-off still running as exactly that', function (): void {
    seedHealthyRuns();

    NightlyRunAlert::monthCloseDeferred(
        Carbon::parse('2026-09-08'),
        Carbon::parse('2026-08-01'),
        null,
        'cutoff_in_flight',
        'August 2026 was not closed tonight: a GSB daily cut-off is still running.',
    );

    $this->artisan('compensation:engine-health-digest')->assertExitCode(0);

    $text = digestText(sentDigest());

    expect($text)->toContain('August 2026 was not closed — the cut-off was still running; it closes the next night');
    expect($text)->not->toContain('of its days have no completed cut-off');
});

it('keeps the coverage headline and its remedy for a genuine coverage gap', function (): void {
    seedHealthyRuns();

    NightlyRunAlert::monthCloseDeferred(
        Carbon::parse('2026-09-01'),
        Carbon::parse('2026-08-01'),
        3,
        'coverage',
        '3 of the 31 days in August 2026 have no completed cut-off.',
    );

    $this->artisan('compensation:engine-health-digest')->assertExitCode(0);

    expect(digestText(sentDigest()))
        ->toContain('August 2026 was not closed — 3 of its days have no completed cut-off')
        ->toContain('gsb:daily-cutoff --date=<day>')
        ->toContain('the monthly run closes the month the next night; nothing to type');
});

it('reports a deferred monthly payout as an incomplete month, with no control to click', function (): void {
    seedHealthyRuns();

    NightlyRunAlert::payoutDeferred(
        Carbon::parse('2026-09-08'),
        Carbon::parse('2026-08-01'),
        'rank.bonus',
        'The August 2026 payout was not built: Rank Bonus has not succeeded.',
    );

    $this->artisan('compensation:engine-health-digest')->assertExitCode(0);

    $text = digestText(sentDigest());

    expect($text)
        ->toContain('August 2026 has not been paid — its crediting is incomplete')
        ->toContain('The monthly run re-attempts the payout every night from the 8th');
    expect($text)->not->toContain('The nightly chain reported something it could not do');
});

it('reports a deferred Tuesday batch by its date, and says what is being waited for', function (): void {
    seedHealthyRuns();

    NightlyRunAlert::weeklyDeferred(
        Carbon::parse('2026-09-08'),
        [Carbon::parse('2026-09-08')],
        "Tonight's nightly run has not succeeded, so the 2026-09-08 weekly batch was not built.",
    );

    $this->artisan('compensation:engine-health-digest')->assertExitCode(0);

    $text = digestText(sentDigest());

    expect($text)
        ->toContain("The 08 Sep 2026 weekly payout was not built — tonight's nightly run had not succeeded")
        ->toContain('Nothing to click: the weekly run builds it on the first night the nightly run is green.');
    expect($text)->not->toContain('A scheduled run reported something it could not do');
});

it('names the run in a skipped-night headline, and what its next night picks up', function (): void {
    seedHealthyRuns();

    NightlyRunAlert::skippedNight(Carbon::parse('2026-09-08'), 'weekly overlapped', 'compensation.weekly-run');

    $this->artisan('compensation:engine-health-digest')->assertExitCode(0);

    $text = digestText(sentDigest());

    expect($text)
        ->toContain('The Weekly Run never started — the previous one was still running')
        ->toContain('the next night builds any Tuesday this one would have');
    expect($text)->not->toContain('The chain never started');
});
