<?php

declare(strict_types=1);

/**
 * T&C §2.3 eligibility declarations, and the consent record they sit beside.
 *
 * The compliance sign-off found neither existed. C-08: sound mind, insolvency
 * and moral turpitude were captured nowhere — "sound mind" appeared in no view,
 * no column and no test, so "which distributors declared this?" had no answer.
 * R-51: `consents.doc_hash_sha256` held a hardcoded hash of a Phase-1 stub
 * string and a version of `1.0.0`, while the published documents were dated
 * 2026-05-21 and later — so the electronic record proved neither the text nor
 * the version, which is its only job under IT Act §10A.
 *
 * DEC-01: the consent step refuses a submission with the declarations missing
 * DEC-02: an unchecked box posts nothing, and is refused rather than defaulted
 * DEC-03: all three accepted lets the step through
 * DEC-04: each declaration is stored separately, with the date
 * DEC-05: the consent hash is the hash of the published document body
 * DEC-06: the recorded version is the published document's, not a constant
 * DEC-07: changing the document changes what the next joiner's record says
 * DEC-08: consent cannot be recorded at all when the document is not published
 */

use App\Modules\Consent\Services\ConsentDocuments;
use App\Modules\Content\Models\ContentPage;
use App\Modules\Identity\Services\WizardStateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    seedConsentDocuments();
});

/** Put the wizard at step 4 so the consent route's guard is satisfied. */
function decWizardAtConsent(): void
{
    $wizard = app(WizardStateService::class);
    $wizard->start(sponsorId: 1, placementId: 1, sideOpt: 'L');
    $wizard->saveStepData(2, ['email' => 'joiner@example.com']);
    $wizard->saveStepData(3, ['quiz_passed' => true]);
}

/**
 * @param  array<string, string|null>  $overrides
 * @return array<string, string|null>
 */
function decPayload(array $overrides = []): array
{
    return array_merge([
        'consent_tnc' => '1',
        'consent_ethics' => '1',
        'consent_plan' => '1',
        'consent_privacy' => '1',
        'declared_sound_mind' => '1',
        'declared_not_insolvent' => '1',
        'declared_no_moral_turpitude' => '1',
    ], $overrides);
}

it('DEC-01: the consent step refuses a submission with the declarations missing', function () {
    decWizardAtConsent();

    $payload = decPayload();
    unset($payload['declared_sound_mind'], $payload['declared_not_insolvent'], $payload['declared_no_moral_turpitude']);

    $this->post('/register/consent', $payload)
        ->assertSessionHasErrors(['declared_sound_mind', 'declared_not_insolvent', 'declared_no_moral_turpitude']);
});

it('DEC-02: an unchecked box posts nothing and is refused rather than defaulted', function () {
    decWizardAtConsent();

    // An unchecked checkbox sends no key at all — the shape that let
    // grievances be filed with no contact details. `accepted` catches it;
    // `boolean` would not, because there is nothing to validate.
    $this->post('/register/consent', decPayload(['declared_not_insolvent' => null]))
        ->assertSessionHasErrors('declared_not_insolvent');
});

it('DEC-03: all three accepted lets the step through', function () {
    decWizardAtConsent();

    $this->post('/register/consent', decPayload())
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('register.pan'));

    // Read the session the request left behind, not a fresh service instance.
    $stored = session('registration_wizard')['data']['consent']['declarations'] ?? null;

    expect($stored)->toBe([
        'sound_mind' => true,
        'not_insolvent' => true,
        'no_moral_turpitude' => true,
    ]);
});

it('DEC-04: the declaration columns exist and are separate facts', function () {
    // Three columns, not one "I meet the eligibility criteria" flag: each of
    // these voids the agreement ab initio on its own, so which one was false
    // has to be answerable afterwards.
    foreach ([
        'declared_sound_mind',
        'declared_not_insolvent',
        'declared_no_moral_turpitude',
        'declarations_made_at',
    ] as $column) {
        expect(Schema::hasColumn('distributors', $column))->toBeTrue($column);
    }

    // Nullable, because every distributor who registered before the question
    // existed was never asked. A default of true would be the platform
    // declaring something on their behalf.
    $meta = collect(Schema::getColumns('distributors'))
        ->firstWhere('name', 'declared_sound_mind');

    expect($meta['nullable'])->toBeTrue();
});

it('DEC-05: the consent hash is the hash of the published document body', function () {
    $documents = app(ConsentDocuments::class)->all();
    $terms = ContentPage::where('slug', 'terms')->firstOrFail();

    expect($documents['tnc']['hash'])->toBe(hash('sha256', (string) $terms->body));
});

it('DEC-06: the recorded version is the published document, not a constant', function () {
    $documents = app(ConsentDocuments::class)->all();
    $privacy = ContentPage::where('slug', 'privacy')->firstOrFail();

    expect($documents['privacy']['version'])->toBe($privacy->updated_at->format('Y-m-d'))
        // The bug this replaces: all four read 1.0.0 regardless of the text.
        ->and(collect($documents)->pluck('version')->unique())->not->toContain('1.0.0');
});

it('DEC-07: changing the document changes what the next record says', function () {
    $before = app(ConsentDocuments::class)->all()['tnc']['hash'];

    ContentPage::where('slug', 'terms')->update(['body' => '<p>Amended terms.</p>']);

    // A fresh instance: the service memoises per request, which is correct —
    // every consent written in one registration must attest to one text.
    $after = app()->make(ConsentDocuments::class)->all()['tnc']['hash'];

    expect($after)->not->toBe($before);
});

it('DEC-08: consent cannot be recorded when the document is not published', function () {
    ContentPage::where('slug', 'terms')->update(['status' => 'draft']);

    // Refusing is the point. A fallback would let registration proceed while
    // writing a consent that points at nothing — which is the defect R-51 was
    // about, reintroduced as a silent default.
    expect(fn () => app()->make(ConsentDocuments::class)->all())
        ->toThrow(RuntimeException::class, "the 'terms' content page is not published");
});
