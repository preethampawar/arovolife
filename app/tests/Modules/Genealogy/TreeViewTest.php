<?php

declare(strict_types=1);

use App\Modules\Genealogy\Services\DTOs\PlaceDistributorInput;
use App\Modules\Genealogy\Services\PlacementEngine;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Services\TeamStatsService;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/**
 * The distributor "my tree" pages must:
 *  - render only the auth'd user's own subtree (no IDOR via ?distributor=N)
 *  - render the binary subtree (placement) and the horizontal sponsorship list
 */
function tvSeedRoot(int $userId): int
{
    disableTestForeignKeys();
    try {
        $id = DB::table('distributors')->insertGetId([
            'user_id' => $userId,
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
            'created_at' => now()->format('Y-m-d H:i:s.v'),
            'updated_at' => now()->format('Y-m-d H:i:s.v'),
        ]);
        DB::table('distributors')->where('id', $id)->update([
            'sponsor_id' => $id, 'placement_parent_id' => $id,
        ]);
    } finally {
        enableTestForeignKeys();
    }

    DB::table('genealogy_closure')->insert([
        'ancestor_id' => $id, 'descendant_id' => $id, 'depth' => 0,
    ]);

    return $id;
}

function tvUser(string $tag): User
{
    return User::create([
        'email' => "tv-{$tag}-".rand(1000, 9999).'@test.com',
        'phone_e164' => '+91'.str_pad((string) rand(7000000000, 9999999999), 10, '0'),
        'password_hash' => bcrypt('x'),
        'status' => 'active',
    ]);
}

function tvEngine(): PlacementEngine
{
    return new PlacementEngine(
        app(DatabaseManager::class),
        app(Dispatcher::class),
        app(TeamStatsService::class),
    );
}

function tvPlace(int $sponsorId, User $user, ?string $side = null, ?int $placementId = null): int
{
    $result = tvEngine()->place(
        new PlaceDistributorInput(
            userId: $user->id,
            sponsorId: $sponsorId,
            placementId: $placementId ?? $sponsorId,
            panHash: random_bytes(32),
            panLast4: '1234',
            bankAccountEnc: 'stub',
            bankIfsc: 'HDFC0001234',
            state: 'TS',
            sideOpt: $side,
        ),
    );

    return $result->distributorId;
}

it('TV-01: distributor /tree renders own ADN and immediate children', function () {
    $rootUser = tvUser('root');
    $rootId = tvSeedRoot($rootUser->id);

    $childUser = tvUser('child');
    $childId = tvPlace($rootId, $childUser);

    $rootUserModel = $rootUser->refresh();

    $response = $this->actingAs($rootUserModel)->get('/tree');
    $response->assertOk();

    $rootAdn = DB::table('distributors')->where('id', $rootId)->value('adn');
    $childAdn = DB::table('distributors')->where('id', $childId)->value('adn');

    $response->assertSee($rootAdn);
    $response->assertSee($childAdn);
});

it('TV-02: distributor /tree shows ONLY own subtree, never an ancestor or sibling subtree', function () {
    $rootUser = tvUser('root');
    $rootId = tvSeedRoot($rootUser->id);

    // Build: root → middle, root → sibling.  middle has its own child "leaf".
    $middleId = tvPlace($rootId, tvUser('mid'), 'L');
    $siblingId = tvPlace($rootId, tvUser('sib'), 'R');
    $leafUser = tvUser('leaf');
    $leafId = tvPlace($middleId, $leafUser);

    $middleUser = User::find(DB::table('distributors')->where('id', $middleId)->value('user_id'));

    // Acting as middle — they MUST see leaf but MUST NOT see sibling or root.
    $response = $this->actingAs($middleUser)->get('/tree');
    $response->assertOk();

    $rootAdn = DB::table('distributors')->where('id', $rootId)->value('adn');
    $middleAdn = DB::table('distributors')->where('id', $middleId)->value('adn');
    $siblingAdn = DB::table('distributors')->where('id', $siblingId)->value('adn');
    $leafAdn = DB::table('distributors')->where('id', $leafId)->value('adn');

    $response->assertSee($middleAdn);
    $response->assertSee($leafAdn);
    $response->assertDontSee($rootAdn);
    $response->assertDontSee($siblingAdn);
});

it('TV-03: /tree/sponsorship default depth shows ONLY direct referrals (depth 1)', function () {
    $rootUser = tvUser('root');
    $rootId = tvSeedRoot($rootUser->id);

    $directA = tvPlace($rootId, tvUser('dirA'), 'L');
    $directB = tvPlace($rootId, tvUser('dirB'), 'R');
    $indirect = tvPlace($directA, tvUser('indir'));

    $response = $this->actingAs($rootUser->refresh())->get('/tree/sponsorship');
    $response->assertOk();

    $aAdn = DB::table('distributors')->where('id', $directA)->value('adn');
    $bAdn = DB::table('distributors')->where('id', $directB)->value('adn');
    $iAdn = DB::table('distributors')->where('id', $indirect)->value('adn');

    // Default behavior: selected distributor + their direct sponsorees only.
    $response->assertSee($aAdn);
    $response->assertSee($bAdn);
    // Indirect referrals (sponsored by direct sponsorees) are NOT shown by
    // default — user must explicitly opt in by increasing the Depth picker.
    $response->assertDontSee($iAdn);
});

it('TV-03b: /tree/sponsorship hard-caps at 1 level — ?levels=2 is ignored', function () {
    $rootUser = tvUser('root');
    $rootId = tvSeedRoot($rootUser->id);

    $directA = tvPlace($rootId, tvUser('dirA'), 'L');
    $indirect = tvPlace($directA, tvUser('indir'));

    // Hand-edited URL trying to opt into deeper levels — the controller
    // discards `levels` for the sponsorship view and locks the depth at 1.
    $response = $this->actingAs($rootUser->refresh())->get('/tree/sponsorship?levels=2');
    $response->assertOk();

    $aAdn = DB::table('distributors')->where('id', $directA)->value('adn');
    $iAdn = DB::table('distributors')->where('id', $indirect)->value('adn');

    $response->assertSee($aAdn);
    // indirect must NOT appear — controller forces $levels = 1 regardless
    // of the query param.
    $response->assertDontSee($iAdn);
});

it('TV-04: /tree/{adn} re-roots at a descendant ADN — root and sibling are hidden', function () {
    $rootUser = tvUser('root');
    $rootId = tvSeedRoot($rootUser->id);

    $middleId = tvPlace($rootId, tvUser('mid'), 'L');
    $siblingId = tvPlace($rootId, tvUser('sib'), 'R');
    $leafId = tvPlace($middleId, tvUser('leaf'));

    $middleAdn = DB::table('distributors')->where('id', $middleId)->value('adn');
    $rootAdn = DB::table('distributors')->where('id', $rootId)->value('adn');
    $siblingAdn = DB::table('distributors')->where('id', $siblingId)->value('adn');
    $leafAdn = DB::table('distributors')->where('id', $leafId)->value('adn');

    $response = $this->actingAs($rootUser->refresh())->get('/tree/'.$middleAdn);
    $response->assertOk();

    // The new root (middle) AND its leaf are visible;
    // the original root and the sibling-subtree are not in this pivot.
    $response->assertSee($middleAdn);
    $response->assertSee($leafAdn);
    $response->assertDontSee($rootAdn);
    $response->assertDontSee($siblingAdn);
});

it('TV-05: /tree/{adn} for a foreign ADN bounces back to /tree (no leak)', function () {
    // Two separate trees, no shared ancestry.
    $treeAuser = tvUser('a-root');
    $treeAroot = tvSeedRoot($treeAuser->id);

    $treeBuser = tvUser('b-root');
    $treeBroot = tvSeedRoot($treeBuser->id);

    $treeBadn = DB::table('distributors')->where('id', $treeBroot)->value('adn');

    // Acting as tree-A's root, ask for tree-B's ADN — must not render it.
    $response = $this->actingAs($treeAuser->refresh())->get('/tree/'.$treeBadn);

    $response->assertRedirect(route('tree.binary'));
});

it('TV-06: distributor /tree defaults to 3 levels deep; deeper nodes need an explicit ?levels=', function () {
    $rootUser = tvUser('root');
    $rootId = tvSeedRoot($rootUser->id);

    // Build a straight binary chain 4 levels deep below the root:
    // root(0) → l1(1) → l2(2) → l3(3) → l4(4).
    $l1 = tvPlace($rootId, tvUser('l1'), 'L');
    $l2 = tvPlace($l1, tvUser('l2'), 'L');
    $l3 = tvPlace($l2, tvUser('l3'), 'L');
    $l4 = tvPlace($l3, tvUser('l4'), 'L');

    $l3Adn = DB::table('distributors')->where('id', $l3)->value('adn');
    $l4Adn = DB::table('distributors')->where('id', $l4)->value('adn');

    // Default view (no ?levels=) caps at 3 levels: level-3 node shows,
    // the level-4 node does not.
    $default = $this->actingAs($rootUser->refresh())->get('/tree');
    $default->assertOk();
    $default->assertSee($l3Adn);
    $default->assertDontSee($l4Adn);

    // Explicitly requesting depth 4 reveals the level-4 node.
    $deep = $this->actingAs($rootUser->refresh())->get('/tree?levels=4');
    $deep->assertOk();
    $deep->assertSee($l4Adn);
});
