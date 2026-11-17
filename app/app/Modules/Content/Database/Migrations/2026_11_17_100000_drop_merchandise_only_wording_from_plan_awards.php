<?php

declare(strict_types=1);

use App\Modules\Compliance\Models\AuditLog;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Plan §11.5 Lifetime Awards (user instruction 2026-11-17): the award is
 * described as merchandise, without "only — never cash", "There is no cash
 * alternative" or "converted to money" — awards are now paid in cash with the
 * monthly payout. The markdown source (database/seeders/content/compensation.md)
 * carries the same wording; this migration brings the live page in line on
 * environments where it is already published, which content:publish
 * --if-unpublished never rewrites.
 *
 * Replaces the one exact sentence only. A page whose §11.5 no longer contains
 * it (edited in the portal, or already migrated) is left alone and no audit
 * row is written.
 */
return new class extends Migration
{
    private const ACTION = 'plan.migration.drop_merchandise_only_wording_from_plan_awards';

    public const OLD = 'given as <strong>merchandise only — never cash</strong>. There is no cash alternative, and an award cannot be exchanged, transferred or converted to money.';

    public const NEW = 'given as <strong>merchandise</strong>. An award cannot be exchanged or transferred.';

    public function up(): void
    {
        DB::transaction(function (): void {
            $page = DB::table('content_pages')->where('slug', 'compensation')->lockForUpdate()->first(['id', 'body']);

            if ($page === null || ! str_contains((string) $page->body, self::OLD)) {
                return;
            }

            $before = (string) $page->body;
            $after = str_replace(self::OLD, self::NEW, $before);

            DB::table('content_pages')->where('id', $page->id)->update(['body' => $after, 'updated_at' => now()]);

            // Through the model, not a raw insert: the creating hook links the
            // row into the audit hash chain, which a raw insert would skip.
            AuditLog::create([
                'actor_id' => null,
                'action' => self::ACTION,
                'subject_type' => 'content_page',
                'subject_id' => (int) $page->id,
                'before_hash' => AuditLog::digest($before),
                'after_hash' => AuditLog::digest($after),
                'details' => [
                    'migration' => '2026_11_17_100000_drop_merchandise_only_wording_from_plan_awards',
                    'reason' => 'User instruction 2026-11-17: plan §11.5 describes the award as merchandise, without the "only — never cash" and "no cash alternative / converted to money" wording.',
                    'slug' => 'compensation',
                    'before' => self::OLD,
                    'after' => self::NEW,
                ],
            ]);
        });
    }

    public function down(): void
    {
        throw new RuntimeException(
            'Cannot roll back '.self::ACTION.': the previous sentence is in the audit row (details.before).'
        );
    }
};
