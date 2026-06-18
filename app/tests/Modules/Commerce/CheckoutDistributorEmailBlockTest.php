<?php

declare(strict_types=1);

use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Commerce\Models\Cart;
use App\Modules\Commerce\Models\CartItem;
use App\Modules\Identity\Models\User;
use Database\Seeders\LedgerAccountSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/**
 * A guest or another customer must not be able to place an order using a
 * registered user's (especially a distributor's) email address. Doing so
 * would collide with the unique email_hash index on the customers table and
 * cause a 500; the controller must intercept it with a user-facing error.
 */
beforeEach(function (): void {
    $this->seed(LedgerAccountSeeder::class);
    DB::table('settings')->updateOrInsert(['key' => 'commerce.checkout.enabled'], ['value' => 'true', 'version' => 1, 'updated_at' => now()]);
    DB::table('settings')->updateOrInsert(['key' => 'commerce.guest_checkout.enabled'], ['value' => 'true', 'version' => 1, 'updated_at' => now()]);
    DB::table('settings')->updateOrInsert(['key' => 'payments.cod.enabled'], ['value' => 'true', 'version' => 1, 'updated_at' => now()]);
    DB::table('settings')->updateOrInsert(['key' => 'payments.gateway.stub.enabled'], ['value' => 'false', 'version' => 1, 'updated_at' => now()]);
});

function cdebDistributor(): User
{
    $user = User::create([
        'full_name' => 'Test Distributor',
        'email' => 'dist-'.uniqid().'@cdeb.test',
        'phone_e164' => '+91'.str_pad((string) rand(7000000000, 9999999999), 10, '0'),
        'password_hash' => bcrypt('x'),
        'status' => 'active',
    ]);

    disableTestForeignKeys();
    try {
        $id = DB::table('distributors')->insertGetId([
            'user_id' => $user->id,
            'adn' => 'ADN'.random_int(10000, 99999),
            'pan_hash' => bin2hex(random_bytes(16)),
            'pan_last4' => '0000',
            'bank_account_enc' => 'stub',
            'bank_ifsc' => 'SBIN0000000',
            'sponsor_id' => 0,
            'placement_parent_id' => 0,
            'side_chosen_by' => 'referral_default',
            'depth' => 0,
            'effective_date' => now()->format('Y-m-d H:i:s.v'),
            'cooling_off_end_at' => now()->addDays(30)->format('Y-m-d H:i:s.v'),
            'state' => 'TS',
            'is_primary_couple' => 0,
            'created_at' => now()->format('Y-m-d H:i:s.v'),
            'updated_at' => now()->format('Y-m-d H:i:s.v'),
        ]);
        DB::table('distributors')->where('id', $id)->update(['sponsor_id' => $id, 'placement_parent_id' => $id]);
    } finally {
        enableTestForeignKeys();
    }

    return $user->fresh();
}

function cdebCartWithItem(): Cart
{
    $n = random_int(10000, 99999);
    $product = Product::create(['sku' => "CDEB-{$n}", 'slug' => "cdeb-{$n}", 'name' => "CDEB {$n}", 'hsn_code' => '3004', 'status' => 'active']);
    $variant = ProductVariant::create([
        'product_id' => $product->id, 'variant_sku' => "CDEB-{$n}-V1", 'name' => 'Default',
        'mrp_paise' => 50000, 'sale_price_paise' => 50000, 'gst_rate_bp' => 1800,
        'inventory_policy' => 'no_track', 'status' => 'active',
    ]);
    $cart = Cart::create(['anonymous_key' => "cdebk{$n}", 'expires_at' => now()->addDay()]);
    CartItem::create([
        'cart_id' => $cart->id, 'product_variant_id' => $variant->id, 'qty' => 1,
        'unit_price_paise' => 50000, 'bv_paise' => 25000, 'gst_rate_bp' => 1800,
    ]);

    return $cart;
}

function cdebPayload(string $email): array
{
    return [
        'buyer_name' => 'Test Customer',
        'buyer_email' => $email,
        'buyer_phone' => '9876543210',
        'ship_line1' => '1 Main Street',
        'ship_city' => 'Hyderabad',
        'ship_state' => 'Telangana',
        'ship_pincode' => '500001',
        'billing_same' => '1',
        'payment_method' => 'cod',
        'accept_terms' => '1',
    ];
}

it('blocks a guest placing an order with a registered distributor\'s email', function (): void {
    $distributor = cdebDistributor();

    $response = $this->post(route('shop.checkout.place'), cdebPayload($distributor->email));

    $response->assertRedirect();
    $response->assertSessionHasErrors('buyer_email');
    expect(session('errors')?->first('buyer_email'))->toContain('Direct Seller');
});

it('blocks a guest placing an order with a registered non-distributor user\'s email', function (): void {
    $member = User::create([
        'full_name' => 'Other Member',
        'email' => 'member-'.uniqid().'@cdeb.test',
        'phone_e164' => '+917000000001',
        'password_hash' => bcrypt('x'),
        'status' => 'active',
    ]);

    $response = $this->post(route('shop.checkout.place'), cdebPayload($member->email));

    $response->assertRedirect();
    $response->assertSessionHasErrors('buyer_email');
    expect(session('errors')?->first('buyer_email'))->toContain('registered with an arovolife account');
});

it('does not block a logged-in user from using their own email', function (): void {
    $member = User::create([
        'full_name' => 'Self Member',
        'email' => 'self-'.uniqid().'@cdeb.test',
        'phone_e164' => '+917000000002',
        'password_hash' => bcrypt('x'),
        'status' => 'active',
    ]);

    // The guard queries User WHERE email = buyer_email AND id != auth_user_id.
    // For a user's own email, no conflict row is found, so the guard never fires.
    // Without a cart, the response will be an error but NOT a buyer_email error.
    $this->actingAs($member)
        ->post(route('shop.checkout.place'), cdebPayload($member->email));

    // Must NOT have a buyer_email session error — the guard must have passed.
    $errors = session('errors');
    $hasBuyerEmailError = $errors !== null && $errors->has('buyer_email');
    expect($hasBuyerEmailError)->toBeFalse();
});
