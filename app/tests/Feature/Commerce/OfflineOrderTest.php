<?php

declare(strict_types=1);

/**
 * Offline orders — orders staff create for a distributor who paid outside the
 * gateway, confirmed by finance (docs/plans/offline-orders-2026-09-23.md).
 *
 * The central claim under test: once confirmed, an offline order has exactly
 * the effects of a paid shop order, because it reaches the same
 * PaymentConfirmationService::settle() → OrderStateMachine::markPaid() path.
 */

use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Commerce\Models\BvLedgerEntry;
use App\Modules\Commerce\Models\OfflinePayment;
use App\Modules\Commerce\Models\Order;
use App\Modules\Commerce\Services\DTOs\OfflinePaymentDetails;
use App\Modules\Commerce\Services\OfflineOrderService;
use App\Modules\Commerce\Services\OfflinePaymentProofVault;
use App\Modules\Commerce\Services\OrderStateMachine;
use App\Modules\Compensation\Jobs\PropagateGroupBvJob;
use App\Modules\Compensation\Services\WalletService;
use App\Modules\Compliance\Models\AuditLog;
use App\Modules\Identity\Models\Distributor;
use App\Modules\Identity\Models\User;
use App\Modules\Inventory\Models\StockBatch;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\StockLedger;
use App\Modules\Ledger\Models\LedgerEntry;
use App\Modules\Ledger\Models\LedgerTx;
use App\Modules\Payments\Data\ConfirmationResult;
use App\Modules\Payments\Services\PaymentConfirmationService;
use App\Modules\Payments\Support\RefundPayable;
use App\Modules\Shared\Features\OfflineOrdersFeature;
use App\Modules\Tax\Models\Invoice;
use Carbon\CarbonImmutable;
use Database\Seeders\LedgerAccountSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Pennant\Feature;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(LedgerAccountSeeder::class);
    $this->seed(RolesAndPermissionsSeeder::class);
    Feature::for(null)->activate(OfflineOrdersFeature::class);
    Storage::fake(OfflinePayment::DISK);
    ooSetting('commerce.self_purchase.earns_bv', 'true');
    ooSetting('tax.seller_state', 'Telangana');
});

function ooSetting(string $key, string $value): void
{
    DB::table('settings')->updateOrInsert(['key' => $key], ['value' => $value, 'version' => 1, 'updated_at' => now()]);
}

function ooStaff(string $role): User
{
    $user = User::create([
        'full_name' => ucfirst($role).' Staff',
        'email' => 'oo-'.$role.'-'.uniqid().'@test.com',
        'phone_e164' => '+91'.random_int(7000000000, 9999999999),
        'password_hash' => bcrypt('x'),
        'password_set_at' => now(),
        'status' => 'active',
        'email_verified_at' => now(),
    ]);
    $user->assignRole($role);

    return $user;
}

function ooDistributor(string $userStatus = 'active', ?User $user = null): Distributor
{
    $user ??= User::create([
        'full_name' => 'Offline Buyer',
        'email' => 'oo-buyer-'.uniqid().'@test.com',
        'phone_e164' => '+91'.random_int(7000000000, 9999999999),
        'password_hash' => bcrypt('x'),
        'status' => $userStatus,
    ]);

    disableTestForeignKeys();
    try {
        $id = DB::table('distributors')->insertGetId([
            'user_id' => $user->id,
            'adn' => (string) random_int(100000000, 999999999),
            'pan_hash' => random_bytes(32),
            'pan_last4' => '0000',
            'bank_account_enc' => 'stub',
            'bank_ifsc' => 'SBIN0000000',
            'sponsor_id' => 0,
            'placement_parent_id' => 0,
            'side_chosen_by' => 'referral_default',
            'depth' => 0,
            'effective_date' => now()->subDays(60)->format('Y-m-d H:i:s.v'),
            'cooling_off_end_at' => now()->subDays(30)->format('Y-m-d H:i:s.v'),
            'state' => 'TS',
            'is_primary_couple' => 0,
            'created_at' => now()->format('Y-m-d H:i:s.v'),
            'updated_at' => now()->format('Y-m-d H:i:s.v'),
        ]);
        DB::table('distributors')->where('id', $id)->update(['sponsor_id' => $id, 'placement_parent_id' => $id]);
    } finally {
        enableTestForeignKeys();
    }

    return Distributor::with('user')->findOrFail($id);
}

/** ₹1,000 GST-inclusive, 500 BV, 18% GST, with warehouse stock so it can be packed. */
function ooVariant(int $stock = 20): ProductVariant
{
    $n = random_int(10000, 99999);
    $product = Product::create(['sku' => "OO-{$n}", 'slug' => "oo-{$n}", 'name' => "Offline {$n}", 'hsn_code' => '3004', 'status' => 'active']);
    $variant = ProductVariant::create([
        'product_id' => $product->id, 'variant_sku' => "OO-{$n}-V1", 'name' => 'Default',
        'mrp_paise' => 100000, 'sale_price_paise' => 100000, 'cost_paise' => 60000, 'bv_paise' => 50000,
        'gst_rate_bp' => 1800, 'inventory_policy' => 'track', 'status' => 'active',
    ]);

    $batch = StockBatch::create([
        'product_variant_id' => $variant->id, 'warehouse_code' => Warehouse::DEFAULT_CODE, 'batch_no' => 'OO1',
        'unit_cost_paise' => 60000, 'qty_on_hand' => 0, 'received_at' => now(),
    ]);
    app(StockLedger::class)->post([
        'type' => StockMovement::TYPE_PURCHASE_IN, 'variant_id' => $variant->id, 'warehouse_code' => Warehouse::DEFAULT_CODE,
        'batch_id' => $batch->id, 'qty' => $stock, 'unit_cost_paise' => 60000,
        'reference_type' => 'purchase_invoice_item', 'reference_id' => 1,
    ]);

    return $variant;
}

/** @return array{delivery_type: string, arete_center_id: null, name: string, phone: string, line1: string, line2: null, city: string, state: string, pincode: string} */
function ooDelivery(): array
{
    return ['delivery_type' => 'ship', 'arete_center_id' => null, 'name' => 'Offline Buyer', 'phone' => '+919800000000',
        'line1' => '1 Market Road', 'line2' => null, 'city' => 'Pune', 'state' => 'Maharashtra', 'pincode' => '411001'];
}

function ooDetails(int $amountPaise, string $channel = OfflinePayment::CHANNEL_UPI, ?string $reference = 'default', ?string $receivedOn = null): OfflinePaymentDetails
{
    return new OfflinePaymentDetails(
        channel: $channel,
        channelOther: null,
        amountPaise: $amountPaise,
        receivedOn: CarbonImmutable::parse($receivedOn ?? now()->toDateString()),
        referenceNo: $reference === 'default' ? 'UTR'.random_int(100000, 999999) : $reference,
        payerName: 'Offline Buyer',
        notes: null,
    );
}

/** The payable for $qty units of ooVariant() delivered: the shop's own fee applies. */
function ooTotal(Distributor $d, ProductVariant $v, int $qty): int
{
    return app(OfflineOrderService::class)->quote($d, [$v->id => $qty], false)->totalPaise;
}

function ooCreate(Distributor $d, ProductVariant $v, User $actor, int $qty = 2, ?OfflinePaymentDetails $details = null, ?string $key = null, ?UploadedFile $proof = null): Order
{
    return app(OfflineOrderService::class)->create(
        $d, [$v->id => $qty], ooDelivery(), $details ?? ooDetails(ooTotal($d, $v, $qty)), $proof, $key ?? (string) Str::uuid(), $actor,
    );
}

it('OO-01: creating an offline order places it pending with nothing on the books', function (): void {
    $d = ooDistributor();
    $v = ooVariant();
    $ops = ooStaff('admin-operations');
    $walletBefore = app(WalletService::class)->repurchaseWalletBalancePaise($d->id);

    $order = ooCreate($d, $v, $ops, 2);

    expect($order->status)->toBe(Order::STATUS_PLACED)
        ->and($order->payment_method)->toBe(Order::PAYMENT_OFFLINE)
        ->and($order->attribution_source)->toBe('admin')
        ->and($order->self_consumption)->toBeTrue()
        ->and($order->attributed_distributor_id)->toBe($d->id)
        ->and($order->paid_at)->toBeNull()
        ->and($order->offlinePayment->status)->toBe(OfflinePayment::STATUS_PENDING)
        ->and($order->offlinePayment->recorded_by_user_id)->toBe($ops->id)
        ->and(LedgerTx::where('idempotency_key', "order.placed:{$order->id}")->exists())->toBeFalse()
        ->and(BvLedgerEntry::where('order_id', $order->id)->exists())->toBeFalse()
        ->and((int) $v->inventory()->value('reserved'))->toBe(2)
        ->and(app(WalletService::class)->repurchaseWalletBalancePaise($d->id))->toBe($walletBefore)
        ->and(AuditLog::where('action', 'order.offline_created')->where('subject_id', $order->id)->value('actor_id'))->toBe($ops->id)
        ->and(AuditLog::where('action', 'order.placed')->where('subject_id', $order->id)->value('actor_id'))->toBe($ops->id);
});

it('OO-02: an amount that does not match the order total creates nothing', function (): void {
    $d = ooDistributor();
    $v = ooVariant();
    $proof = UploadedFile::fake()->create('slip.pdf', 20, 'application/pdf');

    expect(fn () => ooCreate($d, $v, ooStaff('admin-operations'), 2, ooDetails(ooTotal($d, $v, 2) - 100), proof: $proof))
        ->toThrow(RuntimeException::class, 'must match exactly');

    expect(Order::count())->toBe(0)
        ->and(OfflinePayment::count())->toBe(0)
        ->and(Storage::disk(OfflinePayment::DISK)->allFiles())->toBe([]);
});

it('OO-03: resubmitting the same form token returns the same order', function (): void {
    $d = ooDistributor();
    $v = ooVariant();
    $ops = ooStaff('admin-operations');
    $key = (string) Str::uuid();

    $first = ooCreate($d, $v, $ops, 1, key: $key);
    $second = ooCreate($d, $v, $ops, 1, key: $key);

    expect($second->id)->toBe($first->id)->and(Order::count())->toBe(1);
});

it('OO-04: a reference already used by a live offline payment is refused', function (): void {
    $d = ooDistributor();
    $v = ooVariant();
    $ops = ooStaff('admin-operations');

    ooCreate($d, $v, $ops, 1, ooDetails(ooTotal($d, $v, 1), reference: 'utr 555 111'));

    expect(fn () => ooCreate($d, $v, $ops, 1, ooDetails(ooTotal($d, $v, 1), reference: 'UTR555111')))
        ->toThrow(RuntimeException::class, 'already recorded');
});

it('OO-05: a blocked or terminated distributor cannot be ordered for', function (string $status): void {
    $d = ooDistributor($status);

    expect(app(OfflineOrderService::class)->eligibleDistributor($d->adn))->toBeNull();
    expect(fn () => ooCreate($d, ooVariant(), ooStaff('admin-operations'), 1, ooDetails(100000)))
        ->toThrow(RuntimeException::class, 'cannot be ordered for');
})->with(['frozen', 'terminated', 'rejected']);

it('OO-06: finance confirming has exactly the effects of a paid shop order', function (): void {
    Bus::fake([PropagateGroupBvJob::class]);
    $d = ooDistributor();
    $v = ooVariant();
    $order = ooCreate($d, $v, ooStaff('admin-operations'), 2);
    $finance = ooStaff('admin-finance');

    $result = app(PaymentConfirmationService::class)->confirmOffline($order, $finance, 'Matched on statement');

    $order->refresh();
    $tx = LedgerTx::where('idempotency_key', "order.placed:{$order->id}")->sole();
    $lines = LedgerEntry::where('ledger_tx_id', $tx->id)->with('account')->get()
        ->mapWithKeys(fn (LedgerEntry $e) => [$e->account->code.':'.$e->side => (int) $e->amount_paise]);
    $paidAudit = AuditLog::where('action', 'order.paid')->where('subject_id', $order->id)->sole();

    expect($result->status)->toBe(ConfirmationResult::CONFIRMED)
        ->and($order->status)->toBe(Order::STATUS_PAID)
        ->and($order->paid_at)->not->toBeNull()
        ->and($lines->all())->toBe([
            'asset.cash.bank.settlement:debit' => $order->total_paise,
            'liability.customer_prepayment:credit' => $order->total_paise,
        ])
        // Personal BV: the same accrual row BvLedgerService writes for a shop order.
        ->and((int) BvLedgerEntry::where('order_id', $order->id)->where('type', BvLedgerEntry::TYPE_ACCRUAL)->value('bv_paise'))->toBe(100000)
        ->and(Invoice::where('order_id', $order->id)->exists())->toBeTrue()
        ->and($paidAudit->actor_id)->toBe($finance->id)
        ->and($paidAudit->details['confirmed_via'])->toBe('offline')
        ->and($paidAudit->details['offline_payment_id'])->toBe($order->offlinePayment->id)
        ->and($paidAudit->details['same_actor'])->toBeFalse()
        ->and($order->offlinePayment->fresh()->status)->toBe(OfflinePayment::STATUS_CONFIRMED)
        ->and($order->offlinePayment->fresh()->confirmed_by_user_id)->toBe($finance->id);

    // Genos BV up the upline: the same listener → job as a shop order.
    Bus::assertDispatched(PropagateGroupBvJob::class, fn (PropagateGroupBvJob $job): bool => (fn (): array => [$this->orderId, $this->distributorId, $this->bvPaise])->call($job)
        === [$order->id, $d->id, 100000]);
});

it('OO-06b: cash lands in the office cash account', function (): void {
    $d = ooDistributor();
    $v = ooVariant();
    $order = ooCreate($d, $v, ooStaff('admin-operations'), 1, ooDetails(ooTotal($d, $v, 1), OfflinePayment::CHANNEL_CASH, null));

    app(PaymentConfirmationService::class)->confirmOffline($order, ooStaff('admin-finance'));

    $tx = LedgerTx::where('idempotency_key', "order.placed:{$order->id}")->sole();
    $debit = LedgerEntry::where('ledger_tx_id', $tx->id)->where('side', 'debit')->with('account')->sole();

    expect($debit->account->code)->toBe('asset.cash.office');
});

it('OO-07: confirming twice changes nothing the second time', function (): void {
    $d = ooDistributor();
    $v = ooVariant();
    $order = ooCreate($d, $v, ooStaff('admin-operations'), 1);
    $finance = ooStaff('admin-finance');

    app(PaymentConfirmationService::class)->confirmOffline($order, $finance);
    $again = app(PaymentConfirmationService::class)->confirmOffline($order->fresh(), $finance);

    expect($again->status)->toBe(ConfirmationResult::ALREADY_CONFIRMED)
        ->and(LedgerTx::where('idempotency_key', "order.placed:{$order->id}")->count())->toBe(1)
        ->and(BvLedgerEntry::where('order_id', $order->id)->count())->toBe(1);
});

it('OO-08: a cancelled or rejected offline order cannot be confirmed', function (): void {
    $d = ooDistributor();
    $v = ooVariant();
    $ops = ooStaff('admin-operations');
    $finance = ooStaff('admin-finance');

    $rejected = ooCreate($d, $v, $ops, 1);
    app(OfflineOrderService::class)->reject($rejected, $finance, 'Deposit never arrived');

    expect(fn () => app(PaymentConfirmationService::class)->confirmOffline($rejected->fresh(), $finance))
        ->toThrow(RuntimeException::class, 'rejected');

    $cancelled = ooCreate($d, $v, $ops, 1);
    app(OrderStateMachine::class)->cancel($cancelled, 'test', $ops->id);

    expect(fn () => app(PaymentConfirmationService::class)->confirmOffline($cancelled->fresh(), $finance))
        ->toThrow(RuntimeException::class, 'cancelled');

    expect(LedgerTx::where('idempotency_key', 'like', 'order.placed:%')->count())->toBe(0);
});

it('OO-09: a confirmed offline order ships with balanced revenue recognition', function (): void {
    $d = ooDistributor();
    $v = ooVariant();
    $order = ooCreate($d, $v, ooStaff('admin-operations'), 2);
    app(PaymentConfirmationService::class)->confirmOffline($order, ooStaff('admin-finance'));

    app(OrderStateMachine::class)->markShipped($order->fresh(), null, 'Delhivery', 'AWB-1');

    expect($order->fresh()->status)->toBe(Order::STATUS_SHIPPED)
        ->and(LedgerTx::where('idempotency_key', "order.shipped:{$order->id}")->exists())->toBeTrue();
});

it('OO-10: cancelling a confirmed offline order puts the refund on the books', function (): void {
    $d = ooDistributor();
    $v = ooVariant();
    $order = ooCreate($d, $v, ooStaff('admin-operations'), 1);
    app(PaymentConfirmationService::class)->confirmOffline($order, ooStaff('admin-finance'));

    app(OrderStateMachine::class)->cancel($order->fresh(), 'Buyer changed mind', null);

    $order->refresh();
    expect($order->status)->toBe(Order::STATUS_CANCELLED)
        ->and(RefundPayable::owedOutsideGateway($order))->toBe($order->total_paise)
        ->and((int) BvLedgerEntry::where('order_id', $order->id)->sum('bv_paise'))->toBe(0);
});

it('OO-11: rejecting cancels the order and releases the stock, posting nothing', function (): void {
    $d = ooDistributor();
    $v = ooVariant();
    $order = ooCreate($d, $v, ooStaff('admin-operations'), 3);

    app(OfflineOrderService::class)->reject($order, ooStaff('admin-finance'), 'Deposit never arrived');

    $order->refresh();
    expect($order->status)->toBe(Order::STATUS_CANCELLED)
        ->and($order->offlinePayment->status)->toBe(OfflinePayment::STATUS_REJECTED)
        ->and($order->offlinePayment->rejection_reason)->toBe('Deposit never arrived')
        ->and((int) $v->inventory()->value('reserved'))->toBe(0)
        ->and(LedgerTx::where('source_id', $order->id)->exists())->toBeFalse();
});

it('OO-19: the service refuses a confirmer without finance.record', function (): void {
    $d = ooDistributor();
    $v = ooVariant();
    $ops = ooStaff('admin-operations');
    $order = ooCreate($d, $v, $ops, 1);

    expect(fn () => app(PaymentConfirmationService::class)->confirmOffline($order, $ops))->toThrow(AuthorizationException::class);
    expect(fn () => ooCreate($d, $v, ooStaff('admin-finance'), 1))->toThrow(AuthorizationException::class);
});

it('OO-20: cash of ₹2 lakh or more from one person in a day is refused', function (): void {
    $d = ooDistributor();
    $v = ooVariant(500);
    $ops = ooStaff('admin-operations');
    // 100 units ≈ ₹1 lakh each order; two on the same day reach the ceiling.
    $total = ooTotal($d, $v, 100);

    ooCreate($d, $v, $ops, 100, ooDetails($total, OfflinePayment::CHANNEL_CASH, null));

    expect(fn () => ooCreate($d, $v, $ops, 100, ooDetails($total, OfflinePayment::CHANNEL_CASH, null)))
        ->toThrow(RuntimeException::class, 's.269ST');

    // A different day is a different day.
    ooCreate($d, $v, $ops, 100, ooDetails($total, OfflinePayment::CHANNEL_CASH, null, now()->subDay()->toDateString()));
    expect(Order::count())->toBe(2);
});

it('OO-22: staff cannot order for, or confirm, their own distributor account', function (): void {
    $ops = ooStaff('admin-operations');
    $own = ooDistributor(user: $ops);

    expect(fn () => ooCreate($own, ooVariant(), $ops, 1, ooDetails(100000)))->toThrow(RuntimeException::class, 'your own');

    $admin = ooStaff('admin');
    $ownAdmin = ooDistributor(user: $admin);
    $v = ooVariant();
    $order = ooCreate($ownAdmin, $v, ooStaff('admin-operations'), 1);

    expect(fn () => app(PaymentConfirmationService::class)->confirmOffline($order, $admin))->toThrow(RuntimeException::class, 'your own');
});

it('OO-23: proof files are stored encrypted and read back as the original bytes', function (): void {
    $d = ooDistributor();
    $v = ooVariant();
    $proof = UploadedFile::fake()->image('slip.png', 20, 20);
    $original = (string) file_get_contents($proof->getRealPath());

    $order = ooCreate($d, $v, ooStaff('admin-operations'), 1, proof: $proof);
    $payment = $order->offlinePayment;
    $stored = Storage::disk(OfflinePayment::DISK)->get($payment->proof_storage_key);

    expect($payment->hasProof())->toBeTrue()
        ->and($payment->proof_mime)->toBe('image/png')
        ->and($stored)->not->toBe($original)
        ->and(app(OfflinePaymentProofVault::class)->read($payment))->toBe($original);
});

it('OO-25: one person recording and confirming is flagged as same_actor', function (): void {
    $d = ooDistributor();
    $v = ooVariant();
    $admin = ooStaff('admin');
    $order = ooCreate($d, $v, $admin, 1);

    app(PaymentConfirmationService::class)->confirmOffline($order, $admin);

    expect(AuditLog::where('action', 'order.paid')->where('subject_id', $order->id)->sole()->details['same_actor'])->toBeTrue();
});

/** A valid store() payload for $qty units of $v, paid by UPI. */
function ooPayload(Distributor $d, ProductVariant $v, int $qty = 1, array $overrides = []): array
{
    return array_merge([
        'adn' => $d->adn,
        'qty' => [$v->id => $qty],
        'delivery_type' => 'ship',
        'buyer_name' => 'Offline Buyer',
        'buyer_phone' => '9800000000',
        'ship_line1' => '1 Market Road',
        'ship_city' => 'Pune',
        'ship_state' => 'Maharashtra',
        'ship_pincode' => '411001',
        'channel' => 'upi',
        'amount' => number_format(ooTotal($d, $v, $qty) / 100, 2, '.', ''),
        'received_on' => now()->toDateString(),
        'reference_no' => 'UPI'.random_int(100000, 999999),
        'terms_acknowledged' => '1',
        'form_token' => (string) Str::uuid(),
    ], $overrides);
}

it('OO-12: operations can open the form and create, but cannot confirm or reject', function (): void {
    $d = ooDistributor();
    $v = ooVariant();
    $ops = ooStaff('admin-operations');

    $this->actingAs($ops)->get(route('admin.commerce.offline-orders.create', ['adn' => $d->adn]))
        ->assertOk()->assertSee($d->user->full_name)->assertSee('Create offline order');

    $this->actingAs($ops)->post(route('admin.commerce.offline-orders.store'), ooPayload($d, $v))
        ->assertRedirect();
    $order = Order::sole();

    expect($order->isOffline())->toBeTrue();

    $this->actingAs($ops)->get(route('admin.commerce.orders.show', $order))
        ->assertOk()->assertSee('Awaiting finance confirmation')->assertDontSee('Confirm payment');
    $this->actingAs($ops)->post(route('admin.commerce.offline-orders.confirm', $order), ['verified' => '1'])->assertForbidden();
    $this->actingAs($ops)->post(route('admin.commerce.offline-orders.reject', $order), ['money_not_received' => '1', 'reason' => 'nope nope'])->assertForbidden();
});

it('OO-13: finance cannot create, but can confirm', function (): void {
    $d = ooDistributor();
    $v = ooVariant();
    $finance = ooStaff('admin-finance');

    $this->actingAs($finance)->get(route('admin.commerce.offline-orders.create'))->assertForbidden();
    $this->actingAs($finance)->post(route('admin.commerce.offline-orders.store'), ooPayload($d, $v))->assertForbidden();
    $this->actingAs($finance)->get(route('admin.commerce.orders.index'))->assertOk()->assertDontSee('New offline order');

    $order = ooCreate($d, $v, ooStaff('admin-operations'), 1);

    $this->actingAs($finance)->get(route('admin.commerce.orders.show', $order))->assertOk()->assertSee('Confirm payment');
    $this->actingAs($finance)->post(route('admin.commerce.offline-orders.confirm', $order), ['verified' => '1'])
        ->assertRedirect(route('admin.commerce.orders.show', $order))->assertSessionHasNoErrors();

    expect($order->fresh()->status)->toBe(Order::STATUS_PAID);
});

it('OO-13b: confirming needs the "money checked" tick; rejecting needs the "not received" tick', function (): void {
    $order = ooCreate(ooDistributor(), ooVariant(), ooStaff('admin-operations'), 1);
    $finance = ooStaff('admin-finance');

    $this->actingAs($finance)->post(route('admin.commerce.offline-orders.confirm', $order), [])->assertSessionHasErrors('verified');
    $this->actingAs($finance)->post(route('admin.commerce.offline-orders.reject', $order), ['reason' => 'Deposit never arrived'])
        ->assertSessionHasErrors('money_not_received');

    expect($order->fresh()->status)->toBe(Order::STATUS_PLACED);
});

it('OO-14: compliance can neither create, confirm nor open the proof', function (): void {
    $d = ooDistributor();
    $v = ooVariant();
    $order = ooCreate($d, $v, ooStaff('admin-operations'), 1, proof: UploadedFile::fake()->image('slip.png'));
    $compliance = ooStaff('admin-compliance');

    $this->actingAs($compliance)->get(route('admin.commerce.offline-orders.create'))->assertForbidden();
    $this->actingAs($compliance)->post(route('admin.commerce.offline-orders.confirm', $order), ['verified' => '1'])->assertForbidden();
    $this->actingAs($compliance)->get(route('admin.commerce.offline-orders.proof', $order))->assertForbidden();
});

it('OO-15: a distributor cannot reach any offline-order route', function (): void {
    $d = ooDistributor();
    $v = ooVariant();
    $order = ooCreate($d, $v, ooStaff('admin-operations'), 1);

    $this->actingAs($d->user)->get(route('admin.commerce.offline-orders.create'))->assertForbidden();
    $this->actingAs($d->user)->post(route('admin.commerce.offline-orders.store'), ooPayload($d, $v))->assertForbidden();
    $this->actingAs($d->user)->post(route('admin.commerce.offline-orders.confirm', $order), ['verified' => '1'])->assertForbidden();
});

it('OO-16: with the flag off every offline route 404s and the button is gone', function (): void {
    $d = ooDistributor();
    $v = ooVariant();
    $order = ooCreate($d, $v, ooStaff('admin-operations'), 1);
    Feature::for(null)->deactivate(OfflineOrdersFeature::class);
    $admin = ooStaff('admin');

    $this->actingAs($admin)->get(route('admin.commerce.offline-orders.create'))->assertNotFound();
    $this->actingAs($admin)->post(route('admin.commerce.offline-orders.store'), ooPayload($d, $v))->assertNotFound();
    $this->actingAs($admin)->post(route('admin.commerce.offline-orders.confirm', $order), ['verified' => '1'])->assertNotFound();
    $this->actingAs($admin)->post(route('admin.commerce.offline-orders.reject', $order), ['money_not_received' => '1', 'reason' => 'x x x x x'])->assertNotFound();
    $this->actingAs($admin)->get(route('admin.commerce.offline-orders.proof', $order))->assertNotFound();
    $this->actingAs($admin)->get(route('admin.commerce.orders.index'))->assertOk()->assertDontSee('New offline order');
});

it('OO-17: the buyer cannot self-cancel an offline order still being confirmed', function (): void {
    $d = ooDistributor();
    $order = ooCreate($d, ooVariant(), ooStaff('admin-operations'), 1);

    $this->actingAs($d->user)->get(route('orders.show', $order->order_no))
        ->assertOk()->assertSee('Paid offline')->assertDontSee('Cancel this order');
    $this->actingAs($d->user)->post(route('orders.cancel', $order->order_no))->assertSessionHasErrors('cancel');

    expect($order->fresh()->status)->toBe(Order::STATUS_PLACED);
});

it('OO-18: viewing the proof streams the original bytes and is audited', function (): void {
    $order = ooCreate(ooDistributor(), ooVariant(), ooStaff('admin-operations'), 1, proof: UploadedFile::fake()->image('slip.png', 10, 10));
    $finance = ooStaff('admin-finance');

    $this->actingAs($finance)->get(route('admin.commerce.offline-orders.proof', $order))
        ->assertOk()->assertHeader('Content-Type', 'image/png')->assertHeader('Cache-Control', 'no-store, private');

    expect(AuditLog::where('action', 'order.offline_proof_viewed')->where('actor_id', $finance->id)->exists())->toBeTrue();

    $noProof = ooCreate(ooDistributor(), ooVariant(), ooStaff('admin-operations'), 1);
    $this->actingAs($finance)->get(route('admin.commerce.offline-orders.proof', $noProof))->assertNotFound();
});

it('OO-21: operations cannot cancel a pending offline order from the order page', function (): void {
    $ops = ooStaff('admin-operations');
    $order = ooCreate(ooDistributor(), ooVariant(), $ops, 1);

    $this->actingAs($ops)->post(route('admin.commerce.orders.cancel', $order))->assertSessionHasErrors('cancel');

    expect($order->fresh()->status)->toBe(Order::STATUS_PLACED);
});

it('OO-24: the purge deletes expired proofs, keeps live ones, and a dry run touches nothing', function (): void {
    $ops = ooStaff('admin-operations');
    $finance = ooStaff('admin-finance');

    $old = ooCreate(ooDistributor(), ooVariant(), $ops, 1, proof: UploadedFile::fake()->image('a.png'));
    app(OfflineOrderService::class)->reject($old, $finance, 'Deposit never arrived');
    $old->offlinePayment->update(['rejected_at' => now()->subDays(91)]);

    $live = ooCreate(ooDistributor(), ooVariant(), $ops, 1, proof: UploadedFile::fake()->image('b.png'));
    app(PaymentConfirmationService::class)->confirmOffline($live, $finance);

    $this->artisan('commerce:purge-offline-payment-proofs', ['--dry-run' => true])->assertSuccessful();
    expect($old->offlinePayment->fresh()->proof_storage_key)->not->toBeNull();

    $this->artisan('commerce:purge-offline-payment-proofs')->assertSuccessful();

    $purged = $old->offlinePayment->fresh();
    expect($purged->proof_storage_key)->toBeNull()
        ->and($purged->proof_purged_at)->not->toBeNull()
        ->and($live->offlinePayment->fresh()->proof_storage_key)->not->toBeNull()
        ->and(Storage::disk(OfflinePayment::DISK)->allFiles())->toHaveCount(1)
        ->and(AuditLog::where('action', 'offline_payment.proof_purged')->count())->toBe(1);
});

it('OO-26: a store() amount that does not match comes back as a form error', function (): void {
    $d = ooDistributor();
    $v = ooVariant();

    $this->actingAs(ooStaff('admin-operations'))
        ->post(route('admin.commerce.offline-orders.store'), ooPayload($d, $v, 1, ['amount' => '1.00']))
        ->assertSessionHasErrors('offline');

    expect(Order::count())->toBe(0);
});

it('OO-27: cash of ₹2 lakh or more is refused by validation', function (): void {
    $d = ooDistributor();
    $v = ooVariant();

    $this->actingAs(ooStaff('admin-operations'))
        ->post(route('admin.commerce.offline-orders.store'), ooPayload($d, $v, 1, ['channel' => 'cash', 'amount' => '200000.00', 'reference_no' => '']))
        ->assertSessionHasErrors('amount');
});

it('OO-28: the unpaid-order expiry sweep never touches an offline order', function (): void {
    $order = ooCreate(ooDistributor(), ooVariant(), ooStaff('admin-operations'), 1);
    Order::whereKey($order->id)->update(['placed_at' => now()->subDays(30)]);

    $this->artisan('orders:expire-unpaid')->assertSuccessful();

    expect($order->fresh()->status)->toBe(Order::STATUS_PLACED);
});

it('OO-29: the background quote returns the payable and only needs ADN, quantities and delivery', function (): void {
    $d = ooDistributor();
    $v = ooVariant();

    $this->actingAs(ooStaff('admin-operations'))
        ->getJson(route('admin.commerce.offline-orders.quote', ['adn' => $d->adn, 'qty' => [$v->id => 2], 'delivery_type' => 'ship']))
        ->assertOk()
        ->assertJsonPath('amount', number_format(ooTotal($d, $v, 2) / 100, 2, '.', ''));

    $this->actingAs(ooStaff('admin-finance'))
        ->getJson(route('admin.commerce.offline-orders.quote', ['adn' => $d->adn, 'qty' => [$v->id => 2]]))
        ->assertForbidden();
});

it('OO-30: retention settings below their floors are ignored', function (): void {
    ooSetting(OfflinePaymentProofVault::RETENTION_SETTING, '1');
    ooSetting(OfflinePaymentProofVault::REJECTED_RETENTION_SETTING, '1');

    expect(app(OfflinePaymentProofVault::class)->retentionDays())->toBe(365)
        ->and(app(OfflinePaymentProofVault::class)->rejectedRetentionDays())->toBe(30);
});
