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
    // 18 August 2026 pays the week that closed on Tuesday 11 August (the
    // client's own example). Admins reconciling a batch have to be able to see
    // which week it covers without recomputing the offset by hand.
    PayoutBatch::create([
        'batch_type' => PayoutBatch::TYPE_WEEKLY,
        'batch_date' => '2026-08-18',
        'status' => PayoutBatch::STATUS_PENDING,
    ]);

    $this->actingAs(smokeAdmin())
        ->get(route('admin.compensation.weekly-payouts.index'))
        ->assertOk()
        ->assertSee('Earnings through')
        ->assertSee('18 Aug 2026')
        ->assertSee('11 Aug 2026')
        // Never the statutory term: cooling-off is the 30-day cancellation window.
        ->assertDontSee('cooling-off');
});
