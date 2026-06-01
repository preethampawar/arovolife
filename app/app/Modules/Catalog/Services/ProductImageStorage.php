<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Services;

use App\Modules\Catalog\Models\ProductImage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Stores catalog product images on the public `s3` disk and records a
 * {@see ProductImage} row. Mirrors the EXIF-strip + canonical-extension
 * behaviour of {@see \App\Modules\Identity\Services\IdPhotoStorage}, but
 * for the PUBLIC disk — catalog images are not PII (unlike KYC documents),
 * so they are web-served directly via their S3 URL.
 *
 * Used for two kinds:
 *   - 'gallery' — product gallery images (attached to a product)
 *   - 'inline'  — images embedded in the WYSIWYG description via Trix
 *                 (product_id may be null until the product is saved)
 *
 * Storage key: `products/{kind}/{uuid}.{ext}` on the `s3` disk.
 */
final class ProductImageStorage
{
    /**
     * Store an uploaded image and return its persisted ProductImage row.
     */
    public function store(UploadedFile $file, string $kind, ?int $productId = null, ?string $alt = null): ProductImage
    {
        $ext = strtolower($file->extension() ?: 'jpg');
        if ($ext === 'jpeg') {
            $ext = 'jpg';
        }

        $key = sprintf('products/%s/%s.%s', $kind, Str::uuid()->toString(), $ext);

        $cleaned = $this->stripExif($file, $ext);
        if ($cleaned === null) {
            throw new \RuntimeException('We could not process that image. Please upload a valid JPG or PNG.');
        }

        Storage::disk('s3')->put($key, $cleaned, ['visibility' => 'public']);

        $nextSort = $productId !== null
            ? (int) ProductImage::query()->where('product_id', $productId)->where('kind', $kind)->max('sort') + 1
            : 0;

        return ProductImage::create([
            'product_id' => $productId,
            's3_key' => $key,
            'alt' => $alt,
            'sort' => $nextSort,
            'kind' => $kind,
        ]);
    }

    /**
     * Store an image on the public `s3` disk WITHOUT creating a ProductImage
     * row, returning the object key. Used for the category tile image, whose
     * key is held directly on the category row. EXIF-stripped like the rest.
     */
    public function putRaw(UploadedFile $file, string $prefix): string
    {
        $ext = strtolower($file->extension() ?: 'jpg');
        if ($ext === 'jpeg') {
            $ext = 'jpg';
        }

        $cleaned = $this->stripExif($file, $ext);
        if ($cleaned === null) {
            throw new \RuntimeException('We could not process that image. Please upload a valid JPG or PNG.');
        }

        $key = sprintf('%s/%s.%s', rtrim($prefix, '/'), Str::uuid()->toString(), $ext);
        Storage::disk('s3')->put($key, $cleaned, ['visibility' => 'public']);

        return $key;
    }

    /**
     * Best-effort delete of a raw S3 object (no DB row).
     */
    public function deleteKey(?string $key): void
    {
        if ($key === null) {
            return;
        }
        try {
            Storage::disk('s3')->delete($key);
        } catch (\Throwable) {
            // Janitor reconciles.
        }
    }

    /**
     * Delete a product image (S3 object + row). Best-effort on the S3 delete.
     */
    public function delete(ProductImage $image): void
    {
        $key = $image->s3_key;
        $image->delete();

        try {
            Storage::disk('s3')->delete($key);
        } catch (\Throwable) {
            // Orphan reconciled by the janitor; DB is the source of truth.
        }
    }

    /**
     * Decode → re-encode via GD to drop all EXIF metadata. GD does not write
     * EXIF on output, so decode+re-encode is the simplest strip. Mirrors
     * IdPhotoStorage so behaviour is identical across upload surfaces.
     */
    private function stripExif(UploadedFile $file, string $ext): ?string
    {
        $bytes = (string) $file->get();
        $image = @imagecreatefromstring($bytes);
        if ($image === false) {
            return null;
        }

        try {
            ob_start();
            match ($ext) {
                'png' => imagepng($image, null, 6),
                default => imagejpeg($image, null, 90),
            };
            $out = ob_get_clean();

            return is_string($out) ? $out : null;
        } finally {
            imagedestroy($image);
        }
    }
}
