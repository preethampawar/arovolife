<?php

declare(strict_types=1);

/**
 * The messaging policy guards, all of which live in MessageService.
 *
 *   POL-01..03  audience: own line only, staff bypass, company reachable
 *   POL-04..05  block list
 *   POL-06..07  rate limits (per hour, per recipient per day)
 *   POL-08..09  PAN / Aadhaar refused in the body
 *   POL-10..11  reporting
 *   POL-12..13  the killswitch leaves no trace
 *   POL-14      opening a report is audit-logged (the DPDP control)
 *   POL-15      the Aadhaar branch: Verhoeff-valid refused, random 12 digits not
 *
 * Every case asserts through MessageService rather than the controller: the
 * tree-card modal is a second entry point, and a guard proved only at the
 * controller would not cover it.
 */

use App\Modules\Compliance\Models\AuditLog;
use App\Modules\Identity\Models\User;
use App\Modules\Messaging\Exceptions\MessageRefused;
use App\Modules\Messaging\Models\Message;
use App\Modules\Messaging\Models\MessageBlock;
use App\Modules\Messaging\Models\MessageReport;
use App\Modules\Messaging\Services\MessageService;
use App\Modules\Messaging\Services\MessagingSettingsService;
use App\Modules\Shared\Features\MessagingFeature;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;
use Laravel\Pennant\Feature;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Notification::fake();
    RateLimiter::clear('messaging:send:1');
});

function polUser(string $key, string $status = 'active'): User
{
    return User::create([
        'full_name' => 'POL '.$key,
        'email' => 'pol-'.$key.'-'.uniqid().'@example.com',
        'phone_e164' => '+91944'.str_pad((string) random_int(0, 9999999), 7, '0', STR_PAD_LEFT),
        'password_hash' => Hash::make('pol-test-pwd-2026'),
        'password_set_at' => now(),
        'status' => $status,
        'email_verified_at' => now(),
        'activated_at' => now(),
    ]);
}

/** Seed a distributor row + its closure rows. Returns the distributor id. */
function polDistributor(int $userId, ?int $parentId = null): int
{
    disableTestForeignKeys();
    try {
        $depth = $parentId === null
            ? 0
            : (int) DB::table('distributors')->where('id', $parentId)->value('depth') + 1;

        $id = DB::table('distributors')->insertGetId([
            'user_id' => $userId,
            'adn' => (string) random_int(100000000, 999999999),
            'pan_hash' => random_bytes(32),
            'pan_last4' => '0000',
            'bank_account_enc' => 'stub',
            'bank_ifsc' => 'SBIN0000000',
            'sponsor_id' => $parentId ?? 0,
            'placement_parent_id' => $parentId ?? 0,
            'placement_side' => $parentId !== null ? 'L' : null,
            'side_chosen_by' => 'referral_default',
            'depth' => $depth,
            'effective_date' => now()->format('Y-m-d H:i:s.v'),
            'cooling_off_end_at' => now()->copy()->addDays(30)->format('Y-m-d H:i:s.v'),
            'state' => 'TS',
            'is_primary_couple' => 0,
            'created_at' => now()->format('Y-m-d H:i:s.v'),
            'updated_at' => now()->format('Y-m-d H:i:s.v'),
        ]);

        if ($parentId === null) {
            DB::table('distributors')->where('id', $id)->update([
                'sponsor_id' => $id,
                'placement_parent_id' => $id,
            ]);
        }
    } finally {
        enableTestForeignKeys();
    }

    DB::table('genealogy_closure')->insert(['ancestor_id' => $id, 'descendant_id' => $id, 'depth' => 0]);

    if ($parentId !== null) {
        foreach (DB::table('genealogy_closure')->where('descendant_id', $parentId)->get(['ancestor_id', 'depth']) as $ancestor) {
            DB::table('genealogy_closure')->insert([
                'ancestor_id' => $ancestor->ancestor_id,
                'descendant_id' => $id,
                'depth' => $ancestor->depth + 1,
            ]);
        }
    }

    return $id;
}

/** Override a messaging setting and drop the singleton's cached read. */
function polSetting(string $key, string $value): void
{
    DB::table('settings')->updateOrInsert(
        ['key' => $key],
        ['value' => $value, 'version' => 1, 'created_at' => now(), 'updated_at' => now()],
    );

    app()->forgetInstance(MessagingSettingsService::class);
    app()->forgetInstance(MessageService::class);
}

it('POL-01: refuses a send to someone outside the sender’s own line', function (): void {
    $alice = polUser('alice');
    $stranger = polUser('stranger');
    polDistributor($alice->id);
    polDistributor($stranger->id);

    expect(fn () => app(MessageService::class)->send($alice, $stranger, 'Join my team'))
        ->toThrow(MessageRefused::class);

    expect(Message::count())->toBe(0);
});

it('POL-02: allows a send down the sender’s own placement line', function (): void {
    $sponsor = polUser('sponsor');
    $downline = polUser('downline');
    $sponsorId = polDistributor($sponsor->id);
    polDistributor($downline->id, $sponsorId);

    $message = app(MessageService::class)->send($sponsor, $downline, 'Well done this week');

    expect($message->exists)->toBeTrue();

    // …and back up it, so a downline can reply to their upline.
    expect(app(MessageService::class)->send($downline, $sponsor, 'Thank you')->exists)->toBeTrue();
});

it('POL-03: staff may message anyone, and anyone may message staff', function (): void {
    Role::findOrCreate('admin-compliance', 'web');

    $staff = polUser('staff');
    $staff->assignRole('admin-compliance');
    $stranger = polUser('stranger');
    polDistributor($stranger->id);

    expect(app(MessageService::class)->send($staff, $stranger, 'About your account')->exists)->toBeTrue();
    expect(app(MessageService::class)->send($stranger, $staff, 'A question')->exists)->toBeTrue();
});

it('POL-04: a blocked sender is refused', function (): void {
    $sponsor = polUser('sponsor');
    $downline = polUser('downline');
    $sponsorId = polDistributor($sponsor->id);
    polDistributor($downline->id, $sponsorId);

    MessageBlock::create(['blocker_user_id' => $downline->id, 'blocked_user_id' => $sponsor->id]);

    expect(fn () => app(MessageService::class)->send($sponsor, $downline, 'Hello?'))
        ->toThrow(MessageRefused::class);
});

it('POL-05: the block is one-directional — the blocker can still write', function (): void {
    $sponsor = polUser('sponsor');
    $downline = polUser('downline');
    $sponsorId = polDistributor($sponsor->id);
    polDistributor($downline->id, $sponsorId);

    MessageBlock::create(['blocker_user_id' => $downline->id, 'blocked_user_id' => $sponsor->id]);

    expect(app(MessageService::class)->send($downline, $sponsor, 'Leave me alone')->exists)->toBeTrue();
});

it('POL-05b: the thread page renders its flash status once, not twice (F79)', function (): void {
    $sponsor = polUser('sponsor');
    $downline = polUser('downline');
    $sponsorId = polDistributor($sponsor->id);
    polDistributor($downline->id, $sponsorId);

    $response = $this->actingAs($sponsor)
        ->withSession(['status' => 'You have blocked this person. They cannot send you new messages, and they have not been told.'])
        ->get(route('messages.show', ['user' => $downline->id]))
        ->assertOk();

    expect(substr_count(
        $response->getContent(),
        'You have blocked this person. They cannot send you new messages, and they have not been told.',
    ))->toBe(1);
});

it('POL-05c: opening a thread with someone outside your line 404s (F25/F75)', function (): void {
    $alice = polUser('alice');
    $stranger = polUser('stranger');
    polDistributor($alice->id);
    polDistributor($stranger->id);

    $this->actingAs($alice)
        ->get(route('messages.show', ['user' => $stranger->id]))
        ->assertNotFound();
});

it('POL-05d: walking sequential user ids reveals no name (F25/F75)', function (): void {
    $sponsor = polUser('sponsor');
    $downline = polUser('downline');
    $sponsorId = polDistributor($sponsor->id);
    polDistributor($downline->id, $sponsorId);

    // Everyone the enumerator is not on a line with, plus an id that does
    // not exist at all — the two must be indistinguishable from outside.
    $strangers = [polUser('s1'), polUser('s2'), polUser('s3')];
    foreach ($strangers as $stranger) {
        polDistributor($stranger->id);
    }

    foreach ($strangers as $stranger) {
        $response = $this->actingAs($sponsor)
            ->get(route('messages.show', ['user' => $stranger->id]))
            ->assertNotFound();

        expect($response->getContent())->not->toContain($stranger->full_name)
            ->and($response->getContent())->not->toContain($stranger->email);
    }

    $this->actingAs($sponsor)->get(route('messages.show', ['user' => 999999]))->assertNotFound();

    // The viewer's own line still opens.
    $this->actingAs($sponsor)->get(route('messages.show', ['user' => $downline->id]))->assertOk();
});

it('POL-05e: a thread already exchanged stays readable after a block (F25/F75)', function (): void {
    $sponsor = polUser('sponsor');
    $downline = polUser('downline');
    $sponsorId = polDistributor($sponsor->id);
    polDistributor($downline->id, $sponsorId);

    app(MessageService::class)->send($sponsor, $downline, 'Well done this week');

    MessageBlock::create(['blocker_user_id' => $downline->id, 'blocked_user_id' => $sponsor->id]);

    expect(app(MessageService::class)->canMessage($sponsor, $downline))->toBeFalse();

    $this->actingAs($sponsor)
        ->get(route('messages.show', ['user' => $downline->id]))
        ->assertOk();
});

it('POL-06: the hourly cap refuses the send past the limit', function (): void {
    polSetting('messaging.rate_limit_per_hour', '2');
    polSetting('messaging.rate_limit_per_recipient_per_day', '0');

    $sponsor = polUser('sponsor');
    $sponsorId = polDistributor($sponsor->id);

    $service = app(MessageService::class);

    // Chained rather than three siblings: a placement parent holds one
    // distributor per side, so three under the same node is not a tree.
    $parentId = $sponsorId;
    foreach (['a', 'b', 'c'] as $key) {
        $downline = polUser($key);
        $parentId = polDistributor($downline->id, $parentId);

        if ($key === 'c') {
            expect(fn () => $service->send($sponsor, $downline, 'hi'))->toThrow(MessageRefused::class);

            continue;
        }

        expect($service->send($sponsor, $downline, 'hi')->exists)->toBeTrue();
    }
});

it('POL-07: a refused send does not consume the sender’s quota', function (): void {
    polSetting('messaging.rate_limit_per_hour', '2');

    $sponsor = polUser('sponsor');
    $sponsorId = polDistributor($sponsor->id);
    $stranger = polUser('stranger');
    polDistributor($stranger->id);
    $downline = polUser('downline');
    polDistributor($downline->id, $sponsorId);

    $service = app(MessageService::class);

    // Two refusals against someone out of scope…
    expect(fn () => $service->send($sponsor, $stranger, 'hi'))->toThrow(MessageRefused::class);
    expect(fn () => $service->send($sponsor, $stranger, 'hi'))->toThrow(MessageRefused::class);

    // …must not have spent the two sends they were entitled to.
    expect($service->send($sponsor, $downline, 'one')->exists)->toBeTrue();
    expect($service->send($sponsor, $downline, 'two')->exists)->toBeTrue();
});

it('POL-08: a body carrying a full PAN is refused', function (): void {
    $sponsor = polUser('sponsor');
    $downline = polUser('downline');
    $sponsorId = polDistributor($sponsor->id);
    polDistributor($downline->id, $sponsorId);

    expect(fn () => app(MessageService::class)->send($sponsor, $downline, 'My PAN is ABCDE1234F, please check'))
        ->toThrow(MessageRefused::class);

    expect(Message::count())->toBe(0);
});

it('POL-09: the PAN guard holds for staff too — hard rule 8 has no staff exception', function (): void {
    Role::findOrCreate('admin-compliance', 'web');

    $staff = polUser('staff');
    $staff->assignRole('admin-compliance');
    $member = polUser('member');
    polDistributor($member->id);

    expect(fn () => app(MessageService::class)->send($staff, $member, 'Confirming PAN ABCDE1234F'))
        ->toThrow(MessageRefused::class);
});

it('POL-10: the recipient can report a message they received', function (): void {
    $sponsor = polUser('sponsor');
    $downline = polUser('downline');
    $sponsorId = polDistributor($sponsor->id);
    polDistributor($downline->id, $sponsorId);

    $message = app(MessageService::class)->send($sponsor, $downline, 'You will earn a lot');

    $this->actingAs($downline)
        ->post(route('messages.report', ['message' => $message->id]), [
            'category' => 'income_claim',
            'reason' => 'Promised earnings',
        ])
        ->assertRedirect();

    expect(MessageReport::where('message_id', $message->id)->count())->toBe(1);
});

it('POL-11: nobody but the recipient can report a message', function (): void {
    $sponsor = polUser('sponsor');
    $downline = polUser('downline');
    $sponsorId = polDistributor($sponsor->id);
    polDistributor($downline->id, $sponsorId);

    $message = app(MessageService::class)->send($sponsor, $downline, 'hello');

    // The sender cannot report their own message…
    $this->actingAs($sponsor)
        ->post(route('messages.report', ['message' => $message->id]), ['category' => 'spam'])
        ->assertForbidden();

    // …nor can an unrelated third party.
    $outsider = polUser('outsider');
    polDistributor($outsider->id);
    $this->actingAs($outsider)
        ->post(route('messages.report', ['message' => $message->id]), ['category' => 'spam'])
        ->assertForbidden();

    expect(MessageReport::count())->toBe(0);
});

it('POL-12: the killswitch 404s every messaging route', function (): void {
    Feature::for(null)->deactivate(MessagingFeature::class);

    $alice = polUser('alice');
    $bob = polUser('bob');
    polDistributor($alice->id);
    polDistributor($bob->id);

    $this->actingAs($alice)->get(route('messages.index'))->assertNotFound();
    $this->actingAs($alice)->get(route('messages.show', ['user' => $bob->id]))->assertNotFound();
    $this->actingAs($alice)->post(route('messages.store', ['user' => $bob->id]), ['body' => 'hi'])->assertNotFound();
});

it('POL-13: the killswitch refuses a send made through the service directly', function (): void {
    Feature::for(null)->deactivate(MessagingFeature::class);

    $sponsor = polUser('sponsor');
    $downline = polUser('downline');
    $sponsorId = polDistributor($sponsor->id);
    polDistributor($downline->id, $sponsorId);

    expect(fn () => app(MessageService::class)->send($sponsor, $downline, 'hi'))
        ->toThrow(MessageRefused::class);
});

it('POL-14: opening a report writes an audit row naming the actor and the message', function (): void {
    Role::findOrCreate('admin-compliance', 'web');
    $permission = Permission::findOrCreate('messaging.moderate', 'web');
    Role::findByName('admin-compliance', 'web')->givePermissionTo($permission);

    $sponsor = polUser('sponsor');
    $downline = polUser('downline');
    $sponsorId = polDistributor($sponsor->id);
    polDistributor($downline->id, $sponsorId);

    $message = app(MessageService::class)->send($sponsor, $downline, 'You will earn a lot');

    $report = MessageReport::create([
        'message_id' => $message->id,
        'reported_by_user_id' => $downline->id,
        'category' => 'income_claim',
        'reason' => 'Promised earnings',
        'status' => MessageReport::STATUS_OPEN,
    ]);

    $moderator = polUser('moderator');
    $moderator->assignRole('admin-compliance');

    $this->actingAs($moderator)
        ->get(route('admin.messaging.reports.show', ['report' => $report->id]))
        ->assertOk();

    // This audit row is the single control that makes staff reading a private
    // conversation defensible under DPDP §4/§8(7). If it stops being written,
    // the queue stops being defensible — hence a test of its own.
    $audit = AuditLog::where('action', 'messaging.report_opened')
        ->where('subject_id', $report->id)
        ->first();

    expect($audit)->not->toBeNull()
        ->and((int) $audit->actor_id)->toBe((int) $moderator->id)
        ->and((int) ($audit->details['message_id'] ?? 0))->toBe((int) $message->id);
});

it('POL-15: a Verhoeff-valid Aadhaar is refused; a random 12-digit string is not', function (): void {
    $sponsor = polUser('sponsor');
    $downline = polUser('downline');
    $sponsorId = polDistributor($sponsor->id);
    polDistributor($downline->id, $sponsorId);

    // 223456789018 carries a valid Verhoeff check digit; 223456789011 does not.
    // Without the check digit the guard would refuse every order number and
    // every phone-and-extension a member ever pastes.
    expect(fn () => app(MessageService::class)->send($sponsor, $downline, 'Aadhaar 2234 5678 9018 attached'))
        ->toThrow(MessageRefused::class);

    $ok = app(MessageService::class)->send($sponsor, $downline, 'Reference 223456789011 for the courier');

    expect($ok->exists)->toBeTrue()
        ->and(Message::count())->toBe(1);
});
