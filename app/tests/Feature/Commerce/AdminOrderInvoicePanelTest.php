<?php

declare(strict_types=1);

/**
 * F101: the admin order detail must surface the order's tax invoice and its
 * gateway payment. Before this, neither was reachable from any admin page —
 * the invoice route existed but nothing linked to it.
 */

use App\Modules\Commerce\Models\Customer;
use App\Modules\Commerce\Models\Order;
use App\Modules\Identity\Models\User;
use App\Modules\Payments\Models\PaymentIntent;
use App\Modules\Tax\Models\Invoice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

function aoiAdmin(): User
{
    Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
    $admin = User::create([
        'full_name' => 'Invoice Admin',
        'email' => 'aoi-admin-'.uniqid().'@test.com',
        'phone_e164' => '+91'.str_pad((string) rand(7000000000, 9999999999), 10, '0'),
        'password_hash' => bcrypt('x'),
        'password_set_at' => now(),
        'status' => 'active',
        'email_verified_at' => now(),
    ]);
    $admin->assignRole('admin');

    return $admin;
}

function aoiPaidOrder(): Order
{
    $customer = Customer::create([
        'display_name' => 'AOI Customer',
        'email_hash' => hash('sha256', 'aoi-'.uniqid()),
        'email_enc' => 'aoi-'.uniqid().'@test.com',
    ]);

    $id = DB::table('orders')->insertGetId([
        'order_no' => 'ORD-AOI-'.rand(10000, 99999),
        'customer_id' => $customer->id,
        'payment_method' => 'online',
        'status' => Order::STATUS_PAID,
        'self_consumption' => false,
        'subtotal_paise' => 118000,
        'gst_paise' => 18000,
        'discount_paise' => 0,
        'shipping_paise' => 0,
        'total_paise' => 118000,
        'ship_name' => 'AOI', 'ship_phone_e164' => '+919000000000',
        'ship_line1' => '1 St', 'ship_city' => 'Hyd', 'ship_state' => 'TS', 'ship_pincode' => '500001',
        'placed_at' => now(), 'paid_at' => now(),
        'idempotency_key' => 'test-aoi-'.uniqid(),
        'created_at' => now(), 'updated_at' => now(),
    ]);

    return Order::findOrFail($id);
}

it('F101-01: links the payment intent and offers the invoice control', function (): void {
    $order = aoiPaidOrder();
    $intent = PaymentIntent::create([
        'order_id' => $order->id, 'gateway' => 'stub', 'amount_paise' => 118000,
        'status' => 'captured', 'idempotency_key' => 'aoi-'.uniqid(),
    ]);

    $this->actingAs(aoiAdmin())->get(route('admin.commerce.orders.show', $order))
        ->assertOk()
        ->assertSee(route('admin.payments.show', $intent))
        ->assertSee('No invoice issued yet')
        ->assertSee(route('admin.payments.invoices.generate', $order))
        ->assertSee('Generate invoice');
});

it('F101-02: shows the issued invoice number and offers a re-issue', function (): void {
    $order = aoiPaidOrder();
    Invoice::create([
        'order_id' => $order->id, 'invoice_no' => 'INV-AOI-0001', 'issued_at' => now(),
        'seller_state' => 'TS', 'buyer_state' => 'TS', 'place_of_supply' => 'Telangana',
        'subtotal_paise' => 100000, 'cgst_paise' => 0, 'sgst_paise' => 0,
        'igst_paise' => 18000, 'cess_paise' => 0, 'total_paise' => 118000,
    ]);

    $this->actingAs(aoiAdmin())->get(route('admin.commerce.orders.show', $order))
        ->assertOk()
        ->assertSee('INV-AOI-0001')
        ->assertSee('Re-issue invoice')
        ->assertSee('No gateway payment recorded');
});
