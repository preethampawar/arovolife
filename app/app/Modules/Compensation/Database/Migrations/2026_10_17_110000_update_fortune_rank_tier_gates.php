<?php

declare(strict_types=1);

use App\Modules\Compliance\Models\AuditLog;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Client 2026-08-07: the Fortune Bonus ranked tiers need 8 / 11 / 14 / 17
     * / 20 slab achievements and 1,000 → 1,400 BV in the month (plan §7.2).
     *
     * FortuneBonusTiersSeeder carries these, but ProductionSeeder seeds plan
     * tables only while they are empty, so a table seeded in June kept the
     * 2026-06-28 gates — 7 / 10 / 13 / 16 / 19 and 1,000 BV for every rank —
     * and enrolled distributors the published plan does not (found on dev,
     * 2026-10-17 audit).
     *
     * A tier moves only while it still holds exactly the old default, so an
     * admin's edit is kept; the audit row records each tier either way.
     */
    private const ACTION = 'plan.migration.update_fortune_rank_tier_gates';

    /** @var array<string, array{old: array{int, int}, new: array{int, int}}> tier => [bv_required_paise, slabs_required] */
    private const TIERS = [
        'rank_1' => ['old' => [100_000, 7], 'new' => [100_000, 8]],
        'rank_2' => ['old' => [100_000, 10], 'new' => [110_000, 11]],
        'rank_3' => ['old' => [100_000, 13], 'new' => [120_000, 14]],
        'rank_4' => ['old' => [100_000, 16], 'new' => [130_000, 17]],
        'rank_5' => ['old' => [100_000, 19], 'new' => [140_000, 20]],
    ];

    public function up(): void
    {
        // `migrate --pretend` (app:deploy's dry run) only prints SQL, and the
        // audit row's binary hash cannot be printed — show the updates and stop.
        if (DB::pretending()) {
            foreach (self::TIERS as $tier => $gates) {
                $this->moveIfDefault($tier, $gates);
            }

            return;
        }

        DB::transaction(function (): void {
            $report = [];

            foreach (self::TIERS as $tier => $gates) {
                $before = DB::table('fortune_bonus_tiers')->where('tier', $tier)->first(['bv_required_paise', 'slabs_required']);

                // A fresh database has no rows yet: the seeder writes the
                // current gates and there is nothing to move.
                if ($before === null) {
                    continue;
                }

                $report[$tier] = [
                    'moved' => $this->moveIfDefault($tier, $gates),
                    'before' => ['bv_required_paise' => (int) $before->bv_required_paise, 'slabs_required' => (int) $before->slabs_required],
                ];
            }

            if ($report === []) {
                return;
            }

            // Through the model, not a raw insert: the creating hook links the
            // row into the audit hash chain, which a raw insert would skip.
            AuditLog::create([
                'actor_id' => null,
                'action' => self::ACTION,
                'subject_type' => 'fortune_bonus_tiers',
                'subject_id' => null,
                'details' => [
                    'migration' => '2026_10_17_110000_update_fortune_rank_tier_gates',
                    'reason' => 'Client 2026-08-07: ranked Fortune tiers need 8/11/14/17/20 slabs and 1,000–1,400 BV (plan §7.2).',
                    'tiers' => $report,
                ],
            ]);
        });
    }

    /** @param  array{old: array{int, int}, new: array{int, int}}  $gates */
    private function moveIfDefault(string $tier, array $gates): bool
    {
        return DB::table('fortune_bonus_tiers')
            ->where('tier', $tier)
            ->where('bv_required_paise', $gates['old'][0])
            ->where('slabs_required', $gates['old'][1])
            ->update([
                'bv_required_paise' => $gates['new'][0],
                'slabs_required' => $gates['new'][1],
                'updated_at' => now(),
            ]) > 0;
    }

    public function down(): void
    {
        // Never guess: a tier may have been an admin edit that was left alone.
        // Restore from the audit row's tiers.*.before by hand.
        throw new RuntimeException(
            'Cannot roll back '.self::ACTION.': restore fortune_bonus_tiers from the audit row\'s tiers.*.before.'
        );
    }
};
