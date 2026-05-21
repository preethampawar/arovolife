<?php

declare(strict_types=1);

/**
 * Content pages tests — public-facing legal/policy documents.
 *
 * CP-001..004 each of /p/ethics, /p/terms, /p/grievance, /p/privacy returns 200
 *                     when the seeder has run, and the rendered HTML contains
 *                     the statutory phrases required for that page.
 * CP-005          unknown slug under /p/{slug} returns 404.
 * CP-006          a page in draft status under a valid slug returns 404
 *                 (we only publish `published` rows).
 * CP-007          the seeder is idempotent — re-running it does not duplicate
 *                 rows and does refresh the body content.
 *
 * These tests exercise the seeder + the PublicContentPageController + the
 * `resources/views/content/show.blade.php` view as one unit. They intentionally
 * assert on statutory phrases the legal/compliance team has signed off on
 * (cooling-off, DPDP, grievance officer, PAN, Aadhaar, eight-hard-rules
 * fingerprints) so that a future copy edit that accidentally removes one of
 * those phrases fails CI loudly.
 */

use App\Modules\Content\Models\ContentPage;
use Database\Seeders\ContentPageSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(ContentPageSeeder::class);
});

it('renders the Code of Ethics page with the required statutory phrases', function (): void {
    $response = $this->get('/p/ethics');

    $response->assertOk();
    $response->assertSee('Code of Ethics', escape: false);
    $body = strtolower((string) $response->getContent());

    // Hard rule fingerprints + DSR 2021 obligations.
    expect($body)
        ->toContain('income') // no income projections clause
        ->toContain('cooling-off')
        ->toContain('grievance officer')
        ->toContain('placement')
        ->toContain('hyderabad'); // governing-law / arbitration seat
});

it('renders the Direct Seller Agreement & Terms with the required statutory phrases', function (): void {
    $response = $this->get('/p/terms');

    $response->assertOk();
    $body = strtolower((string) $response->getContent());

    // The eight hard rules + DPDP + jurisdictional fingerprints.
    expect($body)
        ->toContain('free of cost')         // hard rule #1
        ->toContain('cooling-off')           // hard rule #5
        ->toContain('one pan')               // hard rule #6 ("one PAN = one ADN")
        ->toContain('aadhaar')               // hard rule #8 (Aadhaar handling)
        ->toContain('pan')
        ->toContain('dpdp')                  // DPDP Act reference
        ->toContain('dsr 2021')              // DSR 2021 reference
        ->toContain('grievance')
        ->toContain('hyderabad');            // jurisdictional seat
});

it('renders the Grievance Redressal page with the required SLA, escalation and statutory references', function (): void {
    $response = $this->get('/p/grievance');

    $response->assertOk();
    $body = strtolower((string) $response->getContent());

    expect($body)
        ->toContain('grievance officer')
        ->toContain('48 hours')               // acknowledgement SLA
        ->toContain('30 days')                // resolution SLA
        ->toContain('escalation')
        ->toContain('consumer protection')    // CCPA / Consumer Protection Act
        ->toContain('data protection board'); // DPDP-act recourse
});

it('renders the Privacy Policy page with the DPDP-Act-mandated disclosures', function (): void {
    $response = $this->get('/p/privacy');

    $response->assertOk();
    $body = strtolower((string) $response->getContent());

    expect($body)
        ->toContain('dpdp')                                   // DPDP Act
        ->toContain('aadhaar')                                // sensitive-data handling
        ->toContain('pan')                                    // sensitive-data handling
        ->toContain('grievance officer')                      // §12
        ->toContain('data protection officer')                // §1
        ->toContain('retention')                              // §5
        ->toContain('cookies')                                // §9
        ->toContain('right to')                               // §10 rights enumerated
        ->toContain('data protection board');                 // §10.7 / §12 escalation
});

it('returns 404 for an unknown content slug', function (): void {
    $this->get('/p/this-slug-does-not-exist')->assertNotFound();
});

it('returns 404 for a draft content page even when the slug exists', function (): void {
    ContentPage::query()->where('slug', 'ethics')->update(['status' => ContentPage::STATUS_DRAFT]);

    $this->get('/p/ethics')->assertNotFound();
});

it('is idempotent — re-seeding does not duplicate rows and refreshes the body content', function (): void {
    $before = ContentPage::query()->count();
    expect($before)->toBe(4);

    $page = ContentPage::query()->where('slug', 'terms')->firstOrFail();
    $page->update(['body' => '<p>stale draft</p>']);

    $this->seed(ContentPageSeeder::class);

    $after = ContentPage::query()->count();
    expect($after)->toBe(4);

    $page->refresh();
    expect($page->body)
        ->not->toBe('<p>stale draft</p>')
        ->toContain('Direct Seller');
});

it('never exposes the engineer-only legal-review draft banner to end users', function (): void {
    foreach (['ethics', 'terms', 'grievance', 'privacy'] as $slug) {
        $body = (string) $this->get('/p/'.$slug)->getContent();
        // The "DRAFT — LEGAL REVIEW REQUIRED" HTML comment lives in the
        // markdown source files for the benefit of counsel and engineers,
        // and must be stripped by the seeder before persistence.
        expect($body)->not->toContain('LEGAL REVIEW REQUIRED');
        expect($body)->not->toContain('DRAFT —');
    }
});
