<?php

declare(strict_types=1);

namespace App\Modules\Content\Console\Commands;

use App\Modules\Compliance\Models\AuditLog;
use App\Modules\Compliance\Support\AuditDigests;
use App\Modules\Content\Models\ContentPage;
use Database\Seeders\ContentPageSeeder;
use Illuminate\Console\Command;

/**
 * Republish one named policy page from its markdown source.
 *
 * `db:seed --class=ContentPageSeeder` rewrites the body of *every* policy page,
 * and the pages do not share a publication gate: the payout-week wording in
 * `compensation.md` is held by R-75 until the plan-change notice is ready to start (R-123),
 * while a privacy-notice amendment may need to go out on its own. Publishing
 * all five to ship one is how an un-notified material amendment reaches
 * distributors as a side effect of an unrelated fix.
 *
 * So a deploy names the page it means to publish. Each run is audit-logged,
 * because publishing a policy page is a change to what the company has told
 * its distributors.
 *
 * `--if-unpublished` publishes only the named pages that are missing or still
 * a draft, and never rewrites a published one. `app:deploy` runs it for the
 * consent pages: registration refuses while any of them is unpublished
 * (ConsentDocuments), and a freshly seeded environment holds `compensation`
 * as a draft — while an environment whose page is already live keeps its
 * notified text.
 *
 * A page an administrator has changed in the portal is kept, not replaced:
 * from 2026-10-26 the plan page is edited there, so that copy is the master
 * (R-125). So is the published plan page even before its first portal edit:
 * a plan change there needs a second person's approval, and this command
 * would otherwise be the way round it. `--overwrite-portal-edits` replaces
 * either from the markdown anyway — a one-person developer path, recorded
 * with each page's before and after digest.
 */
final class PublishContentPageCommand extends Command
{
    protected $signature = 'content:publish {slug* : One or more page slugs to republish}
        {--if-unpublished : Only publish pages that are missing or not yet published}
        {--overwrite-portal-edits : Also replace pages an administrator has changed in the portal}';

    protected $description = 'Republish named policy pages from their markdown source.';

    public function handle(): int
    {
        /** @var list<string> $slugs */
        $slugs = array_values((array) $this->argument('slug'));

        $known = ContentPageSeeder::slugs();
        $unknown = array_diff($slugs, $known);

        if ($unknown !== []) {
            $this->error('Unknown page(s): '.implode(', ', $unknown).'. Known: '.implode(', ', $known).'.');

            return self::FAILURE;
        }

        if ($this->option('if-unpublished')) {
            $published = ContentPage::query()
                ->whereIn('slug', $slugs)
                ->where('status', ContentPage::STATUS_PUBLISHED)
                ->pluck('slug')
                ->all();
            $slugs = array_values(array_diff($slugs, $published));

            if ($slugs === []) {
                $this->info('All named pages are already published; nothing written.');

                return self::SUCCESS;
            }
        }

        $overwrite = (bool) $this->option('overwrite-portal-edits');
        $kept = $overwrite ? [] : array_values(array_filter($slugs, ContentPageSeeder::keptFromSource(...)));

        if ($kept !== []) {
            $this->warn('Kept the portal version of: '.implode(', ', $kept).'. '
                .'These are changed in the admin editor (plan changes with a second person\'s approval), so that copy is the master. '
                .'Pass --overwrite-portal-edits to replace them from the markdown source.');
        }

        $written = array_values(array_diff($slugs, $kept));
        $before = $this->digests($written);

        $seeder = new ContentPageSeeder;
        $count = $seeder->publish($slugs, $overwrite);

        $after = $this->digests($written);
        $digests = [];

        foreach ($written as $slug) {
            $digests[$slug] = ['before' => $before[$slug] ?? null, 'after' => $after[$slug] ?? null];
        }

        AuditLog::create([
            'actor_id' => null,
            'action' => 'content_page.republished',
            'subject_type' => 'content_page',
            'subject_id' => null,
            'details' => ['slugs' => $slugs, 'pages_written' => $count, 'portal_versions_kept' => $kept, 'overwrote_portal_edits' => $overwrite, 'digests' => $digests],
        ]);

        $this->info('Republished '.$count.' page(s): '.implode(', ', $written).'.');

        return self::SUCCESS;
    }

    /**
     * Each existing page's digest, hex, keyed by slug.
     *
     * @param  list<string>  $slugs
     * @return array<string, string>
     */
    private function digests(array $slugs): array
    {
        return ContentPage::query()
            ->whereIn('slug', $slugs)
            ->get()
            ->mapWithKeys(fn (ContentPage $page): array => [$page->slug => bin2hex((string) AuditDigests::of($page))])
            ->all();
    }
}
