<?php

declare(strict_types=1);

use App\Modules\Identity\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
    app(PermissionRegistrar::class)->forgetCachedPermissions();
});

function compAdmin(): User
{
    $user = User::create([
        'full_name' => 'Comp Admin',
        'email' => 'comp-admin-'.uniqid().'@test.com',
        'phone_e164' => '+9180000'.rand(10000, 99999),
        'password_hash' => bcrypt('x'),
        'password_set_at' => now(),
        'status' => 'active',
        'email_verified_at' => now(),
    ]);
    $user->assignRole('admin');

    return $user;
}

function compDistributor(?int $parentId = null, ?string $side = null, string $name = 'Comp Dist'): int
{
    $user = User::create([
        'full_name' => $name,
        'email' => 'comp-dist-'.uniqid().'@test.com',
        'phone_e164' => '+91'.str_pad((string) random_int(7000000000, 9999999999), 10, '0'),
        'password_hash' => bcrypt('x'),
        'status' => 'active',
    ]);

    disableTestForeignKeys();
    try {
        $id = DB::table('distributors')->insertGetId([
            'user_id' => $user->id,
            'adn' => 'ADN'.random_int(10000, 99999),
            'pan_hash' => random_bytes(32),
            'pan_last4' => '1234',
            'bank_account_enc' => 'stub',
            'bank_ifsc' => 'SBIN0000000',
            'sponsor_id' => $parentId ?? 0,
            'placement_parent_id' => $parentId ?? 0,
            'placement_side' => $side,
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
            DB::table('distributors')->where('id', $id)->update(['sponsor_id' => $id, 'placement_parent_id' => $id]);
        }
    } finally {
        enableTestForeignKeys();
    }

    return $id;
}

it('flags group BV as not credited when the distributor is below 600 personal BV', function (): void {
    $distributorId = compDistributor();

    DB::table('group_bv_daily')->insert([
        'distributor_id' => $distributorId,
        'date' => today()->toDateString(),
        'left_bv_paise' => 150_000, // 1,500 BV — raw accumulator stays visible
        'right_bv_paise' => 80_000,
    ]);

    $this->actingAs(compAdmin())
        ->get(route('admin.compensation.distributors.show', $distributorId))
        ->assertOk()
        ->assertSee('1,500')
        ->assertSee('Not credited — personal BV below 600');
});

it('shows the genos BV ledger with side-attributed credits and the cut-off settlement', function (): void {
    $root = compDistributor();
    $leftChild = compDistributor($root, 'L', 'Left Child');
    $rightChild = compDistributor($root, 'R', 'Right Child');
    $grandBuyer = compDistributor($leftChild, 'L', 'Grand Buyer');

    // Closure rows the ledger join needs: self rows plus child→descendant.
    DB::table('genealogy_closure')->insert([
        ['ancestor_id' => $leftChild, 'descendant_id' => $leftChild, 'depth' => 0],
        ['ancestor_id' => $rightChild, 'descendant_id' => $rightChild, 'depth' => 0],
        ['ancestor_id' => $grandBuyer, 'descendant_id' => $grandBuyer, 'depth' => 0],
        ['ancestor_id' => $leftChild, 'descendant_id' => $grandBuyer, 'depth' => 1],
        ['ancestor_id' => $root, 'descendant_id' => $leftChild, 'depth' => 1],
        ['ancestor_id' => $root, 'descendant_id' => $rightChild, 'depth' => 1],
        ['ancestor_id' => $root, 'descendant_id' => $grandBuyer, 'depth' => 2],
    ]);

    // Two propagated paid orders: a grandchild deep on the left, a direct right child.
    DB::table('bv_propagation_log')->insert([
        ['order_id' => 1001, 'distributor_id' => $grandBuyer, 'bv_paise' => 50_000, 'date' => today()->toDateString()],
        ['order_id' => 1002, 'distributor_id' => $rightChild, 'bv_paise' => 30_000, 'date' => today()->toDateString()],
    ]);

    DB::table('gsb_cutoff_results')->insert([
        'distributor_id' => $root,
        'cutoff_date' => today()->toDateString(),
        'left_bv_paise' => 50_000,
        'right_bv_paise' => 30_000,
        'weaker_bv_paise' => 30_000,
        'gross_gsb_paise' => 0,
        'admin_charge_paise' => 0,
        'tds_paise' => 0,
        'net_gsb_paise' => 0,
        'power_cf_before_paise' => 0,
        'power_cf_after_paise' => 50_000,
        'power_side_after' => 'L',
        'slab1_weaker_cf_before_paise' => 0,
        'slab1_weaker_cf_after_paise' => 30_000,
        'status' => 'no_match',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $this->actingAs(compAdmin())
        ->get(route('admin.compensation.distributors.show', [$root, 'tab' => 'genos-ledger']))
        ->assertOk()
        ->assertSee('#1001')
        ->assertSee('#1002')
        ->assertSee('Grand Buyer')
        ->assertSee('+500')
        ->assertSee('+300')
        ->assertSee('Cut-off settlement')
        ->assertSee('no match');
});

it('shows an empty genos BV ledger when there is no downline activity', function (): void {
    $distributorId = compDistributor();

    $this->actingAs(compAdmin())
        ->get(route('admin.compensation.distributors.show', [$distributorId, 'tab' => 'genos-ledger']))
        ->assertOk()
        ->assertSee('No Genos BV activity yet.');
});

it('shows no not-credited pill once the distributor has 600 personal BV', function (): void {
    $distributorId = compDistributor();

    disableTestForeignKeys();
    try {
        DB::table('bv_ledger_entries')->insert([
            'distributor_id' => $distributorId,
            'order_id' => 999_999,
            'bv_paise' => 60_000, // exactly 600 BV — the gate is >=
            'type' => 'accrual',
            'effective_at' => now()->format('Y-m-d H:i:s.v'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    } finally {
        enableTestForeignKeys();
    }

    DB::table('group_bv_daily')->insert([
        'distributor_id' => $distributorId,
        'date' => today()->toDateString(),
        'left_bv_paise' => 150_000,
        'right_bv_paise' => 80_000,
    ]);

    $this->actingAs(compAdmin())
        ->get(route('admin.compensation.distributors.show', $distributorId))
        ->assertOk()
        ->assertSee('1,500')
        ->assertDontSee('Not credited — personal BV below 600');
});
