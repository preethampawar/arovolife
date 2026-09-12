<?php

declare(strict_types=1);

/**
 * Statutory: `ConsentDocuments::all()` refuses to record a consent — which
 * blocks registration outright — when one of these four pages is not
 * published (plan §4, Compliance).
 */

use App\Modules\ActionCenter\Providers\Compliance\ContentRequiredPageUnpublishedProvider;
use App\Modules\Content\Models\ContentPage;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    disableTestForeignKeys();
    $this->provider = app(ContentRequiredPageUnpublishedProvider::class);
});

function publishedPage(string $slug): ContentPage
{
    return ContentPage::create([
        'slug' => $slug,
        'title' => ucfirst($slug),
        'body' => "{$slug} body",
        'status' => ContentPage::STATUS_PUBLISHED,
        'published_at' => now(),
    ]);
}

it('is statutory and cannot be snoozed', function (): void {
    expect($this->provider->statutory())->toBeTrue();
});

it('counts every required page that is not published', function (): void {
    publishedPage('terms');
    publishedPage('ethics');
    // 'compensation' and 'privacy' are missing entirely.

    expect($this->provider->count())->toBe(2);
    expect($this->provider->items()->map(fn ($item) => $item->meta['slug'])->all())->toEqualCanonicalizing(['compensation', 'privacy']);
});

it('counts a page that exists but is only a draft', function (): void {
    publishedPage('terms');
    publishedPage('ethics');
    publishedPage('compensation');
    ContentPage::create([
        'slug' => 'privacy',
        'title' => 'Privacy',
        'body' => 'draft body',
        'status' => ContentPage::STATUS_DRAFT,
    ]);

    expect($this->provider->count())->toBe(1);
});

it('counts nothing when all four required pages are published', function (): void {
    foreach (['terms', 'ethics', 'compensation', 'privacy'] as $slug) {
        publishedPage($slug);
    }

    expect($this->provider->count())->toBe(0);
});
