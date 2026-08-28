<?php

declare(strict_types=1);

use App\Modules\Commerce\Models\Franchise;
use App\Modules\Identity\Models\User;
use App\Modules\Shared\Features\FranchiseFeature;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Pennant\Feature;

uses(RefreshDatabase::class);

/**
 * Seed a minimal active distributor user for franchise tests.
 *
 * @return array{User, int}
 */
function faUserWithDistributor(): array
{
    $user = User::create([
        'full_name' => 'FA Test User',
        'email' => 'fa-'.uniqid().'@test.com',
        'phone_e164' => '+91'.str_pad((string) random_int(7000000000, 9999999999), 10, '0'),
        'password_hash' => bcrypt('x'),
        'status' => 'active',
    ]);

    disableTestForeignKeys();
    try {
        $distId = DB::table('distributors')->insertGetId([
            'user_id' => $user->id,
            'adn' => 'ADN'.random_int(100000000, 999999999),
            'pan_hash' => random_bytes(32),
            'pan_last4' => '0000',
            'bank_account_enc' => 'stub',
            'bank_ifsc' => 'SBIN0000000',
            'sponsor_id' => 0,
            'placement_parent_id' => 0,
            'side_chosen_by' => 'referral_default',
            'depth' => 0,
            'effective_date' => now()->format('Y-m-d H:i:s.v'),
            'cooling_off_end_at' => now()->addDays(30)->format('Y-m-d H:i:s.v'),
            'state' => 'TS',
            'is_primary_couple' => 0,
            'created_at' => now()->format('Y-m-d H:i:s.v'),
            'updated_at' => now()->format('Y-m-d H:i:s.v'),
        ]);
        DB::table('distributors')->where('id', $distId)->update([
            'sponsor_id' => $distId,
            'placement_parent_id' => $distId,
        ]);
    } finally {
        enableTestForeignKeys();
    }

    return [$user->fresh(), $distId];
}

// ── Test 1: guest redirect ────────────────────────────────────────────────────

it('FA-01: guest accessing /my/franchise/apply is redirected to login', function (): void {
    Feature::for(null)->activate(FranchiseFeature::class);

    $this->get('/my/franchise/apply')
        ->assertRedirect(route('login'));
});

// ── Test 2: flag OFF → 403 ────────────────────────────────────────────────────

it('FA-02: flag OFF — authenticated distributor accessing /my/franchise/apply gets 403', function (): void {
    // FranchiseFeature resolves false by default — no explicit deactivate needed,
    // but we deactivate explicitly to be unambiguous.
    Feature::for(null)->deactivate(FranchiseFeature::class);

    [$user] = faUserWithDistributor();
    $this->actingAs($user)
        ->get('/my/franchise/apply')
        ->assertStatus(403);
});

// ── Test 3: flag ON → apply form ─────────────────────────────────────────────

it('FA-03: flag ON — authenticated distributor sees the apply form (200)', function (): void {
    Feature::for(null)->activate(FranchiseFeature::class);

    [$user] = faUserWithDistributor();
    $this->actingAs($user)
        ->get('/my/franchise/apply')
        ->assertStatus(200)
        ->assertViewIs('my.franchise.apply');
});

// ── Test 4: valid POST creates a Franchise row ────────────────────────────────

it('FA-04: flag ON — valid POST creates Franchise with status=pending_approval and applied_at set', function (): void {
    Feature::for(null)->activate(FranchiseFeature::class);

    [$user, $distId] = faUserWithDistributor();

    $this->actingAs($user)->post('/my/franchise/apply', [
        '_token' => csrf_token(),
        'address_line' => '12 Main Street, Banjara Hills',
        'pincode' => '500034',
        'district' => 'Hyderabad',
        'state' => 'Telangana',
        'arete_center_id' => null,
        'notes' => 'Motivated to grow the brand.',
    ])->assertRedirect(route('franchise.status'));

    $franchise = Franchise::where('operator_distributor_id', $distId)->first();

    expect($franchise)->not->toBeNull()
        ->and($franchise->status)->toBe(Franchise::STATUS_PENDING)
        ->and($franchise->applied_at)->not->toBeNull()
        ->and($franchise->code)->toStartWith('ARV-FR-')
        ->and($franchise->address_line)->toBe('12 Main Street, Banjara Hills')
        ->and($franchise->pincode)->toBe('500034')
        ->and($franchise->district)->toBe('Hyderabad')
        ->and($franchise->state)->toBe('Telangana');
});

// ── Test 5: duplicate application → 422 ──────────────────────────────────────

it('FA-05: flag ON — duplicate application (pending exists) returns 422', function (): void {
    Feature::for(null)->activate(FranchiseFeature::class);

    [$user, $distId] = faUserWithDistributor();

    // Seed an existing pending franchise for this distributor.
    Franchise::create([
        'operator_distributor_id' => $distId,
        'name' => 'Existing Franchise',
        'status' => Franchise::STATUS_PENDING,
        'code' => 'ARV-FR-000001',
        'address_line' => 'Existing address',
        'pincode' => '500001',
        'district' => 'Hyderabad',
        'state' => 'Telangana',
        'applied_at' => now(),
    ]);

    $this->actingAs($user)->post('/my/franchise/apply', [
        '_token' => csrf_token(),
        'address_line' => '99 New Street',
        'pincode' => '500099',
        'district' => 'Hyderabad',
        'state' => 'Telangana',
    ])->assertStatus(422);
});

// ── Test 6: status page — no application ─────────────────────────────────────

it('FA-06: status page with no application returns 200 with "not yet applied" copy', function (): void {
    Feature::for(null)->activate(FranchiseFeature::class);

    [$user] = faUserWithDistributor();
    $response = $this->actingAs($user)
        ->get('/my/franchise/status')
        ->assertStatus(200)
        ->assertViewIs('my.franchise.status');

    $response->assertSee('not yet applied', false);
});

// ── Test 7: status page — pending application ─────────────────────────────────

it('FA-07: status page with pending application returns 200 with "under review" copy', function (): void {
    Feature::for(null)->activate(FranchiseFeature::class);

    [$user, $distId] = faUserWithDistributor();

    Franchise::create([
        'operator_distributor_id' => $distId,
        'name' => 'Existing Franchise',
        'status' => Franchise::STATUS_PENDING,
        'code' => 'ARV-FR-000002',
        'address_line' => 'Some Street',
        'pincode' => '500002',
        'district' => 'Hyderabad',
        'state' => 'Telangana',
        'applied_at' => now(),
    ]);

    $response = $this->actingAs($user)
        ->get('/my/franchise/status')
        ->assertStatus(200)
        ->assertViewIs('my.franchise.status');

    $response->assertSee('under review', false);
});
