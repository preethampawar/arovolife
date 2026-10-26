<?php

declare(strict_types=1);

/**
 * Editing the repo-sourced policy pages in the admin editor (R-125).
 *
 *   PPE-01  a policy page gets the visual editor and a warning; other pages keep Trix
 *   PPE-02  saving the plan page unchanged leaves its text, hash and version date as they were
 *   PPE-03  an edit keeps headings, tables and the change list, and strips the rest
 *   PPE-04  content:publish and a re-seed keep a page changed in the portal
 *   PPE-05  a plan edit that keeps the old version label says the notice will not go out
 *   PPE-06  a new footer version with no matching change entry holds the notice
 *   PPE-07  a new version with its own change entry saves with no warning
 *   PPE-08  a plan edit while the notice is switched off says nobody will be told
 *   PPE-09  the income-projection rule applies to the plan page
 */

use App\Modules\Consent\Services\PlanChangeNotices;
use App\Modules\Content\Models\ContentPage;
use App\Modules\Identity\Models\User;
use App\Modules\Shared\Features\PlanChangeNoticeFeature;
use Database\Seeders\ContentPageSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Pennant\Feature;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    seedConsentDocuments();
});

function ppeAdmin(): User
{
    $user = User::create([
        'full_name' => 'Policy editor',
        'email' => 'ppe-'.uniqid().'@example.com',
        'phone_e164' => '+91933'.str_pad((string) random_int(0, 9999999), 7, '0', STR_PAD_LEFT),
        'password_hash' => Hash::make('ppe-test-pwd-2026'),
        'password_set_at' => now(),
        'status' => 'active',
        'email_verified_at' => now(),
        'activated_at' => now(),
    ]);
    $user->assignRole(Role::findOrCreate('admin', 'web'));

    return $user;
}

function ppePlan(): ContentPage
{
    return ContentPage::query()->where('slug', 'compensation')->firstOrFail();
}

/** @return array<string, mixed> */
function ppeForm(ContentPage $page, string $body): array
{
    return [
        'title' => $page->title,
        'slug' => $page->slug,
        'type' => '',
        'meta_description' => $page->meta_description,
        'body' => $body,
        'status' => $page->status,
    ];
}

it('PPE-01: gives a policy page the visual editor and a warning, and keeps Trix for other pages', function (): void {
    Feature::for(null)->activate(PlanChangeNoticeFeature::class);
    $admin = ppeAdmin();

    $this->actingAs($admin)->get(route('admin.content.edit', ppePlan()))
        ->assertOk()
        ->assertSee('This is a legal page. Edit with care.')
        ->assertSee('Every distributor will be shown the change')
        ->assertSee('data-policy-editor', escape: false)
        ->assertSee('Small heading')
        ->assertDontSee('<trix-editor', escape: false);

    $this->actingAs($admin)->get(route('admin.content.edit', ContentPage::query()->where('slug', 'privacy')->firstOrFail()))
        ->assertOk()
        ->assertSee('This is a legal page. Edit with care.')
        ->assertDontSee('Every distributor will be shown the change');

    $blog = ContentPage::create(['slug' => 'a-blog-post', 'type' => 'blog', 'title' => 'A post', 'body' => '<p>Hi</p>', 'status' => ContentPage::STATUS_DRAFT]);

    $this->actingAs($admin)->get(route('admin.content.edit', $blog))
        ->assertOk()
        ->assertSee('<trix-editor', escape: false)
        ->assertDontSee('This is a legal page.');
});

it('PPE-02: saving the plan page unchanged leaves its text exactly as it was', function (): void {
    $page = ppePlan();
    $before = (string) $page->body;
    $updatedAt = $page->updated_at;

    // Browsers send textarea line breaks as CRLF.
    $this->actingAs(ppeAdmin())
        ->patch(route('admin.content.update', $page), ppeForm($page, str_replace("\n", "\r\n", $before)))
        ->assertSessionHasNoErrors();

    $page->refresh();

    // Nor its updated_at: that date is the version every new plan consent records.
    expect($page->body)->toBe($before)
        ->and($page->updated_at->equalTo($updatedAt))->toBeTrue()
        ->and($page->edited_in_portal_at)->toBeNull();
});

it('PPE-03: an edit keeps headings, tables and the change list, and strips scripts and styles', function (): void {
    $page = ppePlan();
    $edited = str_replace(
        '<h2>',
        '<script>alert(1)</script><h2 style="color:red" onclick="x()">',
        (string) $page->body,
    );

    $this->actingAs(ppeAdmin())
        ->patch(route('admin.content.update', $page), ppeForm($page, $edited))
        ->assertSessionHasNoErrors();

    $page->refresh();
    $body = (string) $page->body;

    expect($body)->not->toContain('<script')
        ->and($body)->not->toContain('onclick')
        ->and($body)->not->toContain('style=')
        ->and(substr_count($body, '<h2>'))->toBe(substr_count($edited, '<h2 style'))
        ->and($body)->toContain('<h3>')
        ->and($body)->toContain('<h4>')
        ->and($body)->toContain('<table>')
        ->and($page->edited_in_portal_at)->not->toBeNull()
        // The sign-in notice can still read the change list.
        ->and(app(PlanChangeNotices::class)->entries($body))->not->toBe([]);
});

it('PPE-04: content:publish and a re-seed keep a page changed in the portal unless told to overwrite', function (): void {
    $page = ppePlan();
    $page->update(['body' => '<p>Portal text.</p>', 'edited_in_portal_at' => now()]);

    $this->artisan('content:publish', ['slug' => ['compensation']])
        ->expectsOutputToContain('Kept the portal version of: compensation')
        ->assertExitCode(0);
    (new ContentPageSeeder)->publish();

    expect($page->fresh()->body)->toBe('<p>Portal text.</p>');

    $this->artisan('content:publish', ['slug' => ['compensation'], '--overwrite-portal-edits' => true])
        ->assertExitCode(0);

    $page->refresh();

    expect($page->body)->not->toBe('<p>Portal text.</p>')
        ->and($page->edited_in_portal_at)->toBeNull();
});

function ppePlanNoticeOn(): void
{
    disableTestForeignKeys();
    Feature::for(null)->activate(PlanChangeNoticeFeature::class);
}

function ppeBumpFooter(string $body, string $label): string
{
    return str_replace('disclosure version: v2026-10-27', 'disclosure version: '.$label, $body);
}

it('PPE-05: a plan edit that keeps the old version label warns that distributors will not be told', function (): void {
    // No version has been recorded for the old text yet (as after a platform
    // reset), so the controller has to compare the labels itself.
    ppePlanNoticeOn();

    $page = ppePlan();
    $edited = preg_replace('/<p>/', '<p>A new sentence. ', (string) $page->body, 1);

    $this->actingAs(ppeAdmin())
        ->patch(route('admin.content.update', $page), ppeForm($page, (string) $edited))
        ->assertSessionHas('status', 'Page saved.')
        ->assertSessionHas('error', fn (string $message): bool => str_contains($message, 'still says v2026-10-27'));
});

it('PPE-06: a new footer version with no matching change entry holds the notice', function (): void {
    ppePlanNoticeOn();

    $page = ppePlan();

    $this->actingAs(ppeAdmin())
        ->patch(route('admin.content.update', $page), ppeForm($page, ppeBumpFooter((string) $page->body, 'v2026-11-02')))
        ->assertSessionHas('error', fn (string $message): bool => str_contains($message, 'the newest entry')
            && str_contains($message, 'v2026-11-02'));

    app()->forgetScopedInstances();

    expect(app(PlanChangeNotices::class)->blockedReason())->toContain('newest entry');
});

it('PPE-07: a new version with its own change entry saves with no warning', function (): void {
    ppePlanNoticeOn();

    $page = ppePlan();
    $edited = str_replace(
        '<h3>Changes to this plan, by version</h3>',
        '<h3>Changes to this plan, by version</h3><h4>v2026-11-02</h4><ul><li>A test change.</li></ul>',
        ppeBumpFooter((string) $page->body, 'v2026-11-02'),
    );

    $this->actingAs(ppeAdmin())
        ->patch(route('admin.content.update', $page), ppeForm($page, $edited))
        ->assertSessionHas('status', 'Page saved.')
        ->assertSessionMissing('error');

    app()->forgetScopedInstances();
    $notices = app(PlanChangeNotices::class);

    expect($notices->blockedReason())->toBeNull()
        ->and($notices->entries((string) $page->fresh()->body)[0]->label)->toBe('v2026-11-02');
});

it('PPE-08: a plan edit while the notice is switched off says nobody will be told', function (): void {
    Feature::for(null)->deactivate(PlanChangeNoticeFeature::class);
    $page = ppePlan();
    $admin = ppeAdmin();

    $this->actingAs($admin)->get(route('admin.content.edit', $page))
        ->assertSee('The plan-change notice is switched off');

    $edited = preg_replace('/<p>/', '<p>A new sentence. ', (string) $page->body, 1);

    $this->actingAs($admin)
        ->patch(route('admin.content.update', $page), ppeForm($page, (string) $edited))
        ->assertSessionHas('error', fn (string $message): bool => str_contains($message, 'switched off'));
});

it('PPE-09: the income-projection rule applies to the plan page', function (): void {
    $page = ppePlan();
    $before = (string) $page->body;
    $edited = preg_replace('/<p>/', '<p>You can earn up to a lakh a month. ', $before, 1);

    $this->actingAs(ppeAdmin())
        ->patch(route('admin.content.update', $page), ppeForm($page, (string) $edited))
        ->assertSessionHasErrors('body');

    expect($page->fresh()->body)->toBe($before);
});
