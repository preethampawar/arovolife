<?php

declare(strict_types=1);

use App\Modules\Commerce\Models\OrderCoolingOff;

/**
 * The 30-day cooling-off counter must count the day the buyer is on: a window
 * opened today and closing in 30 days reads "30 days remaining", not 29 (QA F59).
 */
function codWindow(string $status, int $hoursToClose): OrderCoolingOff
{
    $window = new OrderCoolingOff;
    $window->status = $status;
    $window->ends_at = now()->addHours($hoursToClose);

    return $window;
}

it('F59-04: reads 30 on the day a 30-day window opens', function (): void {
    // Opened a few hours ago, closing in 30 days minus those hours.
    expect(codWindow(OrderCoolingOff::STATUS_OPEN, 30 * 24 - 7)->daysRemaining())->toBe(30);
});

it('F59-05: reads 1 on the final day and 0 once the window has passed', function (): void {
    expect(codWindow(OrderCoolingOff::STATUS_OPEN, 5)->daysRemaining())->toBe(1)
        ->and(codWindow(OrderCoolingOff::STATUS_OPEN, -5)->daysRemaining())->toBe(0);
});

it('F59-06: reads 0 for a window that is no longer open', function (): void {
    expect(codWindow(OrderCoolingOff::STATUS_CANCELLED, 30 * 24)->daysRemaining())->toBe(0)
        ->and(codWindow(OrderCoolingOff::STATUS_EXPIRED, 30 * 24)->daysRemaining())->toBe(0);
});
