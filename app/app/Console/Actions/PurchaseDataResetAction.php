<?php

declare(strict_types=1);

namespace App\Console\Actions;

use App\Modules\Compensation\Support\DerivedTables;
use App\Modules\Compliance\Models\AuditLog;
use App\Modules\Identity\Models\User;
use Closure;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Facades\Schema;

/**
 * Purchase-data-only reset: wipes every table derived from product sales
 * (orders, BV, GSB/MB and the other bonus results, wallet ledger, payout
 * batches, money ledger, returns) so GSB/MSB can be tested from a clean
 * slate — while PRESERVING everything that is not purchase-derived:
 * users, distributors, the Genos tree, sponsorship, KYC, consents,
 * settings, plan configuration (gsb_slabs, rank_tiers, fortune levels/
 * tiers, lifetime_award_rewards), arete centers, the product catalog,
 * coupons, customers, the ledger chart of accounts, and the audit log.
 *
 * Derived counters are reset rather than deleted: coupons.used_count → 0,
 * inventory_levels.reserved → 0 (on_hand untouched), and
 * distributors.gsb_frozen_at → NULL. Personal-purchase titles need no
 * reset — they are computed from bv_ledger_entries, which is wiped.
 *
 * Idempotent: running twice yields the identical post-state.
 */
final class PurchaseDataResetAction
{
    /**
     * Commerce tables wiped after the compensation-derived ones — the purchase
     * flow itself and its BV trail. This is what separates this reset from
     * {@see \App\Modules\Compensation\Services\Recompute\CompensationStateWiper},
     * which keeps every one of these so the same history can be recomputed.
     *
     * @var list<string>
     */
    private const COMMERCE_TABLES = [
        'bv_ledger_entries',
        'coupon_redemptions',
        'return_inspections',
        'buyback_decisions',
        'return_requests',
        'refund_intents',
        'payment_intents',
        'shipments',
        'ledger_entries',
        'ledger_tx',
        'order_cooling_off',
        'order_items',
        'orders',
        'cart_items',
        'carts',
        'shared_carts',
    ];

    public function __construct(private readonly DatabaseManager $db) {}

    /**
     * Everything this reset truncates, in FK-safe order: the compensation-derived
     * tables first (single-sourced from {@see DerivedTables}, so a new bonus table
     * can never be added to one reset and forgotten in the other), then the
     * commerce tables.
     *
     * @return list<string>
     */
    public static function wipeTables(): array
    {
        return [...DerivedTables::inTruncationOrder(), ...self::COMMERCE_TABLES];
    }

    /**
     * @param  Closure(string): void|null  $progress  optional callback for CLI output
     */
    public function execute(?Closure $progress = null): void
    {
        $log = $progress ?? static fn (string $_m): null => null;

        $log('Truncating purchase-derived tables...');
        $this->truncateAll($log);

        $log('Resetting derived counters (gsb_frozen_at, coupons.used_count, inventory reserved)...');
        $this->resetDerivedColumns();

        $log('Removing queued BV-propagation jobs...');
        $deleted = $this->db->table('jobs')
            ->where('payload', 'like', '%PropagateGroupBvJob%')
            ->delete();
        $log(sprintf('  %d queued job(s) removed', $deleted));

        $log('Writing platform.purchase_reset audit-log entry...');
        AuditLog::create([
            'actor_id' => $this->resolveAdminUserId(),
            'action' => 'platform.purchase_reset',
            'subject_type' => 'platform',
            'subject_id' => 0,
            'details' => [
                'tables_wiped' => self::wipeTables(),
                'note' => 'Purchase + compensation data reset via php artisan platform:reset-purchases',
            ],
        ]);

        $log('Purchase-data reset complete.');
    }

    /** @param Closure(string): void $log */
    private function truncateAll(Closure $log): void
    {
        Schema::disableForeignKeyConstraints();

        try {
            foreach (self::wipeTables() as $table) {
                if (! $this->db->getSchemaBuilder()->hasTable($table)) {
                    continue;
                }
                $count = $this->db->table($table)->count();
                $this->db->table($table)->truncate();
                $log(sprintf('  %s: %d row(s) removed', $table, $count));
            }
        } finally {
            Schema::enableForeignKeyConstraints();
        }
    }

    /**
     * Public so PlatformResetAction (the full reset) can reuse it after
     * wiping its superset of tables.
     */
    public function resetDerivedColumns(): void
    {
        $this->db->table('distributors')
            ->whereNotNull('gsb_frozen_at')
            ->update(['gsb_frozen_at' => null]);

        $this->db->table('coupons')
            ->where('used_count', '>', 0)
            ->update(['used_count' => 0]);

        $this->db->table('inventory_levels')
            ->where('reserved', '>', 0)
            ->update(['reserved' => 0]);
    }

    private function resolveAdminUserId(): ?int
    {
        return User::query()->where('email', 'admin@arovolife.test')->value('id');
    }
}
