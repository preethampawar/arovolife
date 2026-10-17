<?php

declare(strict_types=1);

use App\Modules\Compliance\Models\AuditLog;
use Database\Seeders\LifetimeAwardTranchesSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Client 2026-10-09 Lifetime Awards & Rewards: each rank's award is paid in up
 * to three merchandise tranches — A on the 1st qualification, B on the 2nd, C
 * on the 3rd — and the rank's budget is the sum of its tranches.
 *
 * One concern: the tranche table, its nine ranks' rows (seeded here so a fresh
 * install migrates end to end without a seeder run), and the rank budgets
 * derived from them. rank_tiers.lifetime_award_budget_paise moves only where
 * it still holds the old seeded default, so an admin override is kept; the
 * audit row records per rank whether it moved. On a fresh install rank_tiers
 * is still empty here and RankTiersSeeder seeds the new budgets.
 *
 * The DDL stays outside the data transaction because MySQL commits a schema
 * change implicitly.
 */
return new class extends Migration
{
    private const ACTION = 'plan.migration.lifetime_award_tranches';

    /** The budgets RankTiersSeeder seeded before 2026-10-09, rank => paise. */
    private const OLD_BUDGETS = [
        1 => 1_500_000,
        2 => 3_000_000,
        3 => 9_000_000,
        4 => 36_500_000,
        5 => 100_000_000,
        6 => 300_000_000,
        7 => 900_000_000,
        8 => 1_400_000_000,
        9 => 2_250_000_000,
    ];

    public function up(): void
    {
        Schema::create('lifetime_award_tranches', function (Blueprint $table): void {
            $table->id();
            $table->unsignedTinyInteger('rank_number');
            $table->unsignedTinyInteger('tranche');
            $table->unsignedBigInteger('amount_paise');
            $table->timestamps();

            $table->unique(['rank_number', 'tranche'], 'uq_award_tranche_rank_tranche');
        });

        DB::transaction(function (): void {
            (new LifetimeAwardTranchesSeeder)->run();

            $budgets = [];
            foreach (DB::table('rank_tiers')->orderBy('rank_number')->get(['rank_number', 'lifetime_award_budget_paise']) as $row) {
                $rank = (int) $row->rank_number;
                $before = (int) $row->lifetime_award_budget_paise;
                $target = array_sum(LifetimeAwardTranchesSeeder::TRANCHES[$rank] ?? []);
                $moved = $target > 0
                    && isset(self::OLD_BUDGETS[$rank])
                    && DB::table('rank_tiers')
                        ->where('rank_number', $rank)
                        ->where('lifetime_award_budget_paise', self::OLD_BUDGETS[$rank])
                        ->update(['lifetime_award_budget_paise' => $target, 'updated_at' => now()]) > 0;

                $budgets[] = ['rank' => $rank, 'moved' => $moved, 'before' => $before, 'after' => $moved ? $target : $before];
            }

            // Through the model, not a raw insert: the creating hook links the
            // row into the audit hash chain, which a raw insert would skip.
            AuditLog::create([
                'actor_id' => null,
                'action' => self::ACTION,
                'subject_type' => 'rank_tier',
                'subject_id' => null,
                'details' => [
                    'migration' => '2026_10_09_100800_create_lifetime_award_tranches_table',
                    'reason' => 'Client 2026-10-09 Lifetime Awards & Rewards: merchandise tranches A/B/C per rank; the rank budget is their sum.',
                    'tranche_rows' => DB::table('lifetime_award_tranches')->count(),
                    'budgets' => $budgets,
                ],
            ]);
        });
    }

    public function down(): void
    {
        // Never guess: a budget that was moved can only be told apart from one
        // an admin set to the same figure through the audit row's `budgets`.
        throw new RuntimeException(
            'Cannot roll back '.self::ACTION.': restore from the plan.migration.* audit row '
            .'(budgets[].before is the prior rank_tiers.lifetime_award_budget_paise), then drop lifetime_award_tranches by hand.'
        );
    }
};
