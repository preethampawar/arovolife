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

it('follows the distributor own cycle end when it falls before the month end', function () {
    // Anchored on the 20th of August: the window closes on 19 September, well
    // before the month-end gate, and on the 5th they have 15 days.
    $status = RepurchaseWalletStatus::for(50_000, Carbon::parse('2026-09-05'), Carbon::parse('2026-09-19'));

    expect($status->tone)->toBe('amber')
        ->and($status->deadline->toDateString())->toBe('2026-09-19')
        ->and($status->detail())->toBe('Bring this to ₹0 by 19 Sep — 15 days left');
});

it('counts down to the month-end gate when the cycle runs past it', function () {
    // Anchored on the 8th: the window closes on 8 October, but the monthly
    // bonus engines read the wallet at the last instant of September first —
    // a countdown to 8 October would let September's Growth Booster and
    // Fortune be forfeited while the pill still read "on track".
    $status = RepurchaseWalletStatus::for(50_000, Carbon::parse('2026-09-25'), Carbon::parse('2026-10-08'));

    expect($status->tone)->toBe('red')
        ->and($status->deadline->toDateString())->toBe('2026-09-30')
        ->and($status->overdue())->toBeFalse()
        ->and($status->detail())->toBe('Bring this to ₹0 by 30 Sep — 6 days left');
});

it('reports one day left on the last day of the cycle', function () {
    $status = RepurchaseWalletStatus::for(50_000, Carbon::parse('2026-02-28'), Carbon::parse('2026-02-28'));

    expect($status->tone)->toBe('red')
        ->and($status->daysRemaining)->toBe(1)
        ->and($status->detail())->toContain('1 day left');
});

it('says the window was missed once its last day has passed with a balance', function () {
    // Forfeit model: the cycle failed on 28 Feb and every day since is not
    // counted. "1 day left" on 5 March would be a countdown to a date already
    // behind the distributor.
    $status = RepurchaseWalletStatus::for(50_000, Carbon::parse('2026-03-05'), Carbon::parse('2026-02-28'));

    expect($status->tone)->toBe('red')
        ->and($status->daysRemaining)->toBe(0)
        ->and($status->overdue())->toBeTrue()
        ->and($status->label())->toBe('Clear now')
        ->and($status->detail())->toBe('Your repurchase window closed on 28 Feb with a balance — your Genos BV for each day until this is ₹0 is not counted.');
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
