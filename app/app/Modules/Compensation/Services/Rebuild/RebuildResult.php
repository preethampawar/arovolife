<?php

declare(strict_types=1);

namespace App\Modules\Compensation\Services\Rebuild;

/**
 * What a wipe actually did — the durable record behind the
 * `compensation.rebuild.wiped` audit row.
 *
 * Three fields rather than one count map, because a rebuild does three
 * different things and an audit row that flattened them would be the one place
 * nobody could tell them apart afterwards:
 *
 *  - `removed` — rows deleted, per table. What "N rows removed" means.
 *  - `adjusted` — rows corrected IN PLACE and never deleted, per table: the
 *    night's `group_bv_daily` accumulators, which the day's personal-BV top-ups
 *    incremented and the wipe hands back.
 *  - `walletTotals` — how much credited income went, per wallet entry type.
 *    A period wiped and then never rebuilt (the re-run failed, nobody came
 *    back) otherwise leaves a row count and no money, and the question asked
 *    afterwards is always how much. The payout paths record ids and paise on
 *    their own `payout.batch.unbuilt` row already; this is the same fact for
 *    the night and the month.
 */
final readonly class RebuildResult
{
    /**
     * @param  array<string, int>  $removed  table => rows deleted
     * @param  array<string, int>  $adjusted  table => rows corrected in place
     * @param  array<string, array{count: int, paise: int}>  $walletTotals  wallet entry type => rows and paise removed
     */
    public function __construct(
        public array $removed = [],
        public array $adjusted = [],
        public array $walletTotals = [],
    ) {}
}
