<?php

declare(strict_types=1);

/**
 * Published helpline hours (F77; DSR 2021 Rule 5(1)(a), grievance SLA).
 *
 * The same sentence was typed into five footers and two policy pages, and the
 * footers drifted: they promised 9:30–17:30 every day except Sundays while
 * `/help` and the published Grievance Redressal Policy said 10:00–18:00
 * Mon–Sat. Published hours are a commitment about when a complaint can reach
 * a person, so two answers is not a typo — it is the company saying two
 * different things, and a consumer who rings at 09:45 on the footer's word
 * finds nobody there.
 *
 * HLP-01: the footer states the hours the company actually keeps
 * HLP-02: no template hardcodes hours — there is one source and they read it
 * HLP-03: the published Grievance Redressal Policy agrees with it
 */

use App\Modules\Content\Models\ContentPage;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('HLP-01: the footer states the hours the company actually keeps', function () {
    seedConsentDocuments();

    $this->get('/')
        ->assertOk()
        ->assertSee(config('arovolife.support_hours'))
        // The hours the footer used to promise, which nobody keeps.
        ->assertDontSee('9:30 am')
        ->assertDontSee('5:30 pm');
});

it('HLP-02: no template hardcodes the hours', function () {
    // One place to change them, so the next correction cannot reach four
    // footers and miss the fifth.
    $hardcoded = [];

    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator(resource_path('views'), FilesystemIterator::SKIP_DOTS)
    );

    foreach ($files as $file) {
        if (! str_ends_with((string) $file, '.blade.php')) {
            continue;
        }

        $source = (string) file_get_contents((string) $file);

        if (preg_match('/\d{1,2}:\d{2} ?(am|pm)? ?[–-] ?\d{1,2}:\d{2}/i', $source) === 1) {
            $hardcoded[] = str_replace(resource_path('views').'/', '', (string) $file);
        }
    }

    expect($hardcoded)->toBe([], 'These hardcode an hours range instead of reading '
        ."config('arovolife.support_hours'): ".implode(', ', $hardcoded));
});

it('HLP-03: the published Grievance Redressal Policy agrees with the footer', function () {
    seedConsentDocuments();

    $grievance = ContentPage::query()->where('slug', 'grievance')->firstOrFail();

    // The policy is the document a regulator reads. The footer must not
    // undercut it.
    expect((string) $grievance->body)->toContain((string) config('arovolife.support_hours'));
});
