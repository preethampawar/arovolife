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

use App\Modules\Identity\Models\User;
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
        ->assertSee('+91 96405 61976')
        ->assertSee('support@arovolife.com')
        ->assertSee('www.arovolife.com');
});

it('names the Grievance Officer and DPO with their contact on the public pages', function (): void {
    $this->seed(ContentPageSeeder::class);

    $this->get('/contact-us')
        ->assertOk()
        ->assertSeeInOrder(['Grievance Officer', 'Shankar G, Marketing Head', '+91 96405 61976', 'Data Protection Officer', 'Rajendar L, Customer Service &amp; Legal Head', '+91 96405 61976', 'Nodal Officer', 'KP Naik', '+91 96405 61976', 'Compliance Committee', 'Shankar G, Marketing Head'], escape: false);

    $this->get('/p/grievance')->assertOk()->assertSee('Shankar G, Marketing Head')->assertSee('Rajendar L, Customer Service')->assertDontSee('Shanker');
    $this->get('/p/privacy')->assertOk()->assertSee('Shankar G')->assertSee('Rajendar L')->assertSee('KP Naik')->assertDontSee('dpo@arovolife.com')->assertDontSee('nodal@arovolife.com');
});

it('names the Grievance Officer on the support hub and routes the published officers to support@', function (): void {
    $this->actingAs(User::factory()->create())
        ->get(route('help.index'))
        ->assertOk()
        ->assertSee('Shankar G, Marketing Head')
        ->assertSee('Nodal Officer — KP Naik')
        ->assertSee('support@arovolife.com')
        ->assertDontSee('grievance@arovolife.com')
        ->assertDontSee('nodal@arovolife.com')
        ->assertDontSee('compliance@arovolife.com');
});
