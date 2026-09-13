<?php

declare(strict_types=1);

/**
 * Admin needs to see, per order, how much of it was paid out of the
 * distributor's repurchase wallet (applied automatically at checkout) rather
 * than the payment gateway — on both the orders list and the order detail
 * page. An order with no wallet usage must show a hyphen / no line, never a
 * blank that could be misread as ₹0 applied vs "not applicable".
 */

use App\Modules\Commerce\Models\Customer;
use App\Modules\Commerce\Models\Order;
use App\Modules\Compensation\Models\WalletLedgerEntry;
use App\Modules\Identity\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

function aorwAdmin(): User
{
    Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
    $admin = User::create([
        'full_name' => 'Repurchase Wallet Admin',
        'email' => 'aorw-admin-'.uniqid().'@test.com',
        'phone_e164' => '+91'.str_pad((string) random_int(7000000000, 9999999999), 10, '0'),
        'password_hash' => bcrypt('x'),
        'password_set_at' => now(),
        'status' => 'active',
        'email_verified_at' => now(),
    ]);
    $admin->assignRole('admin');

    return $admin;
}

/** @return array{0: int, 1: int} [userId, distributorId] */
function aorwDistributor(): array
{
    $user = User::create([
        'full_name' => 'AORW Dist',
        'email' => 'aorw-'.uniqid().'@test.com',
        'phone_e164' => '+91'.str_pad((string) random_int(7000000000, 9999999999), 10, '0'),
        'password_hash' => bcrypt('x'),
        'status' => 'active',
    ]);

    disableTestForeignKeys();
    try {
        $id = DB::table('distributors')->insertGetId([
            'user_id' => $user->id,
            'adn' => 'ADN'.random_int(10000, 99999),
            'pan_hash' => random_bytes(32),
            'pan_last4' => '0000',
            'bank_account_enc' => 'stub',
            'bank_ifsc' => 'SBIN0000000',
            'sponsor_id' => 0,
            'placement_parent_id' => 0,
            'side_chosen_by' => 'referral_default',
            'depth' => 0,
            'effective_date' => now()->format('Y-m-d H:i:s.v'),
            'cooling_off_end_at' => now()->copy()->addDays(30)->format('Y-m-d H:i:s.v'),
            'state' => 'TS',
            'is_primary_couple' => 0,
            'created_at' => now()->format('Y-m-d H:i:s.v'),
            'updated_at' => now()->format('Y-m-d H:i:s.v'),
        ]);
        DB::table('distributors')->where('id', $id)->update(['sponsor_id' => $id, 'placement_parent_id' => $id]);
    } finally {
        enableTestForeignKeys();
    }

    return [$user->id, $id];
}

function aorwOrder(): Order
{
    $customer = Customer::create([
        'display_name' => 'AORW Customer',
        'email_hash' => hash('sha256', 'aorw-'.uniqid()),
        'email_enc' => 'aorw-'.uniqid().'@test.com',
    ]);

    $id = DB::table('orders')->insertGetId([
        'order_no' => 'ORD-AORW-'.random_int(10000, 99999),
        'customer_id' => $customer->id,
        'payment_method' => 'online',
        'status' => Order::STATUS_PAID,
        'self_consumption' => false,
        'subtotal_paise' => 118000,
        'gst_paise' => 18000,
        'discount_paise' => 0,
        'shipping_paise' => 0,
        'total_paise' => 18000,
        'ship_name' => 'AORW', 'ship_phone_e164' => '+919000000000',
        'ship_line1' => '1 St', 'ship_city' => 'Hyd', 'ship_state' => 'TS', 'ship_pincode' => '500001',
        'placed_at' => now(), 'paid_at' => now(),
        'idempotency_key' => 'test-aorw-'.uniqid(),
        'created_at' => now(), 'updated_at' => now(),
    ]);

    return Order::findOrFail($id);
}

it('shows the repurchase wallet amount on the orders list when it was used', function (): void {
    [, $distributorId] = aorwDistributor();
    $order = aorwOrder();

    WalletLedgerEntry::create([
        'distributor_id' => $distributorId,
        'type' => 'repurchase_wallet_used',
        'amount_paise' => -100000,
        'reference_id' => $order->id,
        'reference_type' => 'order',
        'memo' => 'Applied at checkout',
    ]);

    $this->actingAs(aorwAdmin())->get(route('admin.commerce.orders.index'))
        ->assertOk()
        ->assertSee('1,000.00');
});

it('shows a hyphen on the orders list when the repurchase wallet was not used', function (): void {
    aorwOrder();

    $response = $this->actingAs(aorwAdmin())->get(route('admin.commerce.orders.index'))
        ->assertOk();

    $response->assertSee('—');
    $response->assertDontSee('Repurchase wallet used');
});

it('shows the repurchase wallet line on the order detail page when it was used', function (): void {
    [, $distributorId] = aorwDistributor();
    $order = aorwOrder();

    WalletLedgerEntry::create([
        'distributor_id' => $distributorId,
        'type' => 'repurchase_wallet_used',
        'amount_paise' => -100000,
        'reference_id' => $order->id,
        'reference_type' => 'order',
        'memo' => 'Applied at checkout',
    ]);

    $this->actingAs(aorwAdmin())->get(route('admin.commerce.orders.show', $order))
        ->assertOk()
        ->assertSee('Repurchase wallet used')
        ->assertSee('1,000.00');
});

it('omits the repurchase wallet line on the order detail page when it was not used', function (): void {
    $order = aorwOrder();

    $this->actingAs(aorwAdmin())->get(route('admin.commerce.orders.show', $order))
        ->assertOk()
        ->assertDontSee('Repurchase wallet used');
});
