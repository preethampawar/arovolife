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
    /**
     * Phrases that imply a future income.
     *
     * The second group was added on 2026-08-17 after the C-02 sign-off found
     * "Income amounts shown in any plan illustration represent maximum
     * achievable levels based on historical top performer data" inside the
     * registration consent step. None of the original phrases would have
     * caught it: the pattern is not a promise of a number, it is a
     * characterisation of the plan's numbers by reference to what the best
     * earners have made, which is the more common way this rule gets broken.
     *
     * @var array<int, string>
     */
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
        'top performer',
        'top earner',
        'maximum achievable',
        'typical results',
        'plan illustration',
        'income illustration',
        'potential earnings',
        'earning potential',
        'expected income',
        'average income',
        'average earnings',
    ];

    public function test_public_blade_templates_have_no_income_projection_copy(): void
    {
        // Every surface a prospect or a distributor reads. `registration` is
        // the one C-02 is actually named after and it was not scanned until
        // 2026-08-17 — the wizard is the most consequential copy on the
        // platform, because the applicant signs it.
        $roots = [
            base_path('resources/views/landing'),
            base_path('resources/views/shop'),
            base_path('resources/views/content'),
            base_path('resources/views/layouts'),
            base_path('resources/views/registration'),
            base_path('resources/views/public'),
            base_path('resources/views/income'),
            base_path('resources/views/dashboard'),
            base_path('resources/views/membership'),
            base_path('resources/views/compliance'),
            base_path('resources/views/tree'),
            base_path('resources/views/emails'),
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

    /**
     * Reviewed exceptions: basename => [phrase => why it is allowed there].
     *
     * Deliberately an explicit list rather than negation-detection. A scanner
     * clever enough to see that "not imply guaranteed or assured income" is a
     * prohibition is also clever enough to be fooled, and the whole value of
     * this test is that it cannot be talked round. Every entry here is a
     * sentence somebody read and signed off; adding one is a decision, not a
     * workaround.
     *
     * @var array<string, array<string, string>>
     */
    private array $reviewedExceptions = [
        'step9-consent.blade.php' => [
            'assured income' => 'The Code of Ethics undertaking the applicant gives: they promise NOT to '
                .'imply guaranteed or assured income to prospects. Removing the phrase would remove the duty.',
        ],
    ];

    /** @param array<int, string> $found */
    private function scan(string $dir, array &$found): void
    {
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir));
        foreach ($it as $file) {
            if (! $file->isFile() || ! str_ends_with($file->getFilename(), '.blade.php')) {
                continue;
            }

            $allowed = $this->reviewedExceptions[$file->getFilename()] ?? [];
            $contents = strtolower((string) file_get_contents($file->getPathname()));

            foreach ($this->bannedPhrases as $phrase) {
                if (str_contains($contents, $phrase) && ! isset($allowed[$phrase])) {
                    $found[] = "  - {$file->getPathname()}: found \"{$phrase}\"";
                }
            }
        }
    }
}
