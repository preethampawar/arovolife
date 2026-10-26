<?php

declare(strict_types=1);

namespace App\Modules\Content\Console\Commands;

use App\Modules\Compliance\Models\AuditLog;
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
 * (R-125). `--overwrite-portal-edits` replaces it from the markdown anyway.
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
        $kept = $overwrite ? [] : array_values(array_filter($slugs, ContentPageSeeder::editedInPortal(...)));

        if ($kept !== []) {
            $this->warn('Kept the portal version of: '.implode(', ', $kept).'. '
                .'These were changed in the admin editor, so that copy is the master. '
                .'Pass --overwrite-portal-edits to replace them from the markdown source.');
        }

        $seeder = new ContentPageSeeder;
        $count = $seeder->publish($slugs, $overwrite);

        AuditLog::create([
            'actor_id' => null,
            'action' => 'content_page.republished',
            'subject_type' => 'content_page',
            'subject_id' => null,
            'details' => ['slugs' => $slugs, 'pages_written' => $count, 'portal_versions_kept' => $kept, 'overwrote_portal_edits' => $overwrite],
        ]);

        $this->info('Republished '.$count.' page(s): '.implode(', ', array_diff($slugs, $kept)).'.');

        return self::SUCCESS;
    }
}
