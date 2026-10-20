<?php

declare(strict_types=1);

use App\Modules\Compliance\Models\AuditLog;
use App\Modules\Identity\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

/**
 * Developer-only joining-date correction (distributors.effective_date).
 * Cooling-off (hard rule 5) is only ever extended; the tree's joining-date
 * ordering, root accounts and Fortune Bonus months already run are guarded.
 */
beforeEach(function () {
    Carbon::setTestNow('2026-10-21 12:00:00');
});

afterEach(function () {
    Carbon::setTestNow();
});

function ajdStaff(string $role): User
{
    Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']);
    $user = User::create([
        'full_name' => 'Staff '.$role,
        'email' => 'ajd-'.$role.'-'.uniqid().'@example.com',
        'phone_e164' => '+9180000'.rand(10000, 99999),
        'password_hash' => bcrypt('Adm1n!Pass#2026Test'),
        'password_set_at' => now(),
        'status' => 'active',
        'email_verified_at' => now(),
    ]);
    $user->assignRole($role);

    return $user;
}

/** A distributor joined at $joined under $parentId (null = root, its own sponsor/parent). */
function ajdDistributor(string $joined, ?int $parentId = null, array $overrides = []): int
{
    $user = User::create([
        'email' => 'ajd'.uniqid().'@test.com',
        'phone_e164' => '+91'.str_pad((string) rand(7000000000, 9999999999), 10, '0'),
        'password_hash' => bcrypt('x'),
        'status' => 'active',
    ]);

    $at = Carbon::parse($joined);

    disableTestForeignKeys();
    try {
        $id = DB::table('distributors')->insertGetId(array_merge([
            'user_id' => $user->id,
            'adn' => (string) rand(100000000, 999999999),
            'pan_hash' => random_bytes(32),
            'pan_last4' => '0000',
            'bank_account_enc' => 'stub',
            'bank_ifsc' => 'SBIN0000000',
            'sponsor_id' => $parentId ?? 0,
            'placement_parent_id' => $parentId ?? 0,
            'placement_side' => $parentId === null ? null : 'L',
            'side_chosen_by' => 'referral_default',
            'depth' => $parentId === null ? 0 : 1,
            'effective_date' => $at->format('Y-m-d H:i:s.v'),
            'cooling_off_end_at' => $at->copy()->addDays(30)->format('Y-m-d H:i:s.v'),
            'state' => 'TS',
            'is_primary_couple' => 0,
            'created_at' => now()->format('Y-m-d H:i:s.v'),
            'updated_at' => now()->format('Y-m-d H:i:s.v'),
        ], $overrides));
        if ($parentId === null) {
            DB::table('distributors')->where('id', $id)->update(['sponsor_id' => $id, 'placement_parent_id' => $id]);
        }
    } finally {
        enableTestForeignKeys();
    }

    return $id;
}

function ajdRow(int $id): object
{
    return DB::table('distributors')->where('id', $id)->first();
}

it('AJD-01: a developer backdates the joining date; cooling-off is not shortened and the change is audited', function () {
    $root = ajdDistributor('2026-09-01 09:00:00');
    $id = ajdDistributor('2026-10-20 15:30:00', $root);
    $coolingOffBefore = ajdRow($id)->cooling_off_end_at;

    $this->actingAs(ajdStaff('developer'))
        ->post("/admin/distributors/{$id}/joining-date", ['joining_date' => '2026-10-15', 'joining_date_reason' => 'Agreement signed 15 Oct'])
        ->assertSessionHasNoErrors();

    $row = ajdRow($id);
    expect(Carbon::parse($row->effective_date)->format('Y-m-d H:i'))->toBe('2026-10-15 15:30')
        ->and(Carbon::parse($row->cooling_off_end_at)->equalTo(Carbon::parse($coolingOffBefore)))->toBeTrue();

    $audit = AuditLog::where('action', 'admin.distributor.joining_date_changed')->sole();
    expect($audit->subject_id)->toBe($id)
        ->and($audit->details['reason'])->toBe('Agreement signed 15 Oct');
});

it('AJD-02: moving the joining date later extends cooling-off to the new date + 30 days', function () {
    $root = ajdDistributor('2026-09-01 09:00:00');
    $id = ajdDistributor('2026-10-01 10:00:00', $root);

    $this->actingAs(ajdStaff('developer'))
        ->post("/admin/distributors/{$id}/joining-date", ['joining_date' => '2026-10-10', 'joining_date_reason' => 'Wrong date keyed'])
        ->assertSessionHasNoErrors();

    expect(Carbon::parse(ajdRow($id)->cooling_off_end_at)->toDateString())->toBe('2026-11-09');
});

it('AJD-03: the change is refused when it breaks the tree\'s joining-date order', function (string $newDate) {
    $root = ajdDistributor('2026-10-05 09:00:00');
    $id = ajdDistributor('2026-10-10 09:00:00', $root);
    ajdDistributor('2026-10-15 09:00:00', $id);

    $this->actingAs(ajdStaff('developer'))
        ->post("/admin/distributors/{$id}/joining-date", ['joining_date' => $newDate, 'joining_date_reason' => 'x'])
        ->assertSessionHasErrors('joining_date');

    expect(Carbon::parse(ajdRow($id)->effective_date)->toDateString())->toBe('2026-10-10');
})->with(['before the sponsor' => '2026-10-01', 'after a team member' => '2026-10-16']);

it('AJD-04: refused for a root account and for a month Fortune Bonus has already run', function () {
    $root = ajdDistributor('2026-08-01 09:00:00');
    $id = ajdDistributor('2026-10-10 09:00:00', $root);
    $developer = ajdStaff('developer');

    $this->actingAs($developer)
        ->post("/admin/distributors/{$root}/joining-date", ['joining_date' => '2026-07-01', 'joining_date_reason' => 'x'])
        ->assertSessionHasErrors('joining_date');

    DB::table('fortune_bonus_participants')->insert([
        'distributor_id' => $root, 'month_start' => '2026-09-01', 'position' => 1,
        'matrix_level' => 0, 'eligibility_tier' => 'new_joiner',
    ]);

    $this->actingAs($developer)
        ->post("/admin/distributors/{$id}/joining-date", ['joining_date' => '2026-09-20', 'joining_date_reason' => 'x'])
        ->assertSessionHasErrors('joining_date');

    expect(Carbon::parse(ajdRow($root)->effective_date)->toDateString())->toBe('2026-08-01')
        ->and(Carbon::parse(ajdRow($id)->effective_date)->toDateString())->toBe('2026-10-10');
});

it('AJD-05: a couple moves as one, whichever account the developer edits', function () {
    $root = ajdDistributor('2026-09-01 09:00:00');
    $primary = ajdDistributor('2026-10-10 09:00:00', $root, ['is_primary_couple' => 1]);
    $spouse = ajdDistributor('2026-10-10 09:00:00', $primary, ['placement_side' => null, 'spouse_distributor_id' => $primary]);
    DB::table('distributors')->where('id', $primary)->update(['spouse_distributor_id' => $spouse]);

    $this->actingAs(ajdStaff('developer'))
        ->post("/admin/distributors/{$spouse}/joining-date", ['joining_date' => '2026-10-05', 'joining_date_reason' => 'x'])
        ->assertSessionHasNoErrors();

    expect(Carbon::parse(ajdRow($primary)->effective_date)->toDateString())->toBe('2026-10-05')
        ->and(Carbon::parse(ajdRow($spouse)->effective_date)->toDateString())->toBe('2026-10-05');
});

it('AJD-06: only the developer role sees and can use it; a future date is refused', function () {
    $root = ajdDistributor('2026-09-01 09:00:00');
    $id = ajdDistributor('2026-10-10 09:00:00', $root);
    $admin = ajdStaff('admin');
    $developer = ajdStaff('developer');

    $this->actingAs($admin)->get("/admin/distributors/{$id}/edit")
        ->assertOk()->assertDontSee('Update joining date');
    $this->actingAs($admin)
        ->post("/admin/distributors/{$id}/joining-date", ['joining_date' => '2026-10-05', 'joining_date_reason' => 'x'])
        ->assertForbidden();

    $this->actingAs($developer)->get("/admin/distributors/{$id}/edit")
        ->assertOk()->assertSee('Update joining date');
    $this->actingAs($developer)
        ->post("/admin/distributors/{$id}/joining-date", ['joining_date' => '2026-10-22', 'joining_date_reason' => 'x'])
        ->assertSessionHasErrors('joining_date');

    expect(Carbon::parse(ajdRow($id)->effective_date)->toDateString())->toBe('2026-10-10');
});

it('AJD-07: extending cooling-off clears the D-7 / D-1 reminder flags so they fire for the new end date', function () {
    $root = ajdDistributor('2026-09-01 09:00:00');
    $id = ajdDistributor('2026-10-01 10:00:00', $root);
    DB::table('cooling_off_events')->insert([
        'distributor_id' => $id, 'opened_at' => '2026-10-01 10:00:00',
        'reminder_d7_sent_at' => '2026-10-24 08:00:00', 'reminder_d1_sent_at' => '2026-10-30 08:00:00',
    ]);

    $this->actingAs(ajdStaff('developer'))
        ->post("/admin/distributors/{$id}/joining-date", ['joining_date' => '2026-10-10', 'joining_date_reason' => 'Wrong date keyed'])
        ->assertSessionHasNoErrors();

    $event = DB::table('cooling_off_events')->where('distributor_id', $id)->first();
    expect($event->reminder_d7_sent_at)->toBeNull()
        ->and($event->reminder_d1_sent_at)->toBeNull();
});

it('AJD-08: refused before the terms acceptance or orientation pass', function (string $table) {
    $root = ajdDistributor('2026-09-01 09:00:00');
    $id = ajdDistributor('2026-10-20 10:00:00', $root);

    if ($table === 'consents') {
        DB::table('consents')->insert([
            'distributor_id' => $id, 'document_type' => 'tnc', 'document_version' => '1.0',
            'doc_hash_sha256' => random_bytes(32), 'accepted_at' => '2026-10-18 09:00:00',
            'ip' => '127.0.0.1', 'user_agent' => 'test',
        ]);
    } else {
        DB::table('orientation_views')->insert([
            'distributor_id' => $id, 'video_id' => 'v1', 'started_at' => '2026-10-18 08:00:00',
            'watch_percent' => 100, 'quiz_passed_at' => '2026-10-18 09:00:00',
        ]);
    }

    $developer = ajdStaff('developer');

    $this->actingAs($developer)
        ->post("/admin/distributors/{$id}/joining-date", ['joining_date' => '2026-10-15', 'joining_date_reason' => 'x'])
        ->assertSessionHasErrors('joining_date');
    expect(Carbon::parse(ajdRow($id)->effective_date)->toDateString())->toBe('2026-10-20');

    $this->actingAs($developer)
        ->post("/admin/distributors/{$id}/joining-date", ['joining_date' => '2026-10-18', 'joining_date_reason' => 'x'])
        ->assertSessionHasNoErrors();
    expect(Carbon::parse(ajdRow($id)->effective_date)->toDateString())->toBe('2026-10-18');
})->with(['consents', 'orientation_views']);
