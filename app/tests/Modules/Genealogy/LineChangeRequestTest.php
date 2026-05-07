<?php

declare(strict_types=1);

use App\Modules\Compliance\Models\AuditLog;
use App\Modules\Genealogy\Events\LineChangeRequested;
use App\Modules\Genealogy\Models\LineChangeRequest;
use App\Modules\Genealogy\Services\Exceptions\LineChangeAlreadyRequestedError;
use App\Modules\Genealogy\Services\Exceptions\LineChangeHasDownlineError;
use App\Modules\Genealogy\Services\Exceptions\LineChangeNewSponsorTooNewError;
use App\Modules\Genealogy\Services\Exceptions\LineChangeWindowExpiredError;
use App\Modules\Genealogy\Services\RequestLineChange;
use App\Modules\Identity\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

uses(RefreshDatabase::class);

/**
 * T&C §10: a distributor may request a line-change within 5 working days
 * of registration, provided they have no downline (and no purchases — which
 * is a no-op in Phase 1 since Commerce isn't yet a registration concern).
 */
function lcrSeed(int $userId, ?int $effectiveAtBusinessDaysAgo = null, ?int $sponsorId = null): int
{
    DB::statement('SET FOREIGN_KEY_CHECKS=0');
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
        DB::statement('SET FOREIGN_KEY_CHECKS=1');
    }

    DB::table('genealogy_closure')->insert([
        'ancestor_id' => $sponsorId ?? $id,
        'descendant_id' => $id,
        'depth' => $sponsorId === null ? 0 : 1,
    ]);
    if ($sponsorId !== null) {
        DB::table('genealogy_closure')->insert([
            'ancestor_id' => $id, 'descendant_id' => $id, 'depth' => 0,
        ]);
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
        toSponsorId: $newSponsorId,
        actorUserId: $applicantUser->id,
        reason: 'requested by applicant',
    );

    $row = LineChangeRequest::query()->where('distributor_id', $applicantId)->firstOrFail();
    expect($row->status)->toBe('pending')
        ->and($row->from_sponsor_id)->toBe($rootId)
        ->and($row->to_sponsor_id)->toBe($newSponsorId);

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
        toSponsorId: $newSponsorId,
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
        toSponsorId: $newSponsorId,
        actorUserId: $applicantUser->id,
    ))->toThrow(LineChangeHasDownlineError::class);
});

it('LCR-05: 5 weekdays + a few hours still inside the window (boundary-floor semantics)', function () {
    // Effective date is 5 weekdays + 4 hours ago. diffInWeekdays returns
    // ~5.17 → (int) 5 → still <= 5. The window is inclusive at the boundary.
    $rootId = lcrSeed(lcrUser('root')->id, effectiveAtBusinessDaysAgo: 30);
    // New sponsor joined ~10 weekdays ago, before the applicant's
    // 5-weekday-and-4-hour effective_date.
    $newSponsorId = lcrSeed(lcrUser('newSp')->id, effectiveAtBusinessDaysAgo: 10);

    $applicantUser = lcrUser('app');
    $applicantId = lcrSeed($applicantUser->id, sponsorId: $rootId);
    DB::table('distributors')->where('id', $applicantId)->update([
        'effective_date' => now()->subWeekdays(5)->subHours(4)->format('Y-m-d H:i:s.v'),
    ]);

    app(RequestLineChange::class)(
        distributorId: $applicantId,
        toSponsorId: $newSponsorId,
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
        toSponsorId: $newSponsorId,
        actorUserId: $applicantUser->id,
    );

    expect(fn () => app(RequestLineChange::class)(
        distributorId: $applicantId,
        toSponsorId: $newSponsorId,
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
        toSponsorId: $newerSponsorId,
        actorUserId: $applicantUser->id,
    ))->toThrow(LineChangeNewSponsorTooNewError::class);

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
        toSponsorId: $sameDayId,
        actorUserId: $applicantUser->id,
    ))->toThrow(LineChangeNewSponsorTooNewError::class);
});
