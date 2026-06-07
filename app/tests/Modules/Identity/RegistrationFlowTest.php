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

    disableTestForeignKeys();
    try {
        $adn = (string) rand(100000000, 999999999);

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
        enableTestForeignKeys();
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
    $adn = (string) rand(100000000, 999999999);
    $userId = regSeedUser();

    disableTestForeignKeys();
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
        enableTestForeignKeys();
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

    // 999999999 passes the 9-digit regex but won't be in the DB (the
    // sponsor's adn is generated below it via rand). Tests the
    // DB-lookup-fail branch of start(), not the regex branch.
    $response = $this->get('/register?sponsor='.$sponsor['adn'].'&placement=999999999');

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

it('REG-004: GET /register where specified side is already taken redirects to placement_full', function () {
    $sponsor = regSeedRoot();

    // Fill the L slot under the sponsor's own root node
    regPlaceUnder($sponsor['id'], $sponsor['id'], 'L', 0);

    // A referral link requesting side=L on that same node must be rejected —
    // a full slot is its own, recoverable reason (not "invalid link").
    $response = $this->get('/register?sponsor='.$sponsor['adn'].'&placement='.$sponsor['adn'].'&side=L');

    $response->assertRedirect('/contact-us?reason=placement_full');
});

it('REG-004b: GET /register where both slots are taken (no side given) redirects to placement_full', function () {
    $sponsor = regSeedRoot();

    regPlaceUnder($sponsor['id'], $sponsor['id'], 'L', 0);
    regPlaceUnder($sponsor['id'], $sponsor['id'], 'R', 0);

    $response = $this->get('/register?sponsor='.$sponsor['adn'].'&placement='.$sponsor['adn']);

    $response->assertRedirect('/contact-us?reason=placement_full');
});

it('REG-004c: a couple-secondary (-S) placement ADN is normalised to its primary tree node', function () {
    $sponsor = regSeedRoot();

    // A shared link that uses the SECONDARY suffix on the primary's own ADN
    // must resolve to the primary (the real tree node) and proceed, not fail.
    $response = $this->get('/register?sponsor='.$sponsor['adn'].'-S&placement='.$sponsor['adn'].'-S&side=L');

    $response->assertRedirect(route('register.account.show'));

    $intent = app(WizardStateService::class)->intent();
    expect($intent)->not->toBeNull()
        ->and((int) $intent['placement_id'])->toBe($sponsor['id'])  // primary node, not a -S row
        ->and((int) $intent['sponsor_id'])->toBe($sponsor['id']);
});

it('REG-004d: with spillover enabled, a full target is accepted (no placement_full redirect)', function () {
    $sponsor = regSeedRoot();
    regPlaceUnder($sponsor['id'], $sponsor['id'], 'L', 0);
    regPlaceUnder($sponsor['id'], $sponsor['id'], 'R', 0);

    // ADR-0007: spillover on → the engine will place below, so start() must not
    // pre-reject the full target.
    DB::table('settings')->updateOrInsert(
        ['key' => 'placement.spillover.enabled'],
        ['value' => 'true', 'version' => 1, 'updated_at' => now()],
    );

    $response = $this->get('/register?sponsor='.$sponsor['adn'].'&placement='.$sponsor['adn']);

    $response->assertRedirect(route('register.account.show'));
});

// ─── REG-005 ─────────────────────────────────────────────────────────────────

it('REG-005: happy path GET /register with valid sponsor, placement in downline, open L slot → redirects to /register/account', function () {
    $sponsor = regSeedRoot();

    $response = $this->get('/register?sponsor='.$sponsor['adn'].'&placement='.$sponsor['adn'].'&side=L');

    // Orientation is now step 1 (public, before account creation), so the
    // referral-link entry now lands the user on the orientation page.
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

// ── /join + ADN-lookup endpoint ──────────────────────────────────────────────

it('JOIN-01: GET /join?sponsor=<adn> pre-fills + locks the sponsor field', function () {
    $sponsor = regSeedRoot();

    $response = $this->get('/join?sponsor='.$sponsor['adn']);

    $response->assertOk();
    // The sponsor input is pre-filled AND rendered readonly (the user
    // can't edit it client-side; the form still POSTs the value).
    $response->assertSee('value="'.$sponsor['adn'].'"', escape: false);
    $response->assertSee('readonly', escape: false);
});

it('JOIN-LOOKUP-01: returns the name for a valid ADN but never the sponsor email (privacy)', function () {
    $sponsor = regSeedRoot();
    // The regSeed helpers create a User without a full_name; backfill
    // one so we can assert the controller surfaces it correctly.
    $sponsorUserId = DB::table('distributors')->where('id', $sponsor['id'])->value('user_id');
    DB::table('users')->where('id', $sponsorUserId)->update(['full_name' => 'Aarti Sharma']);
    $sponsorEmail = (string) DB::table('users')->where('id', $sponsorUserId)->value('email');

    $response = $this->getJson('/join/lookup?adn='.$sponsor['adn']);

    $response->assertOk();
    $response->assertJson([
        'found' => true,
        'name' => 'Aarti Sharma',
        'is_secondary' => false,
    ]);
    // The sponsor's email — even masked — must not be surfaced.
    $response->assertJsonMissingPath('email_masked');
    $response->assertJsonMissingPath('email');
    expect($response->getContent())->not->toContain('@');
    expect($response->getContent())->not->toContain(explode('@', $sponsorEmail)[0]);
});

it('JOIN-LOOKUP-02: returns found=false for an unknown but well-formed ADN', function () {
    regSeedRoot();

    // 999999999 passes the regex but isn't seeded.
    $response = $this->getJson('/join/lookup?adn=999999999');

    $response->assertOk();
    $response->assertExactJson(['found' => false]);
});

it('JOIN-LOOKUP-03: rejects malformed ADN without touching the DB', function () {
    $response = $this->getJson('/join/lookup?adn=garbage');

    $response->assertOk();
    $response->assertExactJson(['found' => false]);
});

// ─── JOIN-SUBMIT — server backstop: a wrong ADN must not proceed ──────────────

it('JOIN-SUBMIT-01: a non-existent placement ADN returns to /join with an error (does not proceed)', function () {
    $sponsor = regSeedRoot();

    $response = $this->from('/join')->post('/join', [
        'sponsor_adn' => $sponsor['adn'],
        'placement_adn' => '999999999', // well-formed but not seeded
    ]);

    $response->assertRedirect('/join');
    $response->assertSessionHasErrors('placement_adn');
    $response->assertSessionDoesntHaveErrors('sponsor_adn');
});

it('JOIN-SUBMIT-02: a non-existent sponsor ADN returns to /join with an error', function () {
    $sponsor = regSeedRoot();

    $response = $this->from('/join')->post('/join', [
        'sponsor_adn' => '999999999',
        'placement_adn' => $sponsor['adn'],
    ]);

    $response->assertRedirect('/join');
    $response->assertSessionHasErrors('sponsor_adn');
});

it('JOIN-SUBMIT-03: both ADNs valid proceeds to the canonical /register entry', function () {
    $sponsor = regSeedRoot();

    $response = $this->post('/join', [
        'sponsor_adn' => $sponsor['adn'],
        'placement_adn' => $sponsor['adn'],
    ]);

    $response->assertRedirect('/register?sponsor='.$sponsor['adn'].'&placement='.$sponsor['adn']);
    $response->assertSessionHasNoErrors();
});
