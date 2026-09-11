<?php

declare(strict_types=1);

use App\Modules\Commerce\Models\CustomerAddress;
use App\Modules\Commerce\Services\ShippingService;
use App\Modules\Identity\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

function areaSetting(string $value): void
{
    DB::table('settings')->updateOrInsert(
        ['key' => 'commerce.shipping.india_mainland_only'],
        ['value' => $value, 'version' => 1, 'updated_at' => now(), 'created_at' => now()],
    );
}

function areaUser(): User
{
    return User::create([
        'full_name' => 'Area User '.random_int(1000, 9999),
        'email' => 'area-'.uniqid().'@test.com',
        'phone_e164' => '+91'.str_pad((string) rand(7000000000, 9999999999), 10, '0'),
        'password_hash' => bcrypt('x'),
        'password_set_at' => now(),
        'status' => 'active',
    ]);
}

/** @param array<string, string> $overrides */
function areaPayload(array $overrides = []): array
{
    return array_merge([
        'label' => 'Home',
        'name' => 'Ravi Kumar',
        'phone' => '9876543210',
        'line1' => '12 MG Road',
        'city' => 'Pune',
        'state' => 'MH',
        'pincode' => '411001',
    ], $overrides);
}

it('F57-01: refuses Andaman & Nicobar and Lakshadweep pincodes while mainland-only is on', function (): void {
    areaSetting('true');
    $shipping = app(ShippingService::class);

    expect($shipping->servesPincode('744101'))->toBeFalse()  // Port Blair
        ->and($shipping->servesPincode('744999'))->toBeFalse()
        ->and($shipping->servesPincode('682551'))->toBeFalse() // Kavaratti
        ->and($shipping->servesPincode('682559'))->toBeFalse();
});

it('F57-02: serves mainland pincodes, including Kochi either side of the Lakshadweep range', function (): void {
    areaSetting('true');
    $shipping = app(ShippingService::class);

    expect($shipping->servesPincode('411001'))->toBeTrue()
        ->and($shipping->servesPincode('682550'))->toBeTrue()
        ->and($shipping->servesPincode('682560'))->toBeTrue()
        ->and($shipping->servesPincode('743999'))->toBeTrue()
        ->and($shipping->servesPincode('745001'))->toBeTrue();
});

it('F57-03: serves the islands once the admin turns mainland-only off', function (): void {
    areaSetting('false');

    expect(app(ShippingService::class)->servesPincode('744101'))->toBeTrue();
});

it('F57-04: defaults to mainland-only when the setting row is missing', function (): void {
    DB::table('settings')->where('key', 'commerce.shipping.india_mainland_only')->delete();

    expect(app(ShippingService::class)->mainlandOnly())->toBeTrue()
        ->and(app(ShippingService::class)->servesPincode('744101'))->toBeFalse();
});

it('F57-05: saving an island address is rejected with a clear message', function (): void {
    areaSetting('true');

    $this->actingAs(areaUser())
        ->post(route('addresses.store'), areaPayload(['pincode' => '744101', 'city' => 'Port Blair', 'state' => 'AN']))
        ->assertSessionHasErrors(['pincode' => 'We cannot deliver to this pincode. arovolife currently ships to mainland India only — the Andaman & Nicobar Islands and Lakshadweep are not served. Please use a mainland delivery address or contact support.']);

    expect(CustomerAddress::count())->toBe(0);
});

it('F57-06: checkout refuses an island delivery pincode', function (): void {
    areaSetting('true');
    foreach (['commerce.checkout.enabled', 'commerce.guest_checkout.enabled', 'payments.gateway.stub.enabled'] as $key) {
        DB::table('settings')->updateOrInsert(['key' => $key], ['value' => 'true', 'version' => 1, 'updated_at' => now(), 'created_at' => now()]);
    }

    $this->post(route('shop.checkout.place'), [
        'buyer_name' => 'Test Customer',
        'buyer_email' => 'island-'.uniqid().'@test.com',
        'buyer_phone' => '9876543210',
        'ship_line1' => '1 Marine Hill',
        'ship_city' => 'Port Blair',
        'ship_state' => 'Andaman and Nicobar Islands',
        'ship_pincode' => '744101',
        'billing_same' => '1',
        'delivery_type' => 'ship',
        'payment_method' => 'online',
        'accept_terms' => '1',
    ])->assertSessionHasErrors('ship_pincode');

    expect(session('errors')?->first('ship_pincode'))->toContain('mainland India only');
});

it('F57-07: a mainland address still saves', function (): void {
    areaSetting('true');

    $this->actingAs(areaUser())
        ->post(route('addresses.store'), areaPayload())
        ->assertSessionHasNoErrors();

    expect(CustomerAddress::count())->toBe(1);
});
