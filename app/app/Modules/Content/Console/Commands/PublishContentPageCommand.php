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
 * `compensation.md` is held by R-75 until the DSA §6.2 30-day notice has run,
 * while a privacy-notice amendment may need to go out immediately. Publishing
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
 */
final class PublishContentPageCommand extends Command
{
    protected $signature = 'content:publish {slug* : One or more page slugs to republish}
        {--if-unpublished : Only publish pages that are missing or not yet published}';

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

        $seeder = new ContentPageSeeder;
        $count = $seeder->publish($slugs);

        AuditLog::create([
            'actor_id' => null,
            'action' => 'content_page.republished',
            'subject_type' => 'content_page',
            'subject_id' => null,
            'details' => ['slugs' => $slugs, 'pages_written' => $count],
        ]);

        $this->info('Republished '.$count.' page(s): '.implode(', ', $slugs).'.');

        return self::SUCCESS;
    }
}
