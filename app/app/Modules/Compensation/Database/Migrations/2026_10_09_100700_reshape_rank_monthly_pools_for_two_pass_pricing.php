<?php

declare(strict_types=1);

use App\Modules\Compliance\Models\AuditLog;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Client 2026-10-05 Rank Income Point System: the 20% envelope is one pool,
 * priced in two passes at a capped point value (RankBonusService). Each pass
 * gets a frozen row of its own — the amount it divided, its points, the raw
 * floored value, the cap in force and the value paid — so a later setting
 * change never moves a frozen month.
 *
 * rank_monthly_pools gains the pass each rank was priced in. For each rank row
 * pool_paise becomes that rank's allotment (total_points × point_value) and
 * leftover_paise is 0: the pass row carries the real leftover.
 *
 * pool_pct is dropped in this release (user decision 2026-10-09: stale columns
 * go now); its readers were removed in the same change. No row is rewritten:
 * months frozen before this release keep their per-rank economics and read
 * pass 1 on every rank (the column default). The dropped values go into the
 * audit row first (the list when under 500 rows, the count always), in its own
 * transaction; the DDL stays outside it because MySQL commits a schema change
 * implicitly.
 */
return new class extends Migration
{
    private const ACTION = 'plan.migration.rank_monthly_pools_two_pass';

    public function up(): void
    {
        DB::transaction(function (): void {
            $count = DB::table('rank_monthly_pools')->count();

            $rows = $count < 500
                ? DB::table('rank_monthly_pools')
                    ->orderBy('id')
                    ->get(['id', 'month_start', 'rank_number', 'pool_pct'])
                    ->map(fn (object $row): array => [
                        'id' => (int) $row->id,
                        'month_start' => (string) $row->month_start,
                        'rank_number' => (int) $row->rank_number,
                        'pool_pct' => (float) $row->pool_pct,
                    ])
                    ->all()
                : null;

            // Through the model, not a raw insert: the creating hook links the
            // row into the audit hash chain, which a raw insert would skip.
            AuditLog::create([
                'actor_id' => null,
                'action' => self::ACTION,
                'subject_type' => 'rank_monthly_pool',
                'subject_id' => null,
                'details' => [
                    'migration' => '2026_10_09_100700_reshape_rank_monthly_pools_for_two_pass_pricing',
                    'reason' => 'Client 2026-10-05 Rank Income Point System: one 20% pool priced in two passes; per-rank pool % retired.',
                    'rows' => $count,
                    'dropped' => ['pool_pct'],
                    'added' => ['pass' => 1],
                    'before' => $rows,
                ],
            ]);
        });

        Schema::create('rank_monthly_passes', function (Blueprint $table): void {
            $table->id();
            $table->date('month_start');
            $table->unsignedTinyInteger('pass');
            $table->bigInteger('company_turnover_paise');
            $table->unsignedInteger('envelope_bp');
            $table->bigInteger('envelope_paise');
            // The amount this pass divided: the envelope for pass 1, what pass
            // 1 left for pass 2.
            $table->bigInteger('pool_paise');
            $table->unsignedInteger('total_points');
            $table->unsignedBigInteger('raw_point_value_paise');
            $table->unsignedBigInteger('point_value_cap_paise');
            $table->unsignedBigInteger('point_value_paise');
            $table->bigInteger('payout_paise');
            $table->bigInteger('leftover_paise');
            $table->timestamps();

            $table->unique(['month_start', 'pass'], 'uq_rank_pass_month_pass');
        });

        Schema::table('rank_monthly_pools', function (Blueprint $table): void {
            $table->unsignedTinyInteger('pass')->default(1)->after('rank_number');
        });

        Schema::table('rank_monthly_pools', function (Blueprint $table): void {
            $table->dropColumn('pool_pct');
        });
    }

    public function down(): void
    {
        // Schema only. The dropped pool_pct values come back NULL; restore the
        // ids listed in the plan.migration.rank_monthly_pools_two_pass audit row
        // by hand if they are needed.
        Schema::table('rank_monthly_pools', function (Blueprint $table): void {
            $table->decimal('pool_pct', 8, 4)->nullable()->after('envelope_bp');
        });

        Schema::table('rank_monthly_pools', function (Blueprint $table): void {
            $table->dropColumn('pass');
        });

        Schema::dropIfExists('rank_monthly_passes');
    }
};
