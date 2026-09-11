<?php

declare(strict_types=1);

/**
 * The distributor-facing /cooling-off page must state the ACTUAL window for
 * this registration, not a hardcoded "30-day" — 31 reserved/company accounts
 * carry a 0-day window (cooling_off_end_at === effective_date), and printing
 * "30-day window" against them is a wrong statutory statement (F73(f)).
 */

use App\Modules\Identity\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

function ccpUser(): User
{
    return User::create([
        'full_name' => 'Cooling Off Copy',
        'email' => 'ccp-'.uniqid().'@example.com',
        'phone_e164' => '+91954'.str_pad((string) random_int(0, 9999999), 7, '0', STR_PAD_LEFT),
        'password_hash' => bcrypt('x'),
        'status' => 'active',
    ]);
}

/** @return int the distributor id */
function ccpDistributor(User $user, int $windowDays, int $daysAgoJoined): int
{
    disableTestForeignKeys();
    try {
        $id = DB::table('distributors')->insertGetId([
            'user_id' => $user->id,
            'adn' => (string) random_int(100000000, 999999999),
            'pan_hash' => random_bytes(32),
            'pan_last4' => '0000',
            'bank_account_enc' => 'stub',
            'bank_ifsc' => 'SBIN0000000',
            'sponsor_id' => 0,
            'placement_parent_id' => 0,
            'side_chosen_by' => 'referral_default',
            'depth' => 0,
            'effective_date' => now()->subDays($daysAgoJoined)->format('Y-m-d H:i:s.v'),
            'cooling_off_end_at' => now()->subDays($daysAgoJoined)->addDays($windowDays)->format('Y-m-d H:i:s.v'),
            'state' => 'TS',
            'is_primary_couple' => 0,
            'created_at' => now()->format('Y-m-d H:i:s.v'),
            'updated_at' => now()->format('Y-m-d H:i:s.v'),
        ]);
        DB::table('distributors')->where('id', $id)->update(['sponsor_id' => $id, 'placement_parent_id' => $id]);
    } finally {
        enableTestForeignKeys();
    }

    return $id;
}

it('states the real 30-day window while a normal distributor is still inside it', function (): void {
    $user = ccpUser();
    ccpDistributor($user, windowDays: 30, daysAgoJoined: 1);

    $this->actingAs($user)
        ->get(route('cooling-off.show'))
        ->assertOk()
        ->assertSee('30-day cooling-off');
});

it('states the real 30-day window once a normal distributor\'s window has expired', function (): void {
    $user = ccpUser();
    ccpDistributor($user, windowDays: 30, daysAgoJoined: 40);

    $this->actingAs($user)
        ->get(route('cooling-off.show'))
        ->assertOk()
        ->assertSee('30-day window expired', false);
});

it('never claims a "30-day window" for a 0-day reserved-account window (F73)', function (): void {
    $user = ccpUser();
    ccpDistributor($user, windowDays: 0, daysAgoJoined: 1);

    $this->actingAs($user)
        ->get(route('cooling-off.show'))
        ->assertOk()
        ->assertDontSee('30-day')
        ->assertSee('0-day window expired', false);
});
