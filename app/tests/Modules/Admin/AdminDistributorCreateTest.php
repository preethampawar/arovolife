<?php

declare(strict_types=1);

use App\Modules\Compliance\Models\AuditLog;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Notifications\SpouseActivationNotification;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

/**
 * ADC-01 .. ADC-04 — admin-driven distributor creation. The admin enters
 * everything the prospect handed them on paper; the action wires it
 * through PlacementEngine, opens cooling-off, attests orientation +
 * consent on the prospect's behalf, and emails a magic-link activation
 * URL so the prospect can set their own password.
 */
function adcSeedSponsor(): int
{
    $userId = DB::table('users')->insertGetId([
        'email' => 'sponsor-'.uniqid().'@test.com',
        'phone_e164' => '+91'.str_pad((string) rand(7000000000, 9999999999), 10, '0'),
        'password_hash' => bcrypt('x'),
        'password_set_at' => now(),
        'status' => 'active',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    disableTestForeignKeys();
    try {
        $id = DB::table('distributors')->insertGetId([
            'user_id' => $userId,
            'adn' => (string) rand(100000000, 999999999),
            'pan_hash' => random_bytes(32),
            'pan_last4' => '0000',
            'aadhaar_last4' => '0000',
            'bank_account_enc' => random_bytes(32),
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

    return $id;
}

function adcAdmin(): User
{
    Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
    $admin = User::create([
        'email' => 'admin-'.rand(10000, 99999).'@test.com',
        'phone_e164' => '+91'.str_pad((string) rand(7000000000, 9999999999), 10, '0'),
        'password_hash' => bcrypt('x'),
        'password_set_at' => now(),
        'status' => 'active',
    ]);
    $admin->assignRole('admin');

    return $admin;
}

/** Build a baseline valid payload for the admin create form. */
function adcValidPayload(string $sponsorAdn, ?string $placementAdn = null): array
{
    $rand = (string) rand(1000, 9999);

    return [
        'sponsor_adn' => $sponsorAdn,
        'placement_adn' => $placementAdn ?? $sponsorAdn,
        'side' => null,
        'full_name' => 'Test Prospect '.$rand,
        'email' => 'prospect-'.$rand.'@test.com',
        // 10-digit Indian mobile; controller normalises to +91 prefix.
        'phone_e164' => '9'.str_pad((string) rand(100000000, 999999999), 9, '0'),
        'date_of_birth' => '1985-06-15',
        'pan_number' => 'ABCDE'.str_pad((string) rand(1, 9999), 4, '0', STR_PAD_LEFT).'F',
        'aadhaar_number' => str_pad((string) rand(100000000000, 999999999999), 12, '0'),
        'bank_account' => '123456789012',
        'bank_ifsc' => 'HDFC0001234',
        'state' => 'KA',
    ];
}

it('ADC-01: admin creates a valid distributor; ADN allocated; closure rows written; audit logged', function (): void {
    Notification::fake();

    $sponsorId = adcSeedSponsor();
    $sponsorAdn = (string) DB::table('distributors')->where('id', $sponsorId)->value('adn');
    $admin = adcAdmin();

    $payload = adcValidPayload($sponsorAdn);

    $response = $this->actingAs($admin)
        ->withoutMiddleware(PreventRequestForgery::class)
        ->post(route('admin.distributors.store'), $payload);
    $response->assertRedirect();
    $response->assertSessionMissing('errors');

    $newRow = DB::table('distributors')->where('id', '!=', $sponsorId)->first();
    expect($newRow)->not->toBeNull()
        ->and($newRow->adn)->not->toBeEmpty()
        ->and((int) $newRow->sponsor_id)->toBe($sponsorId)
        ->and((int) $newRow->placement_parent_id)->toBe($sponsorId)
        ->and($newRow->placement_side)->toBeIn(['L', 'R']);

    // Closure rows: self (depth 0) + parent edge (depth 1)
    $closureCount = DB::table('genealogy_closure')
        ->where('descendant_id', $newRow->id)->count();
    expect($closureCount)->toBe(2);

    // Audit row
    $audit = AuditLog::where('action', 'admin.distributor.created')
        ->where('subject_id', $newRow->id)->first();
    expect($audit)->not->toBeNull()
        ->and($audit->actor_id)->toBe($admin->id)
        ->and($audit->details['admin_attested_orientation'] ?? null)->toBeTrue()
        ->and($audit->details['admin_attested_consent'] ?? null)->toBeTrue()
        ->and($audit->details['sponsor_adn'] ?? null)->toBe($sponsorAdn);

    // Cooling-off + orientation + consent rows all present
    expect(DB::table('cooling_off_events')->where('distributor_id', $newRow->id)->count())->toBe(1)
        ->and(DB::table('orientation_views')->where('distributor_id', $newRow->id)->count())->toBe(1)
        ->and(DB::table('consents')->where('distributor_id', $newRow->id)->count())->toBe(4);
});

it('ADC-02: cross-line placement (placement not in sponsor downline) rejected', function (): void {
    $sponsor1 = adcSeedSponsor();
    $sponsor2 = adcSeedSponsor();
    $sponsor1Adn = (string) DB::table('distributors')->where('id', $sponsor1)->value('adn');
    $sponsor2Adn = (string) DB::table('distributors')->where('id', $sponsor2)->value('adn');

    $admin = adcAdmin();

    // Sponsor1 is the sponsor; placement targets sponsor2 — a separate
    // root. PlacementEngine's cross-line guard MUST reject this.
    $payload = adcValidPayload($sponsor1Adn, $sponsor2Adn);

    $response = $this->actingAs($admin)
        ->withoutMiddleware(PreventRequestForgery::class)
        ->post(route('admin.distributors.store'), $payload);

    $response->assertSessionHasErrors(['placement_adn']);

    // No new distributor row created.
    $count = DB::table('distributors')->count();
    expect($count)->toBe(2); // just the two sponsors
});

it('ADC-03: duplicate PAN rejected via dedup guard', function (): void {
    Notification::fake();

    $sponsorId = adcSeedSponsor();
    $sponsorAdn = (string) DB::table('distributors')->where('id', $sponsorId)->value('adn');
    $admin = adcAdmin();

    // First registration succeeds.
    $payload1 = adcValidPayload($sponsorAdn);
    $this->actingAs($admin)
        ->withoutMiddleware(PreventRequestForgery::class)
        ->post(route('admin.distributors.store'), $payload1)
        ->assertRedirect();

    $countAfterFirst = DB::table('distributors')->count();

    // Second registration with the SAME PAN must be rejected. Use a
    // distinct email + phone + Aadhaar so PAN is the only collision.
    $payload2 = adcValidPayload($sponsorAdn);
    $payload2['pan_number'] = $payload1['pan_number'];

    $response = $this->actingAs($admin)
        ->withoutMiddleware(PreventRequestForgery::class)
        ->post(route('admin.distributors.store'), $payload2);

    $response->assertSessionHasErrors(['pan_number']);

    // No new row.
    expect(DB::table('distributors')->count())->toBe($countAfterFirst);
});

it('ADC-04: created user has password_set_at=null and receives a spouse-activation magic link', function (): void {
    Notification::fake();

    $sponsorId = adcSeedSponsor();
    $sponsorAdn = (string) DB::table('distributors')->where('id', $sponsorId)->value('adn');
    $admin = adcAdmin();

    $payload = adcValidPayload($sponsorAdn);
    $this->actingAs($admin)
        ->withoutMiddleware(PreventRequestForgery::class)
        ->post(route('admin.distributors.store'), $payload)
        ->assertRedirect();

    $newUser = User::where('email', $payload['email'])->first();
    expect($newUser)->not->toBeNull()
        ->and($newUser->password_set_at)->toBeNull()
        ->and($newUser->status)->toBe('pending');

    // Magic-link notification dispatched (same flow as spouse activation
    // — gates on password_set_at IS NULL).
    Notification::assertSentTo($newUser, SpouseActivationNotification::class);
});
