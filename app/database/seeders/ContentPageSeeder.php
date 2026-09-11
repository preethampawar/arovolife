<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Modules\Content\Models\ContentPage;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Seeds the five public content pages: ethics, terms, grievance, compensation, privacy.
 *
 * Four of them are published. `compensation` is seeded as a draft and left
 * unpublished — see {@see ContentPageSeeder::HELD_SLUGS} for why, and for the
 * one command that publishes it.
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
            'slug' => 'compensation',
            'title' => 'Compensation Plan Disclosure',
            'meta_description' => 'How arovolife bonuses are calculated — Business Volume, the Genos Sales Bonus slabs and pooled score value, the Mentorship Bonus point value, eligibility gates, deductions and caps. Published under §6.2 of the Direct Seller Agreement.',
        ],
        [
            'slug' => 'privacy',
            'title' => 'Privacy Policy',
            'meta_description' => 'How arovolife collects, uses, stores, shares and protects personal data under the DPDP Act 2023 — including PAN, Aadhaar and KYC handling.',
        ],
    ];

    /**
     * Slugs this seeder writes but never publishes.
     *
     * R-75 holds the Wednesday-to-Tuesday payout week and the 8th-of-month
     * cadence in `compensation.md` until the DSA §6.2 30-day material-amendment
     * notice has run. `run()` is the blanket path — `db:seed`, `platform:reset`,
     * the test bootstrap — and publishing an un-notified plan amendment must
     * not be a side effect of any of them. The page is still seeded, as a
     * draft, so the copy is in the environment and one named command publishes
     * it: `php artisan content:publish compensation`.
     *
     * @var list<string>
     */
    public const HELD_SLUGS = ['compensation'];

    public function run(): void
    {
        $published = $this->publish(array_values(array_diff(self::slugs(), self::HELD_SLUGS)));
        $held = $this->seedAsDraft(self::HELD_SLUGS);

        $this->command->info('Seeded '.($published + $held).' content pages.');
        $this->command->warn(
            'Held unpublished (R-75, DSA §6.2 notice): '.implode(', ', self::HELD_SLUGS).'. '
            .'Publish with: php artisan content:publish '.implode(' ', self::HELD_SLUGS)
        );
    }

    /**
     * Republish some or all of the policy pages from their markdown source.
     *
     * Split out of run() because republishing *all* of them is rarely what a
     * deploy wants. Each page carries its own publication gate — the payout
     * week in `compensation.md` is held by R-75 until the DSA §6.2 30-day
     * notice has run — so a bare `db:seed --class=ContentPageSeeder` can
     * publish an un-notified material amendment as a side effect of fixing an
     * unrelated page. `content:publish <slug>` exists so that a deploy can
     * name what it means to publish.
     *
     * @param  list<string>|null  $slugs  null republishes every page
     * @return int the number of pages written
     */
    public function publish(?array $slugs = null): int
    {
        $now = now();
        $count = 0;

        foreach (self::PAGES as $meta) {
            if ($slugs !== null && ! in_array($meta['slug'], $slugs, true)) {
                continue;
            }

            ContentPage::updateOrCreate(
                ['slug' => $meta['slug']],
                [
                    'title' => $meta['title'],
                    'meta_description' => $meta['meta_description'],
                    'body' => $this->renderBody($meta['slug']),
                    'status' => ContentPage::STATUS_PUBLISHED,
                    'published_at' => $now,
                ],
            );

            $count++;
        }

        return $count;
    }

    /**
     * Create the named pages as drafts where they do not exist, and leave
     * them exactly as they are where they do.
     *
     * Deliberately not `updateOrCreate`. An environment in which the §6.2
     * notice has run and the page is legitimately published must not be
     * demoted to a draft by an unrelated re-seed — taking a statutory
     * disclosure offline is the other way to get this wrong, and registration
     * depends on this page being published (ConsentDocuments) — and its
     * body must not be silently rewritten from a source that has moved on
     * since the notice was served.
     *
     * @param  list<string>  $slugs
     * @return int the number of pages created
     */
    private function seedAsDraft(array $slugs): int
    {
        $created = 0;

        foreach (self::PAGES as $meta) {
            if (! in_array($meta['slug'], $slugs, true)) {
                continue;
            }

            if (ContentPage::query()->where('slug', $meta['slug'])->exists()) {
                continue;
            }

            ContentPage::create([
                'slug' => $meta['slug'],
                'title' => $meta['title'],
                'meta_description' => $meta['meta_description'],
                'body' => $this->renderBody($meta['slug']),
                'status' => ContentPage::STATUS_DRAFT,
                'published_at' => null,
            ]);

            $created++;
        }

        return $created;
    }

    /** @return list<string> every slug this seeder knows how to publish */
    public static function slugs(): array
    {
        return array_map(static fn (array $meta): string => $meta['slug'], self::PAGES);
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
