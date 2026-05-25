<?php

declare(strict_types=1);

use App\Modules\Identity\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

function tcsAdmin(): User
{
    Role::findOrCreate('admin', 'web');
    $u = User::create([
        'email' => 'tcs-admin-'.rand(1000, 9999).'@test.com',
        'phone_e164' => '+91'.str_pad((string) rand(7000000000, 9999999999), 10, '0'),
        'password_hash' => bcrypt('x'),
        'status' => 'active',
    ]);
    $u->assignRole('admin');

    return $u;
}

function tcsSeedClosed(string $status, ?string $closureType): int
{
    $u = User::create([
        'email' => 'tcs-d-'.rand(1000, 9999).'@test.com',
        'phone_e164' => '+91'.str_pad((string) rand(7000000000, 9999999999), 10, '0'),
        'password_hash' => bcrypt('x'),
        'status' => $status,
        'closure_type' => $closureType,
    ]);

    disableTestForeignKeys();
    try {
        $id = DB::table('distributors')->insertGetId([
            'user_id' => $u->id,
            'adn' => (string) rand(100000000, 999999999),
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
            'cooling_off_end_at' => now()->addDays(30)->format('Y-m-d H:i:s.v'),
            'state' => 'TS',
            'is_primary_couple' => 0,
            'status' => 'inactive',
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

it('TCS-01: a cooling-off self-cancellation shows "Cancelled" on the tree card', function () {
    $admin = tcsAdmin();
    $id = tcsSeedClosed('terminated', 'cooling_off_cancellation');

    $this->actingAs($admin)
        ->get(route('admin.tree.show', $id))
        ->assertOk()
        ->assertSee('Cancelled')
        ->assertDontSee('Verification');
});

it('TCS-02: an admin termination shows "Terminated" on the tree card', function () {
    $admin = tcsAdmin();
    $id = tcsSeedClosed('terminated', 'admin_termination');

    $this->actingAs($admin)
        ->get(route('admin.tree.show', $id))
        ->assertOk()
        ->assertSee('Terminated');
});
