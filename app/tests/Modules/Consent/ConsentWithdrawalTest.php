<?php

declare(strict_types=1);

/**
 * Consent withdrawal (C-06, R-52; DPDP 2023 §6(4)-(6)).
 *
 * `privacy.md` has stated since launch that consent is "revocable" and that a
 * data principal "may withdraw it at any time". There was no column, no route,
 * no service and no UI — a published promise the platform could not honour,
 * the same defect class as the franchise collection-point picker (R-47) and
 * the half-price offer (R-49).
 *
 * CWD-01: a distributor can reach their own withdrawal page
 * CWD-02: withdrawal needs the typed confirmation, not a checkbox
 * CWD-03: withdrawing marks every live consent and closes the ADN
 * CWD-04: the acceptance record survives — withdrawal is not deletion
 * CWD-05: it is audited, with the distributor as the actor and their reason
 * CWD-06: a second submission does not terminate twice
 * CWD-07: the withdrawal page belongs to the signed-in distributor
 * CWD-08: the profile links to it plainly rather than hiding it
 */

use App\Modules\Admin\Events\DistributorTerminated;
use App\Modules\Compliance\Models\AuditLog;
use App\Modules\Consent\Models\Consent;
use App\Modules\Consent\Services\ConsentDocuments;
use App\Modules\Consent\Services\WithdrawConsent;
use App\Modules\Identity\Models\Distributor;
use App\Modules\Identity\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    seedConsentDocuments();
    disableTestForeignKeys();
});

/** A distributor with the four consents on file. */
function cwdDistributor(): Distributor
{
    $user = User::factory()->create(['status' => 'active']);
    $distributor = Distributor::factory()->create(['user_id' => $user->id, 'status' => 'active']);

    foreach (app(ConsentDocuments::class)->all() as $type => $document) {
        Consent::create([
            'distributor_id' => $distributor->id,
            'document_type' => $type,
            'document_version' => $document['version'],
            'doc_hash_sha256' => hex2bin($document['hash']),
            'accepted_at' => now()->subMonths(3),
            'ip' => '127.0.0.1',
            'user_agent' => 'test',
        ]);
    }

    return $distributor->fresh();
}

it('CWD-01: a distributor can reach their own withdrawal page', function () {
    $distributor = cwdDistributor();

    $this->actingAs($distributor->user)
        ->get(route('consent.withdraw'))
        ->assertOk()
        // The consequence has to be on the page before the form, not after it.
        ->assertSee('it closes your ADN');
});

it('CWD-02: withdrawal needs the typed confirmation', function () {
    $distributor = cwdDistributor();

    // A checkbox beside a paragraph gets ticked without the paragraph being
    // read, and this ends the distributorship.
    $this->actingAs($distributor->user)
        ->post(route('consent.withdraw.store'), ['confirmation' => 'yes'])
        ->assertSessionHasErrors('confirmation');

    expect(Consent::whereNotNull('withdrawn_at')->count())->toBe(0)
        ->and($distributor->fresh()->status)->toBe('active');
});

it('CWD-03: withdrawing marks every live consent and closes the ADN', function () {
    Event::fake([DistributorTerminated::class]);
    $distributor = cwdDistributor();

    $this->actingAs($distributor->user)
        ->post(route('consent.withdraw.store'), [
            'confirmation' => 'WITHDRAW',
            'reason' => 'No longer selling.',
        ])
        ->assertRedirect(route('login'));

    expect(Consent::where('distributor_id', $distributor->id)->whereNull('withdrawn_at')->count())->toBe(0)
        ->and(Consent::where('distributor_id', $distributor->id)->count())->toBe(4);

    $distributor = $distributor->fresh();
    expect($distributor->status)->toBe('inactive')
        ->and($distributor->terminated_at)->not->toBeNull()
        ->and($distributor->user->status)->toBe('terminated')
        // Not `admin_termination`: the company did not choose this closure,
        // and its name should not be on it.
        ->and($distributor->user->closure_type)->toBe('consent_withdrawn');

    Event::assertDispatched(DistributorTerminated::class);
});

it('CWD-04: the acceptance record survives — withdrawal is not deletion', function () {
    $distributor = cwdDistributor();

    app(WithdrawConsent::class)->execute($distributor, 'Changed my mind.');

    $consent = Consent::where('distributor_id', $distributor->id)->first();

    // Withdrawal does not invalidate processing performed before it, so the
    // evidence that the earlier processing was lawful has to survive. Deleting
    // the row would destroy the platform's own defence.
    expect($consent->accepted_at)->not->toBeNull()
        ->and($consent->doc_hash_sha256)->not->toBeNull()
        ->and($consent->withdrawn_at)->not->toBeNull()
        ->and($consent->withdrawal_reason)->toBe('Changed my mind.');
});

it('CWD-05: it is audited with the distributor as the actor', function () {
    $distributor = cwdDistributor();

    app(WithdrawConsent::class)->execute($distributor, 'Moving abroad.');

    $entry = AuditLog::where('action', 'consent.withdrawn')->firstOrFail();

    expect($entry->actor_id)->toBe($distributor->user_id)
        ->and($entry->subject_id)->toBe($distributor->id)
        ->and($entry->details['consents_withdrawn'])->toBe(4)
        // Their own words, so a later DPDP enquiry sees why and not only that.
        ->and($entry->details['reason'])->toBe('Moving abroad.');
});

it('CWD-06: a second submission does not terminate twice', function () {
    Event::fake([DistributorTerminated::class]);
    $distributor = cwdDistributor();

    $service = app(WithdrawConsent::class);

    expect($service->execute($distributor, 'first'))->toBe(4)
        // A double-submitted form must not produce a second termination event.
        ->and($service->execute($distributor->fresh(), 'second'))->toBe(0);

    Event::assertDispatchedTimes(DistributorTerminated::class, 1);
});

it('CWD-07: the page belongs to the signed-in distributor', function () {
    cwdDistributor();

    // A user with no distributor row has nothing to withdraw.
    $this->actingAs(User::factory()->create())
        ->get(route('consent.withdraw'))
        ->assertNotFound();
});

it('CWD-08: the profile links to withdrawal plainly', function () {
    $distributor = cwdDistributor();

    // A withdrawal route that is hard to find does not satisfy §6(5) — it must
    // be as easy to take back as it was to give.
    $this->actingAs($distributor->user)
        ->get(route('profile.show'))
        ->assertOk()
        ->assertSee('Withdraw consent');
});
