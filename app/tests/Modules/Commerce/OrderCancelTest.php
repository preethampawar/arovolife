<?php

declare(strict_types=1);

use App\Modules\Catalog\Models\InventoryLevel;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Commerce\Models\BvLedgerEntry;
use App\Modules\Commerce\Models\Cart;
use App\Modules\Commerce\Models\CartItem;
use App\Modules\Commerce\Models\Customer;
use App\Modules\Commerce\Models\Order;
use App\Modules\Commerce\Services\CheckoutService;
use App\Modules\Commerce\Services\OrderStateMachine;
use App\Modules\Compliance\Models\AuditLog;
use App\Modules\Identity\Models\User;
use Database\Seeders\LedgerAccountSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(LedgerAccountSeeder::class);
});

function ocUser(): User
{
    return User::create([
        'full_name' => 'OC User', 'email' => 'oc-'.uniqid().'@test.com',
        'phone_e164' => '+91'.str_pad((string) rand(7000000000, 9999999999), 10, '0'),
        'password_hash' => bcrypt('x'), 'status' => 'active',
    ]);
}

/** @return array{0: Cart, 1: ProductVariant} a tracked-inventory cart */
function ocCart(): array
{
    $n = random_int(10000, 99999);
    $product = Product::create(['sku' => "OC-{$n}", 'slug' => "oc-{$n}", 'name' => "OC {$n}", 'hsn_code' => '3004', 'status' => 'active']);
    $variant = ProductVariant::create(['product_id' => $product->id, 'variant_sku' => "OC-{$n}-V1", 'name' => 'Default', 'mrp_paise' => 100000, 'sale_price_paise' => 100000, 'gst_rate_bp' => 1800, 'inventory_policy' => 'track', 'status' => 'active']);
    InventoryLevel::create(['product_variant_id' => $variant->id, 'warehouse_code' => 'DEFAULT', 'on_hand' => 50, 'reserved' => 0]);
    $cart = Cart::create(['anonymous_key' => "k{$n}", 'expires_at' => now()->addDay()]);
    CartItem::create(['cart_id' => $cart->id, 'product_variant_id' => $variant->id, 'qty' => 2, 'unit_price_paise' => 100000, 'bv_paise' => 0, 'gst_rate_bp' => 1800]);

    return [$cart->load('items.variant.product'), $variant];
}

function ocPlace(User $user): Order
{
    [$cart] = ocCart();

    return app(CheckoutService::class)->place(
        $cart,
        ['name' => 'OC', 'email' => $user->email, 'phone' => '+919800000000', 'marketing_opt_in' => false],
        ['name' => 'OC', 'phone' => '+919800000000', 'line1' => '1 St', 'line2' => null, 'city' => 'Pune', 'state' => 'MH', 'pincode' => '411001'],
        [], null, 'direct', Order::PAYMENT_ONLINE, null, $user->id, null,
    );
}

it('SHIP-01: markShipped persists the courier + tracking number', function (): void {
    $user = ocUser();
    $order = ocPlace($user);
    $sm = app(OrderStateMachine::class);
    $sm->markPaid($order->fresh());
    $sm->markShipped($order->fresh(), null, 'Delhivery', 'TRK-12345');

    $fresh = $order->fresh();
    expect($fresh->status)->toBe('shipped')
        ->and($fresh->ship_carrier)->toBe('Delhivery')
        ->and($fresh->ship_tracking_no)->toBe('TRK-12345');
});

it('CANCEL-01: cancelling a placed order releases the reserved stock', function (): void {
    $user = ocUser();
    $order = ocPlace($user);
    $variant = $order->fresh()->load('items.variant.inventory')->items->first()->variant;
    expect((int) $variant->inventory->reserved)->toBe(2); // reserved at placement

    app(OrderStateMachine::class)->cancel($order->fresh(), 'test');

    expect($order->fresh()->status)->toBe('cancelled')
        ->and((int) $variant->inventory->fresh()->reserved)->toBe(0); // released
});

it('CANCEL-01b: cancelling a PAID self-consumption order reverses its accrued BV (hard rule #2)', function (): void {
    // Enable self-purchase BV and build a self-consumption paid order.
    DB::table('settings')->updateOrInsert(['key' => 'commerce.self_purchase.earns_bv'], ['value' => 'true', 'version' => 1, 'updated_at' => now()]);
    $user = ocUser();
    disableTestForeignKeys();
    try {
        $distId = DB::table('distributors')->insertGetId([
            'user_id' => $user->id, 'adn' => 'ADN'.random_int(10000, 99999),
            'pan_hash' => random_bytes(32), 'pan_last4' => '0000', 'bank_account_enc' => 'stub', 'bank_ifsc' => 'SBIN0000000',
            'sponsor_id' => 0, 'placement_parent_id' => 0, 'side_chosen_by' => 'referral_default', 'depth' => 0,
            'effective_date' => now()->format('Y-m-d H:i:s.v'), 'cooling_off_end_at' => now()->copy()->addDays(30)->format('Y-m-d H:i:s.v'),
            'state' => 'TS', 'is_primary_couple' => 0, 'created_at' => now()->format('Y-m-d H:i:s.v'), 'updated_at' => now()->format('Y-m-d H:i:s.v'),
        ]);
        DB::table('distributors')->where('id', $distId)->update(['sponsor_id' => $distId, 'placement_parent_id' => $distId]);
    } finally {
        enableTestForeignKeys();
    }
    Customer::firstOrCreate(['user_id' => $user->id], ['display_name' => 'OC', 'distributor_id' => $distId]);

    [$cart] = ocCart();
    CartItem::where('cart_id', $cart->id)->update(['bv_paise' => 50000]); // give the line BV
    $cart->load('items.variant.product');
    $order = app(CheckoutService::class)->place(
        $cart,
        ['name' => 'OC', 'email' => $user->email, 'phone' => '+919800000000', 'marketing_opt_in' => false],
        ['name' => 'OC', 'phone' => '+919800000000', 'line1' => '1 St', 'line2' => null, 'city' => 'Pune', 'state' => 'MH', 'pincode' => '411001'],
        [], $distId, 'logged_in', Order::PAYMENT_ONLINE, null, $user->id, $distId,
    );
    $sm = app(OrderStateMachine::class);
    $sm->markPaid($order->fresh()); // accrues BV (self-consumption)

    expect((int) BvLedgerEntry::where('order_id', $order->id)->sum('bv_paise'))->toBeGreaterThan(0);

    $sm->cancel($order->fresh(), 'changed mind');

    // Net BV for the order is now zero (accrual + reversal).
    expect((int) BvLedgerEntry::where('order_id', $order->id)->sum('bv_paise'))->toBe(0);
});

it('CANCEL-02: a shipped order cannot be cancelled', function (): void {
    $user = ocUser();
    $order = ocPlace($user);
    $sm = app(OrderStateMachine::class);
    $sm->markPaid($order->fresh());
    $sm->markShipped($order->fresh());

    $sm->cancel($order->fresh(), 'too late');
})->throws(RuntimeException::class);

it('CANCEL-03: a customer can cancel their own pre-ship order', function (): void {
    $user = ocUser();
    $order = ocPlace($user);

    $this->actingAs($user)->post(route('orders.cancel', $order->order_no))
        ->assertRedirect(route('orders.show', $order->order_no));

    expect($order->fresh()->status)->toBe('cancelled');
});

it('CANCEL-04: a customer cannot cancel someone else\'s order (404)', function (): void {
    $owner = ocUser();
    $order = ocPlace($owner);

    $this->actingAs(ocUser())->post(route('orders.cancel', $order->order_no))->assertNotFound();
    expect($order->fresh()->status)->toBe('placed');
});

it('CANCEL-05: a customer cannot cancel an order that has shipped', function (): void {
    $user = ocUser();
    $order = ocPlace($user);
    $sm = app(OrderStateMachine::class);
    $sm->markPaid($order->fresh());
    $sm->markShipped($order->fresh());

    $this->actingAs($user)->post(route('orders.cancel', $order->order_no))
        ->assertSessionHasErrors('cancel');
    expect($order->fresh()->status)->toBe('shipped');
});

/**
 * The lines of a transaction, keyed "account.code|side".
 *
 * @return array<string, int>
 */
function ocTxLines(string $idempotencyKey): array
{
    $tx = DB::table('ledger_tx')->where('idempotency_key', $idempotencyKey)->first();
    if ($tx === null) {
        return [];
    }

    return DB::table('ledger_entries')
        ->join('ledger_accounts', 'ledger_accounts.id', '=', 'ledger_entries.account_id')
        ->where('ledger_entries.ledger_tx_id', $tx->id)
        ->get(['ledger_accounts.code', 'ledger_entries.side', 'ledger_entries.amount_paise'])
        ->mapWithKeys(fn ($row): array => ["{$row->code}|{$row->side}" => (int) $row->amount_paise])
        ->all();
}

it('CANCEL-06: cancelling an unpaid online order undoes the placement entry outright', function (): void {
    $user = ocUser();
    $order = ocPlace($user);

    expect(ocTxLines("order.placed:{$order->id}"))->toEqualCanonicalizing([
        'asset.cash.gateway.razorpay|debit' => $order->total_paise,
        'liability.customer_prepayment|credit' => $order->total_paise,
    ]);

    app(OrderStateMachine::class)->cancel($order->fresh(), 'changed mind', $user->id);

    // Never paid, so nothing was ever collected: the gateway cash goes back
    // out the same way it came in and no refund is payable.
    expect(ocTxLines("order.cancelled:{$order->id}"))->toEqualCanonicalizing([
        'liability.customer_prepayment|debit' => $order->total_paise,
        'asset.cash.gateway.razorpay|credit' => $order->total_paise,
    ]);
});

it('CANCEL-07: cancelling a paid online order turns the prepayment into a refund payable', function (): void {
    $user = ocUser();
    $order = ocPlace($user);
    $sm = app(OrderStateMachine::class);
    $sm->markPaid($order->fresh());

    $sm->cancel($order->fresh(), 'changed mind', $user->id);

    // The money really is at the gateway, so the obligation to deliver becomes
    // an obligation to refund — settled in Phase 3, exactly as RefundOrder does.
    expect(ocTxLines("order.cancelled:{$order->id}"))->toEqualCanonicalizing([
        'liability.customer_prepayment|debit' => $order->total_paise,
        'liability.refund_payable|credit' => $order->total_paise,
    ]);

    // And the prepayment account nets to zero across both transactions.
    $net = DB::table('ledger_entries')
        ->join('ledger_accounts', 'ledger_accounts.id', '=', 'ledger_entries.account_id')
        ->join('ledger_tx', 'ledger_tx.id', '=', 'ledger_entries.ledger_tx_id')
        ->where('ledger_accounts.code', 'liability.customer_prepayment')
        ->where('ledger_tx.source_id', $order->id)
        ->get(['ledger_entries.side', 'ledger_entries.amount_paise'])
        ->sum(fn ($row): int => $row->side === 'credit' ? (int) $row->amount_paise : -(int) $row->amount_paise);

    expect($net)->toBe(0)
        ->and(AuditLog::where('subject_id', $order->id)->where('action', 'order.cancelled')->value('details')['prepayment_reversed_paise'])
        ->toBe($order->total_paise);
});
