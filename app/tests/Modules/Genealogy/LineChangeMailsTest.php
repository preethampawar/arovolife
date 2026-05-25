<?php

declare(strict_types=1);

use App\Modules\Genealogy\Events\LineChangeApproved;
use App\Modules\Genealogy\Events\LineChangeRejected;
use App\Modules\Genealogy\Events\LineChangeRequested;
use App\Modules\Genealogy\Notifications\LineChangeApprovedNotification;
use App\Modules\Genealogy\Notifications\LineChangeRejectedNotification;
use App\Modules\Genealogy\Notifications\LineChangeRequestedAdminNotification;
use App\Modules\Genealogy\Notifications\LineChangeRequestedRequesterNotification;
use App\Modules\Genealogy\Notifications\NewPlacementUnderYouNotification;
use App\Modules\Identity\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

// Local seed helpers (lcm* prefix to avoid global function collisions with
// the alc*/lcr* helpers defined in sibling Pest files).
function lcmUser(string $tag): User
{
    return User::create([
        'email' => "lcm-{$tag}-".rand(1000, 9999).'@test.com',
        'phone_e164' => '+91'.str_pad((string) rand(7000000000, 9999999999), 10, '0'),
        'password_hash' => bcrypt('x'),
        'status' => 'active',
        'full_name' => 'LCM '.$tag,
    ]);
}

function lcmSeed(int $userId, int $businessDaysAgo, ?int $parentId = null): int
{
    disableTestForeignKeys();
    try {
        $effective = now()->subWeekdays($businessDaysAgo);
        $depth = 0;
        if ($parentId !== null) {
            $depth = (int) DB::table('distributors')->where('id', $parentId)->value('depth') + 1;
        }
        $id = DB::table('distributors')->insertGetId([
            'user_id' => $userId,
            'adn' => 'ARO'.rand(100000, 999999),
            'pan_hash' => random_bytes(32),
            'pan_last4' => '0000',
            'bank_account_enc' => 'stub',
            'bank_ifsc' => 'SBIN0000000',
            'sponsor_id' => $parentId ?? 0,
            'placement_parent_id' => $parentId ?? 0,
            'placement_side' => null,
            'side_chosen_by' => 'referral_default',
            'depth' => $depth,
            'effective_date' => $effective->format('Y-m-d H:i:s.v'),
            'cooling_off_end_at' => $effective->copy()->addDays(30)->format('Y-m-d H:i:s.v'),
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

function lcmPendingRequest(int $distributorId, int $fromParentId, int $toParentId): int
{
    return DB::table('line_change_requests')->insertGetId([
        'distributor_id' => $distributorId,
        'from_placement_parent_id' => $fromParentId,
        'to_placement_parent_id' => $toParentId,
        'requested_at' => now()->format('Y-m-d H:i:s.v'),
        'status' => 'pending',
        'reason' => 'please move me',
    ]);
}

it('LCM-01: a new request emails the requester and every admin reviewer', function () {
    Notification::fake();

    Role::findOrCreate('admin', 'web');
    $adminUser = lcmUser('admin');
    $adminUser->assignRole('admin');

    $rootId = lcmSeed(lcmUser('root')->id, 40);
    $targetId = lcmSeed(lcmUser('target')->id, 20, parentId: $rootId);
    $requesterUser = lcmUser('app');
    $requesterId = lcmSeed($requesterUser->id, 2, parentId: $rootId);

    $reqId = lcmPendingRequest($requesterId, $rootId, $targetId);

    LineChangeRequested::dispatch($reqId, $requesterId, $rootId, $targetId, now());

    Notification::assertSentTo($requesterUser, LineChangeRequestedRequesterNotification::class);
    Notification::assertSentTo($adminUser, LineChangeRequestedAdminNotification::class);
});

it('LCM-02: an approval emails the requester and the new placement parent', function () {
    Notification::fake();

    $rootId = lcmSeed(lcmUser('root')->id, 40);
    $newParentUser = lcmUser('newp');
    $newParentId = lcmSeed($newParentUser->id, 20, parentId: $rootId);
    $requesterUser = lcmUser('app');
    $requesterId = lcmSeed($requesterUser->id, 2, parentId: $rootId);

    $reviewer = lcmUser('rev');

    LineChangeApproved::dispatch(0, $requesterId, $newParentId, 'L', $reviewer->id, now());

    Notification::assertSentTo($requesterUser, LineChangeApprovedNotification::class);
    Notification::assertSentTo($newParentUser, NewPlacementUnderYouNotification::class);
});

it('LCM-03: a rejection emails the requester', function () {
    Notification::fake();

    $rootId = lcmSeed(lcmUser('root')->id, 40);
    $requesterUser = lcmUser('app');
    $requesterId = lcmSeed($requesterUser->id, 2, parentId: $rootId);

    $reviewer = lcmUser('rev');

    LineChangeRejected::dispatch(0, $requesterId, 'Not eligible for this leg.', $reviewer->id, now());

    Notification::assertSentTo($requesterUser, LineChangeRejectedNotification::class);
});
