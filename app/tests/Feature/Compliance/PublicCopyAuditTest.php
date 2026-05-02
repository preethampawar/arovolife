<?php

declare(strict_types=1);

namespace Tests\Feature\Compliance;

use Tests\TestCase;

/**
 * DSR 2021 Rule 5(1)(d) forbids income projections on public UI.
 * We fail the build if any public-facing Blade template contains
 * banned phrases that imply future earnings.
 */
final class PublicCopyAuditTest extends TestCase
{
    /** @var array<int, string> */
    private array $bannedPhrases = [
        'guaranteed income',
        'assured income',
        'earn upto',
        'earn up to',
        'earn per day',
        'earn per month',
        'earn every month',
        'monthly income guaranteed',
        'passive income',
        'unlimited earnings',
        'become rich',
        'get rich',
    ];

    public function test_public_blade_templates_have_no_income_projection_copy(): void
    {
        $roots = [
            base_path('resources/views/landing'),
            base_path('resources/views/shop'),
            base_path('resources/views/content'),
            base_path('resources/views/layouts'),
        ];

        $found = [];
        foreach ($roots as $root) {
            if (! is_dir($root)) {
                continue;
            }
            $this->scan($root, $found);
        }

        $this->assertEmpty(
            $found,
            "Banned phrases found in public copy:\n".implode("\n", $found),
        );
    }

    /** @param array<int, string> $found */
    private function scan(string $dir, array &$found): void
    {
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir));
        foreach ($it as $file) {
            if (! $file->isFile() || ! str_ends_with($file->getFilename(), '.blade.php')) {
                continue;
            }
            $contents = strtolower((string) file_get_contents($file->getPathname()));
            foreach ($this->bannedPhrases as $phrase) {
                if (str_contains($contents, $phrase)) {
                    $found[] = "  - {$file->getPathname()}: found \"{$phrase}\"";
                }
            }
        }
    }
}
