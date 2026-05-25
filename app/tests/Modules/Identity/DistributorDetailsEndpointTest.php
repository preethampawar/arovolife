<?php

declare(strict_types=1);

/**
 * Tests for the /distributors/{distributor}/id-card-panel endpoint that
 * powers the tree-view "Details" modal.
 *
 * The endpoint returns Blade-rendered HTML; authorization mirrors the
 * existing tree-pivot rules — viewer can see self, descendants, or any
 * distributor if the viewer is an admin. Anything else is 403.
 */

use App\Modules\Genealogy\Services\DTOs\PlaceDistributorInput;
use App\Modules\Genealogy\Services\PlacementEngine;
use App\Modules\Identity\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

function ddeUser(string $key): User
{
    return User::create([
        'full_name' => 'DDE '.$key,
        'email' => 'dde-'.$key.'-'.uniqid().'@example.com',
        'phone_e164' => '+91955'.str_pad((string) random_int(0, 9999999), 7, '0', STR_PAD_LEFT),
        'password_hash' => Hash::make('dde-test-pwd-2026'),
        'password_set_at' => now(),
        'status' => 'active',
        'email_verified_at' => now(),
    ]);
}

function ddeSeedRoot(int $userId): int
{
    disableTestForeignKeys();
    try {
        $now = now()->format('Y-m-d H:i:s.v');
        $id = DB::table('distributors')->insertGetId([
            'user_id' => $userId, 'adn' => (string) random_int(100000001, 999999999),
            'pan_hash' => random_bytes(32), 'pan_last4' => '0000',
            'bank_account_enc' => 'stub', 'bank_ifsc' => 'HDFC0001234',
            'sponsor_id' => 0, 'placement_parent_id' => 0, 'placement_side' => null,
            'side_chosen_by' => 'referral_default', 'depth' => 0,
            'effective_date' => $now, 'cooling_off_end_at' => $now,
            'state' => 'TS', 'is_primary_couple' => 0,
            'created_at' => $now, 'updated_at' => $now,
        ]);
        DB::table('distributors')->where('id', $id)->update(['sponsor_id' => $id, 'placement_parent_id' => $id]);
    } finally {
        enableTestForeignKeys();
    }
    DB::table('genealogy_closure')->insert(['ancestor_id' => $id, 'descendant_id' => $id, 'depth' => 0]);

    return $id;
}

function ddePlace(int $sponsorId, User $user): int
{
    return app(PlacementEngine::class)->place(new PlaceDistributorInput(
        userId: $user->id, sponsorId: $sponsorId, placementId: $sponsorId,
        panHash: random_bytes(32), panLast4: '1234',
        bankAccountEnc: 'stub', bankIfsc: 'HDFC0001234', state: 'TS',
    ))->distributorId;
}

it('DDE-01: viewer can see their own id-card panel', function (): void {
    $rootUser = ddeUser('root');
    $rootId = ddeSeedRoot($rootUser->id);

    $response = $this->actingAs($rootUser->refresh())
        ->get("/distributors/{$rootId}/id-card-panel");

    $response->assertOk();
    $response->assertSee('Region', false);
    $response->assertSee('India', false);
    $response->assertSee('Status', false);
});

it('DDE-02: viewer can see a descendant in their binary downline', function (): void {
    $rootUser = ddeUser('root');
    $rootId = ddeSeedRoot($rootUser->id);
    $childUser = ddeUser('child');
    $childId = ddePlace($rootId, $childUser);

    $response = $this->actingAs($rootUser->refresh())
        ->get("/distributors/{$childId}/id-card-panel");

    $response->assertOk();
    $childAdn = DB::table('distributors')->where('id', $childId)->value('adn');
    $response->assertSee($childAdn);
});

it('DDE-03: viewer is blocked from a distributor outside their downline (403)', function (): void {
    $aliceUser = ddeUser('alice');
    $aliceId = ddeSeedRoot($aliceUser->id);
    $bobUser = ddeUser('bob');
    $bobId = ddeSeedRoot($bobUser->id); // unrelated root — not in Alice's downline

    $response = $this->actingAs($aliceUser->refresh())
        ->get("/distributors/{$bobId}/id-card-panel");

    $response->assertStatus(403);
});

it('DDE-04: admin can see any distributor regardless of downline', function (): void {
    $adminRole = Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
    $adminUser = ddeUser('admin');
    $adminUser->syncRoles([$adminRole]);

    $bobUser = ddeUser('bob');
    $bobId = ddeSeedRoot($bobUser->id);

    $response = $this->actingAs($adminUser->refresh())
        ->get("/distributors/{$bobId}/id-card-panel");

    $response->assertOk();
});

it('DDE-05: unauthenticated request redirects to login', function (): void {
    $rootUser = ddeUser('root');
    $rootId = ddeSeedRoot($rootUser->id);

    $response = $this->get("/distributors/{$rootId}/id-card-panel");

    $response->assertRedirect(route('login'));
});
