<?php

declare(strict_types=1);

/**
 * The nine client policy documents (2026-09-23) as public content pages —
 * see docs/plans/policy-pages-home-about-genos-star-fb-card-2026-09-23.md.
 *
 *   POL-01  every ContentPageSeeder slug has a markdown source file
 *   POL-02  a blanket seed publishes every slug except compensation
 *   POL-03  ProductionSeeder on an empty table creates all nine policy pages
 *   POL-04  ProductionSeeder never overwrites an existing edited row
 *   POL-05  the privacy body carries Part B, including the message-moderation
 *           disclosure (§4 item 5b — the page never contained the literal
 *           string "4.5b")
 *   POL-06  shipping and returns are properly split
 */

use App\Modules\Content\Models\ContentPage;
use Database\Seeders\ContentPageSeeder;
use Database\Seeders\ProductionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('POL-01: every seeder slug has a markdown source file', function (): void {
    foreach (ContentPageSeeder::slugs() as $slug) {
        $path = base_path('database/seeders/content/'.$slug.'.md');

        expect(is_file($path))->toBeTrue("Missing content markdown source: {$path}");
    }
});

it('POL-02: a blanket seed publishes every slug except compensation', function (): void {
    $this->seed(ContentPageSeeder::class);

    foreach (ContentPageSeeder::slugs() as $slug) {
        $page = ContentPage::query()->where('slug', $slug)->first();
        expect($page)->not->toBeNull("Missing seeded page: {$slug}");

        if ($slug === 'compensation') {
            expect($page->status)->toBe(ContentPage::STATUS_DRAFT);

            continue;
        }

        expect($page->status)->toBe(ContentPage::STATUS_PUBLISHED);

        $this->get(route('content.show', $slug))
            ->assertOk()
            ->assertSee($page->title);
    }
});

it('POL-03: ProductionSeeder on an empty table creates all nine policy pages published', function (): void {
    $this->seed(ProductionSeeder::class);

    $slugs = array_values(array_diff(ContentPageSeeder::slugs(), ContentPageSeeder::HELD_SLUGS));
    expect($slugs)->toHaveCount(9);

    foreach ($slugs as $slug) {
        $page = ContentPage::query()->where('slug', $slug)->first();

        expect($page)->not->toBeNull("Missing production-seeded page: {$slug}")
            ->and($page->status)->toBe(ContentPage::STATUS_PUBLISHED)
            ->and($page->body)->not->toContain('placeholder');
    }
});

it('POL-04: ProductionSeeder leaves an existing edited terms row unchanged', function (): void {
    ContentPage::create([
        'slug' => 'terms',
        'title' => 'Client-edited title',
        'meta_description' => 'Client-edited meta.',
        'body' => 'Client-edited body.',
        'status' => ContentPage::STATUS_PUBLISHED,
        'published_at' => now()->subYear(),
    ]);

    $this->seed(ProductionSeeder::class);

    $page = ContentPage::query()->where('slug', 'terms')->first();

    expect($page->title)->toBe('Client-edited title')
        ->and($page->body)->toBe('Client-edited body.');
});

it('POL-05: the rendered privacy body carries Part B and the message-moderation disclosure', function (): void {
    $this->seed(ContentPageSeeder::class);

    $body = (string) ContentPage::query()->where('slug', 'privacy')->value('body');

    expect($body)
        ->toContain('Part B')
        ->toContain('Moderation of reported messages');
});

it('POL-06: shipping and returns are properly split', function (): void {
    $this->seed(ContentPageSeeder::class);

    $shippingBody = (string) ContentPage::query()->where('slug', 'shipping')->value('body');
    $returnsBody = (string) ContentPage::query()->where('slug', 'returns')->value('body');

    expect($shippingBody)->toContain('mainland India')
        ->and($returnsBody)->not->toContain('## 6. Shipping');
});
