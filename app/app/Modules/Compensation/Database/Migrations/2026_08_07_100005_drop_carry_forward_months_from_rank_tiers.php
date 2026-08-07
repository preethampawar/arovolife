<?php

declare(strict_types=1);
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Drops the last trace of the "1+2 rule" plan config (KP 2026-08-05, replaced
 * by the AO-GO offer; user directive 2026-08-07 to remove superseded logic).
 *
 * `rank_tiers.carry_forward_months` was seeded 0 for every rank, but the
 * qualification engine still read it, so an admin editing the field on
 * /admin/compensation/plan-settings could have silently revived a retired
 * bonus rule. RankQualificationService no longer creates carry-forward
 * qualifications at all and the field is gone from the rank form.
 *
 * `rank_qualifications.is_carry_forward` and its historical rows are KEPT and
 * still read: the Q-Period count excludes them, the Rank Bonus does not pay
 * them, and a rank-2 qualification still voids a pending rank-1 carry.
 *
 * The per-rank values are captured into `audit_log` BEFORE the column goes — a
 * dropped plan-config column must leave the same retention-guaranteed
 * before-image as a deleted plan-config row (the pattern of
 * 2026_08_07_100004_remove_legacy_gsb_plan_settings).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('rank_tiers', 'carry_forward_months')) {
            return;
        }

        // Read the before-image and commit the audit row FIRST, in its own
        // transaction. The DDL deliberately stays OUTSIDE it: MySQL implicitly
        // commits a schema change, so a dropColumn() inside DB::transaction()
        // leaves nothing to commit ("There is no active transaction") and the
        // audit write would ride on an already-closed transaction. Ordering the
        // capture first also means a failed drop leaves the record behind
        // rather than the column surviving with no before-image recorded.
        DB::transaction(function (): void {
            $before = DB::table('rank_tiers')
                ->orderBy('rank_number')
                ->pluck('carry_forward_months', 'rank_number')
                ->map(fn ($months): int => (int) $months)
                ->all();

            // audit_log is append-only: it has created_at and no updated_at.
            DB::table('audit_log')->insert([
                'action' => 'rank_tiers.migration_drop_column',
                'subject_type' => 'rank_tier',
                'details' => json_encode([
                    'migration' => '2026_08_07_100005_drop_carry_forward_months_from_rank_tiers',
                    'column' => 'carry_forward_months',
                    'reason' => 'The "1+2 rule" carry-forward is retired (KP 2026-08-05, replaced by the AO-GO offer); the qualification engine no longer reads the field.',
                    'before' => $before,
                    'after' => null,
                ]),
                'created_at' => now(),
            ]);
        });

        Schema::table('rank_tiers', function (Blueprint $table) {
            $table->dropColumn('carry_forward_months');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('rank_tiers', 'carry_forward_months')) {
            Schema::table('rank_tiers', function (Blueprint $table) {
                $table->unsignedTinyInteger('carry_forward_months')->default(0)->after('structural_qualifiers_per_side');
            });
        }
    }
};
