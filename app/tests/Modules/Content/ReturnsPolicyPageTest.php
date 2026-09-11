<?php

declare(strict_types=1);

/**
 * F35: DSR 2021 Rule 5 requires the return, refund, exchange and cancellation
 * policy to be displayed on the website. There was no such page and no footer
 * link, while product pages advertised "30-day returns".
 *
 * The page is seeded as an UNPUBLISHED draft for the client's review (its
 * windows follow the buy-back matrix the platform applies, which DSA §5.4 does
 * not yet match), so the footer link must appear only once it is published.
 */

use App\Modules\Content\Models\ContentPage;
use Database\Seeders\ContentPageSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(ContentPageSeeder::class);
});

it('F35-01: seeds the policy as a draft, never published by a blanket seed', function (): void {
    $page = ContentPage::query()->where('slug', 'returns')->first();

    expect($page)->not->toBeNull()
        ->and($page->status)->toBe(ContentPage::STATUS_DRAFT)
        ->and($page->published_at)->toBeNull()
        ->and($page->title)->toBe('Refunds, returns & shipping')
        ->and(ContentPageSeeder::HELD_SLUGS)->toContain('returns');
});

it('F35-02: states the cooling-off right, the deduction rules and the refund timeline', function (): void {
    $body = strtolower((string) ContentPage::query()->where('slug', 'returns')->value('body'));

    expect($body)
        ->toContain('cooling-off')
        ->toContain('30 days of delivery')
        ->toContain('less gst')
        ->toContain('7 working days')
        ->toContain('mainland india')
        ->toContain('90%')            // DSR Rule 7 buy-back
        ->not->toContain('legal review required'); // the engineer-only banner
});

it('F35-03: the footer links the policy only once it is published', function (): void {
    $this->get('/')->assertOk()->assertDontSee(route('content.show', 'returns'));

    (new ContentPageSeeder)->publish(['returns']);

    $this->get('/')->assertOk()->assertSee(route('content.show', 'returns'));
});
