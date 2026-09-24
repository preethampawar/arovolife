<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Console\Commands;

use App\Modules\Catalog\Support\CatalogImageUrl;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * One-off move of catalogue images from the private S3 bucket to the local
 * `catalog` disk they are now served from (2026-09-25). Copies every
 * catalogue key the database references that the local disk does not have
 * yet. Idempotent, and never deletes anything from S3 — removing the S3
 * copies is a separate, deliberate step.
 */
final class CopyCatalogImagesFromS3Command extends Command
{
    protected $signature = 'catalog:copy-images-from-s3
        {--dry-run : List the keys that would be copied without copying}';

    protected $description = 'Copy catalogue images referenced in the database from S3 to the local catalog disk';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $s3 = Storage::disk('s3');
        $local = Storage::disk(CatalogImageUrl::DISK);

        $copied = $present = $missing = 0;

        foreach ($this->referencedKeys() as $key) {
            if ($local->exists($key)) {
                $present++;

                continue;
            }

            if ($dryRun) {
                $this->line("would copy {$key}");
                $copied++;

                continue;
            }

            // The s3 disk is throw=false: a missing object is a null stream.
            // Never write it — a 0-byte file would count as present forever.
            $stream = $s3->readStream($key);
            if (! is_resource($stream)) {
                $this->warn("missing on S3: {$key}");
                $missing++;

                continue;
            }

            try {
                if ($local->writeStream($key, $stream) === false) {
                    $this->error("could not write {$key}");

                    return self::FAILURE;
                }
            } finally {
                fclose($stream);
            }

            $copied++;
        }

        $this->info(sprintf(
            '%s%d copied, %d already present, %d missing on S3.',
            $dryRun ? 'DRY RUN — ' : '',
            $copied,
            $present,
            $missing,
        ));

        return self::SUCCESS;
    }

    /**
     * Every distinct catalogue key held in the database.
     *
     * @return list<string>
     */
    private function referencedKeys(): array
    {
        $keys = DB::table('product_images')->whereNotNull('s3_key')->pluck('s3_key')
            ->merge(DB::table('product_categories')->whereNotNull('image_s3_key')->pluck('image_s3_key'))
            ->merge(DB::table('product_categories')->whereNotNull('banner_s3_key')->pluck('banner_s3_key'))
            ->merge(DB::table('banners')->whereNotNull('s3_key')->pluck('s3_key'));

        return array_values($keys
            ->map(static fn (mixed $key): string => (string) $key)
            ->filter(static fn (string $key): bool => CatalogImageUrl::isCatalogKey($key))
            ->unique()
            ->sort()
            ->all());
    }
}
