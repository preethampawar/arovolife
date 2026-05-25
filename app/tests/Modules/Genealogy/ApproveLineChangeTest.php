<?php

declare(strict_types=1);

use App\Modules\Compliance\Models\AuditLog;
use App\Modules\Genealogy\Events\LineChangeApproved;
use App\Modules\Genealogy\Models\LineChangeRequest;
use App\Modules\Genealogy\Services\ApproveLineChange;
use App\Modules\Genealogy\Services\Exceptions\LineChangePlacementSlotFullError;
use App\Modules\Identity\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

uses(RefreshDatabase::class);

function alcSeed(int $userId, int $businessDaysAgo, ?int $parentId = null): int
{
    disableTestForeignKeys();
    try {
        $effective = now()->subWeekdays($businessDaysAgo);
        $depth = $parentId === null ? 0 : 1;
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

function alcUser(string $tag): User
{
    return User::create([
        'email' => "alc-{$tag}-".rand(1000, 9999).'@test.com',
        'phone_e164' => '+91'.str_pad((string) rand(7000000000, 9999999999), 10, '0'),
        'password_hash' => bcrypt('x'),
        'status' => 'active',
    ]);
}

function alcPendingRequest(int $distributorId, int $fromParentId, int $toParentId): int
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

it('ALC-01: approval moves placement, depth, closure and leaves sponsor intact', function () {
    Event::fake();

    $rootId = alcSeed(alcUser('root')->id, 40);
    $oldParentId = alcSeed(alcUser('old')->id, 30, parentId: $rootId);
    $newParentId = alcSeed(alcUser('new')->id, 25, parentId: $rootId);
    $applicantId = alcSeed(alcUser('app')->id, 2, parentId: $oldParentId);

    $reqId = alcPendingRequest($applicantId, $oldParentId, $newParentId);
    $admin = alcUser('admin');

    app(ApproveLineChange::class)($reqId, $admin->id, 'L');

    $d = DB::table('distributors')->where('id', $applicantId)->first();
    expect((int) $d->placement_parent_id)->toBe($newParentId)
        ->and($d->placement_side)->toBe('L')
        ->and((int) $d->depth)->toBe(2)
        ->and((int) $d->sponsor_id)->toBe($oldParentId);

    $closure = DB::table('genealogy_closure')->where('descendant_id', $applicantId)
        ->orderBy('depth')->get()->map(fn ($r) => [(int) $r->ancestor_id, (int) $r->depth])->all();
    expect($closure)->toBe([[$applicantId, 0], [$newParentId, 1], [$rootId, 2]]);

    $req = LineChangeRequest::find($reqId);
    expect($req->status)->toBe('approved')
        ->and($req->chosen_side)->toBe('L')
        ->and((int) $req->reviewed_by)->toBe($admin->id)
        ->and($req->reviewed_at)->not->toBeNull()
        ->and($req->approved_at)->not->toBeNull();

    Event::assertDispatched(LineChangeApproved::class, fn ($e) => $e->distributorId === $applicantId && $e->chosenSide === 'L');
    expect(AuditLog::where('action', 'genealogy.line_change.approved')->where('subject_id', $applicantId)->exists())->toBeTrue();
});

it('ALC-02: approving onto a taken side throws slot-full', function () {
    $rootId = alcSeed(alcUser('root')->id, 40);
    $newParentId = alcSeed(alcUser('new')->id, 25, parentId: $rootId);
    $blocker = alcSeed(alcUser('blk')->id, 20, parentId: $newParentId);
    DB::table('distributors')->where('id', $blocker)->update(['placement_side' => 'L']);

    $applicantId = alcSeed(alcUser('app')->id, 2, parentId: $rootId);
    $reqId = alcPendingRequest($applicantId, $rootId, $newParentId);

    expect(fn () => app(ApproveLineChange::class)($reqId, alcUser('admin')->id, 'L'))
        ->toThrow(LineChangePlacementSlotFullError::class);

    expect(LineChangeRequest::find($reqId)->status)->toBe('pending');
});
