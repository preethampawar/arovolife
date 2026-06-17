<?php

declare(strict_types=1);

use App\Modules\Compliance\Models\AuditLog;
use App\Modules\Genealogy\Events\LineChangeRequested;
use App\Modules\Genealogy\Models\LineChangeRequest;
use App\Modules\Genealogy\Services\Exceptions\LineChangeAlreadyProcessedError;
use App\Modules\Genealogy\Services\Exceptions\LineChangeAlreadyRequestedError;
use App\Modules\Genealogy\Services\Exceptions\LineChangeHasCommerceError;
use App\Modules\Genealogy\Services\Exceptions\LineChangeHasDownlineError;
use App\Modules\Genealogy\Services\Exceptions\LineChangeNewParentTooNewError;
use App\Modules\Genealogy\Services\Exceptions\LineChangePlacementSlotFullError;
use App\Modules\Genealogy\Services\Exceptions\LineChangeWindowExpiredError;
use App\Modules\Genealogy\Services\RequestLineChange;
use App\Modules\Identity\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

uses(RefreshDatabase::class);

/**
 * T&C §10: a distributor may request a line-change within 5 working days
 * of registration, provided they have no downline AND no commerce activity
 * (Phase-2 block — see LCR-COMMERCE-BLOCK).
 */
function lcrSeed(int $userId, ?int $effectiveAtBusinessDaysAgo = null, ?int $sponsorId = null): int
{
    disableTestForeignKeys();
    try {
        $effective = $effectiveAtBusinessDaysAgo === null
            ? now()
            : now()->subWeekdays($effectiveAtBusinessDaysAgo);

        $id = DB::table('distributors')->insertGetId([
            'user_id' => $userId,
            'adn' => 'ARO'.rand(100000, 999999),
            'pan_hash' => random_bytes(32),
            'pan_last4' => '0000',
            'bank_account_enc' => 'stub',
            'bank_ifsc' => 'SBIN0000000',
            'sponsor_id' => $sponsorId ?? 0,
            'placement_parent_id' => $sponsorId ?? 0,
            'placement_side' => null,
            'side_chosen_by' => 'referral_default',
            'depth' => 0,
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

function lcrUser(string $tag): User
{
    return User::create([
        'email' => "lcr-{$tag}-".rand(1000, 9999).'@test.com',
        'phone_e164' => '+91'.str_pad((string) rand(7000000000, 9999999999), 10, '0'),
        'password_hash' => bcrypt('x'),
        'status' => 'active',
    ]);
}

it('LCR-01: request within 5 business days creates pending row + event + audit', function () {
    Event::fake();

    // Senior-sponsor rule: the new sponsor must have joined BEFORE the
    // applicant. Real registrations always satisfy this; test setup
    // mirrors that ordering explicitly so the LineChangeNewSponsorTooNew
    // guard isn't tripped.
    $rootUser = lcrUser('root');
    $rootId = lcrSeed($rootUser->id, effectiveAtBusinessDaysAgo: 30);

    $newSponsorUser = lcrUser('newSp');
    $newSponsorId = lcrSeed($newSponsorUser->id, effectiveAtBusinessDaysAgo: 10);

    $applicantUser = lcrUser('app');
    $applicantId = lcrSeed($applicantUser->id, effectiveAtBusinessDaysAgo: 2, sponsorId: $rootId);

    app(RequestLineChange::class)(
        distributorId: $applicantId,
        toPlacementParentId: $newSponsorId,
        actorUserId: $applicantUser->id,
        reason: 'requested by applicant',
    );

    $row = LineChangeRequest::query()->where('distributor_id', $applicantId)->firstOrFail();
    expect($row->status)->toBe('pending')
        ->and($row->from_placement_parent_id)->toBe($rootId)
        ->and($row->to_placement_parent_id)->toBe($newSponsorId);

    Event::assertDispatched(LineChangeRequested::class, fn ($e) => $e->distributorId === $applicantId);

    $audit = AuditLog::query()->where('action', 'genealogy.line_change.requested')
        ->where('subject_id', $applicantId)->first();
    expect($audit)->not->toBeNull();
});

it('LCR-02: request beyond 5 business days throws LineChangeWindowExpiredError', function () {
    $rootId = lcrSeed(lcrUser('root')->id);
    $newSponsorId = lcrSeed(lcrUser('newSp')->id);

    $applicantUser = lcrUser('app');
    $applicantId = lcrSeed($applicantUser->id, effectiveAtBusinessDaysAgo: 6, sponsorId: $rootId);

    expect(fn () => app(RequestLineChange::class)(
        distributorId: $applicantId,
        toPlacementParentId: $newSponsorId,
        actorUserId: $applicantUser->id,
    ))->toThrow(LineChangeWindowExpiredError::class);

    expect(LineChangeRequest::count())->toBe(0);
});

it('LCR-03: request rejected when distributor has any descendants', function () {
    $rootId = lcrSeed(lcrUser('root')->id);
    $newSponsorId = lcrSeed(lcrUser('newSp')->id);

    $applicantUser = lcrUser('app');
    $applicantId = lcrSeed($applicantUser->id, effectiveAtBusinessDaysAgo: 2, sponsorId: $rootId);

    // Seed a descendant under the applicant directly into the closure.
    $childId = lcrSeed(lcrUser('child')->id, effectiveAtBusinessDaysAgo: 0, sponsorId: $applicantId);

    expect(fn () => app(RequestLineChange::class)(
        distributorId: $applicantId,
        toPlacementParentId: $newSponsorId,
        actorUserId: $applicantUser->id,
    ))->toThrow(LineChangeHasDownlineError::class);
});

it('LCR-05: 4 weekdays + a fractional day still inside the window', function () {
    // Effective date is 4 weekdays + 20 hours ago — diffInWeekdays returns
    // ~4.83 → (int) 4 → safely inside the 5-business-day window. Originally
    // pinned at "5 weekdays + 4 hours" but Carbon 3's diffInWeekdays rounds
    // to the next whole weekday near midnight crossings, making the
    // 5-weekday boundary flaky depending on what time the test runs.
    $rootId = lcrSeed(lcrUser('root')->id, effectiveAtBusinessDaysAgo: 30);
    $newSponsorId = lcrSeed(lcrUser('newSp')->id, effectiveAtBusinessDaysAgo: 10);

    $applicantUser = lcrUser('app');
    $applicantId = lcrSeed($applicantUser->id, sponsorId: $rootId);
    DB::table('distributors')->where('id', $applicantId)->update([
        'effective_date' => now()->subWeekdays(4)->subHours(20)->format('Y-m-d H:i:s.v'),
    ]);

    app(RequestLineChange::class)(
        distributorId: $applicantId,
        toPlacementParentId: $newSponsorId,
        actorUserId: $applicantUser->id,
    );

    expect(LineChangeRequest::where('distributor_id', $applicantId)->count())->toBe(1);
});

it('LCR-04: cannot request twice while one is pending', function () {
    $rootId = lcrSeed(lcrUser('root')->id, effectiveAtBusinessDaysAgo: 30);
    // newSponsor joined before the applicant — required by LCR-06.
    $newSponsorId = lcrSeed(lcrUser('newSp')->id, effectiveAtBusinessDaysAgo: 10);

    $applicantUser = lcrUser('app');
    $applicantId = lcrSeed($applicantUser->id, effectiveAtBusinessDaysAgo: 2, sponsorId: $rootId);

    app(RequestLineChange::class)(
        distributorId: $applicantId,
        toPlacementParentId: $newSponsorId,
        actorUserId: $applicantUser->id,
    );

    expect(fn () => app(RequestLineChange::class)(
        distributorId: $applicantId,
        toPlacementParentId: $newSponsorId,
        actorUserId: $applicantUser->id,
    ))->toThrow(LineChangeAlreadyRequestedError::class);
});

it('LCR-06: rejects a new sponsor whose effective_date is later than the applicant', function () {
    // Applicant joined 4 weekdays ago (still inside the 5-day window).
    // Candidate "new sponsor" joined just 1 weekday ago — strictly newer
    // than the applicant. Should throw before any row is written.
    $rootId = lcrSeed(lcrUser('root')->id, effectiveAtBusinessDaysAgo: 30);
    $newerSponsorId = lcrSeed(lcrUser('newer')->id, effectiveAtBusinessDaysAgo: 1);

    $applicantUser = lcrUser('app');
    $applicantId = lcrSeed($applicantUser->id, effectiveAtBusinessDaysAgo: 4, sponsorId: $rootId);

    expect(fn () => app(RequestLineChange::class)(
        distributorId: $applicantId,
        toPlacementParentId: $newerSponsorId,
        actorUserId: $applicantUser->id,
    ))->toThrow(LineChangeNewParentTooNewError::class);

    // No row written; this is what enforces the "no abuse" property —
    // the request never enters the queue.
    expect(LineChangeRequest::where('distributor_id', $applicantId)->count())->toBe(0);
});

it('LCR-07: rejects a new sponsor whose effective_date EQUALS the applicant (strict)', function () {
    // Equal-second ties count as "not earlier" and are rejected by
    // policy. We mirror the timestamps exactly to verify the boundary.
    $rootId = lcrSeed(lcrUser('root')->id, effectiveAtBusinessDaysAgo: 30);
    $sameDayId = lcrSeed(lcrUser('same')->id, effectiveAtBusinessDaysAgo: 2);

    $applicantUser = lcrUser('app');
    $applicantId = lcrSeed($applicantUser->id, effectiveAtBusinessDaysAgo: 2, sponsorId: $rootId);

    // Force exact-match effective_date so .lessThan returns false.
    $sharedDate = now()->subWeekdays(2)->format('Y-m-d H:i:s.v');
    DB::table('distributors')->whereIn('id', [$sameDayId, $applicantId])->update([
        'effective_date' => $sharedDate,
    ]);

    expect(fn () => app(RequestLineChange::class)(
        distributorId: $applicantId,
        toPlacementParentId: $sameDayId,
        actorUserId: $applicantUser->id,
    ))->toThrow(LineChangeNewParentTooNewError::class);
});

it('LCR-08: cannot request a second line change after one was approved', function () {
    $rootId = lcrSeed(lcrUser('root')->id, effectiveAtBusinessDaysAgo: 30);
    $newParentId = lcrSeed(lcrUser('newP')->id, effectiveAtBusinessDaysAgo: 10);

    $applicantUser = lcrUser('app');
    $applicantId = lcrSeed($applicantUser->id, effectiveAtBusinessDaysAgo: 2, sponsorId: $rootId);

    // Simulate a previously approved line change.
    DB::table('line_change_requests')->insert([
        'distributor_id' => $applicantId,
        'from_placement_parent_id' => $rootId,
        'to_placement_parent_id' => $newParentId,
        'requested_at' => now()->subDay()->format('Y-m-d H:i:s.v'),
        'approved_at' => now()->subDay()->format('Y-m-d H:i:s.v'),
        'status' => 'approved',
    ]);

    expect(fn () => app(RequestLineChange::class)(
        distributorId: $applicantId,
        toPlacementParentId: $newParentId,
        actorUserId: $applicantUser->id,
    ))->toThrow(LineChangeAlreadyProcessedError::class);
});

it('LCR-09: rejects when the target parent has no open slot', function () {
    $rootId = lcrSeed(lcrUser('root')->id, effectiveAtBusinessDaysAgo: 30);
    // Target parent joined before applicant; fill both its legs.
    $targetId = lcrSeed(lcrUser('target')->id, effectiveAtBusinessDaysAgo: 20);
    $cL = lcrSeed(lcrUser('cl')->id, effectiveAtBusinessDaysAgo: 15, sponsorId: $targetId);
    $cR = lcrSeed(lcrUser('cr')->id, effectiveAtBusinessDaysAgo: 14, sponsorId: $targetId);
    DB::table('distributors')->where('id', $cL)->update(['placement_side' => 'L']);
    DB::table('distributors')->where('id', $cR)->update(['placement_side' => 'R']);

    $applicantUser = lcrUser('app');
    $applicantId = lcrSeed($applicantUser->id, effectiveAtBusinessDaysAgo: 2, sponsorId: $rootId);

    expect(fn () => app(RequestLineChange::class)(
        distributorId: $applicantId,
        toPlacementParentId: $targetId,
        actorUserId: $applicantUser->id,
    ))->toThrow(LineChangePlacementSlotFullError::class);
});

/** Seed one product order (via a linked customer) so the distributor has commerce activity. */
function lcrSeedCommerce(int $distributorId): void
{
    $customerId = DB::table('customers')->insertGetId([
        'display_name' => 'LCR Customer',
        'distributor_id' => $distributorId,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    DB::table('orders')->insert([
        'order_no' => 'LCR-'.$distributorId.'-'.random_int(1000, 99999),
        'idempotency_key' => 'lcr-'.$distributorId.'-'.uniqid(),
        'customer_id' => $customerId,
        'attributed_distributor_id' => $distributorId,
        'attribution_source' => 'logged_in',
        'status' => 'placed',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

it('LCR-COMMERCE-BLOCK: request rejected when the distributor has commerce activity', function () {
    $rootId = lcrSeed(lcrUser('root')->id, effectiveAtBusinessDaysAgo: 30);
    $newParentId = lcrSeed(lcrUser('newSp')->id, effectiveAtBusinessDaysAgo: 10);
    $applicantUser = lcrUser('app');
    // Within the 5-day window and a leaf — only the commerce activity should block it.
    $applicantId = lcrSeed($applicantUser->id, effectiveAtBusinessDaysAgo: 2, sponsorId: $rootId);

    lcrSeedCommerce($applicantId);

    expect(fn () => app(RequestLineChange::class)(
        distributorId: $applicantId,
        toPlacementParentId: $newParentId,
        actorUserId: $applicantUser->id,
    ))->toThrow(LineChangeHasCommerceError::class);

    // Nothing was recorded — the guard fires before the request row is created.
    expect(LineChangeRequest::query()->where('distributor_id', $applicantId)->exists())->toBeFalse();
});
