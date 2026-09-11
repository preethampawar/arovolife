<?php

declare(strict_types=1);

/**
 * The one helper every audit call site uses for before/after state (F108).
 *
 * AD-01: a digest is 32 raw bytes, never hex
 * AD-02: null state (a create) digests to null
 * AD-03: key order does not change the digest
 * AD-04: an int and its string form are the same state
 * AD-05: `updated_at` alone never moves the digest
 * AD-06: a real change does move the digest
 * AD-07: an identity number never reaches the digest input in the clear
 * AD-08: `$only` pins the digest to the fields an action governs
 */

use App\Modules\Compliance\Models\AuditLog;
use App\Modules\Compliance\Support\AuditDigests;
use App\Modules\Identity\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('AD-01: returns 32 raw bytes, never hex', function () {
    $digest = AuditDigests::of(['status' => 'pending']);

    expect($digest)->not->toBeNull()
        ->and(strlen((string) $digest))->toBe(32);
});

it('AD-02: digests a missing before-state to null', function () {
    expect(AuditDigests::of(null))->toBeNull();
});

it('AD-03: is blind to key order', function () {
    expect(AuditDigests::of(['a' => 1, 'b' => 2]))
        ->toBe(AuditDigests::of(['b' => 2, 'a' => 1]));
});

it('AD-04: treats a scalar and its string form as the same state', function () {
    // A model just written holds ints where the same row read back holds
    // strings. Without this, an untouched column would look like a change.
    expect(AuditDigests::of(['id' => 7, 'ok' => true]))
        ->toBe(AuditDigests::of(['id' => '7', 'ok' => '1']));
});

it('AD-05: ignores updated_at, AD-06: but not a real change', function () {
    $user = User::factory()->create(['status' => 'pending']);

    $before = AuditDigests::of($user);

    $user->updated_at = now()->addDay();
    expect(AuditDigests::of($user))->toBe($before);

    $user->status = 'active';
    expect(AuditDigests::of($user))->not->toBe($before);
});

it('AD-07: keeps an identity number out of the digest input', function () {
    // The digest must not become a lookup oracle over a PAN-sized space.
    $masked = AuditDigests::of(['pan_number' => 'AAAAA0000A']);

    expect($masked)->toBe(AuditDigests::of(['pan_number' => 'ZZZZZ000A']))
        ->and($masked)->not->toBe(AuditDigests::of(['pan_number' => 'AAAAA0000B']))
        ->and(AuditDigests::of(['password_hash' => 'one']))
        ->toBe(AuditDigests::of(['password_hash' => 'another']));
});

it('AD-08: pins a snapshot to the named fields', function () {
    $user = User::factory()->create(['status' => 'pending']);

    expect(AuditDigests::snapshot($user, ['status']))->toBe(['status' => 'pending']);
});

it('AD-09: stores what it returns without the model rewriting it', function () {
    $entry = AuditLog::create([
        'action' => 'test.digested',
        'subject_type' => 'user',
        'subject_id' => 1,
        'before_hash' => AuditDigests::of(['status' => 'pending']),
        'after_hash' => AuditDigests::of(['status' => 'active']),
        'details' => [],
    ]);

    expect(strlen((string) $entry->fresh()->before_hash))->toBe(32)
        ->and(strlen((string) $entry->fresh()->after_hash))->toBe(32);
});
