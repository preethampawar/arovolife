<?php

declare(strict_types=1);

use App\Modules\Identity\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

/**
 * LF-01 .. LF-04 — query-string filters on the admin distributors list.
 *
 * The dashboard's stat tiles click-through to this page with `status` or
 * `cooling_off` query params pre-applied. These tests pin the controller's
 * filter predicates so the dashboard count and the resulting list always
 * agree on what they describe.
 */
function lfAdmin(): User
{
    Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
    $admin = User::create([
        'full_name' => 'List Filter Admin',
        'email' => 'lf-admin-'.uniqid().'@test.com',
        'phone_e164' => '+91'.str_pad((string) rand(7000000000, 9999999999), 10, '0'),
        'password_hash' => bcrypt('x'),
        'password_set_at' => now(),
        'status' => 'active',
    ]);
    $admin->assignRole('admin');

    return $admin;
}

/**
 * Seed one distributor row, returning ['user_id' => …, 'distributor_id' => …].
 *
 * @param  array<string, mixed>  $userOverrides
 * @param  array<string, mixed>  $distOverrides
 * @return array{user_id:int, distributor_id:int, adn:string}
 */
function lfSeedDistributor(array $userOverrides = [], array $distOverrides = []): array
{
    $userId = DB::table('users')->insertGetId(array_merge([
        'full_name' => 'LF User '.uniqid(),
        'email' => 'lf-'.uniqid().'@test.com',
        'phone_e164' => '+91'.str_pad((string) rand(7000000000, 9999999999), 10, '0'),
        'password_hash' => bcrypt('x'),
        'password_set_at' => now(),
        'status' => 'active',
        'created_at' => now(),
        'updated_at' => now(),
    ], $userOverrides));

    $adn = (string) rand(100000000, 999999999);

    disableTestForeignKeys();
    try {
        $distributorId = DB::table('distributors')->insertGetId(array_merge([
            'user_id' => $userId,
            'adn' => $adn,
            'pan_hash' => random_bytes(32),
            'pan_last4' => '0000',
            'aadhaar_last4' => '0000',
            'bank_account_enc' => random_bytes(32),
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
        ], $distOverrides));

        DB::table('distributors')->where('id', $distributorId)->update([
            'sponsor_id' => $distributorId,
            'placement_parent_id' => $distributorId,
        ]);
    } finally {
        enableTestForeignKeys();
    }

    DB::table('genealogy_closure')->insert([
        'ancestor_id' => $distributorId,
        'descendant_id' => $distributorId,
        'depth' => 0,
    ]);

    return ['user_id' => $userId, 'distributor_id' => $distributorId, 'adn' => $adn];
}

it('LF-01: cooling_off=active returns only distributors whose cooling_off_end_at is in the future', function (): void {
    // 2 active (>now), 1 expired (<now)
    $futureA = lfSeedDistributor([], ['cooling_off_end_at' => now()->addDays(20)->format('Y-m-d H:i:s.v')]);
    $futureB = lfSeedDistributor([], ['cooling_off_end_at' => now()->addDays(3)->format('Y-m-d H:i:s.v')]);
    $expired = lfSeedDistributor([], ['cooling_off_end_at' => now()->subDays(2)->format('Y-m-d H:i:s.v')]);

    $this->actingAs(lfAdmin());
    $response = $this->get(route('admin.distributors.index', ['cooling_off' => 'active']));

    $response->assertStatus(200)
        ->assertSee($futureA['adn'])
        ->assertSee($futureB['adn'])
        ->assertDontSee($expired['adn']);
});

it('LF-02: cooling_off=expiring returns only distributors with cooling_off_end_at in the next 7 days', function (): void {
    Carbon::setTestNow(Carbon::parse('2026-06-01 10:00:00'));

    try {
        $within = lfSeedDistributor([], ['cooling_off_end_at' => Carbon::parse('2026-06-04 09:00:00')->format('Y-m-d H:i:s.v')]);
        $edge = lfSeedDistributor([], ['cooling_off_end_at' => Carbon::parse('2026-06-08 09:00:00')->format('Y-m-d H:i:s.v')]);
        $tooFar = lfSeedDistributor([], ['cooling_off_end_at' => Carbon::parse('2026-06-20 09:00:00')->format('Y-m-d H:i:s.v')]);
        $expired = lfSeedDistributor([], ['cooling_off_end_at' => Carbon::parse('2026-05-25 09:00:00')->format('Y-m-d H:i:s.v')]);

        $this->actingAs(lfAdmin());
        $response = $this->get(route('admin.distributors.index', ['cooling_off' => 'expiring']));

        $response->assertStatus(200)
            ->assertSee($within['adn'])
            ->assertSee($edge['adn'])
            ->assertDontSee($tooFar['adn'])
            ->assertDontSee($expired['adn']);
    } finally {
        Carbon::setTestNow();
    }
});

it('LF-03: status=frozen returns only frozen users\' distributors', function (): void {
    $frozen = lfSeedDistributor(['status' => 'frozen']);
    $active = lfSeedDistributor(['status' => 'active']);
    $pending = lfSeedDistributor(['status' => 'pending']);

    $this->actingAs(lfAdmin());
    $response = $this->get(route('admin.distributors.index', ['status' => 'frozen']));

    $response->assertStatus(200)
        ->assertSee($frozen['adn'])
        ->assertDontSee($active['adn'])
        ->assertDontSee($pending['adn']);
});

it('LF-04: existing status filters (active, pending, terminated) still work — regression', function (): void {
    $active = lfSeedDistributor(['status' => 'active']);
    $pending = lfSeedDistributor(['status' => 'pending']);
    $terminated = lfSeedDistributor(['status' => 'terminated']);

    $this->actingAs(lfAdmin());

    // status=active should show only the active row.
    $r = $this->get(route('admin.distributors.index', ['status' => 'active']));
    $r->assertStatus(200)
        ->assertSee($active['adn'])
        ->assertDontSee($pending['adn'])
        ->assertDontSee($terminated['adn']);

    // status=pending should show only the pending row.
    $r = $this->get(route('admin.distributors.index', ['status' => 'pending']));
    $r->assertStatus(200)
        ->assertSee($pending['adn'])
        ->assertDontSee($active['adn'])
        ->assertDontSee($terminated['adn']);

    // status=terminated should show only the terminated row.
    $r = $this->get(route('admin.distributors.index', ['status' => 'terminated']));
    $r->assertStatus(200)
        ->assertSee($terminated['adn'])
        ->assertDontSee($active['adn'])
        ->assertDontSee($pending['adn']);
});

it('LF-05: unknown cooling_off value fails validation', function (): void {
    $this->actingAs(lfAdmin());
    $response = $this->get(route('admin.distributors.index', ['cooling_off' => 'bogus']));

    // Laravel renders a 302 redirect-back on validation error for GET with
    // session-flashed errors; the page should not render a 200.
    expect($response->status())->not->toBe(200);
});
