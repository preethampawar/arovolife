<?php

declare(strict_types=1);

use App\Modules\Compensation\Models\PayoutBatch;
use App\Modules\Compensation\Models\PayoutLineItem;
use App\Modules\Identity\Models\Distributor;
use App\Modules\Identity\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    disableTestForeignKeys();
    $this->seed(RolesAndPermissionsSeeder::class);
});

function smokeAdmin(): User
{
    $user = User::factory()->create(['status' => 'active']);
    $user->assignRole('developer');

    return $user;
}

it('renders the payout settings page', function (): void {
    $this->actingAs(smokeAdmin())
        ->get(route('admin.compensation.payout-settings.index'))
        ->assertOk()
        ->assertSee('Payout configuration')
        ->assertSee('RAZORPAYX_KEY_ID');
});

it('renders a payout batch page with a dispatched line item', function (): void {
    $batch = PayoutBatch::create([
        'batch_type' => PayoutBatch::TYPE_WEEKLY,
        'batch_date' => now()->toDateString(),
        'status' => PayoutBatch::STATUS_DISPATCHED,
    ]);
    $dist = Distributor::factory()->create();
    PayoutLineItem::create([
        'payout_batch_id' => $batch->id,
        'distributor_id' => $dist->id,
        'wallet_balance_paise' => 100000,
        'gross_paise' => 100000,
        'repurchase_deduction_paise' => 0,
        'admin_charge_paise' => 0,
        'tds_paise' => 0,
        'net_transferred_paise' => 100000,
        'status' => PayoutLineItem::STATUS_FAILED,
        'failure_reason' => 'Invalid IFSC code',
        'transfer_mode' => 'neft',
        'retry_count' => 1,
    ]);

    $this->actingAs(smokeAdmin())
        ->get(route('admin.compensation.weekly-payouts.show', $batch))
        ->assertOk()
        ->assertSee('Invalid IFSC code')
        ->assertSee('Dispatched');
});

it('renders the payout operations help document', function (): void {
    $this->actingAs(smokeAdmin())
        ->get(route('admin.help.show', 'payout-operations'))
        ->assertOk()
        ->assertSee('Payout Operations');
});

it('shows the earning week each weekly batch pays for on the batch list', function (): void {
    // A batch dated Tuesday 22 September pays the week that closed on Tuesday
    // 15 September (the client's own offset). Admins reconciling a batch have
    // to be able to see which week it covers without recomputing it by hand —
    // and the column reads the date the batch recorded for itself.
    PayoutBatch::create([
        'batch_type' => PayoutBatch::TYPE_WEEKLY,
        'batch_date' => '2026-09-22',
        'earnings_through' => '2026-09-15',
        'status' => PayoutBatch::STATUS_PENDING,
    ]);

    $this->actingAs(smokeAdmin())
        ->get(route('admin.compensation.weekly-payouts.index'))
        ->assertOk()
        ->assertSee('Earnings through')
        ->assertSee('22 Sep 2026')
        ->assertSee('15 Sep 2026')
        // Never the statutory term: cooling-off is the 30-day cancellation window.
        ->assertDontSee('cooling-off');
});

it('leaves Earnings through blank for batches that recorded no earning week', function (): void {
    // Legacy `gsb_weekly` batches, and `weekly` batches written before the
    // column existed, settled the wallet balance as it stood on the batch date.
    // They carry no earning week, and the report must not invent one from the
    // batch date — that would misstate what a distributor was actually paid for.
    PayoutBatch::create([
        'batch_type' => PayoutBatch::TYPE_WEEKLY,
        'batch_date' => '2026-08-18',
        'status' => PayoutBatch::STATUS_COMPLETED,
    ]);
    PayoutBatch::create([
        'batch_type' => PayoutBatch::TYPE_GSB_WEEKLY,
        'batch_date' => '2026-09-29',
        'status' => PayoutBatch::STATUS_COMPLETED,
    ]);

    $this->actingAs(smokeAdmin())
        ->get(route('admin.compensation.weekly-payouts.index'))
        ->assertOk()
        ->assertSee('18 Aug 2026')
        ->assertSee('29 Sep 2026')
        // Neither batch's window is printed: 11 Aug and 22 Sep must not appear.
        ->assertDontSee('11 Aug 2026')
        ->assertDontSee('22 Sep 2026');
});

it('reads the earning window off the batch and never re-derives it', function (): void {
    $stamped = new PayoutBatch([
        'batch_type' => PayoutBatch::TYPE_WEEKLY,
        'batch_date' => '2026-09-22',
        'earnings_through' => '2026-09-15',
    ]);
    $unstamped = new PayoutBatch(['batch_type' => PayoutBatch::TYPE_WEEKLY, 'batch_date' => '2026-09-22']);
    $legacy = new PayoutBatch(['batch_type' => PayoutBatch::TYPE_GSB_WEEKLY, 'batch_date' => '2026-09-22']);

    expect($stamped->weeklyEarningThrough()?->toDateString())->toBe('2026-09-15')
        ->and($unstamped->weeklyEarningThrough())->toBeNull()
        ->and($legacy->weeklyEarningThrough())->toBeNull();
});
