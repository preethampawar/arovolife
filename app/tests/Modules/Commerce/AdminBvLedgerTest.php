<?php

declare(strict_types=1);

use App\Modules\Commerce\Models\BvLedgerEntry;
use App\Modules\Commerce\Models\Customer;
use App\Modules\Commerce\Models\Order;
use App\Modules\Compliance\Models\AuditLog;
use App\Modules\Identity\Models\User;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

/** An admin user who can reach the BV-ledger report. */
function ablAdmin(): User
{
    Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
    $admin = User::create([
        'full_name' => 'Ledger Admin',
        'email' => 'abl-admin-'.uniqid().'@test.com',
        'phone_e164' => '+91'.str_pad((string) rand(7000000000, 9999999999), 10, '0'),
        'password_hash' => bcrypt('x'),
        'password_set_at' => now(),
        'status' => 'active',
        'email_verified_at' => now(),
    ]);
    $admin->assignRole('admin');

    return $admin;
}

/** A self-rooted distributor with a known name, returning its id. */
function ablDistributor(string $name): int
{
    $user = User::create([
        'full_name' => $name,
        'email' => 'abl-'.uniqid().'@test.com',
        'phone_e164' => '+91'.str_pad((string) rand(7000000000, 9999999999), 10, '0'),
        'password_hash' => bcrypt('x'),
        'status' => 'active',
    ]);
    disableTestForeignKeys();
    try {
        $id = DB::table('distributors')->insertGetId([
            'user_id' => $user->id, 'adn' => (string) random_int(100000000, 999999999),
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

/** A minimal paid order (satisfies the bv_ledger_entries.order_id FK). */
function ablOrder(int $distributorId): Order
{
    // No distributor_id on the customer — customers.distributor_id is UNIQUE,
    // and the ledger keys off bv_ledger_entries.distributor_id directly anyway.
    $customer = Customer::create(['display_name' => 'Buyer']);

    return Order::create([
        'order_no' => 'ORD-ABL-'.random_int(100000, 999999),
        'customer_id' => $customer->id,
        'attributed_distributor_id' => $distributorId,
        'attribution_source' => 'logged_in',
        'payment_method' => Order::PAYMENT_ONLINE,
        'status' => Order::STATUS_PAID,
        'self_consumption' => true,
        'subtotal_paise' => 300000, 'gst_paise' => 0, 'discount_paise' => 0,
        'shipping_paise' => 0, 'total_paise' => 300000,
        'ship_name' => 'Buyer', 'ship_phone_e164' => '+919800000000',
        'ship_line1' => '1 St', 'ship_city' => 'Pune', 'ship_state' => 'MH', 'ship_pincode' => '411001',
        'placed_at' => now(), 'idempotency_key' => 'idem-'.uniqid(),
    ]);
}

function ablEntry(int $distributorId, int $orderId, int $bvPaise, string $type, ?string $effectiveAt = null): BvLedgerEntry
{
    return BvLedgerEntry::create([
        'distributor_id' => $distributorId,
        'order_id' => $orderId,
        'bv_paise' => $bvPaise,
        'type' => $type,
        'effective_at' => $effectiveAt ?? now(),
    ]);
}

/** distributor with 3,630 BV accrued, 1,000 BV reversed → 2,630 BV net. */
function ablSeedDistributor(string $name = 'K RAMAKRISHNA'): int
{
    $id = ablDistributor($name);
    $o1 = ablOrder($id);
    $o2 = ablOrder($id);
    ablEntry($id, $o1->id, 213000, BvLedgerEntry::TYPE_ACCRUAL);
    ablEntry($id, $o2->id, 150000, BvLedgerEntry::TYPE_ACCRUAL);
    ablEntry($id, $o2->id, -100000, BvLedgerEntry::TYPE_REVERSAL);

    return $id;
}

it('summary tab shows a distributor accrued/reversed/net and links to their ledger', function (): void {
    $id = ablSeedDistributor();

    $this->actingAs(ablAdmin())
        ->get(route('admin.commerce.bv-ledger.index'))
        ->assertOk()
        ->assertSee('3,630 BV')   // accrued
        ->assertSee('1,000 BV')   // reversed
        ->assertSee('2,630 BV')   // net
        ->assertSee(route('admin.commerce.bv-ledger.show', $id));
});

it('summary tab filters by ADN/name search', function (): void {
    ablSeedDistributor('FINDABLE PERSON');
    ablSeedDistributor('OTHER PERSON');

    $this->actingAs(ablAdmin())
        ->get(route('admin.commerce.bv-ledger.index', ['tab' => 'summary', 'q' => 'FINDABLE']))
        ->assertOk()
        ->assertSee('FINDABLE PERSON')
        ->assertDontSee('OTHER PERSON');
});

it('entries tab lists raw accrual and reversal rows with the order number', function (): void {
    $id = ablDistributor('Entry Person');
    $order = ablOrder($id);
    ablEntry($id, $order->id, 213000, BvLedgerEntry::TYPE_ACCRUAL);

    $this->actingAs(ablAdmin())
        ->get(route('admin.commerce.bv-ledger.index', ['tab' => 'entries']))
        ->assertOk()
        ->assertSee('Accrual')
        ->assertSee($order->order_no);
});

it('date-range filter excludes entries outside the window', function (): void {
    $id = ablDistributor('Date Person');
    $order = ablOrder($id);
    ablEntry($id, $order->id, 213000, BvLedgerEntry::TYPE_ACCRUAL, '2026-01-15 10:00:00.000');

    // A window that starts after the entry must not include it.
    $this->actingAs(ablAdmin())
        ->get(route('admin.commerce.bv-ledger.index', ['tab' => 'entries', 'from' => '2026-05-01']))
        ->assertOk()
        ->assertSee('No BV entries in this range')
        ->assertDontSee($order->order_no);
});

it('individual ledger shows a running balance that nets to the lifetime total', function (): void {
    $id = ablSeedDistributor('Running Person');

    $res = $this->actingAs(ablAdmin())
        ->get(route('admin.commerce.bv-ledger.show', $id))
        ->assertOk();

    // Ascending: 2,130 → 3,630 (running) then reversal → 2,630 net.
    $res->assertSee('2,130 BV')   // running after first accrual
        ->assertSee('3,630 BV')   // running after second accrual
        ->assertSee('2,630 BV');  // running after reversal == lifetime net
});

it('summary CSV export returns text/csv, includes the row, and is audit-logged', function (): void {
    $id = ablSeedDistributor('Export Person');
    $adn = DB::table('distributors')->where('id', $id)->value('adn');

    $res = $this->actingAs(ablAdmin())
        ->get(route('admin.commerce.bv-ledger.export', ['tab' => 'summary']))
        ->assertOk();

    expect($res->headers->get('content-type'))->toContain('text/csv');
    expect($res->getContent())->toContain((string) $adn);
    expect(AuditLog::where('action', 'bv.report.exported')->count())->toBe(1);
});

it('individual ledger CSV export is audit-logged', function (): void {
    $id = ablSeedDistributor('Export Two');

    $this->actingAs(ablAdmin())
        ->get(route('admin.commerce.bv-ledger.show.export', $id))
        ->assertOk();

    expect(AuditLog::where('action', 'bv.report.exported')->count())->toBe(1);
});

it('forbids a non-admin from the BV ledger report', function (): void {
    $user = User::create([
        'full_name' => 'Plain User',
        'email' => 'abl-plain-'.uniqid().'@test.com',
        'phone_e164' => '+91'.str_pad((string) rand(7000000000, 9999999999), 10, '0'),
        'password_hash' => bcrypt('x'),
        'password_set_at' => now(),
        'status' => 'active',
        'email_verified_at' => now(),
    ]);

    $this->actingAs($user)
        ->withoutMiddleware(PreventRequestForgery::class)
        ->get(route('admin.commerce.bv-ledger.index'))
        ->assertForbidden();
});
