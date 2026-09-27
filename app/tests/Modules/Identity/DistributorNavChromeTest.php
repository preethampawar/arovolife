<?php
declare(strict_types=1);
use Illuminate\Foundation\Testing\RefreshDatabase;
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

it('guest Sign In pill and distributor Sign out pill share the same slot', function () {
    $guest = $this->get(route('about'))->assertOk()->getContent();
    expect($guest)->toContain('data-signin-pill')->not->toContain('data-signout-pill');
    // Guests find Sign in in the same spot: thin strip, after the country marker.
    expect(strpos($guest, 'data-signin-pill'))->toBeGreaterThan(strpos($guest, 'India'))
        ->and(strpos($guest, 'data-signin-pill'))->toBeLessThan(strpos($guest, '<nav'));
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
    \Spatie\Permission\Models\Role::findOrCreate('admin', 'web');
    $admin = \App\Modules\Identity\Models\User::create([
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
