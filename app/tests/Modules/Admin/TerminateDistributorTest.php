<?php

declare(strict_types=1);

use App\Modules\Admin\Services\TerminateDistributor;
use App\Modules\Compliance\Models\AuditLog;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Notifications\AccountTerminatedNotification;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

/**
 * Both terminal admin paths must:
 *  - flip users.status to 'terminated' with closure_type='admin_termination'
 *    (so the UI labels it "Terminated", distinct from a cooling-off
 *    "Cancelled (cooling-off)");
 *  - flip distributors.status to 'inactive' (no contradictory
 *    "Distributor: Active" pill on a closed account);
 *  - emit the termination email.
 *
 * Two paths exist: TerminateDistributor (service, used by the KYC terminate)
 * and AdminDistributorController::terminate (the distributor-show button,
 * which previously sent NO email).
 */
function tdSeedDistributor(): array
{
    $user = User::create([
        'email' => 'd'.rand(10000, 99999).'@test.com',
        'phone_e164' => '+91'.str_pad((string) rand(7000000000, 9999999999), 10, '0'),
        'password_hash' => bcrypt('x'),
        'full_name' => 'Test Distributor',
        'status' => 'active',
    ]);

    disableTestForeignKeys();
    try {
        $id = DB::table('distributors')->insertGetId([
            'user_id' => $user->id,
            'adn' => 'ARO'.rand(100000, 999999),
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
            'cooling_off_end_at' => now()->addDays(30)->format('Y-m-d H:i:s.v'),
            'state' => 'TS',
            'is_primary_couple' => 0,
            'status' => 'active',
            'created_at' => now()->format('Y-m-d H:i:s.v'),
            'updated_at' => now()->format('Y-m-d H:i:s.v'),
        ]);
        DB::table('distributors')->where('id', $id)->update([
            'sponsor_id' => $id, 'placement_parent_id' => $id,
        ]);
    } finally {
        enableTestForeignKeys();
    }

    DB::table('genealogy_closure')->insert([
        'ancestor_id' => $id, 'descendant_id' => $id, 'depth' => 0,
    ]);

    return ['user' => $user, 'distributor_id' => $id];
}

function tdAdmin(): User
{
    Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
    $admin = User::create([
        'email' => 'admin-'.rand(10000, 99999).'@test.com',
        'phone_e164' => '+91'.str_pad((string) rand(7000000000, 9999999999), 10, '0'),
        'password_hash' => bcrypt('x'),
        'status' => 'active',
    ]);
    $admin->assignRole('admin');

    return $admin;
}

it('TD-01: TerminateDistributor service sets closure_type, distributors.status=inactive, dispatches event', function (): void {
    Notification::fake();

    ['user' => $user, 'distributor_id' => $id] = tdSeedDistributor();
    $admin = tdAdmin();

    app(TerminateDistributor::class)($id, $admin->id, 'Confirmed fraud.');

    $user->refresh();
    expect($user->status)->toBe('terminated')
        ->and($user->closure_type)->toBe('admin_termination')
        ->and(DB::table('distributors')->where('id', $id)->value('status'))->toBe('inactive');

    Notification::assertSentTo($user, AccountTerminatedNotification::class);
});

it('TD-02: AdminDistributorController::terminate route sets closure_type, inactive, AND emails (was previously silent)', function (): void {
    Notification::fake();

    ['user' => $user, 'distributor_id' => $id] = tdSeedDistributor();
    $admin = tdAdmin();

    $this->actingAs($admin)
        ->withoutMiddleware(PreventRequestForgery::class)
        ->post(route('admin.distributors.terminate', $id), [
            'reason' => 'Repeat KYC rejections.',
        ])
        ->assertRedirect(route('admin.distributors.show', $id));

    $user->refresh();
    expect($user->status)->toBe('terminated')
        ->and($user->closure_type)->toBe('admin_termination')
        ->and(DB::table('distributors')->where('id', $id)->value('status'))->toBe('inactive');

    $audit = AuditLog::where('action', 'admin.distributor.terminated')
        ->where('subject_id', $id)->first();
    expect($audit)->not->toBeNull()
        ->and($audit->details['reason'] ?? null)->toBe('Repeat KYC rejections.');

    Notification::assertSentTo($user, AccountTerminatedNotification::class);
});
