<?php

declare(strict_types=1);

/**
 * The membership card back must speak to an independent contractor, not an
 * employee — "while in office" and "HR's notice" contradict the DSA §5
 * principal-to-principal framing printed on the sibling Direct Seller
 * Application page (F68 / F73(d)).
 */

use App\Modules\Identity\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

uses(RefreshDatabase::class);

function mccUser(): User
{
    return User::create([
        'full_name' => 'Card Test',
        'email' => 'mcc-'.uniqid().'@example.com',
        'phone_e164' => '+91956'.str_pad((string) random_int(0, 9999999), 7, '0', STR_PAD_LEFT),
        'password_hash' => Hash::make('mcc-test-pwd-2026'),
        'password_set_at' => now(),
        'status' => 'active',
        'email_verified_at' => now(),
    ]);
}

function mccDistributor(User $user): int
{
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

it('the membership card back never uses employee wording (F68/F73)', function (): void {
    $user = mccUser();
    mccDistributor($user);

    $this->actingAs($user)
        ->get(route('membership-card.show'))
        ->assertOk()
        ->assertSee("Support team's notice", false)
        ->assertDontSee('while in office')
        ->assertDontSee("HR's notice", false);
});
