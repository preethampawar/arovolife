<?php

declare(strict_types=1);

use App\Modules\Identity\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

/**
 * Seed a distributor row + its closure rows. Returns the distributor id.
 * Mirrors the closure-seeding pattern used in ApproveLineChangeTest, with
 * unique local helper names (ts*) to avoid Pest global function collisions.
 */
function tsSeed(int $userId, ?int $parentId = null): int
{
    disableTestForeignKeys();
    try {
        $depth = 0;
        if ($parentId !== null) {
            $depth = (int) DB::table('distributors')->where('id', $parentId)->value('depth') + 1;
        }
        $id = DB::table('distributors')->insertGetId([
            'user_id' => $userId,
            'adn' => (string) rand(100000000, 999999999),
            'pan_hash' => random_bytes(32),
            'pan_last4' => '0000',
            'bank_account_enc' => 'stub',
            'bank_ifsc' => 'SBIN0000000',
            'sponsor_id' => $parentId ?? 0,
            'placement_parent_id' => $parentId ?? 0,
            'placement_side' => $parentId !== null ? 'L' : null,
            'side_chosen_by' => 'referral_default',
            'depth' => $depth,
            'effective_date' => now()->format('Y-m-d H:i:s.v'),
            'cooling_off_end_at' => now()->copy()->addDays(30)->format('Y-m-d H:i:s.v'),
            'state' => 'TS',
            'is_primary_couple' => 0,
            'created_at' => now()->format('Y-m-d H:i:s.v'),
            'updated_at' => now()->format('Y-m-d H:i:s.v'),
        ]);
        if ($parentId === null) {
            DB::table('distributors')->where('id', $id)->update(['sponsor_id' => $id, 'placement_parent_id' => $id]);
        }
    } finally {
        enableTestForeignKeys();
    }

    DB::table('genealogy_closure')->insert(['ancestor_id' => $id, 'descendant_id' => $id, 'depth' => 0]);
    if ($parentId !== null) {
        $ancestors = DB::table('genealogy_closure')->where('descendant_id', $parentId)->get(['ancestor_id', 'depth']);
        foreach ($ancestors as $a) {
            DB::table('genealogy_closure')->insert([
                'ancestor_id' => $a->ancestor_id, 'descendant_id' => $id, 'depth' => $a->depth + 1,
            ]);
        }
    }

    return $id;
}

function tsUser(string $tag, ?string $name = null, ?string $email = null, ?string $phone = null): User
{
    return User::create([
        'full_name' => $name ?? ('User '.$tag),
        'email' => $email ?? ("ts-{$tag}-".rand(1000, 9999).'@test.com'),
        'phone_e164' => $phone ?? ('+91'.str_pad((string) rand(7000000000, 9999999999), 10, '0')),
        'password_hash' => bcrypt('x'),
        'status' => 'active',
    ]);
}

function tsAdmin(): User
{
    Role::findOrCreate('admin', 'web');
    $u = tsUser('admin');
    $u->assignRole('admin');

    return $u;
}

/** Reads the adn for a distributor id. */
function tsAdn(int $id): string
{
    return (string) DB::table('distributors')->where('id', $id)->value('adn');
}

it('TS-01: distributor finds a descendant by ADN', function () {
    $rootUser = tsUser('root');
    $rootId = tsSeed($rootUser->id);
    $childId = tsSeed(tsUser('child')->id, parentId: $rootId);
    $childAdn = tsAdn($childId);

    $this->actingAs($rootUser)
        ->getJson(route('tree.search', ['q' => $childAdn]))
        ->assertOk()
        ->assertJson(['found' => true, 'adn' => $childAdn, 'id' => $childId]);
});

it('TS-02: distributor finds a descendant by name, email and phone', function () {
    $rootUser = tsUser('root');
    $rootId = tsSeed($rootUser->id);
    $childUser = tsUser('child', name: 'Aisha Khanna', email: 'aisha.k@example.com', phone: '+919876500001');
    $childId = tsSeed($childUser->id, parentId: $rootId);

    // Partial name
    $this->actingAs($rootUser)
        ->getJson(route('tree.search', ['q' => 'Khanna']))
        ->assertOk()
        ->assertJson(['found' => true, 'id' => $childId]);

    // Exact email
    $this->actingAs($rootUser)
        ->getJson(route('tree.search', ['q' => 'aisha.k@example.com']))
        ->assertOk()
        ->assertJson(['found' => true, 'id' => $childId]);

    // Phone — bare 10-digit form must match the stored +91 form
    $this->actingAs($rootUser)
        ->getJson(route('tree.search', ['q' => '9876500001']))
        ->assertOk()
        ->assertJson(['found' => true, 'id' => $childId]);
});

it('TS-03: distributor searching outside their downline gets found:false', function () {
    $aliceUser = tsUser('alice');
    $aliceId = tsSeed($aliceUser->id);

    // Bob is a SEPARATE root tree — not under Alice.
    $bobUser = tsUser('bob', name: 'Bob Stranger', email: 'bob.stranger@example.com', phone: '+919811122233');
    tsSeed($bobUser->id);

    // By ADN, name, email, phone — all must miss for Alice.
    $bobAdn = (string) DB::table('distributors')->where('user_id', $bobUser->id)->value('adn');

    foreach ([$bobAdn, 'Bob Stranger', 'bob.stranger@example.com', '9811122233'] as $q) {
        $this->actingAs($aliceUser)
            ->getJson(route('tree.search', ['q' => $q]))
            ->assertOk()
            ->assertExactJson(['found' => false]);
    }
});

it('TS-03b: email match is exact, not partial', function () {
    $rootUser = tsUser('root');
    $rootId = tsSeed($rootUser->id);
    $childUser = tsUser('child', email: 'precise@example.com');
    tsSeed($childUser->id, parentId: $rootId);

    // A substring of the email must NOT match (anti-enumeration).
    $this->actingAs($rootUser)
        ->getJson(route('tree.search', ['q' => 'precise']))
        ->assertOk()
        ->assertExactJson(['found' => false]);
});

it('TS-04: admin finds any distributor globally by adn and name', function () {
    $admin = tsAdmin();

    // Two unrelated root trees.
    $aId = tsSeed(tsUser('a', name: 'Carol Global')->id);
    $aAdn = tsAdn($aId);
    $bId = tsSeed(tsUser('b', name: 'Dave Elsewhere', email: 'dave.e@example.com')->id);

    $this->actingAs($admin)
        ->getJson(route('admin.tree.search', ['q' => $aAdn]))
        ->assertOk()
        ->assertJson(['found' => true, 'id' => $aId, 'adn' => $aAdn]);

    $this->actingAs($admin)
        ->getJson(route('admin.tree.search', ['q' => 'Elsewhere']))
        ->assertOk()
        ->assertJson(['found' => true, 'id' => $bId]);

    $this->actingAs($admin)
        ->getJson(route('admin.tree.search', ['q' => 'dave.e@example.com']))
        ->assertOk()
        ->assertJson(['found' => true, 'id' => $bId]);
});

it('TS-05: empty query returns found:false', function () {
    $rootUser = tsUser('root');
    tsSeed($rootUser->id);

    $this->actingAs($rootUser)
        ->getJson(route('tree.search', ['q' => '']))
        ->assertOk()
        ->assertExactJson(['found' => false]);
});
