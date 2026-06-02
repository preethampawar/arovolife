<?php

declare(strict_types=1);

use App\Modules\Admin\Http\Controllers\AdminSettingsController;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

function codSetting(string $key, string $value): void
{
    DB::table('settings')->updateOrInsert(['key' => $key], ['value' => $value, 'version' => 1, 'updated_at' => now()]);
}

beforeEach(function (): void {
    codSetting('commerce.storefront.enabled', 'true');
    codSetting('commerce.checkout.enabled', 'true');
    codSetting('commerce.guest_checkout.enabled', 'true');
    codSetting('payments.gateway.stub.enabled', 'true'); // online available
});

it('exposes the COD toggle and shipping charges in the admin settings registry', function (): void {
    $registry = AdminSettingsController::registry();

    expect($registry)->toHaveKey('payments.cod.enabled')
        ->and($registry['payments.cod.enabled']['group'])->toBe('payments')
        ->and($registry['payments.cod.enabled']['default'])->toBe('false')
        ->and($registry)->toHaveKey('commerce.shipping.fee_rupees')
        ->and($registry)->toHaveKey('commerce.shipping.free_threshold_rupees')
        ->and(AdminSettingsController::groups())->toHaveKey('payments');
});

it('rejects a COD checkout submission when the flag is OFF (validation runs before the cart)', function (): void {
    codSetting('payments.cod.enabled', 'false');

    $this->post(route('shop.checkout.place'), [
        'buyer_name' => 'Cod Buyer',
        'buyer_email' => 'cod-'.uniqid().'@test.com',
        'buyer_phone' => '9800000000',
        'ship_line1' => '1 Test St',
        'ship_city' => 'Pune',
        'ship_state' => 'MH',
        'ship_pincode' => '411001',
        'payment_method' => 'cod',
        'billing_same' => '1',
        'accept_terms' => '1',
    ])->assertSessionHasErrors('payment_method');
});
