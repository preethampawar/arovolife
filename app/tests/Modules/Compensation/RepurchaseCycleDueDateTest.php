<?php

declare(strict_types=1);

use App\Modules\Compensation\Models\RepurchaseCycle;
use App\Modules\Compensation\Services\RepurchaseCycleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

beforeEach(fn () => seedCompensationPlanTables());

it('opens the first cycle with due_date = anchor + 29 days (client example 14 Feb → 15 Mar)', function (): void {
    // Client 2026-10-09: 300 + 200 + 100 BV reach 600 on 14 Feb; the window is
    // 30 days INCLUSIVE of that day, so the last day to fulfil is 15 Mar.
    $d = uiDistributor();
    uiPaidSelfOrder($d['id'], 30_000, Carbon::parse('2026-02-05 10:00:00'));
    uiPaidSelfOrder($d['id'], 20_000, Carbon::parse('2026-02-10 10:00:00'));
    uiPaidSelfOrder($d['id'], 10_000, Carbon::parse('2026-02-14 10:00:00'));

    $cycle = app(RepurchaseCycleService::class)->evaluate($d['id'], Carbon::parse('2026-02-20'));

    expect($cycle)->not->toBeNull()
        ->and($cycle->cycle_start_date->toDateString())->toBe('2026-02-14')
        ->and($cycle->due_date->toDateString())->toBe('2026-03-15');
});

it('a cycle anchored on 7 Jul also spans 30 calendar days inclusive', function (): void {
    $d = uiDistributor();
    uiPaidSelfOrder($d['id'], 60_000, Carbon::parse('2026-07-07 10:00:00'));
    app(RepurchaseCycleService::class)->evaluate($d['id'], Carbon::parse('2026-07-08'));

    $first = RepurchaseCycle::where('distributor_id', $d['id'])->orderBy('id')->firstOrFail();
    expect($first->due_date->toDateString())->toBe('2026-08-05');
});

it('the dashboard card reports a 30-day window for a 30-day cycle', function (): void {
    // Review fix: the blade used to print daysTotal − 1 (right under the old
    // start + 30 rule, "29-day window" under the inclusive one).
    $d = uiDistributor();
    uiPaidSelfOrder($d['id'], 60_000, Carbon::parse('2026-02-14 10:00:00'));
    $service = app(RepurchaseCycleService::class);
    $service->evaluate($d['id'], Carbon::parse('2026-02-20'));

    $card = $service->cardFor($d['id'], Carbon::parse('2026-02-20'));
    expect($card->daysTotal)->toBe(30);

    $blade = (string) file_get_contents(resource_path('views/dashboard/_repurchase-cycle.blade.php'));
    expect($blade)->toContain('{{ $card->daysTotal }}-day window');
    expect(str_contains($blade, 'daysTotal - 1'))->toBeFalse();
});

it('takes the verdict on the day AFTER the due date, never on it (F-1c)', function (): void {
    // The 00:05 run dated 15 Mar still sees an open window; the run dated
    // 16 Mar is the first that may judge the 14 Feb → 15 Mar cycle.
    $d = uiDistributor();
    uiPaidSelfOrder($d['id'], 60_000, Carbon::parse('2026-02-14 10:00:00'));
    $service = app(RepurchaseCycleService::class);

    $onDue = $service->evaluate($d['id'], Carbon::parse('2026-03-15'));

    expect($onDue)->not->toBeNull()
        ->and($onDue->due_date->toDateString())->toBe('2026-03-15')
        ->and($onDue->status)->toBe(RepurchaseCycle::STATUS_ACTIVE)
        ->and($onDue->resolved_at)->toBeNull();

    $service->evaluate($d['id'], Carbon::parse('2026-03-16'));

    $judged = RepurchaseCycle::findOrFail($onDue->id);
    expect($judged->resolved_at)->not->toBeNull()
        ->and($judged->status)->not->toBe(RepurchaseCycle::STATUS_ACTIVE);
});
