<?php

declare(strict_types=1);

use App\Modules\Commerce\Models\Coupon;
use App\Modules\Compliance\Models\AuditLog;
use App\Modules\Identity\Models\User;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

function acpnAdmin(): User
{
    Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
    $admin = User::create([
        'full_name' => 'Coupon Admin',
        'email' => 'acpn-admin-'.uniqid().'@example.com',
        'phone_e164' => '+9180000'.rand(10000, 99999),
        'password_hash' => bcrypt('Adm1n!Pass#2026Test'),
        'password_set_at' => now(),
        'status' => 'active',
        'email_verified_at' => now(),
    ]);
    $admin->assignRole('admin');

    return $admin;
}

it('ACPN-01: admin creates a fixed coupon — rupee inputs stored as paise', function (): void {
    $this->actingAs(acpnAdmin())
        ->withoutMiddleware(PreventRequestForgery::class)
        ->post(route('admin.commerce.coupons.store'), [
            'code' => 'save150',
            'type' => 'fixed',
            'value' => '150',          // ₹150
            'min_purchase' => '500',    // ₹500
            'scope' => 'all',
            'status' => 'active',
        ])
        ->assertRedirect();

    $coupon = Coupon::where('code', 'SAVE150')->first(); // upper-cased
    expect($coupon)->not->toBeNull();
    expect($coupon->type)->toBe('fixed');
    expect($coupon->value)->toBe(15000);                 // ₹150 → paise
    expect($coupon->min_purchase_paise)->toBe(50000);    // ₹500 → paise
    expect($coupon->max_discount_paise)->toBeNull();
    expect(AuditLog::where('action', 'commerce.coupon.created')->count())->toBe(1);
});

it('ACPN-02: admin creates a percent coupon with a max-discount cap', function (): void {
    $this->actingAs(acpnAdmin())
        ->withoutMiddleware(PreventRequestForgery::class)
        ->post(route('admin.commerce.coupons.store'), [
            'code' => 'WELCOME10',
            'type' => 'percent',
            'value' => '10',           // 10%
            'max_discount' => '200',    // cap ₹200
            'scope' => 'all',
            'status' => 'active',
        ])
        ->assertRedirect();

    $coupon = Coupon::where('code', 'WELCOME10')->first();
    expect($coupon->value)->toBe(10);                    // percent stored as-is
    expect($coupon->max_discount_paise)->toBe(20000);    // ₹200 → paise
});

it('ACPN-03: a non-admin cannot reach coupon admin', function (): void {
    $user = User::create([
        'full_name' => 'Plain', 'email' => 'plain-'.uniqid().'@example.com',
        'phone_e164' => '+9181111'.rand(10000, 99999),
        'password_hash' => bcrypt('User!Pass#2026Test'), 'password_set_at' => now(),
        'status' => 'active', 'email_verified_at' => now(),
    ]);

    $this->actingAs($user)->get(route('admin.commerce.coupons.create'))->assertForbidden();
});
