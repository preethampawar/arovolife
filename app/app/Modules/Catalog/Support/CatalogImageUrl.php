<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Support;

use Illuminate\Support\Facades\Storage;

/**
 * The one place a catalogue image key becomes a browser URL.
 *
 * Catalogue images (product gallery and inline, category tiles and banners,
 * home banners) live on the local `catalog` disk, which the web server serves
 * directly under /storage/catalog. Their URLs are stable — cacheable, indexable
 * and safe to embed in stored HTML. Any key outside the catalogue allow-list
 * falls back to a 1-day signed S3 URL; KYC scans, ID photos and every other
 * private upload are never written to the `catalog` disk.
 */
final class CatalogImageUrl
{
    /** The disk catalogue images are stored on and served from. */
    public const DISK = 'catalog';

    /**
     * A catalogue key: allowed prefix, then a single generated file name.
     */
    public const KEY_PATTERN = '(?:products/(?:gallery|inline)|categories|category-banners|banners)/[A-Za-z0-9-]+\.(?:jpe?g|png|webp)';

    public static function isCatalogKey(string $key): bool
    {
        return preg_match('#\A'.self::KEY_PATTERN.'\z#', $key) === 1;
    }

    public static function for(string $key): string
    {
        if (self::isCatalogKey($key)) {
            return Storage::disk(self::DISK)->url($key);
        }

        return Storage::disk('s3')->temporaryUrl($key, now()->addDay());
    }

    /**
     * Re-point image URLs saved inside stored HTML (product description and
     * attribute bodies) at the current URL for the same key. Two shapes are
     * rewritten:
     *   - S3 URLs on this app's own bucket — the editor used to save the 1-day
     *     signed URL the upload returned, which stops working a day later;
     *   - `/storage/catalog/<key>` URLs on any host — so stored links survive
     *     a domain change or a database copied between environments.
     * Only catalogue keys are touched, and only when the whole URL is the key —
     * anything trailing (e.g. `/../`) leaves the URL as stored.
     */
    public static function rewriteStoredHtml(string $html): string
    {
        if ($html === '' || (! str_contains($html, 'amazonaws.com') && ! str_contains($html, '/storage/catalog/'))) {
            return $html;
        }

        $end = '(?:\?[^"\'\s<>]*)?(?=["\'\s<>]|\z)';
        $repoint = static fn (array $m): string => e(self::for($m[1]));

        $bucket = (string) config('filesystems.disks.s3.bucket', '');
        if ($bucket !== '') {
            $html = (string) preg_replace_callback(
                '#https://'.preg_quote($bucket, '#').'\.s3(?:[.-][a-z0-9-]+)?\.amazonaws\.com/('.self::KEY_PATTERN.')'.$end.'#',
                $repoint,
                $html,
            );
        }

        return (string) preg_replace_callback(
            '#https?://[A-Za-z0-9.-]+(?::\d+)?/storage/catalog/('.self::KEY_PATTERN.')'.$end.'#',
            $repoint,
            $html,
        );
    }
}
