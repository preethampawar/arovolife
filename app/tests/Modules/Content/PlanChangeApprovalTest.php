<?php

declare(strict_types=1);

/**
 * A second person approves every Compensation Plan change (R-125).
 *
 *   PCA-01  a proposal leaves the live page and its version date untouched, and is flagged in the Action Center
 *   PCA-02  admin cannot approve or reject; only the developer can
 *   PCA-03  the developer's approval publishes the change and dates it today
 *   PCA-04  nobody approves their own proposal
 *   PCA-05  a proposal made against a page that has since changed is refused
 *   PCA-06  only one proposal waits at a time
 *   PCA-07  rejecting needs a reason; rejecting and withdrawing leave the page alone; only the author withdraws
 *   PCA-08  a version dated before the day of approval is refused
 *   PCA-09  nothing is approved while the plan-change notice is off
 *   PCA-10  the plan's address cannot be changed, it is not archived from the archive button, and it is not created in the editor
 */

use App\Modules\ActionCenter\Providers\Compliance\ContentPlanChangeAwaitingApprovalProvider;
use App\Modules\Compliance\Models\AuditLog;
use App\Modules\Consent\Services\PlanChangeNotices;
use App\Modules\Content\Models\ContentPage;
use App\Modules\Content\Models\ContentPageRevision;
use App\Modules\Identity\Models\User;
use App\Modules\Shared\Features\PlanChangeNoticeFeature;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Laravel\Pennant\Feature;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->travelTo(Carbon::parse('2026-11-02 10:00:00'));
    seedConsentDocuments();
    disableTestForeignKeys();
    Feature::for(null)->activate(PlanChangeNoticeFeature::class);
});

function pcaUser(string $role): User
{
    $user = User::create([
        'full_name' => 'Plan '.$role,
        'email' => 'pca-'.uniqid().'@example.com',
        'phone_e164' => '+91944'.str_pad((string) random_int(0, 9999999), 7, '0', STR_PAD_LEFT),
        'password_hash' => Hash::make('pca-test-pwd-2026'),
        'password_set_at' => now(),
        'status' => 'active',
        'email_verified_at' => now(),
        'activated_at' => now(),
    ]);
    $user->assignRole(Role::findOrCreate($role, 'web'));

    return $user;
}

function pcaPlan(): ContentPage
{
    return ContentPage::query()->where('slug', 'compensation')->firstOrFail();
}

/** A correct plan change: new footer version and its own change entry. */
function pcaEditedBody(ContentPage $page, string $label = 'v2026-11-02'): string
{
    return str_replace(
        '<h3>Changes to this plan, by version</h3>',
        '<h3>Changes to this plan, by version</h3><h4>'.$label.'</h4><ul><li>A test change.</li></ul>',
        str_replace('disclosure version: v2026-10-27', 'disclosure version: '.$label, (string) $page->body),
    );
}

function pcaPropose(User $by, ?string $body = null): ContentPageRevision
{
    $page = pcaPlan();

    test()->actingAs($by)->patch(route('admin.content.update', $page), [
        'title' => $page->title,
        'slug' => $page->slug,
        'type' => '',
        'meta_description' => $page->meta_description,
        'body' => $body ?? pcaEditedBody($page),
        'status' => $page->status,
        'change_note' => 'Raised the minimum payout.',
    ])->assertSessionHasNoErrors();

    return ContentPageRevision::query()->latest('id')->firstOrFail();
}

it('PCA-01: a proposal leaves the live page and its version date untouched', function (): void {
    $page = pcaPlan();
    $before = (string) $page->body;
    $updatedAt = $page->updated_at;

    $revision = pcaPropose(pcaUser('admin'));
    $page->refresh();

    expect($page->body)->toBe($before)
        ->and($page->updated_at->equalTo($updatedAt))->toBeTrue()
        ->and($page->edited_in_portal_at)->toBeNull()
        ->and($revision->isPending())->toBeTrue()
        ->and(AuditLog::query()->where('action', 'content_page.change_requested')->count())->toBe(1)
        ->and(app(ContentPlanChangeAwaitingApprovalProvider::class)->count())->toBe(1);
});

it('PCA-02: admin cannot approve or reject; only the developer can', function (): void {
    $revision = pcaPropose(pcaUser('admin'));
    $otherAdmin = pcaUser('admin');

    $this->actingAs($otherAdmin)->post(route('admin.content.plan-changes.approve', $revision))->assertForbidden();
    $this->actingAs($otherAdmin)->post(route('admin.content.plan-changes.reject', $revision), ['decision_note' => 'No.'])->assertForbidden();

    // The review page itself is open to anyone who edits content, without the decision buttons.
    $this->actingAs($otherAdmin)->get(route('admin.content.plan-changes.show', $revision))
        ->assertOk()
        ->assertSee('Raised the minimum payout.')
        ->assertDontSee('Approve and publish');

    expect($revision->fresh()->isPending())->toBeTrue();
});

it('PCA-03: the developer\'s approval publishes the change and dates it today', function (): void {
    $revision = pcaPropose(pcaUser('admin'));
    $developer = pcaUser('developer');

    $this->actingAs($developer)->get(route('admin.content.plan-changes.show', $revision))
        ->assertOk()
        ->assertSee('Approve and publish')
        ->assertSee('What distributors will be told: v2026-11-02');

    $this->actingAs($developer)
        ->post(route('admin.content.plan-changes.approve', $revision), ['decision_note' => 'Checked.'])
        ->assertRedirect(route('admin.content.edit', pcaPlan()))
        ->assertSessionHas('status');

    $page = pcaPlan();
    $revision->refresh();
    app()->forgetScopedInstances();

    expect($page->body)->toBe($revision->body)
        ->and($page->updated_at->toDateString())->toBe('2026-11-02')
        ->and($page->updated_by_user_id)->toBe($developer->id)
        ->and($page->edited_in_portal_at)->not->toBeNull()
        ->and($revision->state)->toBe(ContentPageRevision::STATE_APPROVED)
        ->and($revision->decided_by)->toBe($developer->id)
        ->and(app(PlanChangeNotices::class)->blockedReason())->toBeNull()
        ->and(AuditLog::query()->where('action', 'content_page.change_approved')->count())->toBe(1);
});

it('PCA-04: nobody approves their own proposal', function (): void {
    $developer = pcaUser('developer');
    $revision = pcaPropose($developer);
    $before = (string) pcaPlan()->body;

    $this->actingAs($developer)
        ->post(route('admin.content.plan-changes.approve', $revision))
        ->assertSessionHas('error', fn (string $message): bool => str_contains($message, 'you cannot also approve it'));

    expect(pcaPlan()->body)->toBe($before)
        ->and($revision->fresh()->isPending())->toBeTrue()
        ->and(AuditLog::query()->where('action', 'content_page.change_self_approval_refused')->count())->toBe(1);
});

it('PCA-05: a proposal made against a page that has since changed is refused', function (): void {
    $revision = pcaPropose(pcaUser('admin'));
    pcaPlan()->update(['meta_description' => 'Changed behind the proposal.']);

    $this->actingAs(pcaUser('developer'))
        ->post(route('admin.content.plan-changes.approve', $revision))
        ->assertSessionHas('error', fn (string $message): bool => str_contains($message, 'has changed since this was proposed'));

    expect($revision->fresh()->isPending())->toBeTrue();
});

it('PCA-06: only one proposal waits at a time', function (): void {
    $admin = pcaUser('admin');
    pcaPropose($admin);
    $page = pcaPlan();

    $this->actingAs($admin)->patch(route('admin.content.update', $page), [
        'title' => 'Another title',
        'slug' => $page->slug,
        'type' => '',
        'meta_description' => $page->meta_description,
        'body' => (string) $page->body,
        'status' => $page->status,
        'change_note' => 'A second change.',
    ])->assertSessionHasErrors('plan_change');

    expect(ContentPageRevision::query()->count())->toBe(1);
});

it('PCA-07: rejecting needs a reason, closing leaves the page alone, and only the author withdraws', function (): void {
    $admin = pcaUser('admin');
    $developer = pcaUser('developer');
    $page = pcaPlan();
    $updatedAt = $page->updated_at;

    $first = pcaPropose($admin);

    $this->actingAs($developer)->post(route('admin.content.plan-changes.reject', $first))->assertSessionHasErrors('decision_note');
    $this->actingAs($developer)->post(route('admin.content.plan-changes.reject', $first), ['decision_note' => 'Wrong date.'])
        ->assertSessionHas('status');

    $second = pcaPropose($admin);

    $this->actingAs(pcaUser('admin'))->post(route('admin.content.plan-changes.withdraw', $second))
        ->assertSessionHas('error', fn (string $message): bool => str_contains($message, 'Only the person who proposed'));
    $this->actingAs($admin)->post(route('admin.content.plan-changes.withdraw', $second))->assertSessionHas('status');

    expect($first->fresh()->state)->toBe(ContentPageRevision::STATE_REJECTED)
        ->and($first->fresh()->decision_note)->toBe('Wrong date.')
        ->and($second->fresh()->state)->toBe(ContentPageRevision::STATE_WITHDRAWN)
        ->and(pcaPlan()->updated_at->equalTo($updatedAt))->toBeTrue();
});

it('PCA-08: a version dated before the day of approval is refused', function (): void {
    $revision = pcaPropose(pcaUser('admin'));

    // Approved a day later: v2026-11-02 would now apply the change to the past.
    $this->travelTo(Carbon::parse('2026-11-03 09:00:00'));

    $this->actingAs(pcaUser('developer'))
        ->post(route('admin.content.plan-changes.approve', $revision))
        ->assertSessionHas('error', fn (string $message): bool => str_contains($message, 'before today'));

    expect($revision->fresh()->isPending())->toBeTrue();
});

it('PCA-09: nothing is approved while the plan-change notice is off', function (): void {
    $revision = pcaPropose(pcaUser('admin'));
    Feature::for(null)->deactivate(PlanChangeNoticeFeature::class);

    $this->actingAs(pcaUser('developer'))
        ->post(route('admin.content.plan-changes.approve', $revision))
        ->assertSessionHas('error', fn (string $message): bool => str_contains($message, 'switched off'));

    expect($revision->fresh()->isPending())->toBeTrue();
});

it('PCA-10: the plan\'s address cannot be changed, and the archive button does not archive it', function (): void {
    $admin = pcaUser('admin');
    $page = pcaPlan();

    $this->actingAs($admin)->patch(route('admin.content.update', $page), [
        'title' => $page->title,
        'slug' => 'compensation-old',
        'type' => '',
        'meta_description' => $page->meta_description,
        'body' => pcaEditedBody($page),
        'status' => $page->status,
        'change_note' => 'Rename.',
    ])->assertSessionHasErrors('slug');

    $this->actingAs($admin)->delete(route('admin.content.destroy', $page))->assertSessionHas('error');

    expect(pcaPlan()->slug)->toBe('compensation')
        ->and(pcaPlan()->status)->toBe(ContentPage::STATUS_PUBLISHED)
        ->and(ContentPageRevision::query()->count())->toBe(0);

    // Nor is it created in the editor if the row were ever missing.
    $page->delete();
    $this->actingAs($admin)->post(route('admin.content.store'), [
        'title' => 'Plan', 'slug' => 'compensation', 'type' => '', 'body' => '<p>x</p>', 'status' => ContentPage::STATUS_PUBLISHED,
    ])->assertSessionHasErrors('slug');
    expect(ContentPage::query()->where('slug', 'compensation')->exists())->toBeFalse();
});
