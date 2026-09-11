<?php

declare(strict_types=1);

/**
 * Company announcements.
 *
 *   ANN-01..02  the flag leaves no trace while it is off
 *   ANN-03      a draft is not visible to anyone
 *   ANN-04..06  audience: everyone, one account state, a rank and above
 *   ANN-07      an expired announcement stops appearing
 *   ANN-08      reading one marks it read; the unread count follows
 *   ANN-09..10  income-projection copy is refused, on save and on publish
 *   ANN-11      an announcement addressed elsewhere 404s by id
 *   ANN-12      the bell links to announcements when only announcements are unread (F54)
 */

use App\Modules\Content\Models\Announcement;
use App\Modules\Content\Services\AnnouncementService;
use App\Modules\Identity\Models\User;
use App\Modules\Shared\Features\AnnouncementsFeature;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Laravel\Pennant\Feature;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Feature::for(null)->activate(AnnouncementsFeature::class);
});

function annUser(string $key, string $status = 'active'): User
{
    return User::create([
        'full_name' => 'ANN '.$key,
        'email' => 'ann-'.$key.'-'.uniqid().'@example.com',
        'phone_e164' => '+91933'.str_pad((string) random_int(0, 9999999), 7, '0', STR_PAD_LEFT),
        'password_hash' => Hash::make('ann-test-pwd-2026'),
        'password_set_at' => now(),
        'status' => $status,
        'email_verified_at' => now(),
        'activated_at' => now(),
    ]);
}

/** A distributor row for $user. Returns the distributor id. */
function annDistributor(int $userId): int
{
    disableTestForeignKeys();
    try {
        $id = DB::table('distributors')->insertGetId([
            'user_id' => $userId,
            'adn' => (string) random_int(100000000, 999999999),
            'pan_hash' => random_bytes(32),
            'pan_last4' => '0000',
            'bank_account_enc' => 'stub',
            'bank_ifsc' => 'SBIN0000000',
            'sponsor_id' => 0,
            'placement_parent_id' => 0,
            'placement_side' => null,
            'side_chosen_by' => 'referral_default',
            'depth' => 0,
            'effective_date' => now()->format('Y-m-d H:i:s.v'),
            'cooling_off_end_at' => now()->copy()->addDays(30)->format('Y-m-d H:i:s.v'),
            'state' => 'TS',
            'is_primary_couple' => 0,
            'created_at' => now()->format('Y-m-d H:i:s.v'),
            'updated_at' => now()->format('Y-m-d H:i:s.v'),
        ]);
        DB::table('distributors')->where('id', $id)->update(['sponsor_id' => $id, 'placement_parent_id' => $id]);
    } finally {
        enableTestForeignKeys();
    }

    return $id;
}

function annPublished(array $attributes = []): Announcement
{
    return Announcement::create(array_merge([
        'title' => 'A notice',
        'body' => 'Something factual happened.',
        'audience' => Announcement::AUDIENCE_ALL,
        'status' => Announcement::STATUS_PUBLISHED,
        'published_at' => now()->subMinute(),
    ], $attributes));
}

it('ANN-01: the distributor routes 404 while the flag is off', function (): void {
    Feature::for(null)->deactivate(AnnouncementsFeature::class);

    $user = annUser('reader');
    annDistributor($user->id);
    $announcement = annPublished();

    $this->actingAs($user)->get(route('announcements.index'))->assertNotFound();
    $this->actingAs($user)->get(route('announcements.show', ['announcement' => $announcement->id]))->assertNotFound();
});

it('ANN-02: the admin routes 404 while the flag is off', function (): void {
    Feature::for(null)->deactivate(AnnouncementsFeature::class);
    Role::findOrCreate('admin', 'web');

    $admin = annUser('admin');
    $admin->assignRole('admin');

    $this->actingAs($admin)->get(route('admin.announcements.index'))->assertNotFound();
});

it('ANN-03: a draft is invisible to distributors', function (): void {
    $user = annUser('reader');
    annDistributor($user->id);
    annPublished(['status' => Announcement::STATUS_DRAFT, 'published_at' => null]);

    expect(app(AnnouncementService::class)->forUser($user))->toHaveCount(0);
});

it('ANN-04: an "everyone" announcement reaches a distributor', function (): void {
    $user = annUser('reader');
    annDistributor($user->id);
    annPublished();

    expect(app(AnnouncementService::class)->forUser($user))->toHaveCount(1);
});

it('ANN-05: a status-scoped announcement reaches only that account state', function (): void {
    $active = annUser('active', 'active');
    annDistributor($active->id);
    $frozen = annUser('frozen', 'frozen');
    annDistributor($frozen->id);

    annPublished([
        'audience' => Announcement::AUDIENCE_STATUS,
        'audience_value' => 'frozen',
    ]);

    $service = app(AnnouncementService::class);
    expect($service->forUser($frozen))->toHaveCount(1);
    expect($service->forUser($active))->toHaveCount(0);
});

it('ANN-06: a rank-scoped announcement reaches only those who have reached it', function (): void {
    $ranked = annUser('ranked');
    $rankedId = annDistributor($ranked->id);
    $unranked = annUser('unranked');
    annDistributor($unranked->id);

    DB::table('rank_qualifications')->insert([
        'distributor_id' => $rankedId,
        'rank_number' => 3,
        'month_start' => now()->startOfMonth()->format('Y-m-d'),
        'left_genos_bv_paise' => 0,
        'right_genos_bv_paise' => 0,
        'occurrence_in_month' => 1,
        'is_carry_forward' => 0,
        'status' => 'qualified',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    annPublished([
        'audience' => Announcement::AUDIENCE_RANK,
        'audience_value' => '2',
    ]);

    $service = app(AnnouncementService::class);
    expect($service->forUser($ranked))->toHaveCount(1);
    expect($service->forUser($unranked))->toHaveCount(0);
});

it('ANN-07: an expired announcement stops appearing', function (): void {
    $user = annUser('reader');
    annDistributor($user->id);
    annPublished(['expires_at' => now()->subMinute()]);

    expect(app(AnnouncementService::class)->forUser($user))->toHaveCount(0);
});

it('ANN-08: opening one marks it read and the unread count follows', function (): void {
    $user = annUser('reader');
    annDistributor($user->id);
    $announcement = annPublished();

    $service = app(AnnouncementService::class);
    expect($service->unreadCountFor($user))->toBe(1);

    $this->actingAs($user)
        ->get(route('announcements.show', ['announcement' => $announcement->id]))
        ->assertOk();

    expect(app(AnnouncementService::class)->unreadCountFor($user->fresh()))->toBe(0);
});

it('ANN-12: the bell links to announcements when only announcements are unread', function (): void {
    Feature::for(null)->activate(MessagingFeature::class);

    $user = annUser('bell');
    annDistributor($user->id);
    annPublished();

    $this->actingAs($user);
    $bell = (string) view('partials._notification-bell')->render();

    expect($bell)->toContain('href="'.route('announcements.index').'"')
        ->and($bell)->not->toContain('href="'.route('messages.index').'"');
});

it('ANN-09: copy implying a future income is refused on save', function (): void {
    Role::findOrCreate('admin', 'web');
    $admin = annUser('admin');
    $admin->assignRole('admin');

    $this->actingAs($admin)
        ->post(route('admin.announcements.store'), [
            'title' => 'Big news',
            'body' => 'Every member has unlimited earnings ahead of them.',
            'audience' => Announcement::AUDIENCE_ALL,
        ])
        ->assertSessionHasErrors('body');

    expect(Announcement::count())->toBe(0);
});

it('ANN-10: copy that slipped into a draft is refused again at publish', function (): void {
    Role::findOrCreate('admin', 'web');
    $admin = annUser('admin');
    $admin->assignRole('admin');

    // Written straight to the table, as an edit in a second tab would be.
    $announcement = annPublished([
        'status' => Announcement::STATUS_DRAFT,
        'published_at' => null,
        'body' => 'Guaranteed income for everyone who joins this month.',
    ]);

    $this->actingAs($admin)
        ->post(route('admin.announcements.transition', ['announcement' => $announcement->id]), [
            'status' => Announcement::STATUS_PUBLISHED,
        ])
        ->assertSessionHasErrors('body');

    expect($announcement->fresh()->status)->toBe(Announcement::STATUS_DRAFT);
});

it('ANN-11: an announcement addressed to someone else 404s by id', function (): void {
    $user = annUser('outsider', 'active');
    annDistributor($user->id);

    $announcement = annPublished([
        'audience' => Announcement::AUDIENCE_STATUS,
        'audience_value' => 'frozen',
    ]);

    $this->actingAs($user)
        ->get(route('announcements.show', ['announcement' => $announcement->id]))
        ->assertNotFound();
});
