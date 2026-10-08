<?php

declare(strict_types=1);
use App\Modules\Identity\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

it('distributor top bar has a red sign-out pill and no profile dropdown', function () {
    $d = uiDistributor();
    $html = $this->actingAs($d['user'])->get(route('my-business'))->assertOk()->getContent();
    expect($html)->not->toContain(' data-profile-menu>')
        ->toContain('data-signout-pill')
        ->toContain('bg-brand-600');
    // Sign out sits at the far right of the thin strip, after the country marker.
    expect(strpos($html, 'data-signout-pill'))->toBeGreaterThan(strpos($html, 'India'));
});

it('guests get a Sign in link in the top strip before Register with us, and no main-nav Sign In button', function () {
    $guest = $this->get(route('about'))->assertOk()->getContent();
    expect($guest)->toContain('data-signin-link')->not->toContain('data-signout-pill')
        ->toContain(route('login'))
        ->not->toContain(">\n                Sign In\n            </a>");
    // Sign in precedes Register with us in the utility strip (client, 2026-09-30).
    expect(strpos($guest, 'data-signin-link'))->toBeLessThan(strpos($guest, 'Register with us'));
});

it('distributor header chip shows the ADN beside the name, on desktop and in the mobile menu', function () {
    $d = uiDistributor();
    $html = $this->actingAs($d['user'])->get(route('my-business'))->assertOk()->getContent();
    expect($html)->toContain('data-header-adn')
        ->toContain('Signed in as');
    expect(substr_count($html, $d['user']->distributor->adn))->toBeGreaterThanOrEqual(2);
});

it('sidebar has a My Profile group with Edit Profile, Change Password, My Addresses', function () {
    $d = uiDistributor();
    $html = $this->actingAs($d['user'])->get(route('profile.password.show'))->assertOk()->getContent();
    expect($html)->toContain('>My Profile<')
        ->toContain('Edit Profile')->toContain('Change Password')->toContain('My Addresses');
    // On the password page only Change Password is current.
    preg_match_all('/aria-current="page"[^>]*>.*?<span class="flex-1 truncate">([^<]+)</s', $html, $m);
    expect(array_values(array_unique($m[1])))->toBe(['Change Password']);
});

it('admins keep the profile dropdown and get no sign-out pill', function () {
    Role::findOrCreate('admin', 'web');
    $admin = User::create([
        'email' => 'ui-admin-'.uniqid().'@test.com',
        'phone_e164' => '+91'.str_pad((string) random_int(7000000000, 9999999999), 10, '0'),
        'password_hash' => bcrypt('x'),
        'status' => 'active',
    ]);
    $admin->assignRole('admin');

    $html = $this->actingAs($admin)->get(route('about'))->assertOk()->getContent();
    expect($html)->toContain('data-profile-menu')->not->toContain('data-signout-pill');
});

it('sidebar column scrolls on its own and items hover light blue', function () {
    $d = uiDistributor();
    $html = $this->actingAs($d['user'])->get(route('my-business'))->getContent();
    expect($html)->toContain('lg:max-h-[calc(100vh-8rem)]')->toContain('nav-scroll')
        ->toContain('hover:bg-brand-100')
        ->not->toContain('hover:bg-white/80');
});
