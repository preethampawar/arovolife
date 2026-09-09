<?php

declare(strict_types=1);

/**
 * The FAQ library.
 *
 *   FAQ-01  the flag leaves no trace while it is off
 *   FAQ-02  members-only by default — a visitor gets a 404, not a 403
 *   FAQ-03  turning members-only off opens it to visitors
 *   FAQ-04  only published entries of type faq are listed
 *   FAQ-05  search narrows on title and body
 *   FAQ-06  'faq' is refused as a content-page type while the flag is off
 *   FAQ-07  an entry is not served by the generic /p/{slug} reader
 *   FAQ-08  an income projection is refused in an entry, allowed in a policy page
 *   FAQ-09  saving an entry with a blank type still runs the copy rule
 *   FAQ-10  the editor offers 'faq' only while the flag is on
 */

use App\Modules\Content\Http\Requests\ContentPageRequest;
use App\Modules\Content\Models\ContentPage;
use App\Modules\Content\Services\AnnouncementSettingsService;
use App\Modules\Identity\Models\User;
use App\Modules\Shared\Features\FaqLibraryFeature;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Laravel\Pennant\Feature;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Feature::for(null)->activate(FaqLibraryFeature::class);
});

function faqUser(): User
{
    return User::create([
        'full_name' => 'FAQ reader',
        'email' => 'faq-'.uniqid().'@example.com',
        'phone_e164' => '+91922'.str_pad((string) random_int(0, 9999999), 7, '0', STR_PAD_LEFT),
        'password_hash' => Hash::make('faq-test-pwd-2026'),
        'password_set_at' => now(),
        'status' => 'active',
        'email_verified_at' => now(),
        'activated_at' => now(),
    ]);
}

function faqEntry(string $title, string $body, string $category = 'Payouts', string $status = ContentPage::STATUS_PUBLISHED): ContentPage
{
    return ContentPage::create([
        'slug' => 'faq-'.uniqid(),
        'type' => ContentPage::TYPE_FAQ,
        'category' => $category,
        'sort_order' => 0,
        'title' => $title,
        'body' => $body,
        'status' => $status,
        'published_at' => $status === ContentPage::STATUS_PUBLISHED ? now()->subMinute() : null,
    ]);
}

function faqMembersOnly(bool $value): void
{
    DB::table('settings')->updateOrInsert(
        ['key' => 'faq.members_only'],
        ['value' => $value ? 'true' : 'false', 'version' => 1, 'created_at' => now(), 'updated_at' => now()],
    );

    app()->forgetInstance(AnnouncementSettingsService::class);
}

it('FAQ-01: the route 404s while the flag is off', function (): void {
    Feature::for(null)->deactivate(FaqLibraryFeature::class);
    faqEntry('When am I paid?', 'Every Tuesday.');

    $this->actingAs(faqUser())->get(route('faq.index'))->assertNotFound();
});

it('FAQ-02: members-only by default — a visitor gets a 404', function (): void {
    faqEntry('When am I paid?', 'Every Tuesday.');

    $this->get(route('faq.index'))->assertNotFound();
    $this->actingAs(faqUser())->get(route('faq.index'))->assertOk();
});

it('FAQ-03: turning members-only off opens it to visitors', function (): void {
    faqMembersOnly(false);
    faqEntry('When am I paid?', 'Every Tuesday.');

    $this->get(route('faq.index'))->assertOk()->assertSee('When am I paid?');
});

it('FAQ-04: only published faq entries are listed', function (): void {
    faqEntry('Published answer', 'Visible.');
    faqEntry('Draft answer', 'Hidden.', 'Payouts', ContentPage::STATUS_DRAFT);

    // A page of another type must not leak into the library.
    ContentPage::create([
        'slug' => 'some-news-'.uniqid(),
        'type' => 'news',
        'title' => 'Not an answer',
        'body' => 'News item.',
        'status' => ContentPage::STATUS_PUBLISHED,
        'published_at' => now()->subMinute(),
    ]);

    $this->actingAs(faqUser())
        ->get(route('faq.index'))
        ->assertOk()
        ->assertSee('Published answer')
        ->assertDontSee('Draft answer')
        ->assertDontSee('Not an answer');
});

it('FAQ-05: search narrows on title and body', function (): void {
    faqEntry('When am I paid?', 'Payouts run every Tuesday.');
    faqEntry('How do I change my bank?', 'Raise a request from your profile.');

    $this->actingAs(faqUser())
        ->get(route('faq.index', ['q' => 'Tuesday']))
        ->assertOk()
        ->assertSee('When am I paid?')
        ->assertDontSee('How do I change my bank?');
});

it('FAQ-06: faq is refused as a content-page type while the flag is off', function (): void {
    Feature::for(null)->deactivate(FaqLibraryFeature::class);

    $request = new ContentPageRequest;
    $rules = $request->rules();

    $validator = validator(['type' => ContentPage::TYPE_FAQ], ['type' => $rules['type']]);

    expect($validator->fails())->toBeTrue();
});

it('FAQ-07: a published faq entry is not served by the generic /p/{slug} reader', function (): void {
    $entry = faqEntry('How much can I earn?', 'Nothing is guaranteed.');

    // The whole point of the flag and of faq.members_only is that this answer
    // is reachable only through /faq. Serving it here as well published it to
    // the open internet with both controls still on (found in review 2026-09-09).
    $this->get(route('content.show', ['slug' => $entry->slug]))->assertNotFound();

    // A page of any other type still reads normally.
    $page = ContentPage::create([
        'slug' => 'a-news-item-'.uniqid(),
        'type' => 'news',
        'title' => 'A news item',
        'body' => 'Body.',
        'status' => ContentPage::STATUS_PUBLISHED,
        'published_at' => now()->subMinute(),
    ]);

    $this->get(route('content.show', ['slug' => $page->slug]))->assertOk();
});

it('FAQ-08: an income projection is refused in an faq entry but allowed in the Code of Ethics', function (): void {
    // rules() reads the submitted type, so each case is built through a request
    // that actually carries one.
    $faqRequest = ContentPageRequest::create('/admin/content', 'POST', [
        'type' => ContentPage::TYPE_FAQ,
        'title' => 'What will I make?',
        'body' => 'Your earning potential is unlimited.',
        'slug' => 'what-will-i-make',
        'status' => ContentPage::STATUS_DRAFT,
    ]);
    $faqRules = $faqRequest->rules();
    expect(validator($faqRequest->all(), ['body' => $faqRules['body']])->fails())->toBeTrue();

    // The same words in an untyped policy page are not refused: the Code of
    // Ethics quotes the banned phrases in order to forbid them, and a blanket
    // rule would make that page uneditable. `type === null` is exactly that
    // policy/legal set — every typed page is audited.
    $policyRequest = ContentPageRequest::create('/admin/content', 'POST', [
        'type' => '',
        'title' => 'Code of Ethics',
        'body' => 'Phrases such as "passive income" or "earning potential" are prohibited.',
        'slug' => 'ethics',
        'status' => ContentPage::STATUS_DRAFT,
    ]);
    $policyRules = $policyRequest->rules();
    expect(validator($policyRequest->all(), ['body' => $policyRules['body']])->fails())->toBeFalse();

    // A blog post is not a policy page: marketing copy is audited like the rest.
    $blogRequest = ContentPageRequest::create('/admin/content', 'POST', [
        'type' => 'blog',
        'title' => 'A post',
        'body' => 'Your earning potential is unlimited.',
        'slug' => 'a-post',
        'status' => ContentPage::STATUS_DRAFT,
    ]);
    $blogRules = $blogRequest->rules();
    expect(validator($blogRequest->all(), ['body' => $blogRules['body']])->fails())->toBeTrue();
});

it('FAQ-09: saving an existing entry with a blank type still runs the copy rule', function (): void {
    $entry = faqEntry('What will I make?', 'Nothing is guaranteed.');

    $staff = faqUser();
    $staff->assignRole(Role::findOrCreate('admin', 'web'));

    // The type <select> can post an empty string, which becomes null before
    // the request sees it. Reading that literally made an ordinary save skip
    // the copy rule AND write type = null, which is publicly readable at
    // /p/{slug} — H-2's failure mode, back through the editor (review 2026-09-09).
    $this->actingAs($staff)
        ->from(route('admin.content.edit', $entry))
        ->patch(route('admin.content.update', $entry), [
            'title' => 'What will I make?',
            'slug' => $entry->slug,
            'type' => '',
            'body' => 'Your earning potential is unlimited.',
            'status' => ContentPage::STATUS_PUBLISHED,
        ])
        ->assertSessionHasErrors('body');

    $entry->refresh();

    expect($entry->type)->toBe(ContentPage::TYPE_FAQ)
        ->and($entry->body)->toBe('Nothing is guaranteed.');

    // …and it is still not readable as an ordinary page.
    $this->get(route('content.show', ['slug' => $entry->slug]))->assertNotFound();
});

it('FAQ-10: the editor offers faq as a type only while the flag is on', function (): void {
    $staff = faqUser();
    $staff->assignRole(Role::findOrCreate('admin', 'web'));

    $this->actingAs($staff)
        ->get(route('admin.content.create'))
        ->assertOk()
        ->assertSee('faq — FAQ answer')
        ->assertSee('FAQ category');

    Feature::for(null)->deactivate(FaqLibraryFeature::class);

    $this->actingAs($staff)
        ->get(route('admin.content.create'))
        ->assertOk()
        ->assertDontSee('faq — FAQ answer')
        ->assertDontSee('FAQ category');
});
