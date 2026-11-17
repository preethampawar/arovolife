<?php

declare(strict_types=1);

use App\Modules\Compliance\Models\AuditLog;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Plan §8 (user instruction 2026-11-17): the awards are called "merchandise
 * awards", not "non-cash awards", matching §11.5. The markdown source
 * (database/seeders/content/compensation.md) carries the same wording; this
 * migration brings the live page in line on environments where it is already
 * published, which content:publish --if-unpublished never rewrites.
 *
 * Replaces the one exact sentence only. A page whose §8 no longer contains it
 * (edited in the portal, or already migrated) is left alone and no audit row
 * is written.
 */
return new class extends Migration
{
    private const ACTION = 'plan.migration.rename_non_cash_awards_to_merchandise_awards_in_plan';

    public const OLD = 'Non-cash awards are not subject to the administrative charge. Applicable tax on non-cash awards is dealt with separately at the time of release.';

    public const NEW = 'Merchandise awards are not subject to the administrative charge. Applicable tax on merchandise awards is dealt with separately at the time of release.';

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
                    'migration' => '2026_11_17_100100_rename_non_cash_awards_to_merchandise_awards_in_plan',
                    'reason' => 'User instruction 2026-11-17: plan §8 calls the awards "merchandise awards" instead of "non-cash awards".',
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
