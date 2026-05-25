<?php

declare(strict_types=1);

use App\Modules\Compliance\Events\CoolingOffCancelled;
use App\Modules\Compliance\Models\AuditLog;
use App\Modules\Compliance\Models\CoolingOffEvent;
use App\Modules\Compliance\Services\CancelCoolingOff;
use App\Modules\Compliance\Services\Exceptions\CoolingOffAlreadyCancelledError;
use App\Modules\Compliance\Services\Exceptions\CoolingOffWindowExpiredError;
use App\Modules\Identity\Models\Distributor;
use App\Modules\Identity\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

uses(RefreshDatabase::class);

/**
 * Statutory: a distributor may cancel within 30 days of Effective Date
 * (T&C §4, DSR 2021 Rule 5(1)(g)). One-click. Audit-logged. Event-emitted.
 */
function seedActiveDistributor(int $daysSinceJoin = 5): array
{
    $user = User::create([
        'email' => 'd'.rand(1000, 9999).'@test.com',
        'phone_e164' => '+91'.str_pad((string) rand(7000000000, 9999999999), 10, '0'),
        'password_hash' => bcrypt('x'),
        'status' => 'active',
    ]);

    disableTestForeignKeys();
    try {
        $id = DB::table('distributors')->insertGetId([
            'user_id' => $user->id,
            'adn' => 'ARO'.rand(100000, 999999),
            'pan_hash' => random_bytes(32),
            'pan_last4' => '0000',
            'bank_account_enc' => 'stub',
            'bank_ifsc' => 'SBIN0000000',
            'sponsor_id' => 0,
            'placement_parent_id' => 0,
            'placement_side' => null,
            'side_chosen_by' => 'referral_default',
            'depth' => 0,
            'effective_date' => now()->subDays($daysSinceJoin)->format('Y-m-d H:i:s.v'),
            'cooling_off_end_at' => now()->subDays($daysSinceJoin)->addDays(30)->format('Y-m-d H:i:s.v'),
            'state' => 'TS',
            'is_primary_couple' => 0,
            'created_at' => now()->format('Y-m-d H:i:s.v'),
            'updated_at' => now()->format('Y-m-d H:i:s.v'),
        ]);
        DB::table('distributors')->where('id', $id)->update([
            'sponsor_id' => $id,
            'placement_parent_id' => $id,
        ]);
    } finally {
        enableTestForeignKeys();
    }

    DB::table('genealogy_closure')->insert([
        'ancestor_id' => $id,
        'descendant_id' => $id,
        'depth' => 0,
    ]);

    CoolingOffEvent::create([
        'distributor_id' => $id,
        'opened_at' => now()->subDays($daysSinceJoin),
    ]);

    return ['user' => $user, 'distributor_id' => $id];
}

it('COC-01: cancel within window flips user.status to terminated and stamps cancelled_at', function () {
    Event::fake();

    [$user, $id] = array_values(seedActiveDistributor(daysSinceJoin: 5));

    app(CancelCoolingOff::class)($id, actorUserId: $user->id);

    $user->refresh();
    expect($user->status)->toBe('terminated')
        // Marks the terminal state as a self-cancellation so the admin UI can
        // label it "Cancelled (cooling-off)" rather than "Terminated".
        ->and($user->closure_type)->toBe('cooling_off_cancellation');

    // The distributor-record flag follows the account into the terminal state
    // (no contradictory "Distributor: Active" pill on a cancelled account).
    expect(DB::table('distributors')->where('id', $id)->value('status'))->toBe('inactive');

    $row = CoolingOffEvent::where('distributor_id', $id)->firstOrFail();
    expect($row->cancelled_at)->not->toBeNull();

    Event::assertDispatched(CoolingOffCancelled::class, fn ($e) => $e->distributorId === $id);
});

it('COC-02: cancel writes an audit_log row', function () {
    [$user, $id] = array_values(seedActiveDistributor(daysSinceJoin: 5));

    app(CancelCoolingOff::class)($id, actorUserId: $user->id);

    $audit = AuditLog::where('action', 'compliance.cooling_off.cancelled')
        ->where('subject_type', 'distributor')
        ->where('subject_id', $id)
        ->first();

    expect($audit)->not->toBeNull()
        ->and($audit->actor_id)->toBe($user->id);
});

it('COC-03: cancel after the 30-day window throws CoolingOffWindowExpiredError', function () {
    [$user, $id] = array_values(seedActiveDistributor(daysSinceJoin: 31));

    expect(fn () => app(CancelCoolingOff::class)($id, actorUserId: $user->id))
        ->toThrow(CoolingOffWindowExpiredError::class);

    // No state change on rejection.
    $user->refresh();
    expect($user->status)->toBe('active')
        ->and(CoolingOffEvent::where('distributor_id', $id)->value('cancelled_at'))->toBeNull();
});

it('COC-04: cancelling twice throws CoolingOffAlreadyCancelledError', function () {
    [$user, $id] = array_values(seedActiveDistributor(daysSinceJoin: 5));

    app(CancelCoolingOff::class)($id, actorUserId: $user->id);

    expect(fn () => app(CancelCoolingOff::class)($id, actorUserId: $user->id))
        ->toThrow(CoolingOffAlreadyCancelledError::class);
});
