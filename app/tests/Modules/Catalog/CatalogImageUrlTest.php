<?php

declare(strict_types=1);

use App\Modules\Catalog\Models\Banner;
use App\Modules\Catalog\Models\ProductCategory;
use App\Modules\Catalog\Models\ProductImage;
use App\Modules\Catalog\Support\CatalogImageUrl;
use Illuminate\Support\Facades\Storage;

/**
 * Catalogue images are served from the local `catalog` disk at a stable
 * /storage/catalog/<key> URL; every other key keeps the 1-day signed S3 URL.
 */
const CATALOG_IMG_TEST_UUID = '0f8fad5b-d9cb-469f-a165-70867728950e';

beforeEach(function (): void {
    Storage::fake('s3');
    config([
        'filesystems.disks.s3.bucket' => 'arovolife-prod',
        'app.url' => 'https://shop.example.com',
        'filesystems.disks.catalog.url' => 'https://shop.example.com/storage/catalog',
    ]);
});

function catalogBase(): string
{
    return 'https://shop.example.com/storage/catalog/';
}

it('IMG-01: each catalogue prefix gets a stable /storage/catalog URL', function (string $key): void {
    // Real disk, not faked: url() does no I/O, so this pins the true URL shape.
    expect(CatalogImageUrl::for($key))->toBe(catalogBase().$key);
})->with([
    'gallery' => 'products/gallery/'.CATALOG_IMG_TEST_UUID.'.jpg',
    'inline' => 'products/inline/'.CATALOG_IMG_TEST_UUID.'.png',
    'category tile' => 'categories/'.CATALOG_IMG_TEST_UUID.'.jpg',
    'category banner' => 'category-banners/'.CATALOG_IMG_TEST_UUID.'.webp',
    'home banner' => 'banners/'.CATALOG_IMG_TEST_UUID.'.jpeg',
]);

it('IMG-02: private and malformed keys keep a signed S3 URL, never a catalogue URL', function (string $key): void {
    $url = CatalogImageUrl::for($key);

    expect($url)->not->toContain('/storage/catalog/');
    expect($url)->toBe(Storage::disk('s3')->temporaryUrl($key, now()->addDay()));
})->with([
    'ID photo' => 'user_5/id-photo/'.CATALOG_IMG_TEST_UUID.'.jpg',
    'KYC scan' => 'kyc/reg_abc/pan.jpg',
    'grievance' => 'grievance/12/'.CATALOG_IMG_TEST_UUID.'.pdf',
    'nested traversal' => 'products/gallery/../../kyc/pan.jpg',
    'wrong type' => 'products/gallery/'.CATALOG_IMG_TEST_UUID.'.pdf',
    'trailing newline' => 'products/gallery/'.CATALOG_IMG_TEST_UUID.".jpg\n",
]);

it('IMG-03: the model accessors route through the catalogue URL', function (): void {
    $image = new ProductImage(['s3_key' => 'products/gallery/'.CATALOG_IMG_TEST_UUID.'.jpg']);
    $category = new ProductCategory(['image_s3_key' => 'categories/'.CATALOG_IMG_TEST_UUID.'.jpg']);
    $category->banner_s3_key = 'category-banners/'.CATALOG_IMG_TEST_UUID.'.jpg';
    $banner = new Banner;
    $banner->s3_key = 'banners/'.CATALOG_IMG_TEST_UUID.'.jpg';

    expect($image->url())->toBe(catalogBase().'products/gallery/'.CATALOG_IMG_TEST_UUID.'.jpg');
    expect($category->imageUrl())->toBe(catalogBase().'categories/'.CATALOG_IMG_TEST_UUID.'.jpg');
    expect($category->bannerUrl())->toBe(catalogBase().'category-banners/'.CATALOG_IMG_TEST_UUID.'.jpg');
    expect($banner->url())->toBe(catalogBase().'banners/'.CATALOG_IMG_TEST_UUID.'.jpg');
});

it('IMG-04: stored HTML has expired signed S3 URLs re-pointed at the catalogue URL', function (): void {
    $signed = 'https://arovolife-prod.s3.ap-south-1.amazonaws.com/products/inline/'.CATALOG_IMG_TEST_UUID
        .'.png?X-Amz-Algorithm=AWS4-HMAC-SHA256&amp;X-Amz-Expires=86400&amp;X-Amz-Signature=abc123';

    $out = CatalogImageUrl::rewriteStoredHtml('<p>Hi</p><img src="'.$signed.'" alt="x">');

    expect($out)->toBe('<p>Hi</p><img src="'.catalogBase().'products/inline/'.CATALOG_IMG_TEST_UUID.'.png" alt="x">');
});

it('IMG-05: a catalogue URL saved on another host is re-pointed at this host', function (): void {
    $key = 'products/inline/'.CATALOG_IMG_TEST_UUID.'.png';
    $html = '<img src="https://staging.example.net/storage/catalog/'.$key.'"><img src="http://localhost:8084/storage/catalog/'.$key.'">';

    expect(CatalogImageUrl::rewriteStoredHtml($html))
        ->toBe('<img src="'.catalogBase().$key.'"><img src="'.catalogBase().$key.'">');
});

it('IMG-06: the rewrite leaves external URLs, other buckets and private prefixes alone', function (): void {
    $html = '<img src="https://example.com/a.png">'
        .'<img src="https://other-bucket.s3.ap-south-1.amazonaws.com/products/inline/'.CATALOG_IMG_TEST_UUID.'.png?X-Amz-Signature=1">'
        .'<img src="https://arovolife-prod.s3.ap-south-1.amazonaws.com/kyc/reg_1/pan.jpg?X-Amz-Signature=1">'
        .'<img src="https://shop.example.com/storage/catalog/kyc/reg_1/pan.jpg">';

    expect(CatalogImageUrl::rewriteStoredHtml($html))->toBe($html);
});

it('IMG-07: a stored URL that continues past the key (path traversal) is left as stored', function (): void {
    $html = '<img src="https://arovolife-prod.s3.ap-south-1.amazonaws.com/products/inline/'
        .CATALOG_IMG_TEST_UUID.'.png/../../user_5/id-photo/x.jpg">'
        .'<img src="https://evil.example/storage/catalog/products/inline/'.CATALOG_IMG_TEST_UUID.'.png/../../../.env">';

    expect(CatalogImageUrl::rewriteStoredHtml($html))->toBe($html);
});
