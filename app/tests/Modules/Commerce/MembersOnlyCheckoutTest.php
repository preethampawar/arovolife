<?php

declare(strict_types=1);

use App\Modules\Identity\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/**
 * Members-only buying (partner spec 2026-05-31): when
 * `commerce.guest_checkout.enabled` is OFF, an unauthenticated visitor is sent
 * to login before checkout; browsing the storefront stays open. When ON,
 * guests may check out as before.
 */
function mocSetting(string $key, string $value): void
{
    DB::table('settings')->updateOrInsert(
        ['key' => $key],
        ['value' => $value, 'version' => 1, 'updated_at' => now()],
    );
}

function mocUser(): User
{
    return User::create([
        'full_name' => 'Member Buyer',
        'email' => 'moc-'.uniqid().'@test.com',
        'phone_e164' => '+91'.str_pad((string) rand(7000000000, 9999999999), 10, '0'),
        'password_hash' => bcrypt('x'),
        'status' => 'active',
    ]);
}

beforeEach(function (): void {
    mocSetting('commerce.checkout.enabled', 'true');
});

it('MOC-01: guest checkout OFF + not logged in → checkout redirects to login', function (): void {
    mocSetting('commerce.guest_checkout.enabled', 'false');

    $this->get(route('shop.checkout'))->assertRedirect(route('login'));
});

it('MOC-02: guest checkout OFF + logged in → passes the members gate (no login redirect)', function (): void {
    mocSetting('commerce.guest_checkout.enabled', 'false');

    // Authenticated, but the cart is empty → the controller redirects to the
    // shop (NOT to login), proving the members-only gate was cleared.
    $this->actingAs(mocUser())
        ->get(route('shop.checkout'))
        ->assertRedirect(route('shop.index'));
});

it('MOC-03: guest checkout ON → an anonymous visitor is not gated', function (): void {
    mocSetting('commerce.guest_checkout.enabled', 'true');

    // No login redirect; empty cart sends the guest back to the shop.
    $this->get(route('shop.checkout'))->assertRedirect(route('shop.index'));
});
