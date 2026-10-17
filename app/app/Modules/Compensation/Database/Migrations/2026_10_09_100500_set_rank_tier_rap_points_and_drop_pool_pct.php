<?php

declare(strict_types=1);

use App\Modules\Compliance\Models\AuditLog;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Client 2026-10-05 Rank Income Point System: every rank carries Rank
     * Achievement Points and the 20% envelope is one pool, divided in two
     * passes at a capped point value (RankBonusService). The per-rank pool
     * percentage is gone.
     *
     * rap_points is set unconditionally: the old values (Rank 1 = 10, ranks
     * 2–9 null) were never a client choice an admin could have tuned toward,
     * they were the only rule that existed. The column becomes NOT NULL so a
     * rank can never silently fall back to an equal split.
     *
     * pool_pct is dropped in this release (user decision 2026-10-09: stale
     * columns go now); its last reader went with this change.
     *
     * The before-image of every rank goes into the audit row first, in its own
     * transaction; the DDL stays outside it because MySQL commits a schema
     * change implicitly.
     */
    private const ACTION = 'plan.migration.rank_tiers_rap_points_two_pass';

    private const RAP_POINTS = [1 => 72, 2 => 189, 3 => 468, 4 => 1125, 5 => 2583, 6 => 5688, 7 => 11934, 8 => 23877, 9 => 39501];

    public function up(): void
    {
        DB::transaction(function (): void {
            $before = DB::table('rank_tiers')
                ->orderBy('rank_number')
                ->get(['id', 'rank_number', 'rap_points', 'pool_pct'])
                ->map(fn (object $row): array => [
                    'id' => (int) $row->id,
                    'rank_number' => (int) $row->rank_number,
                    'rap_points' => $row->rap_points !== null ? (int) $row->rap_points : null,
                    'pool_pct' => (float) $row->pool_pct,
                ])
                ->all();

            $updated = 0;
            foreach (self::RAP_POINTS as $rank => $points) {
                $updated += DB::table('rank_tiers')->where('rank_number', $rank)->update(['rap_points' => $points]);
            }

            // Through the model, not a raw insert: the creating hook links the
            // row into the audit hash chain, which a raw insert would skip.
            AuditLog::create([
                'actor_id' => null,
                'action' => self::ACTION,
                'subject_type' => 'rank_tier',
                'subject_id' => null,
                'details' => [
                    'migration' => '2026_10_09_100500_set_rank_tier_rap_points_and_drop_pool_pct',
                    'reason' => 'Client 2026-10-05 Rank Income Point System: RAP on every rank, one 20% pool in two passes; the per-rank pool % is retired.',
                    'rows_updated' => $updated,
                    'before' => $before,
                    'after_rap_points' => self::RAP_POINTS,
                    'dropped' => ['pool_pct'],
                ],
            ]);
        });

        Schema::table('rank_tiers', function (Blueprint $table): void {
            $table->unsignedInteger('rap_points')->nullable(false)->default(0)->change();
            $table->dropColumn('pool_pct');
        });
    }

    public function down(): void
    {
        // Never guess: the prior rap_points and pool_pct per rank are only
        // known from the audit row's `before` list.
        throw new RuntimeException('restore rank_tiers from the '.self::ACTION.' audit row');
    }
};
