<?php

declare(strict_types=1);

use App\Modules\Compliance\Models\AuditLog;
use Database\Seeders\LifetimeAwardTranchesSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Client 2026-10-09 Lifetime Awards & Rewards: the itemised reward catalogue
 * seeded on 2026-06-28 no longer reconciles to the rank budgets, which are now
 * the sum of each rank's merchandise tranches (2026_10_09_100800). Each rank's
 * catalogue becomes one placeholder item per tranche, worth the tranche amount,
 * until the client supplies the item list.
 *
 * A rank's rows are replaced ONLY while they still equal the old seeded list
 * exactly (sort_order, item, worth); an admin-edited catalogue is left alone
 * and recorded as `moved: false`. On a fresh install the table is still empty
 * here, nothing moves, and LifetimeAwardRewardsSeeder seeds the placeholders.
 * The audit row records every rank's before and after rows.
 */
return new class extends Migration
{
    private const ACTION = 'plan.migration.lifetime_award_catalogue';

    /** The catalogue LifetimeAwardRewardsSeeder seeded before 2026-10-09: rank => [[item, worth_paise], …] in sort_order. */
    private const OLD_CATALOGUE = [
        1 => [
            ['15 Lakh accident insurance (single person)', 1_500_000],
        ],
        2 => [
            ['30 Lakh term insurance (single person)', 3_000_000],
        ],
        3 => [
            ['15 Lakh health insurance (2+2)', 2_500_000],
            ['1 foreign trip (3N/4D)', 5_000_000],
            ['Gold', 1_500_000],
        ],
        4 => [
            ['4 foreign trip tickets (3N/4D)', 20_000_000],
            ['Samsung Tab', 3_000_000],
            ['Gold', 13_500_000],
        ],
        5 => [
            ['4 foreign trip tickets (3N/4D)', 20_000_000],
            ['Car down payment', 50_000_000],
            ['iPhone', 10_000_000],
            ['Gold', 20_000_000],
        ],
        6 => [
            ['4 foreign trip tickets (6N/7D)', 40_000_000],
            ['Car down payment', 150_000_000],
            ['Laptop down payment', 15_000_000],
            ['iPhone', 15_000_000],
            ['Preloaded debit card', 10_000_000],
            ['Gold', 70_000_000],
        ],
        7 => [
            ['4 foreign trip tickets (6N/7D)', 40_000_000],
            ['House down payment', 640_000_000],
            ['Royal Enfield Bullet down payment', 15_000_000],
            ['2 iPhones', 30_000_000],
            ['Preloaded debit card', 20_000_000],
            ['Gold', 80_000_000],
            ['Silver', 75_000_000],
        ],
        8 => [
            ['4 foreign trip tickets (10N/11D)', 100_000_000],
            ['House down payment', 750_000_000],
            ['Luxury car down payment', 340_000_000],
            ['Preloaded debit card', 30_000_000],
            ['Office rent', 5_000_000],
            ['Gold', 90_000_000],
            ['Silver', 85_000_000],
        ],
        9 => [
            ['4 foreign tickets (10N/11D)', 100_000_000],
            ['Independent villa down payment', 1_350_000_000],
            ['Luxury car down payment', 500_000_000],
            ['Driver salary', 5_000_000],
            ['Office rent', 10_000_000],
            ['PA salary', 5_000_000],
            ['Preloaded debit card', 50_000_000],
            ['Gold', 120_000_000],
            ['Silver', 110_000_000],
        ],
    ];

    public function up(): void
    {
        DB::transaction(function (): void {
            $now = now();
            $ranks = [];

            foreach (self::OLD_CATALOGUE as $rank => $oldItems) {
                $before = DB::table('lifetime_award_rewards')
                    ->where('rank_number', $rank)
                    ->orderBy('sort_order')
                    ->get(['id', 'item', 'worth_paise', 'sort_order'])
                    ->map(fn (object $row): array => [
                        'id' => (int) $row->id,
                        'item' => (string) $row->item,
                        'worth_paise' => (int) $row->worth_paise,
                        'sort_order' => (int) $row->sort_order,
                    ])
                    ->all();

                $current = array_map(fn (array $row): array => [$row['sort_order'], $row['item'], $row['worth_paise']], $before);
                $old = array_map(fn (int $sort, array $item): array => [$sort, $item[0], $item[1]], array_keys($oldItems), $oldItems);
                $moved = $before !== [] && $current === $old;

                if ($moved) {
                    DB::table('lifetime_award_rewards')->where('rank_number', $rank)->delete();

                    foreach (LifetimeAwardTranchesSeeder::TRANCHES[$rank] as $sort => $worth) {
                        DB::table('lifetime_award_rewards')->insert([
                            'rank_number' => $rank,
                            'sort_order' => $sort,
                            'item' => sprintf('Merchandise, tranche %s — items to be specified by the company', chr(65 + $sort)),
                            'worth_paise' => $worth,
                            'created_at' => $now,
                            'updated_at' => $now,
                        ]);
                    }
                }

                $after = $moved
                    ? DB::table('lifetime_award_rewards')
                        ->where('rank_number', $rank)
                        ->orderBy('sort_order')
                        ->get(['id', 'item', 'worth_paise', 'sort_order'])
                        ->map(fn (object $row): array => [
                            'id' => (int) $row->id,
                            'item' => (string) $row->item,
                            'worth_paise' => (int) $row->worth_paise,
                            'sort_order' => (int) $row->sort_order,
                        ])
                        ->all()
                    : $before;

                $ranks[] = ['rank' => $rank, 'moved' => $moved, 'before' => $before, 'after' => $after];
            }

            // Through the model, not a raw insert: the creating hook links the
            // row into the audit hash chain, which a raw insert would skip.
            AuditLog::create([
                'actor_id' => null,
                'action' => self::ACTION,
                'subject_type' => 'lifetime_award_reward',
                'subject_id' => null,
                'details' => [
                    'migration' => '2026_10_09_100950_replace_lifetime_award_catalogue',
                    'reason' => 'Client 2026-10-09 Lifetime Awards & Rewards: one placeholder merchandise item per tranche until the client supplies the item list; admin-edited catalogues are kept.',
                    'ranks' => $ranks,
                ],
            ]);
        });
    }

    public function down(): void
    {
        // Never guess: the replaced rows are only known from the audit row's
        // `ranks[].before` list.
        throw new RuntimeException(
            'Cannot roll back '.self::ACTION.': restore from the plan.migration.* audit row '
            .'(ranks[].before holds every replaced row).'
        );
    }
};
