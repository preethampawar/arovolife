<?php

declare(strict_types=1);

use App\Modules\Compensation\Models\GsbCutoffResult;
use App\Modules\Identity\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    disableTestForeignKeys();
    $this->seed(RolesAndPermissionsSeeder::class);
    seedCompensationPlanTables();
});

function cutoffAdmin(): User
{
    $user = User::create([
        'full_name' => 'Cut-off Admin',
        'email' => 'cutoff-admin-'.uniqid().'@test.com',
        'phone_e164' => '+91'.str_pad((string) random_int(7000000000, 9999999999), 10, '0'),
        'password_hash' => bcrypt('x'),
        'status' => 'active',
        'email_verified_at' => now(),
    ]);
    $user->assignRole('admin');

    return $user;
}

/** One credited cut-off row for today, so the table (and its header tooltips) renders. */
function cutoffRowForToday(): void
{
    $user = User::create([
        'full_name' => 'Cut-off Distributor',
        'email' => 'cutoff-dist-'.uniqid().'@test.com',
        'phone_e164' => '+91'.str_pad((string) random_int(7000000000, 9999999999), 10, '0'),
        'password_hash' => bcrypt('x'),
        'status' => 'active',
    ]);

    $id = DB::table('distributors')->insertGetId([
        'user_id' => $user->id,
        'adn' => 'ADNCUT'.random_int(100, 999),
        'pan_hash' => random_bytes(32),
        'pan_last4' => '1234',
        'sponsor_id' => 0,
        'placement_parent_id' => 0,
        'side_chosen_by' => 'referral_default',
        'depth' => 0,
        'effective_date' => now()->format('Y-m-d H:i:s.v'),
        'cooling_off_end_at' => now()->addDays(30)->format('Y-m-d H:i:s.v'),
        'state' => 'TS',
        'is_primary_couple' => 0,
        'created_at' => now()->format('Y-m-d H:i:s.v'),
        'updated_at' => now()->format('Y-m-d H:i:s.v'),
    ]);

    // Raw insert, not GsbCutoffResult::create(): the model's 'date' cast
    // serialises through the connection's datetime format, which on SQLite
    // stores "…: 00:00:00" and silently misses the controller's plain
    // where('cutoff_date', 'Y-m-d') — the same driver trap documented on
    // FortuneMonthlyPool::$month_start.
    DB::table('gsb_cutoff_results')->insert([
        'distributor_id' => $id,
        'cutoff_date' => today()->toDateString(),
        'left_bv_paise' => 2_000_000,
        'right_bv_paise' => 1_600_000,
        'weaker_bv_paise' => 1_600_000,
        'slab' => 1,
        'score' => 8,
        'gross_gsb_paise' => 200_000,
        'admin_charge_paise' => 0,
        'tds_paise' => 0,
        'net_gsb_paise' => 200_000,
        'status' => GsbCutoffResult::STATUS_CREDITED,
        'created_at' => now()->toDateTimeString(),
        'updated_at' => now()->toDateTimeString(),
    ]);
}

it('ADC-01: the slab tooltip quotes the live ladder on both cut-off screens', function (): void {
    cutoffRowForToday();
    $admin = cutoffAdmin();

    foreach ([
        route('admin.compensation.daily-cutoffs.index'),
        route('admin.compensation.daily-cutoffs.show', today()->toDateString()),
    ] as $url) {
        $this->actingAs($admin)->get($url)
            ->assertOk()
            ->assertSee('Slab 1=15K, 2=36K, 3=1L, 4=3L, 5=9L, 6=27L, 7=81L BV matched on the weaker side.', false)
            // KP 2026-07-21 retired these thresholds; the tooltip went on
            // quoting them because it was written out rather than derived.
            ->assertDontSee('2=30K')
            ->assertDontSee('4=2.7L');
    }
});

it('ADC-02: an admin plan edit moves the tooltip with it', function (): void {
    cutoffRowForToday();
    DB::table('gsb_slabs')->where('slab', 3)->update(['matched_bv_paise' => 12_000_000]);

    $this->actingAs(cutoffAdmin())
        ->get(route('admin.compensation.daily-cutoffs.index'))
        ->assertOk()
        ->assertSee('3=1.2L', false)
        ->assertDontSee('3=1L,');
});
