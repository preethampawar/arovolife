<?php

declare(strict_types=1);

use App\Modules\Identity\Models\User;

/**
 * The same account status must read identically on every surface (distributor
 * dashboard, admin, reports). Regression guard for the partner feedback that
 * 'frozen' showed up as "Suspended" / "Frozen" / "Blocked" in three places.
 * Canonical: a frozen account is "Blocked" everywhere.
 */
function statusUser(string $status, ?string $closureType = null): User
{
    $u = new User;
    $u->status = $status;
    $u->closure_type = $closureType;

    return $u;
}

it('exposes a single canonical status-label map', function (): void {
    expect(User::STATUS_LABELS)->toBe([
        'pending' => 'Pending',
        'active' => 'Active',
        'frozen' => 'Blocked',
        'terminated' => 'Terminated',
        'rejected' => 'Rejected',
    ]);
});

it('renders a frozen account as "Blocked" on every label surface', function (): void {
    $u = statusUser('frozen');

    expect($u->statusLabel())->toBe('Blocked');
    expect($u->statusTheme()['card_label'])->toBe('Blocked');
    expect($u->statusTheme()['pill_label'])->toBe('Blocked');
    expect($u->accountStatusLabel()['label'])->toBe('Blocked');

    $legendLabels = collect(User::treeLegend())->pluck('label');
    expect($legendLabels)->toContain('Blocked');
    expect($legendLabels)->not->toContain('Suspended');
});

it('keeps active and pending canonical labels', function (): void {
    expect(statusUser('active')->statusLabel())->toBe('Active');
    expect(statusUser('pending')->statusLabel())->toBe('Pending');
});

it('splits terminated into Cancelled (cooling-off) vs Terminated', function (): void {
    expect(statusUser('terminated')->statusLabel())->toBe('Terminated');
    expect(statusUser('terminated', 'cooling_off_cancellation')->statusLabel())->toBe('Cancelled');
});
