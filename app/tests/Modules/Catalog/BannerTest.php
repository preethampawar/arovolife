<?php

declare(strict_types=1);

use App\Modules\Catalog\Models\Banner;
use App\Modules\Catalog\Models\ProductCategory;
use App\Modules\Identity\Models\User;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    DB::table('settings')->updateOrInsert(['key' => 'commerce.storefront.enabled'], ['value' => 'true', 'version' => 1, 'updated_at' => now()]);
});

function banAdmin(): User
{
    Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
    $u = User::create([
        'full_name' => 'Banner Admin', 'email' => 'ban-'.uniqid().'@test.com',
        'phone_e164' => '+9180000'.rand(10000, 99999), 'password_hash' => bcrypt('x'),
        'password_set_at' => now(), 'status' => 'active', 'email_verified_at' => now(),
    ]);
    $u->assignRole('admin');

    return $u;
}

function banUser(): User
{
    return User::create([
        'full_name' => 'Plain', 'email' => 'plain-'.uniqid().'@test.com',
        'phone_e164' => '+9181000'.rand(10000, 99999), 'password_hash' => bcrypt('x'),
        'password_set_at' => now(), 'status' => 'active',
    ]);
}

it('BAN-01: admin creates a banner via external URL and it shows on the shop carousel', function (): void {
    $this->actingAs(banAdmin())->withoutMiddleware(PreventRequestForgery::class)
        ->post(route('admin.catalog.banners.store'), [
            'title' => 'Summer Sale', 'external_url' => 'https://cdn.example.com/b.jpg', 'sort' => 0, 'status' => 'active',
        ])->assertRedirect(route('admin.catalog.banners.index'));

    $banner = Banner::first();
    expect($banner->external_url)->toBe('https://cdn.example.com/b.jpg')
        ->and($banner->s3_key)->toBeNull();

    $this->get(route('shop.index'))->assertOk()
        ->assertSee('data-carousel', false)
        ->assertSee('https://cdn.example.com/b.jpg', false)
        ->assertSee('Summer Sale');
});

it('BAN-02: admin uploads a banner image to S3 (file wins over URL)', function (): void {
    Storage::fake('s3');

    $this->actingAs(banAdmin())->withoutMiddleware(PreventRequestForgery::class)
        ->post(route('admin.catalog.banners.store'), [
            'image' => UploadedFile::fake()->image('mall.jpg', 1520, 350),
            'external_url' => 'https://cdn.example.com/ignored.jpg',
            'status' => 'active',
        ])->assertRedirect();

    $banner = Banner::first();
    expect($banner->s3_key)->not->toBeNull()
        ->and($banner->external_url)->toBeNull(); // upload took precedence
    Storage::disk('s3')->assertExists($banner->s3_key);
});

it('BAN-03: an archived banner is NOT shown on the shop', function (): void {
    Banner::create(['title' => 'Hidden', 'external_url' => 'https://x/a.jpg', 'status' => 'archived']);

    $this->get(route('shop.index'))->assertOk()->assertDontSee('https://x/a.jpg', false);
});

it('BAN-04: a non-admin cannot manage banners', function (): void {
    $this->actingAs(banUser())->get(route('admin.catalog.banners.index'))->assertForbidden();
});

it('BAN-05: admin can delete a banner', function (): void {
    $banner = Banner::create(['title' => 'Bye', 'external_url' => 'https://x/d.jpg', 'status' => 'active']);

    $this->actingAs(banAdmin())->withoutMiddleware(PreventRequestForgery::class)
        ->delete(route('admin.catalog.banners.destroy', $banner))->assertRedirect();

    expect(Banner::find($banner->id))->toBeNull();
});

it('BAN-07: an uploaded banner is written to S3 and served via a signed URL (not a dead public URL)', function (): void {
    Storage::fake('s3');
    // The fake (local) disk needs a stub for signed URLs, mirroring real S3.
    Storage::disk('s3')->buildTemporaryUrlsUsing(fn (string $path, $exp) => 'https://signed.example/'.$path);

    $this->actingAs(banAdmin())->withoutMiddleware(PreventRequestForgery::class)
        ->post(route('admin.catalog.banners.store'), [
            'title' => 'Uploaded banner',
            'image' => UploadedFile::fake()->image('mall.png', 1520, 350),
            'sort' => 0, 'status' => 'active',
        ])->assertRedirect(route('admin.catalog.banners.index'));

    $banner = Banner::first();
    expect($banner->s3_key)->not->toBeNull();
    expect($banner->external_url)->toBeNull();
    // The object was actually written (the silent-fail bug is gone)...
    Storage::disk('s3')->assertExists($banner->s3_key);
    // ...and url() returns a SIGNED url, not a plain (403-ing) public one.
    expect($banner->url())->toBe('https://signed.example/'.$banner->s3_key);
});

it('BAN-06: the admin banners index renders a status flash exactly once (no duplicate)', function (): void {
    $response = $this->actingAs(banAdmin())
        ->withSession(['status' => 'Banner removed.'])
        ->get(route('admin.catalog.banners.index'))
        ->assertOk();

    // The layout is the single source of the flash — the view must not echo it again.
    expect(substr_count($response->getContent(), 'Banner removed.'))->toBe(1);
});

it('CATBAN-01: a category banner shows on the category-filtered shop view', function (): void {
    $cat = ProductCategory::create([
        'slug' => 'health', 'name' => 'Health', 'status' => 'active', 'sort' => 0,
        'banner_external_url' => 'https://cdn.example.com/cat.jpg',
    ]);

    $this->get(route('shop.index', ['category' => 'health']))->assertOk()
        ->assertSee('https://cdn.example.com/cat.jpg', false);

    // Not shown on the unfiltered shop.
    $this->get(route('shop.index'))->assertOk()->assertDontSee('https://cdn.example.com/cat.jpg', false);
});

it('CATNAV-01: active top-level categories appear in the storefront categories dropdown', function (): void {
    ProductCategory::create(['slug' => 'beauty', 'name' => 'Beauty Care', 'status' => 'active', 'sort' => 1]);

    $this->get(route('shop.index'))->assertOk()
        ->assertSee('data-cat-panel', false)
        ->assertSee('Beauty Care');
});
