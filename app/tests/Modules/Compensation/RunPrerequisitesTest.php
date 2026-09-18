<?php

declare(strict_types=1);

use App\Modules\Compensation\Models\EngineRun;
use App\Modules\Compensation\Models\PayoutBatch;
use App\Modules\Compensation\Support\RunPrerequisites;
use App\Modules\Shared\Features\GenosSalesBonusFeature;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Pennant\Feature;

uses(RefreshDatabase::class);

function seedRunRow(string $key, string $period, string $status, string $startedAt, ?string $error = null): EngineRun
{
    return EngineRun::create([
        'engine_key' => $key,
        'period_start' => $period,
        'status' => $status,
        'trigger' => EngineRun::TRIGGER_CONSOLE,
        'error' => $error,
        'started_at' => Carbon::parse($startedAt),
        'finished_at' => Carbon::parse($startedAt)->addMinutes(2),
    ]);
}

beforeEach(function (): void {
    disableTestForeignKeys();
    Feature::for(null)->activate(GenosSalesBonusFeature::class);
});

it('accepts a nightly run that succeeded tonight', function (): void {
    seedRunRow('compensation.nightly-run', '2026-09-16', EngineRun::STATUS_SUCCEEDED, '2026-09-16 00:05:00');

    expect(app(RunPrerequisites::class)->nightlyRunRefusal(Carbon::parse('2026-09-16')))->toBeNull();
});

it('refuses a succeeded nightly row for tonight that started before tonight began', function (): void {
    // A replay of a past night typed by hand. The ordering rule is about the
    // process that has just written tonight's cut-off, not about the date.
    seedRunRow('compensation.nightly-run', '2026-09-16', EngineRun::STATUS_SUCCEEDED, '2026-09-15 23:40:00');

    expect(app(RunPrerequisites::class)->nightlyRunRefusal(Carbon::parse('2026-09-16')))
        ->toContain("tonight's nightly run (16 Sep 2026) has not succeeded");
});

it('refuses when the latest nightly attempt failed, naming the error\'s first line', function (): void {
    seedRunRow('compensation.nightly-run', '2026-09-16', EngineRun::STATUS_SUCCEEDED, '2026-09-16 00:05:00');
    seedRunRow(
        'compensation.nightly-run',
        '2026-09-16',
        EngineRun::STATUS_FAILED,
        '2026-09-16 00:20:00',
        "GSB Daily Cut-off exited 1 for 15 Sep 2026.\nThe remaining steps did not run.",
    );

    expect(app(RunPrerequisites::class)->nightlyRunRefusal(Carbon::parse('2026-09-16')))
        ->toContain('its last attempt is failed: GSB Daily Cut-off exited 1 for 15 Sep 2026.')
        ->not->toContain('The remaining steps did not run.');
});

it('refuses when the nightly run has never run at all', function (): void {
    expect(app(RunPrerequisites::class)->nightlyRunRefusal(Carbon::parse('2026-09-16')))
        ->toContain('it has not run at all');
});

it('diagnoses tonight\'s attempt only, never another night\'s row', function (): void {
    // A4. On a night whose run has not started yet, reading the engine's last
    // run at ANY period names yesterday's row — and an operator told "its last
    // attempt is succeeded" goes looking for a failure that is not there.
    seedRunRow('compensation.nightly-run', '2026-09-15', EngineRun::STATUS_SUCCEEDED, '2026-09-15 00:05:00');

    expect(app(RunPrerequisites::class)->nightlyRunRefusal(Carbon::parse('2026-09-16')))
        ->toContain("tonight's nightly run (16 Sep 2026) has not succeeded")
        ->toContain('it has not run at all tonight')
        ->not->toContain('its last attempt');
});

it('asks nothing of the weekly run on a night no Tuesday is owed', function (): void {
    PayoutBatch::create([
        'batch_type' => PayoutBatch::TYPE_WEEKLY,
        'batch_date' => '2026-09-15',
        'earnings_through' => '2026-09-08',
        'status' => PayoutBatch::STATUS_PENDING,
        'processed_at' => Carbon::parse('2026-09-15 03:05:00'),
    ]);

    expect(app(RunPrerequisites::class)->weeklyRunRefusal(Carbon::parse('2026-09-16')))->toBeNull();
});

it('accepts a weekly run that succeeded tonight while a Tuesday is owed', function (): void {
    seedRunRow('compensation.weekly-run', '2026-09-15', EngineRun::STATUS_SUCCEEDED, '2026-09-15 03:00:00');

    expect(app(RunPrerequisites::class)->weeklyRunRefusal(Carbon::parse('2026-09-15')))->toBeNull();
});

it('refuses when the owed Tuesday\'s weekly run only recorded a skip', function (): void {
    // `skipped` is not an attempt: the run declined to start, so nothing has
    // decided anything about tonight's batch.
    seedRunRow('compensation.weekly-run', '2026-09-15', EngineRun::STATUS_SKIPPED, '2026-09-15 03:00:00');

    expect(app(RunPrerequisites::class)->weeklyRunRefusal(Carbon::parse('2026-09-15')))
        ->toContain("tonight's weekly run has not succeeded and a Tuesday batch (2026-09-15) is owed");
});

it('refuses when a Tuesday is owed and the weekly run never ran', function (): void {
    expect(app(RunPrerequisites::class)->weeklyRunRefusal(Carbon::parse('2026-09-15')))
        ->toContain('a Tuesday batch (2026-09-15) is owed');

    expect(PayoutBatch::query()->count())->toBe(0);
});
