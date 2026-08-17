<?php

declare(strict_types=1);

use App\Modules\Commerce\Models\BvLedgerEntry;
use App\Modules\Identity\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/**
 * The tree cards carry three own-data-only rows — Highest Rank, Current Rank
 * and Personal BV. They must be filled in on the viewer's own card and stay
 * "—" on everybody else's (hard rule #3, DSR 2021 r.5(1)(d)).
 */
beforeEach(function (): void {
    disableTestForeignKeys();
});

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
function tcosSeedDistributor(int $userId, ?int $parentId = null): int
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
        'placement_side' => $parentId === null ? null : 'L',
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
        DB::table('genealogy_closure')->insert(['ancestor_id' => $parentId, 'descendant_id' => $id, 'depth' => 1]);
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

it('never shows a downline member\'s Personal BV on their card', function (): void {
    $user = tcosUser('self');
    $id = tcosSeedDistributor($user->id);
    tcosAccrueBv($id, 1_600_000);   // 16,000 BV — the viewer's own, shown

    $childId = tcosSeedDistributor(tcosUser('child')->id, $id);
    tcosAccrueBv($childId, 550_000);  // 5,500 BV — someone else's, hidden

    $this->actingAs($user->refresh())
        ->get(route('tree.binary'))
        ->assertOk()
        ->assertSee('16,000 BV')
        ->assertDontSee('5,500 BV');
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

it('shows an admin no distributor figures on any tree card', function (): void {
    $user = tcosUser('self');
    $id = tcosSeedDistributor($user->id);
    tcosAccrueBv($id, 1_600_000);

    $admin = tcosUser('admin');
    \Spatie\Permission\Models\Role::findOrCreate('admin', 'web');
    $admin->assignRole('admin');

    $this->actingAs($admin->refresh())
        ->get(route('admin.tree.show', $id))
        ->assertOk()
        ->assertDontSee('16,000 BV');
});
