<?php

declare(strict_types=1);

use App\Modules\Catalog\Models\Banner;
use App\Modules\Catalog\Models\ProductCategory;
use App\Modules\Catalog\Models\ProductImage;
use App\Modules\Catalog\Support\CatalogImageUrl;
use Illuminate\Support\Facades\Storage;

/**
 * Catalogue images get a stable CDN URL when `arovolife.media.catalog_cdn_url`
 * is set; everything else — and everything when it is unset — keeps the 1-day
 * signed S3 URL. See docs/plans/2026-09-24-cloudfront-catalog-cdn.md.
 */
const CATALOG_CDN_TEST_UUID = '0f8fad5b-d9cb-469f-a165-70867728950e';

beforeEach(function (): void {
    Storage::fake('s3');
    config(['filesystems.disks.s3.bucket' => 'arovolife-prod']);
});

function catalogCdnOn(): void
{
    config(['arovolife.media.catalog_cdn_url' => 'https://cdn.example.com/']);
}

it('CDN-01: with the CDN unset every key keeps a signed URL (killswitch)', function (): void {
    config(['arovolife.media.catalog_cdn_url' => '']);

    $url = CatalogImageUrl::for('products/gallery/'.CATALOG_CDN_TEST_UUID.'.jpg');

    expect($url)->not->toStartWith('https://cdn.example.com');
    expect($url)->toBe(Storage::disk('s3')->temporaryUrl('products/gallery/'.CATALOG_CDN_TEST_UUID.'.jpg', now()->addDay()));
});

it('CDN-02: with the CDN set each catalogue prefix gets a stable CDN URL', function (string $key): void {
    catalogCdnOn();

    expect(CatalogImageUrl::for($key))->toBe('https://cdn.example.com/'.$key);
})->with([
    'gallery' => 'products/gallery/'.CATALOG_CDN_TEST_UUID.'.jpg',
    'inline' => 'products/inline/'.CATALOG_CDN_TEST_UUID.'.png',
    'category tile' => 'categories/'.CATALOG_CDN_TEST_UUID.'.jpg',
    'category banner' => 'category-banners/'.CATALOG_CDN_TEST_UUID.'.webp',
    'home banner' => 'banners/'.CATALOG_CDN_TEST_UUID.'.jpeg',
]);

it('CDN-03: private keys never get a CDN URL, even with the CDN set', function (string $key): void {
    catalogCdnOn();

    expect(CatalogImageUrl::for($key))->not->toStartWith('https://cdn.example.com');
})->with([
    'ID photo' => 'user_5/id-photo/'.CATALOG_CDN_TEST_UUID.'.jpg',
    'KYC scan' => 'kyc/reg_abc/pan.jpg',
    'grievance' => 'grievance/12/'.CATALOG_CDN_TEST_UUID.'.pdf',
    'nested traversal' => 'products/gallery/../../kyc/pan.jpg',
    'wrong type' => 'products/gallery/'.CATALOG_CDN_TEST_UUID.'.pdf',
]);

it('CDN-04: the model accessors route through the CDN', function (): void {
    catalogCdnOn();

    $image = new ProductImage(['s3_key' => 'products/gallery/'.CATALOG_CDN_TEST_UUID.'.jpg']);
    $category = new ProductCategory(['image_s3_key' => 'categories/'.CATALOG_CDN_TEST_UUID.'.jpg']);
    $category->banner_s3_key = 'category-banners/'.CATALOG_CDN_TEST_UUID.'.jpg';
    $banner = new Banner;
    $banner->s3_key = 'banners/'.CATALOG_CDN_TEST_UUID.'.jpg';

    expect($image->url())->toBe('https://cdn.example.com/products/gallery/'.CATALOG_CDN_TEST_UUID.'.jpg');
    expect($category->imageUrl())->toBe('https://cdn.example.com/categories/'.CATALOG_CDN_TEST_UUID.'.jpg');
    expect($category->bannerUrl())->toBe('https://cdn.example.com/category-banners/'.CATALOG_CDN_TEST_UUID.'.jpg');
    expect($banner->url())->toBe('https://cdn.example.com/banners/'.CATALOG_CDN_TEST_UUID.'.jpg');
});

it('CDN-05: stored HTML has expired signed S3 URLs re-pointed at the CDN', function (): void {
    catalogCdnOn();
    $signed = 'https://arovolife-prod.s3.ap-south-1.amazonaws.com/products/inline/'.CATALOG_CDN_TEST_UUID
        .'.png?X-Amz-Algorithm=AWS4-HMAC-SHA256&amp;X-Amz-Expires=86400&amp;X-Amz-Signature=abc123';

    $out = CatalogImageUrl::rewriteStoredHtml('<p>Hi</p><img src="'.$signed.'" alt="x">');

    expect($out)->toBe('<p>Hi</p><img src="https://cdn.example.com/products/inline/'.CATALOG_CDN_TEST_UUID.'.png" alt="x">');
});

it('CDN-06: the rewrite leaves external URLs, other buckets and private prefixes alone', function (): void {
    catalogCdnOn();
    $html = '<img src="https://example.com/a.png">'
        .'<img src="https://other-bucket.s3.ap-south-1.amazonaws.com/products/inline/'.CATALOG_CDN_TEST_UUID.'.png?X-Amz-Signature=1">'
        .'<img src="https://arovolife-prod.s3.ap-south-1.amazonaws.com/kyc/reg_1/pan.jpg?X-Amz-Signature=1">';

    expect(CatalogImageUrl::rewriteStoredHtml($html))->toBe($html);
});

it('CDN-07: without the CDN the rewrite re-signs the stored URL, HTML-escaped', function (): void {
    config(['arovolife.media.catalog_cdn_url' => '']);
    $key = 'products/inline/'.CATALOG_CDN_TEST_UUID.'.png';
    $stale = 'https://arovolife-prod.s3.amazonaws.com/'.$key.'?X-Amz-Signature=stale';

    $out = CatalogImageUrl::rewriteStoredHtml('<img src="'.$stale.'">');

    expect($out)->toBe('<img src="'.e(Storage::disk('s3')->temporaryUrl($key, now()->addDay())).'">');
    expect($out)->not->toContain('stale');
});

it('CDN-08: a key with a trailing newline is not treated as a catalogue key', function (): void {
    catalogCdnOn();

    expect(CatalogImageUrl::for('products/gallery/'.CATALOG_CDN_TEST_UUID.".jpg\n"))
        ->not->toStartWith('https://cdn.example.com');
});

it('CDN-09: a stored URL that continues past the key (path traversal) is left as stored', function (): void {
    catalogCdnOn();
    $html = '<img src="https://arovolife-prod.s3.ap-south-1.amazonaws.com/products/inline/'
        .CATALOG_CDN_TEST_UUID.'.png/../../user_5/id-photo/x.jpg">';

    expect(CatalogImageUrl::rewriteStoredHtml($html))->toBe($html);
});
