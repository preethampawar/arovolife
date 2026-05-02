<?php

declare(strict_types=1);

/**
 * Registration wizard entry-point tests (ADR-0003 referral-link gating).
 *
 * All five cases exercise GET /register. The controller (start()) is a
 * read-only redirect handler so CSRF is not involved. No wizard session
 * state needs to pre-exist for these entry tests.
 *
 * REG-001: direct visit (no query params) → 302 to /contact-us?reason=referral_link_required
 * REG-002: malformed / non-existent ADNs → 302 to /contact-us?reason=invalid_referral_link
 * REG-003: placement ADN not in sponsor's downline → invalid_referral_link
 * REG-004: slot B.L is already taken → invalid_referral_link
 * REG-005: happy path (valid sponsor, placement in downline, open slot) → 302 to /register/account + intent stashed
 */

use App\Modules\Identity\Services\WizardStateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

// ─── helpers ────────────────────────────────────────────────────────────────

/**
 * Insert a minimal distributor row that self-references (root) and seed the
 * closure table. Returns both the distributor id AND its ADN.
 *
 * @return array{id: int, adn: string}
 */
function regSeedRoot(?int $userId = null): array
{
    $userId = $userId ?? regSeedUser();

    DB::statement('SET FOREIGN_KEY_CHECKS=0');
    try {
        $adn = 'REG'.str_pad((string) rand(1, 999999), 6, '0', STR_PAD_LEFT);

        $id = DB::table('distributors')->insertGetId([
            'user_id' => $userId,
            'adn' => $adn,
            'pan_hash' => random_bytes(32),
            'pan_last4' => '0000',
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
            'sponsor_id' => $id,
            'placement_parent_id' => $id,
        ]);
    } finally {
        DB::statement('SET FOREIGN_KEY_CHECKS=1');
    }

    DB::table('genealogy_closure')->insert([
        'ancestor_id' => $id,
        'descendant_id' => $id,
        'depth' => 0,
    ]);

    return ['id' => $id, 'adn' => $adn];
}

function regSeedUser(): int
{
    return DB::table('users')->insertGetId([
        'email' => 'reg'.uniqid().'@test.com',
        'phone_e164' => '+919'.rand(100000000, 999999999),
        'password_hash' => bcrypt('password'),
        'password_set_at' => now(),
        'status' => 'active',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

/**
 * Place a distributor under $parentId on $side without going through the
 * engine (avoids cross-test coupling); updates the closure table manually.
 *
 * @return array{id: int, adn: string}
 */
function regPlaceUnder(int $sponsorId, int $parentId, string $side, int $parentDepth): array
{
    $adn = 'C'.str_pad((string) rand(1, 999999), 7, '0', STR_PAD_LEFT);
    $userId = regSeedUser();

    DB::statement('SET FOREIGN_KEY_CHECKS=0');
    try {
        $id = DB::table('distributors')->insertGetId([
            'user_id' => $userId,
            'adn' => $adn,
            'pan_hash' => random_bytes(32),
            'pan_last4' => '1111',
            'bank_account_enc' => random_bytes(32),
            'bank_ifsc' => 'SBIN0000000',
            'sponsor_id' => $sponsorId,
            'placement_parent_id' => $parentId,
            'placement_id_at_registration' => $parentId,
            'placement_side' => $side,
            'side_chosen_by' => 'referral_explicit',
            'depth' => $parentDepth + 1,
            'effective_date' => now()->format('Y-m-d H:i:s.v'),
            'cooling_off_end_at' => now()->addDays(30)->format('Y-m-d H:i:s.v'),
            'state' => 'TS',
            'is_primary_couple' => 0,
            'created_at' => now()->format('Y-m-d H:i:s.v'),
            'updated_at' => now()->format('Y-m-d H:i:s.v'),
        ]);
    } finally {
        DB::statement('SET FOREIGN_KEY_CHECKS=1');
    }

    // Self-row
    DB::table('genealogy_closure')->insert([
        'ancestor_id' => $id,
        'descendant_id' => $id,
        'depth' => 0,
    ]);

    // Inherit parent's ancestors
    $parentAncestors = DB::table('genealogy_closure')
        ->where('descendant_id', $parentId)
        ->get();

    foreach ($parentAncestors as $row) {
        DB::table('genealogy_closure')->insert([
            'ancestor_id' => $row->ancestor_id,
            'descendant_id' => $id,
            'depth' => $row->depth + 1,
        ]);
    }

    return ['id' => $id, 'adn' => $adn];
}

// ─── REG-001 ─────────────────────────────────────────────────────────────────

it('REG-001: GET /register without any query params redirects to /contact-us?reason=referral_link_required', function () {
    $response = $this->get('/register');

    $response->assertRedirect('/contact-us?reason=referral_link_required');
});

it('REG-001b: GET /register with only sponsor param (no placement) redirects to referral_link_required', function () {
    $sponsor = regSeedRoot();

    $response = $this->get('/register?sponsor='.$sponsor['adn']);

    $response->assertRedirect('/contact-us?reason=referral_link_required');
});

// ─── REG-002 ─────────────────────────────────────────────────────────────────

it('REG-002: GET /register with non-existent sponsor ADN redirects to invalid_referral_link', function () {
    $response = $this->get('/register?sponsor=BADADN999&placement=BADADN888');

    $response->assertRedirect('/contact-us?reason=invalid_referral_link');
});

it('REG-002b: ADN that fails the format regex (injection attempt) redirects to invalid_referral_link', function () {
    // < 6 characters — fails /^[A-Z0-9-]{6,18}$/
    $response = $this->get('/register?sponsor=AB&placement=CD');

    $response->assertRedirect('/contact-us?reason=invalid_referral_link');
});

it('REG-002c: side param that is neither L nor R redirects to invalid_referral_link', function () {
    $sponsor = regSeedRoot();

    $response = $this->get('/register?sponsor='.$sponsor['adn'].'&placement='.$sponsor['adn'].'&side=X');

    $response->assertRedirect('/contact-us?reason=invalid_referral_link');
});

it('REG-002d: existing sponsor ADN but non-existent placement ADN redirects to invalid_referral_link', function () {
    $sponsor = regSeedRoot();

    $response = $this->get('/register?sponsor='.$sponsor['adn'].'&placement=NONEXIST99');

    $response->assertRedirect('/contact-us?reason=invalid_referral_link');
});

// ─── REG-003 ─────────────────────────────────────────────────────────────────

it('REG-003: GET /register where placement ADN is not in sponsor downline redirects to invalid_referral_link', function () {
    // Two unrelated root distributors
    $sponsorA = regSeedRoot();
    $sponsorB = regSeedRoot();

    // SponsorA tries to place under sponsorB — cross-line
    $response = $this->get('/register?sponsor='.$sponsorA['adn'].'&placement='.$sponsorB['adn']);

    $response->assertRedirect('/contact-us?reason=invalid_referral_link');
});

// ─── REG-004 ─────────────────────────────────────────────────────────────────

it('REG-004: GET /register where specified side is already taken redirects to invalid_referral_link', function () {
    $sponsor = regSeedRoot();

    // Fill the L slot under the sponsor's own root node
    regPlaceUnder($sponsor['id'], $sponsor['id'], 'L', 0);

    // A referral link requesting side=L on that same node must be rejected
    $response = $this->get('/register?sponsor='.$sponsor['adn'].'&placement='.$sponsor['adn'].'&side=L');

    $response->assertRedirect('/contact-us?reason=invalid_referral_link');
});

it('REG-004b: GET /register where both slots are taken (no side given) redirects to invalid_referral_link', function () {
    $sponsor = regSeedRoot();

    regPlaceUnder($sponsor['id'], $sponsor['id'], 'L', 0);
    regPlaceUnder($sponsor['id'], $sponsor['id'], 'R', 0);

    $response = $this->get('/register?sponsor='.$sponsor['adn'].'&placement='.$sponsor['adn']);

    $response->assertRedirect('/contact-us?reason=invalid_referral_link');
});

// ─── REG-005 ─────────────────────────────────────────────────────────────────

it('REG-005: happy path GET /register with valid sponsor, placement in downline, open L slot → redirects to /register/account', function () {
    $sponsor = regSeedRoot();

    $response = $this->get('/register?sponsor='.$sponsor['adn'].'&placement='.$sponsor['adn'].'&side=L');

    $response->assertRedirect(route('register.account.show'));
});

it('REG-005b: happy path stashes sponsor_id and placement_id in the wizard session intent', function () {
    $sponsor = regSeedRoot();

    // Follow the redirect so the session is populated
    $this->get('/register?sponsor='.$sponsor['adn'].'&placement='.$sponsor['adn'].'&side=L');

    $intent = app(WizardStateService::class)->intent();

    expect($intent)->not->toBeNull()
        ->and((int) $intent['sponsor_id'])->toBe($sponsor['id'])
        ->and((int) $intent['placement_id'])->toBe($sponsor['id'])
        ->and($intent['side_opt'])->toBe('L');
});

it('REG-005c: happy path with no explicit side — defaults to open L — stashes null side_opt', function () {
    $sponsor = regSeedRoot();

    $this->get('/register?sponsor='.$sponsor['adn'].'&placement='.$sponsor['adn']);

    $intent = app(WizardStateService::class)->intent();

    expect($intent)->not->toBeNull()
        ->and($intent['side_opt'])->toBeNull();
});

it('REG-005d: happy path with placement_id deep in sponsor downline is accepted', function () {
    $sponsor = regSeedRoot();
    // Place a child under sponsor so we can then target the child as placement
    $child = regPlaceUnder($sponsor['id'], $sponsor['id'], 'L', 0);

    $response = $this->get('/register?sponsor='.$sponsor['adn'].'&placement='.$child['adn'].'&side=R');

    $response->assertRedirect(route('register.account.show'));
});
