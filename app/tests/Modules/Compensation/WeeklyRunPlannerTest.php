<?php

declare(strict_types=1);

use App\Modules\Compensation\Models\PayoutBatch;
use App\Modules\Compensation\Support\WeeklyRunPlanner;
use App\Modules\Shared\Features\GenosSalesBonusFeature;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Pennant\Feature;

uses(RefreshDatabase::class);

/** A built batch is the only proof a Tuesday was paid — never the run log. */
function seedPlannerWeeklyBatch(string $tuesday): void
{
    PayoutBatch::create([
        'batch_type' => PayoutBatch::TYPE_WEEKLY,
        'batch_date' => $tuesday,
        'earnings_through' => Carbon::parse($tuesday)->subDays(7)->toDateString(),
        'status' => PayoutBatch::STATUS_PENDING,
        'processed_at' => Carbon::parse($tuesday)->setTime(3, 5),
    ]);
}

/** @return list<string> */
function owedTuesdayStrings(string $night): array
{
    return array_map(
        static fn (Carbon $tuesday): string => $tuesday->toDateString(),
        app(WeeklyRunPlanner::class)->owedTuesdays(Carbon::parse($night)),
    );
}

beforeEach(function (): void {
    disableTestForeignKeys();
    Feature::for(null)->activate(GenosSalesBonusFeature::class);
});

it('owes tonight\'s Tuesday when the week before it was paid', function (): void {
    seedPlannerWeeklyBatch('2026-09-08');

    expect(owedTuesdayStrings('2026-09-15'))->toBe(['2026-09-15']);
});

it('backfills every Tuesday since the frontier, oldest first', function (): void {
    // The last batch built was 25 August; three Tuesdays have passed since, and
    // each is still owed its own batch, still dated that Tuesday.
    seedPlannerWeeklyBatch('2026-08-25');

    expect(owedTuesdayStrings('2026-09-15'))->toBe(['2026-09-01', '2026-09-08', '2026-09-15']);
});

it('owes one Tuesday only when no batch exists anywhere in the window', function (): void {
    // A fresh install must not invent a month of batches — but the most recent
    // Tuesday is still owed, which is what used to cost the FIRST payout
    // Tuesday a whole week.
    expect(owedTuesdayStrings('2026-09-16'))->toBe(['2026-09-15']);
});

it('owes tonight\'s Tuesday with the flag off, and nothing on any other night', function (): void {
    // The `skipped` row that night is the only record the engine was off on a
    // night it was due — and the succeeded weekly run around it is what keeps a
    // flag-off Tuesday from blocking the monthly close for ever. The backfill
    // is dropped: with no batch ever built it would name a debt that does not
    // exist, every night, for as long as the flag stayed off.
    Feature::for(null)->deactivate(GenosSalesBonusFeature::class);

    expect(owedTuesdayStrings('2026-09-15'))->toBe(['2026-09-15']);
    expect(owedTuesdayStrings('2026-09-16'))->toBe([]);
});

it('is not due on a Wednesday whose Tuesday was paid', function (): void {
    seedPlannerWeeklyBatch('2026-09-15');

    expect(app(WeeklyRunPlanner::class)->isDue(Carbon::parse('2026-09-16')))->toBeFalse();
});

it('is due on a Wednesday whose Tuesday was never built', function (): void {
    seedPlannerWeeklyBatch('2026-09-08');

    expect(app(WeeklyRunPlanner::class)->isDue(Carbon::parse('2026-09-16')))->toBeTrue();
    expect(owedTuesdayStrings('2026-09-16'))->toBe(['2026-09-15']);
});
