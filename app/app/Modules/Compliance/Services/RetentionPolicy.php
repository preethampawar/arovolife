<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Services;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * What the platform keeps, for how long, and counted from when (R-54).
 *
 * Retention was published in three places — `terms.md` §15, `privacy.md`, and
 * `docs/architecture/data-model.md` — and implemented in none. The platform
 * had two purge commands, for contact inquiries and grievances, and neither
 * touched distributors, KYC documents, consents, orientation views or
 * cooling-off events. Telling data principals their data is held for eight
 * years while holding it indefinitely is a discrepancy in the wrong direction:
 * DPDP §8(7) makes the stated period a ceiling, not only a floor.
 *
 * This class is the single source for those periods. Prose in three documents
 * that nothing reads will drift; a constant the report command reads cannot.
 *
 * **Nothing here deletes a distributor.** Every category below is either a
 * satellite record or an encrypted blob. Erasing a distributor means cascading
 * through orders, the double-entry ledger, BV, bonus results and payouts —
 * records the Companies Act and the Income-tax Act require to be kept, and
 * which the plan needs to explain historical payments to everybody upline.
 * What to do with a distributor row after eight years is a question for
 * counsel, not a `DELETE` somebody writes on a Friday. The report says how
 * many are due so the question cannot be forgotten; it does not answer it.
 */
final class RetentionPolicy
{
    /**
     * Categories, in the order the report prints them.
     *
     * `purgeable` marks the ones where deletion is unambiguous — no other
     * record depends on them and no statute requires them beyond the window.
     * The rest are reported and left alone.
     *
     * @var array<string, array{label: string, years: int, anchor: string, purgeable: bool, note: string}>
     */
    private const CATEGORIES = [
        'orientation_views' => [
            'label' => 'Orientation views',
            'years' => 8,
            'anchor' => 'started_at',
            'purgeable' => true,
            'note' => 'Evidence that the mandatory orientation was completed (DSR Rule 5).',
        ],
        'consents' => [
            'label' => 'Consents',
            'years' => 8,
            'anchor' => 'accepted_at',
            'purgeable' => false,
            'note' => 'Kept while the ADN lives — this is the proof of lawful processing. Purge only after the distributor record itself is settled.',
        ],
        'cooling_off_events' => [
            'label' => 'Cooling-off events',
            'years' => 8,
            'anchor' => 'opened_at',
            'purgeable' => true,
            'note' => 'The statutory 30-day window and whether it was exercised.',
        ],
        'audit_log' => [
            'label' => 'Audit log',
            'years' => 8,
            'anchor' => 'created_at',
            'purgeable' => false,
            'note' => 'Deleting entries breaks the hash chain (compliance:verify-audit-log), so a purge has to re-anchor it. Needs a decision, not a DELETE.',
        ],
        'distributors' => [
            'label' => 'Terminated distributors',
            'years' => 8,
            'anchor' => 'terminated_at',
            'purgeable' => false,
            'note' => 'Cascades into orders, the ledger, BV and payouts — records other statutes require. For counsel.',
        ],
    ];

    /**
     * How many rows in each category are now past their retention window.
     *
     * @return array<int, array{key: string, label: string, years: int, purgeable: bool, note: string, expired: int, cutoff: string}>
     */
    public function report(?Carbon $asOf = null): array
    {
        $asOf ??= Carbon::now();
        $rows = [];

        foreach (self::CATEGORIES as $table => $category) {
            $cutoff = $asOf->copy()->subYears($category['years']);

            $rows[] = [
                'key' => $table,
                'label' => $category['label'],
                'years' => $category['years'],
                'purgeable' => $category['purgeable'],
                'note' => $category['note'],
                'expired' => (int) $this->expiredQuery($table, $cutoff)->count(),
                'cutoff' => $cutoff->toDateString(),
            ];
        }

        return $rows;
    }

    /**
     * Delete the expired rows of one purgeable category.
     *
     * @return int rows deleted
     */
    public function purge(string $table, ?Carbon $asOf = null): int
    {
        $category = self::CATEGORIES[$table] ?? null;

        if ($category === null || ! $category['purgeable']) {
            // Refusing rather than silently doing nothing: a caller that asks
            // to purge the audit log has misunderstood something, and should
            // hear about it.
            throw new \InvalidArgumentException("'{$table}' is not a purgeable retention category.");
        }

        $cutoff = ($asOf ?? Carbon::now())->copy()->subYears($category['years']);

        return $this->expiredQuery($table, $cutoff)->delete();
    }

    private function expiredQuery(string $table, Carbon $cutoff): Builder
    {
        $anchor = self::CATEGORIES[$table]['anchor'];

        $query = DB::table($table)
            ->whereNotNull($anchor)
            ->where($anchor, '<', $cutoff);

        // A distributor is only counted from termination — an active one has
        // no retention clock running at all.
        if ($table === 'distributors') {
            $query->whereNotNull('terminated_at');
        }

        return $query;
    }
}
