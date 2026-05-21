<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Modules\Content\Models\ContentPage;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Seeds the four public content pages: ethics, terms, grievance, privacy.
 *
 * Source of truth for each page is a Markdown file under
 * `database/seeders/content/<slug>.md`. The seeder reads each file, strips
 * any leading HTML comment used for the "DRAFT — LEGAL REVIEW REQUIRED"
 * banner (so the banner does not render on the public page), pre-renders
 * the Markdown to HTML at seed time, and stores the HTML in `content_pages.body`.
 *
 * Why pre-render at seed time:
 *  - The public view (`resources/views/content/show.blade.php`) prints
 *    `{!! $page->body !!}` and only ships styles for HTML elements
 *    (h2, h3, p, ul, li, blockquote, …). Storing raw Markdown there
 *    would render literal `#` and `*` to end-users.
 *  - The Admin content editor (Phase 1 stub) accepts HTML directly; storing
 *    HTML keeps both surfaces consistent until a richer Markdown editor lands.
 *  - Rendering inside the seeder is idempotent — re-running
 *    `platform:reset --force` replaces the body with the freshly rendered
 *    HTML from the current Markdown source. Editors will be added in
 *    Phase 2; until then, this seeder + the markdown files are the
 *    authoritative source.
 */
final class ContentPageSeeder extends Seeder
{
    /**
     * @var list<array{slug: string, title: string, meta_description: string}>
     */
    private const PAGES = [
        [
            'slug' => 'ethics',
            'title' => 'Code of Ethics',
            'meta_description' => 'Ethical standards every arovolife Distributor and administrator agrees to uphold — recruitment ethics, customer-facing conduct, tree-placement integrity, and sanctions for breach.',
        ],
        [
            'slug' => 'terms',
            'title' => 'Direct Seller Agreement & Terms of Service',
            'meta_description' => 'The master agreement between Arovolife Private Limited and its Direct Sellers, covering registration, cooling-off, compensation framework, KYC, tax and termination.',
        ],
        [
            'slug' => 'grievance',
            'title' => 'Grievance Redressal',
            'meta_description' => 'How to file a complaint with arovolife, the SLA we commit to, and the escalation matrix up to the Central Consumer Protection Authority and the Data Protection Board of India.',
        ],
        [
            'slug' => 'privacy',
            'title' => 'Privacy Policy',
            'meta_description' => 'How arovolife collects, uses, stores, shares and protects personal data under the DPDP Act 2023 — including PAN, Aadhaar and KYC handling.',
        ],
    ];

    public function run(): void
    {
        $now = now();
        $count = 0;

        foreach (self::PAGES as $meta) {
            $body = $this->renderBody($meta['slug']);

            ContentPage::updateOrCreate(
                ['slug' => $meta['slug']],
                [
                    'title' => $meta['title'],
                    'meta_description' => $meta['meta_description'],
                    'body' => $body,
                    'status' => ContentPage::STATUS_PUBLISHED,
                    'published_at' => $now,
                ],
            );

            $count++;
        }

        $this->command->info('Seeded '.$count.' content pages.');
    }

    /**
     * Read the markdown source for the given slug, strip the legal-review
     * banner comment, and pre-render to HTML.
     */
    private function renderBody(string $slug): string
    {
        $path = __DIR__.'/content/'.$slug.'.md';

        if (! is_file($path)) {
            throw new RuntimeException('Missing content markdown source: '.$path);
        }

        $markdown = file_get_contents($path);

        if ($markdown === false) {
            throw new RuntimeException('Failed to read content markdown source: '.$path);
        }

        // Strip the leading HTML comment used as the "DRAFT — LEGAL REVIEW
        // REQUIRED" banner from the source file. The banner is for engineers
        // and reviewers, not for end-users — it must not render on the page.
        $markdown = preg_replace('/^<!--.*?-->\s*/s', '', $markdown) ?? $markdown;

        return Str::markdown(trim($markdown));
    }
}
