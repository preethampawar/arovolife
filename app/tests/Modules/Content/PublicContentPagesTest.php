<?php

declare(strict_types=1);

/**
 * Public content listing tests — /blogs, /seminars, /news, /arovo-hub.
 *
 * PCL-001  GET /blogs    returns 200 with the listing view.
 * PCL-002  GET /seminars returns 200 with the listing view.
 * PCL-003  GET /news     returns 200 with the listing view.
 * PCL-004  GET /arovo-hub returns 200 with the hub landing view.
 * PCL-005  A seeded blog page appears in the /blogs listing.
 * PCL-006  A non-blog page does NOT appear in the /blogs listing.
 */

use App\Modules\Content\Models\ContentPage;
use Database\Seeders\ProductionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

// PCL-001
it('GET /blogs returns 200', function (): void {
    $this->get('/blogs')->assertOk();
});

// PCL-002
it('GET /seminars returns 200', function (): void {
    $this->get('/seminars')->assertOk();
});

// PCL-003
it('GET /news returns 200', function (): void {
    $this->get('/news')->assertOk();
});

// PCL-004
it('GET /arovo-hub returns 200', function (): void {
    $this->get('/arovo-hub')->assertOk();
});

// PCL-005
it('a published blog page appears in the /blogs listing', function (): void {
    ContentPage::create([
        'slug' => 'test-blog-post',
        'title' => 'Test Blog Post',
        'body' => '<p>Hello from the test blog.</p>',
        'type' => 'blog',
        'status' => ContentPage::STATUS_PUBLISHED,
        'published_at' => now()->subHour(),
    ]);

    $this->get('/blogs')->assertOk()->assertSee('Test Blog Post');
});

// PCL-006
it('a non-blog page does not appear in the /blogs listing', function (): void {
    ContentPage::create([
        'slug' => 'test-news-article',
        'title' => 'Test News Article',
        'body' => '<p>This is news, not a blog.</p>',
        'type' => 'news',
        'status' => ContentPage::STATUS_PUBLISHED,
        'published_at' => now()->subHour(),
    ]);

    $this->get('/blogs')->assertOk()->assertDontSee('Test News Article');
});

// PCL-007 — staging 2026-08-29: the About dropdown linked pages that only the
// dev seeder created, so a fresh install 404'd on every one of them.
it('every /p/ page the public top-nav links to exists after the production seeder', function (): void {
    $this->seed(ProductionSeeder::class);

    $html = $this->get('/')->assertOk()->getContent();
    preg_match_all('#href="/p/([a-z0-9-]+)"#', $html, $m);

    expect($m[1])->not->toBeEmpty()
        ->and($m[1])->toContain('business-opportunities');

    foreach (array_unique($m[1]) as $slug) {
        $this->get('/p/'.$slug)->assertOk();
    }
});

// PCL-008 — Arovo Hub is hidden from the nav until its pages are written.
it('does not show the Arovo Hub dropdown in the public nav', function (): void {
    $this->get('/')->assertOk()->assertDontSee('Arovo Hub');
});
