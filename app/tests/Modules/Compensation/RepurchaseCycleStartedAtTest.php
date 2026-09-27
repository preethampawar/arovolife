<?php

declare(strict_types=1);
use App\Modules\Compensation\Models\RepurchaseCycle;
use App\Modules\Compensation\Services\RepurchaseCycleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

beforeEach(fn () => seedCompensationPlanTables());

it('first cycle starts at the time the qualifying order was paid', function () {
    $d = uiDistributor();
    uiPaidSelfOrder($d['id'], 60000, Carbon::parse('2026-07-07 14:32:00'));
    $cycle = RepurchaseCycle::create([
        'distributor_id' => $d['id'], 'cycle_start_date' => '2026-07-07', 'due_date' => '2026-08-06',
        'required_bv_paise' => 60000, 'completed_bv_paise' => 0, 'status' => RepurchaseCycle::STATUS_ACTIVE,
    ]);
    expect(app(RepurchaseCycleService::class)->cycleStartedAt($cycle)->format('Y-m-d H:i'))->toBe('2026-07-07 14:32');
});

it('a rolled-over cycle starts at midnight', function () {
    $d = uiDistributor();
    uiPaidSelfOrder($d['id'], 60000, Carbon::parse('2026-07-07 14:32:00'));
    $cycle = RepurchaseCycle::create([
        'distributor_id' => $d['id'], 'cycle_start_date' => '2026-08-07', 'due_date' => '2026-09-06',
        'required_bv_paise' => 60000, 'completed_bv_paise' => 0, 'status' => RepurchaseCycle::STATUS_ACTIVE,
    ]);
    expect(app(RepurchaseCycleService::class)->cycleStartedAt($cycle)->format('Y-m-d H:i'))->toBe('2026-08-07 00:00');
});

it('the card line shows start and end with times and the window length', function () {
    $blade = file_get_contents(resource_path('views/dashboard/_repurchase-cycle.blade.php'));
    expect($blade)->toContain("startedAt?->format('j M Y, g:i A')")
        ->toContain("endDate?->format('j M Y')")->toContain('11:59 PM')
        ->not->toContain('last day counts');
});
