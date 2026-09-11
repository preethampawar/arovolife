<?php

declare(strict_types=1);

/**
 * The consent record a distributor can actually see, and the agreements
 * registry behind it (F72; DPDP 2023 §5, §6).
 *
 * Staging holds 1,144 acceptance rows across two version schemes — `1.0.0`
 * from the Phase-1 stubs and dated versions like `2026-08-30` from
 * `ConsentDocuments` — and zero rows in `agreements`. So the platform could
 * say a distributor accepted `tnc` version `1.0.0` and could not say what
 * `1.0.0` was, and the distributor could see neither: the profile linked the
 * Privacy Policy and nothing else.
 *
 * CR-01: the record lists every document accepted, both version schemes
 * CR-02: it shows the acceptance IP and marks withdrawn rows as withdrawn
 * CR-03: it is the signed-in distributor's own record and nobody else's
 * CR-04: an account with no rows is told so, rather than shown an empty table
 * CR-05: the profile links to it
 * CR-06: the backfill derives one agreement per accepted version
 * CR-07: the backfill is idempotent — a second run writes nothing
 * CR-08: the backfill chains each type's versions in effective order
 * CR-09: two texts under one version string are reported, not silently merged
 */

use App\Modules\Consent\Models\Agreement;
use App\Modules\Consent\Models\Consent;
use App\Modules\Consent\Services\WithdrawConsent;
use App\Modules\Identity\Models\Distributor;
use App\Modules\Identity\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    seedConsentDocuments();
    disableTestForeignKeys();
});

/** An acceptance row, written straight in so the test can pick the scheme. */
function crConsent(Distributor $distributor, string $type, string $version, string $acceptedAt, string $hashSeed = 'body'): Consent
{
    return Consent::create([
        'distributor_id' => $distributor->id,
        'document_type' => $type,
        'document_version' => $version,
        'doc_hash_sha256' => hash('sha256', $hashSeed, true),
        'accepted_at' => $acceptedAt,
        'ip' => '203.0.113.7',
        'user_agent' => 'test',
    ]);
}

/** A distributor carrying rows under both version schemes, as staging does. */
function crDistributor(): Distributor
{
    $user = User::factory()->create(['status' => 'active']);
    $distributor = Distributor::factory()->create(['user_id' => $user->id, 'status' => 'active']);

    crConsent($distributor, 'tnc', '1.0.0', '2026-05-21 10:00:00', 'tnc-old');
    crConsent($distributor, 'ethics', '1.0.0', '2026-05-21 10:00:00', 'ethics-old');
    crConsent($distributor, 'plan', '2026-08-30', '2026-08-30 09:15:00', 'plan-new');
    crConsent($distributor, 'privacy', '2026-08-30', '2026-08-30 09:15:00', 'privacy-new');

    return $distributor->fresh();
}

it('CR-01: the record lists every document accepted, under both version schemes', function () {
    $distributor = crDistributor();

    $this->actingAs($distributor->user)
        ->get(route('consent.record'))
        ->assertOk()
        // The title each document gives itself, taken from the published page.
        ->assertSee('Direct Seller Agreement &amp; Terms of Service', false)
        ->assertSee('Code of Ethics')
        ->assertSee('Compensation Plan Disclosure')
        ->assertSee('Privacy Policy')
        // Both schemes, exactly as recorded — the version in the row, not the
        // version published today.
        ->assertSee('1.0.0')
        ->assertSee('2026-08-30')
        ->assertSee('21 May 2026')
        // A link to the document itself, not a paraphrase of it.
        ->assertSee('/p/terms')
        ->assertSee('/p/privacy');
});

it('CR-02: it shows the acceptance IP and marks a withdrawn row withdrawn', function () {
    $distributor = crDistributor();

    $this->actingAs($distributor->user)
        ->get(route('consent.record'))
        ->assertOk()
        // Part of the electronic record under IT Act §10A, and shown only to
        // the person it belongs to.
        ->assertSee('203.0.113.7')
        ->assertSee('In force');

    app(WithdrawConsent::class)->execute($distributor, 'Done.');

    $this->actingAs($distributor->user->fresh())
        ->get(route('consent.record'))
        ->assertOk()
        ->assertSee('Withdrawn')
        ->assertDontSee('In force')
        // Withdrawal is not deletion: the acceptance stays visible.
        ->assertSee('1.0.0');
});

it('CR-03: the record belongs to the signed-in distributor', function () {
    $mine = crDistributor();

    $other = User::factory()->create(['status' => 'active']);
    $otherDistributor = Distributor::factory()->create(['user_id' => $other->id, 'status' => 'active']);
    crConsent($otherDistributor, 'tnc', '2026-01-01', '2026-01-01 08:00:00', 'someone-else');

    $this->actingAs($mine->user)
        ->get(route('consent.record'))
        ->assertOk()
        ->assertDontSee('2026-01-01');

    // A user with no distributor row has no record here.
    $this->actingAs(User::factory()->create())
        ->get(route('consent.record'))
        ->assertNotFound();
});

it('CR-04: an account with no rows is told so, not shown an empty table', function () {
    $user = User::factory()->create(['status' => 'active']);
    Distributor::factory()->create(['user_id' => $user->id, 'status' => 'active']);

    // "You consented to nothing" and "we did not write it down" are very
    // different statements, and only one of them is true.
    $this->actingAs($user)
        ->get(route('consent.record'))
        ->assertOk()
        ->assertSee('do not hold a dated acceptance record')
        ->assertSee('Direct Seller Agreement &amp; Terms of Service', false)
        // The withdrawal route stays open — a missing row is live consent.
        ->assertSee('Withdraw consent');
});

it('CR-05: the profile links to the record', function () {
    $distributor = crDistributor();

    $this->actingAs($distributor->user)
        ->get(route('profile.show'))
        ->assertOk()
        ->assertSee('My consents &amp; agreements', false);
});

it('CR-06: the backfill derives one agreement per accepted version', function () {
    $distributor = crDistributor();
    // A second distributor on the same versions must not produce duplicates.
    $second = User::factory()->create(['status' => 'active']);
    $secondDistributor = Distributor::factory()->create(['user_id' => $second->id, 'status' => 'active']);
    crConsent($secondDistributor, 'tnc', '1.0.0', '2026-06-02 11:00:00', 'tnc-old');

    expect(Agreement::count())->toBe(0);

    $this->artisan('consent:backfill-agreements')->assertExitCode(0);

    expect(Agreement::count())->toBe(4);

    $tnc = Agreement::where('type', 'tnc')->where('version', '1.0.0')->firstOrFail();

    expect($tnc->pdf_hash)->toBe(hash('sha256', 'tnc-old', true))
        // The earliest acceptance is when the document took effect, not the
        // latest one this backfill happened to read.
        ->and($tnc->effective_from->format('Y-m-d H:i'))->toBe('2026-05-21 10:00')
        ->and($distributor->fresh()->id)->not->toBeNull();
});

it('CR-07: the backfill is idempotent', function () {
    crDistributor();

    $this->artisan('consent:backfill-agreements')->assertExitCode(0);

    $before = Agreement::query()->orderBy('id')->get()
        ->map(fn (Agreement $a): string => $a->type.'|'.$a->version.'|'.$a->effective_from->toString().'|'.bin2hex((string) $a->pdf_hash))
        ->all();
    $maxId = (int) Agreement::max('id');

    // Safe in a deploy script without a "has this run yet" flag.
    $this->artisan('consent:backfill-agreements')->assertExitCode(0);
    $this->artisan('consent:backfill-agreements')->assertExitCode(0);

    $after = Agreement::query()->orderBy('id')->get()
        ->map(fn (Agreement $a): string => $a->type.'|'.$a->version.'|'.$a->effective_from->toString().'|'.bin2hex((string) $a->pdf_hash))
        ->all();

    expect($after)->toBe($before)
        ->and((int) Agreement::max('id'))->toBe($maxId);
});

it('CR-08: the backfill chains each type versions in effective order', function () {
    $distributor = crDistributor();
    crConsent($distributor, 'tnc', '2026-08-30', '2026-08-30 09:15:00', 'tnc-new');

    $this->artisan('consent:backfill-agreements')->assertExitCode(0);

    $old = Agreement::where('type', 'tnc')->where('version', '1.0.0')->firstOrFail();
    $new = Agreement::where('type', 'tnc')->where('version', '2026-08-30')->firstOrFail();

    // The registry answers "what did this replace", not only "what was it".
    expect($old->supersedes_id)->toBeNull()
        ->and($new->supersedes_id)->toBe($old->id);
});

it('CR-09: two texts under one version string are reported, not silently merged', function () {
    $distributor = crDistributor();

    $second = User::factory()->create(['status' => 'active']);
    $secondDistributor = Distributor::factory()->create(['user_id' => $second->id, 'status' => 'active']);
    // Same version string, different document body — the exact drift that
    // versioned consent exists to make impossible.
    crConsent($secondDistributor, 'tnc', '1.0.0', '2026-07-01 09:00:00', 'tnc-edited');

    $this->artisan('consent:backfill-agreements')
        ->expectsOutputToContain('different document')
        ->assertExitCode(0);

    // One row per version, carrying the earliest text.
    expect(Agreement::where('type', 'tnc')->count())->toBe(1)
        ->and(Agreement::where('type', 'tnc')->firstOrFail()->pdf_hash)
        ->toBe(hash('sha256', 'tnc-old', true))
        ->and($distributor->fresh()->id)->not->toBeNull();
});
