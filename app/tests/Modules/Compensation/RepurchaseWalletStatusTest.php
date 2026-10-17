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

it('follows the distributor own cycle end', function () {
    // Anchored on the 21st of August: the window closes on 19 September, and
    // on the 5th they have 15 days.
    $status = RepurchaseWalletStatus::for(50_000, Carbon::parse('2026-09-05'), Carbon::parse('2026-09-19'));

    expect($status->tone)->toBe('amber')
        ->and($status->deadline?->toDateString())->toBe('2026-09-19')
        ->and($status->detail())->toBe('Bring this to ₹0 by 19 Sep — 15 days left');
});

it('counts down past the month end to the cycle end — there is no month-end wallet date', function () {
    // User decision 2026-10-17: the wallet is judged only on the cycle's last
    // day, so a window closing on 8 October counts down to 8 October even on
    // 25 September.
    $status = RepurchaseWalletStatus::for(50_000, Carbon::parse('2026-09-25'), Carbon::parse('2026-10-08'));

    expect($status->tone)->toBe('amber')
        ->and($status->deadline?->toDateString())->toBe('2026-10-08')
        ->and($status->overdue())->toBeFalse()
        ->and($status->detail())->toBe('Bring this to ₹0 by 08 Oct — 14 days left');
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

it('shows no countdown for a distributor with no cycle', function () {
    $status = RepurchaseWalletStatus::for(50_000, Carbon::parse('2026-09-05'));

    expect($status->tone)->toBe('green')
        ->and($status->deadline)->toBeNull()
        ->and($status->overdue())->toBeFalse()
        ->and($status->detail())->toBe('Spend this at checkout on your own purchases.');
});

it('shows the cleared state whatever the day once the balance is zero', function () {
    $status = RepurchaseWalletStatus::for(0, Carbon::parse('2026-09-25'));

    expect($status->tone)->toBe('cleared')
        ->and($status->label())->toBe('Cleared — ₹0')
        ->and($status->detail())->toBe('Nothing to clear this cycle.')
        ->and($status->pillClasses())->toContain('bg-gray-100');
});
