<?php

declare(strict_types=1);

use App\Modules\Compensation\Models\RepurchaseCycle;
use App\Modules\Compensation\Services\DTOs\RepurchaseCycleCard;
use Illuminate\Support\Carbon;

/**
 * The repurchase card's arithmetic and state mapping.
 *
 * These are the numbers a distributor plans a purchase around — how many days
 * are left, how much BV is still owed — so they are asserted directly on the
 * DTO rather than scraped out of rendered HTML.
 *
 * @see docs/plans/repurchase-cycle-visual-2026-09-13.md
 */
/** @param  array<string, mixed>  $overrides */
function rccCycle(array $overrides = []): RepurchaseCycle
{
    $cycle = new RepurchaseCycle;
    $cycle->forceFill(array_merge([
        'distributor_id' => 1,
        'cycle_start_date' => Carbon::parse('2026-09-01'),
        'due_date' => Carbon::parse('2026-09-30'),
        'required_bv_paise' => 60000,
        'completed_bv_paise' => 0,
        'wallet_balance_paise' => 0,
        'wallet_zeroed' => false,
        'status' => RepurchaseCycle::STATUS_ACTIVE,
        'failure_reason' => null,
    ], $overrides));

    return $cycle;
}

it('RCC-01: an unqualified distributor gets the gate and their progress, not an empty window', function (): void {
    $card = RepurchaseCycleCard::notQualified(personalBvPaise: 30000, qualifyBvPaise: 60000);

    expect($card->qualified())->toBeFalse()
        ->and($card->state)->toBe(RepurchaseCycleCard::STATE_NOT_QUALIFIED)
        ->and($card->startDate)->toBeNull()
        ->and($card->endDate)->toBeNull()
        ->and($card->qualifyFraction())->toBe(0.5)
        ->and($card->qualifyRemainingPaise())->toBe(30000);
});

it('RCC-02: the last day of the window still counts as a day left', function (): void {
    // Standing on the due date, the distributor has the whole of that day to
    // fulfil. Reporting "0 days left" would say the chance had already gone.
    $card = RepurchaseCycleCard::fromCycle(rccCycle(), Carbon::parse('2026-09-30'), 0, 60000);

    expect($card->daysLeft)->toBe(1);
});

it('RCC-03: days left never goes negative once the window has closed', function (): void {
    $card = RepurchaseCycleCard::fromCycle(rccCycle(), Carbon::parse('2026-10-15'), 0, 60000);

    expect($card->daysLeft)->toBe(0)
        ->and($card->ringFraction())->toBe(0.0);
});

it('RCC-04: the window length and the ring fraction describe the same window', function (): void {
    $card = RepurchaseCycleCard::fromCycle(rccCycle(), Carbon::parse('2026-09-01'), 0, 60000);

    // 1 Sep to 30 Sep inclusive is 30 days, and on day one all of it remains.
    expect($card->daysTotal)->toBe(30)
        ->and($card->daysLeft)->toBe(30)
        ->and($card->ringFraction())->toBe(1.0);
});

it('RCC-05: BV progress reports what is still owed', function (): void {
    $card = RepurchaseCycleCard::fromCycle(
        rccCycle(['completed_bv_paise' => 45000]),
        Carbon::parse('2026-09-10'),
        0,
        60000,
    );

    expect($card->bvFraction())->toBe(0.75)
        ->and($card->bvMet())->toBeFalse()
        ->and($card->bvRemainingPaise())->toBe(15000);
});

it('RCC-06: over-purchasing does not push the bar past full or owe a negative amount', function (): void {
    $card = RepurchaseCycleCard::fromCycle(
        rccCycle(['completed_bv_paise' => 90000]),
        Carbon::parse('2026-09-10'),
        0,
        60000,
    );

    expect($card->bvFraction())->toBe(1.0)
        ->and($card->bvMet())->toBeTrue()
        ->and($card->bvRemainingPaise())->toBe(0);
});

it('RCC-07: both engine conditions must hold before the cycle reads as met', function (): void {
    $bvOnly = RepurchaseCycleCard::fromCycle(
        rccCycle(['completed_bv_paise' => 60000, 'wallet_zeroed' => false]),
        Carbon::parse('2026-09-10'),
        0,
        60000,
    );

    $both = RepurchaseCycleCard::fromCycle(
        rccCycle(['completed_bv_paise' => 60000, 'wallet_zeroed' => true]),
        Carbon::parse('2026-09-10'),
        0,
        60000,
    );

    expect($bvOnly->bvMet())->toBeTrue()
        ->and($bvOnly->bothConditionsMet())->toBeFalse()
        ->and($both->bothConditionsMet())->toBeTrue();
});

it('RCC-08: the last week of an open window reads as urgent, a closed one does not', function (): void {
    $urgent = RepurchaseCycleCard::fromCycle(rccCycle(), Carbon::parse('2026-09-26'), 0, 60000);
    $calm = RepurchaseCycleCard::fromCycle(rccCycle(), Carbon::parse('2026-09-10'), 0, 60000);
    $done = RepurchaseCycleCard::fromCycle(
        rccCycle(['status' => RepurchaseCycle::STATUS_COMPLETED]),
        Carbon::parse('2026-09-26'),
        0,
        60000,
    );

    expect($urgent->urgent())->toBeTrue()
        ->and($calm->urgent())->toBeFalse()
        ->and($done->urgent())->toBeFalse();
});

it('RCC-09: each cycle status maps to its own card state', function (): void {
    $today = Carbon::parse('2026-09-10');

    expect(RepurchaseCycleCard::fromCycle(rccCycle(), $today, 0, 60000)->state)
        ->toBe(RepurchaseCycleCard::STATE_ACTIVE)
        ->and(RepurchaseCycleCard::fromCycle(rccCycle(['status' => RepurchaseCycle::STATUS_COMPLETED]), $today, 0, 60000)->state)
        ->toBe(RepurchaseCycleCard::STATE_COMPLETED)
        ->and(RepurchaseCycleCard::fromCycle(rccCycle(['status' => RepurchaseCycle::STATUS_SUSPENDED]), $today, 0, 60000)->state)
        ->toBe(RepurchaseCycleCard::STATE_SUSPENDED);
});

it('RCC-10: a missed window says which condition failed, in plain words', function (): void {
    $today = Carbon::parse('2026-10-05');

    $bvShort = RepurchaseCycleCard::fromCycle(
        rccCycle(['status' => RepurchaseCycle::STATUS_SUSPENDED, 'failure_reason' => RepurchaseCycle::REASON_BV_SHORT]),
        $today, 0, 60000,
    );
    $walletNonZero = RepurchaseCycleCard::fromCycle(
        rccCycle(['status' => RepurchaseCycle::STATUS_SUSPENDED, 'failure_reason' => RepurchaseCycle::REASON_WALLET_NONZERO]),
        $today, 0, 60000,
    );
    $both = RepurchaseCycleCard::fromCycle(
        rccCycle(['status' => RepurchaseCycle::STATUS_SUSPENDED, 'failure_reason' => RepurchaseCycle::REASON_BOTH]),
        $today, 0, 60000,
    );

    expect($bvShort->failureLabel())->toContain('BV')
        ->and($walletNonZero->failureLabel())->toContain('wallet')
        ->and($both->failureLabel())->toContain('and')
        ->and(RepurchaseCycleCard::fromCycle(rccCycle(), $today, 0, 60000)->failureLabel())->toBeNull();
});

it('RCC-11: a zero-length gate never divides by zero', function (): void {
    $card = RepurchaseCycleCard::notQualified(personalBvPaise: 0, qualifyBvPaise: 0);

    expect($card->qualifyFraction())->toBe(1.0)
        ->and($card->qualifyRemainingPaise())->toBe(0);
});

it('RCC-13: the ring reads green early, amber mid-window and red near the end', function (): void {
    $tier = fn (string $today): string => RepurchaseCycleCard::fromCycle(
        rccCycle(),
        Carbon::parse($today),
        0,
        60000,
    )->urgencyTier();

    expect($tier('2026-09-01'))->toBe('ok')
        ->and($tier('2026-09-10'))->toBe('ok')
        ->and($tier('2026-09-11'))->toBe('warn')
        ->and($tier('2026-09-20'))->toBe('warn')
        ->and($tier('2026-09-21'))->toBe('late')
        ->and($tier('2026-09-30'))->toBe('late');
});
