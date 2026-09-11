<?php

declare(strict_types=1);

/**
 * The Direct Seller Application print page must mask a 10-character PAN the
 * same way /profile does — `XXXXXX` + last 4 — not an 11th trailing X (F73(e)).
 */

use App\Modules\Identity\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

uses(RefreshDatabase::class);

function dsaUser(): User
{
    return User::create([
        'full_name' => 'DSA Test',
        'email' => 'dsa-'.uniqid().'@example.com',
        'phone_e164' => '+91957'.str_pad((string) random_int(0, 9999999), 7, '0', STR_PAD_LEFT),
        'password_hash' => Hash::make('dsa-test-pwd-2026'),
        'password_set_at' => now(),
        'status' => 'active',
        'email_verified_at' => now(),
    ]);
}

function dsaDistributor(User $user): int
{
    disableTestForeignKeys();
    try {
        $id = DB::table('distributors')->insertGetId([
            'user_id' => $user->id,
            'adn' => (string) random_int(100000000, 999999999),
            'pan_hash' => random_bytes(32),
            'pan_last4' => '8994',
            'aadhaar_last4' => '0000',
            'bank_account_enc' => 'stub',
            'bank_ifsc' => 'SBIN0000000',
            'sponsor_id' => 0,
            'placement_parent_id' => 0,
            'placement_side' => null,
            'side_chosen_by' => 'referral_default',
            'depth' => 0,
            'effective_date' => now()->format('Y-m-d H:i:s.v'),
            'cooling_off_end_at' => now()->copy()->addDays(30)->format('Y-m-d H:i:s.v'),
            'state' => 'TS',
            'is_primary_couple' => 0,
            'created_at' => now()->format('Y-m-d H:i:s.v'),
            'updated_at' => now()->format('Y-m-d H:i:s.v'),
        ]);
        DB::table('distributors')->where('id', $id)->update(['sponsor_id' => $id, 'placement_parent_id' => $id]);
    } finally {
        enableTestForeignKeys();
    }

    return $id;
}

it('masks a 10-character PAN, matching /profile — not 11 (F73)', function (): void {
    $user = dsaUser();
    dsaDistributor($user);

    $this->actingAs($user)
        ->get(route('direct-seller-application.show'))
        ->assertOk()
        ->assertSee('XXXXXX8994')
        ->assertDontSee('XXXXXX8994X');
});
