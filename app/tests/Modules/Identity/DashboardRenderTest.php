<?php

declare(strict_types=1);

/**
 * Smoke tests that the distributor dashboard actually RENDERS. A missing
 * render test let a Blade compile error (an inline @php(...) with a method
 * call) ship a 500 on /dashboard. DSH-01 exercises the account-status pill
 * (rendered for every user); DSH-02 exercises the distributor ID-card panel.
 */

use App\Modules\Identity\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

uses(RefreshDatabase::class);

function dshUser(string $status = 'active'): User
{
    return User::create([
        'full_name' => 'Dash User',
        'email' => 'dsh-'.uniqid().'@example.com',
        'phone_e164' => '+91955'.str_pad((string) random_int(0, 9999999), 7, '0', STR_PAD_LEFT),
        'password_hash' => Hash::make('dsh-test-pwd-2026'),
        'password_set_at' => now(),
        'status' => $status,
        'email_verified_at' => now(),
    ]);
}

function dshDistributor(User $user): int
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
    DB::table('genealogy_closure')->insert(['ancestor_id' => $id, 'descendant_id' => $id, 'depth' => 0]);

    return $id;
}

it('DSH-01: dashboard renders for an authenticated user with the account-status pill', function () {
    $user = dshUser('active');

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertSee('Status:', false)
        ->assertSee('Active', false);
});

it('DSH-02: dashboard renders the ID-card panel for a distributor', function () {
    $user = dshUser('active');
    dshDistributor($user);

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertSee('Your ADN', false)
        ->assertSee('Status', false);
});
