<?php

declare(strict_types=1);

use App\Modules\Commerce\Models\Customer;
use App\Modules\Commerce\Models\CustomerAddress;
use App\Modules\Identity\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function abUser(): User
{
    return User::create([
        'full_name' => 'Addr User '.random_int(1000, 9999),
        'email' => 'addr-'.uniqid().'@test.com',
        'phone_e164' => '+91'.str_pad((string) rand(7000000000, 9999999999), 10, '0'),
        'password_hash' => bcrypt('x'),
        'password_set_at' => now(),
        'status' => 'active',
    ]);
}

/**
 * @param  array<string, string>  $overrides
 * @return array<string, string> a valid address payload
 */
function abPayload(array $overrides = []): array
{
    return array_merge([
        'label' => 'Home',
        'name' => 'Ravi Kumar',
        'phone' => '9876543210',
        'line1' => '12 MG Road',
        'line2' => 'Near Park',
        'city' => 'Pune',
        'state' => 'MH',
        'pincode' => '411001',
    ], $overrides);
}

it('AB-01: the address book page loads for a fresh user (empty state)', function (): void {
    $this->actingAs(abUser())->get(route('addresses.index'))
        ->assertOk()
        ->assertSee('My Addresses')
        ->assertSee('saved any addresses yet');
});

it('AB-02: storing the first address creates the customer, the address, and makes it default', function (): void {
    $user = abUser();

    $this->actingAs($user)->post(route('addresses.store'), abPayload())
        ->assertRedirect(route('addresses.index'));

    $customer = Customer::where('user_id', $user->id)->first();
    expect($customer)->not->toBeNull();

    $addr = CustomerAddress::where('customer_id', $customer->id)->first();
    expect($addr->label)->toBe('Home')
        ->and($addr->kind)->toBe('shipping')
        ->and($addr->phone_e164)->toBe('+919876543210') // +91 prefixed
        ->and($addr->is_default)->toBeTrue();            // first = default
});

it('AB-03: a second address is not default; set-default flips it', function (): void {
    $user = abUser();
    $this->actingAs($user)->post(route('addresses.store'), abPayload(['label' => 'Home']));
    $this->actingAs($user)->post(route('addresses.store'), abPayload(['label' => 'Work', 'line1' => '9 Office Park']));

    $customer = Customer::where('user_id', $user->id)->first();
    $home = CustomerAddress::where('customer_id', $customer->id)->where('label', 'Home')->first();
    $work = CustomerAddress::where('customer_id', $customer->id)->where('label', 'Work')->first();

    expect($home->is_default)->toBeTrue()
        ->and($work->is_default)->toBeFalse();

    $this->actingAs($user)->post(route('addresses.set-default', $work))->assertRedirect();

    expect($work->fresh()->is_default)->toBeTrue()
        ->and($home->fresh()->is_default)->toBeFalse();
});

it('AB-04: updating an address edits its fields', function (): void {
    $user = abUser();
    $this->actingAs($user)->post(route('addresses.store'), abPayload());
    $addr = CustomerAddress::firstWhere('label', 'Home');

    $this->actingAs($user)->patch(route('addresses.update', $addr), abPayload(['city' => 'Mumbai', 'label' => 'Home']))
        ->assertRedirect(route('addresses.index'));

    expect($addr->fresh()->city)->toBe('Mumbai');
});

it('AB-05: deleting the default promotes another address to default', function (): void {
    $user = abUser();
    $this->actingAs($user)->post(route('addresses.store'), abPayload(['label' => 'Home']));
    $this->actingAs($user)->post(route('addresses.store'), abPayload(['label' => 'Work', 'line1' => '9 Office Park']));
    $customer = Customer::where('user_id', $user->id)->first();
    $home = CustomerAddress::where('customer_id', $customer->id)->where('label', 'Home')->first(); // default

    $this->actingAs($user)->delete(route('addresses.destroy', $home))->assertRedirect();

    expect(CustomerAddress::find($home->id))->toBeNull();
    expect(CustomerAddress::where('customer_id', $customer->id)->where('is_default', true)->count())->toBe(1);
});

it('AB-06: a user cannot edit or delete another user\'s address (404)', function (): void {
    $owner = abUser();
    $this->actingAs($owner)->post(route('addresses.store'), abPayload());
    $addr = CustomerAddress::firstWhere('label', 'Home');

    $attacker = abUser();
    $this->actingAs($attacker)->patch(route('addresses.update', $addr), abPayload(['city' => 'Hacked']))->assertNotFound();
    $this->actingAs($attacker)->delete(route('addresses.destroy', $addr))->assertNotFound();
    $this->actingAs($attacker)->post(route('addresses.set-default', $addr))->assertNotFound();

    expect($addr->fresh()->city)->toBe('Pune'); // untouched
});

it('AB-07: rejects an invalid phone / pincode', function (): void {
    $user = abUser();
    $this->actingAs($user)->post(route('addresses.store'), abPayload(['phone' => '123', 'pincode' => '99']))
        ->assertSessionHasErrors(['phone', 'pincode']);

    expect(CustomerAddress::count())->toBe(0);
});
