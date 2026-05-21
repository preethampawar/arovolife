<?php

declare(strict_types=1);

/**
 * Tests for the distributor self-uploaded ID-card photo.
 *
 * IDP-01..05 cover the happy path, the replace-and-delete-old invariant,
 * validation rejections, and the auth gate.
 *
 * Uses Storage::fake('s3') to mock the disk — no real AWS traffic and
 * the test isolates fully under SQLite :memory:. The tests upload a
 * GD-generated PNG (UploadedFile::fake()->image()) which is a valid
 * image but contains no EXIF; that's fine for these tests because we're
 * asserting the storage / DB / audit-log mechanics, not the EXIF strip
 * behaviour itself (which is implementation detail of the controller's
 * private stripExif() method).
 */

use App\Modules\Compliance\Models\AuditLog;
use App\Modules\Identity\Models\User;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

function idpUser(): User
{
    return User::create([
        'full_name' => 'Photo Uploader',
        'email' => 'idp-'.uniqid().'@example.com',
        'phone_e164' => '+91955'.str_pad((string) random_int(0, 9999999), 7, '0', STR_PAD_LEFT),
        'password_hash' => Hash::make('idp-test-pwd-2026'),
        'password_set_at' => now(),
        'status' => 'active',
        'email_verified_at' => now(),
    ]);
}

it('IDP-01: authenticated user uploads a valid image — S3 object created, DB updated, audit entry written', function (): void {
    Storage::fake('s3');
    $user = idpUser();

    $response = $this->actingAs($user)
        ->withoutMiddleware(PreventRequestForgery::class)
        ->post(route('profile.id-photo.update'), [
            'photo' => UploadedFile::fake()->image('passport.jpg', 800, 1000),
        ]);

    $response->assertRedirect();

    $user->refresh();
    expect($user->id_photo_path)->not->toBeNull();
    expect($user->id_photo_path)->toStartWith("user_{$user->id}/id-photo/");
    expect($user->id_photo_path)->toEndWith('.jpg');

    Storage::disk('s3')->assertExists($user->id_photo_path);

    expect(AuditLog::where('action', 'profile.id_photo.updated')->where('actor_id', $user->id)->count())->toBe(1);
});

it('IDP-02: replacing an existing photo deletes the previous S3 object (no orphans)', function (): void {
    Storage::fake('s3');
    $user = idpUser();

    // First upload
    $this->actingAs($user)
        ->withoutMiddleware(PreventRequestForgery::class)
        ->post(route('profile.id-photo.update'), [
            'photo' => UploadedFile::fake()->image('first.jpg', 400, 500),
        ]);
    $user->refresh();
    $firstKey = $user->id_photo_path;
    expect($firstKey)->not->toBeNull();
    Storage::disk('s3')->assertExists($firstKey);

    // Second upload — different image
    $this->actingAs($user)
        ->withoutMiddleware(PreventRequestForgery::class)
        ->post(route('profile.id-photo.update'), [
            'photo' => UploadedFile::fake()->image('second.png', 400, 500),
        ]);
    $user->refresh();
    $secondKey = $user->id_photo_path;
    expect($secondKey)->not->toBe($firstKey);

    // Old object must be gone; new must exist.
    Storage::disk('s3')->assertMissing($firstKey);
    Storage::disk('s3')->assertExists($secondKey);
    expect($secondKey)->toEndWith('.png');
});

it('IDP-03: file exceeding 5 MB cap is rejected with a 422-style validation error', function (): void {
    Storage::fake('s3');
    $user = idpUser();

    // create() makes a fake file with a specified kilobyte size — 6144 KB = 6 MB
    $response = $this->actingAs($user)
        ->withoutMiddleware(PreventRequestForgery::class)
        ->from(route('dashboard'))
        ->post(route('profile.id-photo.update'), [
            'photo' => UploadedFile::fake()->image('huge.jpg', 800, 1000)->size(6144),
        ]);

    $response->assertSessionHasErrors('photo');
    $user->refresh();
    expect($user->id_photo_path)->toBeNull();
});

it('IDP-04: non-image upload (e.g. PDF) is rejected and DB is untouched', function (): void {
    Storage::fake('s3');
    $user = idpUser();

    $response = $this->actingAs($user)
        ->withoutMiddleware(PreventRequestForgery::class)
        ->from(route('dashboard'))
        ->post(route('profile.id-photo.update'), [
            'photo' => UploadedFile::fake()->create('not-an-image.pdf', 100, 'application/pdf'),
        ]);

    $response->assertSessionHasErrors('photo');
    $user->refresh();
    expect($user->id_photo_path)->toBeNull();
});

it('IDP-05: unauthenticated POST to /profile/id-photo redirects to login', function (): void {
    Storage::fake('s3');

    $response = $this
        ->withoutMiddleware(PreventRequestForgery::class)
        ->post(route('profile.id-photo.update'), [
            'photo' => UploadedFile::fake()->image('any.jpg', 400, 500),
        ]);

    $response->assertRedirect(route('login'));
});

it('IDP-06: DELETE /profile/id-photo removes the S3 object and clears the DB column', function (): void {
    Storage::fake('s3');
    $user = idpUser();

    // First upload so there's something to delete
    $this->actingAs($user)
        ->withoutMiddleware(PreventRequestForgery::class)
        ->post(route('profile.id-photo.update'), [
            'photo' => UploadedFile::fake()->image('to-be-deleted.jpg', 400, 500),
        ]);
    $user->refresh();
    $key = $user->id_photo_path;
    expect($key)->not->toBeNull();
    Storage::disk('s3')->assertExists($key);

    // Now delete
    $response = $this->actingAs($user)
        ->withoutMiddleware(PreventRequestForgery::class)
        ->delete(route('profile.id-photo.destroy'));

    $response->assertRedirect();
    $user->refresh();
    expect($user->id_photo_path)->toBeNull();
    Storage::disk('s3')->assertMissing($key);
});
