<?php

declare(strict_types=1);

/**
 * The compensation-plan change notice (plan page §12, R-123).
 *
 * PCN-01 a distributor whose accepted plan text differs sees no pop-up after login (user decision 2026-10-26)
 * PCN-02 nothing shows once they hold a consent for the published text, or with the flag OFF
 * PCN-03 nothing shows for super-staff, under impersonation, or on the exempt pages
 * PCN-04 agreeing records a plan consent with the published version + hash, links it, and emails a receipt
 * PCN-05 agreeing to a text that is no longer published records nothing
 * PCN-06 agreeing is refused under impersonation
 * PCN-07 "Remind me later" still answers, and nothing shows afterwards either
 * PCN-08 browsing the account records no shown_at, so only the email counts as notice
 * PCN-09 the email waits 3 days from the notice start, goes to everyone not agreed, once
 * PCN-10 the email skips agreed, terminated and reserved accounts, and does nothing with the flag OFF
 * PCN-11 the list shows only versions newer than the one the distributor accepted
 * PCN-12 the published page yields entries, newest first, and the newest matches the footer label
 * PCN-14 agreeing twice (two tabs, a double click) records one consent and sends one receipt
 * PCN-15 the notice is held when the text changed under a reused label, or has no readable change list
 * PCN-16 a failed email is recorded, counted as never reached, and retried on the next run
 */

use App\Modules\Consent\Models\Consent;
use App\Modules\Consent\Models\PlanChangeNotice;
use App\Modules\Consent\Models\PlanTextVersion;
use App\Modules\Consent\Notifications\PlanChangeAcceptedNotification;
use App\Modules\Consent\Notifications\PlanChangeNoticeNotification;
use App\Modules\Consent\Services\PlanChangeNotices;
use App\Modules\Content\Models\ContentPage;
use App\Modules\Genealogy\Support\ReservedAdns;
use App\Modules\Identity\Models\Distributor;
use App\Modules\Identity\Models\User;
use App\Modules\Shared\Features\PlanChangeNoticeFeature;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Laravel\Pennant\Feature;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    seedConsentDocuments();
    disableTestForeignKeys();
    Feature::for(null)->activate(PlanChangeNoticeFeature::class);
});

function pcnPlanHash(): string
{
    $body = (string) ContentPage::query()->where('slug', 'compensation')->value('body');

    return hash('sha256', $body, true);
}

/** A distributor holding a plan consent for some older text. */
function pcnDistributor(string $status = 'active', ?string $planHash = null, array $distributorAttributes = []): Distributor
{
    $user = User::factory()->create(['status' => $status]);
    $distributor = Distributor::factory()->create(array_merge(['user_id' => $user->id, 'status' => 'active'], $distributorAttributes));

    Consent::create([
        'distributor_id' => $distributor->id,
        'document_type' => 'plan',
        'document_version' => '2026-10-18',
        'doc_hash_sha256' => $planHash ?? hash('sha256', 'plan v2026-10-17', true),
        'accepted_at' => '2026-10-20 10:00:00',
        'ip' => '203.0.113.7',
        'user_agent' => 'test',
    ]);

    return $distributor->fresh();
}

it('PCN-01: shows no pop-up to a distributor on an older plan text', function () {
    $distributor = pcnDistributor();

    $this->actingAs($distributor->user)
        ->get(route('profile.show'))
        ->assertOk()
        ->assertDontSee('The arovolife Compensation Plan has changed')
        ->assertDontSee('I have read the changes and agree')
        ->assertDontSee('Remind me later');
});

it('PCN-02: shows nothing once the published text is agreed, or while the flag is off', function () {
    $agreed = pcnDistributor(planHash: pcnPlanHash());

    $this->actingAs($agreed->user)->get(route('profile.show'))
        ->assertOk()->assertDontSee('I have read the changes and agree');

    Feature::for(null)->deactivate(PlanChangeNoticeFeature::class);
    $pending = pcnDistributor();

    $this->actingAs($pending->user)->get(route('profile.show'))
        ->assertOk()->assertDontSee('I have read the changes and agree');
});

it('PCN-03: shows nothing to super-staff, under impersonation, or on the exempt pages', function () {
    Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
    $staff = pcnDistributor();
    $staff->user->assignRole('admin');

    $this->actingAs($staff->user)->get(route('profile.show'))
        ->assertDontSee('I have read the changes and agree');

    $distributor = pcnDistributor();

    $this->actingAs($distributor->user)
        ->withSession(['impersonator_id' => 1])
        ->get(route('profile.show'))
        ->assertDontSee('I have read the changes and agree');

    $this->flushSession();
    $this->actingAs($distributor->user)
        ->get(route('consent.record'))
        ->assertOk()
        ->assertDontSee('I have read the changes and agree');
});

it('PCN-04: agreeing records a plan consent for the published text and emails a receipt', function () {
    Notification::fake();
    $distributor = pcnDistributor();
    $page = ContentPage::query()->where('slug', 'compensation')->firstOrFail();

    $this->actingAs($distributor->user)
        ->from(route('profile.show'))
        ->post(route('plan-changes.accept'), ['hash' => bin2hex(pcnPlanHash())])
        ->assertRedirect(route('profile.show'))
        ->assertSessionHas('status');

    $consent = Consent::query()
        ->where('distributor_id', $distributor->id)
        ->where('document_type', 'plan')
        ->orderByDesc('id')
        ->firstOrFail();

    expect($consent->getRawOriginal('doc_hash_sha256'))->toBe(pcnPlanHash())
        ->and($consent->document_version)->toBe($page->updated_at->format('Y-m-d'))
        ->and(Consent::query()->where('distributor_id', $distributor->id)->where('document_type', 'plan')->count())->toBe(2);

    $notice = PlanChangeNotice::query()->where('distributor_id', $distributor->id)->firstOrFail();
    expect($notice->accepted_consent_id)->toBe($consent->id);

    Notification::assertSentTo($distributor->user, PlanChangeAcceptedNotification::class,
        fn (PlanChangeAcceptedNotification $n): bool => $n->label === 'v2026-10-27' && count($n->entries) === 2);

    $this->actingAs($distributor->user)->get(route('profile.show'))
        ->assertDontSee('I have read the changes and agree');
});

it('PCN-05: agreeing to a text that is no longer published records nothing', function () {
    $distributor = pcnDistributor();

    $this->actingAs($distributor->user)
        ->post(route('plan-changes.accept'), ['hash' => str_repeat('ab', 32)])
        ->assertRedirect()
        ->assertSessionHas('status');

    expect(Consent::query()->where('distributor_id', $distributor->id)->where('document_type', 'plan')->count())->toBe(1);
});

it('PCN-06: agreeing is refused while staff impersonate the distributor', function () {
    $distributor = pcnDistributor();

    $this->actingAs($distributor->user)
        ->withSession(['impersonator_id' => 1])
        ->post(route('plan-changes.accept'), ['hash' => bin2hex(pcnPlanHash())])
        ->assertForbidden();

    expect(Consent::query()->where('distributor_id', $distributor->id)->where('document_type', 'plan')->count())->toBe(1);
});

it('PCN-07: "Remind me later" still answers, and nothing shows afterwards either', function () {
    $distributor = pcnDistributor();

    $this->actingAs($distributor->user)
        ->post(route('plan-changes.later'), ['hash' => bin2hex(pcnPlanHash())])
        ->assertRedirect();

    $this->flushSession();

    $this->actingAs($distributor->user)->get(route('profile.show'))
        ->assertOk()
        ->assertDontSee('I have read the changes and agree');
});

it('PCN-08: browsing the account records no shown_at, so only the email counts as notice', function () {
    $distributor = pcnDistributor();

    $this->actingAs($distributor->user)->get(route('profile.show'))->assertOk();

    expect(PlanChangeNotice::query()->where('distributor_id', $distributor->id)->whereNotNull('shown_at')->exists())->toBeFalse();
});

it('PCN-09: emails everyone not agreed once the notice is 3 days old, once, even if they saw the pop-up', function () {
    Notification::fake();
    Carbon::setTestNow('2026-11-01 10:00:00');
    $seen = pcnDistributor();
    $unseen = pcnDistributor();
    $notices = app(PlanChangeNotices::class);
    $notices->currentVersion();
    $notices->markShown($seen, $notices->pendingFor($seen->user));

    Carbon::setTestNow('2026-11-03 10:00:00');
    expect(app(PlanChangeNotices::class)->notifyUnread()->emailedNow)->toBe(0);
    Notification::assertNothingSent();

    Carbon::setTestNow('2026-11-04 10:00:00');
    $this->artisan('plan:notify-unread')->assertSuccessful();

    Notification::assertSentTo($seen->user, PlanChangeNoticeNotification::class);
    Notification::assertSentTo($unseen->user, PlanChangeNoticeNotification::class,
        fn (PlanChangeNoticeNotification $n): bool => $n->adn === $unseen->adn && count($n->entries) === 2);
    expect(PlanChangeNotice::query()->whereNotNull('emailed_at')->count())->toBe(2);

    Carbon::setTestNow('2026-11-05 10:00:00');
    expect(app(PlanChangeNotices::class)->notifyUnread()->emailedNow)->toBe(0);
    Notification::assertSentTimes(PlanChangeNoticeNotification::class, 2);

    Carbon::setTestNow();
});

it('PCN-10: the email skips agreed, terminated and reserved accounts, and does nothing with the flag off', function () {
    Notification::fake();
    Carbon::setTestNow('2026-11-01 10:00:00');
    $agreed = pcnDistributor(planHash: pcnPlanHash());
    $terminated = pcnDistributor('terminated');
    $reserved = pcnDistributor(distributorAttributes: ['adn' => ReservedAdns::CHILDREN[0]]);
    $rejected = pcnDistributor('rejected');
    app(PlanChangeNotices::class)->currentVersion();

    Carbon::setTestNow('2026-11-04 10:00:00');
    Feature::for(null)->deactivate(PlanChangeNoticeFeature::class);
    expect(app(PlanChangeNotices::class)->notifyUnread()->noticeStartedAt)->toBeNull();
    Notification::assertNothingSent();

    Feature::for(null)->activate(PlanChangeNoticeFeature::class);
    $summary = app(PlanChangeNotices::class)->notifyUnread();

    Notification::assertSentTo($rejected->user, PlanChangeNoticeNotification::class);
    Notification::assertNotSentTo($agreed->user, PlanChangeNoticeNotification::class);
    Notification::assertNotSentTo($terminated->user, PlanChangeNoticeNotification::class);
    Notification::assertNotSentTo($reserved->user, PlanChangeNoticeNotification::class);
    expect($summary->emailedNow)->toBe(1)
        ->and($summary->accepted)->toBe(1)
        ->and($summary->reservedNotAccepted)->toBe(1);

    Carbon::setTestNow();
});

it('PCN-11: lists only versions newer than the one the distributor accepted', function () {
    $older = hash('sha256', 'plan v2026-10-26 text', true);
    PlanTextVersion::query()->create([
        'doc_hash_sha256' => $older,
        'label' => 'v2026-10-26',
        'notice_started_at' => now()->subDays(40),
    ]);
    $distributor = pcnDistributor(planHash: $older);
    $notices = app(PlanChangeNotices::class);

    $labels = array_map(fn ($e) => $e->label, $notices->pendingFor($distributor->user)->entries);
    expect($labels)->toBe(['v2026-10-27']);

    $unknown = pcnDistributor();
    $labels = array_map(fn ($e) => $e->label, app(PlanChangeNotices::class)->pendingFor($unknown->user)->entries);
    expect($labels)->toBe(['v2026-10-27', 'v2026-10-26']);
});

it('PCN-12: the published page yields entries, newest first, and the newest matches the footer label', function () {
    $body = (string) ContentPage::query()->where('slug', 'compensation')->value('body');
    $notices = app(PlanChangeNotices::class);
    $entries = $notices->entries($body);

    expect($entries)->not->toBeEmpty()
        ->and($entries[0]->label)->toBe($notices->label($body))
        ->and($entries[0]->html)->toContain('<li>')
        // The block ends at the footer rule; nothing after it leaks into the last entry.
        ->and($entries[array_key_last($entries)]->html)->not->toContain('current version of this page')
        ->and($notices->entries('<p>No change list here.</p>'))->toBe([]);
});

it('PCN-14: agreeing twice records one consent and sends one receipt', function () {
    Notification::fake();
    $distributor = pcnDistributor();
    $hash = bin2hex(pcnPlanHash());

    $this->actingAs($distributor->user)->post(route('plan-changes.accept'), ['hash' => $hash]);
    $this->actingAs($distributor->user)->post(route('plan-changes.accept'), ['hash' => $hash]);

    expect(Consent::query()->where('distributor_id', $distributor->id)->where('document_type', 'plan')->count())->toBe(2);
    Notification::assertSentTimes(PlanChangeAcceptedNotification::class, 1);
});

it('PCN-15: the notice is held when the text changed under a reused label, or has no readable list', function () {
    Notification::fake();
    Carbon::setTestNow('2026-11-01 10:00:00');
    PlanTextVersion::query()->create([
        'doc_hash_sha256' => hash('sha256', 'an earlier v2026-10-27 text', true),
        'label' => 'v2026-10-27',
        'notice_started_at' => now()->subDays(10),
    ]);
    $distributor = pcnDistributor();

    $this->actingAs($distributor->user)->get(route('profile.show'))
        ->assertOk()->assertDontSee('I have read the changes and agree');

    Carbon::setTestNow('2026-11-10 10:00:00');
    $this->artisan('plan:notify-unread')
        ->expectsOutputToContain('kept the version label v2026-10-27')
        ->assertFailed();
    Notification::assertNothingSent();

    PlanTextVersion::query()->delete();
    ContentPage::query()->where('slug', 'compensation')->update(['body' => '<p>Plan text.</p><p><em>Compensation Plan disclosure version: v2026-11-01</em></p>']);
    $this->app->forgetScopedInstances();
    $notices = app(PlanChangeNotices::class);

    expect($notices->blockedReason())->toContain('no readable')
        ->and($notices->pendingFor($distributor->user))->toBeNull()
        ->and(PlanTextVersion::query()->count())->toBe(0);

    // A hand-made POST with the held text's hash records nothing either.
    $heldHash = hash('sha256', '<p>Plan text.</p><p><em>Compensation Plan disclosure version: v2026-11-01</em></p>');
    expect($notices->accept($distributor, $heldHash, '203.0.113.7', 'test'))->toBeNull()
        ->and(Consent::query()->where('distributor_id', $distributor->id)->where('document_type', 'plan')->count())->toBe(1);

    Carbon::setTestNow();
});

it('PCN-16: a failed email is recorded, counted as never reached, and retried next run', function () {
    Carbon::setTestNow('2026-11-01 10:00:00');
    $distributor = pcnDistributor();
    app(PlanChangeNotices::class)->currentVersion();
    Carbon::setTestNow('2026-11-04 10:00:00');

    // A mailer that cannot connect: the send throws, as a real outage would.
    config([
        'mail.default' => 'unreachable',
        'mail.mailers.unreachable' => ['transport' => 'smtp', 'host' => '127.0.0.1', 'port' => 1, 'timeout' => 2],
    ]);
    $summary = app(PlanChangeNotices::class)->notifyUnread();

    $notice = PlanChangeNotice::query()->where('distributor_id', $distributor->id)->sole();
    expect($summary->failedAdns)->toBe([$distributor->adn])
        ->and($summary->neverReached)->toBe(1)
        ->and($notice->emailed_at)->toBeNull()
        ->and($notice->email_failed_at)->not->toBeNull();

    Notification::fake();
    $this->app->forgetScopedInstances();
    $summary = app(PlanChangeNotices::class)->notifyUnread();

    expect($summary->failedAdns)->toBe([])
        ->and($summary->emailedNow)->toBe(1)->and($summary->neverReached)->toBe(0);
    Notification::assertSentTo($distributor->user, PlanChangeNoticeNotification::class);

    Carbon::setTestNow();
});
