<?php

declare(strict_types=1);

/**
 * F37: a handful of public-site defects found on the walkthrough — the
 * registration entry screen never said joining is free (hard rule #1), the
 * policy pages had no Save-as-PDF path and none of the mandated printable
 * contact footer, the home banner borrowed marketplace language ("Shopping
 * Mall", against hard rule #7), and the carousel arrows sat on top of the hero
 * copy at phone widths.
 */

use Database\Seeders\ContentPageSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('F37-01: /join states that joining is free of cost', function (): void {
    $this->get(route('join.show'))
        ->assertOk()
        ->assertSee('Joining arovolife is free of cost.')
        ->assertSee('no purchase is required', escape: false);
});

it('F37-02: the home banner carries no marketplace framing', function (): void {
    $this->get('/')->assertOk()->assertDontSee('Shopping Mall');
});

it('F37-03: the hero carousel arrows are hidden at phone widths', function (): void {
    $source = (string) file_get_contents(resource_path('views/landing/index.blade.php'));

    // Both arrows opt out below the md breakpoint; swipe and the dots remain.
    expect(substr_count($source, 'hidden md:flex absolute'))->toBe(2);
});

it('F37-04: a policy page offers Save as PDF and ends with the contact footer', function (): void {
    $this->seed(ContentPageSeeder::class);

    $this->get('/p/grievance')
        ->assertOk()
        ->assertSee('Download / Print')
        ->assertSee('Save as PDF', escape: false)
        ->assertSee('+91 88866 62949')
        ->assertSee('support@arovolife.com')
        ->assertSee('www.arovolife.com');
});
