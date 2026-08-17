<?php

declare(strict_types=1);

namespace App\Modules\Compensation\Services\Recompute;

use App\Modules\Compensation\Support\DerivedTables;
use Closure;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Facades\Schema;

/**
 * Removes every row computed from BV, leaving the purchases untouched.
 *
 * The distinction from {@see \App\Console\Actions\PurchaseDataResetAction} is
 * the whole point: that action wipes the orders too ("start selling again"),
 * this one keeps them ("recompute the same history"). Both take their
 * compensation table list from {@see DerivedTables}, so neither can drift.
 *
 * TESTING ONLY. Every row this removes is a record of money credited.
 */
final class CompensationStateWiper
{
    public function __construct(private readonly DatabaseManager $db) {}

    /**
     * Truncate every derived table and reset the derived columns.
     *
     * @param  Closure(string): void|null  $progress
     * @return array<string, int>  table => rows removed
     */
    public function wipe(?Closure $progress = null): array
    {
        $log = $progress ?? static fn (string $_m): null => null;

        $removed = [];

        Schema::disableForeignKeyConstraints();

        try {
            foreach (DerivedTables::inTruncationOrder() as $table) {
                if (! $this->db->getSchemaBuilder()->hasTable($table)) {
                    continue;
                }

                $count = (int) $this->db->table($table)->count();
                $this->db->table($table)->truncate();
                $removed[$table] = $count;

                if ($count > 0) {
                    $log(sprintf('  %-28s %d row(s)', $table, $count));
                }
            }
        } finally {
            Schema::enableForeignKeyConstraints();
        }

        // A manual GSB freeze is an operator decision layered on top of the
        // engine's own state; it would silently suppress every replayed
        // cut-off for that distributor.
        $unfrozen = $this->db->table('distributors')
            ->whereNotNull('gsb_frozen_at')
            ->update(['gsb_frozen_at' => null]);

        if ($unfrozen > 0) {
            $log(sprintf('  %-28s %d distributor(s) unfrozen', 'gsb_frozen_at', $unfrozen));
        }

        // Queued propagation jobs reference the pre-wipe state. Leaving them
        // would let a worker double-apply BV after the replay has finished.
        $queued = $this->db->table('jobs')
            ->where('payload', 'like', '%PropagateGroupBvJob%')
            ->delete();

        if ($queued > 0) {
            $log(sprintf('  %-28s %d queued job(s) removed', 'jobs', $queued));
        }

        return $removed;
    }

    /**
     * Row counts that would be destroyed, without destroying them — the
     * confirmation prompt shows this before asking.
     *
     * @return array<string, int>
     */
    public function preview(): array
    {
        $counts = [];

        foreach (DerivedTables::inTruncationOrder() as $table) {
            if (! $this->db->getSchemaBuilder()->hasTable($table)) {
                continue;
            }

            $count = (int) $this->db->table($table)->count();
            if ($count > 0) {
                $counts[$table] = $count;
            }
        }

        return $counts;
    }
}
