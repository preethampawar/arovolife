<?php

declare(strict_types=1);

use App\Modules\Commerce\Models\BvLedgerEntry;
use App\Modules\Commerce\Models\Customer;
use App\Modules\Commerce\Models\Order;
use App\Modules\Commerce\Models\OrderItem;
use App\Modules\Commerce\Services\BvLedgerService;
use App\Modules\Commerce\Services\OrderStateMachine;
use App\Modules\Identity\Models\Distributor;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Services\DistributorIdCardStats;
use Database\Seeders\LedgerAccountSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(LedgerAccountSeeder::class);
    DB::table('settings')->updateOrInsert(['key' => 'commerce.self_purchase.earns_bv'], ['value' => 'true', 'version' => 1, 'updated_at' => now()]);
});

function blDistributorId(): int
{
    $user = User::create([
        'full_name' => 'BL Dist', 'email' => 'bl-'.uniqid().'@test.com',
        'phone_e164' => '+91'.str_pad((string) rand(7000000000, 9999999999), 10, '0'),
        'password_hash' => bcrypt('x'), 'status' => 'active',
    ]);
    disableTestForeignKeys();
    try {
        $id = DB::table('distributors')->insertGetId([
            'user_id' => $user->id, 'adn' => 'ADN'.random_int(10000, 99999),
            'pan_hash' => random_bytes(32), 'pan_last4' => '0000',
            'bank_account_enc' => 'stub', 'bank_ifsc' => 'SBIN0000000',
            'sponsor_id' => 0, 'placement_parent_id' => 0, 'side_chosen_by' => 'referral_default', 'depth' => 0,
            'effective_date' => now()->format('Y-m-d H:i:s.v'),
            'cooling_off_end_at' => now()->copy()->addDays(30)->format('Y-m-d H:i:s.v'),
            'state' => 'TS', 'is_primary_couple' => 0,
            'created_at' => now()->format('Y-m-d H:i:s.v'), 'updated_at' => now()->format('Y-m-d H:i:s.v'),
        ]);
        DB::table('distributors')->where('id', $id)->update(['sponsor_id' => $id, 'placement_parent_id' => $id]);
    } finally {
        enableTestForeignKeys();
    }

    return $id;
}

/** A just-PLACED (unpaid) self-consumption online order with one BV line. */
function blOrder(int $distributorId, int $bvPaise = 50000, bool $selfConsumption = true): Order
{
    $customer = Customer::create(['display_name' => 'BL Buyer', 'distributor_id' => $distributorId]);
    $order = Order::create([
        'order_no' => 'ORD-BL-'.random_int(1000, 9999),
        'customer_id' => $customer->id,
        'attributed_distributor_id' => $distributorId,
        'attribution_source' => 'logged_in',
        'payment_method' => Order::PAYMENT_ONLINE,
        'status' => Order::STATUS_PLACED,
        'self_consumption' => $selfConsumption,
        'subtotal_paise' => 100000, 'gst_paise' => 15254, 'discount_paise' => 0,
        'shipping_paise' => 0, 'total_paise' => 100000,
        'ship_name' => 'BL Buyer', 'ship_phone_e164' => '+919800000000',
        'ship_line1' => '1 St', 'ship_city' => 'Pune', 'ship_state' => 'MH', 'ship_pincode' => '411001',
        'placed_at' => now(), 'idempotency_key' => 'idem-'.uniqid(),
    ]);
    disableTestForeignKeys();
    try {
        OrderItem::create([
            'order_id' => $order->id, 'product_variant_id' => 1,
            'product_name_snapshot' => 'Item', 'variant_sku_snapshot' => 'I-1', 'hsn_code_snapshot' => '3004',
            'qty' => 1, 'unit_price_paise' => 100000, 'bv_paise' => $bvPaise, 'gst_rate_bp' => 1800,
            'taxable_value_paise' => 84746, 'gst_paise' => 15254, 'line_total_paise' => 100000,
        ]);
    } finally {
        enableTestForeignKeys();
    }

    return $order->load('items');
}

it('does NOT accrue BV before payment is received', function (): void {
    $distId = blDistributorId();
    blOrder($distId); // placed, unpaid

    expect(BvLedgerEntry::where('distributor_id', $distId)->count())->toBe(0);
    expect(app(BvLedgerService::class)->totalPersonalBvPaise($distId))->toBe(0);
});

it('accrues personal BV exactly once as soon as payment is received', function (): void {
    $distId = blDistributorId();
    $order = blOrder($distId, 50000);

    app(OrderStateMachine::class)->markPaid($order);

    expect($order->fresh()->status)->toBe(Order::STATUS_PAID);
    $entries = BvLedgerEntry::where('distributor_id', $distId)->where('type', 'accrual')->get();
    expect($entries)->toHaveCount(1);
    expect($entries->first()->bv_paise)->toBe(50000);
    expect(app(BvLedgerService::class)->totalPersonalBvPaise($distId))->toBe(50000);
});

it('writes an audit-log row when BV is accrued', function (): void {
    $distId = blDistributorId();
    $order = blOrder($distId, 50000);

    app(OrderStateMachine::class)->markPaid($order);

    expect(DB::table('audit_log')
        ->where('action', 'bv.accrued')
        ->where('subject_type', 'bv_ledger_entry')
        ->count())->toBe(1);
});

it('is idempotent — re-running accrual does not double-count', function (): void {
    $distId = blDistributorId();
    $order = blOrder($distId, 50000);

    app(OrderStateMachine::class)->markPaid($order);
    app(BvLedgerService::class)->accrue($order->fresh()->load('items')); // second call

    expect(BvLedgerEntry::where('order_id', $order->id)->where('type', 'accrual')->count())->toBe(1);
    expect(app(BvLedgerService::class)->totalPersonalBvPaise($distId))->toBe(50000);
});

it('accrues BV for an attributed customer sale (self_consumption=false)', function (): void {
    // A customer places an order via the distributor's Easy Purchase / shared-cart
    // link — self_consumption is false but attributed_distributor_id is set.
    // BV must still accrue so the distributor is credited (hard rule #2: BV tied
    // to a product sale, not just self-purchase).
    $distId = blDistributorId();
    $order = blOrder($distId, 50000, selfConsumption: false);

    app(OrderStateMachine::class)->markPaid($order);

    expect(BvLedgerEntry::where('distributor_id', $distId)->where('type', 'accrual')->count())->toBe(1);
    expect(app(BvLedgerService::class)->totalPersonalBvPaise($distId))->toBe(50000);
});

it('does NOT accrue when there is no attributed distributor (direct/unattributed order)', function (): void {
    // An order with no attribution (customer landed directly, no ?ref= cookie)
    // should never create a BV entry.
    $distId = blDistributorId();
    $customer = Customer::create(['display_name' => 'Direct Buyer', 'distributor_id' => null]);
    $order = Order::create([
        'order_no' => 'ORD-DIRECT-'.random_int(1000, 9999),
        'customer_id' => $customer->id,
        'attributed_distributor_id' => null, // no attribution
        'attribution_source' => 'direct',
        'payment_method' => Order::PAYMENT_ONLINE,
        'status' => Order::STATUS_PLACED,
        'self_consumption' => false,
        'subtotal_paise' => 100000, 'gst_paise' => 15254, 'discount_paise' => 0,
        'shipping_paise' => 0, 'total_paise' => 100000,
        'ship_name' => 'Direct', 'ship_phone_e164' => '+919800000001',
        'ship_line1' => '2 St', 'ship_city' => 'Pune', 'ship_state' => 'MH', 'ship_pincode' => '411001',
        'placed_at' => now(), 'idempotency_key' => 'idem-direct-'.uniqid(),
    ]);
    disableTestForeignKeys();
    try {
        OrderItem::create([
            'order_id' => $order->id, 'product_variant_id' => 1,
            'product_name_snapshot' => 'Item', 'variant_sku_snapshot' => 'I-1', 'hsn_code_snapshot' => '3004',
            'qty' => 1, 'unit_price_paise' => 100000, 'bv_paise' => 50000, 'gst_rate_bp' => 1800,
            'taxable_value_paise' => 84746, 'gst_paise' => 15254, 'line_total_paise' => 100000,
        ]);
    } finally {
        enableTestForeignKeys();
    }

    app(OrderStateMachine::class)->markPaid($order->load('items'));

    expect(BvLedgerEntry::where('order_id', $order->id)->count())->toBe(0);
});

it('does NOT accrue when self-purchase BV is disabled', function (): void {
    DB::table('settings')->updateOrInsert(['key' => 'commerce.self_purchase.earns_bv'], ['value' => 'false', 'version' => 2, 'updated_at' => now()]);
    $distId = blDistributorId();
    $order = blOrder($distId, 50000);

    app(OrderStateMachine::class)->markPaid($order);

    expect(BvLedgerEntry::where('distributor_id', $distId)->count())->toBe(0);
});

it('reverses an accrued order back to zero and is idempotent', function (): void {
    $distId = blDistributorId();
    $order = blOrder($distId, 50000);
    app(OrderStateMachine::class)->markPaid($order);
    expect(app(BvLedgerService::class)->totalPersonalBvPaise($distId))->toBe(50000);

    app(BvLedgerService::class)->reverse($order->fresh());
    app(BvLedgerService::class)->reverse($order->fresh()); // idempotent

    expect(app(BvLedgerService::class)->totalPersonalBvPaise($distId))->toBe(0);
    expect(BvLedgerEntry::where('order_id', $order->id)->where('type', 'reversal')->count())->toBe(1);
});

it('reverse is a no-op when nothing was ever accrued (unpaid order)', function (): void {
    $distId = blDistributorId();
    $order = blOrder($distId, 50000); // never paid → never accrued

    app(BvLedgerService::class)->reverse($order);

    expect(BvLedgerEntry::where('order_id', $order->id)->count())->toBe(0);
});

it('surfaces the owner\'s accumulated BV on their stats, formatted as "N BV"', function (): void {
    $distId = blDistributorId();
    $order = blOrder($distId, 50000);
    app(OrderStateMachine::class)->markPaid($order);
    $dist = Distributor::find($distId);

    $this->actingAs($dist->user);
    $stats = app(DistributorIdCardStats::class)->compact($dist);

    expect($stats['total_personal_bv'])->toBe('500 BV');
});

it('hides a distributor\'s personal BV from a non-owner viewer (hard rule #3)', function (): void {
    $distId = blDistributorId();
    $order = blOrder($distId, 50000);
    app(OrderStateMachine::class)->markPaid($order);
    $dist = Distributor::find($distId);

    // No authenticated owner → personal BV must not be exposed.
    $stats = app(DistributorIdCardStats::class)->compact($dist);

    expect($stats['total_personal_bv'])->toBeNull();
});
