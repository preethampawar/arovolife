<?php

declare(strict_types=1);

use App\Modules\Compliance\Models\AuditLog;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Client 2026-10-09 Lifetime Awards & Rewards: a milestone is one tranche of a
 * rank's award, released once the rank has been qualified at least `tranche`
 * times (assumption A-A1; the old 1/2/3 rank-based threshold is gone). The
 * unique key widens from (distributor, rank) to (distributor, rank, tranche).
 *
 * Every existing milestone becomes tranche A (the column default). Fail-safe
 * finding F-11:
 * - amount_paise is backfilled from tranche A of the rank on every row that is
 *   not delivered;
 * - a delivered row gets amount_paise = its recorded gross_paise, or tranche A
 *   when none was recorded — informational only: its status,
 *   qualification_count and released_rule_changed_at are never touched;
 * - a pending row that was not releasable under the old rule and is under the
 *   new one gets released_rule_changed_at, so the Lifetime Awards page can say
 *   why a tranche is suddenly due.
 * The audit row lists every changed row (id, before, after) when under 500.
 *
 * The DDL stays outside the data transaction because MySQL commits a schema
 * change implicitly. The index swap is plain Schema builder DDL, which MySQL
 * and SQLite both run as drop/create index: the new unique is added before the
 * old one is dropped, and idx_lifetime_award_dist keeps an index on
 * distributor_id for fk_lifetime_award_dist throughout.
 */
return new class extends Migration
{
    private const ACTION = 'plan.migration.add_tranche_to_lifetime_award_milestones';

    public function up(): void
    {
        Schema::table('lifetime_award_milestones', function (Blueprint $table): void {
            $table->unsignedTinyInteger('tranche')->default(1)->after('rank_number');
            $table->unsignedBigInteger('amount_paise')->default(0)->after('tranche');
            $table->timestamp('released_rule_changed_at')->nullable()->after('status');
        });

        Schema::table('lifetime_award_milestones', function (Blueprint $table): void {
            $table->unique(['distributor_id', 'rank_number', 'tranche'], 'uq_award_milestone_dist_rank_tranche');
        });

        Schema::table('lifetime_award_milestones', function (Blueprint $table): void {
            $table->dropUnique('uq_lifetime_award_dist_rank');
        });

        DB::transaction(function (): void {
            $trancheA = DB::table('lifetime_award_tranches')
                ->where('tranche', 1)
                ->pluck('amount_paise', 'rank_number')
                ->mapWithKeys(fn ($amount, $rank): array => [(int) $rank => (int) $amount])
                ->all();

            $rows = DB::table('lifetime_award_milestones')
                ->where('status', '!=', 'delivered')
                ->orderBy('id')
                ->get(['id', 'rank_number', 'qualification_count', 'status']);

            $deliveredRows = DB::table('lifetime_award_milestones')
                ->where('status', 'delivered')
                ->orderBy('id')
                ->get(['id', 'rank_number', 'gross_paise']);

            // Fail-safe principle 1: read every amount before the first write.
            foreach ($rows->concat($deliveredRows->whereNull('gross_paise')) as $row) {
                if (! isset($trancheA[(int) $row->rank_number])) {
                    throw new RuntimeException("lifetime_award_tranches has no tranche A row for rank {$row->rank_number}; refusing to backfill lifetime_award_milestones");
                }
            }

            $now = now();
            $changed = [];
            $ruleChanged = 0;

            // Informational only: what was handed over, else tranche A. Status,
            // qualification_count and released_rule_changed_at stay as they are.
            $deliveredRecorded = [];
            foreach ($deliveredRows as $row) {
                $amount = $row->gross_paise !== null ? (int) $row->gross_paise : $trancheA[(int) $row->rank_number];

                DB::table('lifetime_award_milestones')->where('id', $row->id)->update(['amount_paise' => $amount]);

                $deliveredRecorded[] = [
                    'id' => (int) $row->id,
                    'before' => ['amount_paise' => 0],
                    'after' => ['amount_paise' => $amount, 'source' => $row->gross_paise !== null ? 'gross_paise' : 'tranche_a'],
                ];
            }

            foreach ($rows as $row) {
                $rank = (int) $row->rank_number;
                $count = (int) $row->qualification_count;
                $amount = $trancheA[$rank];
                $wasReleasable = $count >= $this->oldReleaseThreshold($rank);
                $isReleasable = $count >= 1;
                $flag = $row->status === 'pending' && ! $wasReleasable && $isReleasable;

                DB::table('lifetime_award_milestones')->where('id', $row->id)->update(array_filter([
                    'amount_paise' => $amount,
                    'released_rule_changed_at' => $flag ? $now : null,
                ], fn ($value): bool => $value !== null));

                $ruleChanged += $flag ? 1 : 0;
                $changed[] = [
                    'id' => (int) $row->id,
                    'before' => ['amount_paise' => 0, 'releasable' => $wasReleasable],
                    'after' => ['amount_paise' => $amount, 'releasable' => $isReleasable, 'released_rule_changed' => $flag],
                ];
            }

            // Through the model, not a raw insert: the creating hook links the
            // row into the audit hash chain, which a raw insert would skip.
            AuditLog::create([
                'actor_id' => null,
                'action' => self::ACTION,
                'subject_type' => 'lifetime_award_milestone',
                'subject_id' => null,
                'details' => [
                    'migration' => '2026_10_09_100900_add_tranche_to_lifetime_award_milestones',
                    'reason' => 'Client 2026-10-09: one milestone per award tranche, released once qualification_count ≥ tranche; existing rows become tranche A.',
                    'rows_backfilled' => count($changed),
                    'rows_release_rule_changed' => $ruleChanged,
                    'rows_delivered_amount_recorded' => count($deliveredRecorded),
                    'changed' => count($changed) < 500 ? $changed : null,
                    'delivered_amount_recorded' => count($deliveredRecorded) < 500 ? $deliveredRecorded : null,
                ],
            ]);
        });
    }

    public function down(): void
    {
        // Never guess: rows created under the per-tranche rule (tranche B/C)
        // cannot fit the old (distributor, rank) key, and the flag and amounts
        // are only known from the audit row's `changed` list.
        throw new RuntimeException(
            'Cannot roll back '.self::ACTION.': restore from the plan.migration.* audit row '
            .'(changed[].id lists every row backfilled or flagged; delivered_amount_recorded[].id every delivered row given an amount).'
        );
    }

    /** The release threshold in force before 2026-10-09: ranks 1–2 → 1, 3–5 → 2, 6–9 → 3. */
    private function oldReleaseThreshold(int $rank): int
    {
        return match (true) {
            $rank <= 2 => 1,
            $rank <= 5 => 2,
            default => 3,
        };
    }
};
