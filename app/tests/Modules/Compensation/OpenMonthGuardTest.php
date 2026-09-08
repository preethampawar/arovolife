<?php

declare(strict_types=1);

use App\Modules\Compensation\Support\OpenMonthGuard;
use App\Modules\Shared\Features\AreteDevelopmentCenterBonusFeature;
use App\Modules\Shared\Features\FortuneBonusFeature;
use App\Modules\Shared\Features\GrowthBoosterBonusFeature;
use App\Modules\Shared\Features\PurchaseOffersFeature;
use App\Modules\Shared\Features\RankBonusFeature;
use Illuminate\Console\Command;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Laravel\Pennant\Feature;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Carbon::setTestNow('2026-09-20 10:00:00');
    Feature::for(null)->activate(RankBonusFeature::class);
    Feature::for(null)->activate(GrowthBoosterBonusFeature::class);
    Feature::for(null)->activate(FortuneBonusFeature::class);
    Feature::for(null)->activate(AreteDevelopmentCenterBonusFeature::class);
    Feature::for(null)->activate(PurchaseOffersFeature::class);
});

afterEach(fn () => Carbon::setTestNow());

it('knows whether a month has closed in IST', function (): void {
    expect(OpenMonthGuard::isOpen(Carbon::parse('2026-09-01')))->toBeTrue()
        ->and(OpenMonthGuard::refusal(Carbon::parse('2026-09-01')))->toContain('September 2026 has not closed yet')
        ->and(OpenMonthGuard::isOpen(Carbon::parse('2026-08-01')))->toBeFalse()
        ->and(OpenMonthGuard::refusal(Carbon::parse('2026-08-01')))->toBeNull();

    Carbon::setTestNow('2026-09-30 23:59:59');
    expect(OpenMonthGuard::isOpen(Carbon::parse('2026-09-01')))->toBeTrue();

    Carbon::setTestNow('2026-10-01 00:00:00');
    expect(OpenMonthGuard::isOpen(Carbon::parse('2026-09-01')))->toBeFalse();
});

it('refuses every freezing engine for the month still in flight, on the command line', function (string $signature): void {
    // A run on the 20th would freeze the month's pool on twenty days of BV
    // and credit from it; the 1st-of-month run keeps a credited pool. This is
    // the admin console's closed-period rule applied to the CLI.
    $exit = Artisan::call($signature, ['--month' => '2026-09']);

    expect($exit)->toBe(Command::FAILURE)
        ->and(Artisan::output())->toContain('September 2026 has not closed yet');
})->with([
    'rank:monthly-run',
    'gbb:monthly-run',
    'fortune:enroll-eligible',
    'fortune:monthly-run',
    'adc:monthly-run',
    'offers:monthly-run',
    'compensation:monthly-close',
    'compensation:monthly-payout-close',
]);

it('lets a closed month through and lets --in-flight override the refusal', function (): void {
    // August has closed: the guard is silent (the run may still stop on its
    // own prerequisites, which is not this guard's message).
    Artisan::call('adc:monthly-run', ['--month' => '2026-08']);
    expect(Artisan::output())->not->toContain('has not closed yet');

    // The override names its purpose: a deliberate, provisional freeze.
    Artisan::call('adc:monthly-run', ['--month' => '2026-09', '--in-flight' => true]);
    expect(Artisan::output())->not->toContain('has not closed yet');
});

it('builds the override only for a freezing command on an open month', function (): void {
    expect(OpenMonthGuard::overrideFor('gbb:monthly-run', Carbon::parse('2026-09-01')))->toBe(['--in-flight' => true])
        ->and(OpenMonthGuard::overrideFor('gbb:monthly-run', Carbon::parse('2026-08-01')))->toBe([])
        ->and(OpenMonthGuard::overrideFor('rank:check-qualifications', Carbon::parse('2026-09-01')))->toBe([]);
});
