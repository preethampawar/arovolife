<?php

declare(strict_types=1);

use App\Modules\Compliance\Models\AuditLog;
use App\Modules\Identity\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

/**
 * Staff-account management: creating back-office logins, changing their
 * roles, and deactivating them. Every path is audit-logged and no path may
 * ever put a password anywhere but users.password_hash.
 */
beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
    app(PermissionRegistrar::class)->forgetCachedPermissions();
});

function staffActor(string $role = 'admin'): User
{
    $user = User::create([
        'full_name' => 'Actor '.$role,
        'email' => 'actor-'.uniqid().'@arovolife.test',
        'phone_e164' => '+9198'.rand(10000000, 99999999),
        'password_hash' => bcrypt('ActorPassword123'),
        'password_set_at' => now(),
        'status' => 'active',
        'email_verified_at' => now(),
    ]);
    $user->assignRole($role);

    return $user;
}

/** @return array<string, string> */
function staffPayload(array $overrides = []): array
{
    return array_merge([
        'full_name' => 'New Operations Person',
        'email' => 'ops-'.uniqid().'@arovolife.test',
        'phone_e164' => '+919'.rand(100000000, 999999999),
        'password' => 'SufficientlyLong123',
        'password_confirmation' => 'SufficientlyLong123',
        'roles' => ['admin-operations'],
    ], $overrides);
}

it('STAFF-01: creates a staff account with a hashed password and an audit row', function () {
    $actor = staffActor();
    $payload = staffPayload();

    $this->actingAs($actor)
        ->withoutMiddleware(PreventRequestForgery::class)
        ->post(route('admin.staff.store'), $payload)
        ->assertRedirect(route('admin.staff.index'));

    $created = User::where('email', $payload['email'])->firstOrFail();

    expect($created->hasRole('admin-operations'))->toBeTrue()
        ->and($created->status)->toBe('active')
        // Without password_set_at the login controller refuses the account.
        ->and($created->password_set_at)->not->toBeNull()
        ->and(Hash::check($payload['password'], $created->password_hash))->toBeTrue()
        // The plaintext must not have been stored anywhere readable.
        ->and($created->password_hash)->not->toBe($payload['password']);

    $audit = AuditLog::where('action', 'staff.user.created')->firstOrFail();
    expect($audit->actor_id)->toBe($actor->id)
        ->and($audit->subject_id)->toBe($created->id)
        ->and(json_encode($audit->details))->not->toContain($payload['password']);
});

it('STAFF-02: the new staff member can sign in with their email', function () {
    // Scoped roles have no ADN, so email is their only sign-in channel.
    $payload = staffPayload(['roles' => ['admin-operations']]);

    $this->actingAs(staffActor())
        ->withoutMiddleware(PreventRequestForgery::class)
        ->post(route('admin.staff.store'), $payload);

    // Drop the creating admin's session before signing in as the new account.
    $this->app['auth']->forgetGuards();
    $this->flushSession();

    $this->withoutMiddleware(PreventRequestForgery::class)
        ->post('/login', ['login' => $payload['email'], 'password' => $payload['password']])
        ->assertRedirect(route('admin.dashboard'));
});

it('STAFF-03: rejects an email that already belongs to a distributor', function () {
    $distributorUser = User::create([
        'full_name' => 'Existing Distributor',
        'email' => 'distributor@arovolife.test',
        'phone_e164' => '+919812300001',
        'password_hash' => bcrypt('x'),
        'password_set_at' => now(),
        'status' => 'active',
    ]);

    $this->actingAs(staffActor())
        ->withoutMiddleware(PreventRequestForgery::class)
        ->post(route('admin.staff.store'), staffPayload(['email' => $distributorUser->email]))
        // Unique-email validation catches it first; either way no staff role
        // is granted to an account that already exists.
        ->assertSessionHasErrors('email');

    expect($distributorUser->fresh()->roles)->toBeEmpty();
});

it('STAFF-04: rejects a weak password and a malformed phone', function () {
    $this->actingAs(staffActor())
        ->withoutMiddleware(PreventRequestForgery::class)
        ->post(route('admin.staff.store'), staffPayload([
            'password' => 'short',
            'password_confirmation' => 'short',
            'phone_e164' => '9876543210',
        ]))
        ->assertSessionHasErrors(['password', 'phone_e164']);
});

it('STAFF-05: replaces roles and records the before/after in the audit log', function () {
    $actor = staffActor();
    $target = staffActor('admin-finance');

    $this->actingAs($actor)
        ->withoutMiddleware(PreventRequestForgery::class)
        ->post(route('admin.staff.roles', $target->id), ['roles' => ['admin-compliance']])
        ->assertRedirect(route('admin.staff.index'));

    $target->refresh();
    expect($target->hasRole('admin-compliance'))->toBeTrue()
        ->and($target->hasRole('admin-finance'))->toBeFalse();

    $audit = AuditLog::where('action', 'staff.roles.changed')->firstOrFail();
    expect($audit->details['from'])->toBe(['admin-finance'])
        ->and($audit->details['to'])->toBe(['admin-compliance']);
});

it('STAFF-06: deactivates and reactivates a staff login', function () {
    $target = staffActor('admin-operations');

    $this->actingAs(staffActor())
        ->withoutMiddleware(PreventRequestForgery::class)
        ->post(route('admin.staff.status', $target->id), ['status' => 'frozen'])
        ->assertRedirect(route('admin.staff.index'));

    expect($target->fresh()->status)->toBe('frozen');

    $this->withoutMiddleware(PreventRequestForgery::class)
        ->post(route('admin.staff.status', $target->id), ['status' => 'active'])
        ->assertRedirect(route('admin.staff.index'));

    expect($target->fresh()->status)->toBe('active');
    expect(AuditLog::where('action', 'staff.status.changed')->count())->toBe(2);
});

it('STAFF-07: a staff member cannot change their own roles or status', function () {
    $actor = staffActor();

    $this->actingAs($actor)
        ->withoutMiddleware(PreventRequestForgery::class)
        ->post(route('admin.staff.roles', $actor->id), ['roles' => ['admin-finance']])
        ->assertSessionHasErrors('roles');

    $this->withoutMiddleware(PreventRequestForgery::class)
        ->post(route('admin.staff.status', $actor->id), ['status' => 'frozen'])
        ->assertSessionHasErrors('status');

    expect($actor->fresh()->hasRole('admin'))->toBeTrue()
        ->and($actor->fresh()->status)->toBe('active');
});

it('STAFF-08: a scoped admin role cannot reach staff management at all', function (string $role) {
    $this->actingAs(staffActor($role));

    $this->get(route('admin.staff.index'))->assertForbidden();
    $this->get(route('admin.staff.create'))->assertForbidden();
})->with(['admin-operations', 'admin-finance', 'admin-compliance']);

it('STAFF-09: the console command provisions an account without touching config', function () {
    $this->artisan('staff:create', ['email' => 'console-dev@arovolife.test', '--role' => 'developer'])
        ->expectsQuestion('Full name', 'Console Developer')
        ->expectsQuestion('Mobile number (E.164)', '+919876500001')
        ->expectsQuestion('Password (min 12 characters)', 'ConsolePassword123')
        ->expectsQuestion('Confirm password', 'ConsolePassword123')
        ->assertSuccessful();

    $user = User::where('email', 'console-dev@arovolife.test')->firstOrFail();

    expect($user->hasRole('developer'))->toBeTrue()
        ->and($user->status)->toBe('active')
        ->and(Hash::check('ConsolePassword123', $user->password_hash))->toBeTrue();

    // The secret must exist only as the hash on the user row.
    expect(DB::table('settings')->where('value', 'like', '%ConsolePassword123%')->exists())->toBeFalse();
    expect(json_encode(AuditLog::where('action', 'staff.user.created')->first()->details))
        ->not->toContain('ConsolePassword123');
});

it('STAFF-10: the console command refuses an unknown role', function () {
    $this->artisan('staff:create', ['email' => 'nope@arovolife.test', '--role' => 'wizard'])
        ->assertFailed();

    expect(User::where('email', 'nope@arovolife.test')->exists())->toBeFalse();
});
