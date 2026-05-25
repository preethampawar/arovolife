<?php

declare(strict_types=1);

use App\Modules\Identity\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

/**
 * Seed a distributor row + its closure rows. Returns the distributor id.
 * Mirrors the closure-seeding pattern used in TreeSearchTest, with unique
 * local helper names (tsg*) to avoid Pest global function collisions.
 */
function tsgSeed(int $userId, ?int $parentId = null, string $side = 'L'): int
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
            'placement_side' => $parentId !== null ? $side : null,
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

function tsgUser(string $tag, ?string $name = null, ?string $email = null, ?string $phone = null): User
{
    return User::create([
        'full_name' => $name ?? ('User '.$tag),
        'email' => $email ?? ("tsg-{$tag}-".rand(1000, 9999).'@test.com'),
        'phone_e164' => $phone ?? ('+91'.str_pad((string) rand(7000000000, 9999999999), 10, '0')),
        'password_hash' => bcrypt('x'),
        'status' => 'active',
    ]);
}

function tsgAdmin(): User
{
    Role::findOrCreate('admin', 'web');
    $u = tsgUser('admin');
    $u->assignRole('admin');

    return $u;
}

function tsgAdn(int $id): string
{
    return (string) DB::table('distributors')->where('id', $id)->value('adn');
}

it('TSG-01: distributor suggest returns matching downline distributors as a list', function () {
    $rootUser = tsgUser('root');
    $rootId = tsgSeed($rootUser->id);

    $a = tsgUser('a', name: 'Khanna Aisha');
    $aId = tsgSeed($a->id, parentId: $rootId);
    $b = tsgUser('b', name: 'Khanna Bilal');
    $bId = tsgSeed($b->id, parentId: $rootId, side: 'R');

    $res = $this->actingAs($rootUser)
        ->getJson(route('tree.suggest', ['q' => 'Khanna']))
        ->assertOk()
        ->assertJsonStructure(['results' => [['adn', 'id', 'name']]]);

    $ids = collect($res->json('results'))->pluck('id')->all();
    expect($ids)->toContain($aId)->toContain($bId);

    // Each row carries the expected shape: name + adn, no email/phone.
    $first = $res->json('results.0');
    expect(array_keys($first))->toEqualCanonicalizing(['adn', 'id', 'name']);
});

it('TSG-02: distributor suggest excludes distributors outside the caller downline', function () {
    $aliceUser = tsgUser('alice');
    tsgSeed($aliceUser->id);

    // Separate root tree — not under Alice.
    $bobUser = tsgUser('bob', name: 'Stranger Bob');
    tsgSeed($bobUser->id);

    $this->actingAs($aliceUser)
        ->getJson(route('tree.suggest', ['q' => 'Stranger']))
        ->assertOk()
        ->assertExactJson(['results' => []]);
});

it('TSG-03: a sub-2-character query returns empty results', function () {
    $rootUser = tsgUser('root', name: 'Q');
    tsgSeed($rootUser->id);

    $this->actingAs($rootUser)
        ->getJson(route('tree.suggest', ['q' => 'Q']))
        ->assertOk()
        ->assertExactJson(['results' => []]);

    $this->actingAs($rootUser)
        ->getJson(route('tree.suggest', ['q' => '']))
        ->assertOk()
        ->assertExactJson(['results' => []]);
});

it('TSG-04: admin suggest returns global matches across unrelated trees', function () {
    $admin = tsgAdmin();

    $aId = tsgSeed(tsgUser('a', name: 'Global Carol')->id);
    $bId = tsgSeed(tsgUser('b', name: 'Global Dave')->id);

    $res = $this->actingAs($admin)
        ->getJson(route('admin.tree.suggest', ['q' => 'Global']))
        ->assertOk()
        ->assertJsonStructure(['results' => [['adn', 'id', 'name']]]);

    $ids = collect($res->json('results'))->pluck('id')->all();
    expect($ids)->toContain($aId)->toContain($bId);
});
