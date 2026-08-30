<?php

declare(strict_types=1);

use App\Modules\Commerce\Models\BvLedgerEntry;
use App\Modules\Identity\Models\Distributor;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Services\DistributorIdCardStats;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

/**
 * Every tree card carries Highest Rank, Current Rank and Personal BV. They
 * are always filled on the viewer's own card; on downline cards and for
 * admins only while `genealogy.downline_stats_visible` is ON (client
 * decision 2026-08-30, risk register R-65, hard rule 3 as amended); and
 * never for anyone outside the viewer's subtree. The canvas resolves them in
 * one batch through DistributorIdCardStats::compactMany().
 */
beforeEach(function (): void {
    disableTestForeignKeys();
});

function tcosDownlineStats(bool $on): void
{
    DB::table('settings')->updateOrInsert(
        ['key' => DistributorIdCardStats::DOWNLINE_STATS_SETTING],
        ['value' => $on ? 'true' : 'false', 'version' => 1, 'updated_at' => now()],
    );
}

function tcosUser(string $tag): User
{
    return User::create([
        'email' => "tcos-{$tag}-".rand(100000, 999999).'@test.com',
        'phone_e164' => '+91'.str_pad((string) rand(7000000000, 9999999999), 10, '0'),
        'password_hash' => bcrypt('x'),
        'status' => 'active',
    ]);
}

/** Insert a distributor row; a null $parentId makes it a self-rooted tree. */
function tcosSeedDistributor(int $userId, ?int $parentId = null, string $side = 'L'): int
{
    $id = DB::table('distributors')->insertGetId([
        'user_id' => $userId,
        'adn' => (string) rand(100000000, 999999999),
        'pan_hash' => random_bytes(32),
        'pan_last4' => '0000',
        'bank_account_enc' => 'stub',
        'bank_ifsc' => 'SBIN0000000',
        'sponsor_id' => $parentId ?? 0,
        'placement_parent_id' => $parentId ?? 0,
        'placement_side' => $parentId === null ? null : $side,
        'side_chosen_by' => 'referral_default',
        'depth' => $parentId === null ? 0 : 1,
        'effective_date' => now()->format('Y-m-d H:i:s.v'),
        'cooling_off_end_at' => now()->addDays(30)->format('Y-m-d H:i:s.v'),
        'state' => 'TS',
        'is_primary_couple' => 0,
        'created_at' => now()->format('Y-m-d H:i:s.v'),
        'updated_at' => now()->format('Y-m-d H:i:s.v'),
    ]);

    if ($parentId === null) {
        DB::table('distributors')->where('id', $id)->update([
            'sponsor_id' => $id, 'placement_parent_id' => $id,
        ]);
    }

    DB::table('genealogy_closure')->insert(['ancestor_id' => $id, 'descendant_id' => $id, 'depth' => 0]);
    if ($parentId !== null) {
        // Copy the parent's ancestor chain one level deeper (closure-table insert).
        foreach (DB::table('genealogy_closure')->where('descendant_id', $parentId)->get() as $row) {
            DB::table('genealogy_closure')->insert([
                'ancestor_id' => $row->ancestor_id, 'descendant_id' => $id, 'depth' => $row->depth + 1,
            ]);
        }
    }

    return $id;
}

/** Accrue personal BV (paise = BV × 100) so the card has something to show. */
function tcosAccrueBv(int $distributorId, int $bvPaise): void
{
    static $orderId = 700000;
    BvLedgerEntry::create([
        'distributor_id' => $distributorId,
        'order_id' => $orderId++,
        'bv_paise' => $bvPaise,
        'type' => 'accrual',
        'effective_at' => now(),
    ]);
}

it('fills in the viewer\'s own Personal BV on their Genos card', function (): void {
    $user = tcosUser('self');
    $id = tcosSeedDistributor($user->id);
    tcosAccrueBv($id, 1_600_000);   // 16,000 BV

    $this->actingAs($user->refresh())
        ->get(route('tree.binary'))
        ->assertOk()
        ->assertSee('16,000 BV');
});

it('shows each downline member\'s Personal BV on their own card while the switch is ON', function (): void {
    tcosDownlineStats(true);
    $user = tcosUser('self');
    $id = tcosSeedDistributor($user->id);
    tcosAccrueBv($id, 1_600_000);   // 16,000 BV — the viewer's own

    $childId = tcosSeedDistributor(tcosUser('child')->id, $id);
    tcosAccrueBv($childId, 550_000);  // 5,500 BV — a downline member's, also shown

    $this->actingAs($user->refresh())
        ->get(route('tree.binary'))
        ->assertOk()
        ->assertSee('16,000 BV')
        ->assertSee('5,500 BV')
        ->assertSee('visible to you as their upline');
});

it('keeps downline figures at — and shows no notice while the switch is OFF (default)', function (): void {
    $user = tcosUser('self');
    $id = tcosSeedDistributor($user->id);
    tcosAccrueBv($id, 1_600_000);

    $childId = tcosSeedDistributor(tcosUser('child')->id, $id);
    tcosAccrueBv($childId, 550_000);

    $this->actingAs($user->refresh())
        ->get(route('tree.binary'))
        ->assertOk()
        ->assertSee('16,000 BV')
        ->assertDontSee('5,500 BV')
        ->assertDontSee('visible to you as their upline');
});

it('never resolves a figure for a node outside the viewer\'s subtree, even with the switch ON', function (): void {
    tcosDownlineStats(true);
    $rootId = tcosSeedDistributor(tcosUser('root')->id);
    $aUser = tcosUser('a');
    $aId = tcosSeedDistributor($aUser->id, $rootId, 'L');
    $bId = tcosSeedDistributor(tcosUser('b')->id, $rootId, 'R');
    tcosAccrueBv($aId, 1_600_000);
    tcosAccrueBv($bId, 550_000);

    // Service-level guard, independent of route scoping: asking for a
    // sibling's card as A must come back masked.
    $this->actingAs($aUser->refresh());
    $stats = app(DistributorIdCardStats::class)->compactMany(
        Distributor::query()->with('user')->whereIn('id', [$aId, $bId])->get()
    );

    expect($stats[$aId]['total_personal_bv'])->toBe('16,000 BV')
        ->and($stats[$bId]['total_personal_bv'])->toBeNull()
        ->and($stats[$bId]['highest_rank'])->toBeNull()
        ->and($stats[$bId]['current_rank'])->toBeNull();
});

it('masks a downline member\'s figures in the Details popup while the switch is OFF and shows them when ON', function (): void {
    $user = tcosUser('self');
    $id = tcosSeedDistributor($user->id);
    $childId = tcosSeedDistributor(tcosUser('child')->id, $id);
    tcosAccrueBv($childId, 550_000);

    $this->actingAs($user->refresh())
        ->get(route('distributor.id-card-panel', $childId))
        ->assertOk()
        ->assertDontSee('5,500 BV');

    tcosDownlineStats(true);
    $this->get(route('distributor.id-card-panel', $childId))
        ->assertOk()
        ->assertSee('5,500 BV');
});

it('resolves the card stats for the whole canvas in a bounded number of queries', function (): void {
    tcosDownlineStats(true);
    $user = tcosUser('self');
    $id = tcosSeedDistributor($user->id);
    tcosAccrueBv($id, 1_600_000);
    // Two full levels below the viewer: 2 children, 4 grandchildren.
    $i = 0;
    foreach (['L', 'R'] as $side) {
        $childId = tcosSeedDistributor(tcosUser('c'.++$i)->id, $id, $side);
        tcosAccrueBv($childId, 100_000 * $i);
        foreach (['L', 'R'] as $gSide) {
            $gId = tcosSeedDistributor(tcosUser('c'.++$i)->id, $childId, $gSide);
            tcosAccrueBv($gId, 100_000 * $i);
        }
    }

    DB::enableQueryLog();
    $this->actingAs($user->refresh())
        ->get(route('tree.binary', ['levels' => 3]))
        ->assertOk()
        ->assertSee('6,000 BV');
    $bvSums = collect(DB::getQueryLog())
        ->filter(fn ($q) => str_contains($q['query'], 'SUM(bv_paise)'))
        ->count();
    DB::disableQueryLog();

    expect($bvSums)->toBe(1);
});

it('fills in the viewer\'s own Personal BV on the sponsorship card too', function (): void {
    $user = tcosUser('self');
    $id = tcosSeedDistributor($user->id);
    tcosAccrueBv($id, 1_600_000);

    $this->actingAs($user->refresh())
        ->get(route('tree.sponsorship'))
        ->assertOk()
        ->assertSee('16,000 BV');
});

it('shows an admin every distributor\'s figures on the tree cards while the switch is ON', function (): void {
    tcosDownlineStats(true);
    $user = tcosUser('self');
    $id = tcosSeedDistributor($user->id);
    tcosAccrueBv($id, 1_600_000);

    $admin = tcosUser('admin');
    Role::findOrCreate('admin', 'web');
    $admin->assignRole('admin');

    $this->actingAs($admin->refresh())
        ->get(route('admin.tree.show', $id))
        ->assertOk()
        ->assertSee('16,000 BV');
});
