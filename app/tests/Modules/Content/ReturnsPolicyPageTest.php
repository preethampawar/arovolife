<?php

declare(strict_types=1);

/**
 * F35: DSR 2021 Rule 5 requires the return, refund, exchange and cancellation
 * policy to be displayed on the website. There was no such page and no footer
 * link, while product pages advertised "30-day returns".
 *
 * The client supplied the Product Return, Warranty & Guarantee Policy on
 * 2026-09-23 (see docs/plans/policy-pages-home-about-genos-star-fb-card-2026-09-23.md),
 * closing QA F35: `returns` is no longer held and is published by a blanket
 * seed, same as the other statutory pages.
 */

use App\Modules\Content\Models\ContentPage;
use Database\Seeders\ContentPageSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(ContentPageSeeder::class);
});

it('F35-01: a blanket seed publishes the returns policy', function (): void {
    $page = ContentPage::query()->where('slug', 'returns')->first();

    expect($page)->not->toBeNull()
        ->and($page->status)->toBe(ContentPage::STATUS_PUBLISHED)
        ->and($page->published_at)->not->toBeNull()
        ->and($page->title)->toBe('Product Return, Warranty & Guarantee Policy')
        ->and(ContentPageSeeder::HELD_SLUGS)->not->toContain('returns');
});

it('F35-02: states the buy-back conditions, the inspection timeline and the deduction rule', function (): void {
    $body = (string) ContentPage::query()->where('slug', 'returns')->value('body');

    expect($body)
        ->toContain('Buyback/Repurchase conditions')
        ->toContain('Within 7 days after receipt of material in warehouse')
        ->toContain('Direct Seller Price less GST')
        ->not->toContain('LEGAL REVIEW REQUIRED'); // the engineer-only banner
});

it('F35-03: the footer shows the link when published and hides it when the row is a draft', function (): void {
    $this->get('/')->assertOk()->assertSee(route('content.show', 'returns'));

    ContentPage::query()->where('slug', 'returns')->update([
        'status' => ContentPage::STATUS_DRAFT,
        'published_at' => null,
    ]);

    $this->get('/')->assertOk()->assertDontSee(route('content.show', 'returns'));
});
