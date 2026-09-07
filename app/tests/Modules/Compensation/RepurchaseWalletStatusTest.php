<?php

declare(strict_types=1);

use App\Modules\Compensation\Support\RepurchaseWalletStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

it('escalates the tone by days left in the cycle while the wallet holds a balance', function (string $today, string $tone) {
    // Cycle due 2026-09-30, so "days left" counts down to that date.
    expect(RepurchaseWalletStatus::for(50_000, Carbon::parse($today), Carbon::parse('2026-09-30'))->tone)
        ->toBe($tone);
})->with([
    ['2026-09-01', 'green'],  // 30 left
    ['2026-09-10', 'green'],  // 21 left
    ['2026-09-11', 'amber'],  // 20 left
    ['2026-09-20', 'amber'],  // 11 left
    ['2026-09-21', 'red'],    // 10 left
    ['2026-09-30', 'red'],    // 1 left
]);

it('follows the distributor own cycle end, not the calendar month end', function () {
    // Anchored on the 10th: the deadline is the 8th of the next month, and on
    // the 25th they are already in the red — the month-end rule would have said
    // amber and named the wrong date.
    $status = RepurchaseWalletStatus::for(50_000, Carbon::parse('2026-09-25'), Carbon::parse('2026-10-08'));

    expect($status->tone)->toBe('amber')
        ->and($status->deadline->toDateString())->toBe('2026-10-08')
        ->and($status->detail())->toBe('Bring this to ₹0 by 08 Oct — 14 days left');
});

it('reports one day left on the last day of the cycle', function () {
    $status = RepurchaseWalletStatus::for(50_000, Carbon::parse('2026-02-28'), Carbon::parse('2026-02-28'));

    expect($status->tone)->toBe('red')
        ->and($status->daysRemaining)->toBe(1)
        ->and($status->detail())->toContain('1 day left');
});

it('never counts below one day once the deadline has passed', function () {
    // The cycle is being resolved; the distributor's action has not changed, so
    // a negative countdown would be noise.
    $status = RepurchaseWalletStatus::for(50_000, Carbon::parse('2026-03-05'), Carbon::parse('2026-02-28'));

    expect($status->tone)->toBe('red')
        ->and($status->daysRemaining)->toBe(1);
});

it('falls back to month end for a distributor with no cycle yet', function () {
    expect(RepurchaseWalletStatus::for(50_000, Carbon::parse('2026-09-05'))->detail())
        ->toBe('Bring this to ₹0 by 30 Sep — 26 days left');
});

it('shows the cleared state whatever the day once the balance is zero', function () {
    $status = RepurchaseWalletStatus::for(0, Carbon::parse('2026-09-25'));

    expect($status->tone)->toBe('cleared')
        ->and($status->label())->toBe('Cleared — ₹0')
        ->and($status->detail())->toBe('Nothing to clear this cycle.')
        ->and($status->pillClasses())->toContain('bg-gray-100');
});
