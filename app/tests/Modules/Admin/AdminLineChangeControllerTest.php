<?php

declare(strict_types=1);

use App\Modules\Genealogy\Models\LineChangeRequest;
use App\Modules\Identity\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

function adminUser(): User
{
    Role::findOrCreate('admin', 'web');
    $u = User::create([
        'email' => 'lc-admin-'.rand(1000, 9999).'@test.com',
        'phone_e164' => '+91'.str_pad((string) rand(7000000000, 9999999999), 10, '0'),
        'password_hash' => bcrypt('x'),
        'status' => 'active',
    ]);
    $u->assignRole('admin');

    return $u;
}

function adminLcSeed(int $businessDaysAgo, ?int $parentId = null): int
{
    disableTestForeignKeys();
    try {
        $u = User::create([
            'email' => 'lc-d-'.rand(1000, 9999).'@test.com',
            'phone_e164' => '+91'.str_pad((string) rand(7000000000, 9999999999), 10, '0'),
            'password_hash' => bcrypt('x'),
            'status' => 'active',
        ]);
        $effective = now()->subWeekdays($businessDaysAgo);
        $id = DB::table('distributors')->insertGetId([
            'user_id' => $u->id,
            'adn' => (string) rand(100000000, 999999999),
            'pan_hash' => random_bytes(32),
            'pan_last4' => '0000',
            'bank_account_enc' => 'stub',
            'bank_ifsc' => 'SBIN0000000',
            'sponsor_id' => $parentId ?? 0,
            'placement_parent_id' => $parentId ?? 0,
            'placement_side' => null,
            'side_chosen_by' => 'referral_default',
            'depth' => $parentId === null ? 0 : 1,
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
        $anc = DB::table('genealogy_closure')->where('descendant_id', $parentId)->get(['ancestor_id', 'depth']);
        foreach ($anc as $a) {
            DB::table('genealogy_closure')->insert(['ancestor_id' => $a->ancestor_id, 'descendant_id' => $id, 'depth' => $a->depth + 1]);
        }
    }

    return $id;
}

it('ALCC-01: index lists pending requests', function () {
    $admin = adminUser();
    $rootId = adminLcSeed(40);
    $targetId = adminLcSeed(25, parentId: $rootId);
    $applicantId = adminLcSeed(2, parentId: $rootId);
    DB::table('line_change_requests')->insert([
        'distributor_id' => $applicantId, 'from_placement_parent_id' => $rootId,
        'to_placement_parent_id' => $targetId, 'requested_at' => now()->format('Y-m-d H:i:s.v'),
        'status' => 'pending', 'reason' => 'move me',
    ]);

    $this->actingAs($admin)->get(route('admin.line-changes.index'))
        ->assertOk()
        ->assertSee('Line-change requests');
});

it('ALCC-02: approve moves placement and marks approved', function () {
    Notification::fake();
    $admin = adminUser();
    $rootId = adminLcSeed(40);
    $targetId = adminLcSeed(25, parentId: $rootId);
    $applicantId = adminLcSeed(2, parentId: $rootId);
    $reqId = DB::table('line_change_requests')->insertGetId([
        'distributor_id' => $applicantId, 'from_placement_parent_id' => $rootId,
        'to_placement_parent_id' => $targetId, 'requested_at' => now()->format('Y-m-d H:i:s.v'),
        'status' => 'pending', 'reason' => 'move me',
    ]);

    $this->actingAs($admin)
        ->post(route('admin.line-changes.approve', $reqId), ['chosen_side' => 'L'])
        ->assertRedirect(route('admin.line-changes.index'));

    expect(LineChangeRequest::find($reqId)->status)->toBe('approved');
    expect((int) DB::table('distributors')->where('id', $applicantId)->value('placement_parent_id'))->toBe($targetId);
});

it('ALCC-03: reject requires a note and marks rejected', function () {
    Notification::fake();
    $admin = adminUser();
    $rootId = adminLcSeed(40);
    $targetId = adminLcSeed(25, parentId: $rootId);
    $applicantId = adminLcSeed(2, parentId: $rootId);
    $reqId = DB::table('line_change_requests')->insertGetId([
        'distributor_id' => $applicantId, 'from_placement_parent_id' => $rootId,
        'to_placement_parent_id' => $targetId, 'requested_at' => now()->format('Y-m-d H:i:s.v'),
        'status' => 'pending', 'reason' => 'move me',
    ]);

    // Missing note → validation error.
    $this->actingAs($admin)
        ->post(route('admin.line-changes.reject', $reqId), ['decision_note' => 'short'])
        ->assertSessionHasErrors('decision_note');

    // Valid note → rejected.
    $this->actingAs($admin)
        ->post(route('admin.line-changes.reject', $reqId), ['decision_note' => 'Target leg is not eligible for this move.'])
        ->assertRedirect(route('admin.line-changes.index'));

    expect(LineChangeRequest::find($reqId)->status)->toBe('rejected');
});

it('ALCC-04: approve onto a taken slot fails and request stays pending', function () {
    Notification::fake();
    $admin = adminUser();
    $rootId = adminLcSeed(40);
    $targetId = adminLcSeed(25, parentId: $rootId);

    // Fill both legs under the target parent.
    $childL = adminLcSeed(10, parentId: $targetId);
    $childR = adminLcSeed(10, parentId: $targetId);
    DB::table('distributors')->where('id', $childL)->update(['placement_side' => 'L']);
    DB::table('distributors')->where('id', $childR)->update(['placement_side' => 'R']);

    $applicantId = adminLcSeed(2, parentId: $rootId);
    $reqId = DB::table('line_change_requests')->insertGetId([
        'distributor_id' => $applicantId, 'from_placement_parent_id' => $rootId,
        'to_placement_parent_id' => $targetId, 'requested_at' => now()->format('Y-m-d H:i:s.v'),
        'status' => 'pending', 'reason' => 'move me',
    ]);

    // The show page must render the reject form even when no leg is free.
    $this->actingAs($admin)->get(route('admin.line-changes.show', $reqId))
        ->assertOk()
        ->assertSee('Reject');

    $this->actingAs($admin)
        ->post(route('admin.line-changes.approve', $reqId), ['chosen_side' => 'L'])
        ->assertSessionHasErrors('chosen_side');

    expect(LineChangeRequest::find($reqId)->status)->toBe('pending');
    expect((int) DB::table('distributors')->where('id', $applicantId)->value('placement_parent_id'))->toBe($rootId);
});

it('ALCC-05: guest cannot access the queue', function () {
    $this->get(route('admin.line-changes.index'))->assertStatus(302);
});

it('ALCC-06: rejecting an already-decided request redirects without a 500', function () {
    Notification::fake();
    $admin = adminUser();
    $rootId = adminLcSeed(40);
    $targetId = adminLcSeed(25, parentId: $rootId);
    $applicantId = adminLcSeed(2, parentId: $rootId);
    $reqId = DB::table('line_change_requests')->insertGetId([
        'distributor_id' => $applicantId, 'from_placement_parent_id' => $rootId,
        'to_placement_parent_id' => $targetId, 'requested_at' => now()->format('Y-m-d H:i:s.v'),
        'status' => 'approved', 'reason' => 'move me',
    ]);

    $this->actingAs($admin)
        ->post(route('admin.line-changes.reject', $reqId), ['decision_note' => 'A perfectly valid rejection note.'])
        ->assertRedirect(route('admin.line-changes.index'));

    expect(LineChangeRequest::find($reqId)->status)->toBe('approved');
});
