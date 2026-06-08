<?php

declare(strict_types=1);

use App\Modules\Compliance\Models\AuditLog;
use App\Modules\Identity\Models\User;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;

uses(RefreshDatabase::class);

/**
 * Backlog #9: "Find My ID" — recover a forgotten ADN by registered name + PAN.
 * The PAN is hashed the same way registration does (sha256 of trimmed/upper)
 * and matched against the stored hash; raw PAN is never stored/shown/logged.
 * Only an exact name + PAN match returns an ADN, behind a per-IP rate limit.
 */
function fmiDistributor(string $adn, string $fullName, string $pan, string $status = 'active'): User
{
    $user = User::create([
        'full_name' => $fullName,
        'email' => 'fmi-'.uniqid().'@test.com',
        'phone_e164' => '+91'.str_pad((string) rand(7000000000, 9999999999), 10, '0'),
        'password_hash' => bcrypt('x'), 'status' => $status,
    ]);
    disableTestForeignKeys();
    try {
        $id = DB::table('distributors')->insertGetId([
            'user_id' => $user->id, 'adn' => $adn,
            'pan_hash' => hash('sha256', strtoupper(trim($pan)), true),
            'pan_last4' => strtoupper(substr($pan, -4)),
            'bank_account_enc' => 'stub', 'bank_ifsc' => 'SBIN0000000',
            'sponsor_id' => 0, 'placement_parent_id' => 0, 'side_chosen_by' => 'referral_default', 'depth' => 0,
            'effective_date' => now()->format('Y-m-d H:i:s.v'),
            'cooling_off_end_at' => now()->copy()->addDays(30)->format('Y-m-d H:i:s.v'),
            // distributors.status is the slot flag (active/inactive); account
            // liveness for the lookup is the USER status set above.
            'state' => 'TS', 'is_primary_couple' => 0, 'status' => 'active',
            'created_at' => now()->format('Y-m-d H:i:s.v'), 'updated_at' => now()->format('Y-m-d H:i:s.v'),
        ]);
        DB::table('distributors')->where('id', $id)->update(['sponsor_id' => $id, 'placement_parent_id' => $id]);
    } finally {
        enableTestForeignKeys();
    }

    return $user;
}

function fmiPost(array $data)
{
    // Consent is required (DPDP); default it on so individual tests stay focused.
    $data += ['consent_privacy' => '1'];

    return test()->withoutMiddleware(PreventRequestForgery::class)
        ->post(route('find-my-id.lookup'), $data);
}

beforeEach(function (): void {
    RateLimiter::clear('find-my-id:127.0.0.1');
    RateLimiter::clear('find-my-id-daily:127.0.0.1');
});

it('FMI-01: exact name + PAN returns the ADN and audits the disclosure', function (): void {
    fmiDistributor('100200300', 'Ravi Kumar', 'ABCDE1234F');

    $response = fmiPost(['full_name' => 'Ravi Kumar', 'pan' => 'ABCDE1234F']);

    $response->assertOk();
    $response->assertSee('100200300');
    $response->assertSee('We found your account');

    expect(AuditLog::where('action', 'identity.find_my_id.success')->count())->toBe(1);
    // The audit row carries no PAN.
    $log = AuditLog::where('action', 'identity.find_my_id.success')->first();
    expect(json_encode($log->details))->not->toContain('ABCDE1234F');
});

it('FMI-02: correct PAN but wrong name does not reveal the ADN', function (): void {
    fmiDistributor('100200301', 'Ravi Kumar', 'ABCDE1234F');

    $response = fmiPost(['full_name' => 'Someone Else', 'pan' => 'ABCDE1234F']);

    $response->assertOk();
    $response->assertDontSee('100200301');
    $response->assertSee('matching those details');
    expect(AuditLog::where('action', 'identity.find_my_id.success')->count())->toBe(0);
});

it('FMI-03: correct name but wrong PAN does not reveal the ADN', function (): void {
    fmiDistributor('100200302', 'Ravi Kumar', 'ABCDE1234F');

    $response = fmiPost(['full_name' => 'Ravi Kumar', 'pan' => 'ZZZZZ9999Z']);

    $response->assertOk();
    $response->assertDontSee('100200302');
    $response->assertSee('matching those details');
});

it('FMI-04: name is case/space-insensitive and lowercase PAN still matches', function (): void {
    fmiDistributor('100200303', 'Ravi Kumar', 'ABCDE1234F');

    $response = fmiPost(['full_name' => '  ravi KUMAR ', 'pan' => 'abcde1234f']);

    $response->assertOk();
    $response->assertSee('100200303');
});

it('FMI-05: a malformed PAN is rejected before any lookup', function (): void {
    fmiDistributor('100200304', 'Ravi Kumar', 'ABCDE1234F');

    fmiPost(['full_name' => 'Ravi Kumar', 'pan' => 'NOTAPAN'])
        ->assertRedirect()
        ->assertSessionHasErrors('pan');
});

it('FMI-06: the per-IP rate limit blocks brute-forcing after 5 attempts', function (): void {
    fmiDistributor('100200305', 'Ravi Kumar', 'ABCDE1234F');

    // 5 wrong-PAN attempts are allowed (each counts toward the limit)...
    for ($i = 0; $i < 5; $i++) {
        fmiPost(['full_name' => 'Ravi Kumar', 'pan' => 'ZZZZZ0000Z'])->assertOk();
    }

    // ...the 6th — even with the CORRECT details — is blocked, so a guesser
    // can't keep trying.
    $response = fmiPost(['full_name' => 'Ravi Kumar', 'pan' => 'ABCDE1234F']);
    $response->assertOk();
    $response->assertSee('Too many attempts');
    $response->assertDontSee('100200305');
});

it('FMI-07: a terminated distributor is not returned', function (): void {
    fmiDistributor('100200306', 'Gone Distributor', 'ABCDE1234F', status: 'terminated');

    fmiPost(['full_name' => 'Gone Distributor', 'pan' => 'ABCDE1234F'])
        ->assertOk()
        ->assertDontSee('100200306')
        ->assertSee('matching those details');
});

it('FMI-08: the lookup requires privacy consent (DPDP)', function (): void {
    fmiDistributor('100200307', 'Ravi Kumar', 'ABCDE1234F');

    test()->withoutMiddleware(PreventRequestForgery::class)
        ->post(route('find-my-id.lookup'), ['full_name' => 'Ravi Kumar', 'pan' => 'ABCDE1234F'])
        ->assertRedirect()
        ->assertSessionHasErrors('consent_privacy');

    expect(AuditLog::where('action', 'identity.find_my_id.success')->count())->toBe(0);
});

it('FMI-09: the login page links to Find My ID', function (): void {
    $this->get(route('login'))
        ->assertOk()
        ->assertSee('Forgot your ADN?')
        ->assertSee(route('find-my-id.show'), false);
});
