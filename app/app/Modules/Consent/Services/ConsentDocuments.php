<?php

declare(strict_types=1);

namespace App\Modules\Consent\Services;

use App\Modules\Content\Models\ContentPage;
use RuntimeException;

/**
 * Resolves what a person is actually agreeing to (R-51).
 *
 * `consents.doc_hash_sha256` exists to prove **which text** somebody accepted.
 * It was populated from four hardcoded hashes of Phase-1 stub strings — not of
 * any published document — while the recorded version read `1.0.0` for all
 * four and the live documents were dated 2026-05-21 and 2026-08-07. The
 * electronic record could therefore evidence neither the text nor the version,
 * which is the one job it has under IT Act §10A and T&C §3.I Step 9.
 *
 * The hash is now taken over the **published body of the content page the
 * public URL serves**, so the record ties to the document a regulator would be
 * shown. Change the page and the next acceptance records a different hash,
 * which is exactly the behaviour a versioned consent needs.
 *
 * The version is derived from that same page rather than declared separately:
 * two sources for "which version did they sign" is how they came to disagree.
 */
final class ConsentDocuments
{
    /**
     * Consent document type => the content page slug that publishes it.
     *
     * @var array<string, string>
     */
    private const PAGES = [
        'tnc' => 'terms',
        'ethics' => 'ethics',
        'plan' => 'compensation',
        'privacy' => 'privacy',
    ];

    /** @var array<string, array{version: string, hash: string, page_id: int}>|null */
    private ?array $cache = null;

    /**
     * The four documents a joiner accepts, each with the version and hash of
     * the text currently published.
     *
     * @return array<string, array{version: string, hash: string, page_id: int}>
     */
    public function all(): array
    {
        if ($this->cache !== null) {
            return $this->cache;
        }

        $resolved = [];

        foreach (self::PAGES as $type => $slug) {
            $page = ContentPage::query()
                ->where('slug', $slug)
                ->where('status', 'published')
                ->first();

            if ($page === null) {
                // Refusing here is deliberate. Falling back to a placeholder
                // would let registration continue while recording a consent
                // that points at nothing — which is the defect this class
                // exists to fix, reintroduced as a silent default.
                throw new RuntimeException(
                    "Cannot record consent: the '{$slug}' content page is not published. ".
                    'Run the content seeder before taking registrations.'
                );
            }

            $resolved[$type] = [
                // Dated versions, because that is how the documents themselves
                // are labelled and how a §6.2 notice refers to them. A
                // semantic version would need somebody to remember to bump it.
                'version' => $page->updated_at->format('Y-m-d'),
                'hash' => hash('sha256', (string) $page->body),
                'page_id' => (int) $page->id,
            ];
        }

        return $this->cache = $resolved;
    }

    /** @return array{version: string, hash: string, page_id: int} */
    public function for(string $type): array
    {
        $documents = $this->all();

        if (! isset($documents[$type])) {
            throw new RuntimeException("Unknown consent document type: {$type}");
        }

        return $documents[$type];
    }

    /**
     * The public path a joiner can read the document at, so the wizard can
     * link to the thing it is asking them to accept rather than paraphrase it.
     */
    public function url(string $type): string
    {
        return '/p/'.(self::PAGES[$type] ?? $type);
    }

    /** @return array<int, string> */
    public static function types(): array
    {
        return array_keys(self::PAGES);
    }
}
