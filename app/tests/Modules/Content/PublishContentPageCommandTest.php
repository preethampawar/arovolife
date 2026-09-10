<?php

declare(strict_types=1);

/**
 * `content:publish` — the targeted alternative to re-seeding every policy page.
 *
 *   PUB-01  republishes only the named page
 *   PUB-02  refuses a slug it does not know
 */

use App\Modules\Compliance\Models\AuditLog;
use App\Modules\Content\Models\ContentPage;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('PUB-01: republishes only the named page and leaves the others untouched', function (): void {
    $stale = 'Superseded body.';

    foreach (['privacy', 'compensation'] as $slug) {
        ContentPage::create([
            'slug' => $slug,
            'title' => ucfirst($slug),
            'body' => $stale,
            'status' => ContentPage::STATUS_PUBLISHED,
            'published_at' => now()->subYear(),
        ]);
    }

    $this->artisan('content:publish', ['slug' => ['privacy']])->assertExitCode(0);

    // The compensation page carries payout-week wording R-75 holds back until
    // the DSA §6.2 notice has run. Publishing the privacy notice must not
    // publish it as a side effect.
    expect(ContentPage::where('slug', 'privacy')->value('body'))->not->toBe($stale)
        ->and(ContentPage::where('slug', 'compensation')->value('body'))->toBe($stale);

    expect(AuditLog::where('action', 'content_page.republished')->count())->toBe(1);
});

it('PUB-02: refuses a slug it does not know', function (): void {
    $this->artisan('content:publish', ['slug' => ['not-a-page']])->assertExitCode(1);

    expect(AuditLog::where('action', 'content_page.republished')->count())->toBe(0);
});
