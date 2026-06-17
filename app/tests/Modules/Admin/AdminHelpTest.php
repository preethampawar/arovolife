<?php

declare(strict_types=1);

use App\Modules\Identity\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

/**
 * In-admin "Help & Reference" — renders curated docs/ markdown for the ops team.
 */
function helpAdmin(): User
{
    Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
    $admin = User::create([
        'full_name' => 'Help Admin',
        'email' => 'help-admin-'.uniqid().'@example.com',
        'phone_e164' => '+9180000'.rand(10000, 99999),
        'password_hash' => bcrypt('Adm1n!Pass#2026Test'),
        'password_set_at' => now(),
        'status' => 'active',
        'email_verified_at' => now(),
    ]);
    $admin->assignRole('admin');

    return $admin;
}

it('AH-01: an admin sees the help index listing the status reference', function (): void {
    $this->actingAs(helpAdmin())
        ->get(route('admin.help.index'))
        ->assertOk()
        ->assertSee('Help &amp; Reference', false)
        ->assertSee('Status Reference');
});

it('AH-02: an admin can read the rendered status-reference doc (markdown → HTML)', function (): void {
    $response = $this->actingAs(helpAdmin())
        ->get(route('admin.help.show', 'status-reference'))
        ->assertOk();

    // Rendered from the markdown: a heading, a table, and a canonical value.
    $response->assertSee('Status Reference');
    $response->assertSee('<table>', false);          // GFM table rendered to HTML
    $response->assertSee('Blocked');                 // the frozen→Blocked label
    $response->assertSee('markdown-doc', false);     // styled container
});

it('AH-03: an unknown reference slug 404s', function (): void {
    $this->actingAs(helpAdmin())
        ->get(route('admin.help.show', 'does-not-exist'))
        ->assertNotFound();
});

it('AH-04: a non-admin user cannot reach the help section', function (): void {
    $user = User::create([
        'full_name' => 'Plain User',
        'email' => 'help-plain-'.uniqid().'@example.com',
        'phone_e164' => '+9180000'.rand(10000, 99999),
        'password_hash' => bcrypt('Us3r!Pass#2026Test'),
        'password_set_at' => now(),
        'status' => 'active',
        'email_verified_at' => now(),
    ]);

    $this->actingAs($user)
        ->get(route('admin.help.index'))
        ->assertForbidden();
});

it('AH-05: a guest is redirected to login', function (): void {
    $this->get(route('admin.help.index'))->assertRedirect(route('login'));
});
