<?php

declare(strict_types=1);

/**
 * R-17 separation of duties — the routes, not the intention.
 *
 * The T-6.1 audit found R-17 was enforced on four routes and asserted in prose
 * everywhere else. `admin-finance` — the role that by design cannot freeze an
 * account — could approve a KYC submission, read Aadhaar and PAN scans, cancel
 * orders, rewrite platform settings, and set an arbitrary password on any
 * distributor. Nothing tested any of it, so nothing noticed.
 *
 * SOD-01: admin-finance cannot reach the KYC queue or the raw identity scans
 * SOD-02: admin-finance cannot set or reset a distributor's password
 * SOD-03: admin-finance cannot rewrite a distributor's identity
 * SOD-04: admin-finance cannot write a platform setting
 * SOD-05: admin-finance cannot cancel an order
 * SOD-06: admin-operations CAN do its own job — the gates are scoped, not shut
 * SOD-07: admin-compliance still cannot record finance actions (the original R-17)
 * SOD-08: no scoped role can change credentials — that is admin/developer only
 */

use App\Modules\Commerce\Models\Customer;
use App\Modules\Commerce\Models\Order;
use App\Modules\Identity\Models\User;
use App\Modules\Returns\Models\ReturnRequest;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
});

function sodUser(string $role): User
{
    Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']);
    $user = User::factory()->create();
    $user->assignRole($role);

    return $user;
}

/**
 * A real order, because `{order}` is route-model bound: binding resolves
 * before the authorization middleware, so a missing row 404s and the 403 we
 * are actually testing for never happens.
 */
function sodOrder(): Order
{
    $customer = Customer::create(['display_name' => 'SoD Buyer']);

    return Order::create([
        'order_no' => 'ORD-SOD-'.random_int(100000, 999999),
        'customer_id' => $customer->id,
        'attribution_source' => 'direct',
        'payment_method' => Order::PAYMENT_ONLINE,
        'status' => Order::STATUS_PAID,
        'subtotal_paise' => 118000, 'gst_paise' => 18000,
        'discount_paise' => 0, 'shipping_paise' => 0, 'total_paise' => 118000,
        'ship_name' => 'Buyer', 'ship_phone_e164' => '+919800000000',
        'ship_line1' => '1 St', 'ship_city' => 'Hyd', 'ship_state' => 'TS', 'ship_pincode' => '500001',
        'placed_at' => now(), 'paid_at' => now(),
        'idempotency_key' => 'sod-'.uniqid(),
    ]);
}

it('SOD-01: admin-finance cannot reach the KYC queue or the identity scans', function () {
    $finance = sodUser('admin-finance');

    // The scans are the DPDP question, not just the decision: who may LOOK at
    // an Aadhaar image matters as much as who may approve it.
    $this->actingAs($finance)->get('/admin/kyc')->assertForbidden();
    $this->actingAs($finance)->get('/admin/kyc/1')->assertForbidden();
    $this->actingAs($finance)->get('/admin/kyc/1/documents/1')->assertForbidden();
    $this->actingAs($finance)->post('/admin/kyc/1/approve')->assertForbidden();
});

it('SOD-02: admin-finance cannot set or reset a distributor password', function () {
    $finance = sodUser('admin-finance');

    // This is account takeover, against a staff login with no MFA.
    $this->actingAs($finance)->post('/admin/distributors/1/set-password')->assertForbidden();
    $this->actingAs($finance)->post('/admin/distributors/1/password-reset')->assertForbidden();
});

it('SOD-03: admin-finance cannot rewrite a distributor identity', function () {
    $finance = sodUser('admin-finance');

    $this->actingAs($finance)->get('/admin/distributors/1/edit')->assertForbidden();
    $this->actingAs($finance)->post('/admin/distributors/1/identity')->assertForbidden();
});

it('SOD-04: admin-finance cannot write a platform setting', function () {
    $finance = sodUser('admin-finance');

    // Reading is monitoring and stays open; writing changes what the platform
    // pays or who it lets in.
    $this->actingAs($finance)->get('/admin/settings')->assertOk();
    $this->actingAs($finance)->post('/admin/settings/commerce.checkout.enabled')->assertForbidden();
    $this->actingAs($finance)->post('/admin/settings/age-rules')->assertForbidden();
});

it('SOD-05: admin-finance cannot cancel an order', function () {
    $finance = sodUser('admin-finance');
    $order = sodOrder();

    $this->actingAs($finance)->post("/admin/commerce/orders/{$order->id}/cancel")->assertForbidden();
});

it('SOD-06: admin-operations can still do its own job', function () {
    $operations = sodUser('admin-operations');

    // A gate that stops everybody is not separation of duties, it is an outage.
    // These must NOT be 403 — a missing record is a 404 and that is fine.
    $order = sodOrder();

    expect($this->actingAs($operations)->get('/admin/kyc')->status())->toBe(200)
        ->and($this->actingAs($operations)->post("/admin/commerce/orders/{$order->id}/cancel")->status())->not->toBe(403);
});

it('SOD-07: admin-compliance still cannot record finance actions', function () {
    $compliance = sodUser('admin-compliance');

    $order = sodOrder();
    $return = ReturnRequest::create([
        'rma_no' => 'RMA-SOD-'.random_int(10000, 99999),
        'order_id' => $order->id,
        'order_item_id' => null,
        'qty' => null,
        'reason' => ReturnRequest::REASON_COOLING_OFF,
        'opened_by_customer_id' => $order->customer_id,
        'status' => ReturnRequest::STATUS_OPENED,
    ]);

    // The original R-17 rule, still holding.
    $this->actingAs($compliance)->post("/admin/returns/{$return->id}/approve")->assertForbidden();
});

it('SOD-08: no scoped role can change credentials — admin and developer only', function () {
    foreach (['admin-finance', 'admin-operations', 'admin-compliance'] as $role) {
        $this->actingAs(sodUser($role))
            ->post('/admin/distributors/1/set-password')
            ->assertForbidden();
    }

    // And the super roles can, or the feature would be unreachable.
    expect($this->actingAs(sodUser('admin'))->post('/admin/distributors/1/set-password')->status())
        ->not->toBe(403);
});
