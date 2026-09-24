<?php

declare(strict_types=1);

use App\Modules\Catalog\Models\Banner;
use App\Modules\Catalog\Models\ProductCategory;
use App\Modules\Catalog\Models\ProductImage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

/**
 * catalog:copy-images-from-s3 — the one-off move of catalogue images from the
 * private bucket to the local `catalog` disk.
 */
beforeEach(function (): void {
    Storage::fake('s3');
    fakeCatalogDisk();
});

function copyImgKey(string $prefix, string $uuid): string
{
    return $prefix.'/'.$uuid.'.jpg';
}

it('COPY-01: copies every referenced catalogue key that the local disk lacks', function (): void {
    $gallery = copyImgKey('products/gallery', '11111111-1111-4111-8111-111111111111');
    $tile = copyImgKey('categories', '22222222-2222-4222-8222-222222222222');
    $catBanner = copyImgKey('category-banners', '33333333-3333-4333-8333-333333333333');
    $banner = copyImgKey('banners', '44444444-4444-4444-8444-444444444444');

    foreach ([$gallery, $tile, $catBanner, $banner] as $key) {
        Storage::disk('s3')->put($key, 'bytes-of-'.$key);
    }

    ProductImage::create(['s3_key' => $gallery, 'kind' => 'gallery', 'sort' => 0]);
    $category = ProductCategory::create(['slug' => 'c', 'name' => 'C', 'sort' => 1, 'status' => 'active']);
    $category->forceFill(['image_s3_key' => $tile, 'banner_s3_key' => $catBanner])->save();
    $b = new Banner(['title' => 'B', 'status' => 'active', 'sort' => 0]);
    $b->s3_key = $banner;
    $b->save();

    // One is already local and must not be rewritten.
    Storage::disk('catalog')->put($tile, 'already-local');

    $this->artisan('catalog:copy-images-from-s3')
        ->expectsOutputToContain('3 copied, 1 already present, 0 missing on S3.')
        ->assertSuccessful();

    foreach ([$gallery, $catBanner, $banner] as $key) {
        expect(Storage::disk('catalog')->get($key))->toBe('bytes-of-'.$key);
    }
    expect(Storage::disk('catalog')->get($tile))->toBe('already-local');
    Storage::disk('s3')->assertExists($gallery); // S3 copies are never deleted
});

it('COPY-02: skips keys outside the catalogue allow-list', function (): void {
    ProductImage::create(['s3_key' => 'kyc/reg_1/pan.jpg', 'kind' => 'gallery', 'sort' => 0]);
    Storage::disk('s3')->put('kyc/reg_1/pan.jpg', 'secret');

    $this->artisan('catalog:copy-images-from-s3')
        ->expectsOutputToContain('0 copied, 0 already present, 0 missing on S3.')
        ->assertSuccessful();

    expect(Storage::disk('catalog')->allFiles())->toBe([]);
});

it('COPY-03: --dry-run lists what would be copied and writes nothing', function (): void {
    $key = copyImgKey('products/inline', '55555555-5555-4555-8555-555555555555');
    Storage::disk('s3')->put($key, 'x');
    ProductImage::create(['s3_key' => $key, 'kind' => 'inline', 'sort' => 0]);

    $this->artisan('catalog:copy-images-from-s3', ['--dry-run' => true])
        ->expectsOutputToContain('would copy '.$key)
        ->expectsOutputToContain('DRY RUN — 1 copied')
        ->assertSuccessful();

    expect(Storage::disk('catalog')->allFiles())->toBe([]);
});

it('COPY-04: a key missing on S3 is reported, not written as an empty file, and the run continues', function (): void {
    $gone = copyImgKey('products/gallery', '66666666-6666-4666-8666-666666666666');
    $here = copyImgKey('products/gallery', '77777777-7777-4777-8777-777777777777');
    Storage::disk('s3')->put($here, 'ok');
    ProductImage::create(['s3_key' => $gone, 'kind' => 'gallery', 'sort' => 0]);
    ProductImage::create(['s3_key' => $here, 'kind' => 'gallery', 'sort' => 1]);

    $this->artisan('catalog:copy-images-from-s3')
        ->expectsOutputToContain('missing on S3: '.$gone)
        ->expectsOutputToContain('1 copied, 0 already present, 1 missing on S3.')
        ->assertSuccessful();

    Storage::disk('catalog')->assertMissing($gone);
    expect(Storage::disk('catalog')->get($here))->toBe('ok');
});
