<?php

declare(strict_types=1);

use App\Modules\Genealogy\Models\LineChangeRequest;
use App\Modules\Identity\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/**
 * Local, distinctly-named helpers (lcc*) so they do not collide with the
 * global Pest functions defined in LineChangeRequestTest (lcr*).
 */
function lccUser(string $tag): User
{
    return User::create([
        'email' => "lcc-{$tag}-".rand(1000, 9999).'@test.com',
        'phone_e164' => '+91'.str_pad((string) rand(7000000000, 9999999999), 10, '0'),
        'password_hash' => bcrypt('x'),
        'status' => 'active',
    ]);
}

function lccSeed(int $userId, ?int $effectiveAtBusinessDaysAgo = null, ?int $sponsorId = null): int
{
    disableTestForeignKeys();
    try {
        $effective = $effectiveAtBusinessDaysAgo === null
            ? now()
            : now()->subWeekdays($effectiveAtBusinessDaysAgo);

        $id = DB::table('distributors')->insertGetId([
            'user_id' => $userId,
            'adn' => (string) rand(100000000, 999999999),
            'pan_hash' => random_bytes(32),
            'pan_last4' => '0000',
            'bank_account_enc' => 'stub',
            'bank_ifsc' => 'SBIN0000000',
            'sponsor_id' => $sponsorId ?? 0,
            'placement_parent_id' => $sponsorId ?? 0,
            'placement_side' => null,
            'side_chosen_by' => 'referral_default',
            'depth' => $sponsorId === null ? 0 : 1,
            'effective_date' => $effective->format('Y-m-d H:i:s.v'),
            'cooling_off_end_at' => $effective->copy()->addDays(30)->format('Y-m-d H:i:s.v'),
            'state' => 'TS',
            'is_primary_couple' => 0,
            'created_at' => now()->format('Y-m-d H:i:s.v'),
            'updated_at' => now()->format('Y-m-d H:i:s.v'),
        ]);
        if ($sponsorId === null) {
            DB::table('distributors')->where('id', $id)->update([
                'sponsor_id' => $id,
                'placement_parent_id' => $id,
            ]);
        }
    } finally {
        enableTestForeignKeys();
    }

    DB::table('genealogy_closure')->insert([
        'ancestor_id' => $id, 'descendant_id' => $id, 'depth' => 0,
    ]);
    if ($sponsorId !== null) {
        $ancestors = DB::table('genealogy_closure')
            ->where('descendant_id', $sponsorId)
            ->get(['ancestor_id', 'depth']);
        foreach ($ancestors as $a) {
            DB::table('genealogy_closure')->insert([
                'ancestor_id' => $a->ancestor_id,
                'descendant_id' => $id,
                'depth' => $a->depth + 1,
            ]);
        }
    }

    return $id;
}

it('LCC-01: a distributor inside the window sees the request form', function () {
    $rootId = lccSeed(lccUser('root')->id, effectiveAtBusinessDaysAgo: 30);

    $applicantUser = lccUser('app');
    lccSeed($applicantUser->id, effectiveAtBusinessDaysAgo: 2, sponsorId: $rootId);

    $this->actingAs($applicantUser)
        ->get(route('line-change.show'))
        ->assertOk()
        ->assertSee('New placement parent ADN');
});

it('LCC-02: submitting a valid target ADN creates a pending request and redirects', function () {
    $rootId = lccSeed(lccUser('root')->id, effectiveAtBusinessDaysAgo: 30);

    // Target parent joined before the applicant and has a free leg.
    $targetId = lccSeed(lccUser('target')->id, effectiveAtBusinessDaysAgo: 20);
    $targetAdn = DB::table('distributors')->where('id', $targetId)->value('adn');

    $applicantUser = lccUser('app');
    $applicantId = lccSeed($applicantUser->id, effectiveAtBusinessDaysAgo: 2, sponsorId: $rootId);

    $this->actingAs($applicantUser)
        ->post(route('line-change.submit'), ['to_parent_adn' => $targetAdn])
        ->assertRedirect(route('line-change.show'))
        ->assertSessionHas('status');

    $row = LineChangeRequest::query()->where('distributor_id', $applicantId)->first();
    expect($row)->not->toBeNull()
        ->and($row->status)->toBe('pending')
        ->and($row->to_placement_parent_id)->toBe($targetId);
});

it('LCC-03: a distributor who already used their one line change sees the terminal panel and no form', function () {
    $rootId = lccSeed(lccUser('root')->id, effectiveAtBusinessDaysAgo: 30);
    $targetId = lccSeed(lccUser('target')->id, effectiveAtBusinessDaysAgo: 20);

    $applicantUser = lccUser('app');
    $applicantId = lccSeed($applicantUser->id, effectiveAtBusinessDaysAgo: 2, sponsorId: $rootId);

    DB::table('line_change_requests')->insert([
        'distributor_id' => $applicantId,
        'from_placement_parent_id' => $rootId,
        'to_placement_parent_id' => $targetId,
        'requested_at' => now()->subDay()->format('Y-m-d H:i:s.v'),
        'approved_at' => now()->subDay()->format('Y-m-d H:i:s.v'),
        'status' => 'approved',
    ]);

    $this->actingAs($applicantUser)
        ->get(route('line-change.show'))
        ->assertOk()
        ->assertSee('already used your one line change')
        ->assertDontSee('New placement parent ADN');
});
