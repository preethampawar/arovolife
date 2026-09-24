<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Support;

use Illuminate\Support\Facades\Storage;

/**
 * The one place a catalogue image key becomes a browser URL.
 *
 * With `arovolife.media.catalog_cdn_url` set (production: the CloudFront
 * distribution in front of the private bucket), a catalogue key gets a stable,
 * cacheable CDN URL — indexable by search engines and safe to embed in stored
 * HTML. Without it, or for any key outside the catalogue allow-list, the key
 * gets the same 1-day signed S3 URL as before, so an empty setting is the
 * killswitch.
 *
 * The allow-list is one of three layers that must change together (see
 * docs/plans/2026-09-24-cloudfront-catalog-cdn.md): the bucket policy lets
 * CloudFront read only these prefixes, and a CloudFront function 404s every
 * other path. KYC scans, ID photos and every other private upload live under
 * other prefixes and are never given a CDN URL here.
 */
final class CatalogImageUrl
{
    /**
     * A catalogue key: allowed prefix, then a single generated file name.
     * Must stay identical to the CloudFront viewer function's regex.
     */
    private const KEY_PATTERN = '(?:products/(?:gallery|inline)|categories|category-banners|banners)/[A-Za-z0-9-]+\.(?:jpe?g|png|webp)';

    public static function for(string $key): string
    {
        $cdn = rtrim((string) config('arovolife.media.catalog_cdn_url', ''), '/');

        if ($cdn !== '' && preg_match('#\A'.self::KEY_PATTERN.'\z#', $key) === 1) {
            return $cdn.'/'.$key;
        }

        return Storage::disk('s3')->temporaryUrl($key, now()->addDay());
    }

    /**
     * Re-point S3 URLs saved inside stored HTML (product description and
     * attribute bodies) at a current URL for the same key. The rich-text editor
     * saved whatever URL the upload returned — a signed URL that stops working
     * a day later — so the stored HTML cannot be trusted to hold a live link.
     * Only URLs on this app's own bucket for a catalogue key are touched, and
     * only when the whole URL is the key — anything trailing (e.g. `/../`)
     * leaves the URL as stored.
     */
    public static function rewriteStoredHtml(string $html): string
    {
        $bucket = (string) config('filesystems.disks.s3.bucket', '');
        if ($html === '' || $bucket === '' || ! str_contains($html, 'amazonaws.com')) {
            return $html;
        }

        $pattern = '#https://'.preg_quote($bucket, '#').'\.s3(?:[.-][a-z0-9-]+)?\.amazonaws\.com/('
            .self::KEY_PATTERN.')(?:\?[^"\'\s<>]*)?(?=["\'\s<>]|\z)#';

        return (string) preg_replace_callback(
            $pattern,
            static fn (array $m): string => e(self::for($m[1])),
            $html,
        );
    }
}
